<?php

namespace Tests\Feature;

use App\Http\Controllers\CodesController;
use App\Models\Operation;
use App\Models\User;
use App\Repositories\CodesRepository;
use App\Repositories\OperationRepository;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CodesController 的 controller 級測試。
 *
 * ── 2026-09-15（Blade 下架環節 4b-2c-2）─────────────────────────
 * 本檔 52 條**全部改打 React 端**（`/app/codes/*`），類級的 `#[Group('legacy-parity')]`
 * 與 setUp 的 `useLegacyBladePages()` 一併移除——4b-2c-1 留下的「混合狀態」到此結束。
 *
 * 讀取面的視圖變數對照（供日後查閱）：
 *   sortBy→sort_by、sortDir→sort_dir、booleanEnabled→boolean_enabled、
 *   booleanFilterAvailable→boolean_filter_available、filterErrors→filter_errors、
 *   filterDescriptions→filter_descriptions、useCursorPagination→use_cursor、
 *   keyColumns→key_columns、appliedFilters→**applied_filters（本環節新增的 prop）**。
 */
class CodesControllerTest extends TestCase {
    protected $operationSpy;
    protected $originalDb;
    protected $fakeDb;

    protected function setUp(): void {
        parent::setUp();

        config(['codes.tables' => ['TEST_CODES', 'TEXT_CODES', 'POSSESSION_DATA', 'CBDB__NAME_FTS', 'APPOINTMENT_CODE_TYPE_REL', 'OFFICE_CODE_TYPE_REL', 'APPOINTMENT_TYPES', 'ADDR_CODES']]);
        config(['codes.connection' => null]);

        $compiledPath = base_path('tests/storage/views');
        if (!is_dir($compiledPath)) {
            mkdir($compiledPath, 0777, true);
        }
        config(['view.compiled' => $compiledPath]);

        $this->originalDb = DB::getFacadeRoot();
        $this->fakeDb = new FakeDatabaseManager(
            [
                'TEST_CODES' => [],
                'TEXT_CODES' => [],
                'POSSESSION_DATA' => [],
                'CBDB__NAME_FTS' => [],
                'APPOINTMENT_CODE_TYPE_REL' => [],
                'OFFICE_CODE_TYPE_REL' => [],
                'APPOINTMENT_TYPES' => [],
                'ADDR_CODES' => [],
                'operations' => [],
            ],
            [
                'TEST_CODES' => ['code_id', 'code_sub', 'description'],
                'ADDR_CODES' => ['c_addr_id', 'c_name', 'c_name_chn'],
                'TEXT_CODES' => ['c_textid', 'c_title', 'c_title_chn', 'c_bibl_cat_code', 'c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date'],
                'POSSESSION_DATA' => ['c_personid', 'c_possession_record_id', 'c_sequence', 'c_possession_act_code', 'c_possession_desc'],
                'CBDB__NAME_FTS' => ['id', 'person_name'],
                'APPOINTMENT_CODE_TYPE_REL' => ['c_appt_code', 'c_appt_type_code'],
                'OFFICE_CODE_TYPE_REL' => ['c_office_id', 'c_office_tree_id'],
                'APPOINTMENT_TYPES' => ['c_appt_type_code', 'c_appt_type_desc', 'c_appt_type_desc_chn'],
                'operations' => ['id', 'user_id', 'resource', 'resource_id', 'op_type', 'resource_data', 'resource_original', 'created_at', 'updated_at'],
            ],
            [
                'APPOINTMENT_TYPES' => [[
                    'name' => 'primary',
                    'columns' => ['c_appt_type_code'],
                    'type' => 'btree',
                    'unique' => true,
                    'primary' => true,
                ]],
            ]
        );
        DB::swap($this->fakeDb);
        $this->app->instance('db', $this->fakeDb);

        $this->app->instance(CodesRepository::class, new class () extends CodesRepository {
            public function allowedTables(): array {
                return ['TEST_CODES', 'TEXT_CODES', 'POSSESSION_DATA', 'CBDB__NAME_FTS', 'APPOINTMENT_CODE_TYPE_REL', 'OFFICE_CODE_TYPE_REL', 'APPOINTMENT_TYPES', 'ADDR_CODES'];
            }

            public function allowedTableMap(): array {
                return [
                    'TEST_CODES' => 'TEST_CODES',
                    'TEXT_CODES' => 'TEXT_CODES',
                    'POSSESSION_DATA' => 'POSSESSION_DATA',
                    'CBDB__NAME_FTS' => 'CBDB__NAME_FTS',
                    'APPOINTMENT_CODE_TYPE_REL' => 'APPOINTMENT_CODE_TYPE_REL',
                    'OFFICE_CODE_TYPE_REL' => 'OFFICE_CODE_TYPE_REL',
                    'APPOINTMENT_TYPES' => 'APPOINTMENT_TYPES',
                    'ADDR_CODES' => 'ADDR_CODES',
                ];
            }
        });

        $this->operationSpy = new class () extends OperationRepository {
            public $calls = [];

            public function store($user_id, $c_personid, $op_type, $resource, $resource_id, $resource_data, $ori = '', $crowdsourcing_status = 0) {
                $this->calls[] = compact('user_id', 'c_personid', 'op_type', 'resource', 'resource_id', 'resource_data', 'ori', 'crowdsourcing_status');
            }

            public function hasPendingCreateProposal(string $resource, string $resourceId, ?int $excludeId = null): bool {
                $query = DB::table('operations')
                    ->where('resource', $resource)
                    ->where('op_type', Operation::TYPE_PROPOSAL_CREATE)
                    ->where('resource_id', $resourceId);

                if ($excludeId !== null) {
                    $query->where('id', '!=', $excludeId);
                }

                $rows = $query->get();
                foreach ($rows as $row) {
                    $payload = json_decode($row->resource_data ?? '', true);
                    $status = is_array($payload) ? ($payload['__review_status'] ?? null) : null;
                    if (in_array($status, ['pending', 'rejected'], true)) {
                        return true;
                    }
                }

                return false;
            }
        };
        $this->app->instance(OperationRepository::class, $this->operationSpy);
    }

    protected function tearDown(): void {
        DB::swap($this->originalDb);
        $this->app->instance('db', $this->originalDb);
        parent::tearDown();
    }

    #[Test]
    public function testGuestCannotStoreRows() {
        $this->operationSpy->calls = [];
        $payload = [
            'code_id' => 'A1',
            'code_sub' => 'B1',
            'description' => 'guest attempt',
        ];

        $response = $this->from('/app/codes/TEST_CODES/create')
            ->post('/app/codes/TEST_CODES', $payload);

        $response->assertRedirect('/app/codes/TEST_CODES/create');
        $this->assertEmpty($this->operationSpy->calls);
    }

