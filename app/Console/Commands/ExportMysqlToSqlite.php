<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;

class ExportMysqlToSqlite extends Command {
    /**
     * 帳號／憑證表：**匯出結構但預設不匯出資料列**（#1251）。鍵為表名（小寫比對），值為理由。
     *
     * 為什麼是「跳過資料」而不是「跳過整張表」——這點很重要，別改回去：
     *
     * 這個命令原本只排除 `CBDB__` 前綴的內部表，所以不帶 `--tables` 時會把整張 `users`
     * （password 雜湊、`confirmation_token`、`remember_token`、`settings` 內的 IP）與
     * `personal_access_tokens` 一起寫進輸出檔。對外釋出流程（`scripts/export-daily-sqlite.sh`）
     * 一向是逐表帶 `--tables` 的 allowlist，所以線上釋出檔沒有受影響；真正的風險是**開發者
     * 照文檔裸跑這個命令**（指向 prod／staging）時，本機會多出一份含密碼雜湊與長期憑證的檔案。
     *
     * 但這些表**不能整張消失**：`README-Docker.md` 那條裸指令產出的 SQLite 就是本機開發要用的
     * 資料庫，應用需要 `users`／`password_resets`／`personal_access_tokens` **存在**才跑得起來
     * （否則登入頁會在執行期炸 no such table，而匯出當下只印一行警告就成功結束）；
     * `scripts/patch_sqlite_db_for_dev.sh` 的補表清單裡也正是這幾張。
     * 開發要的是「表在」，不是「prod 的帳號列」——所以正確的切法是只跳過資料列。
     *
     * 需要連資料一起匯出時顯式帶 `--with-credentials`，命令會印警告說明匯出了什麼。
     *
     * 表名以小寫比對，涵蓋 `lower_case_table_names=1`（全部存成小寫；**裸跑匯出在這種環境下完全
     * 正常，而裸跑正是本 issue 的風險路徑**，只有帶 `--tables` 的呼叫會因 `getTablesToExport()`
     * 的大小寫敏感查找落空）、`=2`（macOS：照原樣存、比對不分大小寫），以及手工建成 `Users`。
     *
     * 範圍誠實說明：這是**精確表名**比對。備份還原常見的 `users_bak`／`users_20260101` 這類
     * 改名副本**不在保護範圍內**，會連資料一起匯出。對外釋出的第一道防線始終是釋出腳本自己的
     * allowlist（見 docs/SQLITE_DATA_RELEASE.md），這份 blocklist 只是降低裸跑的傷害。
     *
     * 另外注意：跳過這些表的資料列**不等於**輸出檔沒有個人資料——`audit_log` 含 email 與登入
     * IP／User-Agent，`nl_query_logs`／`ai_fill_logs` 含使用者輸入。這份清單擋的是**憑證**
     * （密碼雜湊與 token），不是個資。
     */
    public const CREDENTIAL_TABLES = [
        'users' => 'password bcrypt 雜湊、confirmation_token（舊眾包通道的長期憑證，無到期亦無撤銷機制，見 #1248）、remember_token、settings 內的註冊／登入 IP',
        'personal_access_tokens' => 'Sanctum API token 的 SHA-256 雜湊',
        'password_resets' => '密碼重設用的 email 與 token（本專案實際使用的表名，見 config/auth.php）',
        // Laravel 11/12 的預設表名。本專案用 password_resets，但任何一次跟上框架預設的 migration
        // 或新裝環境都會產生這個名字，屆時 blocklist 會靜默失效——兩個都收，成本為零。
        'password_reset_tokens' => '密碼重設 token（Laravel 11/12 預設表名）',
        // 佇列／快取表：payload 是序列化內容，可能夾帶 token 或憑證；本專案 driver 預設不是
        // database（config/queue.php、config/cache.php），屬防禦性條目。
        'failed_jobs' => '失敗任務的序列化 payload 與例外堆疊，可能夾帶憑證',
        'jobs' => '待處理任務的序列化 payload，可能夾帶憑證',
        'cache' => '快取值，可能含 token',
        'cache_locks' => '快取鎖（與 cache 同組）',
        'sessions' => '登入 session 的 payload（session driver 設為 database 時才存在）',
        // 以下 5 張已由 2025_12_18_050122_remove_passport_oauth_tables 移除，完整 migrate 過的庫
        // 不會有它們（其中 oauth_clients.secret 是明文 client secret）。留在清單裡是因為這份
        // blocklist 的用途正是保護「--source 指向任意／陳舊／從備份還原的 MySQL」那種情境。
        'oauth_access_tokens' => 'Passport access token（舊表，已於 migration 移除）',
        'oauth_refresh_tokens' => 'Passport refresh token（舊表，已於 migration 移除）',
        'oauth_auth_codes' => 'Passport 授權碼（舊表，已於 migration 移除）',
        'oauth_clients' => 'Passport client 的**明文** secret（舊表，已於 migration 移除）',
        'oauth_personal_access_clients' => 'Passport personal access client（舊表，已於 migration 移除）',
    ];

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
                            {--with-credentials : 包含帐号／凭证表（users、personal_access_tokens 等；默认排除，见 CREDENTIAL_TABLES）}
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
     * 軟刪除標記（見 App\Services\Mutations\BiogMainDeleteHandler::DELETE_MARKER）。
     * 匯出時：
     *   1. BIOG_MAIN 本身排除 c_name_chn 為此標記的列；
     *   2. 其餘表中，所有指向 BIOG_MAIN.c_personid 的欄位（包含 c_personid 本身，
     *      以及 ASSOC_DATA.c_kin_id、KIN_DATA.c_kin_id 等透過 FK 宣告的關係欄位）
     *      排除該欄位屬於上述已刪除人物的列，
     * 避免已刪除人物的資料或其與他人的關係外流到公開釋出檔。
     *
     * @var string
     */
    protected const DELETED_NAME_MARKER = '<待删除>';

