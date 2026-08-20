<?php

namespace Tests\Feature;

use App\Console\Commands\ExportMysqlToSqlite;
use Illuminate\Console\OutputStyle;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * #1251：`db:export-to-sqlite` 預設不得把帳號／憑證表的**資料列**寫進輸出檔，
 * 但**必須照匯它們的結構**。
 *
 * 背景：這個命令原本只排除 `CBDB__` 前綴的內部表，所以不帶 `--tables` 時會把整張 `users`
 * （password 雜湊、`confirmation_token`、`remember_token`、`settings` 內的 IP）與
 * `personal_access_tokens` 一起寫進輸出檔。對外釋出流程（`scripts/export-daily-sqlite.sh`）
 * 一向是逐表帶 `--tables` 的 allowlist，所以線上釋出檔並未受影響（HuggingFace 上的檔案確認
 * 沒有 `users`）；真正的風險是**開發者照文檔裸跑這個命令**指向 prod／staging。
 *
 * 為什麼是「跳過資料」而不是「跳過整張表」：`README-Docker.md` 那條裸指令產出的 SQLite 就是
 * 本機開發要用的資料庫，應用需要這幾張表**存在**才跑得起來（否則登入頁在執行期炸
 * no such table，而匯出當下只印一行警告就成功結束）。開發要的是「表在」，不是「prod 的帳號列」。
 *
 * 這裡釘住五件事：
 *  1. 憑證表**仍留在匯出清單內**（結構會被匯出）——這條直接守住本機開發流程不被弄壞；
 *  2. 預設會跳過它們的資料列；
 *  3. `--with-credentials` 才連資料一起匯出；
 *  4. 表名大小寫不敏感；
 *  5. `--with-credentials` 這個旗標真的註冊在 signature 上（否則唯一的逃生門會壞掉而無人知道）。
 */
class ExportMysqlToSqliteExcludesCredentialTablesTest extends TestCase {
    /**
     * 完整的憑證表名單，**刻意硬編碼**而不是從 `CREDENTIAL_TABLES` 取。
     *
     * 若用 `array_keys(ExportMysqlToSqlite::CREDENTIAL_TABLES)` 造期望值，輸入與 oracle 同源，
     * 從常數刪掉一張表（例如 `password_resets`）測試仍會全綠——那正是要防的退化。
     */
    private const EXPECTED_CREDENTIAL_TABLES = [
        'users',
        'personal_access_tokens',
        'password_resets',
        'password_reset_tokens',
        'failed_jobs',
        'jobs',
        'cache',
        'cache_locks',
        'sessions',
        'oauth_access_tokens',
        'oauth_refresh_tokens',
        'oauth_auth_codes',
        'oauth_clients',
        'oauth_personal_access_clients',
    ];

    /**
     * 建一個把「來源資料庫有哪些表」換成固定清單的命令實例。
     *
     * `SHOW FULL TABLES` 是 MySQL 專屬語法，測試環境是 SQLite，所以覆寫 fetchAllTables()；
     * 其餘決策邏輯（`--tables` 過濾、`--with-internal`、憑證表判定）都跑真的實作。
     *
     * @param array<int,string> $tableNames
     * @param array<string,mixed> $options
     */
    private function makeCommand(array $tableNames, array $options = []): array {
        $command = new class ($tableNames, $options) extends ExportMysqlToSqlite {
            /** @var array<int,string> */
            private $tableNames;
            /** @var array<string,mixed> */
            private $optionValues;

            public function __construct(array $tableNames, array $optionValues) {
                parent::__construct();
                $this->tableNames = $tableNames;
                $this->optionValues = $optionValues;
            }

            public function option($key = null) {
                return $this->optionValues[$key] ?? false;
            }

            protected function fetchAllTables() {
                $result = [];
                foreach ($this->tableNames as $name) {
                    $result[$name] = ['name' => $name, 'type' => 'BASE TABLE'];
                }

                return $result;
            }
        };

        // warn() 需要 output；用 BufferedOutput 才能斷言警告內容。
        $buffer = new BufferedOutput();
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

        return [$command, $buffer];
    }

    /** @param array<string,mixed> $options */
    private function tablesToExport(array $tableNames, array $options = []): array {
        [$command] = $this->makeCommand($tableNames, $options);

        $method = (new ReflectionClass($command))->getMethod('getTablesToExport');
        $method->setAccessible(true);

        return array_map(fn ($table) => $table['name'], $method->invoke($command));
    }