    #[Test]
    public function testInactiveUserCannotStoreRows() {
        $this->operationSpy->calls = [];
        $inactiveUser = new User([
            'name' => 'inactive',
            'email' => 'inactive@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $inactiveUser->id = 1;
        $inactiveUser->is_active = 0;
        $this->actingAs($inactiveUser);

        $payload = [
            'code_id' => 'A2',
            'code_sub' => 'B2',
            'description' => 'inactive attempt',
        ];

        $response = $this->from('/app/codes/TEST_CODES/create')
            ->post('/app/codes/TEST_CODES', $payload);

        $response->assertRedirect('/app/codes/TEST_CODES/create');
        $this->assertEmpty($this->operationSpy->calls);
    }

    #[Test]
    public function testActiveUserStoreRequiresPrimaryKeys() {
        $this->operationSpy->calls = [];
        $user = new User([
            'name' => 'active',
            'email' => 'active@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 11;
        $user->is_active = 1;
        $this->actingAs($user);

        $response = $this->from('/app/codes/TEST_CODES/create')->post('/app/codes/TEST_CODES', [
            'code_id' => 'A2',
            'description' => 'missing sub key',
        ]);

        $response->assertRedirect('/app/codes/TEST_CODES/create');
        $response->assertSessionHasErrors(['missing_keys']);
        $this->assertEmpty($this->operationSpy->calls);
        $this->assertEmpty($this->fakeDb->tables['TEST_CODES']);
    }

    #[Test]
    public function testActiveUserStoreLogsOperation() {
        $this->operationSpy->calls = [];
        $activeUser = new User([
            'name' => 'active',
            'email' => 'active@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $activeUser->id = 2;
        $activeUser->is_active = 1;
        $this->actingAs($activeUser);

        $expectedInsert = [
            'code_id' => 'A3',
            'code_sub' => 'B3',
            'description' => 'active stored',
        ];
        $response = $this->post('/app/codes/TEST_CODES', $expectedInsert);

        // ⚠️ 新增成功的重導目標 Blade 與 React **不同**：Blade 的 `store()` 傳
        // `$editRoute = 'codes.edit'`（吃得下 `{id}` 路徑段）⇒ 落在新列的編輯頁；
        // `appStore()` 兩個參數都傳 `'app.codes.show'`，而那條路由**沒有 `{id}` 段**
        // ⇒ 落在列表頁並把 id 掛成 query（`?id=…`）。見 `appStore()` 的註解與
        // `CodesCreateInertiaTest::store_inserts_row_and_redirects`。這裡照 React 實況斷言。
        $response->assertRedirect(route('app.codes.show', [
            'table_name' => 'TEST_CODES',
            'id' => 'A3_._B3',
        ]));

        $this->assertCount(1, $this->operationSpy->calls);
        $call = $this->operationSpy->calls[0];
        $this->assertSame(1, $call['op_type']);
        $this->assertSame('TEST_CODES', $call['resource']);
        $this->assertSame('A3_._B3', $call['resource_id']);
        $this->assertSame($expectedInsert['description'], $call['resource_data']['description']);
    }

    #[Test]
    public function testStoreFillsCreateAuditFieldsWhenAvailable() {
        Carbon::setTestNow(Carbon::create(2025, 1, 15, 9, 30));

        $this->operationSpy->calls = [];
        $user = new User([
            'name' => 'audit-user',
            'email' => 'audit@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 12;
        $user->is_active = 1;
        $this->actingAs($user);

        $payload = [
            'c_textid' => 'T100',
            'c_title' => 'Sample',
            'c_title_chn' => '範例',
        ];

        $response = $this->post('/app/codes/TEXT_CODES', $payload);

        // 重導目標的 Blade／React 差異見上一條測試的說明。
        $response->assertRedirect(route('app.codes.show', [
            'table_name' => 'TEXT_CODES',
            'id' => 'T100',
        ]));

        $this->assertCount(1, $this->fakeDb->tables['TEXT_CODES']);
        $row = $this->fakeDb->tables['TEXT_CODES'][0];
        $this->assertSame('audit-user', $row['c_created_by']);
        // Carbon object is stored directly in fake DB (real DB would convert to TIMESTAMP)
        $this->assertInstanceOf(Carbon::class, $row['c_created_date']);
        $this->assertEquals(Carbon::now()->timestamp, $row['c_created_date']->timestamp, '', 1);
        $this->assertSame('Sample', $row['c_title']);
        $this->assertSame('範例', $row['c_title_chn']);

        $this->assertCount(1, $this->operationSpy->calls);
        $call = $this->operationSpy->calls[0];
        $this->assertSame('audit-user', $call['resource_data']['c_created_by']);
        // After DB round-trip, Laravel Query Builder returns TIMESTAMP as ISO-8601 string
        $this->assertIsString($call['resource_data']['c_created_date']);
        // Parse the ISO-8601 string and verify it matches expected time
        $parsedTime = Carbon::parse($call['resource_data']['c_created_date']);
        $this->assertEquals(Carbon::now()->timestamp, $parsedTime->timestamp, '', 1);

        Carbon::setTestNow();
    }

    private function activeUser(string $name = 'active', int $id = 21): User {
        $user = new User(['name' => $name, 'email' => $name.'@example.com', 'confirmation_token' => Str::random(32)]);
        $user->id = $id;
        $user->is_active = 1;

        return $user;
    }

    #[Test]
    public function testStoreNormalizesTier1PinyinColumn() {
        // §D-6：TEXT_CODES.c_title 為 Tier 1，手打 lv 應靜默轉 lü
        $this->actingAs($this->activeUser());

        $this->post('/app/codes/TEXT_CODES', [
            'c_textid' => 'T200',
            'c_title' => 'Lvzhai Shier Bian',
            'c_title_chn' => '呂齋十二辨',
        ]);

        $row = $this->fakeDb->tables['TEXT_CODES'][0];
        $this->assertSame('Lüzhai Shier Bian', $row['c_title']);
        $this->assertSame('呂齋十二辨', $row['c_title_chn']); // 中文欄不動
    }

    #[Test]
    public function testUpdateNormalizesTier1PinyinColumn() {
        $this->actingAs($this->activeUser());
        $this->fakeDb->tables['TEXT_CODES'][] = ['c_textid' => 'T300', 'c_title' => 'old', 'c_title_chn' => '舊'];

        $this->put('/app/codes/TEXT_CODES/T300', [
            'c_textid' => 'T300',
            'c_title' => 'Nvzhen Kao',
            'c_title_chn' => '女真考',
        ]);

        $row = collect($this->fakeDb->tables['TEXT_CODES'])->firstWhere('c_textid', 'T300');
        $this->assertSame('Nüzhen Kao', $row['c_title']);
    }

    #[Test]
    public function testStoreDoesNotNormalizeTier2Column() {
        // §D-6：ADDR_CODES.c_name 為 Tier 2（可能含西文），後端不轉——交前端彈窗
        $this->actingAs($this->activeUser());

        $this->post('/app/codes/ADDR_CODES', [
            'c_addr_id' => '900',
            'c_name' => 'Lvchuan',
            'c_name_chn' => '呂川',
        ]);

        $row = $this->fakeDb->tables['ADDR_CODES'][0];
        $this->assertSame('Lvchuan', $row['c_name']); // 原樣、後端不轉
    }

    #[Test]
    public function testStoreLeavesNonPhaseBTableUntouched() {
        // TEST_CODES 不在 code_table_mutations config → 任何欄皆不歸一化
        $this->actingAs($this->activeUser());

        $this->post('/app/codes/TEST_CODES', [
            'code_id' => 'A9',
            'code_sub' => 'B9',
            'description' => 'lvzhai test',
        ]);

        $row = $this->fakeDb->tables['TEST_CODES'][0];
        $this->assertSame('lvzhai test', $row['description']); // 不動
    }

    #[Test]
    public function testProposalStoreUsesPrimaryKeyOverrideForPossessionData() {
        $user = new User([
            'name' => 'active',
            'email' => 'active-override@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 21;
        $user->is_active = 1;
        $this->actingAs($user);

        $response = $this->post('/app/codes/POSSESSION_DATA/proposal', [
            'c_possession_record_id' => 3,
            'c_possession_desc' => 'proposal row',
            '__proposal_comment' => 'pk override',
        ]);

        $response->assertRedirect('/app/codes/POSSESSION_DATA');
        $this->assertCount(1, $this->operationSpy->calls);
        $call = $this->operationSpy->calls[0];
        $this->assertSame(Operation::TYPE_PROPOSAL_CREATE, $call['op_type']);
        $this->assertSame('POSSESSION_DATA', $call['resource']);
        $this->assertSame(['c_possession_record_id'], $call['resource_data']['__key_columns']);
    }

    #[Test]
    public function testCreateViewPlacesPrimaryKeyFirstWithDefaultValue() {
        $this->fakeDb->tables['TEXT_CODES'][] = [
            'c_textid' => 41,
            'c_title' => 'Existing',
            'c_title_chn' => '既有',
            'c_bibl_cat_code' => null,
            'c_created_by' => 'origin',
            'c_created_date' => '20200101',
            'c_modified_by' => 'origin',
            'c_modified_date' => '20200102',
        ];

        $user = new User([
            'name' => 'viewer',
            'email' => 'viewer@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 13;
        $user->is_active = 1;
        $this->actingAs($user);

        // 原測試用 regex 掃 Blade 產生的 `name="…" class="form-control"` 取第一個輸入框，
        // 再在它後面 150 字元內找 `value="42"`。React 版把同一件事表達成兩個 prop：
        // `columns` 的**順序**就是欄位順序，`defaults` 就是預填值——比掃 HTML 精確，
        // 也不會因為改了 class 名稱就假綠／假紅。
        $props = $this->appProps('/app/codes/TEXT_CODES/create');

        $this->assertSame('c_textid', ($props['columns'] ?? [])[0] ?? null);
        // 型別刻意用 assertEquals：`defaults` 走 JSON 序列化，數值在這條路徑上是字串 '42'。
        // 這條測試的主題是「主鍵排第一且有預設值」，不是型別。
        $this->assertEquals(42, ((array) $props['defaults'])['c_textid'] ?? null);
    }

    #[Test]
    public function testSearchFiltersResults() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
            ['code_id' => 'A2', 'code_sub' => 'X2', 'description' => 'Beta entry'],
            ['code_id' => 'A3', 'code_sub' => 'X3', 'description' => 'Gamma entry'],
        ]);

        $props = $this->appProps('/app/codes/TEST_CODES?search=Beta');

        $this->assertSame(['Beta entry'], $this->rowColumn($props, 'description'));
        // 原本的 assertSee('value="Beta"') 驗的是搜尋框回填，對應 `search` prop。
        $this->assertSame('Beta', $props['search']);
        $this->assertEmpty($this->operationSpy->calls);
    }

    #[Test]
    public function testColumnFiltersAndSortAreAppliedSafely() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A2', 'code_sub' => 'X2', 'description' => 'Beta second'],
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Beta first'],
            ['code_id' => 'A3', 'code_sub' => 'X3', 'description' => 'Gamma third'],
        ]);

        $this->activeReader();
        $props = $this->appProps('/app/codes/TEST_CODES?filters[description]=Beta&filters[bad_column%20or%201=1]=ignored&sort_by=code_id&sort_dir=desc%20union');

        // 結果集與**順序**一次釘住：`sort_dir=desc union` 是非法值 ⇒ 落回 asc ⇒ A1 在 A2 前面。
        // 原測試是拿兩個字串在 HTML 裡的位置相比，這裡直接比較整個欄值清單。
        $this->assertSame(['Beta first', 'Beta second'], $this->rowColumn($props, 'description'));
        // 好欄位留下、注入用的欄名整個消失（不是被跳脫，是根本沒進 filters）。
        $this->assertSame(['description' => 'Beta'], (array) $props['filters']);
        // sort_by 也要斷言：只驗列序不夠——sort_by 被整個丟掉時，主鍵 tie-breaker
        // 會產生**一模一樣**的順序（review 指出）。
        $this->assertSame('code_id', $props['sort_by']);
        $this->assertSame('asc', $props['sort_dir']);
        $this->assertEmpty($this->operationSpy->calls);
    }

    #[Test]
    public function testCursorPaginationBranchRendersWithoutRuntimeError() {
        DB::table('CBDB__NAME_FTS')->insert([
            ['id' => 1, 'person_name' => 'Alpha'],
            ['id' => 2, 'person_name' => 'Beta'],
            ['id' => 3, 'person_name' => 'Gamma'],
        ]);

        $props = $this->appProps('/app/codes/CBDB__NAME_FTS');

        $this->assertSame(['Alpha', 'Beta', 'Gamma'], $this->rowColumn($props, 'person_name'));
        $this->assertTrue($props['use_cursor']);
        // 游標分支必須真的把游標中繼資料傳下去，否則前端的上／下一頁按鈕永遠是停用的
        // （原測試只驗「頁面沒爆」，這裡順帶把那個分支的輸出釘住）。
        $this->assertIsArray($props['cursor']);
    }

    // 這裡原本有 5 條測試。其中 4 條**完全不打 HTTP**（ui_hidden 過濾 ×2、說明欄 locale fallback、
    // config↔lang table_desc key parity）。它們與 Blade 無關，只是住在這個 legacy 檔、
    // 被 setUp 的 useLegacyBladePages() 連坐。已於環節 4b-2a 搬到
    // **tests/Unit/CodesTableListingTest.php**——否則環節 4b 刪掉本檔時會靜默毀掉它們
    // （與環節 4a 的 OperationsIndexLinksTest 同型陷阱）。
    //
    // 前 4 條搬家時有兩處刻意的改動：① 說明欄那條與 tests/Unit/CodesTableDescriptionTest.php
    // 近乎逐字重複，已收斂成只驗「codes() 確實接到那個共用 helper」並改名為
    // codes_list_routes_description_through_the_shared_helper；② 索引陣列那條原本依賴本檔
    // setUp 設的 codes.tables，搬家後自己設（仍是索引陣列、仍走 codes() 第二分支）。
    //
    // 第 5 條 testUiHiddenTableAbsentFromCodesIndexRoute 也一起刪了，但走的是另一條路：
    // 它驗的是 ui_hidden 的**路由層**效果（首頁真的看不到隱藏表），而那是全 repo **唯一**的
    // 路由層斷言（其他 codes 測試一律把 ui_hidden 設成 [] 來排除干擾）。所以先把不變量移植到
    // CodesIndexInertiaTest::ui_hidden_tables_are_absent_from_the_index_route()（打 /app/codes、
    // 斷言 tables prop 不含隱藏表），**再**刪這一條——移植後它就成了重複覆蓋。

    #[Test]
    public function testUiHiddenTableStillReachableViaDirectUrl() {
        // ui_hidden 只從清單隱藏，直連 /codes/{table} 仍可達（不 404）。
        config(['codes.ui_hidden' => ['CBDB__NAME_FTS']]);
        DB::table('CBDB__NAME_FTS')->insert([
            ['id' => 1, 'person_name' => 'Alpha'],
        ]);

        $props = $this->appProps('/app/codes/CBDB__NAME_FTS');

        $this->assertSame(['Alpha'], $this->rowColumn($props, 'person_name'));
    }

    #[Test]
    public function testBooleanModeOffByDefault() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
        ]);

        $props = $this->appProps('/app/codes/TEST_CODES');

        $this->assertFalse($props['boolean_enabled']);
    }

    #[Test]
    public function testBooleanModeEnabledViaQueryParam() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
        ]);

        $props = $this->appProps('/app/codes/TEST_CODES?filter_bool=1');

        $this->assertTrue($props['boolean_enabled']);
    }

