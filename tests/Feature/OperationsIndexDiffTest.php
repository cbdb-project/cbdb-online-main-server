<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `/app/operations` 的 diff 計算：`resource_id` 舊格式必須解得開，才查得到 DB 現況。
 *
 * 這四個 ALTNAME_DATA 分支（3-key dash、3-key `_._`、名字含 `-` 而編碼成 `(minus)`、
 * 以及 4-key legacy）是 #834 主鍵遷移的專屬回歸。`buildOperationsListing()` 是 Blade 與
 * React 共用的，所以不變量本身與頁面無關——但 `OperationsInertiaTest` 那筆 fixture 是
 * `TEST_RES` + 空 `resource_id`，**完全不走 resource_id 解析分支**，所以這批不能丟。
 *
 * ── 2026-09-14（Blade 下架環節 4a-2）─────────────────────────────
 *
 * 本檔原本打 legacy `/operations` 並自建 Blade stub（layouts/dashboard + operations/index +
 * `view.paths` 覆寫 + FileViewFinder 抽換），斷言 `viewData('lists')` 的 `resource_diff`。
 * 現改打 `/app/operations` 並斷言 Inertia prop `lists[].diff_source`
 * （`serializeOperationRow()` 裡的 `resource_diff ?? resource_original`），整組 stub 一併移除。
 *
 * 同批刪掉 1 條 A 類（空資料集回 200，已由 `OperationsInertiaTest::index_renders_component`
 * 以同樣的「未插任何列」斷言覆蓋）。
 */
class OperationsIndexDiffTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->smallInteger('is_active')->default(0);
            $table->smallInteger('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('c_personid')->default(0);
            $table->smallInteger('op_type');
            $table->string('resource');
            $table->string('resource_id');
            $table->longText('resource_data');
            $table->longText('resource_original')->nullable();
            $table->timestamps();
            $table->smallInteger('crowdsourcing_status')->default(0);
            $table->smallInteger('rate')->default(0);
        });

        // React 路徑的 serializeOperationRow() 會解析受影響人物的姓名（legacy Blade 版
        // 由視圖自己處理，所以原本的 stub 沒碰到這張表）。
        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name')->nullable();
            $table->string('c_name_chn')->nullable();
        });

        Schema::create('OFFICE_CODES', function (Blueprint $table) {
            $table->integer('c_office_id')->primary();
            $table->string('c_office_chn')->nullable();
        });

        Schema::create('OFFICE_CODE_TYPE_REL', function (Blueprint $table) {
            $table->integer('c_office_id');
            $table->string('c_office_tree_id');
        });

        Schema::create('OFFICE_TYPE_TREE', function (Blueprint $table) {
            $table->string('c_office_type_node_id')->primary();
            $table->string('c_office_type_desc_chn')->nullable();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('ALTNAME_DATA');
        Schema::dropIfExists('OFFICE_TYPE_TREE');
        Schema::dropIfExists('OFFICE_CODE_TYPE_REL');
        Schema::dropIfExists('OFFICE_CODES');
        Schema::dropIfExists('BIOG_MAIN');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    /**
     * 建立 ALTNAME_DATA 測試表並插入測試資料
     */
    protected function createAltnameTable(): void {
        Schema::create('ALTNAME_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_sequence')->nullable();
            $table->integer('c_alt_name_type_code');
            $table->string('c_alt_name')->nullable();
            $table->string('c_alt_name_chn')->nullable();
        });

        // 誘餌列：與各測試的目標列同表、不同 PK，且 c_sequence 刻意不同。
        //
        // 為什麼需要：只插一列的話「WHERE 條件整組寫錯／整組拿掉」也會撈到那唯一一列，
        // 於是 `matches_current` 照樣為 true——`assertDiffResolvedCurrentRow()` 的
        // 「證明撈到的是**正確那一列**」就只是一句空話。有了誘餌，撈錯列時
        // c_sequence 對不上，matches_current 會是 false。
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 999999,
            'c_sequence' => 87,
            'c_alt_name_type_code' => 99,
            'c_alt_name' => 'Decoy',
            'c_alt_name_chn' => '誘餌',
        ]);
    }

    /**
     * 打 `/app/operations` 並取回 `lists` prop。
     *
     * 取代原本的 `$response->viewData('lists')`——React 側同一份資料來自
     * `serializeOperationRow()`，欄位名有一處不同：`resource_diff` → `diff_source`
     * （值是 `resource_diff ?? resource_original`）。
     *
     * @return array<int, array<string, mixed>>
     */
    protected function appOperationsLists(string $query = ''): array {
        $lists = null;

        $this->get('/app/operations' . $query)
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$lists) {
                $lists = $page->component('Admin/Operations/Index')->toArray()['props']['lists'];
            });

        return $lists;
    }

    /**
     * 斷言 diff 真的**查到了 DB 現況**，而不只是「diff_source 非 null」。
     *
     * ⚠️ 這是本次轉換踩到的坑：`diff_source` 是 `resource_diff ?? resource_original`，
     * 而這批 fixture 的 `resource_original` 都非空——所以即使 resource_id 解析失敗、
     * 現況查詢被略過，`diff_source` 仍然非 null。用 `assertNotNull($diff)` 的話，
     * 把 `parseStoredResourceId()` 整段 no-op 掉測試照樣綠（實測確認過）。
     *
     * 真正的鑑別欄位是每個 diff row 的 `current`：解析失敗時它是 `'(未取得)'`
     * （`OperationsController:2368`）。所以這裡斷言「沒有任何一欄是 (未取得)」
     * 且「至少有一欄 matches_current」——後者代表現況值真的跟 after 值對上了。
     *
     * @param array<int, array<string, mixed>> $lists
     */
    protected function assertDiffResolvedCurrentRow(array $lists, string $because): void {
        $this->assertNotEmpty($lists, $because.'：operations 列表不該是空的');

        $rows = $lists[0]['diff_source']['rows'] ?? null;
        $this->assertNotEmpty($rows, $because.'：diff 應有 rows');

        $currents = array_column($rows, 'current');
        $this->assertNotContains(
            '(未取得)',
            $currents,
            $because.'：現況欄出現 (未取得) 代表 resource_id 沒解開、DB 現況查詢被略過'
        );
        $this->assertContains(
            true,
            array_column($rows, 'matches_current'),
            $because.'：至少要有一欄的現況值與 after 值對上，才證明撈到的是正確那一列'
        );
    }

    protected function actingAsAdmin(): User {
        $user = User::forceCreate([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        $this->actingAs($user);

        return $user;
    }

    #[Test]
    public function test_operations_index_handles_missing_relation_records(): void {
        $this->actingAsAdmin();

        Operation::create([
            'user_id' => 1,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_CREATE,
            'resource' => 'OFFICE_CODE_TYPE_REL',
            'resource_id' => '803818-200501',
            'resource_data' => json_encode([
                'c_office_id' => 803818,
                'c_office_tree_id' => '200501',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // 關聯列撈不到時只要不整頁 500 即可；順便確認那一列真的有被列出
        // （若被靜默吞掉，pagination.total 會是 0 而不是 1）。
        $this->assertCount(1, $this->appOperationsLists());
    }

    // -------------------------------------------------------
    // ALTNAME 3-key 舊格式分支差異比對測試 (#834)
    // -------------------------------------------------------

    #[Test]
    public function test_operations_index_altname_3key_dash_format_diff(): void {
        // 驗證 index() 中 ALTNAME_DATA 舊格式 switch/case 的 3-key dash 路徑
        // 能正確查到 DB 資料列並計算差異比對
        $this->actingAsAdmin();
        $this->createAltnameTable();

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 123,
            'c_sequence' => 2,
            'c_alt_name_type_code' => 10,
            'c_alt_name' => 'Zi Jing',
            'c_alt_name_chn' => '張三',
        ]);

        // 3-key dash format: c_personid-c_alt_name_chn-c_alt_name_type_code
        Operation::create([
            'user_id' => 1,
            'c_personid' => 123,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => '123-張三-10',
            'resource_data' => json_encode([
                'c_personid' => 123,
                'c_sequence' => 2,
                'c_alt_name_chn' => '張三',
                'c_alt_name_type_code' => 10,
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 123,
                'c_sequence' => 1,
                'c_alt_name_chn' => '張三',
                'c_alt_name_type_code' => 10,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->assertDiffResolvedCurrentRow($this->appOperationsLists(), '3-key dash 格式應能查到 DB 資料並產生差異比對');
    }

    #[Test]
    public function test_operations_index_altname_3key_dot_format_diff(): void {
        // 驗證 index() 中 ALTNAME_DATA 舊格式 switch/case 的 3-key _._  路徑
        $this->actingAsAdmin();
        $this->createAltnameTable();

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 456,
            'c_sequence' => 0,
            'c_alt_name_type_code' => 5,
            'c_alt_name' => 'Hao',
            'c_alt_name_chn' => '測試',
        ]);

        // 3-key _._  format: c_personid_._c_alt_name_chn_._c_alt_name_type_code
        Operation::create([
            'user_id' => 1,
            'c_personid' => 456,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => '456_._測試_._5',
            'resource_data' => json_encode([
                'c_personid' => 456,
                'c_sequence' => 0,
                'c_alt_name_chn' => '測試',
                'c_alt_name_type_code' => 5,
                'c_alt_name' => 'Hao',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 456,
                'c_sequence' => 0,
                'c_alt_name_chn' => '測試',
                'c_alt_name_type_code' => 5,
                'c_alt_name' => 'Old',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->assertDiffResolvedCurrentRow($this->appOperationsLists(), '3-key _._  格式應能查到 DB 資料並產生差異比對');
    }

    #[Test]
    public function test_operations_index_altname_3key_dash_with_encoded_minus_diff(): void {
        // 驗證 c_alt_name_chn 含負號時，3-key dash 格式仍能正確比對
        $this->actingAsAdmin();
        $this->createAltnameTable();

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 789,
            'c_sequence' => 1,
            'c_alt_name_type_code' => 3,
            'c_alt_name' => 'Test',
            'c_alt_name_chn' => '張-三',
        ]);

        // 「張-三」在 dash 格式中編碼為「張(minus)三」
        Operation::create([
            'user_id' => 1,
            'c_personid' => 789,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => '789-張(minus)三-3',
            'resource_data' => json_encode([
                'c_personid' => 789,
                'c_sequence' => 1,
                'c_alt_name_chn' => '張-三',
                'c_alt_name_type_code' => 3,
                'c_alt_name' => 'Test',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 789,
                'c_sequence' => 1,
                'c_alt_name_chn' => '張-三',
                'c_alt_name_type_code' => 3,
                'c_alt_name' => 'Old',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->assertDiffResolvedCurrentRow($this->appOperationsLists(), '3-key dash 含 (minus) 編碼應能查到 DB 資料並產生差異比對');
    }

    #[Test]
    public function test_operations_index_altname_4key_legacy_still_works(): void {
        // 確認既有 4-key 舊格式不受 3-key 遷移影響
        $this->actingAsAdmin();
        $this->createAltnameTable();

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 100,
            'c_sequence' => 1,
            'c_alt_name_type_code' => 10,
            'c_alt_name' => 'Ming',
            'c_alt_name_chn' => '李四',
        ]);

        // 4-key dash format: c_personid-c_sequence-c_alt_name_chn-c_alt_name_type_code
        Operation::create([
            'user_id' => 1,
            'c_personid' => 100,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => '100-1-李四-10',
            'resource_data' => json_encode([
                'c_personid' => 100,
                'c_sequence' => 1,
                'c_alt_name_chn' => '李四',
                'c_alt_name_type_code' => 10,
                'c_alt_name' => 'Ming',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 100,
                'c_sequence' => 1,
                'c_alt_name_chn' => '李四',
                'c_alt_name_type_code' => 10,
                'c_alt_name' => 'Old',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->assertDiffResolvedCurrentRow($this->appOperationsLists(), '既有 4-key 格式應繼續正常運作');
    }
}
