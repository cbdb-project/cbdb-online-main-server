<?php

namespace Tests\Feature;

use App\Console\Commands\ExportMysqlToSqlite;
use App\Support\SqliteReleaseTables;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * #1251：釋出產物自檢 `cbdb:assert-sqlite-release-scope`。
 *
 * 這是釋出範圍**繞不過去**的那道防線（靜態的 allowlist 契約測試只看腳本文字，見
 * SqliteReleaseAllowlistTest 的類別註解）。因此它自己必須有真正執行過的測試。
 *
 * 幾條是 review 實測出繞道後補的，不要當成湊數：
 *  - `missing_file_fails_closed()`：`new PDO("sqlite:/no/such/file")` 會**建立**一個空資料庫，
 *    接著 `sqlite_master` 回空清單、自檢「通過」——最危險的假通過。
 *  - `an_empty_artifact_fails()`：0 byte 的真實檔案是合法的空 SQLite，`is_file()` 擋不住。
 *  - fixture 直接用**真實的全部釋出表**且把壞表放在最後：小 fixture（2、3 張表）碰不到任何
 *    截斷邊界，`LIMIT 50` / `array_slice($tables, 0, 50)` 這類弱化會存活。
 */
class AssertSqliteReleaseScopeTest extends TestCase {
    private string $dir;

    protected function setUp(): void {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/cbdb-release-scope-' . getmypid();
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
    }