    #[Test]
    public function testBooleanModeAppliesSimplePositiveTerm() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
            ['code_id' => 'A2', 'code_sub' => 'X2', 'description' => 'Beta entry'],
        ]);

        $this->activeReader();
        $props = $this->appProps('/app/codes/TEST_CODES?filter_bool=1&filters[description]=Beta');

        $this->assertTrue($props['boolean_enabled']);
        $this->assertSame(['Beta entry'], $this->rowColumn($props, 'description'));
        $this->assertSame(['description' => 'Beta'], (array) $props['applied_filters']);
        $this->assertSame([], (array) $props['filter_errors']);
    }

    #[Test]
    public function testBooleanModeMixedValidAndInvalidColumns() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
            ['code_id' => 'A2', 'code_sub' => 'X2', 'description' => 'Beta entry'],
        ]);

        // 好欄位 description=Beta 照常套用；壞欄位 code_sub='X1 AND' 解析失敗 → 記錯誤並略過。
        $this->activeReader();
        $props = $this->appProps('/app/codes/TEST_CODES?filter_bool=1'
            . '&filters[description]=Beta'
            . '&filters[code_sub]=' . urlencode('X1 AND'));

        // 好欄位生效：只剩 Beta entry
        $this->assertSame(['Beta entry'], $this->rowColumn($props, 'description'));
        // 分流正確：applied_filters 只含好欄位、filter_errors 只含壞欄位
        $this->assertSame(['description' => 'Beta'], (array) $props['applied_filters']);
        $this->assertSame(['code_sub' => 'dangling_operator'], (array) $props['filter_errors']);

        // 端到端護欄（決策 #19）：換頁／排序的連結帶好欄位、不帶被略過的壞欄位。
        //
        // Blade 版是伺服器把連結整條組好，所以原測試斷言 `$paginator->url(1)` 的字串
        // 與 hidden input 的 HTML。**React 版的連結是前端組的**，伺服器能負責的那一半就是
        // 這個 prop——`Show.tsx` 的 `navigate()` 拿 `applied_filters` 去組 URL
        // （見該檔註解與 Blade 下架環節 4b-2c-2）。所以這裡斷言的是同一個不變量的伺服器端。
        $this->assertArrayHasKey('description', (array) $props['applied_filters']);
        $this->assertArrayNotHasKey('code_sub', (array) $props['applied_filters']);
        // 狀態攜帶（C6）：布林模式本身要留著，互動不會把它洗掉。
        $this->assertTrue($props['boolean_enabled']);
        // 壞欄位仍要回填到輸入框（使用者才改得動），所以它必須留在 `filters` 裡——
        // 這正是 `filters` 與 `applied_filters` 不可合併的理由。
        $this->assertSame(
            ['description' => 'Beta', 'code_sub' => 'X1 AND'],
            (array) $props['filters']
        );
    }

    #[Test]
    public function testBooleanParseErrorRecordedAndColumnSkipped() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
            ['code_id' => 'A2', 'code_sub' => 'X2', 'description' => 'Beta entry'],
        ]);

        // 'Beta AND' 懸空運算子 → 解析失敗 → 該欄記錯誤並略過（不轉字面、不套用），故兩列都顯示
        $this->activeReader();
        $props = $this->appProps('/app/codes/TEST_CODES?filter_bool=1&filters[description]=' . urlencode('Beta AND'));

        $this->assertSame(['Alpha entry', 'Beta entry'], $this->rowColumn($props, 'description'));
        $this->assertSame([], (array) $props['applied_filters']);
        $this->assertSame(['description' => 'dangling_operator'], (array) $props['filter_errors']);
    }

    #[Test]
    public function testKillSwitchForcesBooleanOff() {
        config(['codes.boolean_filter_enabled' => false]);
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
        ]);

        $props = $this->appProps('/app/codes/TEST_CODES?filter_bool=1');

        $this->assertFalse($props['boolean_enabled']);
    }

    #[Test]
    public function testKillSwitchHidesAdvancedFilterToggle() {
        config(['codes.boolean_filter_enabled' => false]);
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
        ]);

        $props = $this->appProps('/app/codes/TEST_CODES');

        // kill-switch 關閉時整個停用：連開關都不顯示（§2.2）。
        // Blade 版靠 assertDontSee 掃字串；React 版整塊開關包在
        // `{boolean_filter_available && !use_cursor && (…)}` 裡（Show.tsx），所以伺服器端
        // 能負責的就是這個 prop 為 false。
        $this->assertFalse($props['boolean_filter_available']);
    }

    #[Test]
    public function testToggleOffLinkPreservesRawErrorColumn() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
        ]);

        // 布林模式 + 壞欄位 code_sub（解析失敗）。「關閉進階篩選」連結必須保留原始輸入，
        // 讓使用者一鍵把錯誤布林字串降級為字面搜尋，而非讓輸入憑空消失（§9.2）。
        $this->activeReader();
        $props = $this->appProps('/app/codes/TEST_CODES?filter_bool=1&filters[code_sub]=' . urlencode('X1 AND'));

        // Blade 版把「關閉進階篩選」連結整條組好，所以原測試斷言 href 裡有 `filters%5Bcode_sub%5D`。
        // React 版那個連結是前端組的（`Show.tsx::toggleBoolean()` 刻意用**原始輸入**而非
        // applied），伺服器端的對應物就是：壞欄位**留在 `filters`**、但**不在 `applied_filters`**。
        // 這一組對照正是降級路徑與換頁路徑的差別。
        $this->assertSame(['code_sub' => 'X1 AND'], (array) $props['filters']);
        $this->assertSame([], (array) $props['applied_filters']);
        $this->assertSame(['code_sub' => 'dangling_operator'], (array) $props['filter_errors']);
    }

    #[Test]
    public function testFtsHardShortCircuitIgnoresFilterAndSort() {
        DB::table('CBDB__NAME_FTS')->insert([
            ['id' => 1, 'person_name' => 'Alpha'],
            ['id' => 2, 'person_name' => 'Beta'],
        ]);

        // 即使帶 filters/sort/filter_bool，游標大表也應硬短路：忽略它們、永遠走游標路徑
        $this->activeReader();
        $props = $this->appProps('/app/codes/CBDB__NAME_FTS?filter_bool=1&filters[person_name]=' . urlencode('Alpha OR Beta') . '&sort_by=person_name&sort_dir=desc');

        $this->assertTrue($props['use_cursor']);
        $this->assertSame([], (array) $props['filters']);
        $this->assertSame('', $props['sort_by']);
        $this->assertFalse($props['boolean_enabled']);
        // filter 被忽略，兩列都還在
        $this->assertSame(['Alpha', 'Beta'], $this->rowColumn($props, 'person_name'));
    }

    #[Test]
    public function testAdvancedFilterToggleShownWhenOff() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
        ]);

        $props = $this->appProps('/app/codes/TEST_CODES');

        // 關閉狀態：開關要可用（React 依 boolean_filter_available 決定整塊顯不顯示），
        // 且目前處於「關閉」那一支（顯示「進階篩選」開啟鈕而非「停用」鈕）。
        $this->assertTrue($props['boolean_filter_available']);
        $this->assertFalse($props['boolean_enabled']);
        // 原測試另外斷言連結字串帶 `filter_bool=1`。React 的開關是 onClick 組參數
        // （Show.tsx::toggleBoolean），**那一段沒有伺服器端的對應物**——它是純前端邏輯。
        // 不在這裡硬湊一個不相干的 prop 斷言充數（第一版我補了 `use_cursor`，
        // 與 toggle 無關，被 review 指為填充物）。前端那段的覆蓋見
        // resources/js/inertia/Pages/Codes/showNavigation.test.ts。
    }

    #[Test]
    public function testSemanticDescriptionShownForAppliedBooleanFilter() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
            ['code_id' => 'A2', 'code_sub' => 'X2', 'description' => 'Beta entry'],
        ]);

        $this->activeReader();
        $props = $this->appProps('/app/codes/TEST_CODES?filter_bool=1&filters[description]=Beta');

        // 後端權威回填的人話描述（zh-TW）。React 由 `filter_descriptions` 這個 prop 渲染
        // （Show.tsx 的 `filter_applied_label` 區塊），所以伺服器端的契約就是這個 prop 的內容。
        $this->assertSame(['description' => '含「Beta」'], (array) $props['filter_descriptions']);
    }

    #[Test]
    public function testParseErrorShownInUi() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
        ]);

        $this->activeReader();
        $props = $this->appProps('/app/codes/TEST_CODES?filter_bool=1&filters[description]=' . urlencode('Beta AND'));

        // 原測試斷言的三件事（逐欄 is-invalid 標記、本地化訊息、彙總警示）在 React 版
        // **全部由 `filter_errors` 這一個 prop 驅動**（Show.tsx：`col in filter_errors` 決定
        // 欄位標紅、`filter_err_${code}` 取訊息、`filter_errors_heading` 用它的數量）。
        // 所以伺服器端要守的是「錯誤碼有傳下去、而且是可翻譯的那個碼」。
        $this->assertSame(['description' => 'dangling_operator'], (array) $props['filter_errors']);
        // 錯誤碼必須對得上翻譯鍵，否則前端只會顯示 filter_err_unknown（§6）。
        $this->assertNotSame(
            'codes.filter_err_dangling_operator',
            (string) __('codes.filter_err_dangling_operator'),
            '翻譯鍵不存在：前端會退回 filter_err_unknown'
        );
    }

    #[Test]
    public function testGuestViewDoesNotShowActions() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
        ]);

        $props = $this->appProps('/app/codes/TEST_CODES');

        // 原測試靠「頁面上沒有那幾個 Bootstrap 按鈕 class」間接驗；React 版把同一件事
        // 收斂成一個伺服器端的 prop：`can_edit`。Show.tsx 的新增／修改／刪除全部包在
        // `can_edit &&` 裡。斷言 prop 比斷言 class 名稱穩定（改版型不會假紅）。
        $this->assertFalse($props['can_edit']);
        $this->assertEmpty($this->operationSpy->calls);
    }

    #[Test]
    public function testTextCodesUsesExplicitPrimaryKeyOverride() {
        DB::table('TEXT_CODES')->insert([
            [
                'c_textid' => 'T001',
                'c_title' => 'Sample Title',
                'c_title_chn' => 'Sample Title CHN',
                'c_created_by' => 'origin',
                'c_created_date' => '20200101',
                'c_modified_by' => 'previous',
                'c_modified_date' => '20200102',
            ],
        ]);

        $user = new User([
            'name' => 'text-admin',
            'email' => 'text-admin@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 5;
        $user->is_active = 1;
        $this->actingAs($user);

        $props = $this->appProps('/app/codes/TEXT_CODES');

        // 主鍵覆寫生效：只有 c_textid 是鍵欄（沒被推成複合鍵）。
        $this->assertSame(['c_textid'], $props['key_columns']);
        // 原測試斷言表頭上每一欄的字串都出現過、以及 PK 徽章的 Bootstrap class。
        // React 版表頭由 `thead` prop 決定、PK 徽章由 `key_columns` 決定 ⇒ 斷言 thead
        // 的**完整內容**（連順序）比逐一 assertSee 強：少一欄、多一欄、順序變了都會紅。
        $this->assertSame(
            ['c_textid', 'c_title', 'c_title_chn', 'c_bibl_cat_code', 'c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date'],
            $props['thead']
        );
        $this->assertSame(['Sample Title CHN'], $this->rowColumn($props, 'c_title_chn'));
        // 原測試的 assertDontSee('href="/codes/TEXT_CODES/T001_._') 是在驗「單欄主鍵不可
        // 被組成 `T001_._…`」。React 的編輯連結由前端拿 `key_columns` 組（rowId()），
        // 伺服器端的對應物就是上面那條 key_columns 斷言；編輯 URL 模板本身另驗。
        $this->assertSame('/app/codes/TEXT_CODES/__ID__/edit', $props['urls']['edit_template']);
        $this->assertEmpty($this->operationSpy->calls);
    }

    #[Test]
    public function testSingleColumnPrimaryKeyUsesSchemaPrimaryIndexForLinksAndOperationIds() {
        DB::table('APPOINTMENT_TYPES')->insert([
            [
                'c_appt_type_code' => 'T001',
                'c_appt_type_desc' => 'Desc 1',
                'c_appt_type_desc_chn' => '描述一',
            ],
        ]);

        $user = new User([
            'name' => 'appt-admin',
            'email' => 'appt-admin@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 51;
        $user->is_active = 1;
        $this->actingAs($user);

        $props = $this->appProps('/app/codes/APPOINTMENT_TYPES');

        $this->assertSame(['c_appt_type_code'], $props['key_columns']);
        $this->assertSame(['T001'], $this->rowColumn($props, 'c_appt_type_code'));
        $this->assertSame('/app/codes/APPOINTMENT_TYPES/__ID__/edit', $props['urls']['edit_template']);

    }

    /**
     * ── 2026-09-15（Blade 下架環節 4b-2c-1）─────────────────────────
     *
     * 從上一條測試**拆出來**的寫入面（codex 查出）。原本它跟「列表頁的連結長相」混在同一條，
     * 於是整條被歸進「讀取面、留給 4b-2c-2」，結果**全 repo 唯一驗「單欄主鍵表的
     * `operations.resource_id` 不可被寫成複合鍵形式 `T002_._…`」的斷言，仍然只跑 legacy 端點**。
     *
     * 這條不變量與 Blade／React 無關（`performStore` 是共用的），但既然 legacy 端點即將實體
     * 刪除，它必須在 React 端點上成立。列表頁那半（`keyColumns` view 變數與 HTML 連結）
     * 留在原測試裡，隨其餘 32 條讀取面在 4b-2c-2 一起移植。
     *
     * 重導目標照 React 實況：`appStore()` 的 `$editRoute` 也是 `app.codes.show`，
     * 而那條路由沒有 `{id}` 段 ⇒ 落在列表頁並把 id 掛成 query。
     */
    #[Test]
    public function testSingleColumnPrimaryKeyStoreRecordsAScalarOperationResourceId() {
        $user = new User([
            'name' => 'appt-admin',
            'email' => 'appt-admin2@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 52;
        $user->is_active = 1;
        $this->actingAs($user);

        $this->operationSpy->calls = [];

        $storeResponse = $this->post('/app/codes/APPOINTMENT_TYPES', [
            'c_appt_type_code' => 'T002',
            'c_appt_type_desc' => 'Desc 2',
            'c_appt_type_desc_chn' => '描述二',
        ]);

        $storeResponse->assertRedirect(route('app.codes.show', [
            'table_name' => 'APPOINTMENT_TYPES',
            'id' => 'T002',
        ]));
        $this->assertCount(1, $this->operationSpy->calls);
        $this->assertSame('T002', $this->operationSpy->calls[0]['resource_id']);
    }

    #[Test]
    public function testActiveUserCanSubmitCreateProposal() {
        $this->operationSpy->calls = [];
        $user = new User([
            'name' => 'proposer',
            'email' => 'proposer@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 10;
        $user->is_active = 1;
        $this->actingAs($user);

        $payload = [
            'code_id' => 'PX',
            'code_sub' => '01',
            'description' => 'Proposal create',
            '__proposal_comment' => 'Please review',
        ];

        $response = $this->from('/app/codes/TEST_CODES/create')
            ->post('/app/codes/TEST_CODES/proposal', $payload);

        $response->assertRedirect(route('app.codes.show', ['table_name' => 'TEST_CODES']));

        $this->assertCount(1, $this->operationSpy->calls);
        $call = $this->operationSpy->calls[0];
        $this->assertSame(0, $call['c_personid']);
        $this->assertSame(\App\Models\Operation::TYPE_PROPOSAL_CREATE, $call['op_type']);
        $this->assertSame('TEST_CODES', $call['resource']);
        $this->assertSame('PX_._01', substr($call['resource_id'], 0, 7));
        $this->assertSame('pending', $call['resource_data']['__review_status']);
        $this->assertSame(['code_id', 'code_sub'], $call['resource_data']['__key_columns']);
        $this->assertSame('Proposal create', $call['resource_data']['description']);
        $this->assertSame('Please review', $call['resource_data']['__proposal_meta']['comment']);
    }

    #[Test]
    public function testDuplicateCreateProposalIsBlockedWhenPendingExists() {
        $this->operationSpy->calls = [];
        $user = new User([
            'name' => 'proposer',
            'email' => 'proposer@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 12;
        $user->is_active = 1;
        $this->actingAs($user);

        $payload = [
            'code_id' => 'PX',
            'code_sub' => '01',
            'description' => 'Proposal create',
            '__proposal_comment' => 'Please review',
        ];

        $this->post('/app/codes/TEST_CODES/proposal', $payload);
        $this->assertCount(1, $this->operationSpy->calls);
        $call = $this->operationSpy->calls[0];

        DB::table('operations')->insert([
            'id' => 1,
            'user_id' => $user->id,
            'resource' => $call['resource'],
            'resource_id' => $call['resource_id'],
            'op_type' => $call['op_type'],
            'resource_data' => json_encode($call['resource_data']),
            'resource_original' => json_encode($call['ori']),
            'created_at' => Carbon::now()->format('Y-m-d H:i:s'),
            'updated_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);

        $this->operationSpy->calls = [];

        $response = $this->from('/app/codes/TEST_CODES/create')
            ->post('/app/codes/TEST_CODES/proposal', $payload);

        $response->assertRedirect('/app/codes/TEST_CODES/create');
        $response->assertSessionHas('_old_input.code_id', 'PX');
        $this->assertEmpty($this->operationSpy->calls);
    }
    // ── 2026-09-15（Blade 下架環節 4b-2c-1）─────────────────────────
    // 這裡原本有 testProposalOwnerCanViewEditFormForCreateProposal。它**不打 HTTP**：
    // new 一個匿名子類覆蓋 findOperationOrAbort()，直接呼叫 $controller->proposalEdit()
    // 並斷言 $view->getName() === 'codes.proposal-edit'——那個斷言本身就是 Blade 專屬的。
    // 已移植到 tests/Feature/CodesProposalEditInertiaTest.php 的
    // composite_key_create_proposal_renders_both_key_columns()（打真正的路由、走授權），
    // 那裡同時補上了本 repo 缺的「create 提案 ＋ 複合主鍵」形狀。

    #[Test]
    public function testActiveUserCanSubmitUpdateProposal() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'UX', 'code_sub' => '02', 'description' => 'Original'],
        ]);

        $this->operationSpy->calls = [];
        $user = new User([
            'name' => 'editor',
            'email' => 'editor@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 11;
        $user->is_active = 1;
        $this->actingAs($user);

        $payload = [
            'code_id' => 'UX',
            'code_sub' => '02',
            'description' => 'Updated Desc',
            '__proposal_comment' => 'Need approval',
        ];

        $response = $this->from('/app/codes/TEST_CODES/UX_._02/edit')
            ->post('/app/codes/TEST_CODES/UX_._02/proposal', $payload);

        $response->assertRedirect(route('app.codes.edit', ['table_name' => 'TEST_CODES', 'id' => 'UX_._02']));

        $this->assertCount(1, $this->operationSpy->calls);
        $call = $this->operationSpy->calls[0];
        $this->assertSame(\App\Models\Operation::TYPE_PROPOSAL_UPDATE, $call['op_type']);
        $this->assertSame('pending', $call['resource_data']['__review_status']);
        $this->assertSame('Updated Desc', $call['resource_data']['description']);
        $this->assertSame('Original', $call['ori']['description']);
        $this->assertSame(['code_id', 'code_sub'], $call['resource_data']['__key_columns']);
    }

    #[Test]
    public function testProposalOwnerCanCancelPendingProposal() {
        $user = new User([
            'name' => 'proposer',
            'email' => 'proposer@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 15;
        $user->is_active = 1;
        $this->actingAs($user);

        DB::table('operations')->delete();
        $resourceData = [
            'code_id' => 'PX',
            'code_sub' => '01',
            'description' => 'Proposal',
            '__key_columns' => ['code_id', 'code_sub'],
            '__review_status' => 'pending',
            '__proposal_meta' => [
                'submitted_by' => $user->name,
                'submitted_by_id' => $user->id,
                'submitted_at' => Carbon::now()->format('Y-m-d H:i:s'),
            ],
        ];
        DB::table('operations')->insert([
            'id' => 4,
            'user_id' => $user->id,
            'resource' => 'TEST_CODES',
            'resource_id' => 'PX_._01',
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource_data' => json_encode($resourceData),
            'resource_original' => json_encode([]),
            'created_at' => Carbon::now()->format('Y-m-d H:i:s'),
            'updated_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);

        $response = $this->from(route('app.operations.index', ['proposals_only' => 1]))
            ->delete(route('app.codes.proposals.cancel', ['table_name' => 'TEST_CODES', 'operation' => 4]));

        $response->assertRedirect(route('app.operations.index', ['proposals_only' => 1]));

        $row = DB::table('operations')->first();
        $stored = json_decode($row->resource_data, true);
        $this->assertSame('cancelled', $stored['__review_status']);
        $this->assertArrayHasKey('cancelled_at', $stored['__proposal_meta']);
        $this->assertSame($user->name, $stored['__proposal_meta']['cancelled_by']);
        $this->assertSame($user->id, $stored['__proposal_meta']['cancelled_by_id']);
    }

    #[Test]
    public function testProposalOwnerUpdateResetsStatusToPending() {
        $user = new User([
            'name' => 'proposer',
            'email' => 'proposer@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 14;
        $user->is_active = 1;
        $this->actingAs($user);

        DB::table('operations')->delete();
        $resourceData = [
            'code_id' => 'PX',
            'code_sub' => '01',
            'description' => 'Proposal',
            '__key_columns' => ['code_id', 'code_sub'],
            '__review_status' => 'rejected',
            '__review_comment' => 'Missing info',
            '__proposal_meta' => [
                'submitted_by' => $user->name,
                'submitted_by_id' => $user->id,
                'submitted_at' => Carbon::now()->subDay()->format('Y-m-d H:i:s'),
            ],
        ];
        DB::table('operations')->insert([
            'id' => 3,
            'user_id' => $user->id,
            'resource' => 'TEST_CODES',
            'resource_id' => 'PX_._01',
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource_data' => json_encode($resourceData),
            'resource_original' => json_encode([]),
            'created_at' => Carbon::now()->subDay()->format('Y-m-d H:i:s'),
            'updated_at' => Carbon::now()->subDay()->format('Y-m-d H:i:s'),
        ]);

        $response = $this->from(route('app.codes.proposals.edit', ['table_name' => 'TEST_CODES', 'operation' => 3]))
            ->patch(route('app.codes.proposals.update', ['table_name' => 'TEST_CODES', 'operation' => 3]), [
                'code_id' => 'PX',
                'code_sub' => '02',
                'description' => 'Updated proposal',
                '__proposal_comment' => 'Updated info',
            ]);

        $response->assertRedirect(route('app.operations.index', ['proposals_only' => 1]));

        $row = DB::table('operations')->first();
        $this->assertNotNull($row);
        $stored = json_decode($row->resource_data, true);
        $this->assertSame('PX', $stored['code_id']);
        $this->assertSame('02', $stored['code_sub']);
        $this->assertSame('Updated proposal', $stored['description']);
        $this->assertSame('pending', $stored['__review_status']);
        $this->assertArrayNotHasKey('__review_comment', $stored);
        $this->assertArrayHasKey('updated_at', $stored['__proposal_meta']);
        $this->assertSame('Updated info', $stored['__proposal_meta']['comment']);

        $this->assertSame('PX_._02', $row->resource_id);
    }

    #[Test]
    public function testProposalUpdateExistingNormalizesTier1Pinyin() {
        // §D-6：編輯既有提案時，Tier 1 欄（TEXT_CODES.c_title）亦須歸一化，避免核准落庫仍帶 v。
        $user = $this->activeUser('proposal-edit', 15);
        $this->actingAs($user);

        DB::table('operations')->delete();
        DB::table('operations')->insert([
            'id' => 8,
            'user_id' => 15,
            'resource' => 'TEXT_CODES',
            'resource_id' => 'T500',
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource_data' => json_encode([
                'c_textid' => 'T500',
                'c_title' => 'old',
                '__key_columns' => ['c_textid'],
                '__review_status' => 'rejected',
                '__proposal_meta' => ['submitted_by' => $user->name, 'submitted_by_id' => 15, 'submitted_at' => Carbon::now()->subDay()->format('Y-m-d H:i:s')],
            ]),
            'resource_original' => json_encode([]),
            'created_at' => Carbon::now()->subDay()->format('Y-m-d H:i:s'),
            'updated_at' => Carbon::now()->subDay()->format('Y-m-d H:i:s'),
        ]);

        $this->from(route('app.codes.proposals.edit', ['table_name' => 'TEXT_CODES', 'operation' => 8]))
            ->patch(route('app.codes.proposals.update', ['table_name' => 'TEXT_CODES', 'operation' => 8]), [
                'c_textid' => 'T500',
                'c_title' => 'Lvzhai',
            ])->assertRedirect(route('app.operations.index', ['proposals_only' => 1]));

        $stored = json_decode(DB::table('operations')->first()->resource_data, true);
        $this->assertSame('Lüzhai', $stored['c_title']); // 提案 payload 已歸一化
    }

    #[Test]
    public function testAuditFieldsAreReadonlyAndPrefilledOnEdit() {
        Carbon::setTestNow(Carbon::create(2024, 3, 22, 12));

        DB::table('TEXT_CODES')->insert([
            [
                'c_textid' => 'T001',
                'c_title' => 'Sample Title',
                'c_title_chn' => 'Sample Title CHN',
                'c_created_by' => 'origin',
                'c_created_date' => '2020-01-01 00:00:00',
                'c_modified_by' => 'previous',
                'c_modified_date' => '2020-01-02 00:00:00',
            ],
        ]);

        $user = new User([
            'name' => 'text-admin',
            'email' => 'text-admin@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 6;
        $user->is_active = 1;
        $this->actingAs($user);

        // 原測試在 HTML 裡用 strpos 找 `name="c_created_by"`，再在它**之後**找 value 與
        // readonly——那是位置式比對，任何版型調整都可能讓它假綠。React 版把同一組語義
        // 放進兩個 prop：`values`（顯示的原始值）與 `column_behaviour`（唯讀與替換預覽）。
        $props = $this->appProps('/app/codes/TEXT_CODES/T001/edit');

        $values = (array) $props['values'];
        $behaviour = (array) $props['column_behaviour'];

        // 四個稽核欄都顯示**原始值**（不是當前使用者／當前時間）且唯讀。
        $expectedValues = [
            'c_created_by' => 'origin',
            'c_created_date' => '2020-01-01 00:00:00',
            'c_modified_by' => 'previous',
            'c_modified_date' => '2020-01-02 00:00:00',
        ];
        foreach ($expectedValues as $column => $expected) {
            $this->assertSame($expected, $values[$column] ?? null, $column.' 應顯示原始值');
            $this->assertTrue(((array) ($behaviour[$column] ?? []))['readonly'] ?? false, $column.' 應唯讀');
        }

        // 只有 c_modified_* 有「提交後會被替換為 X」預覽（c_created_* 不會被改寫，所以沒有）。
        $expectedTimestamp = Carbon::now()->timezone(config('app.timezone'))->format('Y-m-d H:i:s');
        $this->assertSame(
            '欄位內容提交後會被替換為：text-admin',
            ((array) ($behaviour['c_modified_by']['hint'] ?? []))['text'] ?? null
        );
        $this->assertSame(
            '欄位內容提交後會被替換為：'.$expectedTimestamp,
            ((array) ($behaviour['c_modified_date']['hint'] ?? []))['text'] ?? null
        );
        $this->assertArrayNotHasKey('hint', (array) ($behaviour['c_created_by'] ?? []));
        $this->assertArrayNotHasKey('hint', (array) ($behaviour['c_created_date'] ?? []));

        Carbon::setTestNow();
    }

    #[Test]
    public function testActiveUserUpdateLogsOperation() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Old'],
        ]);

        $this->operationSpy->calls = [];

        $user = new User([
            'name' => 'editor',
            'email' => 'editor@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 3;
        $user->is_active = 1;
        $this->actingAs($user);

        $response = $this->put('/app/codes/TEST_CODES/A1_._X1', [
            'description' => 'Updated',
        ]);

        $response->assertRedirect(route('app.codes.edit', [
            'table_name' => 'TEST_CODES',
            'id' => 'A1_._X1',
        ]));

        $this->assertCount(1, $this->operationSpy->calls);
        $call = $this->operationSpy->calls[0];
        $this->assertSame(2, $call['op_type']);
        $this->assertSame('TEST_CODES', $call['resource']);
        $this->assertSame('A1_._X1', $call['resource_id']);
        $this->assertSame('Updated', $call['resource_data']['description']);
        $this->assertSame('Old', $call['ori']['description']);
    }

    #[Test]
    public function testDestroyIsDisabledAndRecordsNothing() {
        // 安全：碼表刪除已停用（防級聯刪除人物資料）。仍導回 show，但不刪列、不記 operation。
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'To delete'],
        ]);

        $this->operationSpy->calls = [];

        $user = new User([
            'name' => 'deleter',
            'email' => 'deleter@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 4;
        $user->is_active = 1;
        $this->actingAs($user);

        $response = $this->delete('/app/codes/TEST_CODES/A1_._X1');

        $response->assertRedirect(route('app.codes.show', ['table_name' => 'TEST_CODES']));

        // 封堵在刪除前直接 return，故不會記錄任何刪除 operation。
        $this->assertCount(0, $this->operationSpy->calls);
    }

    #[Test]
    public function testUpdateGracefullyHandlesDuplicateKey() {
        $this->fakeDb->tables['TEST_CODES'][] = ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Old'];
        $this->fakeDb->setFailure('update', 'Duplicate entry #1062');
        $this->operationSpy->calls = [];

        $user = new User([
            'name' => 'editor',
            'email' => 'editor@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 6;
        $user->is_active = 1;
        $this->actingAs($user);

        $response = $this->from('/app/codes/TEST_CODES/A1_._X1/edit')->put('/app/codes/TEST_CODES/A1_._X1', [
            'description' => 'Updated',
        ]);

        $response->assertRedirect('/app/codes/TEST_CODES/A1_._X1/edit');
        $response->assertSessionHasErrors(['duplicate']);
        $response->assertSessionHas('_old_input.description', 'Updated');
        $this->assertEmpty($this->operationSpy->calls);

        $this->fakeDb->clearFailures();
        $this->assertCount(1, $this->fakeDb->failuresCleared);
    }

    // ──────────────────────────────────────────────────────────────
    // Phase 2: filter / sort tests
    // ──────────────────────────────────────────────────────────────

    // -- Blade 下架環節 4b-2c-2 的移植輔助 --------------------------------
    //
    // 讀取面從 Blade 視圖變數／HTML 改成斷言 Inertia props。對照：
    //   sortBy→sort_by、sortDir→sort_dir、booleanEnabled→boolean_enabled、
    //   booleanFilterAvailable→boolean_filter_available、filterErrors→filter_errors、
    //   filterDescriptions→filter_descriptions、useCursorPagination→use_cursor、
    //   keyColumns→key_columns、appliedFilters→applied_filters（本環節新增的 prop）。
    //
    // 本環節的排序／篩選需要登入且已啟用帳號（guardSortFilterRequiresAuth()，
    // 見 docs/CODES_SORT_FILTER_AUTH_GATE.md），Blade 端沒有這道門檻。所以帶 sort_by／
    // filters 的測試一律先 actingAs($this->activeReader())。門檻本身的**擋下**路徑由
    // CodesShowInertiaTest::guest_sort_or_filter_on_kinship_codes_computed_column_requires_login()
    // 覆蓋，這裡走的是放行路徑。

    /** 打 React 版 /app/codes/... 並取回 Inertia props（斷言 200）。 */
    private function appProps(string $uri): array {
        $props = [];

        $this->get($uri)
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    /**
     * 取 rows prop 的某一欄。
     *
     * 刻意回傳**欄值清單**而不是把整個 payload 轉成字串再做子字串比對——原本的
     * assertSee('Beta entry') 只證明「頁面某處出現過這串字」，這裡證明「結果集就是這些列」，
     * 順序錯、多撈一列、欄位錯位都會紅。
     *
     * @return array<int,string>
     */
    private function rowColumn(array $props, string $column): array {
        return array_map(
            fn ($row) => (string) (((array) $row)[$column] ?? ''),
            $props['rows'] ?? []
        );
    }

    /** 排序／篩選需要的「已登入且已啟用」讀者。 */
    private function activeReader(): User {
        $user = new User([
            'name' => 'reader',
            'email' => 'reader@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 90;
        $user->is_active = 1;
        $this->actingAs($user);

        return $user;
    }

    #[Test]
    public function testSortByValidColumnReturns200() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'B1', 'code_sub' => 'Y1', 'description' => 'Banana'],
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Apple'],
        ]);

        $this->activeReader();
        $props = $this->appProps('/app/codes/TEST_CODES?sort_by=description&sort_dir=asc');

        $this->assertSame('description', $props['sort_by']);
        $this->assertSame('asc', $props['sort_dir']);
        // 原本只斷言「兩個字串都出現在頁面上」，這裡連**順序**一起釘住——正是 sort_by
        // 這條測試的重點，而 assertSee 對順序是盲的。
        $this->assertSame(['Apple', 'Banana'], $this->rowColumn($props, 'description'));
    }

    #[Test]
    public function testSortByInvalidColumnIsIgnored() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha'],
        ]);

        $this->activeReader();
        $props = $this->appProps('/app/codes/TEST_CODES?sort_by=non_existent_column&sort_dir=asc');

        $this->assertSame('', $props['sort_by']);
    }

    #[Test]
    public function testSortDirInvalidDefaultsToAsc() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha'],
        ]);

        $this->activeReader();
        $props = $this->appProps('/app/codes/TEST_CODES?sort_by=description&sort_dir=INVALID');

        $this->assertSame('asc', $props['sort_dir']);
    }

    #[Test]
    public function testFilterByValidColumnReturnsMatchingRows() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
            ['code_id' => 'B1', 'code_sub' => 'Y1', 'description' => 'Beta entry'],
            ['code_id' => 'C1', 'code_sub' => 'Z1', 'description' => 'Gamma entry'],
        ]);

        $this->activeReader();
        $props = $this->appProps('/app/codes/TEST_CODES?filters[description]=Beta');

        $this->assertSame(['Beta entry'], $this->rowColumn($props, 'description'));
        $this->assertSame(['description' => 'Beta'], (array) $props['filters']);
    }

    #[Test]
    public function testFilterByInvalidColumnIsIgnored() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha'],
        ]);

        $this->activeReader();
        $props = $this->appProps('/app/codes/TEST_CODES?filters[non_existent]=value');

        $this->assertSame([], (array) $props['filters']);
        // 未知欄位被丟掉 ⇒ 沒有任何條件，整表照撈（否則「篩掉全部」也會讓 filters 為空）。
        $this->assertSame(['Alpha'], $this->rowColumn($props, 'description'));
    }

    #[Test]
    public function testFilterArrayAttackIsDiscarded() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha'],
        ]);

        $this->activeReader();
        $props = $this->appProps('/app/codes/TEST_CODES?filters[description][]=array_attack');

        $this->assertSame([], (array) $props['filters']);
        $this->assertSame(['Alpha'], $this->rowColumn($props, 'description'));
    }

    #[Test]
    public function testFilterAndSortTogether() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'C1', 'code_sub' => 'Z1', 'description' => 'Cherry'],
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Apple'],
            ['code_id' => 'B1', 'code_sub' => 'Y1', 'description' => 'Banana'],
        ]);

        $this->activeReader();
        $props = $this->appProps('/app/codes/TEST_CODES?filters[code_sub]=X1&sort_by=code_id&sort_dir=asc');

        $this->assertSame(['Apple'], $this->rowColumn($props, 'description'));
        $this->assertSame('code_id', $props['sort_by']);
        $this->assertSame(['code_sub' => 'X1'], (array) $props['filters']);
    }

    #[Test]
    public function testFilterEmptyValueIsIgnored() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha'],
            ['code_id' => 'B1', 'code_sub' => 'Y1', 'description' => 'Beta'],
        ]);

        // 空值篩選不需要登入——guardSortFilterRequiresAuth() 的判準是「有非空的 filters」，
        // 全空就不觸發門檻。這條順帶釘住那個判準：改成「有 filters 鍵就擋」會讓它 302。
        $props = $this->appProps('/app/codes/TEST_CODES?filters[description]=');

        $this->assertSame(['Alpha', 'Beta'], $this->rowColumn($props, 'description'));
        $this->assertSame([], (array) $props['filters']);
    }

    #[Test]
    public function testViewReceivesFilterSortDirVariables() {
        $props = $this->appProps('/app/codes/TEST_CODES');

        $this->assertSame([], (array) $props['filters']);
        $this->assertSame('', $props['sort_by']);
        $this->assertSame('asc', $props['sort_dir']);
    }

    // ── Phase 3：JOIN 表 resolveColumnForQuery 單元測試 ────────────────

    // 這裡原本有 4 條用 ReflectionMethod 直呼 resolveColumnForQuery() 的**防注入**單元測試。
    // 同樣不打 HTTP、與 Blade 無關，已於環節 4b-2a 搬到
    // **tests/Unit/CodesResolveColumnForQueryTest.php**。
    //
    // 搬家時兩處刻意的改動：① 「base table 欄位」那條原本依賴本檔底部的 FakeSchemaBuilder，
    // 改成真的建一張 APPOINTMENT_CODE_TYPE_REL（那組假 DB 只服務本檔、不隨檔搬家，
    // 而且真表比假 DB 更貼近真實）；② dot-prefix 那條斷言由 'malicious.injection' 改寫成
    // 'other.c_appt_code'——dot 後半改用**真實欄名**，才證明是「先看到 dot 就拒」，
    // 而不是靠「欄名不存在」順帶擋掉。

    // ── Phase 3：JOIN 表整合測試 ───────────────────────────────────────

    #[Test]
    public function testAppointmentCodeTypeRelSortAndFilterReturns200() {
        DB::table('APPOINTMENT_CODE_TYPE_REL')->insert([
            ['c_appt_code' => 'A1', 'c_appt_type_code' => 'T1'],
            ['c_appt_code' => 'B2', 'c_appt_type_code' => 'T2'],
        ]);

        $this->activeReader();

        // sort on JOIN alias column（由 getJoinedColumnNames 加入 $thead）
        $this->fakeDb->recordedOrderBys = [];
        $props = $this->appProps('/app/codes/APPOINTMENT_CODE_TYPE_REL?sort_by=appt_name&sort_dir=asc');
        $this->assertSame('appt_name', $props['sort_by']);
        // resolveColumnForQuery must resolve JOIN alias → fully-qualified expression
        $this->assertContains(['code.c_appt_desc_chn', 'asc'], $this->fakeDb->recordedOrderBys);

        // filter on base table column
        $props2 = $this->appProps('/app/codes/APPOINTMENT_CODE_TYPE_REL?filters[c_appt_code]=A1');
        $this->assertSame(['c_appt_code' => 'A1'], (array) $props2['filters']);

        // 不在 $thead 白名單的欄位 → sanitizeSortParameters 清空 sortBy → 200，無例外
        $this->fakeDb->recordedOrderBys = [];
        $props3 = $this->appProps('/app/codes/APPOINTMENT_CODE_TYPE_REL?sort_by=non_existent_column');
        $this->assertSame('', $props3['sort_by']);
        // no user-requested column should appear; PK tie-breakers are still recorded
        $this->assertNotContains('non_existent_column', array_column($this->fakeDb->recordedOrderBys, 0));
    }

    #[Test]
    public function testOfficeCodeTypeRelSortAndFilterReturns200() {
        DB::table('OFFICE_CODE_TYPE_REL')->insert([
            ['c_office_id' => 1, 'c_office_tree_id' => 10],
            ['c_office_id' => 2, 'c_office_tree_id' => 20],
        ]);

        $this->activeReader();

        // sort on JOIN alias column
        $this->fakeDb->recordedOrderBys = [];
        $props = $this->appProps('/app/codes/OFFICE_CODE_TYPE_REL?sort_by=office_name&sort_dir=desc');
        $this->assertSame('office_name', $props['sort_by']);
        // resolveColumnForQuery must resolve JOIN alias → fully-qualified expression
        $this->assertContains(['code.c_office_chn', 'desc'], $this->fakeDb->recordedOrderBys);

        // filter on base table column
        $props2 = $this->appProps('/app/codes/OFFICE_CODE_TYPE_REL?filters[c_office_id]=1');
        $this->assertSame(['c_office_id' => '1'], (array) $props2['filters']);
    }
}