    /**
     * 額外的人物 ID 欄位對照表：用於資料庫中「語意上」指向 BIOG_MAIN.c_personid，
     * 但因故未宣告正式 FK、無法透過 information_schema 自動偵測的欄位。
     * 例如 MERGED_PERSON_DATA.c_merged_from_personid（重複人物合併來源，見遷移
     * database/migrations/2025_11_12_140940_rename_merged_to_personid_column_in_merged_person_data_table.php）。
     * 新增此類欄位前應優先確認資料庫端是否能補上正式 FK，讓自動偵測涵蓋。
     *
     * @var array<string, array<int, string>>
     */
    protected const EXTRA_PERSON_ID_COLUMNS = [
        'MERGED_PERSON_DATA' => ['c_merged_from_personid'],
    ];

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
            $driver = $this->getSourceDriver();

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
        $result = $this->fetchAllTables();

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
     * 取來源資料庫的全部表（表名 => ['name','type']）。
     *
     * 從 getTablesToExport() 抽出成獨立方法，讓「要匯出哪些表」的決策邏輯可以在測試中
     * 不連 MySQL 就驗證（`SHOW FULL TABLES` 是 MySQL 專屬語法，SQLite 測試庫跑不了）。
     *
     * @return array<string,array{name:string,type:string}>
     */
    protected function fetchAllTables() {
        $connection = DB::connection($this->sourceConnection);
        $tables = $connection->select('SHOW FULL TABLES');
        $tableKey = 'Tables_in_' . $connection->getDatabaseName();

        $result = [];

        foreach ($tables as $table) {
            $name = $table->$tableKey;
            $result[$name] = [
                'name' => $name,
                'type' => isset($table->Table_type) ? strtoupper($table->Table_type) : 'BASE TABLE',
            ];
        }

        return $result;
    }

    /**
     * 這張表是否為帳號／憑證表（見 CREDENTIAL_TABLES）。
     */
    protected function isCredentialTable($tableName) {
        return isset(self::CREDENTIAL_TABLES[strtolower((string) $tableName)]);
    }

    /**
     * 是否該跳過這張表的**資料列**（結構一律照匯，理由見 CREDENTIAL_TABLES 的 docblock）。
     */
    protected function shouldSkipTableData($tableName) {
        return $this->isCredentialTable($tableName) && !$this->option('with-credentials');
    }

