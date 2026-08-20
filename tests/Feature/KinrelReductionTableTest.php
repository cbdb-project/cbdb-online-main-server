<?php

namespace Tests\Feature;

use App\Http\Controllers\OperationsController;
use App\Models\Operation;
use App\Models\User;
use App\Support\CompositePrimaryKey;
use App\Support\SqliteReleaseTables;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `KINREL_REDUCTION`（親屬關係化簡規則表）的 migration 與各處註冊點回歸測試。
 *
 * 這張表要在多個彼此獨立的清單裡登錄才算「接完線」：`config/codes.php`（同時驅動
 * All Tables 列表、Query Playground 與 NL／MCP 白名單）、兩份 i18n 標籤、
 * `SqliteReleaseTables::PUBLIC_TABLES` 與 `scripts/export-daily-sqlite.sh`。
 * 漏改任一處的症狀都是「靜默少一張表」，所以逐項斷言。
 */
class KinrelReductionTableTest extends TestCase {
    private const TABLE = 'KINREL_REDUCTION';

    private const MIGRATION = 'database/migrations/2026_08_20_000000_create_kinrel_reduction_table.php';

    /**
     * 直接跑 migration 的 up()，而不是在測試裡手搓一張同名表：手搓表會讓
     * 「migration 在 SQLite 下跑不起來」這類錯誤完全測不到（見 AGENTS.md §1）。
     */
    private function migration(): object {
        return require base_path(self::MIGRATION);
    }

    private function runMigration(): void {
        $this->migration()->up();
    }

    #[Test]
    public function migration_creates_the_table_on_sqlite_with_the_expected_columns(): void {
        $this->runMigration();

        $this->assertTrue(Schema::hasTable(self::TABLE));

        $expected = [
            'c_kinrel_target', 'c_sex', 'c_kinrel_replacement',
            'c_up_change', 'c_down_change', 'c_col_change', 'c_mar_change',
            'c_notes', 'c_required', 'c_check_ego',
        ];
        $this->assertSame($expected, Schema::getColumnListing(self::TABLE));
    }

    #[Test]
    public function migration_uses_the_composite_primary_key_target_plus_sex(): void {
        $this->runMigration();

        $primary = null;
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if (!empty($index['primary'])) {
                $primary = $index['columns'];

                break;
            }
        }

