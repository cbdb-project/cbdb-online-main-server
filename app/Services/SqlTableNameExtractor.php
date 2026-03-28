<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use PhpMyAdmin\SqlParser\Statements\WithStatement;

class SqlTableNameExtractor {
    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_]+$/';
    private const EXPLAIN_DERIVED_PATTERN = '/^<[^>]+>$/';

    /**
     * @return string[]
     */
    public function extractTableNames(string $sql): array {
        $tablesFromParser = $this->extractTableNamesFromParser($sql);
        if ($tablesFromParser !== []) {
            return $tablesFromParser;
        }

        $cteAliases = $this->extractCteAliasesFromSql($sql);

        $tablesFromRegex = $this->extractTableNamesFromRegex($sql, $cteAliases);
        if ($tablesFromRegex !== []) {
            return $tablesFromRegex;
        }

        return $this->extractTableNamesFromExplain($sql, $cteAliases);
    }

    /**
     * @return string[]
     */
    private function extractTableNamesFromParser(string $sql): array {
        try {
            $parser = new Parser($sql);
            if (!empty($parser->errors)) {
                // WITH RECURSIVE is not fully supported by the parser;
                // strip RECURSIVE and retry so the CTE body can still be analysed.
                if (preg_match('/\bWITH\s+RECURSIVE\b/i', $sql)) {
                    $strippedSql = preg_replace('/\bWITH\s+RECURSIVE\b/i', 'WITH', $sql);
                    $parser = new Parser($strippedSql);
                    if (!empty($parser->errors)) {
                        return [];
                    }
                } else {
                    return [];
                }
            }

            $tables = [];
            $cteAliases = [];

            foreach ($parser->statements as $statement) {
                $tables = array_merge($tables, $this->extractTablesFromStatement($statement, $cteAliases));
            }

            return $this->normalizeAndFilterTables($tables, $cteAliases);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, bool> $cteAliases
     * @return string[]
     */
    private function extractTablesFromStatement(object $statement, array &$cteAliases): array {
        if ($statement instanceof SelectStatement) {
            return $this->extractTablesFromSelectStatement($statement, $cteAliases);
        }

        if ($statement instanceof WithStatement) {
            return $this->extractTablesFromWithStatement($statement, $cteAliases);
        }

        return [];
    }

    /**
     * @param array<string, bool> $cteAliases
     * @return string[]
     */
    private function extractTablesFromWithStatement(WithStatement $statement, array &$cteAliases): array {
        $tables = [];

        foreach ($statement->withers as $key => $withClause) {
            $alias = is_string($key) ? $key : (is_string($withClause->name ?? null) ? $withClause->name : '');
            $alias = $this->normalizeTableName($alias);
            if ($alias !== '') {
                $cteAliases[strtoupper($alias)] = true;
            }

            $withStatementParser = $withClause->statement ?? null;
            if ($withStatementParser instanceof Parser) {
                foreach ($withStatementParser->statements as $withStatement) {
                    $tables = array_merge($tables, $this->extractTablesFromStatement($withStatement, $cteAliases));
                }
            }
        }

        if ($statement->cteStatementParser instanceof Parser) {
            foreach ($statement->cteStatementParser->statements as $cteStatement) {
                $tables = array_merge($tables, $this->extractTablesFromStatement($cteStatement, $cteAliases));
            }
        }

        return $tables;
    }

    /**
     * @param array<string, bool> $cteAliases
     * @return string[]
     */
    private function extractTablesFromSelectStatement(SelectStatement $statement, array &$cteAliases): array {
        $tables = [];

        if ($statement->from) {
            foreach ($statement->from as $fromClause) {
                if ($fromClause->subquery || (is_string($fromClause->expr) && strpos($fromClause->expr, '(') === 0)) {
                    $tables = array_merge($tables, $this->extractTablesFromSubquery((string) $fromClause->expr, $cteAliases));
                } elseif ($fromClause->table) {
                    $tables[] = (string) $fromClause->table;
                }
            }
        }

        if ($statement->join) {
            foreach ($statement->join as $joinClause) {
                if (!$joinClause->expr instanceof Expression) {
                    continue;
                }

                $expression = $joinClause->expr;
                if ($expression->subquery || (is_string($expression->expr) && strpos($expression->expr, '(') === 0)) {
                    $tables = array_merge($tables, $this->extractTablesFromSubquery((string) $expression->expr, $cteAliases));
                } elseif ($expression->table) {
                    $tables[] = (string) $expression->table;
                }
            }
        }

        if ($statement->union) {
            foreach ($statement->union as $unionEntry) {
                if (!is_array($unionEntry) || count($unionEntry) < 2) {
                    continue;
                }

                $unionStatement = $unionEntry[1] ?? null;
                if ($unionStatement instanceof SelectStatement) {
                    $tables = array_merge($tables, $this->extractTablesFromSelectStatement($unionStatement, $cteAliases));
                }
            }
        }

        return $tables;
    }

    /**
     * @param array<string, bool> $cteAliases
     * @return string[]
     */
    private function extractTablesFromSubquery(string $subqueryExpression, array &$cteAliases): array {
        if (!preg_match('/^\((.*)\)$/s', $subqueryExpression, $matches)) {
            return [];
        }

        try {
            $subqueryParser = new Parser($matches[1]);
            if (!empty($subqueryParser->errors)) {
                return [];
            }

            $tables = [];
            foreach ($subqueryParser->statements as $subStatement) {
                $tables = array_merge($tables, $this->extractTablesFromStatement($subStatement, $cteAliases));
            }

            return $tables;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Regex-based fallback: extract table names from FROM / JOIN clauses.
     *
     * Captures the first identifier after FROM or JOIN, which is always the
     * real table name (aliases follow the table name and are NOT captured).
     * CTE aliases are filtered out so only physical tables remain.
     *
     * Before matching, SQL comments and single-quoted string literals are
     * stripped so that identifiers inside non-code content (e.g.
     * WHERE name = 'FROM users') do not cause false positives.
     *
     * @param array<string, bool> $cteAliases
     * @return string[]
     */
    private function extractTableNamesFromRegex(string $sql, array $cteAliases): array {
        $tables = [];

        $cleanedSql = $this->stripCommentsAndStringLiterals($sql);

        // Match: FROM tablename or JOIN tablename
        // Handles optional backtick / double-quote quoting.
        // The first captured group is always the real table name.
        if (preg_match_all('/\b(?:FROM|JOIN)\s+[`"]?([A-Za-z_]\w*)[`"]?/i', $cleanedSql, $matches)) {
            foreach ($matches[1] as $table) {
                $tables[] = $table;
            }
        }

        return $this->normalizeAndFilterTables($tables, $cteAliases);
    }

    /**
     * Strip SQL block comments, line comments, and single-quoted string
     * literals so that regex-based extraction does not pick up identifiers
     * from non-code content (e.g. WHERE name = 'FROM users').
     *
     * Double-quoted identifiers are intentionally preserved because they
     * may legitimately wrap table names.
     */
    private function stripCommentsAndStringLiterals(string $sql): string {
        return (string) preg_replace(
            [
                '/\/\*.*?\*\//s',   // Block comments: /* ... */
                '/--[^\r\n]*/',     // Line comments:  -- ...
                "/'(?:[^']|'')*'/", // Single-quoted strings (handles '' escaping)
            ],
            ' ',
            $sql
        );
    }

    /**
     * @param array<string, bool> $cteAliases
     * @return string[]
     */
    private function extractTableNamesFromExplain(string $sql, array $cteAliases): array {
        $driver = DB::connection()->getDriverName();

        try {
            if ($driver === 'sqlite') {
                $planRows = DB::select('EXPLAIN QUERY PLAN ' . $sql);
                $tables = [];

                foreach ($planRows as $row) {
                    $detail = (string) ($row->detail ?? '');
                    if (preg_match_all('/\b(?:SCAN|SEARCH)\s+[`\"]?([A-Za-z0-9_]+)[`\"]?/i', $detail, $matches)) {
                        foreach ($matches[1] as $table) {
                            $tables[] = $table;
                        }
                    }
                }

                return $this->normalizeAndFilterTables($tables, $cteAliases);
            }

            $planRows = DB::select('EXPLAIN ' . $sql);
            $tables = [];

            foreach ($planRows as $row) {
                $table = (string) ($row->table ?? '');
                if ($table !== '') {
                    $tables[] = $table;
                }
            }

            return $this->normalizeAndFilterTables($tables, $cteAliases);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, bool>
     */
    private function extractCteAliasesFromSql(string $sql): array {
        $aliases = [];

        if (preg_match_all('/\b([A-Za-z0-9_`"]+)\s+AS\s*\(/i', $sql, $matches)) {
            foreach ($matches[1] as $match) {
                $alias = $this->normalizeTableName((string) $match);
                if ($alias !== '') {
                    $aliases[strtoupper($alias)] = true;
                }
            }
        }

        return $aliases;
    }

    /**
     * @param string[] $tables
     * @param array<string, bool> $cteAliases
     * @return string[]
     */
    private function normalizeAndFilterTables(array $tables, array $cteAliases): array {
        $normalized = [];

        foreach ($tables as $table) {
            $tableName = $this->normalizeTableName((string) $table);
            if ($tableName === '') {
                continue;
            }

            if (preg_match(self::EXPLAIN_DERIVED_PATTERN, $tableName) === 1) {
                continue;
            }

            if (isset($cteAliases[strtoupper($tableName)])) {
                continue;
            }

            if (preg_match(self::IDENTIFIER_PATTERN, $tableName) !== 1) {
                continue;
            }

            $normalized[strtoupper($tableName)] = $tableName;
        }

        return array_values($normalized);
    }

    private function normalizeTableName(string $tableName): string {
        $tableName = trim($tableName);
        if ($tableName === '') {
            return '';
        }

        if (str_contains($tableName, '.')) {
            $parts = explode('.', $tableName);
            $tableName = (string) end($parts);
        }

        return trim($tableName, "`'\" \t\n\r\0\x0B");
    }
}
