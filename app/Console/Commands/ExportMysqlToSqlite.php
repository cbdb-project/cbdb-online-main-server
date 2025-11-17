<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExportMysqlToSqlite extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:export-to-sqlite
                            {--output=database/database.sqlite : SQLite 数据库文件路径}
                            {--schema-only : 只导出结构，不导出数据}
                            {--tables= : 只导出指定的表（逗号分隔）}
                            {--batch=1000 : 批量插入的行数}
                            {--source=mysql : 源数据库连接名称}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '从 MySQL 导出数据到 SQLite';

    /**
     * 源数据库连接
     *
     * @var string
     */
    protected $sourceConnection;

    /**
     * 目标 SQLite 数据库路径
     *
     * @var string
     */
    protected $outputPath;

    /**
     * 统计信息
     *
     * @var array
     */
    protected $stats = [
        'tables' => 0,
        'rows' => 0,
        'errors' => 0,
    ];

    /**
     * 表結構附加資訊（如主鍵欄位）
     *
     * @var array
     */
    protected $tableMetadata = [];

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->sourceConnection = $this->option('source');
        $this->outputPath = $this->option('output');

        // 验证源数据库连接
        if (!$this->validateSourceConnection()) {
            return 1;
        }

        // 准备 SQLite 数据库
        if (!$this->prepareSqliteDatabase()) {
            return 1;
        }

        // 获取要导出的表
        $tables = $this->getTablesToExport();

        if (empty($tables)) {
            $this->error('没有找到要导出的表');
            return 1;
        }

        $this->info(sprintf('准备导出 %d 个表...', count($tables)));
        $this->output->newLine();

        // 导出表结构和数据
        $bar = $this->output->createProgressBar(count($tables));
        $bar->start();

        foreach ($tables as $table) {
            try {
                $this->exportTable($table);
                $bar->advance();
            } catch (\Exception $e) {
                $this->stats['errors']++;
                $this->output->newLine();
                $this->error(sprintf('导出表 %s 失败: %s', $table['name'], $e->getMessage()));
                $bar->advance();
            }
        }

        $bar->finish();
        $this->output->newLine(2);

        // 显示统计信息
        $this->displayStats();

        return 0;
    }

    /**
     * 验证源数据库连接
     *
     * @return bool
     */
    protected function validateSourceConnection()
    {
        try {
            $driver = DB::connection($this->sourceConnection)->getDriverName();

            if ($driver !== 'mysql') {
                $this->error(sprintf('源数据库必须是 MySQL，当前是: %s', $driver));
                return false;
            }

            // 测试连接
            DB::connection($this->sourceConnection)->getPdo();

            $this->info(sprintf('✓ 源数据库连接正常 (%s)', $this->sourceConnection));

            return true;
        } catch (\Exception $e) {
            $this->error(sprintf('无法连接到源数据库: %s', $e->getMessage()));
            return false;
        }
    }

    /**
     * 准备 SQLite 数据库
     *
     * @return bool
     */
    protected function prepareSqliteDatabase()
    {
        $absolutePath = base_path($this->outputPath);
        $directory = dirname($absolutePath);

        // 确保目录存在
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                $this->error(sprintf('无法创建目录: %s', $directory));
                return false;
            }
        }

        // 如果文件已存在，询问是否覆盖
        if (file_exists($absolutePath)) {
            if (!$this->confirm(sprintf('文件 %s 已存在，是否覆盖？', $this->outputPath), false)) {
                $this->info('操作已取消');
                return false;
            }

            unlink($absolutePath);
        }

        // 创建空的 SQLite 数据库文件
        touch($absolutePath);

        // 配置临时的 SQLite 连接
        config([
            'database.connections.sqlite_export' => [
                'driver' => 'sqlite',
                'database' => $absolutePath,
                'prefix' => '',
                'foreign_key_constraints' => false, // 导出时先禁用外键
            ]
        ]);

        try {
            DB::connection('sqlite_export')->getPdo();
            $this->info(sprintf('✓ SQLite 数据库已创建: %s', $this->outputPath));
            return true;
        } catch (\Exception $e) {
            $this->error(sprintf('无法创建 SQLite 数据库: %s', $e->getMessage()));
            return false;
        }
    }

    /**
     * 获取要导出的表列表
     *
     * @return array
     */
    protected function getTablesToExport()
    {
        $specifiedTables = $this->option('tables');

        $tables = DB::connection($this->sourceConnection)
            ->select('SHOW FULL TABLES');

        $databaseName = DB::connection($this->sourceConnection)->getDatabaseName();
        $tableKey = 'Tables_in_' . $databaseName;

        $result = [];

        foreach ($tables as $table) {
            $name = $table->$tableKey;
            $result[$name] = [
                'name' => $name,
                'type' => isset($table->Table_type) ? strtoupper($table->Table_type) : 'BASE TABLE',
            ];
        }

        if ($specifiedTables) {
            $names = array_map('trim', explode(',', $specifiedTables));
            $filtered = [];

            foreach ($names as $name) {
                if (isset($result[$name])) {
                    $filtered[] = $result[$name];
                } else {
                    $this->warn(sprintf('⚠ 未找到表: %s', $name));
                }
            }

            return $filtered;
        }

        return array_values($result);
    }

    /**
     * 导出单个表
     *
     * @param string $tableName
     * @return void
     */
    protected function exportTable(array $table)
    {
        $tableName = $table['name'];
        $isView = strtoupper($table['type']) === 'VIEW';

        // 1. 导出表结构
        $this->exportTableSchema($tableName, $isView);

        // 2. 导出数据（如果不是 schema-only 模式且不是视图）
        if (!$isView && !$this->option('schema-only')) {
            $rowCount = $this->getTableRowCount($tableName);
            $this->exportTableData($tableName, $rowCount);
        }

        $this->stats['tables']++;
    }

    /**
     * 导出表结构
     *
     * @param string $tableName
     * @return void
     */
    protected function exportTableSchema($tableName, $isView = false)
    {
        $statement = $isView
            ? "SHOW CREATE VIEW `{$tableName}`"
            : "SHOW CREATE TABLE `{$tableName}`";

        $createTableResult = DB::connection($this->sourceConnection)
            ->select($statement);

        if (empty($createTableResult)) {
            throw new \RuntimeException(sprintf('无法获取 %s 的结构信息', $tableName));
        }

        $definition = (array) $createTableResult[0];
        $sqliteCreateSql = null;

        if ($isView) {
            $key = 'Create View';
            if (!isset($definition[$key])) {
                throw new \RuntimeException(sprintf('未找到视图 %s 的定义', $tableName));
            }

            $sqliteCreateSql = $this->convertCreateViewToSqlite($definition[$key], $tableName);
        } else {
            $key = 'Create Table';
            if (!isset($definition[$key])) {
                throw new \RuntimeException(sprintf('未找到数据表 %s 的定义', $tableName));
            }

            $sqliteCreateSql = $this->convertCreateTableToSqlite($definition[$key], $tableName);
        }

        if ($isView) {
            DB::connection('sqlite_export')->statement($sqliteCreateSql);
            return;
        }

        DB::connection('sqlite_export')->statement($sqliteCreateSql['table']);
        $this->tableMetadata[$tableName] = $sqliteCreateSql['meta'] ?? [];

        foreach ($sqliteCreateSql['indexes'] as $indexSql) {
            DB::connection('sqlite_export')->statement($indexSql);
        }
    }

    /**
     * 将 MySQL CREATE TABLE 语句转换为 SQLite 兼容格式
     *
     * @param string $mysqlSql
     * @param string $tableName
     * @return string
     */
    protected function convertCreateTableToSqlite($mysqlSql, $tableName)
    {
        $cleanSql = preg_replace('/`' . preg_quote($tableName, '/') . '`/i', '"' . $tableName . '"', $mysqlSql);
        $cleanSql = preg_replace('/ENGINE=.*$/is', '', $cleanSql);
        $cleanSql = preg_replace('/ROW_FORMAT=\w+/i', '', $cleanSql);
        $cleanSql = preg_replace('/AUTO_INCREMENT=\d+/i', '', $cleanSql);
        $cleanSql = preg_replace('/DEFAULT CHARSET=\w+/i', '', $cleanSql);
        $cleanSql = preg_replace('/COLLATE=\w+/i', '', $cleanSql);
        $cleanSql = trim($cleanSql);

        if (!preg_match('/^CREATE TABLE\s+"?([^"\s]+)"?\s*\((.*)\)/is', $cleanSql, $matches)) {
            throw new \RuntimeException(sprintf('无法解析数据表 %s 的结构', $tableName));
        }

        $definitions = $matches[2];
        $items = $this->splitDefinitionItems($definitions);

        $columns = [];
        $primaryKeys = [];
        $indexes = [];
        $autoIncrementColumns = [];
        $primaryKeyColumnsList = [];
        $chunkColumn = null;
        $firstColumn = null;

        foreach ($items as $item) {
            $trimmed = trim($item);

            if ($trimmed === '') {
                continue;
            }

            if (stripos($trimmed, 'PRIMARY KEY') === 0) {
                $columnsString = $this->extractColumnsFromDefinition($trimmed);
                $columnNames = $this->extractColumnNames($columnsString);
                $primaryKeyColumnsList[] = $columnNames;

                if (count($columnNames) === 1 && in_array($columnNames[0], $autoIncrementColumns, true)) {
                    continue;
                }

                $primaryKeys[] = sprintf('PRIMARY KEY (%s)', $this->normalizeIndexColumns($columnsString));
                continue;
            }

            if (preg_match('/^(UNIQUE\s+)?KEY\s+`?([^`(]+)`?\s*\((.+)\)/i', $trimmed, $match)
                || preg_match('/^(FULLTEXT\s+)?KEY\s+`?([^`(]+)`?\s*\((.+)\)/i', $trimmed, $match)) {
                $columnsString = $match[3];
                $normalizedColumns = $this->normalizeIndexColumns($columnsString);
                $isUnique = stripos($match[1], 'UNIQUE') !== false;
                $indexName = $this->sanitizeIdentifier($tableName . '_' . $match[2]);

                $indexes[] = sprintf(
                    'CREATE %sINDEX "%s" ON "%s" (%s);',
                    $isUnique ? 'UNIQUE ' : '',
                    $indexName,
                    $tableName,
                    $normalizedColumns
                );

                continue;
            }

            if (stripos($trimmed, 'CONSTRAINT') === 0 || stripos($trimmed, 'FOREIGN KEY') === 0) {
                continue;
            }

            $column = $this->convertColumnDefinition($trimmed);

            if ($column === null) {
                continue;
            }

            $columns[] = $column['definition'];

            if ($column['auto_increment']) {
                $autoIncrementColumns[] = $column['name'];
                if ($chunkColumn === null) {
                    $chunkColumn = $column['name'];
                }
            }

            if ($firstColumn === null) {
                $firstColumn = $column['name'];
            }
        }

        if ($chunkColumn === null) {
            foreach ($primaryKeyColumnsList as $pkColumns) {
                if (count($pkColumns) === 1) {
                    $chunkColumn = $pkColumns[0];
                    break;
                }
            }
        }

        if ($chunkColumn === null) {
            $chunkColumn = $firstColumn;
        }

        $body = array_merge($columns, $primaryKeys);

        if (empty($body)) {
            throw new \RuntimeException(sprintf('无法生成 %s 的字段定义', $tableName));
        }

        $tableSql = sprintf("CREATE TABLE \"%s\" (\n    %s\n);", $tableName, implode(",\n    ", $body));

        return [
            'table' => $tableSql,
            'indexes' => $indexes,
            'meta' => [
                'chunk_column' => $chunkColumn,
            ],
        ];
    }

    /**
     * 将 MySQL CREATE VIEW 语句转换为 SQLite 兼容格式
     *
     * @param string $mysqlSql
     * @param string $viewName
     * @return string
     */
    protected function convertCreateViewToSqlite($mysqlSql, $viewName)
    {
        $sql = trim($mysqlSql);

        if (!preg_match('/\sAS\s/i', $sql, $match, PREG_OFFSET_CAPTURE)) {
            throw new \RuntimeException(sprintf('无法解析视图 %s 的定义', $viewName));
        }

        $start = $match[0][1] + strlen($match[0][0]);
        $definition = substr($sql, $start);

        if ($definition === false) {
            throw new \RuntimeException(sprintf('无法解析视图 %s 的 SELECT 语句', $viewName));
        }

        $definition = trim(rtrim($definition, ';'));
        $viewIdentifier = str_replace('"', '""', $viewName);

        return sprintf('CREATE VIEW "%s" AS %s', $viewIdentifier, $definition);
    }

    /**
     * 将列定义拆分成单独项目
     */
    protected function splitDefinitionItems($definitions)
    {
        $items = [];
        $current = '';
        $depth = 0;

        $length = strlen($definitions);

        for ($i = 0; $i < $length; $i++) {
            $char = $definitions[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                if ($depth > 0) {
                    $depth--;
                }
            } elseif ($char === ',' && $depth === 0) {
                $items[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $items[] = trim($current);
        }

        return $items;
    }

    /**
     * 将 MySQL 列定义转换为 SQLite
     */
    protected function convertColumnDefinition($definition)
    {
        if (!preg_match('/^`?([^`\s]+)`?\s+(.+)$/s', $definition, $matches)) {
            return null;
        }

        $columnName = $matches[1];
        $rest = $matches[2];

        $autoIncrement = stripos($rest, 'auto_increment') !== false;

        $rest = $this->convertColumnType($rest);
        $rest = preg_replace('/\bUNSIGNED\b/i', '', $rest);
        $rest = preg_replace('/\bZEROFILL\b/i', '', $rest);
        $rest = preg_replace('/\bCHARACTER SET\s+\w+/i', '', $rest);
        $rest = preg_replace('/\bCOLLATE\s+\w+/i', '', $rest);
        $rest = preg_replace('/\bCOMMENT\s+\'.*?\'/i', '', $rest);
        $rest = preg_replace('/\bON UPDATE\b[^,]+/i', '', $rest);
        $rest = preg_replace('/\s+DEFAULT\s+NULL/i', ' DEFAULT NULL', $rest);
        $rest = preg_replace('/\s+DEFAULT\s+\'0000-00-00 00:00:00\'/i', ' DEFAULT NULL', $rest);
        $rest = preg_replace('/,\s*$/', '', $rest);
        $rest = preg_replace('/\s+/', ' ', trim($rest));

        if ($autoIncrement) {
            $rest = preg_replace('/\bAUTO_INCREMENT\b/i', '', $rest);
            $rest = preg_replace('/\bNOT NULL\b/i', '', $rest);
            $rest = 'INTEGER PRIMARY KEY AUTOINCREMENT';
        }

        return [
            'definition' => sprintf('"%s" %s', $columnName, trim($rest)),
            'auto_increment' => $autoIncrement,
            'name' => $columnName,
        ];
    }

    /**
     * 转换 MySQL 数据类型到 SQLite
     */
    protected function convertColumnType($definition)
    {
        $map = [
            '/\bTINYINT\(\d+\)\b/i' => 'INTEGER',
            '/\bSMALLINT\(\d+\)\b/i' => 'INTEGER',
            '/\bMEDIUMINT\(\d+\)\b/i' => 'INTEGER',
            '/\bBIGINT\(\d+\)\b/i' => 'INTEGER',
            '/\bINT\(\d+\)\b/i' => 'INTEGER',
            '/\bBIGINT\b/i' => 'INTEGER',
            '/\bINT\b/i' => 'INTEGER',
            '/\bDOUBLE\b/i' => 'REAL',
            '/\bFLOAT\b/i' => 'REAL',
            '/\bDECIMAL\([^)]*\)/i' => 'NUMERIC',
            '/\bNUMERIC\([^)]*\)/i' => 'NUMERIC',
            '/\bVARBINARY\(\d+\)\b/i' => 'BLOB',
            '/\bBINARY\(\d+\)\b/i' => 'BLOB',
            '/\bLONGTEXT\b/i' => 'TEXT',
            '/\bMEDIUMTEXT\b/i' => 'TEXT',
            '/\bTINYTEXT\b/i' => 'TEXT',
            '/\bTEXT\b/i' => 'TEXT',
            '/\bVARCHAR\(\d+\)\b/i' => 'TEXT',
            '/\bCHAR\(\d+\)\b/i' => 'TEXT',
            '/\bDATETIME\b/i' => 'TEXT',
            '/\bTIMESTAMP\b/i' => 'TEXT',
            '/\bDATE\b/i' => 'TEXT',
            '/\bTIME\b/i' => 'TEXT',
            '/\bENUM\([^)]+\)/i' => 'TEXT',
            '/\bSET\([^)]+\)/i' => 'TEXT',
        ];

        foreach ($map as $pattern => $replacement) {
            $definition = preg_replace($pattern, $replacement, $definition);
        }

        return $definition;
    }

    /**
     * 获取索引列定义
     */
    protected function extractColumnsFromDefinition($definition)
    {
        if (preg_match('/\((.+)\)/s', $definition, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * 转换索引列为 SQLite 兼容格式
     */
    protected function normalizeIndexColumns($columnsString)
    {
        $columns = $this->extractColumnNames($columnsString);

        $quoted = array_map(function ($column) {
            return sprintf('"%s"', $column);
        }, $columns);

        return implode(', ', $quoted);
    }

    /**
     * 解析索引列名称
     */
    protected function extractColumnNames($columnsString)
    {
        $parts = preg_split('/\s*,\s*/', $columnsString);
        $columns = [];

        foreach ($parts as $part) {
            $column = trim($part);
            $column = preg_replace('/`([^`]+)`/', '$1', $column);
            $column = preg_replace('/"([^"]+)"/', '$1', $column);
            $column = preg_replace('/\(\d+\)/', '', $column);
            $column = preg_replace('/\s+(ASC|DESC)$/i', '', $column);

            if ($column !== '') {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * 清理索引名称
     */
    protected function sanitizeIdentifier($identifier)
    {
        $clean = preg_replace('/[^A-Za-z0-9_]+/', '_', $identifier);
        return trim($clean, '_');
    }

    /**
     * 导出表数据
     *
     * @param string $tableName
     * @return void
     */
    protected function exportTableData($tableName, $rowCount = 0)
    {
        $metadata = $this->tableMetadata[$tableName] ?? [];
        $chunkColumn = $metadata['chunk_column'] ?? null;
        $insertBatchSize = $this->getSqliteInsertBatchSize();
        $buffer = [];

        $query = DB::connection($this->sourceConnection)
            ->table($tableName);

        if ($chunkColumn) {
            $query->orderBy($chunkColumn);
        }

        // 禁用外键约束
        DB::connection('sqlite_export')->statement('PRAGMA foreign_keys = OFF');

        $dataBar = null;
        if ($rowCount > 0) {
            $this->output->newLine();
            $dataBar = $this->output->createProgressBar($rowCount);
            $dataBar->setBarCharacter('▓');
            $dataBar->setEmptyBarCharacter('░');
            $dataBar->setFormat('  %current%/%max% 行 (%percent:3s%%)');
            $dataBar->start();
        }

        foreach ($query->cursor() as $row) {
            $buffer[] = (array) $row;

            if (count($buffer) >= $insertBatchSize) {
                $count = count($buffer);
                $this->insertRowsIntoSqlite($tableName, $buffer);
                $this->stats['rows'] += $count;
                if ($dataBar) {
                    $dataBar->advance($count);
                }
                $buffer = [];
            }
        }

        if (!empty($buffer)) {
            $count = count($buffer);
            $this->insertRowsIntoSqlite($tableName, $buffer);
            $this->stats['rows'] += $count;
            if ($dataBar) {
                $dataBar->advance($count);
            }
        }

        if ($dataBar) {
            $dataBar->finish();
            $this->output->newLine(2);
        }

        // 重新启用外键约束
        DB::connection('sqlite_export')->statement('PRAGMA foreign_keys = ON');
    }

    /**
     * 批量寫入資料到 SQLite
     */
    protected function insertRowsIntoSqlite($tableName, array $rows)
    {
        DB::connection('sqlite_export')
            ->table($tableName)
            ->insert($rows);
    }

    /**
     * 计算数据表的总行数
     */
    protected function getTableRowCount($tableName)
    {
        try {
            return (int) DB::connection($this->sourceConnection)
                ->table($tableName)
                ->count();
        } catch (\Exception $e) {
            $this->warn(sprintf('⚠ 无法统计表 %s 行数: %s', $tableName, $e->getMessage()));
            return 0;
        }
    }

    /**
     * 取得 SQLite 插入批次大小。SQLite 允許的 compound SELECT 條件數為 500，
     * 但保守使用 400 以避免觸發 "too many terms" 錯誤。
     */
    protected function getSqliteInsertBatchSize()
    {
        return 400;
    }

    /**
     * 显示统计信息
     *
     * @return void
     */
    protected function displayStats()
    {
        $this->info('=== 导出完成 ===');
        $this->info(sprintf('✓ 成功导出 %d 个表', $this->stats['tables']));

        if (!$this->option('schema-only')) {
            $this->info(sprintf('✓ 共导出 %s 行数据', number_format($this->stats['rows'])));
        }

        if ($this->stats['errors'] > 0) {
            $this->warn(sprintf('⚠ 遇到 %d 个错误', $this->stats['errors']));
        }

        $this->output->newLine();
        $this->info(sprintf('SQLite 数据库路径: %s', base_path($this->outputPath)));

        // 显示文件大小
        $fileSize = filesize(base_path($this->outputPath));
        $this->info(sprintf('文件大小: %s', $this->formatBytes($fileSize)));

        $this->output->newLine();
        $this->info('下一步:');
        $this->line('  1. 更新 .env 文件:');
        $this->line('     DB_CONNECTION=sqlite');
        $this->line(sprintf('     DB_DATABASE=%s', base_path($this->outputPath)));
        $this->line('  2. 测试应用: php artisan serve');
    }

    /**
     * 格式化字节数
     *
     * @param int $bytes
     * @return string
     */
    protected function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return sprintf('%.2f %s', $bytes, $units[$unitIndex]);
    }
}
