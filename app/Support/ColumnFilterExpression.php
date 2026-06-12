<?php

namespace App\Support;

/**
 * Parses a single-column boolean filter expression (AND / OR / NOT, with `&` `|` `!`,
 * parentheses and quoted literals) into an AST, and applies it to a query builder as a
 * nested, parameter-bound, NULL-safe set of LIKE conditions.
 *
 * Scope: one column only. The expression never contains a column name — the caller passes
 * the resolved column to {@see applyToBuilder()}. See docs/CODES_BOOLEAN_FILTER_DESIGN.md
 * (§3 semantics, §6 parser, §13 NULL-safe NOT via De Morgan push-down).
 *
 * AST shape (nested arrays):
 *   ['type' => 'term', 'value' => string]
 *   ['type' => 'not',  'child' => node]
 *   ['type' => 'and',  'children' => node[]]
 *   ['type' => 'or',   'children' => node[]]
 */
class ColumnFilterExpression {
    public const MAX_LENGTH = 256;
    public const MAX_TOKENS = 64;
    public const MAX_DEPTH = 5;

    /**
     * Every error code parse() can throw. Kept here so the presentation layer can guarantee
     * an i18n message exists for each (see ColumnFilterExpressionTest). When adding a new
     * thrown code, add it here and to resources/lang/{zh-TW,en}/codes.php (codes.filter_err_*).
     *
     * @var list<string>
     */
    public const ERROR_CODES = [
        'too_long',
        'too_many_tokens',
        'too_deep',
        'empty',
        'adjacent_operand',
        'dangling_operator',
        'unbalanced_paren',
        'empty_group',
        'unterminated_quote',
        'empty_quote',
    ];

    /** @var list<array{type:string,value?:string}> */
    private array $tokens = [];
    private int $pos = 0;

    /**
     * Parse a raw filter string into an AST.
     *
     * @throws ColumnFilterParseException on any malformed input (caller must surface the
     *                                    error and skip the column, never fall back to literal).
     * @return array<string,mixed>
     */
    public function parse(string $raw): array {
        if (mb_strlen($raw) > self::MAX_LENGTH) {
            throw new ColumnFilterParseException('too_long');
        }

        $this->tokens = $this->tokenize($raw);

        if (count($this->tokens) === 0) {
            throw new ColumnFilterParseException('empty');
        }
        if (count($this->tokens) > self::MAX_TOKENS) {
            throw new ColumnFilterParseException('too_many_tokens');
        }

        $this->pos = 0;
        $ast = $this->parseOr(0);

        if ($this->pos !== count($this->tokens)) {
            // A leftover `)` is a surplus closing paren; anything else is two operands with no
            // binary connector between them (e.g. `a NOT b`).
            $code = $this->peek() === 'rp' ? 'unbalanced_paren' : 'adjacent_operand';

            throw new ColumnFilterParseException($code);
        }

        return $ast;
    }

    /**
     * Apply a parsed AST to a query builder as a single nested condition group on $column.
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query
     * @param array<string,mixed> $ast
     */
    public function applyToBuilder($query, string $column, array $ast): void {
        $this->applyNode($query, $column, $ast, false);
    }

    /**
     * Render a parsed AST as a human-readable description for post-submit echo (§9.2).
     *
     * Kept i18n-agnostic: the caller injects localized fragments so zh-TW/en stay in sync
     * via the existing lang files. `$labels` keys: `contains` (with a `:term` placeholder),
     * `not` (prefix), `and` (separator), `or` (separator).
     *
     * @param array<string,mixed> $ast
     * @param array<string,string> $labels
     */
    public function describe(array $ast, array $labels): string {
        return $this->describeNode($ast, $labels);
    }

    /**
     * @param array<string,mixed> $node
     * @param array<string,string> $labels
     */
    private function describeNode(array $node, array $labels): string {
        switch ($node['type']) {
            case 'term':
                return str_replace(':term', $node['value'], $labels['contains']);
            case 'not':
                // Collapse double negation so the description matches the query, which pushes
                // negation to the leaves and cancels `NOT NOT` (see applyNode De Morgan).
                if ($node['child']['type'] === 'not') {
                    return $this->describeNode($node['child']['child'], $labels);
                }

                return $labels['not'] . $this->describeWrapped($node['child'], $labels);
            case 'and':
                return implode($labels['and'], array_map(
                    fn ($child) => $this->describeWrapped($child, $labels),
                    $node['children']
                ));
            case 'or':
                return implode($labels['or'], array_map(
                    fn ($child) => $this->describeWrapped($child, $labels),
                    $node['children']
                ));
        }

        return '';
    }