    /** @param array<string,mixed> $options */
    private function skipsData(string $tableName, array $options = []): bool {
        [$command] = $this->makeCommand([$tableName], $options);

        $method = (new ReflectionClass($command))->getMethod('shouldSkipTableData');
        $method->setAccessible(true);

        return $method->invoke($command, $tableName);
    }

    /** CBDB 資料表 ＋ 全部憑證表，模擬 prod 的表清單。 */
    private function sampleTables(): array {
        return array_merge(
            ['BIOG_MAIN', 'ALTNAME_DATA', 'KIN_DATA'],
            self::EXPECTED_CREDENTIAL_TABLES,
            ['CBDB__NAME_FTS', 'operations', 'audit_log']
        );
    }

    #[Test]
    public function credential_tables_stay_in_the_export_list_so_their_schema_is_created(): void {
        // 本機開發流程（README-Docker.md）靠這條：表必須存在，否則登入會在執行期炸。
        $names = $this->tablesToExport($this->sampleTables());

        foreach (self::EXPECTED_CREDENTIAL_TABLES as $table) {
            $this->assertContains($table, $names, "{$table} 的結構仍必須匯出，否則本機開發資料庫不可用");
        }

        $this->assertContains('BIOG_MAIN', $names);
        $this->assertContains('operations', $names);
        // 既有的 CBDB__ 排除行為不能被這次改動影響。
        $this->assertNotContains('CBDB__NAME_FTS', $names);
    }

    #[Test]
    public function the_constant_contains_exactly_the_pinned_table_list(): void {
        // 用「相等」而非「包含」：從常數刪掉任何一張表都必須被抓到（codex 覆核時實測
        // 刪掉 cache_locks 仍全綠，因為當時這份期望清單漏了 5 張）。新增表時要刻意更新這裡。
        $this->assertSame(
            self::EXPECTED_CREDENTIAL_TABLES,
            array_keys(ExportMysqlToSqlite::CREDENTIAL_TABLES),
            'CREDENTIAL_TABLES 的內容有變動；若是刻意新增／移除，請同步更新本測試的期望清單'
        );

        // 每一張都要附「為什麼不能外流」的理由，警告訊息會直接印出它。
        foreach (ExportMysqlToSqlite::CREDENTIAL_TABLES as $table => $reason) {
            $this->assertNotSame('', trim((string) $reason), "{$table} 缺少理由說明");
        }
    }

    #[Test]
    public function every_credential_table_skips_its_rows_by_default(): void {
        foreach (self::EXPECTED_CREDENTIAL_TABLES as $table) {
            $this->assertTrue($this->skipsData($table), "{$table} 的資料列預設不得匯出");
        }
    }

    #[Test]
    public function ordinary_tables_keep_their_rows(): void {
        foreach (['BIOG_MAIN', 'ALTNAME_DATA', 'operations', 'audit_log', 'CBDB__NAME_FTS'] as $table) {
            $this->assertFalse($this->skipsData($table), "{$table} 不是憑證表，資料列必須照匯");
        }
    }

    #[Test]
    public function the_with_credentials_flag_opts_in(): void {
        foreach (self::EXPECTED_CREDENTIAL_TABLES as $table) {
            $this->assertFalse(
                $this->skipsData($table, ['with-credentials' => true]),
                "{$table} 帶 --with-credentials 時應連資料一起匯出"
            );
        }
    }

    #[Test]
    public function credential_table_matching_is_case_insensitive(): void {
        // 涵蓋 lower_case_table_names=2（照原樣存、比對不分大小寫）與手工建成 Users 的情形。
        foreach (['USERS', 'Users', 'Personal_Access_Tokens', 'OAUTH_CLIENTS'] as $table) {
            $this->assertTrue($this->skipsData($table), "{$table} 應被視為憑證表");
        }
    }

    #[Test]
    public function the_with_credentials_option_is_registered_on_the_command(): void {
        // 若這個旗標從 signature 被刪掉或打錯字，上面的測試仍會全綠（它們覆寫了 option()），
        // 但 `php artisan db:export-to-sqlite --with-credentials` 會死在「option does not exist」
        // ——唯一的逃生門壞掉而 CI 不知道。
        $definition = (new ExportMysqlToSqlite())->getDefinition();

        $this->assertTrue(
            $definition->hasOption('with-credentials'),
            'db:export-to-sqlite 必須提供 --with-credentials 旗標'
        );

        // 也必須是「不吃值」的旗標。改成 `{--with-credentials= : …}` 時 hasOption() 仍為 true，
        // 但 `php artisan db:export-to-sqlite --with-credentials` 會死在 "option requires a value"
        // ——逃生門一樣是壞的。
        $this->assertFalse(
            $definition->getOption('with-credentials')->acceptValue(),
            '--with-credentials 必須是不吃值的旗標'
        );
    }

