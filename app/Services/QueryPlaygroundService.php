<?php

namespace App\Services;

use Illuminate\Support\Facades\Schema;

class QueryPlaygroundService {
    /**
     * 取得 QBE 允許的表清單，含描述與是否為內部表標記。
     * 排序：非內部表優先，同類按字母排。
     *
     * @return array<int, array{name: string, description: string, internal: bool}>
     */
    public function getQbeTables(): array {
        $allowedTables = array_keys(config('codes.tables', []));

        usort($allowedTables, function ($a, $b) {
            $aInternal = self::isInternalTable($a);
            $bInternal = self::isInternalTable($b);
            if ($aInternal === $bInternal) {
                return strcmp($a, $b);
            }

            return $aInternal ? 1 : -1;
        });

        return array_map(function ($table) {
            return [
                'name' => $table,
                'description' => config("codes.tables.{$table}", ''),
                'internal' => self::isInternalTable($table),
            ];
        }, $allowedTables);
    }

    private static function isInternalTable(string $table): bool {
        return str_starts_with($table, 'CBDB__');
    }

    /**
     * 組裝 Inertia 頁面所需的初始 props。
     *
     * @param string|null $sql  從 query string 帶入的初始 SQL
     * @return array<string, mixed>
     */
    public function buildPageProps(?string $sql = null): array {
        $initialSql = $sql ?? 'SELECT * FROM DYNASTIES';

        return [
            'initialSql' => $initialSql,
            'nlModel' => config('services.gemini.model', 'gemini-3-flash-preview'),
            'qbeTables' => $this->getQbeTables(),
            'pageUrl' => route('app.query-playground.index', [], false),
            'runEndpoint' => route('query-playground.run', [], false),
            'schemaEndpoint' => route('query-playground.schema', [], false),
            'generateFromNlEndpoint' => route('query-playground.generate-from-nl', [], false),
            'generateFromNlStreamEndpoint' => route('query-playground.generate-from-nl-stream', [], false),
            'answerFromNlEndpoint' => route('query-playground.answer-from-nl', [], false),
            'answerFromNlStreamEndpoint' => route('query-playground.answer-from-nl-stream', [], false),
        ];
    }

    /**
     * 取得指定表的 schema 資訊（欄位名稱與類型）。
     *
     * @param array<string> $requestedTables
     * @return array<string, array{description: string, columns: array, error: string|null}>
     */
    public function getTableSchemas(array $requestedTables): array {
        $allowedTables = array_keys(config('codes.tables', []));

        // 解析請求的表名（大小寫不敏感匹配白名單）
        $resolvedTables = [];
        foreach ($requestedTables as $requestedTable) {
            foreach ($allowedTables as $allowedTable) {
                if (strcasecmp($requestedTable, $allowedTable) === 0) {
                    $resolvedTables[] = $allowedTable;

                    break;
                }
            }
        }
        $resolvedTables = array_values(array_unique($resolvedTables));

        $schemaBuilder = Schema::getConnection()->getSchemaBuilder();
        $tableSchemas = [];

        foreach ($resolvedTables as $tableName) {
            try {
                $columns = Schema::getColumnListing($tableName);
                $columnEntries = [];
                foreach ($columns as $columnName) {
                    $columnType = '';

                    try {
                        $columnType = $schemaBuilder->getColumnType($tableName, $columnName);
                    } catch (\Throwable $exception) {
                        // Gracefully degrade when DB driver cannot expose exact type metadata.
                    }

                    $columnEntries[] = [
                        'name' => $columnName,
                        'type' => $columnType,
                    ];
                }

                $tableSchemas[$tableName] = [
                    'description' => config("codes.tables.{$tableName}", ''),
                    'columns' => $columnEntries,
                    'error' => null,
                ];
            } catch (\Throwable $exception) {
                $tableSchemas[$tableName] = [
                    'description' => config("codes.tables.{$tableName}", ''),
                    'columns' => [],
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return $tableSchemas;
    }
}
