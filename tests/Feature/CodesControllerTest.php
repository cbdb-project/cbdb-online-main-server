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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CodesControllerTest extends TestCase {
    protected $operationSpy;
    protected $originalDb;
    protected $fakeDb;

    protected function setUp(): void {
        parent::setUp();

        config(['codes.tables' => ['TEST_CODES', 'TEXT_CODES', 'POSSESSION_DATA', 'CBDB__NAME_FTS', 'APPOINTMENT_CODE_TYPE_REL', 'OFFICE_CODE_TYPE_REL']]);
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
                'operations' => [],
            ],
            [
                'TEST_CODES' => ['code_id', 'code_sub', 'description'],
                'TEXT_CODES' => ['c_textid', 'c_title', 'c_title_chn', 'c_bibl_cat_code', 'c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date'],
                'POSSESSION_DATA' => ['c_personid', 'c_possession_record_id', 'c_sequence', 'c_possession_act_code', 'c_possession_desc'],
                'CBDB__NAME_FTS' => ['id', 'person_name'],
                'APPOINTMENT_CODE_TYPE_REL' => ['c_appt_code', 'c_appt_type_code'],
                'OFFICE_CODE_TYPE_REL' => ['c_office_id', 'c_office_tree_id'],
                'operations' => ['id', 'user_id', 'resource', 'resource_id', 'op_type', 'resource_data', 'resource_original', 'created_at', 'updated_at'],
            ]
        );
        DB::swap($this->fakeDb);
        $this->app->instance('db', $this->fakeDb);

        $this->app->instance(CodesRepository::class, new class () extends CodesRepository {
            public function allowedTables(): array {
                return ['TEST_CODES', 'TEXT_CODES', 'POSSESSION_DATA', 'CBDB__NAME_FTS', 'APPOINTMENT_CODE_TYPE_REL', 'OFFICE_CODE_TYPE_REL'];
            }

            public function allowedTableMap(): array {
                return [
                    'TEST_CODES' => 'TEST_CODES',
                    'TEXT_CODES' => 'TEXT_CODES',
                    'POSSESSION_DATA' => 'POSSESSION_DATA',
                    'CBDB__NAME_FTS' => 'CBDB__NAME_FTS',
                    'APPOINTMENT_CODE_TYPE_REL' => 'APPOINTMENT_CODE_TYPE_REL',
                    'OFFICE_CODE_TYPE_REL' => 'OFFICE_CODE_TYPE_REL',
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

        $response = $this->from('/codes/TEST_CODES/create')
            ->post('/codes/TEST_CODES', $payload);

        $response->assertRedirect('/codes/TEST_CODES/create');
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

        $response = $this->from('/codes/TEST_CODES/create')
            ->post('/codes/TEST_CODES', $payload);

        $response->assertRedirect('/codes/TEST_CODES/create');
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

        $response = $this->from('/codes/TEST_CODES/create')->post('/codes/TEST_CODES', [
            'code_id' => 'A2',
            'description' => 'missing sub key',
        ]);

        $response->assertRedirect('/codes/TEST_CODES/create');
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
        $response = $this->post('/codes/TEST_CODES', $expectedInsert);

        $response->assertRedirect(route('codes.edit', [
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

        $response = $this->post('/codes/TEXT_CODES', $payload);

        $response->assertRedirect(route('codes.edit', [
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

        $response = $this->post('/codes/POSSESSION_DATA/proposal', [
            'c_possession_record_id' => 3,
            'c_possession_desc' => 'proposal row',
            '__proposal_comment' => 'pk override',
        ]);

        $response->assertRedirect('/codes/POSSESSION_DATA');
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

        $response = $this->get('/codes/TEXT_CODES/create');

        $response->assertStatus(200);
        $content = $response->getContent();

        preg_match_all('/name="([^"]+)" class="form-control"/', $content, $matches);
        $this->assertNotEmpty($matches[1]);
        $this->assertSame('c_textid', $matches[1][0]);

        $firstInputMarkupStart = strpos($content, $matches[0][0]);
        $this->assertNotFalse($firstInputMarkupStart);
        $firstInputMarkup = substr($content, $firstInputMarkupStart, 150);
        $this->assertNotFalse(strpos($firstInputMarkup, 'value="42"'));
    }

    #[Test]
    public function testSearchFiltersResults() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
            ['code_id' => 'A2', 'code_sub' => 'X2', 'description' => 'Beta entry'],
            ['code_id' => 'A3', 'code_sub' => 'X3', 'description' => 'Gamma entry'],
        ]);

        $response = $this->get('/codes/TEST_CODES?search=Beta');

        $response->assertStatus(200);
        $response->assertSee('Beta entry');
        $response->assertDontSee('Alpha entry');
        $response->assertDontSee('Gamma entry');
        $response->assertSee('value="Beta"', false);
        $this->assertEmpty($this->operationSpy->calls);
    }

    #[Test]
    public function testColumnFiltersAndSortAreAppliedSafely() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A2', 'code_sub' => 'X2', 'description' => 'Beta second'],
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Beta first'],
            ['code_id' => 'A3', 'code_sub' => 'X3', 'description' => 'Gamma third'],
        ]);

        $response = $this->get('/codes/TEST_CODES?filters[description]=Beta&filters[bad_column%20or%201=1]=ignored&sort_by=code_id&sort_dir=desc%20union');

        $response->assertStatus(200);
        $response->assertSee('Beta second');
        $response->assertSee('Beta first');
        $response->assertDontSee('Gamma third');
        $response->assertSee('name="filters[description]"', false);
        $response->assertSee('value="Beta"', false);
        $response->assertDontSee('bad_column or 1=1', false);

        $content = $response->getContent();
        $this->assertNotFalse($content);
        $this->assertLessThan(strpos($content, 'Beta second'), strpos($content, 'Beta first'));
        $this->assertEmpty($this->operationSpy->calls);
    }

    #[Test]
    public function testCursorPaginationBranchRendersWithoutRuntimeError() {
        DB::table('CBDB__NAME_FTS')->insert([
            ['id' => 1, 'person_name' => 'Alpha'],
            ['id' => 2, 'person_name' => 'Beta'],
            ['id' => 3, 'person_name' => 'Gamma'],
        ]);

        $response = $this->get('/codes/CBDB__NAME_FTS');

        $response->assertStatus(200);
        $response->assertSee('Alpha');
        $response->assertSee('Beta');
        $response->assertSee('Gamma');
        $response->assertViewHas('useCursorPagination', true);
    }

    #[Test]
    public function testUiHiddenExcludedFromCodesListAssociativeConfig() {
        // 生產 config/codes.php 的 tables 是關聯陣列（表名 => 說明），走 codes() 第一分支。
        // 同時用全小寫 'pinyin' vs 大寫 'PINYIN' 鎖住 §9.1 C5 大小寫不敏感比對。
        config(['codes.tables' => ['pinyin' => '拼音表', 'TEST_CODES' => '測試表']]);
        config(['codes.ui_hidden' => ['PINYIN']]);

        $names = array_column((new CodesRepository())->codes(), 'name');

        // 從 /codes 首頁清單隱藏（大小寫不敏感）
        $this->assertNotContains('pinyin', $names);
        // 其他表不受影響
        $this->assertContains('TEST_CODES', $names);
        // 共用白名單（codes.tables）維持完整，不受 ui_hidden 影響
        $this->assertArrayHasKey('pinyin', config('codes.tables'));
    }

    #[Test]
    public function testUiHiddenAlsoFiltersLegacyIndexedConfig() {
        // 向後相容：索引陣列（舊格式）走 codes() 第二分支，過濾同樣生效。
        config(['codes.ui_hidden' => ['CBDB__NAME_FTS']]);

        $names = array_column((new CodesRepository())->codes(), 'name');

        $this->assertNotContains('CBDB__NAME_FTS', $names);
        $this->assertContains('TEST_CODES', $names);
        $this->assertContains('CBDB__NAME_FTS', config('codes.tables'));
    }

    #[Test]
    public function testUiHiddenTableAbsentFromCodesIndexRoute() {
        // 路由層級：GET /codes 首頁不應列出被隱藏的表。
        config(['codes.ui_hidden' => ['CBDB__NAME_FTS']]);

        $response = $this->get('/codes');

        $response->assertStatus(200);
        $response->assertDontSee('CBDB__NAME_FTS');
        $response->assertSee('TEST_CODES');
    }

    #[Test]
    public function testUiHiddenTableStillReachableViaDirectUrl() {
        // ui_hidden 只從清單隱藏，直連 /codes/{table} 仍可達（不 404）。
        config(['codes.ui_hidden' => ['CBDB__NAME_FTS']]);
        DB::table('CBDB__NAME_FTS')->insert([
            ['id' => 1, 'person_name' => 'Alpha'],
        ]);

        $response = $this->get('/codes/CBDB__NAME_FTS');

        $response->assertStatus(200);
        $response->assertSee('Alpha');
    }

    #[Test]
    public function testBooleanModeOffByDefault() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
        ]);

        $response = $this->get('/codes/TEST_CODES');

        $response->assertStatus(200);
        $response->assertViewHas('booleanEnabled', false);
    }

    #[Test]
    public function testBooleanModeEnabledViaQueryParam() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
        ]);

        $response = $this->get('/codes/TEST_CODES?filter_bool=1');

        $response->assertStatus(200);
        $response->assertViewHas('booleanEnabled', true);
    }

    #[Test]
    public function testBooleanModeAppliesSimplePositiveTerm() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
            ['code_id' => 'A2', 'code_sub' => 'X2', 'description' => 'Beta entry'],
        ]);

        $response = $this->get('/codes/TEST_CODES?filter_bool=1&filters[description]=Beta');

        $response->assertStatus(200);
        $response->assertViewHas('booleanEnabled', true);
        $response->assertSee('Beta entry');
        $response->assertDontSee('Alpha entry');
        $response->assertViewHas('appliedFilters', ['description' => 'Beta']);
        $response->assertViewHas('filterErrors', []);
    }

    #[Test]
    public function testBooleanModeMixedValidAndInvalidColumns() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
            ['code_id' => 'A2', 'code_sub' => 'X2', 'description' => 'Beta entry'],
        ]);

        // 好欄位 description=Beta 照常套用；壞欄位 code_sub='X1 AND' 解析失敗 → 記錯誤並略過。
        $response = $this->get('/codes/TEST_CODES?filter_bool=1'
            . '&filters[description]=Beta'
            . '&filters[code_sub]=' . urlencode('X1 AND'));

        $response->assertStatus(200);
        // 好欄位生效：只剩 Beta entry
        $response->assertSee('Beta entry');
        $response->assertDontSee('Alpha entry');
        // 分流正確：appliedFilters 只含好欄位、filterErrors 只含壞欄位
        $response->assertViewHas('appliedFilters', ['description' => 'Beta']);
        $errors = $response->viewData('filterErrors');
        $this->assertSame(['code_sub' => 'dangling_operator'], $errors);

        // 端到端護欄（決策 #19）：分頁連結帶好欄位、不帶被略過的壞欄位
        $paginator = $response->viewData('data');
        $url = $paginator->url(1);
        $this->assertStringContainsString('description', $url);
        $this->assertStringNotContainsString('code_sub', $url);
        $this->assertStringContainsString('filter_bool', $url);

        // blade 狀態攜帶（C6）：filter_bool 帶在 form/連結，互動不會洗掉布林模式
        $response->assertSee('name="filter_bool" value="1"', false);
        // blade 連結/隱藏狀態只帶好欄位（#19）：好欄位進 hidden 狀態，壞欄位不進
        // （壞欄位仍會出現在 filter-row 文字輸入框做回填，故此處精準比對 hidden 狀態）
        $response->assertSee('type="hidden" name="filters[description]"', false);
        $response->assertDontSee('type="hidden" name="filters[code_sub]"', false);
    }

    #[Test]
    public function testBooleanParseErrorRecordedAndColumnSkipped() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
            ['code_id' => 'A2', 'code_sub' => 'X2', 'description' => 'Beta entry'],
        ]);

        // 'Beta AND' 懸空運算子 → 解析失敗 → 該欄記錯誤並略過（不轉字面、不套用），故兩列都顯示
        $response = $this->get('/codes/TEST_CODES?filter_bool=1&filters[description]=' . urlencode('Beta AND'));

        $response->assertStatus(200);
        $response->assertSee('Alpha entry');
        $response->assertSee('Beta entry');
        $response->assertViewHas('appliedFilters', []);
        $errors = $response->viewData('filterErrors');
        $this->assertArrayHasKey('description', $errors);
        $this->assertSame('dangling_operator', $errors['description']);
    }

    #[Test]
    public function testKillSwitchForcesBooleanOff() {
        config(['codes.boolean_filter_enabled' => false]);
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
        ]);

        $response = $this->get('/codes/TEST_CODES?filter_bool=1');

        $response->assertStatus(200);
        $response->assertViewHas('booleanEnabled', false);
    }

    #[Test]
    public function testKillSwitchHidesAdvancedFilterToggle() {
        config(['codes.boolean_filter_enabled' => false]);
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
        ]);

        $response = $this->get('/codes/TEST_CODES');

        $response->assertStatus(200);
        // kill-switch 關閉時整個停用：連開關都不顯示（§2.2）
        $response->assertViewHas('booleanFilterAvailable', false);
        $response->assertDontSee(__('codes.advanced_filter'), false);
    }

    #[Test]
    public function testToggleOffLinkPreservesRawErrorColumn() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
        ]);

        // 布林模式 + 壞欄位 code_sub（解析失敗）。「關閉進階篩選」連結必須保留原始輸入，
        // 讓使用者一鍵把錯誤布林字串降級為字面搜尋，而非讓輸入憑空消失（§9.2）。
        $response = $this->get('/codes/TEST_CODES?filter_bool=1&filters[code_sub]=' . urlencode('X1 AND'));

        $response->assertStatus(200);
        // toggle 連結是 href（方括號 URL-encoded 為 %5B/%5D）；filter-row input 用未編碼方括號，
        // 故此斷言精準命中「連結帶有壞欄位原始值」，證明降級路徑保留它（pagination/sort 連結則排除，見上一測試）。
        $response->assertSee('filters%5Bcode_sub%5D', false);
    }

    #[Test]
    public function testFtsHardShortCircuitIgnoresFilterAndSort() {
        DB::table('CBDB__NAME_FTS')->insert([
            ['id' => 1, 'person_name' => 'Alpha'],
            ['id' => 2, 'person_name' => 'Beta'],
        ]);

        // 即使帶 filters/sort/filter_bool，游標大表也應硬短路：忽略它們、永遠走游標路徑
        $response = $this->get('/codes/CBDB__NAME_FTS?filter_bool=1&filters[person_name]=' . urlencode('Alpha OR Beta') . '&sort_by=person_name&sort_dir=desc');

        $response->assertStatus(200);
        $response->assertViewHas('useCursorPagination', true);
        $response->assertViewHas('filters', []);
        $response->assertViewHas('sortBy', '');
        $response->assertViewHas('booleanEnabled', false);
        // filter 被忽略，兩列都還在
        $response->assertSee('Alpha');
        $response->assertSee('Beta');
    }

    #[Test]
    public function testAdvancedFilterToggleShownWhenOff() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
        ]);

        $response = $this->get('/codes/TEST_CODES');

        $response->assertStatus(200);
        // 關閉狀態：顯示「進階篩選」開啟連結（帶 filter_bool=1）
        $response->assertSee(__('codes.advanced_filter'), false);
        $response->assertSee('filter_bool=1', false);
    }

    #[Test]
    public function testSemanticDescriptionShownForAppliedBooleanFilter() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
            ['code_id' => 'A2', 'code_sub' => 'X2', 'description' => 'Beta entry'],
        ]);

        $response = $this->get('/codes/TEST_CODES?filter_bool=1&filters[description]=Beta');

        $response->assertStatus(200);
        // 後端權威回填的人話描述（zh-TW）
        $response->assertViewHas('filterDescriptions', ['description' => '含「Beta」']);
        $response->assertSee('含「Beta」', false);
        $response->assertSee(__('codes.filter_applied_label'), false);
    }

    #[Test]
    public function testParseErrorShownInUi() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
        ]);

        $response = $this->get('/codes/TEST_CODES?filter_bool=1&filters[description]=' . urlencode('Beta AND'));

        $response->assertStatus(200);
        // 逐欄錯誤標記 + 本地化錯誤訊息 + 彙總警示
        $response->assertSee('is-invalid', false);
        $response->assertSee(__('codes.filter_err_dangling_operator'), false);
        $response->assertSee(__('codes.filter_errors_heading', ['count' => 1]), false);
    }

    #[Test]
    public function testGuestViewDoesNotShowActions() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
        ]);

        $response = $this->get('/codes/TEST_CODES');

        $response->assertStatus(200);
        // Avoid matching sidebar labels containing「修改」/「刪除」，so assert on the action button classes instead.
        $response->assertDontSee('btn btn-sm btn-info');
        $response->assertDontSee('btn btn-sm btn-danger');
        $response->assertDontSee('新增');
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

        $response = $this->get('/codes/TEXT_CODES');

        $response->assertStatus(200);
        $response->assertViewHas('keyColumns', ['c_textid']);
        $response->assertSee('/codes/TEXT_CODES/T001/edit');
        $response->assertDontSee('href="/codes/TEXT_CODES/T001_._', false);
        $response->assertSee('c_textid', false);
        $response->assertSee('badge badge-info ml-1', false);
        $response->assertSee('PK', false);
        $response->assertSee('c_title_chn', false);
        $response->assertSee('c_title', false);
        $response->assertSee('c_created_by', false);
        $response->assertSee('c_created_date', false);
        $response->assertSee('c_modified_by', false);
        $response->assertSee('c_modified_date', false);
        $response->assertSee('Sample Title CHN');
        $this->assertEmpty($this->operationSpy->calls);
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

        $response = $this->from('/codes/TEST_CODES/create')
            ->post('/codes/TEST_CODES/proposal', $payload);

        $response->assertRedirect(route('codes.show', ['table_name' => 'TEST_CODES']));

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

        $this->post('/codes/TEST_CODES/proposal', $payload);
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

        $response = $this->from('/codes/TEST_CODES/create')
            ->post('/codes/TEST_CODES/proposal', $payload);

        $response->assertRedirect('/codes/TEST_CODES/create');
        $response->assertSessionHas('_old_input.code_id', 'PX');
        $this->assertEmpty($this->operationSpy->calls);
    }

    #[Test]
    public function testProposalOwnerCanViewEditFormForCreateProposal() {
        $user = new User([
            'name' => 'proposer',
            'email' => 'proposer@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 13;
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
            'id' => 2,
            'user_id' => $user->id,
            'resource' => 'TEST_CODES',
            'resource_id' => 'PX_._01',
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource_data' => json_encode($resourceData),
            'resource_original' => json_encode([]),
            'created_at' => Carbon::now()->format('Y-m-d H:i:s'),
            'updated_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);

        $controller = new class (app(CodesRepository::class), $this->operationSpy, $resourceData, $user) extends CodesController {
            private $mockOperation;

            public function __construct($codesRepository, $operationRepository, array $resourceData, User $user) {
                parent::__construct($codesRepository, $operationRepository);
                $this->mockOperation = [
                    'id' => 2,
                    'resource' => 'TEST_CODES',
                    'op_type' => Operation::TYPE_PROPOSAL_CREATE,
                    'resource_data' => json_encode($resourceData),
                    'resource_original' => json_encode([]),
                    'user_id' => $user->id,
                ];
            }

            protected function findOperationOrAbort(int $operationId): array {
                return $this->mockOperation;
            }
        };

        $view = $controller->proposalEdit('TEST_CODES', 2);
        $this->assertSame('codes.proposal-edit', $view->getName());
        $data = $view->getData();
        $this->assertSame('PX', $data['values']['code_id']);
        $this->assertSame('01', $data['values']['code_sub']);
        $this->assertSame('Proposal', $data['values']['description']);
    }

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

        $response = $this->from('/codes/TEST_CODES/UX_._02/edit')
            ->post('/codes/TEST_CODES/UX_._02/proposal', $payload);

        $response->assertRedirect(route('codes.edit', ['table_name' => 'TEST_CODES', 'id' => 'UX_._02']));

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

        $response = $this->from(route('operations.index', ['proposals_only' => 1]))
            ->delete(route('codes.proposals.cancel', ['table_name' => 'TEST_CODES', 'operation' => 4]));

        $response->assertRedirect(route('operations.index', ['proposals_only' => 1]));

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

        $response = $this->from(route('codes.proposals.edit', ['table_name' => 'TEST_CODES', 'operation' => 3]))
            ->patch(route('codes.proposals.update', ['table_name' => 'TEST_CODES', 'operation' => 3]), [
                'code_id' => 'PX',
                'code_sub' => '02',
                'description' => 'Updated proposal',
                '__proposal_comment' => 'Updated info',
            ]);

        $response->assertRedirect(route('operations.index', ['proposals_only' => 1]));

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

        $response = $this->get('/codes/TEXT_CODES/T001/edit');

        $response->assertStatus(200);
        $content = $response->getContent();

        // c_created_by 应显示原始值并且 readonly
        $createdByPos = strpos($content, 'name="c_created_by"');
        $this->assertNotFalse($createdByPos);
        $this->assertNotFalse(strpos($content, 'value="origin"', $createdByPos));
        $this->assertNotFalse(strpos($content, 'readonly', $createdByPos));

        // c_created_date 应显示原始值并且 readonly
        $createdDatePos = strpos($content, 'name="c_created_date"');
        $this->assertNotFalse($createdDatePos);
        $this->assertNotFalse(strpos($content, 'value="2020-01-01 00:00:00"', $createdDatePos));
        $this->assertNotFalse(strpos($content, 'readonly', $createdDatePos));

        // c_modified_by 应显示原始值（"previous"）而非当前用戶，并且 readonly
        $modifiedByPos = strpos($content, 'name="c_modified_by"');
        $this->assertNotFalse($modifiedByPos);
        $this->assertNotFalse(strpos($content, 'value="previous"', $modifiedByPos));
        $this->assertNotFalse(strpos($content, 'readonly', $modifiedByPos));

        // c_modified_date 应显示原始值（"2020-01-02 00:00:00"）而非当前日期，并且 readonly
        $modifiedDatePos = strpos($content, 'name="c_modified_date"');
        $this->assertNotFalse($modifiedDatePos);
        $this->assertNotFalse(strpos($content, 'value="2020-01-02 00:00:00"', $modifiedDatePos));
        $this->assertNotFalse(strpos($content, 'readonly', $modifiedDatePos));

        // 应该有提示文字说明提交后会被替换的值
        $response->assertSee('欄位內容提交後會被替換為：text-admin', false);
        // Use config timezone (consistent with write operations)
        $expectedTimestamp = Carbon::now()->timezone(config('app.timezone'))->format('Y-m-d H:i:s');
        $response->assertSee('欄位內容提交後會被替換為：'.$expectedTimestamp, false);

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

        $response = $this->put('/codes/TEST_CODES/A1_._X1', [
            'description' => 'Updated',
        ]);

        $response->assertRedirect(route('codes.edit', [
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
    public function testActiveUserDestroyLogsOperation() {
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

        $response = $this->delete('/codes/TEST_CODES/A1_._X1');

        $response->assertRedirect(route('codes.show', ['table_name' => 'TEST_CODES']));

        $this->assertCount(1, $this->operationSpy->calls);
        $call = $this->operationSpy->calls[0];
        $this->assertSame(4, $call['op_type']);
        $this->assertSame('TEST_CODES', $call['resource']);
        $this->assertSame('A1_._X1', $call['resource_id']);
        $this->assertSame('To delete', $call['resource_data']['description']);
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

        $response = $this->from('/codes/TEST_CODES/A1_._X1/edit')->put('/codes/TEST_CODES/A1_._X1', [
            'description' => 'Updated',
        ]);

        $response->assertRedirect('/codes/TEST_CODES/A1_._X1/edit');
        $response->assertSessionHasErrors(['duplicate']);
        $response->assertSessionHas('_old_input.description', 'Updated');
        $this->assertEmpty($this->operationSpy->calls);

        $this->fakeDb->clearFailures();
        $this->assertCount(1, $this->fakeDb->failuresCleared);
    }

    // ──────────────────────────────────────────────────────────────
    // Phase 2: filter / sort tests
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function testSortByValidColumnReturns200() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'B1', 'code_sub' => 'Y1', 'description' => 'Banana'],
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Apple'],
        ]);

        $response = $this->get('/codes/TEST_CODES?sort_by=description&sort_dir=asc');

        $response->assertStatus(200);
        $response->assertViewHas('sortBy', 'description');
        $response->assertViewHas('sortDir', 'asc');
        $response->assertSee('Apple');
        $response->assertSee('Banana');
    }

    #[Test]
    public function testSortByInvalidColumnIsIgnored() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha'],
        ]);

        $response = $this->get('/codes/TEST_CODES?sort_by=non_existent_column&sort_dir=asc');

        $response->assertStatus(200);
        $response->assertViewHas('sortBy', '');
    }

    #[Test]
    public function testSortDirInvalidDefaultsToAsc() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha'],
        ]);

        $response = $this->get('/codes/TEST_CODES?sort_by=description&sort_dir=INVALID');

        $response->assertStatus(200);
        $response->assertViewHas('sortDir', 'asc');
    }

    #[Test]
    public function testFilterByValidColumnReturnsMatchingRows() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
            ['code_id' => 'B1', 'code_sub' => 'Y1', 'description' => 'Beta entry'],
            ['code_id' => 'C1', 'code_sub' => 'Z1', 'description' => 'Gamma entry'],
        ]);

        $response = $this->get('/codes/TEST_CODES?filters[description]=Beta');

        $response->assertStatus(200);
        $response->assertSee('Beta entry');
        $response->assertDontSee('Alpha entry');
        $response->assertDontSee('Gamma entry');
        $response->assertViewHas('filters', ['description' => 'Beta']);
    }

    #[Test]
    public function testFilterByInvalidColumnIsIgnored() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha'],
        ]);

        $response = $this->get('/codes/TEST_CODES?filters[non_existent]=value');

        $response->assertStatus(200);
        $response->assertViewHas('filters', []);
    }

    #[Test]
    public function testFilterArrayAttackIsDiscarded() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha'],
        ]);

        $response = $this->get('/codes/TEST_CODES?filters[description][]=array_attack');

        $response->assertStatus(200);
        $response->assertViewHas('filters', []);
    }

    #[Test]
    public function testFilterAndSortTogether() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'C1', 'code_sub' => 'Z1', 'description' => 'Cherry'],
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Apple'],
            ['code_id' => 'B1', 'code_sub' => 'Y1', 'description' => 'Banana'],
        ]);

        $response = $this->get('/codes/TEST_CODES?filters[code_sub]=X1&sort_by=code_id&sort_dir=asc');

        $response->assertStatus(200);
        $response->assertSee('Apple');
        $response->assertDontSee('Banana');
        $response->assertDontSee('Cherry');
        $response->assertViewHas('sortBy', 'code_id');
        $response->assertViewHas('filters', ['code_sub' => 'X1']);
    }

    #[Test]
    public function testFilterEmptyValueIsIgnored() {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha'],
            ['code_id' => 'B1', 'code_sub' => 'Y1', 'description' => 'Beta'],
        ]);

        $response = $this->get('/codes/TEST_CODES?filters[description]=');

        $response->assertStatus(200);
        $response->assertSee('Alpha');
        $response->assertSee('Beta');
        $response->assertViewHas('filters', []);
    }

    #[Test]
    public function testViewReceivesFilterSortDirVariables() {
        $response = $this->get('/codes/TEST_CODES');

        $response->assertStatus(200);
        $response->assertViewHas('filters', []);
        $response->assertViewHas('sortBy', '');
        $response->assertViewHas('sortDir', 'asc');
    }

    // ── Phase 3：JOIN 表 resolveColumnForQuery 單元測試 ────────────────

    #[Test]
    public function testResolveColumnForQueryJoinAlias() {
        $joinConfig = [
            'base_table' => 'APPOINTMENT_CODE_TYPE_REL',
            'base_alias' => 'rel',
            'select' => [
                'rel.c_appt_code',
                'code.c_appt_desc_chn as appt_name',
                'rel.c_appt_type_code',
                'type.c_appt_type_desc_chn as appt_type_name',
            ],
        ];

        $controller = $this->app->make(\App\Http\Controllers\CodesController::class);
        $method = new \ReflectionMethod(\App\Http\Controllers\CodesController::class, 'resolveColumnForQuery');
        $method->setAccessible(true);

        // JOIN alias 應解析為 selectList 中的原始表達式
        $this->assertEquals('code.c_appt_desc_chn', $method->invoke($controller, 'appt_name', $joinConfig));
        $this->assertEquals('type.c_appt_type_desc_chn', $method->invoke($controller, 'appt_type_name', $joinConfig));
    }

    #[Test]
    public function testResolveColumnForQueryBaseTableColumn() {
        $joinConfig = [
            'base_table' => 'APPOINTMENT_CODE_TYPE_REL',
            'base_alias' => 'rel',
            'select' => [
                'rel.c_appt_code',
                'code.c_appt_desc_chn as appt_name',
                'rel.c_appt_type_code',
                'type.c_appt_type_desc_chn as appt_type_name',
            ],
        ];

        $controller = $this->app->make(\App\Http\Controllers\CodesController::class);
        $method = new \ReflectionMethod(\App\Http\Controllers\CodesController::class, 'resolveColumnForQuery');
        $method->setAccessible(true);

        // base table 真實欄位（FakeSchemaBuilder 回傳 c_appt_code, c_appt_type_code）
        $this->assertEquals('rel.c_appt_code', $method->invoke($controller, 'c_appt_code', $joinConfig));
        $this->assertEquals('rel.c_appt_type_code', $method->invoke($controller, 'c_appt_type_code', $joinConfig));
    }

    #[Test]
    public function testResolveColumnForQueryNonJoinTable() {
        $controller = $this->app->make(\App\Http\Controllers\CodesController::class);
        $method = new \ReflectionMethod(\App\Http\Controllers\CodesController::class, 'resolveColumnForQuery');
        $method->setAccessible(true);

        // 非 JOIN 表：直接回傳欄位名
        $this->assertEquals('c_name', $method->invoke($controller, 'c_name', null));
        $this->assertEquals('description', $method->invoke($controller, 'description', null));
    }

    #[Test]
    public function testResolveColumnForQueryUnresolvableReturnsNull() {
        $joinConfig = [
            'base_table' => 'APPOINTMENT_CODE_TYPE_REL',
            'base_alias' => 'rel',
            'select' => [
                'rel.c_appt_code',
                'code.c_appt_desc_chn as appt_name',
            ],
        ];

        $controller = $this->app->make(\App\Http\Controllers\CodesController::class);
        $method = new \ReflectionMethod(\App\Http\Controllers\CodesController::class, 'resolveColumnForQuery');
        $method->setAccessible(true);

        // 不在 selectList 也不在 base table schema → null
        $this->assertNull($method->invoke($controller, 'unknown_column', $joinConfig));
        // 已有 dot prefix → null（防禦性）
        $this->assertNull($method->invoke($controller, 'malicious.injection', $joinConfig));
    }

    // ── Phase 3：JOIN 表整合測試 ───────────────────────────────────────

    #[Test]
    public function testAppointmentCodeTypeRelSortAndFilterReturns200() {
        DB::table('APPOINTMENT_CODE_TYPE_REL')->insert([
            ['c_appt_code' => 'A1', 'c_appt_type_code' => 'T1'],
            ['c_appt_code' => 'B2', 'c_appt_type_code' => 'T2'],
        ]);

        // sort on JOIN alias column（由 getJoinedColumnNames 加入 $thead）
        $this->fakeDb->recordedOrderBys = [];
        $response = $this->get('/codes/APPOINTMENT_CODE_TYPE_REL?sort_by=appt_name&sort_dir=asc');
        $response->assertStatus(200);
        $response->assertViewHas('sortBy', 'appt_name');
        // resolveColumnForQuery must resolve JOIN alias → fully-qualified expression
        $this->assertContains(['code.c_appt_desc_chn', 'asc'], $this->fakeDb->recordedOrderBys);

        // filter on base table column
        $response2 = $this->get('/codes/APPOINTMENT_CODE_TYPE_REL?filters[c_appt_code]=A1');
        $response2->assertStatus(200);
        $response2->assertViewHas('filters', ['c_appt_code' => 'A1']);

        // 不在 $thead 白名單的欄位 → sanitizeSortParameters 清空 sortBy → 200，無例外
        $this->fakeDb->recordedOrderBys = [];
        $response3 = $this->get('/codes/APPOINTMENT_CODE_TYPE_REL?sort_by=non_existent_column');
        $response3->assertStatus(200);
        $response3->assertViewHas('sortBy', '');
        // no user-requested column should appear; PK tie-breakers are still recorded
        $this->assertNotContains('non_existent_column', array_column($this->fakeDb->recordedOrderBys, 0));
    }

    #[Test]
    public function testOfficeCodeTypeRelSortAndFilterReturns200() {
        DB::table('OFFICE_CODE_TYPE_REL')->insert([
            ['c_office_id' => 1, 'c_office_tree_id' => 10],
            ['c_office_id' => 2, 'c_office_tree_id' => 20],
        ]);

        // sort on JOIN alias column
        $this->fakeDb->recordedOrderBys = [];
        $response = $this->get('/codes/OFFICE_CODE_TYPE_REL?sort_by=office_name&sort_dir=desc');
        $response->assertStatus(200);
        $response->assertViewHas('sortBy', 'office_name');
        // resolveColumnForQuery must resolve JOIN alias → fully-qualified expression
        $this->assertContains(['code.c_office_chn', 'desc'], $this->fakeDb->recordedOrderBys);

        // filter on base table column
        $response2 = $this->get('/codes/OFFICE_CODE_TYPE_REL?filters[c_office_id]=1');
        $response2->assertStatus(200);
        $response2->assertViewHas('filters', ['c_office_id' => '1']);
    }
}

class FakeDatabaseManager {
    public $tables = [];
    public $failures = [];
    public $failuresCleared = [];
    public $schemaColumns = [];
    public array $recordedOrderBys = [];

    public function __construct(array $tables = [], array $schemaColumns = []) {
        $this->tables = $tables;
        $this->schemaColumns = $schemaColumns;
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
        return new FakeSchemaBuilder($this->schemaColumns);
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

    public function __construct(array $schemaColumns = []) {
        $this->schemaColumns = $schemaColumns;
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