    /**
     * 憑證表的提示訊息（跳過資料 / 連資料一起匯出兩種）。
     *
     * 三條訊息：`--schema-only`（本來就不匯資料）／跳過資料／連資料一起匯。抽成獨立方法是為了
     * 讓訊息用字可以單獨斷言——後兩條都含 `--with-credentials`，光看有沒有那個字串分不出
     * 「跳過了」與「匯出了」。
     *
     * 注意：`exportTable()` 本身**是可測的**——測試以匿名子類別覆寫 `exportTableSchema()`／
     * `exportTableData()` 即可在 SQLite 環境下驗證「結構有匯、資料沒匯」，見
     * ExportMysqlToSqliteExcludesCredentialTablesTest::export_table_writes_schema_but_skips_rows()。
     * 別把這個 helper 當成「不必測 exportTable」的理由：真正會被誤刪的是 exportTable 裡那段分支。
     *
     * @return string|null 非憑證表回 null
     */
    protected function credentialDataNotice($tableName) {
        if (!$this->isCredentialTable($tableName)) {
            return null;
        }

        $reason = self::CREDENTIAL_TABLES[strtolower((string) $tableName)];

        // --schema-only 本來就不匯任何資料列，此時無論有沒有 --with-credentials 都不該說
        // 「数据列将被导出」——那與事實相反。
        if ($this->option('schema-only')) {
            return sprintf('⚠ %s：--schema-only，只导出结构（%s）', $tableName, $reason);
        }

        if ($this->shouldSkipTableData($tableName)) {
            return sprintf(
                '⚠ %s：已导出结构、跳过数据列（%s）。需要连数据一起导出请加 --with-credentials',
                $tableName,
                $reason
            );
        }

        // 帶了 --with-credentials：不擋，但要讓操作者知道自己正在把什麼寫進檔案
        //（這個檔案通常會被複製、上傳或留在磁碟上）。
        return sprintf('⚠ --with-credentials：%s 的数据列将被导出（%s）', $tableName, $reason);
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

        // 1.5 帐号／凭证表：结构已导出（本机开发需要这些表存在），但默认不复制数据列。
        //     见 CREDENTIAL_TABLES 的 docblock（#1251）。
        //
        //     注意条件的形状：跳不跳数据列**只**看 shouldSkipTableData()，提示讯息纯粹是输出。
        //     早期版本写成 `if (($notice = credentialDataNotice(...)) !== null)` 再在里面判断跳过，
        //     等于把安全决策挂在讯息产生器的回传值上——在 credentialDataNotice() 开头加一句
        //     `if ($this->option('quiet')) return null;`（看起来只是别洗讯息）就会让 `-q` 把
        //     users 的资料列照汇出去。
        if (!$isView && $this->isCredentialTable($tableName)) {
            if (($notice = $this->credentialDataNotice($tableName)) !== null) {
                $this->warn($notice);
            }

            if ($this->shouldSkipTableData($tableName)) {
                $this->stats['tables']++;

                return;
            }
        }

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

        // 在拆掉 ENGINE=...$ 之前，先抽出表級 COMMENT='...'，否則 ENGINE=.*$ 的貪婪匹配會把它吃掉。
        // 表級註解會以 SQL 區塊註解形式置於 CREATE TABLE 開頭，方便 AI agents 直接從 sqlite_master.sql 讀到。
        // s 旗標保險起見也容許註解字面量內換行。
        $tableComment = null;
        if (preg_match('/\bCOMMENT\s*=\s*\'((?:[^\'\\\\]|\\\\.|\'\')*)\'/is', $cleanSql, $tableCommentMatch)) {
            $tableComment = $this->decodeMysqlStringLiteral($tableCommentMatch[1]);
        }

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

        // 表級註解必須放進括號內，否則 SQLite 解析時會丟掉位於 CREATE TABLE 之前
        // 或分號之後的註解。放在第一個欄位前即可被原樣保存到 sqlite_master.sql。
        $bodyText = implode(",\n    ", $body);
        if ($tableComment !== null && $tableComment !== '') {
            $bodyText = sprintf("/* %s */\n    %s", $this->escapeForBlockComment($tableComment), $bodyText);
        }

        $tableSql = sprintf("CREATE TABLE \"%s\" (\n    %s\n);", $tableName, $bodyText);

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
        // SQLite 不支援 COMMENT 子句，但會保留 CREATE TABLE 原文於 sqlite_master.sql。
        // 因此把 COMMENT 內容抽出後改以 SQL 區塊註解 /* ... */ 的形式接在欄位定義之後，
        // AI agents 可直接從 schema 讀到欄位語意，毋須額外的說明文件。
        // 必須處理 MySQL 對單引號的兩種跳脫方式：'' (SQL 標準) 與 \' (MySQL 擴充)，
        // 否則 COMMENT 內含撇號時非貪婪 .*? 會在第一個內部單引號就提前結束，
        // 殘留字串字面量會破壞後續 SQLite DDL 解析。
        // 用 s 旗標讓 . 也匹配換行（MySQL 雖罕見，但允許 COMMENT 字面量內含真實換行），
        // 並用單一 regex + substr_replace 同時抽出與移除，避免兩條 regex 不同步。
        $columnComment = null;
        if (preg_match('/\bCOMMENT\s+\'((?:[^\'\\\\]|\\\\.|\'\')*)\'/is', $rest, $commentMatch, PREG_OFFSET_CAPTURE)) {
            $columnComment = $this->decodeMysqlStringLiteral($commentMatch[1][0]);
            $rest = substr_replace($rest, '', $commentMatch[0][1], strlen($commentMatch[0][0]));
        }
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

        $definition = sprintf('"%s" %s', $columnName, trim($rest));

        if ($columnComment !== null && $columnComment !== '') {
            $definition .= sprintf(' /* %s */', $this->escapeForBlockComment($columnComment));
        }

        return [
            'definition' => $definition,
            'auto_increment' => $autoIncrement,
            'name' => $columnName,
        ];
    }