    /**
     * Parenthesize compound (AND/OR) children so the rendered description is unambiguous.
     *
     * @param array<string,mixed> $node
     * @param array<string,string> $labels
     */
    private function describeWrapped(array $node, array $labels): string {
        $rendered = $this->describeNode($node, $labels);
        if ($node['type'] === 'and' || $node['type'] === 'or') {
            return '(' . $rendered . ')';
        }

        return $rendered;
    }

    // ---------------------------------------------------------------------
    // Tokenizer
    // ---------------------------------------------------------------------

    /**
     * Operators are all ASCII (single-byte). Multibyte CJK bytes are always >= 0x80 and never
     * collide with ASCII operator bytes, so a byte-wise scan is safe; non-operator bytes simply
     * accumulate into the current term (which may contain internal spaces).
     *
     * @return list<array{type:string,value?:string}>
     */
    private function tokenize(string $raw): array {
        $s = $this->normalizeFullWidth($raw);
        $len = strlen($s);

        $tokens = [];
        $term = '';

        $flush = function () use (&$tokens, &$term): void {
            $t = trim($term);
            $term = '';
            if ($t !== '') {
                $tokens[] = ['type' => 'term', 'value' => $t];
            }
        };

        $i = 0;
        while ($i < $len) {
            $c = $s[$i];

            if ($c === '"') {
                $flush();
                $close = strpos($s, '"', $i + 1);
                if ($close === false) {
                    throw new ColumnFilterParseException('unterminated_quote');
                }
                $content = substr($s, $i + 1, $close - $i - 1);
                if (trim($content) === '') {
                    throw new ColumnFilterParseException('empty_quote');
                }
                $tokens[] = ['type' => 'term', 'value' => $content];
                $i = $close + 1;

                continue;
            }

            if ($c === '(') {
                $flush();
                $tokens[] = ['type' => 'lp'];
                $i++;

                continue;
            }
            if ($c === ')') {
                $flush();
                $tokens[] = ['type' => 'rp'];
                $i++;

                continue;
            }
            if ($c === '&') {
                $flush();
                $tokens[] = ['type' => 'and'];
                $i++;

                continue;
            }
            if ($c === '|') {
                $flush();
                $tokens[] = ['type' => 'or'];
                $i++;

                continue;
            }

            if ($c === '!') {
                // NOT operator iff at an operand-start position: the term buffer is empty (we are
                // right after start / `(` / `&` / `|` / a keyword / another `!`) or it ends in
                // whitespace. Glued to a preceding term char (e.g. `a!b`) is literal. Using the
                // buffer state (not the raw previous char) keeps `&!x`, `AND !x`, `AND!x`, `!!x`
                // and `NOT!x` all consistent.
                if ($term === '' || $this->isAsciiSpace($term[strlen($term) - 1])) {
                    $flush();
                    $tokens[] = ['type' => 'not'];
                    $i++;

                    continue;
                }
                $term .= $c;
                $i++;

                continue;
            }

            // Standalone keyword AND / OR / NOT — only at a word boundary (term empty or the
            // previous char is an ASCII space). CJK glued to a keyword stays literal.
            if ($this->isAsciiAlpha($c) && ($term === '' || $this->isAsciiSpace($term[strlen($term) - 1]))) {
                $kw = $this->matchKeyword($s, $i, $len);
                if ($kw !== null) {
                    $flush();
                    $tokens[] = ['type' => $kw];
                    $i += ($kw === 'or') ? 2 : 3;

                    continue;
                }
            }

            $term .= $c;
            $i++;
        }

        $flush();

        return $tokens;
    }

    private function normalizeFullWidth(string $s): string {
        return strtr($s, [
            '（' => '(',
            '）' => ')',
            '！' => '!',
            '｜' => '|',
            '＆' => '&',
            "\u{3000}" => ' ',
        ]);
    }

    private function matchKeyword(string $s, int $i, int $len): ?string {
        foreach (['NOT' => 3, 'AND' => 3, 'OR' => 2] as $kw => $klen) {
            if ($i + $klen > $len) {
                continue;
            }
            if (strcasecmp(substr($s, $i, $klen), $kw) !== 0) {
                continue;
            }
            $after = ($i + $klen < $len) ? $s[$i + $klen] : '';
            if ($after === '' || $this->isBoundaryChar($after)) {
                return strtolower($kw);
            }
        }

        return null;
    }

