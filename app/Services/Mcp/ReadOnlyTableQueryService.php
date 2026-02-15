<?php

namespace App\Services\Mcp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class ReadOnlyTableQueryService {
    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_]+$/';

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
        if ($driver === 'sqlite') {
            return [
                'table_name' => $tableName,
                'columns' => DB::select("PRAGMA table_info(`{$tableName}`)"),
                'indexes' => DB::select("PRAGMA index_list(`{$tableName}`)"),
                'table_info' => [
                    'driver' => 'sqlite',
                ],
            ];
        }

        return [
            'table_name' => $tableName,
            'columns' => DB::select("DESCRIBE `{$tableName}`"),
            'indexes' => DB::select("SHOW INDEX FROM `{$tableName}`"),
            'table_info' => DB::selectOne(
                <<<'SQL'
                SELECT
                  TABLE_NAME,
                  ENGINE,
                  TABLE_ROWS,
                  DATA_LENGTH,
                  INDEX_LENGTH,
                  CREATE_TIME,
                  UPDATE_TIME,
                  TABLE_COLLATION
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                SQL,
                [$tableName]
            ),
        ];
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
