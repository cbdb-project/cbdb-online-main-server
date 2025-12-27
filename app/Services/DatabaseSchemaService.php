<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DatabaseSchemaService {
    /**
     * 获取允许查询的表的 schema 信息
     *
     * @param array|null $tableNames 指定的表名数组，如果为 null 则使用白名单所有表
     * @return array
     */
    public function getSchemaInfo(?array $tableNames = null): array {
        $allowedTables = array_keys(config('codes.tables', []));

        if ($tableNames !== null) {
            // 过滤：只返回白名单内的表
            $tableNames = array_filter($tableNames, function ($table) use ($allowedTables) {
                return in_array($table, $allowedTables, true);
            });
        } else {
            $tableNames = $allowedTables;
        }

        $schemaInfo = [];

        foreach ($tableNames as $tableName) {
            $schemaInfo[$tableName] = $this->getTableSchema($tableName);
        }

        return $schemaInfo;
    }

    /**
     * 获取单个表的 schema 信息（使用缓存）
     *
     * @param string $tableName
     * @return array
     */
    protected function getTableSchema(string $tableName): array {
        $cacheKey = "db_schema_{$tableName}";

        return Cache::remember($cacheKey, 3600, function () use ($tableName) {
            try {
                // 使用 INFORMATION_SCHEMA 获取列信息
                $columns = DB::select("
                    SELECT
                        COLUMN_NAME,
                        DATA_TYPE,
                        IS_NULLABLE,
                        COLUMN_KEY,
                        COLUMN_COMMENT,
                        COLUMN_DEFAULT
                    FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = ?
                    ORDER BY ORDINAL_POSITION
                ", [$tableName]);

                $schemaData = [
                    'columns' => [],
                    'primary_keys' => [],
                ];

                foreach ($columns as $column) {
                    $columnInfo = [
                        'name' => $column->COLUMN_NAME,
                        'type' => $column->DATA_TYPE,
                        'nullable' => $column->IS_NULLABLE === 'YES',
                        'default' => $column->COLUMN_DEFAULT,
                        'comment' => $column->COLUMN_COMMENT ?? '',
                    ];

                    $schemaData['columns'][] = $columnInfo;

                    if ($column->COLUMN_KEY === 'PRI') {
                        $schemaData['primary_keys'][] = $column->COLUMN_NAME;
                    }
                }

                return $schemaData;

            } catch (\Exception $e) {
                return [
                    'error' => "Failed to fetch schema for table {$tableName}: " . $e->getMessage(),
                    'columns' => [],
                    'primary_keys' => [],
                ];
            }
        });
    }

    /**
     * 生成适合 LLM 的 schema 描述文本
     *
     * @param array|null $tableNames
     * @return string
     */
    public function generateSchemaPrompt(?array $tableNames = null): string {
        $schemaInfo = $this->getSchemaInfo($tableNames);

        $prompt = "以下是可用的数据库表结构信息：\n\n";

        foreach ($schemaInfo as $tableName => $schema) {
            if (isset($schema['error'])) {
                $prompt .= "表 {$tableName}: {$schema['error']}\n\n";

                continue;
            }

            $tableDescription = config("codes.tables.{$tableName}", '');
            $prompt .= "表名: {$tableName}\n";
            if ($tableDescription) {
                $prompt .= "说明: {$tableDescription}\n";
            }

            if (!empty($schema['primary_keys'])) {
                $prompt .= "主键: " . implode(', ', $schema['primary_keys']) . "\n";
            }

            $prompt .= "字段:\n";
            foreach ($schema['columns'] as $column) {
                $nullable = $column['nullable'] ? 'NULL' : 'NOT NULL';
                $comment = $column['comment'] ? " // {$column['comment']}" : '';
                $prompt .= "  - {$column['name']}: {$column['type']} {$nullable}{$comment}\n";
            }

            $prompt .= "\n";
        }

        return $prompt;
    }

    /**
     * 清除 schema 缓存
     *
     * @param string|null $tableName 如果指定则只清除该表的缓存
     * @return void
     */
    public function clearCache(?string $tableName = null): void {
        if ($tableName !== null) {
            Cache::forget("db_schema_{$tableName}");
        } else {
            $allowedTables = array_keys(config('codes.tables', []));
            foreach ($allowedTables as $table) {
                Cache::forget("db_schema_{$table}");
            }
        }
    }
}