    #[Test]
    public function no_option_other_than_with_credentials_can_turn_the_skip_off(): void {
        // 這條守的是「跳過條件的形狀」。review 實測過幾種很像優化的弱化：
        //   `&& !$this->option('tables')`  → 帶 --tables 時失效，而 docs 就是教人帶 --tables
        //   `&& !$this->option('append')`  → 釋出腳本第 2 張起全走 --append
        //   `&& !$this->option('quiet')`   → cron 用 -q 就把 users 的資料列照匯
        // 上面的測試全部只傳自己關心的 option，所以這些條件恆真、mutation 存活。
        // 這裡反過來窮舉命令**真正宣告**的每一個 option（外加 Symfony 的全域旗標），
        // 逐個打開後斷言「還是跳過」。
        $definition = (new ExportMysqlToSqlite())->getDefinition();

        $cases = [];
        foreach ($definition->getOptions() as $option) {
            if ($option->getName() === 'with-credentials') {
                continue;
            }
            $cases[$option->getName()] = $option->acceptValue() ? 'users' : true;
        }
        // 全域旗標不在 signature 裡，但 option() 讀得到（`-q`、`-v`、`--no-interaction`…）。
        foreach (['quiet', 'verbose', 'no-interaction', 'ansi', 'no-ansi', 'help'] as $global) {
            $cases[$global] = true;
        }

        $this->assertArrayHasKey('append', $cases, '期望清單來自真實 definition；--append 應該在裡面');
        $this->assertArrayHasKey('tables', $cases, '期望清單來自真實 definition；--tables 應該在裡面');

        foreach ($cases as $name => $value) {
            $this->assertTrue(
                $this->skipsData('users', [$name => $value]),
                "帶 --{$name} 時 users 的資料列仍不得匯出（只有 --with-credentials 能 opt in）"
            );
        }
    }

    /**
     * 建一個「記錄自己對每張表做了什麼」的命令實例：覆寫 exportTableSchema()／exportTableData()
     * ／getTableRowCount()，就能在 SQLite 環境下驗證 exportTable() 的真實行為。
     *
     * @param array<int,array{name:string,type:string}> $tables
     * @param array<string,mixed> $options
     * @return array{0:array<int,string>,1:array<int,string>,2:int,3:string}
     *         [schemaCalls, dataCalls, stats.tables, 輸出內容]
     */
    private function runExportTable(array $tables, array $options = []): array {
        $command = new class ($options) extends ExportMysqlToSqlite {
            /** @var array<int,string> */
            public $schemaCalls = [];
            /** @var array<int,string> */
            public $dataCalls = [];
            /** @var array<string,mixed> */
            private $optionValues;

            public function __construct(array $optionValues) {
                parent::__construct();
                $this->optionValues = $optionValues;
            }

            public function option($key = null) {
                return $this->optionValues[$key] ?? false;
            }

            protected function exportTableSchema($tableName, $isView = false) {
                $this->schemaCalls[] = $tableName;
            }

            protected function exportTableData($tableName, $rowCount = 0, $limit = null) {
                $this->dataCalls[] = $tableName;
            }

            protected function getTableRowCount($tableName) {
                return 0;
            }

            public function statsTables() {
                return $this->stats['tables'];
            }
        };

        $buffer = new BufferedOutput();
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

        $method = (new ReflectionClass($command))->getMethod('exportTable');
        $method->setAccessible(true);

        foreach ($tables as $table) {
            $method->invoke($command, $table);
        }

        return [$command->schemaCalls, $command->dataCalls, $command->statsTables(), $buffer->fetch()];
    }