class FakeDatabaseManager {
    public $tables = [];
    public $failures = [];
    public $failuresCleared = [];
    public $schemaColumns = [];
    public $schemaIndexes = [];
    public array $recordedOrderBys = [];

    public function __construct(array $tables = [], array $schemaColumns = [], array $schemaIndexes = []) {
        $this->tables = $tables;
        $this->schemaColumns = $schemaColumns;
        $this->schemaIndexes = $schemaIndexes;
    }

    public function table($name) {
        $normalizedName = preg_split('/\s+as\s+/i', $name)[0] ?? $name;
        if (!array_key_exists($normalizedName, $this->tables)) {
            $this->tables[$normalizedName] = [];
        }

        $rows = &$this->tables[$normalizedName];

        return new FakeQueryBuilder($rows, $this, $normalizedName);
    }

    public function connection($name = null) {
        return $this;
    }

    public function getDoctrineSchemaManager() {
        return new FakeDoctrineSchemaManager();
    }

    public function select($query) {
        return [];
    }

    public function getSchemaBuilder() {
        return new FakeSchemaBuilder($this->schemaColumns, $this->schemaIndexes);
    }

    public function setFailure(string $operation, string $message = 'Simulated failure'): void {
        $this->failures[$operation] = $message;
    }

    public function clearFailures(): void {
        $this->failures = [];
        $this->failuresCleared[] = true;
    }

