<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        $this->newLine();

        // 导出表结构和数据
        $bar = $this->output->createProgressBar(count($tables));
        $bar->start();

        foreach ($tables as $table) {
            try {
                $this->exportTable($table);
                $bar->advance();
            } catch (\Exception $e) {
                $this->stats['errors']++;
                $this->newLine();
                $this->error(sprintf('导出表 %s 失败: %s', $table, $e->getMessage()));
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);

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

        if ($specifiedTables) {
            return array_map('trim', explode(',', $specifiedTables));
        }

        // 获取所有表
        $tables = DB::connection($this->sourceConnection)
            ->select('SHOW TABLES');

        $databaseName = DB::connection($this->sourceConnection)->getDatabaseName();
        $tableKey = 'Tables_in_' . $databaseName;

        return array_map(function ($table) use ($tableKey) {
            return $table->$tableKey;
        }, $tables);
    }

    /**
     * 导出单个表
     *
     * @param string $tableName
     * @return void
     */
    protected function exportTable($tableName)
    {
        // 1. 导出表结构
        $this->exportTableSchema($tableName);

        // 2. 导出数据（如果不是 schema-only 模式）
        if (!$this->option('schema-only')) {
            $this->exportTableData($tableName);
        }

        $this->stats['tables']++;
    }

    /**
     * 导出表结构
     *
     * @param string $tableName
     * @return void
     */
    protected function exportTableSchema($tableName)
    {
        // 获取 MySQL 的 CREATE TABLE 语句
        $createTableResult = DB::connection($this->sourceConnection)
            ->select("SHOW CREATE TABLE `{$tableName}`");

        $createTableSql = $createTableResult[0]->{'Create Table'};

        // 转换为 SQLite 兼容的 SQL
        $sqliteCreateSql = $this->convertCreateTableToSqlite($createTableSql, $tableName);

        // 在 SQLite 中执行
        DB::connection('sqlite_export')->statement($sqliteCreateSql);
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
        // 移除 MySQL 特定的选项
        $sql = preg_replace('/ENGINE=\w+/i', '', $mysqlSql);
        $sql = preg_replace('/DEFAULT CHARSET=\w+/i', '', $sql);
        $sql = preg_replace('/COLLATE=\w+/i', '', $sql);
        $sql = preg_replace('/ROW_FORMAT=\w+/i', '', $sql);
        $sql = preg_replace('/AUTO_INCREMENT=\d+/i', '', $sql);
        $sql = preg_replace('/COMMENT\s*=\s*\'[^\']*\'/i', '', $sql);

        // 转换数据类型
        $sql = preg_replace('/\s+unsigned/i', '', $sql);
        $sql = preg_replace('/VARBINARY\(\d+\)/i', 'BLOB', $sql);
        $sql = preg_replace('/TINYINT\(\d+\)/i', 'INTEGER', $sql);
        $sql = preg_replace('/SMALLINT\(\d+\)/i', 'INTEGER', $sql);
        $sql = preg_replace('/MEDIUMINT\(\d+\)/i', 'INTEGER', $sql);
        $sql = preg_replace('/BIGINT\(\d+\)/i', 'INTEGER', $sql);
        $sql = preg_replace('/INT\(\d+\)/i', 'INTEGER', $sql);
        $sql = preg_replace('/DOUBLE/i', 'REAL', $sql);
        $sql = preg_replace('/DATETIME/i', 'TEXT', $sql);
        $sql = preg_replace('/TIMESTAMP/i', 'TEXT', $sql);
        $sql = preg_replace('/ENUM\([^)]+\)/i', 'TEXT', $sql);

        // 转换 VARCHAR/CHAR 为 TEXT（SQLite 没有长度限制）
        $sql = preg_replace('/VARCHAR\(\d+\)/i', 'TEXT', $sql);
        $sql = preg_replace('/CHAR\(\d+\)/i', 'TEXT', $sql);

        // 移除 CHARACTER SET 和 COLLATE 子句
        $sql = preg_replace('/CHARACTER SET \w+/i', '', $sql);
        $sql = preg_replace('/COLLATE \w+/i', '', $sql);

        // 移除列级 COMMENT
        $sql = preg_replace('/COMMENT \'[^\']*\'/i', '', $sql);

        // 处理 AUTO_INCREMENT（转换为 AUTOINCREMENT）
        $sql = preg_replace('/AUTO_INCREMENT/i', 'AUTOINCREMENT', $sql);

        // 移除 USING BTREE/HASH
        $sql = preg_replace('/USING (BTREE|HASH)/i', '', $sql);

        // 移除外键约束（稍后单独处理）
        $sql = preg_replace('/,\s*CONSTRAINT[^,]+FOREIGN KEY[^,]+REFERENCES[^,)]+/is', '', $sql);
        $sql = preg_replace('/,\s*FOREIGN KEY[^,]+REFERENCES[^,)]+/is', '', $sql);

        // 清理多余的逗号和空格
        $sql = preg_replace('/,\s*,/', ',', $sql);
        $sql = preg_replace('/,\s*\)/', ')', $sql);
        $sql = preg_replace('/\s+/', ' ', $sql);
        $sql = trim($sql);

        return $sql;
    }

    /**
     * 导出表数据
     *
     * @param string $tableName
     * @return void
     */
    protected function exportTableData($tableName)
    {
        $batchSize = (int) $this->option('batch');

        // 获取总行数
        $totalRows = DB::connection($this->sourceConnection)
            ->table($tableName)
            ->count();

        if ($totalRows === 0) {
            return;
        }

        // 禁用外键约束
        DB::connection('sqlite_export')->statement('PRAGMA foreign_keys = OFF');

        // 分批导出数据
        DB::connection($this->sourceConnection)
            ->table($tableName)
            ->orderBy(DB::raw('1')) // 使用常量排序避免表没有主键的情况
            ->chunk($batchSize, function ($rows) use ($tableName) {
                $data = json_decode(json_encode($rows), true);

                // 批量插入到 SQLite
                DB::connection('sqlite_export')
                    ->table($tableName)
                    ->insert($data);

                $this->stats['rows'] += count($data);
            });

        // 重新启用外键约束
        DB::connection('sqlite_export')->statement('PRAGMA foreign_keys = ON');
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

        $this->newLine();
        $this->info(sprintf('SQLite 数据库路径: %s', base_path($this->outputPath)));

        // 显示文件大小
        $fileSize = filesize(base_path($this->outputPath));
        $this->info(sprintf('文件大小: %s', $this->formatBytes($fileSize)));

        $this->newLine();
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