    /**
     * 將 MySQL 字串字面量內容（去掉外圍單引號之後的字串）解碼為純文字。
     * 處理 SQL 標準 '' 與 MySQL 擴充 \' \" \\ \n \r \t \0 \b \Z 等跳脫。
     *
     * 以位元組（strlen + index）迭代是 UTF-8 安全的：本函式只比對 ASCII 字元
     * （'、\），UTF-8 多位元組續位 (0x80–0xBF) 的值不會與這些 ASCII 衝突，
     * 因此不會把多位元組字元劈開。
     */
    protected function decodeMysqlStringLiteral($literal) {
        $result = '';
        $length = strlen($literal);
        for ($i = 0; $i < $length; $i++) {
            $ch = $literal[$i];
            if ($ch === "'" && $i + 1 < $length && $literal[$i + 1] === "'") {
                $result .= "'";
                $i++;

                continue;
            }
            if ($ch === '\\' && $i + 1 < $length) {
                $next = $literal[$i + 1];
                $map = [
                    "'" => "'",
                    '"' => '"',
                    '\\' => '\\',
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '0' => "\0",
                    'b' => "\x08",
                    'Z' => "\x1a",
                ];
                if (isset($map[$next])) {
                    $result .= $map[$next];
                    $i++;

                    continue;
                }
                // 其他跳脫直接保留 next 字元（MySQL 行為）
                $result .= $next;
                $i++;

                continue;
            }
            $result .= $ch;
        }

        return $result;
    }