    public function shouldFail(string $operation): bool {
        return array_key_exists($operation, $this->failures);
    }

    public function failureMessage(string $operation): string {
        return $this->failures[$operation] ?? 'Simulated failure';
    }
}

class FakeDoctrineSchemaManager {
    public function listTableDetails($table) {
        return new FakeTableDetails();
    }
}

class FakeTableDetails {
    public function hasPrimaryKey() {
        return false;
    }

    public function getPrimaryKey() {
        return null;
    }
}

class FakeSchemaBuilder {
    private $schemaColumns = [];
    private $schemaIndexes = [];

    public function __construct(array $schemaColumns = [], array $schemaIndexes = []) {
        $this->schemaColumns = $schemaColumns;
        $this->schemaIndexes = $schemaIndexes;
    }

    public function getColumnListing($table) {
        if (isset($this->schemaColumns[$table]) && !empty($this->schemaColumns[$table])) {
            return $this->schemaColumns[$table];
        }

        return ['code_id', 'code_sub', 'description'];
    }

    public function hasTable($table) {
        return array_key_exists($table, $this->schemaColumns);
    }

    /**
     * `VariantReplaceScope` 以 `Schema::getColumns()` 的 type_name 判斷哪些欄是文本型。
     *
     * 假 driver 一律回 varchar：這些代碼表欄位在真實庫都是 varchar／text。落地替換在本
     * harness 下實際仍是 no-op（沒有 char_variant_map 表，服務會降級不替換），所以這個
     * 方法只是讓型別查詢不炸——**不能**靠 loadColumnTypes() 吞掉 Error 來達成：那個
     * catch 已收窄成「只有表確實不存在才降級」，否則一次瞬時錯誤會讓整個 process 之後
     * 都不替換（見 plan S3 補記第 3 點）。
     */
    public function getColumns($table) {
        return array_map(
            static fn ($name) => ['name' => $name, 'type_name' => 'varchar'],
            $this->getColumnListing($table)
        );
    }