    protected function tearDown(): void {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    /**
     * 建一個 SQLite 檔。
     *
     * @param array<int,string> $tables 表名（依序建立，順序會反映在 sqlite_master 上）
     * @param array<int,string> $views  檢視名
     */
    private function makeSqlite(string $name, array $tables, array $views = []): string {
        $path = $this->dir . '/' . $name . '.sqlite';
        @unlink($path);

        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach ($tables as $table) {
            $pdo->exec(sprintf('CREATE TABLE "%s" (id integer)', $table));
        }
        foreach ($views as $view) {
            $pdo->exec(sprintf('CREATE VIEW "%s" AS SELECT 1 AS id', $view));
        }
        unset($pdo);

        return $path;
    }

    /**
     * 真實釋出範圍（全部釋出表）＋ `$extra`；`$extra` 放在**最後**，那是截斷式弱化最容易漏掉的位置。
     *
     * @param array<int,string> $extra
     * @return array<int,string>
     */
    private function releaseShapedTables(array $extra = []): array {
        return array_merge(SqliteReleaseTables::PUBLIC_TABLES, $extra);
    }

    #[Test]
    public function a_release_shaped_artifact_passes(): void {
        $tables = $this->releaseShapedTables();
        $path = $this->makeSqlite('clean', $tables);

        $this->artisan('cbdb:assert-sqlite-release-scope', [
            'file' => $path,
            '--min-tables' => count($tables),
        ])
            ->assertExitCode(0)
            // 斷言「檢查了幾張」而不只是「通過」：否則只讀前 N 筆 sqlite_master 的弱化會存活。
            ->expectsOutputToContain(sprintf('已檢查 %d 張表', count($tables)));
    }

    #[Test]
    public function a_credential_table_at_the_end_of_a_release_shaped_artifact_is_detected(): void {
        $tables = $this->releaseShapedTables(['users']);
        $path = $this->makeSqlite('dirty-tail', $tables);

        $this->artisan('cbdb:assert-sqlite-release-scope', [
            'file' => $path,
            '--min-tables' => 1,
        ])->assertExitCode(1);
    }

    #[Test]
    public function every_credential_table_is_detected(): void {
        // 逐張驗證，而不是只試 users——常數裡任何一張表若沒被實際偵測到都是洞。
        foreach (array_keys(ExportMysqlToSqlite::CREDENTIAL_TABLES) as $credentialTable) {
            $path = $this->makeSqlite('dirty', ['BIOG_MAIN', $credentialTable]);

            $this->artisan('cbdb:assert-sqlite-release-scope', [
                'file' => $path,
                '--min-tables' => 1,
            ])->assertExitCode(1);
        }
    }

    #[Test]
    public function detection_is_case_insensitive(): void {
        $path = $this->makeSqlite('upper', ['BIOG_MAIN', 'USERS']);

        $this->artisan('cbdb:assert-sqlite-release-scope', [
            'file' => $path,
            '--min-tables' => 1,
        ])->assertExitCode(1);
    }

    #[Test]
    public function credential_views_are_checked_too(): void {
        // 一張名為 users 的 view 同樣會把憑證欄位曝露給下游，而且要以「憑證表」的訊息回報，
        // 不能只落到「不得含檢視」那條泛用訊息上。
        $path = $this->makeSqlite('withview', ['BIOG_MAIN'], ['users']);

        $this->artisan('cbdb:assert-sqlite-release-scope', [
            'file' => $path,
            '--min-tables' => 1,
        ])->assertExitCode(1);
    }

    #[Test]
    public function operational_and_pii_tables_are_rejected_even_though_they_are_not_credential_tables(): void {
        // 這些不在 CREDENTIAL_TABLES 裡，但 audit_log 含 email＋登入 IP／UA，
        // operations／nl_query_logs／ai_fill_logs 含 user_id 與使用者輸入。
        // 擋住它們的是 allowlist 成員檢查，不是「營運表名單」——後者會過期。
        foreach (['audit_log', 'operations', 'nl_query_logs', 'ai_fill_logs', 'migrations', 'pinyin', 'char_variant_map', 'person_change_index'] as $table) {
            $path = $this->makeSqlite('ops', ['BIOG_MAIN', $table]);

            $this->artisan('cbdb:assert-sqlite-release-scope', [
                'file' => $path,
                '--min-tables' => 1,
            ])->assertExitCode(1);
        }
    }

    #[Test]
    public function upper_case_tables_outside_the_allowlist_are_rejected(): void {
        // 這條是 codex 覆核時指出的洞：早期版本用「全大寫就算公開 CBDB 表」的形狀判準，於是任何
        // 日後新增的大寫表都會被放行——而個資表的名字一樣可以全大寫。
        // CBDB__NAME_FTS／CBDB__* 是應用自建的索引表，同樣不在 allowlist 內。
        foreach (['AUDIT_LOG_ARCHIVE', 'USER_LOGIN_EVENTS', 'CBDB__NAME_FTS', 'BIOG_MAIN_BAK'] as $table) {
            $path = $this->makeSqlite('outside', ['BIOG_MAIN', $table]);

            $this->artisan('cbdb:assert-sqlite-release-scope', [
                'file' => $path,
                '--min-tables' => 1,
            ])->assertExitCode(1);
        }
    }

    #[Test]
    public function a_view_is_rejected_even_when_its_name_is_allowlisted(): void {
        // codex 覆核時指出的洞：檢視也會被 --min-tables 算進去，所以「與 allowlist 同名、數量足夠的空檢視」
        // 可以冒充一份完整產物。釋出檔只該有資料表。
        $path = $this->makeSqlite('viewnamed', ['BIOG_MAIN'], ['ALTNAME_DATA']);

        $this->artisan('cbdb:assert-sqlite-release-scope', [
            'file' => $path,
            '--min-tables' => 2,
        ])->assertExitCode(1);
    }

    #[Test]
    public function an_artifact_made_entirely_of_allowlisted_views_fails(): void {
        // 上面那條的「完整版」：表數達標、每個名稱都在 allowlist 內，但全是檢視。
        $path = $this->makeSqlite('allviews', [], SqliteReleaseTables::PUBLIC_TABLES);

        $this->artisan('cbdb:assert-sqlite-release-scope', ['file' => $path])
            ->assertExitCode(1);
    }

    #[Test]
    public function the_default_min_tables_is_the_full_allowlist(): void {
        // 漏傳 --min-tables 不該讓下界掉到 1：weekly-sqlite-sync.sh 的上傳邊界那次呼叫就沒傳，
        // 若預設是 1，把產物換成只含一張 allowlist 內表的檔案也會通過（codex 覆核時指出）。
        $path = $this->makeSqlite('single', ['BIOG_MAIN']);

        $this->artisan('cbdb:assert-sqlite-release-scope', ['file' => $path])
            ->assertExitCode(1);

        $full = $this->makeSqlite('full', SqliteReleaseTables::PUBLIC_TABLES);

        $this->artisan('cbdb:assert-sqlite-release-scope', ['file' => $full])
            ->assertExitCode(0)
            ->expectsOutputToContain(sprintf('已檢查 %d 張表', count(SqliteReleaseTables::PUBLIC_TABLES)));
    }

    #[Test]
    public function sqlite_internal_objects_are_ignored(): void {
        // sqlite_sequence 是 SQLite 自己建的，不算釋出內容也不該計入表數。
        $path = $this->dir . '/withsequence.sqlite';
        @unlink($path);
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE "BIOG_MAIN" (id integer primary key autoincrement)');
        $pdo->exec('INSERT INTO "BIOG_MAIN" DEFAULT VALUES');
        $names = $pdo->query("SELECT name FROM sqlite_master WHERE type IN ('table','view')")
            ->fetchAll(PDO::FETCH_COLUMN);
        unset($pdo);
        $this->assertContains('sqlite_sequence', $names, 'fixture 前提不成立：沒有產生 sqlite_sequence');

        $this->artisan('cbdb:assert-sqlite-release-scope', [
            'file' => $path,
            '--min-tables' => 1,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('已檢查 1 張表');
    }

    #[Test]
    public function the_allowlist_plus_min_tables_pins_the_exact_table_set(): void {
        // allowlist（子集）＋ --min-tables（下界）＋ 表名不重複 ⟹ 產物的表集合恰好等於 allowlist 那一份。
        // 這條把那個推論釘住：少一張就會因表數不足而失敗。
        $tables = SqliteReleaseTables::PUBLIC_TABLES;
        $this->assertSame(array_values(array_unique($tables)), array_values($tables), 'allowlist 不得有重複表名');

        array_pop($tables);
        $path = $this->makeSqlite('missing-one', $tables);

        $this->artisan('cbdb:assert-sqlite-release-scope', [
            'file' => $path,
            '--min-tables' => count(SqliteReleaseTables::PUBLIC_TABLES),
        ])->assertExitCode(1);
    }

    #[Test]
    public function an_artifact_with_fewer_tables_than_expected_fails(): void {
        // 匯出中途失敗（或有人拿掉逐表失敗那道閘）會產生「表數不足」的產物；
        // 只檢查壞表名的話這種產物會直接放行。
        $path = $this->makeSqlite('short', ['BIOG_MAIN', 'ALTNAME_DATA']);

        $this->artisan('cbdb:assert-sqlite-release-scope', [
            'file' => $path,
            '--min-tables' => count(SqliteReleaseTables::PUBLIC_TABLES),
        ])->assertExitCode(1);
    }

    #[Test]
    public function an_empty_artifact_fails(): void {
        // 0 byte 的真實檔案是合法的空 SQLite：is_file() 為真、PDO 開得起來、sqlite_master 回 0 筆。
        $path = $this->dir . '/empty.sqlite';
        file_put_contents($path, '');
        $this->assertSame(0, filesize($path));

        $this->artisan('cbdb:assert-sqlite-release-scope', ['file' => $path])
            ->assertExitCode(1);
    }

    #[Test]
    public function a_non_numeric_min_tables_fails_closed(): void {
        $path = $this->makeSqlite('clean2', ['BIOG_MAIN']);

        $this->artisan('cbdb:assert-sqlite-release-scope', [
            'file' => $path,
            '--min-tables' => 'many',
        ])->assertExitCode(1);
    }

    #[Test]
    public function missing_file_fails_closed(): void {
        // 這條是重點：PDO 會替不存在的路徑建一個空資料庫，若不先檢查存在性，
        // 自檢會對「根本沒產出檔案」回報通過。
        $path = $this->dir . '/never-created.sqlite';
        $this->assertFileDoesNotExist($path);

        $this->artisan('cbdb:assert-sqlite-release-scope', ['file' => $path])
            ->assertExitCode(1);

        // 而且不得因為檢查而把檔案建出來。
        $this->assertFileDoesNotExist($path);
    }

    #[Test]
    public function a_directory_argument_fails_closed(): void {
        $this->artisan('cbdb:assert-sqlite-release-scope', ['file' => $this->dir])
            ->assertExitCode(1);
    }

    #[Test]
    public function a_corrupt_file_fails_closed(): void {
        $path = $this->dir . '/corrupt.sqlite';
        file_put_contents($path, 'this is not a sqlite database');

        $this->artisan('cbdb:assert-sqlite-release-scope', ['file' => $path])
            ->assertExitCode(1);
    }
}
