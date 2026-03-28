<?php

namespace App\Services\Mcp;

use App\Services\SqlTableNameExtractor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class ReadOnlyTableQueryService {
    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_]+$/';

    /**
     * Forbidden keywords for read-only SQL execution.
     *
     * @var string[]
     */
    private array $forbiddenKeywords = [
        'UPDATE', 'DELETE', 'INSERT', 'ALTER', 'DROP', 'TRUNCATE',
        'CREATE', 'GRANT', 'REVOKE', 'REPLACE', 'LOCK', 'UNLOCK',
        'COMMIT', 'ROLLBACK', 'SAVEPOINT', 'SET', 'EXECUTE', 'CALL',
        'SHOW', 'DESCRIBE', 'USE', 'EXPLAIN',
    ];

    /**
     * @var string[]
     */
    private array $forbiddenPatterns = [
        '/\bINTO\s+OUTFILE\b/i',
        '/\bINTO\s+DUMPFILE\b/i',
        '/\bFOR\s+UPDATE\b/i',
        '/\bLOCK\s+IN\s+SHARE\s+MODE\b/i',
    ];

    /**
     * @return string[]
     */
    public function listAllowedTables(): array {
        return array_values($this->allowedTablesMap());
    }

    /**
     * @return array<string, mixed>
     */
    public function queryTableSchema(string $tableName): array {
        $tableName = $this->normalizeAllowedTableName($tableName);

        if (!Schema::hasTable($tableName)) {
            throw new InvalidArgumentException("Table '{$tableName}' does not exist");
        }

        $driver = DB::connection()->getDriverName();
        $schemaBuilder = Schema::getConnection()->getSchemaBuilder();

        try {
            return [
                'table_name' => $tableName,
                'columns' => $this->normalizeSchemaBuilderRows($schemaBuilder->getColumns($tableName)),
                'indexes' => $this->normalizeSchemaBuilderRows($schemaBuilder->getIndexes($tableName)),
                'foreign_keys' => $this->normalizeForeignKeys($schemaBuilder->getForeignKeys($tableName)),
                'table_info' => [
                    'driver' => $driver,
                    'metadata_source' => 'schema_builder',
                ],
            ];
        } catch (\Throwable $e) {
            if ($driver === 'sqlite') {
                return [
                    'table_name' => $tableName,
                    'columns' => DB::select("PRAGMA table_info(`{$tableName}`)"),
                    'indexes' => DB::select("PRAGMA index_list(`{$tableName}`)"),
                    'foreign_keys' => DB::select("PRAGMA foreign_key_list(`{$tableName}`)"),
                    'table_info' => [
                        'driver' => 'sqlite',
                        'metadata_source' => 'pragma_fallback',
                        'fallback_error' => $e->getMessage(),
                    ],
                ];
            }

            return [
                'table_name' => $tableName,
                'columns' => DB::select("DESCRIBE `{$tableName}`"),
                'indexes' => DB::select("SHOW INDEX FROM `{$tableName}`"),
                'foreign_keys' => [],
                'table_info' => [
                    'driver' => $driver,
                    'metadata_source' => 'mysql_fallback',
                    'fallback_error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, object>
     */
    private function normalizeSchemaBuilderRows(array $rows): array {
        return array_map(
            static fn (array $row): object => (object) $row,
            $rows
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, object>
     */
    private function normalizeForeignKeys(array $rows): array {
        return array_map(static function (array $row): object {
            $columns = array_values($row['columns'] ?? []);
            $foreignColumns = array_values($row['foreign_columns'] ?? []);

            return (object) array_merge($row, [
                // Keep PRAGMA-style aliases so existing consumers and tests can read the first pair directly.
                'from' => $columns[0] ?? null,
                'table' => $row['foreign_table'] ?? null,
                'to' => $foreignColumns[0] ?? null,
            ]);
        }, $rows);
    }

    /**
     * @param int|string|float|bool|null $idValue
     * @return array<string, mixed>
     */
    public function getTableRowById(string $tableName, string $idColumn, int|string|float|bool|null $idValue): array {
        $tableName = $this->normalizeAllowedTableName($tableName);
        $this->assertValidIdentifier($idColumn, 'column name');

        $row = DB::table($tableName)
            ->where($idColumn, '=', $idValue)
            ->limit(1)
            ->first();

        return [
            'table_name' => $tableName,
            'id_column' => $idColumn,
            'id_value' => $idValue,
            'row' => $row ? (array) $row : (object) [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSampleData(string $tableName, int $limit = 10, int $offset = 0): array {
        $tableName = $this->normalizeAllowedTableName($tableName);
        $limit = $this->sanitizeLimit($limit);
        $offset = $this->sanitizeOffset($offset);

        $baseQuery = DB::table($tableName);
        $total = (clone $baseQuery)->count();
        $rows = $baseQuery->limit($limit)->offset($offset)->get()->map(
            static fn ($row): array => (array) $row
        )->all();

        return [
            'table_name' => $tableName,
            'total_rows' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'returned_rows' => count($rows),
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string, mixed>|string|null $filters
     * @param string[]|string|null $columns
     * @return array<string, mixed>
     */
    public function queryTable(
        string $tableName,
        array|string|null $filters = null,
        array|string|null $columns = null,
        int $limit = 10,
        int $offset = 0
    ): array {
        $tableName = $this->normalizeAllowedTableName($tableName);
        $limit = $this->sanitizeLimit($limit);
        $offset = $this->sanitizeOffset($offset);

        $filterMap = $this->normalizeFilters($filters);
        $columnList = $this->normalizeColumns($columns);

        $query = DB::table($tableName);

        foreach ($filterMap as $key => $value) {
            if (is_scalar($value) && str_contains((string) $value, '%')) {
                $query->where($key, 'like', $value);

                continue;
            }

            $query->where($key, '=', $value);
        }

        $totalMatching = (clone $query)->count();

        if ($columnList !== null) {
            $query->select($columnList);
        }

        $rows = $query
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();

        return [
            'table_name' => $tableName,
            'filters' => $filterMap,
            'columns' => $columnList ?? ['*'],
            'total_matching_rows' => $totalMatching,
            'limit' => $limit,
            'offset' => $offset,
            'returned_rows' => count($rows),
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function queryReadOnlySql(string $sql, int $limit = 20, int $offset = 0): array {
        $inspection = $this->inspectReadOnlySql($sql);
        $normalizedSql = $inspection['sql'];
        $limit = $this->sanitizeLimit($limit);
        $offset = $this->sanitizeOffset($offset);

        $rows = DB::select($this->buildPaginatedSql($normalizedSql, $limit, $offset));
        $normalizedRows = array_map(static fn ($row): array => (array) $row, $rows);

        return [
            'sql' => $normalizedSql,
            'tables' => $inspection['tables'],
            'limit' => $limit,
            'offset' => $offset,
            'returned_rows' => count($normalizedRows),
            'rows' => $normalizedRows,
        ];
    }

    /**
     * @return array{sql:string,tables:string[]}
     */
    public function inspectReadOnlySql(string $sql): array {
        $normalizedSql = $this->normalizeAndValidateReadOnlySql($sql);

        $tablesInQuery = app(SqlTableNameExtractor::class)->extractTableNames($normalizedSql);
        if ($tablesInQuery === []) {
            throw new InvalidArgumentException('Could not detect any table names. Please ensure your query is standard SQL.');
        }

        $normalizedTables = [];
        foreach ($tablesInQuery as $table) {
            $normalizedTableName = $this->normalizeAllowedTableName($table);
            $normalizedTables[$normalizedTableName] = $normalizedTableName;
        }

        return [
            'sql' => $normalizedSql,
            'tables' => array_values($normalizedTables),
        ];
    }

    private function sanitizeLimit(int $limit): int {
        $maxLimit = (int) config('mcp.cbdb.max_limit', 100);
        if ($limit < 1 || $limit > $maxLimit) {
            throw new InvalidArgumentException("Limit must be between 1 and {$maxLimit}");
        }

        return $limit;
    }

    private function sanitizeOffset(int $offset): int {
        if ($offset < 0) {
            throw new InvalidArgumentException('Offset must be non-negative');
        }

        return $offset;
    }

    private function normalizeAndValidateReadOnlySql(string $sql): string {
        $normalizedSql = trim($sql);
        $normalizedSql = rtrim($normalizedSql, "; \t\n\r\0\x0B");

        if ($normalizedSql === '') {
            throw new InvalidArgumentException('SQL must not be empty');
        }

        if (strpos($normalizedSql, ';') !== false) {
            throw new InvalidArgumentException("Multiple SQL statements separated by ';' are not allowed. A single trailing ';' is permitted.");
        }

        if (!preg_match('/^(SELECT|WITH)\b/i', $normalizedSql)) {
            throw new InvalidArgumentException('Only SELECT / WITH queries are allowed.');
        }

        foreach ($this->forbiddenKeywords as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $normalizedSql)) {
                throw new InvalidArgumentException("Forbidden keyword detected: {$keyword}");
            }
        }

        foreach ($this->forbiddenPatterns as $pattern) {
            if (preg_match($pattern, $normalizedSql)) {
                throw new InvalidArgumentException('SQL contains forbidden read-only side-effect clauses.');
            }
        }

        return $normalizedSql;
    }

    private function buildPaginatedSql(string $sql, int $limit, int $offset): string {
        if (preg_match('/^WITH\b/i', $sql)) {
            $paginatedSql = $this->replaceTrailingLimitOrOffset($sql, $limit, $offset);
            if ($paginatedSql !== null) {
                return $paginatedSql;
            }

            return $sql . " LIMIT {$limit} OFFSET {$offset}";
        }

        return "SELECT * FROM ({$sql}) AS subquery_wrapper LIMIT {$limit} OFFSET {$offset}";
    }

    private function replaceTrailingLimitOrOffset(string $sql, int $limit, int $offset): ?string {
        $pattern = '/\bLIMIT\s+(?:(\d+)\s*,\s*(\d+)|(\d+)(?:\s+OFFSET\s+(\d+))?)\s*$/i';
        if (preg_match($pattern, $sql, $matches) !== 1) {
            return null;
        }

        $existingOffset = 0;
        $existingLimit = 0;

        if (isset($matches[1], $matches[2]) && $matches[1] !== '' && $matches[2] !== '') {
            $existingOffset = (int) $matches[1];
            $existingLimit = (int) $matches[2];
        } elseif (isset($matches[3]) && $matches[3] !== '') {
            $existingLimit = (int) $matches[3];
            $existingOffset = isset($matches[4]) && $matches[4] !== '' ? (int) $matches[4] : 0;
        }

        $remainingRows = max(0, $existingLimit - $offset);
        $effectiveLimit = min($limit, $remainingRows);
        $effectiveOffset = $existingOffset + $offset;
        $replacement = "LIMIT {$effectiveLimit} OFFSET {$effectiveOffset}";

        return preg_replace($pattern, $replacement, $sql, 1);
    }

    /**
     * @param array<string, mixed>|string|null $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array|string|null $filters): array {
        if ($filters === null || $filters === '') {
            return [];
        }

        if (is_string($filters)) {
            $decoded = json_decode($filters, true);
            if (!is_array($decoded)) {
                throw new InvalidArgumentException('Filters must be a JSON object');
            }
            $filters = $decoded;
        }

        foreach ($filters as $key => $value) {
            $this->assertValidIdentifier((string) $key, 'filter column name');

            if (is_array($value) || is_object($value)) {
                throw new InvalidArgumentException("Filter value for '{$key}' must be scalar or null");
            }
        }

        return $filters;
    }

    /**
     * @param string[]|string|null $columns
     * @return string[]|null
     */
    private function normalizeColumns(array|string|null $columns): ?array {
        if ($columns === null || $columns === '') {
            return null;
        }

        if (is_string($columns)) {
            $columns = array_values(array_filter(array_map('trim', explode(',', $columns))));
        }

        foreach ($columns as $column) {
            $this->assertValidIdentifier((string) $column, 'column name');
        }

        return array_values($columns);
    }

    private function normalizeAllowedTableName(string $tableName): string {
        $this->assertValidIdentifier($tableName, 'table name');

        $allowedTables = $this->allowedTablesMap();
        $normalized = strtoupper($tableName);

        if (!isset($allowedTables[$normalized])) {
            throw new InvalidArgumentException(
                "Table '{$tableName}' is not in allowlist"
            );
        }

        return $allowedTables[$normalized];
    }

    /**
     * @return array<string, string>
     */
    private function allowedTablesMap(): array {
        $tables = (array) config('mcp.cbdb.allowed_tables', []);
        $map = [];

        foreach ($tables as $table) {
            $table = trim((string) $table);
            if ($table === '') {
                continue;
            }

            $this->assertValidIdentifier($table, 'allowlist table name');
            $map[strtoupper($table)] = $table;
        }

        return $map;
    }

    private function assertValidIdentifier(string $identifier, string $identifierType = 'identifier'): void {
        if ($identifier === '' || preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1) {
            throw new InvalidArgumentException(
                "Invalid {$identifierType}: '{$identifier}'. Only alphanumeric characters and underscores are allowed."
            );
        }
    }
}