    /**
     * 把字串轉義成可安全嵌入 /* ... *​/ 的內容：
     * - 把所有 *​/ 變成 * /，避免提早結束區塊註解
     * - 把控制字元（換行、tab 等）壓成空白，讓註解保持單行
     */
    protected function escapeForBlockComment($text) {
        $text = preg_replace('/\*\//', '* /', $text);
        $text = preg_replace('/[\x00-\x1f]+/', ' ', $text);

        return trim($text);
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
            $this->excludeSoftDeletedPersons($tableName, $query);

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
     * 计算数据表的总行数。
     *
     * 建立過濾條件（excludeSoftDeletedPersons，含 MySQL information_schema 查詢）刻意放在
     * try/catch 之外：該查詢若失敗必須直接中斷匯出，不可被下方「統計失敗就降級為 0」的
     * 容錯邏輯吞掉，否則已刪除人物的關係欄位可能在未被察覺的情況下外流。
     * try/catch 僅保護 COUNT(*) 本身的執行（逾時、鎖等統計性失敗），這類失敗只影響
     * 進度條與 --limit-records 估算，不影響實際匯出資料是否已正確過濾。
     */
    protected function getTableRowCount($tableName) {
        $query = DB::connection($this->sourceConnection)->table($tableName);
        $this->excludeSoftDeletedPersons($tableName, $query);

        try {
            return (int) $query->count();
        } catch (\Exception $e) {
            $this->warn(sprintf('⚠ 无法统计表 %s 行数: %s', $tableName, $e->getMessage()));

            return 0;
        }
    }

    /**
     * 排除已被軟刪除人物的資料列，避免其外流到公開釋出檔：
     *   - BIOG_MAIN 本身：排除 c_name_chn = DELETED_NAME_MARKER 的列。
     *   - 其餘表：排除所有指向 BIOG_MAIN.c_personid 的欄位（見 getPersonIdColumnsForTable()）
     *     屬於上述已刪除人物的列，涵蓋該人物自己的資料列，以及他人紀錄中提及該人物的
     *     關係欄位（如 KIN_DATA.c_kin_id、ASSOC_DATA.c_assoc_id 等）。
     *   - 沒有任何人物 ID 欄位的表（代碼表等）不受影響。
     * 相關欄位為 NULL 時不視為已刪除，予以保留；一列只要有任一欄位命中已刪除人物即排除。
     *
     * @param string $tableName
     * @param \Illuminate\Database\Query\Builder $query
     * @return \Illuminate\Database\Query\Builder
     */
    protected function excludeSoftDeletedPersons($tableName, $query) {
        if ($tableName === 'BIOG_MAIN') {
            return $query->where(function ($q) {
                $q->where('c_name_chn', '!=', self::DELETED_NAME_MARKER)
                    ->orWhereNull('c_name_chn');
            });
        }

        $personIdColumns = $this->getPersonIdColumnsForTable($tableName);

        if (empty($personIdColumns)) {
            return $query;
        }

        return $query->where(function ($q) use ($personIdColumns) {
            foreach ($personIdColumns as $column) {
                $q->where(function ($columnQuery) use ($column) {
                    $columnQuery->whereNull($column)
                        ->orWhereNotIn($column, function ($sub) {
                            $sub->select('c_personid')
                                ->from('BIOG_MAIN')
                                ->where('c_name_chn', self::DELETED_NAME_MARKER);
                        });
                });
            }
        });
    }

    /**
     * 取得指定表中所有指向 BIOG_MAIN.c_personid 的欄位名稱：
     *   1. 欄位名稱本身即為 c_personid（最常見的「擁有者」欄位）。
     *   2. 透過 information_schema 偵測到的、正式宣告 FK 指向 BIOG_MAIN.c_personid 的欄位
     *      （如 ASSOC_DATA.c_kin_id、ENTRY_DATA.c_assoc_id 等關係欄位）。
     *   3. EXTRA_PERSON_ID_COLUMNS 中列出的例外欄位（未宣告 FK 但語意上仍指向人物）。
     * 正式匯出時若 MySQL information_schema 查詢失敗，必須直接拋錯中止；僅當來源本來就不是
     * MySQL（例如測試用 SQLite 連線）時，才略過第 2 點並回退為第 1、3 點。
     *
     * @param string $tableName
     * @return array<int, string>
     */
    protected function getPersonIdColumnsForTable($tableName) {
        $columns = [];

        if (Schema::connection($this->sourceConnection)->hasColumn($tableName, 'c_personid')) {
            $columns[] = 'c_personid';
        }

        // information_schema 僅 MySQL 來源可查詢；正式匯出的 sourceConnection 一律是 MySQL
        // （見 validateSourceConnection()），此處若查詢失敗必須真的中斷匯出，不可悄悄降級，
        // 否則可能讓已刪除人物的關係欄位在未被察覺的情況下外流。僅當來源本來就不是 MySQL
        // （例如測試使用 SQLite 連線）才略過此步驟。
        if ($this->sourceUsesInformationSchemaPersonReferences()) {
            $columns = array_merge($columns, $this->getMysqlPersonIdColumnsFromInformationSchema($tableName));
        }

        foreach (self::EXTRA_PERSON_ID_COLUMNS[$tableName] ?? [] as $extraColumn) {
            $columns[] = $extraColumn;
        }

        return array_values(array_unique($columns));
    }

    /**
     * 取得來源資料庫 driver 名稱。
     */
    protected function getSourceDriver(): string {
        return DB::connection($this->sourceConnection)->getDriverName();
    }

    /**
     * 正式匯出時是否應依賴 MySQL information_schema 偵測人物 FK 欄位。
     */
    protected function sourceUsesInformationSchemaPersonReferences(): bool {
        return $this->getSourceDriver() === 'mysql';
    }

    /**
     * 透過 MySQL information_schema 取得所有 FK 指向 BIOG_MAIN.c_personid 的欄位。
     *
     * @param string $tableName
     * @return array<int, string>
     */
    protected function getMysqlPersonIdColumnsFromInformationSchema($tableName): array {
        $databaseName = DB::connection($this->sourceConnection)->getDatabaseName();
        $rows = DB::connection($this->sourceConnection)->select(
            'SELECT DISTINCT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE '
            . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? '
            . 'AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ?',
            [$databaseName, $tableName, 'BIOG_MAIN', 'c_personid']
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[] = $row->COLUMN_NAME;
        }

        return $columns;
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
