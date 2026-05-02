<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

class ExportMysqlToSqlite extends Command {
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
                            {--source=mysql : 源数据库连接名称}
                            {--with-indexes : 包含索引定义（默认跳过）}
                            {--with-internal : 包含 CBDB__ 开头的内部表（默认跳过）}
                            {--limit-records= : 限制每张表导出的最大记录数}
                            {--chunk-size=5000 : 分块查询的大小（减少内存使用）}
                            {--skip-row-count : 跳过每张表的 COUNT(*) 统计}
                            {--min-free-space=1 : 最小可用磁盘空间（GB）}
                            {--skip-space-check : 跳过磁盘空间检查}
                            {--append : 追加模式，将表添加到现有 SQLite 文件中（不删除现有文件）}';

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
    public function handle() {
        $this->sourceConnection = $this->option('source');
        $this->outputPath = $this->option('output');

        // 验证源数据库连接
        if (!$this->validateSourceConnection()) {
            return 1;
        }

        // 检查磁盘空间
        if (!$this->option('skip-space-check') && !$this->checkDiskSpace()) {
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
                // 显示当前正在导出的表名
                $bar->clear();
                $this->info(sprintf('正在导出表: %s', $table['name']));
                $bar->display();

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

        // 如果有错误，返回非零退出码
        return $this->stats['errors'] > 0 ? 1 : 0;
    }

    /**
     * 验证源数据库连接
     *
     * @return bool
     */
    protected function validateSourceConnection() {
        try {
            $driver = DB::connection($this->sourceConnection)->getDriverName();

            if ($driver !== 'mysql') {
                $this->error(sprintf('源数据库必须是 MySQL，当前是: %s', $driver));

                return false;
            }

            // 测试连接
            $pdo = DB::connection($this->sourceConnection)->getPdo();
            // 使用 unbuffered query，降低内存峰值
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

            $this->info(sprintf('✓ 源数据库连接正常 (%s)', $this->sourceConnection));

            return true;
        } catch (\Exception $e) {
            $this->error(sprintf('无法连接到源数据库: %s', $e->getMessage()));

            return false;
        }
    }

    /**
     * 检查磁盘空间是否足够
     *
     * @return bool
     */
    protected function checkDiskSpace() {
        $paths = [
            base_path(dirname($this->outputPath)), // SQLite 输出目录
            sys_get_temp_dir(), // 系统临时目录
        ];

        $minFreeSpaceGB = (float) $this->option('min-free-space');
        $minFreeSpaceBytes = $minFreeSpaceGB * 1024 * 1024 * 1024;

        $allOk = true;

        foreach ($paths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $freeSpace = disk_free_space($path);

            if ($freeSpace === false) {
                $this->warn(sprintf('⚠ 无法检查 %s 的磁盘空间', $path));

                continue;
            }

            $freeSpaceGB = $freeSpace / 1024 / 1024 / 1024;

            if ($freeSpace < $minFreeSpaceBytes) {
                $this->error(sprintf(
                    '✗ 磁盘空间不足: %s (可用: %.2f GB, 需要: %.2f GB)',
                    $path,
                    $freeSpaceGB,
                    $minFreeSpaceGB
                ));
                $allOk = false;
            } else {
                $this->info(sprintf('✓ 磁盘空间充足: %s (可用: %.2f GB)', $path, $freeSpaceGB));
            }
        }

        if (!$allOk) {
            $this->output->newLine();
            $this->line('建议解决方案:');
            $this->line('  1. 清理临时文件: rm -rf /tmp/*');
            $this->line('  2. 使用 --limit-records=N 限制导出数据量');
            $this->line('  3. 使用 --skip-space-check 强制继续（不推荐）');
            $this->output->newLine();
        }

        return $allOk;
    }

    /**
     * 准备 SQLite 数据库
     *
     * @return bool
     */
    protected function prepareSqliteDatabase() {
        $absolutePath = base_path($this->outputPath);
        $directory = dirname($absolutePath);
        $isAppendMode = $this->option('append');

        // 确保目录存在
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                $this->error(sprintf('无法创建目录: %s', $directory));

                return false;
            }
        }