    #[Test]
    public function export_table_writes_schema_but_skips_rows(): void {
        // 這是本次改動**唯一真正的執行點**：exportTable() 裡「匯結構、跳資料」那段分支。
        // 只測 helper 的回傳值不夠——把那段分支刪掉，helper 測試仍會全綠而 users 的資料照匯。
        // 涵蓋名單裡不同來源的表：實際存在的（users）、大小寫變體（USERS）、
        // Laravel 預設名（password_reset_tokens）、佇列／快取（cache_locks）。
        $tables = [
            ['name' => 'BIOG_MAIN', 'type' => 'BASE TABLE'],
            ['name' => 'users', 'type' => 'BASE TABLE'],
            ['name' => 'USERS', 'type' => 'BASE TABLE'],
            ['name' => 'password_reset_tokens', 'type' => 'BASE TABLE'],
            ['name' => 'cache_locks', 'type' => 'BASE TABLE'],
        ];

        [$schemaCalls, $dataCalls, $statsTables, $output] = $this->runExportTable($tables);

        // 跳過這件事必須被說出來。訊息只在 exportTable() 裡印，helper 的回傳值測試看不到它被刪掉。
        $this->assertStringContainsString('跳过数据列', $output, '跳過憑證資料列時必須印出警告');
        $this->assertStringContainsString('users', $output);

        // 結構一律匯出——這條才是真正守住本機開發流程（表必須存在）的斷言。
        $this->assertSame(
            ['BIOG_MAIN', 'users', 'USERS', 'password_reset_tokens', 'cache_locks'],
            $schemaCalls
        );
        // 資料只匯一般表。
        $this->assertSame(['BIOG_MAIN'], $dataCalls);
        // 跳過的表仍要計入統計（skip 分支自己 ++ 後 return）。
        $this->assertSame(5, $statsTables);
    }

    #[Test]
    public function export_table_copies_rows_when_opted_in(): void {
        [$schemaCalls, $dataCalls, $statsTables, $output] = $this->runExportTable(
            [
                ['name' => 'BIOG_MAIN', 'type' => 'BASE TABLE'],
                ['name' => 'users', 'type' => 'BASE TABLE'],
            ],
            ['with-credentials' => true]
        );

        $this->assertSame(['BIOG_MAIN', 'users'], $schemaCalls);
        $this->assertSame(['BIOG_MAIN', 'users'], $dataCalls);
        $this->assertSame(2, $statsTables);
        // 「你正在把密碼雜湊寫進這個檔案」這句警告不能被靜默掉。
        $this->assertStringContainsString('数据列将被导出', $output);
    }

    #[Test]
    public function views_never_reach_the_data_path(): void {
        // view 一律不匯資料，所以「名為 users 的 view」不構成憑證外流路徑。
        [$schemaCalls, $dataCalls] = $this->runExportTable([
            ['name' => 'users_view', 'type' => 'VIEW'],
            ['name' => 'users', 'type' => 'VIEW'],
        ]);

        $this->assertSame(['users_view', 'users'], $schemaCalls);
        $this->assertSame([], $dataCalls);
    }

    #[Test]
    public function schema_only_mode_says_so_instead_of_promising_data(): void {
        // --schema-only 時本來就不匯任何資料列，訊息不得說「数据列将被导出」。
        $notice = $this->credentialNotice('users', ['schema-only' => true, 'with-credentials' => true]);

        $this->assertStringContainsString('--schema-only', $notice);
        $this->assertStringNotContainsString('数据列将被导出', $notice);
    }

    #[Test]
    public function skipping_and_opting_in_produce_distinguishable_notices(): void {
        // 跳過與放行兩條訊息都含 "--with-credentials"，所以要斷言可區分的字樣，
        // 否則測試分不出「跳過了」與「匯出了」。
        $skipNotice = $this->credentialNotice('users');
        $optInNotice = $this->credentialNotice('users', ['with-credentials' => true]);

        $this->assertStringContainsString('跳过数据列', $skipNotice);
        $this->assertStringContainsString('--with-credentials', $skipNotice);

        $this->assertStringContainsString('数据列将被导出', $optInNotice);
        $this->assertStringNotContainsString('跳过数据列', $optInNotice);

        // 訊息要說明「為什麼這張表敏感」，而不是只列表名。
        $this->assertStringContainsString('confirmation_token', $skipNotice);
        $this->assertStringContainsString('confirmation_token', $optInNotice);

        // 非憑證表不該產生這類提示。
        $this->assertNull($this->credentialNoticeOrNull('BIOG_MAIN'));
    }

    /** @param array<string,mixed> $options */
    private function credentialNoticeOrNull(string $tableName, array $options = []): ?string {
        [$command] = $this->makeCommand([$tableName], $options);

        $method = (new ReflectionClass($command))->getMethod('credentialDataNotice');
        $method->setAccessible(true);

        return $method->invoke($command, $tableName);
    }

    /** @param array<string,mixed> $options */
    private function credentialNotice(string $tableName, array $options = []): string {
        $notice = $this->credentialNoticeOrNull($tableName, $options);

        $this->assertNotNull($notice, "{$tableName} 應該產生憑證表提示");

        return $notice;
    }
}