    public function getIndexes($table) {
        return $this->schemaIndexes[$table] ?? [];
    }

    /**
     * `CodesController::personFkColumns()` 用 `Schema::getForeignKeys()` 找指向 BIOG_MAIN
     * 的欄位（人物選擇器與 -999 哨兵正規化靠它）。缺這個方法時該處的
     * `catch (\Throwable)` 會吞掉 Error 並回 []，於是那段邏輯在本 harness 下**從未被驗證**，
     * 每個寫入請求還會多噴一次 testing.ERROR。假 driver 沒有外鍵資訊，回空陣列即可
     * ——需要驗證 FK 行為的測試應自己覆寫這個回傳值。
     */
    public function getForeignKeys($table) {
        return [];
    }
}

class FakeQueryBuilder {
    private $rows;
    private $conditions = [];
    private $orderBys = [];
    private $selectedColumns = [];
    private $limitValue = null;
    private $table;
    private $manager;

    public function __construct(array &$rows, FakeDatabaseManager $manager, string $table) {
        $this->rows = &$rows;
        $this->manager = $manager;
        $this->table = $table;
    }

    public function __clone() {
        $this->conditions = [];
        $this->orderBys = [];
        $this->selectedColumns = [];
        $this->limitValue = null;
    }

    public function where($column, $operator = null, $value = null, $boolean = 'and') {
        if (is_callable($column)) {
            $column($this);

            return $this;
        }

        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->conditions[] = [
            'column' => $column,
            'operator' => strtolower((string) $operator),
            'value' => $value,
            'boolean' => strtolower($boolean),
        ];

        return $this;
    }