        $this->assertNotNull($primary, '缺少主鍵：CodesController 會退化成用前兩欄當 key。');
        $this->assertSame(['c_kinrel_target', 'c_sex'], $primary);
    }

    #[Test]
    public function required_columns_are_not_null_and_notes_stays_nullable(): void {
        $this->runMigration();

        $nullable = [];
        foreach (DB::select('PRAGMA table_info("' . self::TABLE . '")') as $row) {
            $col = (array) $row;
            $nullable[$col['name']] = (int) $col['notnull'] === 0;
        }

        $this->assertFalse($nullable['c_kinrel_target']);
        $this->assertFalse($nullable['c_sex']);
        $this->assertFalse($nullable['c_kinrel_replacement']);
        $this->assertTrue($nullable['c_notes']);
    }

    #[Test]
    public function migration_seeds_the_eight_reduction_rules_from_the_source_spreadsheet(): void {
        $this->runMigration();

        $rows = DB::table(self::TABLE)->orderBy('c_kinrel_target')->get()
            ->map(fn ($r) => [
                $r->c_kinrel_target, $r->c_sex, $r->c_kinrel_replacement,
                (int) $r->c_up_change, (int) $r->c_down_change,
                (int) $r->c_col_change, (int) $r->c_mar_change,
                $r->c_notes, (int) $r->c_required, (int) $r->c_check_ego,
            ])->all();

        $this->assertSame([
            ['BB', 'B', 'B', 0, 0, -1, 0, null, 1, 0],
            ['BZ', 'B', 'Z', 0, 0, -1, 0, null, 1, 0],
            ['DB', 'B', 'S', 0, 0, -1, 0, null, 1, 0],
            ['DZ', 'B', 'D', 0, 0, -1, 0, null, 1, 0],
            ['SB', 'B', 'S', 0, 0, -1, 0, null, 1, 0],
            ['SZ', 'B', 'D', 0, 0, -1, 0, null, 1, 0],
            ['ZB', 'B', 'B', 0, 0, -1, 0, null, 1, 0],
            ['ZZ', 'B', 'Z', 0, 0, -1, 0, null, 1, 0],
        ], $rows);
    }

    /**
     * 用 MySQL 的 schema grammar 編譯 migration **自己那一份** `defineTable()` 定義。
     *
     * 刻意呼叫 migration 的方法而不是在測試裡重打一遍欄位宣告：後者只會證明測試和測試一致。
     * 也刻意不連真的 MariaDB——`DB::connection('mysql')` 是 lazy 的，`getSchemaGrammar()` 與
     * `Blueprint::toSql()` 全程不需要 PDO，所以這條在 CI 的純 SQLite 環境下照樣跑。
     */
    private function compiledMysqlCreateTable(): string {
        config(['database.default' => 'mysql']);

        $connection = DB::connection('mysql');
        $this->assertSame('mysql', $connection->getDriverName());
        // 連線是 lazy 的，schema grammar 要顯式初始化（否則 Blueprint 收到 null grammar）。
        $connection->useDefaultSchemaGrammar();

        $blueprint = new Blueprint($connection, self::TABLE);
        $blueprint->create();
        $this->migration()->defineTable($blueprint);

        foreach ($blueprint->toSql() as $statement) {
            if (stripos($statement, 'create table') !== false) {
                return $statement;
            }
        }

        $this->fail('defineTable() 沒有編譯出 CREATE TABLE。');
    }

    /**
     * 步數欄必須是**有號**的：化簡的語義就是「減少一層旁系」，現行資料 c_col_change = -1；
     * 若被寫成 unsigned，MariaDB 上 -1 會 out of range 報錯或被截成 0。
     *
     * 為什麼斷言 DDL 而不是「在 SQLite 插一筆 -1 再讀回來」：SQLiteGrammar 的 `$modifiers`
     * **沒有 Unsigned**，所以就算欄位寫成 `unsignedSmallInteger()`，SQLite 照樣存得下 -1、
     * 測試照樣全綠——那種測試剛好在它唯一要防的缺陷上失效。
     */
    #[Test]
    public function step_change_columns_are_signed_smallints_in_mysql(): void {
        $create = $this->compiledMysqlCreateTable();

        foreach (['c_up_change', 'c_down_change', 'c_col_change', 'c_mar_change'] as $column) {
            $this->assertMatchesRegularExpression(
                '/`' . $column . '` smallint not null/',
                $create,
                $column . ' 必須是有號 smallint'
            );
        }

        $this->assertStringNotContainsStringIgnoringCase(
            'unsigned',
            $create,
            '任何欄位都不該是 unsigned——步數欄要存負值。'
        );
    }

    /**
     * CBDB 全庫是 utf8mb4_general_ci（`config/database.php` 的預設卻是 unicode_ci），因此
     * migration 在 Blueprint 上顯式指定；這條釘住那個指定真的有進 DDL，順帶釘住複合主鍵。
     */
    #[Test]
    public function mysql_ddl_pins_the_cbdb_collation_and_composite_primary_key(): void {
        $create = $this->compiledMysqlCreateTable();

        $this->assertStringContainsString('utf8mb4_general_ci', $create);
        $this->assertStringContainsString('primary key (`c_kinrel_target`, `c_sex`)', $create);
    }

    /** SQLite 下負值也要能來回，作為 smoke check（真正的守衛是上面那條 MySQL DDL 斷言）。 */
    #[Test]
    public function negative_step_values_round_trip_on_sqlite(): void {
        $this->runMigration();

        DB::table(self::TABLE)->insert([
            'c_kinrel_target' => 'FBS', 'c_sex' => 'M', 'c_kinrel_replacement' => 'B',
            'c_up_change' => -1, 'c_down_change' => -1, 'c_col_change' => -2, 'c_mar_change' => -1,
            'c_notes' => 'negative probe', 'c_required' => 0, 'c_check_ego' => 1,
        ]);

        $row = DB::table(self::TABLE)->where('c_kinrel_target', 'FBS')->first();
        $this->assertSame(-1, (int) $row->c_up_change);
        $this->assertSame(-2, (int) $row->c_col_change);
    }

    /** 同一個 target 在不同 ego 性別下可以並存——這正是 c_sex 參與主鍵的理由。 */
    #[Test]
    public function the_same_target_can_carry_different_rules_per_sex(): void {
        $this->runMigration();

        DB::table(self::TABLE)->insert([
            ['c_kinrel_target' => 'FBS', 'c_sex' => 'M', 'c_kinrel_replacement' => 'B', 'c_col_change' => -1],
            ['c_kinrel_target' => 'FBS', 'c_sex' => 'F', 'c_kinrel_replacement' => 'Z', 'c_col_change' => -1],
        ]);

        $this->assertSame(2, DB::table(self::TABLE)->where('c_kinrel_target', 'FBS')->count());
    }

    #[Test]
    public function migration_down_drops_the_table(): void {
        $migration = $this->migration();
        $migration->up();
        $this->assertTrue(Schema::hasTable(self::TABLE));

        $migration->down();
        $this->assertFalse(Schema::hasTable(self::TABLE));
    }

    #[Test]
    public function it_is_registered_in_the_codes_whitelist_and_not_ui_hidden(): void {
        $this->assertArrayHasKey(self::TABLE, config('codes.tables'));

        $hidden = array_map('strtoupper', config('codes.ui_hidden', []));
        $this->assertNotContains(self::TABLE, $hidden);
    }

    /**
     * Query Playground 的表白名單就是 `array_keys(config('codes.tables'))`
     * （`QueryPlaygroundController::run()` 逐表 strcasecmp）。這條刻意**真的送一次查詢**，而不是
     * 再斷言一次 config：後者在「controller 改成讀別的清單」時仍會綠。
     */
    #[Test]
    public function a_select_against_the_table_passes_the_query_playground_allowlist(): void {
        $this->runMigration();

        $user = new User();
        $user->forceFill([
            'id' => 1,
            'name' => 'Expert',
            'email' => 'expert@example.com',
            'is_admin' => User::ROLE_EXPERT,
            'is_active' => User::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($user)->postJson(route('query-playground.run'), [
            'sql' => 'SELECT c_kinrel_target, c_kinrel_replacement FROM ' . self::TABLE,
        ]);

        $response->assertOk();
        $this->assertCount(8, $response->json('data'));
    }

    /**
     * 部署環境若把 `MCP_ALLOWED_TABLES` 顯式釘死（`.env`），config 的 fallback 就不生效——
     * 新表必須同時進 `.env.example`，否則照範本部署的環境會少這張表。斷言範本本身，
     * 而不是 `config('mcp.cbdb.allowed_tables')`：後者取決於執行機器的 `.env`，會隨環境紅綠不定。
     */
    #[Test]
    public function it_is_listed_in_the_env_example_mcp_allowlist(): void {
        $env = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression(
            '/^MCP_ALLOWED_TABLES=.*(^|,)' . self::TABLE . '(,|$)/m',
            $env
        );
    }

    /**
     * 這張表在泛用 `/codes` 介面是可寫的（不在 `CodesController::$readOnlyTables`，也沒被
     * `config/entity_aggregates.php` 認領），所以編輯／刪除會產生 operations 列。「還原」按鈕的
     * 顯示條件（admin ＋ opType 3/4 ＋ can_compare）**不看** `resourceKeyColumns()`，
     * 漏登錄的症狀是按鈕出現、按下去卻 `restore_no_pk`。
     */
    #[Test]
    public function operations_restore_knows_this_tables_key_columns(): void {
        $controller = new \ReflectionClass(OperationsController::class);
        $method = $controller->getMethod('resourceKeyColumns');
        $method->setAccessible(true);

        $this->assertSame(
            ['c_kinrel_target', 'c_sex'],
            $method->invoke($controller->newInstanceWithoutConstructor(), self::TABLE)
        );
    }

    /**
     * 還原的第二條路：稽核快照湊不齊主鍵欄時，`buildKeyConditions()` 會回退去解 `resource_id`。
     * CodesController 寫的是 `_._` 格式，而只有 `parseStoredResourceId()` 認得它——它在表沒登記
     * `CompositePrimaryKey::SCHEMAS` 時直接回 null，接著退到按 `-` 切的舊格式，於是 `BB_._B`
     * 會被當成單一段、主鍵永遠湊不齊。這條釘住 SCHEMAS 登錄真的生效。
     */
    #[Test]
    public function the_stored_resource_id_format_parses_back_into_both_key_columns(): void {
        $this->assertSame(
            ['c_kinrel_target' => 'BB', 'c_sex' => 'B'],
            CompositePrimaryKey::parseStoredResourceId('BB_._B', self::TABLE)
        );
    }

    /**
     * 走完整的 `buildKeyConditions()`——上一條只證明 parser 會解，這條證明還原真的用得到它：
     * 稽核快照兩個主鍵欄都缺（`$current` 與 `$fallback` 皆空）時，必須靠 `resource_id` 回退湊齊，
     * 否則回傳空陣列＝`restore_no_pk`。拿掉 `buildKeyConditions()` 裡的回退區塊，這條會紅。
     */
    #[Test]
    public function restore_recovers_both_key_columns_from_the_resource_id_when_the_snapshot_lacks_them(): void {
        $operation = new Operation();
        $operation->forceFill([
            'resource' => self::TABLE,
            'resource_id' => 'BB_._B',
            'op_type' => Operation::TYPE_UPDATE,
        ]);

        $controller = new \ReflectionClass(OperationsController::class);
        $method = $controller->getMethod('buildKeyConditions');
        $method->setAccessible(true);

        $conditions = $method->invoke(
            $controller->newInstanceWithoutConstructor(),
            $operation,
            [],  // $current：快照裡沒有主鍵欄
            []   // $fallback：另一份快照也沒有
        );

        $this->assertSame(['c_kinrel_target' => 'BB', 'c_sex' => 'B'], $conditions);
    }

    #[Test]
    public function it_has_labels_in_both_locales(): void {
        $zh = require base_path('resources/lang/zh-TW/codes.php');
        $en = require base_path('resources/lang/en/codes.php');

        $this->assertArrayHasKey(self::TABLE, $zh['table_desc']);
        $this->assertArrayHasKey(self::TABLE, $en['table_desc']);
        $this->assertNotSame($zh['table_desc'][self::TABLE], $en['table_desc'][self::TABLE]);
    }

    #[Test]
    public function it_is_in_the_sqlite_release_allowlist_and_export_script(): void {
        $this->assertContains(self::TABLE, SqliteReleaseTables::PUBLIC_TABLES);

        // 只比對 TABLES=( ... ) 區塊：整檔比對會被註解或別處出現的表名蒙過去。
        $script = file_get_contents(base_path('scripts/export-daily-sqlite.sh'));
        $this->assertSame(1, preg_match('/^TABLES=\((.*?)^\)/ms', $script, $m), '找不到 TABLES=( ... ) 區塊');
        $this->assertStringContainsString('"' . self::TABLE . '"', $m[1]);
    }

    #[Test]
    public function the_generic_codes_page_renders_the_migrated_table(): void {
        $compiled = sys_get_temp_dir() . '/cbdb-test-views-kinrel-reduction';
        if (!is_dir($compiled)) {
            mkdir($compiled, 0777, true);
        }
        config(['view.compiled' => $compiled]);

        $this->runMigration();

        $this->get(route('app.codes.show', ['table_name' => self::TABLE]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Codes/Show')
                ->where('table', self::TABLE)
                ->has('rows', 8));
    }
}