        // 如果文件已存在
        if (file_exists($absolutePath)) {
            if ($isAppendMode) {
                // 追加模式：保留现有文件
                $this->info(sprintf('✓ 追加模式：使用现有 SQLite 文件: %s', $this->outputPath));
            } else {
                // 覆盖模式：询问是否覆盖
                if (!$this->confirm(sprintf('文件 %s 已存在，是否覆盖？', $this->outputPath), false)) {
                    $this->info('操作已取消');

                    return false;
                }

                unlink($absolutePath);
                // 创建空的 SQLite 数据库文件
                touch($absolutePath);
            }
        } else {
            // 文件不存在，创建新文件
            touch($absolutePath);
        }

        // 配置临时的 SQLite 连接
        config([
            'database.connections.sqlite_export' => [
                'driver' => 'sqlite',
                'database' => $absolutePath,
                'prefix' => '',
                'foreign_key_constraints' => false, // 导出时先禁用外键
            ],
        ]);

        try {
            DB::connection('sqlite_export')->getPdo();

            if ($isAppendMode && file_exists($absolutePath)) {
                $this->info(sprintf('✓ SQLite 数据库已连接: %s', $this->outputPath));
            } else {
                $this->info(sprintf('✓ SQLite 数据库已创建: %s', $this->outputPath));
            }

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
    protected function getTablesToExport() {
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

        // 过滤掉 CBDB__ 开头的内部表（除非用户明确指定 --with-internal）
        if (!$this->option('with-internal')) {
            $result = array_filter($result, function ($table) {
                return strpos($table['name'], 'CBDB__') !== 0;
            });
        }

        return array_values($result);
    }

    /**
     * 导出单个表
     *
     * @param string $tableName
     * @return void
     */
    protected function exportTable(array $table) {
        $tableName = $table['name'];
        $isView = strtoupper($table['type']) === 'VIEW';

        // 1. 导出表结构
        $this->exportTableSchema($tableName, $isView);

        // 2. 导出数据（如果不是 schema-only 模式且不是视图）
        if (!$isView && !$this->option('schema-only')) {
            $rowCount = null;
            if (!$this->option('skip-row-count')) {
                $rowCount = $this->getTableRowCount($tableName);
            }
            $limit = $this->getRecordLimit();
            if ($limit !== null && $rowCount !== null) {
                $rowCount = min($rowCount, $limit);
            }
            $this->exportTableData($tableName, $rowCount, $limit);
        }

        $this->stats['tables']++;
    }

    /**
     * 导出表结构
     *
     * @param string $tableName
     * @return void
     */
    protected function exportTableSchema($tableName, $isView = false) {
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
    protected function convertCreateTableToSqlite($mysqlSql, $tableName) {
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

        // 记录该列是否为单列主键（可安全用于 chunkById）
        $isSingleColumnPrimaryKey = false;
        $compositePrimaryKey = [];
        $hasPrimaryKey = !empty($primaryKeyColumnsList);

        foreach ($primaryKeyColumnsList as $pkColumns) {
            if (count($pkColumns) === 1 && $pkColumns[0] === $chunkColumn) {
                $isSingleColumnPrimaryKey = true;

                break;
            }
            if (count($pkColumns) > 1) {
                // 記錄複合主鍵列，用於穩定排序
                $compositePrimaryKey = $pkColumns;
            }
        }

        $body = array_merge($columns, $primaryKeys);

        if (empty($body)) {
            throw new \RuntimeException(sprintf('无法生成 %s 的字段定义', $tableName));
        }

        $tableSql = sprintf("CREATE TABLE \"%s\" (\n    %s\n);", $tableName, implode(",\n    ", $body));

        // 根据 --with-indexes 选项决定是否包含索引
        $exportIndexes = $this->option('with-indexes') ? $indexes : [];

        return [
            'table' => $tableSql,
            'indexes' => $exportIndexes,
            'meta' => [
                'chunk_column' => $chunkColumn,
                'is_unique_column' => $isSingleColumnPrimaryKey,
                'composite_primary_key' => $compositePrimaryKey,
                'has_primary_key' => $hasPrimaryKey,
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
    protected function convertCreateViewToSqlite($mysqlSql, $viewName) {
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
    protected function splitDefinitionItems($definitions) {
        $items = [];
        $current = '';
        $depth = 0;
        $inString = false;

        $length = strlen($definitions);

        for ($i = 0; $i < $length; $i++) {
            $char = $definitions[$i];

            if ($inString) {
                $current .= $char;

                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $definitions[++$i];

                    continue;
                }

                if ($char === "'" && $i + 1 < $length && $definitions[$i + 1] === "'") {
                    $current .= $definitions[++$i];

                    continue;
                }

                if ($char === "'") {
                    $inString = false;
                }

                continue;
            }

            if ($char === "'") {
                $inString = true;
            } elseif ($char === '(') {
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
    protected function convertColumnDefinition($definition) {
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
        // SQLite 不支援 COMMENT 子句，整段移除。
        // 必須處理 MySQL 對單引號的兩種跳脫方式：'' (SQL 標準) 與 \' (MySQL 擴充)，
        // 否則 COMMENT 內含撇號時非貪婪 .*? 會在第一個內部單引號就提前結束，
        // 殘留字串字面量會破壞後續 SQLite DDL 解析。
        $rest = preg_replace('/\bCOMMENT\s+\'(?:[^\'\\\\]|\\\\.|\'\')*\'/i', '', $rest);
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
    protected function convertColumnType($definition) {
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
    protected function extractColumnsFromDefinition($definition) {
        if (preg_match('/\((.+)\)/s', $definition, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * 转换索引列为 SQLite 兼容格式
     */
    protected function normalizeIndexColumns($columnsString) {
        $columns = $this->extractColumnNames($columnsString);

        $quoted = array_map(function ($column) {
            return sprintf('"%s"', $column);
        }, $columns);

        return implode(', ', $quoted);
    }

    /**
     * 解析索引列名称
     */
    protected function extractColumnNames($columnsString) {
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
    protected function sanitizeIdentifier($identifier) {
        $clean = preg_replace('/[^A-Za-z0-9_]+/', '_', $identifier);

        return trim($clean, '_');
    }

    /**
     * 导出表数据
     *
     * @param string $tableName
     * @return void
     */
    protected function exportTableData($tableName, $rowCount = 0, $limit = null) {
        $metadata = $this->tableMetadata[$tableName] ?? [];
        $chunkColumn = $metadata['chunk_column'] ?? null;
        $isUniqueColumn = $metadata['is_unique_column'] ?? false;
        $compositePrimaryKey = $metadata['composite_primary_key'] ?? [];
        $hasPrimaryKey = $metadata['has_primary_key'] ?? true;
        $insertBatchSize = $this->getSqliteInsertBatchSize();
        $chunkSize = (int) $this->option('chunk-size');
        $indexInfo = null;

        // 禁用外键约束
        DB::connection('sqlite_export')->statement('PRAGMA foreign_keys = OFF');

        $dataBar = null;
        if ($rowCount !== null && $rowCount > 0) {
            $this->output->newLine();
            $dataBar = $this->output->createProgressBar($rowCount);
            $dataBar->setBarCharacter('▓');
            $dataBar->setEmptyBarCharacter('░');
            $dataBar->setFormat('  %current%/%max% 行 (%percent:3s%%)');
            $dataBar->start();
        } else {
            $this->output->newLine();
            $this->info('  统计行数已跳过，使用不定长度导出模式。');
        }

        if ($limit === null) {
            $limit = $this->getRecordLimit();
        }

        // 使用分块查询，总是保证排序以确保数据完整性
        $processedRows = 0;
        $buffer = [];

        try {
            $query = DB::connection($this->sourceConnection)
                ->table($tableName);

            $chunkCallback = function ($rows) use (
                $tableName,
                $insertBatchSize,
                &$buffer,
                &$processedRows,
                $dataBar,
                $limit
            ) {
                foreach ($rows as $row) {
                    // 检查是否达到限制
                    if ($limit !== null && $processedRows >= $limit) {
                        return false; // 停止 chunk 迭代
                    }

                    $buffer[] = (array) $row;
                    $processedRows++;

                    // 当缓冲区达到批次大小时，写入 SQLite
                    if (count($buffer) >= $insertBatchSize) {
                        $count = count($buffer);
                        $this->insertRowsIntoSqlite($tableName, $buffer);
                        $this->stats['rows'] += $count;

                        if ($dataBar) {
                            $dataBar->advance($count);
                        }

                        $buffer = [];

                        // 定期释放内存
                        if ($processedRows % 10000 === 0) {
                            gc_collect_cycles();
                        }
                    }
                }

                return true; // 继续下一批
            };

            // 根據表結構選擇最佳的數據讀取策略
            if (!$hasPrimaryKey) {
                // 無主鍵表：使用 cursor() 單次查詢，保證順序絕對穩定
                // 這樣避免 offset/limit 在非唯一列上的不確定行為
                foreach ($query->cursor() as $row) {
                    // 检查是否达到限制
                    if ($limit !== null && $processedRows >= $limit) {
                        break;
                    }

                    $buffer[] = (array) $row;
                    $processedRows++;

                    // 当缓冲区达到批次大小时，写入 SQLite
                    if (count($buffer) >= $insertBatchSize) {
                        $count = count($buffer);
                        $this->insertRowsIntoSqlite($tableName, $buffer);
                        $this->stats['rows'] += $count;

                        if ($dataBar) {
                            $dataBar->advance($count);
                        }

                        $buffer = [];

                        // 定期释放内存
                        if ($processedRows % 10000 === 0) {
                            gc_collect_cycles();
                        }
                    }
                }
            } elseif ($chunkColumn && $isUniqueColumn) {
                // 單列主鍵表：使用 chunkById()，高效且安全
                $query->chunkById($chunkSize, $chunkCallback, $chunkColumn);
            } else {
                // 複合主鍵表：按所有主鍵列排序 + chunk()，確保穩定排序
                if (!empty($compositePrimaryKey)) {
                    foreach ($compositePrimaryKey as $pkColumn) {
                        $query->orderBy($pkColumn);
                    }
                    if ($indexInfo === null) {
                        $indexInfo = $this->getTableIndexInfo($tableName);
                    }
                    if (!$this->hasLeadingIndexColumns($indexInfo, $compositePrimaryKey)) {
                        $this->warn(sprintf(
                            '⚠ 表 %s 對排序欄位 (%s) 沒有對應索引，可能會造成 filesort/臨時表',
                            $tableName,
                            implode(', ', $compositePrimaryKey)
                        ));
                    }
                    foreach ($query->cursor() as $row) {
                        // 检查是否达到限制
                        if ($limit !== null && $processedRows >= $limit) {
                            break;
                        }

                        $buffer[] = (array) $row;
                        $processedRows++;

                        // 当缓冲区达到批次大小时，写入 SQLite
                        if (count($buffer) >= $insertBatchSize) {
                            $count = count($buffer);
                            $this->insertRowsIntoSqlite($tableName, $buffer);
                            $this->stats['rows'] += $count;

                            if ($dataBar) {
                                $dataBar->advance($count);
                            }

                            $buffer = [];

                            // 定期释放内存
                            if ($processedRows % 10000 === 0) {
                                gc_collect_cycles();
                            }
                        }
                    }
                } else {
                    // 備用路徑（理論上不應該到達這裡）
                    if (!$chunkColumn) {
                        $columns = DB::connection($this->sourceConnection)
                            ->getSchemaBuilder()
                            ->getColumnListing($tableName);

                        if (empty($columns)) {
                            throw new \RuntimeException(sprintf('表 %s 没有任何列', $tableName));
                        }

                        $chunkColumn = $columns[0];
                    }

                    if ($indexInfo === null) {
                        $indexInfo = $this->getTableIndexInfo($tableName);
                    }
                    if (!$this->hasLeadingIndexColumns($indexInfo, [$chunkColumn])) {
                        $this->warn(sprintf(
                            '⚠ 表 %s 對排序欄位 (%s) 沒有對應索引，可能會造成 filesort/臨時表',
                            $tableName,
                            $chunkColumn
                        ));
                    }
                    $query->orderBy($chunkColumn)->chunk($chunkSize, $chunkCallback);
                }
            }

            // 写入剩余的数据
            if (!empty($buffer)) {
                $count = count($buffer);
                $this->insertRowsIntoSqlite($tableName, $buffer);
                $this->stats['rows'] += $count;

                if ($dataBar) {
                    $dataBar->advance($count);
                }
            }
        } catch (\Exception $e) {
            if ($dataBar) {
                $dataBar->finish();
                $this->output->newLine(2);
            }

            // 提供更有帮助的错误信息
            $errorMsg = $e->getMessage();

            if (strpos($errorMsg, 'No space left on device') !== false) {
                $this->output->newLine();
                $this->error('磁盘空间不足！');
                $this->line('建议解决方案:');
                $this->line('  1. 清理 /tmp 目录: sudo rm -rf /tmp/MY* /tmp/ib*');
                $this->line('  2. 使用 --chunk-size=1000 减小分块大小');
                $this->line('  3. 使用 --limit-records=10000 限制导出数据量');
                $this->line('  4. 增加 /tmp 目录的可用空间');
                $this->output->newLine();
            }

            throw $e;
        }

        if ($dataBar) {
            $dataBar->finish();
            $this->output->newLine(2);
        }

        // 重新启用外键约束
        DB::connection('sqlite_export')->statement('PRAGMA foreign_keys = ON');

        // 最终清理
        gc_collect_cycles();
    }

    /**
     * 批量寫入資料到 SQLite
     */
    protected function insertRowsIntoSqlite($tableName, array $rows) {
        DB::connection('sqlite_export')
            ->table($tableName)
            ->insert($rows);
    }

    /**
     * 取得 MySQL 索引資訊（以索引名稱分組的欄位序列）
     *
     * @return array<string, array<int, string>>
     */
    protected function getTableIndexInfo($tableName) {
        try {
            $rows = DB::connection($this->sourceConnection)
                ->select(sprintf('SHOW INDEX FROM `%s`', str_replace('`', '``', $tableName)));
        } catch (\Exception $e) {
            $this->warn(sprintf('⚠ 无法读取表 %s 的索引信息: %s', $tableName, $e->getMessage()));

            return [];
        }

        $indexes = [];

        foreach ($rows as $row) {
            $keyName = $row->Key_name ?? null;
            $seq = isset($row->Seq_in_index) ? (int) $row->Seq_in_index : null;
            $column = $row->Column_name ?? null;

            if ($keyName === null || $seq === null || $column === null) {
                continue;
            }

            $indexes[$keyName][$seq] = $column;
        }

        foreach ($indexes as $key => $columns) {
            ksort($columns);
            $indexes[$key] = array_values($columns);
        }

        return $indexes;
    }

    /**
     * 檢查是否存在以指定欄位序列為前綴的索引
     *
     * @param array<string, array<int, string>> $indexes
     * @param array<int, string> $columns
     * @return bool
     */
    protected function hasLeadingIndexColumns(array $indexes, array $columns) {
        if (empty($indexes) || empty($columns)) {
            return false;
        }

        foreach ($indexes as $indexColumns) {
            $slice = array_slice($indexColumns, 0, count($columns));
            if ($slice === $columns) {
                return true;
            }
        }

        return false;
    }

    /**
     * 计算数据表的总行数
     */
    protected function getTableRowCount($tableName) {
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
    protected function getSqliteInsertBatchSize() {
        return 100;
    }

    /**
     * 取得每張表的最大導出筆數
     */
    protected function getRecordLimit(): ?int {
        $limit = $this->option('limit-records');

        if ($limit === null || $limit === '') {
            return null;
        }

        $limit = (int) $limit;

        return $limit > 0 ? $limit : null;
    }

    /**
     * 显示统计信息
     *
     * @return void
     */
    protected function displayStats() {
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
    }

    /**
     * 格式化字节数
     *
     * @param int $bytes
     * @return string
     */
    protected function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return sprintf('%.2f %s', $bytes, $units[$unitIndex]);
    }
}