    public function orWhere($column, $operator = null, $value = null) {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        return $this->where($column, $operator, $value, 'or');
    }

    public function first() {
        $filtered = $this->applyConditions();
        $first = reset($filtered);

        return $first ? (object) $first : null;
    }

    public function insert(array $data) {
        if ($this->manager->shouldFail('insert')) {
            throw new QueryException('testing', 'insert into '.$this->table, [], new \Exception($this->manager->failureMessage('insert')));
        }

        if (isset($data[0]) && is_array($data[0])) {
            foreach ($data as $row) {
                $this->rows[] = $row;
            }
        } else {
            $this->rows[] = $data;
        }

        return true;
    }

    public function update(array $data) {
        if ($this->manager->shouldFail('update')) {
            throw new QueryException('testing', 'update '.$this->table, [], new \Exception($this->manager->failureMessage('update')));
        }

        foreach ($this->rows as &$row) {
            if ($this->rowMatches($row)) {
                foreach ($data as $key => $value) {
                    $row[$key] = $value;
                }
            }
        }

        return true;
    }

    public function delete() {
        if ($this->manager->shouldFail('delete')) {
            throw new QueryException('testing', 'delete from '.$this->table, [], new \Exception($this->manager->failureMessage('delete')));
        }

        $this->rows = array_values(array_filter($this->rows, function ($row) {
            return !$this->rowMatches($row);
        }));
    }

