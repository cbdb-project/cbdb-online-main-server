<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GenerateSchemaDocs extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cbdb:generate-schema-docs
                            {--output=docs/DATABASE_SCHEMA.md : 輸出文件路徑}
                            {--mysql-connection=mysql : MySQL 數據庫連接名稱}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '自動生成數據庫 Schema 文檔（包含 MySQL 和 SQLite 兩種版本）';

    /**
     * Execute the console command.
     */
    public function handle() {
        $this->info('開始生成數據庫 Schema 文檔...');

        $output = $this->option('output');
        $mysqlConnection = $this->option('mysql-connection');

        // 1. 獲取 MySQL Schema（從現有數據庫讀取）
        $this->info('正在讀取 MySQL/MariaDB Schema...');
        $mysqlSchema = $this->getMySQLSchema($mysqlConnection);

        // 2. 獲取 SQLite Schema（通過 in-memory 運行 migrations）
        $this->info('正在生成 SQLite Schema（運行 migrations）...');
        $sqliteSchema = $this->getSQLiteSchema();

        // 3. 生成 Markdown 文檔
        $this->info('正在生成 Markdown 文檔...');
        $markdown = $this->generateMarkdown($mysqlSchema, $sqliteSchema);

        // 4. 寫入文件
        file_put_contents(base_path($output), $markdown);
        $this->info("✅ Schema 文檔已生成：{$output}");

        return 0;
    }

    /**
     * 獲取 MySQL/MariaDB Schema
     */
    protected function getMySQLSchema(string $connectionName): array {
        // 驗證連接是否存在
        if (!Config::has("database.connections.{$connectionName}")) {
            $this->warn("MySQL 連接 '{$connectionName}' 不存在，跳過 MySQL Schema");

            return [];
        }

        try {
            $connection = DB::connection($connectionName);
            $driver = $connection->getDriverName();

            if (!in_array($driver, ['mysql', 'mariadb'])) {
                $this->warn("連接 '{$connectionName}' 不是 MySQL/MariaDB，跳過");

                return [];
            }

            return $this->extractSchemaFromConnection($connection, 'mysql');
        } catch (\Exception $e) {
            $this->error("讀取 MySQL Schema 失敗: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * 獲取 SQLite Schema（使用 in-memory 數據庫）
     */
    protected function getSQLiteSchema(): array {
        // 創建臨時 SQLite 連接配置
        $tempConnection = 'temp_sqlite_' . uniqid();

        Config::set("database.connections.{$tempConnection}", [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        try {
            // 設置為默認連接
            $originalConnection = Config::get('database.default');
            Config::set('database.default', $tempConnection);

            // 運行 migrations
            Artisan::call('migrate:fresh', [
                '--database' => $tempConnection,
                '--force' => true,
            ]);

            // 提取 Schema
            $connection = DB::connection($tempConnection);
            $schema = $this->extractSchemaFromConnection($connection, 'sqlite');

            // 恢復原連接
            Config::set('database.default', $originalConnection);
            DB::purge($tempConnection);

            return $schema;
        } catch (\Exception $e) {
            $this->error("生成 SQLite Schema 失敗: {$e->getMessage()}");
            Config::set('database.default', $originalConnection ?? 'mysql');
            DB::purge($tempConnection);

            return [];
        }
    }

    /**
     * 從數據庫連接提取 Schema 信息
     */
    protected function extractSchemaFromConnection($connection, string $driverType): array {
        $schema = [];
        $tables = $this->getTableList($connection, $driverType);

        foreach ($tables as $table) {
            $tableInfo = [
                'name' => $table,
                'type' => $this->getTableType($connection, $table, $driverType),
                'columns' => [],
                'indexes' => [],
                'primary_key' => [],
            ];

            // 獲取列信息
            $columns = $this->getColumns($connection, $table, $driverType);
            foreach ($columns as $column) {
                $tableInfo['columns'][] = $column;
                if ($column['is_primary']) {
                    $tableInfo['primary_key'][] = $column['name'];
                }
            }

            // 獲取索引信息
            $tableInfo['indexes'] = $this->getIndexes($connection, $table, $driverType);

            $schema[$table] = $tableInfo;
        }

        return $schema;
    }

    /**
     * 獲取數據庫中的所有表和視圖
     */
    protected function getTableList($connection, string $driverType): array {
        if ($driverType === 'mysql') {
            $results = $connection->select("
                SELECT TABLE_NAME
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                ORDER BY TABLE_NAME
            ");

            return array_map(fn ($r) => $r->TABLE_NAME, $results);
        } else { // sqlite
            $results = $connection->select("
                SELECT name
                FROM sqlite_master
                WHERE type IN ('table', 'view')
                AND name NOT LIKE 'sqlite_%'
                ORDER BY name
            ");

            return array_map(fn ($r) => $r->name, $results);
        }
    }

    /**
     * 判斷是表還是視圖
     */
    protected function getTableType($connection, string $name, string $driverType): string {
        if ($driverType === 'mysql') {
            $result = $connection->selectOne("
                SELECT TABLE_TYPE
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
            ", [$name]);

            return $result && str_contains($result->TABLE_TYPE, 'VIEW') ? 'view' : 'table';
        } else { // sqlite
            $result = $connection->selectOne("
                SELECT type
                FROM sqlite_master
                WHERE name = ?
            ", [$name]);

            return $result ? $result->type : 'table';
        }
    }

    /**
     * 獲取表的列信息
     */
    protected function getColumns($connection, string $table, string $driverType): array {
        if ($driverType === 'mysql') {
            return $this->getMySQLColumns($connection, $table);
        } else {
            return $this->getSQLiteColumns($connection, $table);
        }
    }

    /**
     * 獲取 MySQL 列信息
     */
    protected function getMySQLColumns($connection, string $table): array {
        $results = $connection->select("
            SELECT
                COLUMN_NAME,
                DATA_TYPE,
                COLUMN_TYPE,
                IS_NULLABLE,
                COLUMN_DEFAULT,
                COLUMN_KEY,
                EXTRA,
                COLUMN_COMMENT
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION
        ", [$table]);

        return array_map(function ($col) {
            return [
                'name' => $col->COLUMN_NAME,
                'type' => $col->COLUMN_TYPE,
                'nullable' => $col->IS_NULLABLE === 'YES',
                'default' => $col->COLUMN_DEFAULT,
                'is_primary' => $col->COLUMN_KEY === 'PRI',
                'auto_increment' => str_contains($col->EXTRA, 'auto_increment'),
                'comment' => $col->COLUMN_COMMENT ?? '',
            ];
        }, $results);
    }

    /**
     * 獲取 SQLite 列信息
     */
    protected function getSQLiteColumns($connection, string $table): array {
        $results = $connection->select("PRAGMA table_info({$table})");

        return array_map(function ($col) {
            return [
                'name' => $col->name,
                'type' => $col->type,
                'nullable' => $col->notnull == 0,
                'default' => $col->dflt_value,
                'is_primary' => $col->pk > 0,
                'auto_increment' => false, // SQLite 不直接提供此信息
                'comment' => '',
            ];
        }, $results);
    }

    /**
     * 獲取索引信息
     */
    protected function getIndexes($connection, string $table, string $driverType): array {
        if ($driverType === 'mysql') {
            $results = $connection->select("SHOW INDEX FROM {$table}");

            $indexes = [];
            foreach ($results as $row) {
                $indexName = $row->Key_name;
                if (!isset($indexes[$indexName])) {
                    $indexes[$indexName] = [
                        'name' => $indexName,
                        'unique' => !$row->Non_unique,
                        'columns' => [],
                    ];
                }
                $indexes[$indexName]['columns'][] = $row->Column_name;
            }

            return array_values($indexes);
        } else { // sqlite
            $results = $connection->select("PRAGMA index_list({$table})");
            $indexes = [];

            foreach ($results as $row) {
                $indexInfo = $connection->select("PRAGMA index_info({$row->name})");
                $columns = array_map(fn ($col) => $col->name, $indexInfo);

                $indexes[] = [
                    'name' => $row->name,
                    'unique' => (bool) $row->unique,
                    'columns' => $columns,
                ];
            }

            return $indexes;
        }
    }

    /**
     * 生成 Markdown 文檔
     */
    protected function generateMarkdown(array $mysqlSchema, array $sqliteSchema): string {
        $markdown = "# 數據庫 Schema 文檔\n\n";
        $markdown .= "> 本文檔由 `php artisan cbdb:generate-schema-docs` 自動生成\n";
        $markdown .= "> 生成時間：" . date('Y-m-d H:i:s') . "\n\n";

        $markdown .= "## 目錄\n\n";
        $markdown .= "- [MySQL/MariaDB Schema](#mysqlmariadb-schema)\n";
        $markdown .= "- [SQLite Schema](#sqlite-schema)\n";
        $markdown .= "- [Schema 差異對比](#schema-差異對比)\n\n";

        // MySQL Schema
        if (!empty($mysqlSchema)) {
            $markdown .= "## MySQL/MariaDB Schema\n\n";
            $markdown .= $this->renderSchema($mysqlSchema);
        } else {
            $markdown .= "## MySQL/MariaDB Schema\n\n";
            $markdown .= "> ⚠️ MySQL Schema 未生成（請確保數據庫連接配置正確）\n\n";
        }

        // SQLite Schema
        if (!empty($sqliteSchema)) {
            $markdown .= "## SQLite Schema\n\n";
            $markdown .= $this->renderSchema($sqliteSchema);
        } else {
            $markdown .= "## SQLite Schema\n\n";
            $markdown .= "> ⚠️ SQLite Schema 未生成\n\n";
        }

        // 差異對比
        if (!empty($mysqlSchema) && !empty($sqliteSchema)) {
            $markdown .= "## Schema 差異對比\n\n";
            $markdown .= $this->renderDifferences($mysqlSchema, $sqliteSchema);
        }

        return $markdown;
    }

    /**
     * 渲染 Schema 為 Markdown
     */
    protected function renderSchema(array $schema): string {
        $markdown = "";

        foreach ($schema as $table => $info) {
            $typeLabel = $info['type'] === 'view' ? '（視圖）' : '';
            $markdown .= "### {$table} {$typeLabel}\n\n";

            if (!empty($info['primary_key'])) {
                $markdown .= "**主鍵**: `" . implode('`, `', $info['primary_key']) . "`\n\n";
            }

            // 列信息表格
            if (!empty($info['columns'])) {
                $markdown .= "| 列名 | 類型 | 可空 | 默認值 | 備註 |\n";
                $markdown .= "|------|------|------|--------|------|\n";

                foreach ($info['columns'] as $col) {
                    $nullable = $col['nullable'] ? 'YES' : 'NO';
                    $default = $col['default'] ?? '(NULL)';
                    $comment = $col['comment'] ?: '';
                    if ($col['auto_increment']) {
                        $comment .= ' [AUTO_INCREMENT]';
                    }

                    $markdown .= "| `{$col['name']}` | {$col['type']} | {$nullable} | {$default} | {$comment} |\n";
                }

                $markdown .= "\n";
            }

            // 索引信息
            if (!empty($info['indexes'])) {
                $markdown .= "**索引**:\n\n";
                foreach ($info['indexes'] as $index) {
                    $unique = $index['unique'] ? ' (UNIQUE)' : '';
                    $columns = implode(', ', $index['columns']);
                    $markdown .= "- `{$index['name']}`{$unique}: ({$columns})\n";
                }
                $markdown .= "\n";
            }

            $markdown .= "---\n\n";
        }

        return $markdown;
    }

    /**
     * 渲染兩個 Schema 的差異
     */
    protected function renderDifferences(array $mysqlSchema, array $sqliteSchema): string {
        $markdown = "";

        // 找出只存在於某一個數據庫的表
        $mysqlTables = array_keys($mysqlSchema);
        $sqliteTables = array_keys($sqliteSchema);

        $onlyMySQL = array_diff($mysqlTables, $sqliteTables);
        $onlySQLite = array_diff($sqliteTables, $mysqlTables);
        $common = array_intersect($mysqlTables, $sqliteTables);

        if (!empty($onlyMySQL)) {
            $markdown .= "### 僅存在於 MySQL 的表\n\n";
            foreach ($onlyMySQL as $table) {
                $markdown .= "- `{$table}`\n";
            }
            $markdown .= "\n";
        }

        if (!empty($onlySQLite)) {
            $markdown .= "### 僅存在於 SQLite 的表\n\n";
            foreach ($onlySQLite as $table) {
                $markdown .= "- `{$table}`\n";
            }
            $markdown .= "\n";
        }

        // 檢查共同表的列差異
        $tablesWithDiffs = [];
        foreach ($common as $table) {
            $mysqlCols = array_column($mysqlSchema[$table]['columns'], 'name');
            $sqliteCols = array_column($sqliteSchema[$table]['columns'], 'name');

            $mysqlOnly = array_values(array_diff($mysqlCols, $sqliteCols));
            $sqliteOnly = array_values(array_diff($sqliteCols, $mysqlCols));

            if (!empty($mysqlOnly) || !empty($sqliteOnly)) {
                $tablesWithDiffs[$table] = [
                    'mysql_only' => $mysqlOnly,
                    'sqlite_only' => $sqliteOnly,
                ];
            }
        }

        if (!empty($tablesWithDiffs)) {
            $markdown .= "### 列結構差異（MySQL vs SQLite）\n\n";
            foreach ($tablesWithDiffs as $table => $diffCols) {
                $markdown .= "**{$table}**:\n";
                foreach ($diffCols['mysql_only'] as $col) {
                    $markdown .= "- MySQL 有但 SQLite 沒有: `{$col}`\n";
                }
                foreach ($diffCols['sqlite_only'] as $col) {
                    $markdown .= "- SQLite 有但 MySQL 沒有: `{$col}`\n";
                }
                $markdown .= "\n";
            }
        }

        if (empty($onlyMySQL) && empty($onlySQLite) && empty($tablesWithDiffs)) {
            $markdown .= "> ✅ 兩個數據庫的 Schema 結構一致\n\n";
        }

        return $markdown;
    }
}