    private function isBoundaryChar(string $c): bool {
        return $this->isAsciiSpace($c) || $c === '(' || $c === ')'
            || $c === '&' || $c === '|' || $c === '"' || $c === '!';
    }

    private function isAsciiAlpha(string $c): bool {
        return ($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z');
    }

    private function isAsciiSpace(string $c): bool {
        return $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r";
    }

    // ---------------------------------------------------------------------
    // Recursive-descent parser  (OR <- AND <- NOT <- primary)
    // ---------------------------------------------------------------------

    private function peek(): ?string {
        return $this->tokens[$this->pos]['type'] ?? null;
    }

    /** @return array<string,mixed> */
    private function parseOr(int $depth): array {
        $children = [$this->parseAnd($depth)];
        while ($this->peek() === 'or') {
            $this->pos++;
            $children[] = $this->parseAnd($depth);
        }

        return count($children) === 1 ? $children[0] : ['type' => 'or', 'children' => $children];
    }

    /** @return array<string,mixed> */
    private function parseAnd(int $depth): array {
        $children = [$this->parseUnary($depth)];
        while ($this->peek() === 'and') {
            $this->pos++;
            $children[] = $this->parseUnary($depth);
        }

        return count($children) === 1 ? $children[0] : ['type' => 'and', 'children' => $children];
    }

    /** @return array<string,mixed> */
    private function parseUnary(int $depth): array {
        if ($this->peek() === 'not') {
            $this->pos++;

            return ['type' => 'not', 'child' => $this->parseUnary($depth)];
        }

        return $this->parsePrimary($depth);
    }

    /** @return array<string,mixed> */
    private function parsePrimary(int $depth): array {
        $type = $this->peek();

        if ($type === 'lp') {
            if ($depth + 1 > self::MAX_DEPTH) {
                throw new ColumnFilterParseException('too_deep');
            }
            $this->pos++;
            $node = $this->parseOr($depth + 1);
            if ($this->peek() !== 'rp') {
                throw new ColumnFilterParseException('unbalanced_paren');
            }
            $this->pos++;

            return $node;
        }

        if ($type === 'term') {
            $value = $this->tokens[$this->pos]['value'];
            $this->pos++;

            return ['type' => 'term', 'value' => $value];
        }

        if ($type === 'rp') {
            // e.g. `()` — a group with no content.
            throw new ColumnFilterParseException('empty_group');
        }

        // null (ran out, e.g. trailing `AND`) or a binary operator where an operand was expected.
        throw new ColumnFilterParseException('dangling_operator');
    }

    // ---------------------------------------------------------------------
    // Apply to builder  (De Morgan push-down via $negate; every operand wrapped)
    // ---------------------------------------------------------------------

    /**
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query
     * @param array<string,mixed> $node
     */
    private function applyNode($query, string $col, array $node, bool $negate): void {
        switch ($node['type']) {
            case 'term':
                $pattern = '%' . $node['value'] . '%';
                if (!$negate) {
                    $query->where($col, 'like', $pattern);
                } else {
                    // NULL-safe NOT: a NULL column must NOT be excluded.
                    $query->where(function ($q) use ($col, $pattern): void {
                        $q->whereNull($col)->orWhere($col, 'not like', $pattern);
                    });
                }

                return;

            case 'not':
                // Push negation down toward the leaves (De Morgan): no bare NOT(...) group.
                $this->applyNode($query, $col, $node['child'], !$negate);

                return;

            case 'and':
            case 'or':
                $isAnd = $node['type'] === 'and';
                // De Morgan: under negation AND<->OR flip; children keep carrying $negate down.
                $effectiveAnd = $negate ? !$isAnd : $isAnd;
                $children = $node['children'];

                $query->where(function ($q) use ($col, $children, $negate, $effectiveAnd): void {
                    $first = true;
                    foreach ($children as $child) {
                        $apply = function ($qq) use ($col, $child, $negate): void {
                            $this->applyNode($qq, $col, $child, $negate);
                        };
                        if ($first) {
                            $q->where($apply);
                            $first = false;
                        } elseif ($effectiveAnd) {
                            $q->where($apply);
                        } else {
                            $q->orWhere($apply);
                        }
                    }
                });

                return;
        }
    }
}