    public function paginate($perPage) {
        $items = array_map(function ($row) {
            return (object) $row;
        }, $this->applyConditions());

        return new LengthAwarePaginator(
            $items,
            count($items),
            $perPage,
            1,
            ['path' => url()->current()]
        );
    }

    public function get() {
        return collect(array_map(function ($row) {
            return (object) $row;
        }, $this->applyConditions()));
    }

    /**
     * D7 兩形並存查重掃待審提案時用 lazyById() 分批（見 VariantEquivalentLookup）。
     * 假 driver 的資料集本來就在記憶體裡，直接回 get() 的結果即可（語義上等價於單一批次）。
     */
    public function lazyById($chunkSize = 1000, $column = 'id', $alias = null) {
        return $this->get();
    }

    public function orderBy($column, $direction = 'asc') {
        $normalizedDir = strtolower((string) $direction) === 'desc' ? 'desc' : 'asc';
        $this->manager->recordedOrderBys[] = [(string) $column, $normalizedDir];
        $this->orderBys[] = [
            'column' => $this->normalizeColumnName($column),
            'direction' => $normalizedDir,
        ];

        return $this;
    }

    public function select(...$columns) {
        if (count($columns) === 1 && is_array($columns[0])) {
            $columns = $columns[0];
        }

        $this->selectedColumns = $columns;

        return $this;
    }

    public function leftJoin($table, $first, $operator = null, $second = null) {
        return $this;
    }

    public function join($table, $first, $operator = null, $second = null) {
        return $this;
    }

    public function limit($value) {
        $this->limitValue = (int) $value;

        return $this;
    }

    public function max($column) {
        $filtered = $this->applyConditions();
        if (empty($filtered)) {
            return null;
        }

        $values = array_map(function ($row) use ($column) {
            return $row[$this->normalizeColumnName($column)] ?? null;
        }, $filtered);

        $values = array_filter($values, function ($value) {
            return $value !== null;
        });

        if (empty($values)) {
            return null;
        }

        return max($values);
    }

    private function applyConditions(): array {
        $rows = empty($this->conditions)
            ? $this->rows
            : array_values(array_filter($this->rows, function ($row) {
                return $this->rowMatches($row);
            }));

        if (!empty($this->orderBys)) {
            usort($rows, function (array $left, array $right) {
                foreach ($this->orderBys as $orderBy) {
                    $column = $orderBy['column'];
                    $direction = $orderBy['direction'];
                    $leftValue = $left[$column] ?? null;
                    $rightValue = $right[$column] ?? null;

                    if ($leftValue == $rightValue) {
                        continue;
                    }

                    $comparison = $leftValue <=> $rightValue;

                    return $direction === 'desc' ? -$comparison : $comparison;
                }

                return 0;
            });
        }

        if ($this->limitValue !== null) {
            $rows = array_slice($rows, 0, $this->limitValue);
        }

        return array_map(function (array $row) {
            return $this->applySelectedColumns($row);
        }, $rows);
    }

    private function rowMatches(array $row): bool {
        if (empty($this->conditions)) {
            return true;
        }

        $result = null;
        foreach ($this->conditions as $condition) {
            $match = $this->matchCondition($row, $condition);
            if ($condition['boolean'] === 'or') {
                $result = ($result ?? false) || $match;
            } else {
                $result = ($result ?? true) && $match;
            }
        }

        return (bool) $result;
    }

    private function matchCondition(array $row, array $condition): bool {
        $value = $row[$this->normalizeColumnName($condition['column'])] ?? null;
        $expected = $condition['value'];

        if ($condition['operator'] === 'like') {
            $needle = trim((string) $expected, '%');

            return stripos((string) $value, $needle) !== false;
        }

        // '!=' 必須明確處理：落到最後的等值比較會讓語義**反過來**（原本要排除的那列
        // 變成唯一命中的列）。D7 兩形並存查重就是用 where('id','!=',$excludeId) 排除
        // 「核准重放時自己那一筆」，靜默當成等值會讓它自擋。
        if (in_array($condition['operator'], ['!=', '<>'], true)) {
            return (string) $value !== (string) $expected;
        }

        if ($condition['operator'] === '<') {
            return $value < $expected;
        }

        if ($condition['operator'] === '>') {
            return $value > $expected;
        }

        return (string) $value === (string) $expected;
    }

    private function normalizeColumnName(string $column): string {
        if (str_contains($column, '.')) {
            $parts = explode('.', $column);

            return end($parts);
        }

        return $column;
    }

    private function applySelectedColumns(array $row): array {
        if (empty($this->selectedColumns)) {
            return $row;
        }

        $selected = [];
        foreach ($this->selectedColumns as $column) {
            if (!is_string($column)) {
                continue;
            }

            $trimmed = trim($column);
            if (preg_match('/^\w+\.\*$/', $trimmed)) {
                $selected = array_merge($selected, $row);

                continue;
            }

            if (preg_match('/^(.+?)\s+as\s+(.+)$/i', $trimmed, $matches)) {
                $selected[trim($matches[2])] = $row[$this->normalizeColumnName(trim($matches[1]))] ?? null;

                continue;
            }

            $selected[$this->normalizeColumnName($trimmed)] = $row[$this->normalizeColumnName($trimmed)] ?? null;
        }

        return $selected;
    }
}
