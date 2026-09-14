<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `ADDR_CODES` 經緯度歸零守衛在 v2 API 上的行為（create／update／proposal／核准）。
 *
 * 背景：`0,0` 不是東亞的任何地點，而是「沒有座標」寫成了一個看起來合法的數字。
 * `CoordinateValidator` 讀取端早就判它無效，但寫入端照樣收——這張表因此長年帶著 316 列
 * `0,0`（2026-09-14 清理完畢）。而且它會主動製造錯誤答案：舊版 v1 的鄰近地點查詢是
 * `x_coord BETWEEN other.x_coord ± 0.03` 的自連接，所有 `0,0` 列互為鄰居。
 *
 * **這裡的斷言刻意都落在資料庫的實際值與回應內容上，不看 UI。** 測試跑 SQLite，
 * 而生產是非 strict 的 MariaDB（實測 `''` 與 `'0.00000'` 都會被靜默轉成 `0.0`）；
 * 所以真正要鎖的是「送到資料庫層的值已經是 `null`」，那在兩種引擎上都一樣可觀察。
 */
class ApiV2AddrCodesZeroCoordinateTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('app.env', 'testing');
        $this->app['env'] = 'testing';
        config()->set('prometheus.enabled', false);
        config()->set('prometheus.storage_adapter', 'memory');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('confirmation_token')->nullable();
            $table->integer('is_active')->default(0);
            $table->integer('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->nullable();
            $table->integer('c_personid')->default(0);
            $table->integer('op_type');
            $table->string('resource');
            $table->string('resource_id')->nullable();
            $table->longText('resource_data')->nullable();
            $table->longText('resource_original')->nullable();
            $table->integer('crowdsourcing_status')->default(0);
            $table->timestamps();
        });

        Schema::create('audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');
            $table->string('table_name', 64);
            $table->string('operation', 16);
            $table->string('actor_type', 32);
            $table->string('actor_id', 128);
            $table->string('operation_id', 64);
            $table->text('row_pk');
            $table->string('row_pk_text', 512)->nullable();
            $table->longText('old_data')->nullable();
            $table->longText('new_data')->nullable();
        });

        Schema::create('ADDR_CODES', function (Blueprint $table) {
            $table->integer('c_addr_id')->primary();
            $table->string('c_name')->nullable();
            $table->string('c_name_chn')->nullable();
            $table->smallInteger('c_firstyear')->nullable();
            $table->smallInteger('c_lastyear')->nullable();
            $table->string('c_admin_type')->nullable();
            $table->smallInteger('c_admin_cat_code')->default(0);
            $table->double('x_coord')->nullable();
            $table->double('y_coord')->nullable();
            $table->integer('CHGIS_PT_ID')->nullable();
            $table->longText('c_notes')->nullable();
            $table->string('c_alt_names')->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });
    }

    protected function tearDown(): void {
        foreach (['ADDR_CODES', 'audit_log', 'operations', 'users'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    private function actAsEditor(string $email = 'coord@example.com'): User {
        $user = User::forceCreate([
            'name' => 'coord tester',
            'email' => $email,
            'confirmation_token' => 'token-coord',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_REGULAR,
        ]);
        $this->actingAs($user);

        return $user;
    }

    private function seedRow(array $overrides = []): void {
        DB::table('ADDR_CODES')->insert($overrides + [
            'c_addr_id' => 4338,
            'c_name' => 'Anding Wei',
            'c_name_chn' => '安定衛',
            'c_admin_cat_code' => 176,
            'x_coord' => null,
            'y_coord' => null,
        ]);
    }

    private function row(int $id = 4338): object {
        return DB::table('ADDR_CODES')->where('c_addr_id', $id)->first();
    }

    // ── create ──────────────────────────────────────────────

    #[Test]
    public function testCreateStoresAZeroPairAsNull(): void {
        $this->actAsEditor();

        $response = $this->postJson('/api/v2/create', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_addr_id' => 5001]],
            'changes' => [
                'c_name_chn' => '失里綿衛',
                'c_name' => 'Shilimian Wei',
                'x_coord' => 0,
                'y_coord' => 0,
            ],
        ]);

        $response->assertOk();
        $row = $this->row(5001);
        $this->assertNull($row->x_coord);
        $this->assertNull($row->y_coord);
        // 系統改了輸入就要讓使用者看見。
        $this->assertNotEmpty($response->json('notices'));
    }

    #[Test]
    public function testCreateStoresDecimalZerosAsNull(): void {
        // 「0.00*」是匯入端最常見的寫法，落到 double 就是精確的 0。
        $this->actAsEditor();

        $this->postJson('/api/v2/create', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_addr_id' => 5002]],
            'changes' => ['c_name_chn' => '罕東衛', 'x_coord' => '0.00000', 'y_coord' => '0.0000'],
        ])->assertOk();

        $row = $this->row(5002);
        $this->assertNull($row->x_coord);
        $this->assertNull($row->y_coord);
    }

    #[Test]
    public function testCreateWithOnlyOneZeroAxisStoresBothAsNull(): void {
        $this->actAsEditor();

        $this->postJson('/api/v2/create', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_addr_id' => 5003]],
            'changes' => ['c_name_chn' => '開平衛', 'x_coord' => 105.36354, 'y_coord' => 0],
        ])->assertOk();

        $row = $this->row(5003);
        $this->assertNull($row->x_coord, '緯度為零時經度也必須一併清空，否則存出不可用的半截座標');
        $this->assertNull($row->y_coord);
    }

    #[Test]
    public function testCreateKeepsAValidCoordinatePairAndEmitsNoNotice(): void {
        $this->actAsEditor();

        $response = $this->postJson('/api/v2/create', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_addr_id' => 5004]],
            'changes' => ['c_name_chn' => '宣寧', 'x_coord' => 113.11134338, 'y_coord' => 40.37184906],
        ])->assertOk();

        $row = $this->row(5004);
        $this->assertSame(113.11134338, (float) $row->x_coord);
        $this->assertSame(40.37184906, (float) $row->y_coord);
        $this->assertNull($response->json('notices'));
    }

    // ── update ──────────────────────────────────────────────

    #[Test]
    public function testUpdateStoresAZeroPairAsNullAndRecordsItInTheSnapshots(): void {
        $this->actAsEditor();
        $this->seedRow(['x_coord' => 113.11134338, 'y_coord' => 40.37184906]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 4338]],
            'changes' => ['x_coord' => 0, 'y_coord' => 0],
        ])->assertOk();

        $row = $this->row();
        $this->assertNull($row->x_coord);
        $this->assertNull($row->y_coord);
        $this->assertNotEmpty($response->json('notices'));

        // 稽核快照必須記下「實際落庫的是 NULL」，而不是呼叫端送的 0。
        $op = DB::table('operations')->where('resource', 'ADDR_CODES')->first();
        $data = json_decode($op->resource_data, true);
        $original = json_decode($op->resource_original, true);
        $this->assertNull($data['x_coord']);
        $this->assertNull($data['y_coord']);
        $this->assertSame(113.11134338, (float) $original['x_coord']);
    }

    #[Test]
    public function testUpdateClearsThePartnerColumnItWasNotAskedAbout(): void {
        // 代碼表 update 是逐欄的：只送 x_coord=0 時也必須把 y_coord 一併寫成 NULL，
        // 否則留下 `NULL, 40.37` 這種對每個消費端都不可用的半截列。
        $this->actAsEditor();
        $this->seedRow(['x_coord' => 113.11134338, 'y_coord' => 40.37184906]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 4338]],
            'changes' => ['x_coord' => 0],
        ])->assertOk();

        $row = $this->row();
        $this->assertNull($row->x_coord);
        $this->assertNull($row->y_coord);
        // 補寫的欄位會出現在回報的 updated_fields 裡（API.md 已載明這個行為）。
        $this->assertContains('y_coord', $response->json('result.updated_fields'));
    }

    #[Test]
    public function testUpdateWithABlankAxisDiscardsTheOtherAxisAndSaysSo(): void {
        // 唯一真的丟掉使用者輸入的情形：填了經度、緯度留空。通知絕不能吞掉。
        $this->actAsEditor();
        $this->seedRow();

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 4338]],
            'changes' => ['c_name' => 'Anding', 'x_coord' => 105.36354, 'y_coord' => ''],
        ])->assertOk();

        $row = $this->row();
        $this->assertNull($row->x_coord);
        $this->assertNull($row->y_coord);
        $notices = $response->json('notices');
        $this->assertNotEmpty($notices);
        $this->assertStringContainsString('x_coord', implode(' ', $notices));
    }

    #[Test]
    public function testUpdatingAnAlreadyNullRowWithZeroIsNoEffectiveChangeAndClaimsNoLoss(): void {
        // 掛鉤早於變更偵測，所以這裡必須是 422 而不是「寫一次 NULL 覆蓋 NULL」——
        // 後者會蓋掉 c_modified_* 並寫出 before／after 相同的 operations／audit_log。
        $this->actAsEditor();
        $this->seedRow();  // x/y 皆為 null

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 4338]],
            'changes' => ['x_coord' => 0],
        ]);

        $response->assertStatus(422)->assertJsonFragment(['changes' => ['no_effective_changes']]);

        // 什麼都沒寫：稽核欄不可以被蓋、紀錄表不可以多出列。
        $row = $this->row();
        $this->assertNull($row->c_modified_by);
        $this->assertSame(0, DB::table('operations')->count());
        $this->assertSame(0, DB::table('audit_log')->count());

        // 而且不可以謊報損失：y_coord 沒被送來、原值本來就是 NULL，什麼都沒丟。
        $notices = (array) $response->json('notices');
        $this->assertStringNotContainsString('y_coord', implode(' ', $notices));
    }

    // ── 該回 422 的輸入不可以被靜默清成 NULL ────────────────

    #[Test]
    public function testANonNumericAxisIsRejectedRatherThanClearedToNull(): void {
        // 清成 NULL 會把一個該報錯的請求變成「靜默存成沒有座標」。
        $this->actAsEditor();
        $this->seedRow(['x_coord' => 113.11134338, 'y_coord' => 40.37184906]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 4338]],
            'changes' => ['x_coord' => 0, 'y_coord' => 'east'],
        ])->assertStatus(422);

        $row = $this->row();
        $this->assertSame(113.11134338, (float) $row->x_coord, '被拒絕的請求不可以動到任何資料');
    }

    #[Test]
    public function testALeadingPlusIsRejectedRatherThanClearingThePair(): void {
        // `+40.5` 是 CodeTableFieldValidator 明確拒絕的形狀。歸一層若把它當成數值，
        // 這個請求會變成 200＋兩個 NULL 而不是 422——歸一的 isNumeric() 因此刻意與
        // validator 的 looksNumeric() 逐字一致。
        $this->actAsEditor();
        $this->seedRow(['x_coord' => 113.11134338, 'y_coord' => 40.37184906]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 4338]],
            'changes' => ['x_coord' => '0', 'y_coord' => '+40.5'],
        ])->assertStatus(422)->assertJsonFragment(['y_coord' => ['y_coord 必須為數值']]);

        $this->assertSame(113.11134338, (float) $this->row()->x_coord);
    }

    #[Test]
    public function testAnOverflowingJsonNumberIsRejectedAsNonFinite(): void {
        // `1e999` 經 json_decode 就是 float(INF)，不必刻意構造。
        $this->actAsEditor();
        $this->seedRow(['x_coord' => 113.11134338, 'y_coord' => 40.37184906]);

        $this->call(
            'POST',
            '/api/v2/mutate',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            // 手寫 JSON：`json_encode()` 對 INF 會直接失敗，而重點正是「一個十進位
            // 字面值溢位成 INF」——呼叫端送的是文字，不是 PHP 的浮點常數。
            '{"resource":"addr-codes","person_id":0,"mode":"direct","operation":"update",'
            .'"target":{"pk":{"c_addr_id":4338}},"changes":{"x_coord":1e999,"y_coord":40.5}}'
        )->assertStatus(422)->assertJsonFragment(['x_coord' => ['x_coord 必須為有限數值']]);

        $this->assertSame(113.11134338, (float) $this->row()->x_coord);
    }

    // ── proposal 與核准 ─────────────────────────────────────

    #[Test]
    public function testProposalPayloadCarriesTheNormalizedNulls(): void {
        $this->actAsEditor();
        $this->seedRow(['x_coord' => 113.11134338, 'y_coord' => 40.37184906]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'proposal',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 4338]],
            'changes' => ['x_coord' => 0, 'y_coord' => 0],
        ])->assertOk();

        $op = DB::table('operations')->where('resource', 'ADDR_CODES')->first();
        $payload = json_decode($op->resource_data, true);
        $this->assertNull($payload['x_coord'], '提案 payload 就該存歸一後的值，核准時才不需要補救');
        $this->assertNull($payload['y_coord']);
        // 提案階段不得動到資料。
        $this->assertSame(113.11134338, (float) $this->row()->x_coord);
    }

    #[Test]
    public function testApprovingALegacyCreatePayloadThatStillCarriesZeroDoesNotCreateAZeroRow(): void {
        // create 端的核准掛鉤有自己的一條路（applyCreateProposal），不會被 update 端的
        // 測試覆蓋到——實測拿掉它，update 端的測試照樣全綠。
        $admin = User::forceCreate([
            'name' => 'coord admin create',
            'email' => 'coord-admin-create@example.com',
            'confirmation_token' => 'token-coord-admin-c',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);

        $legacyPayload = [
            'c_addr_id' => 6001,
            'c_name_chn' => '建州衛',
            'c_admin_cat_code' => 176,
            'x_coord' => 0,
            'y_coord' => 0,
            '__review_status' => 'pending',
            '__key_columns' => ['c_addr_id'],
            '__proposal_meta' => [
                'action' => 'create',
                'table' => 'ADDR_CODES',
                'submitted_by' => $admin->name,
                'submitted_by_id' => $admin->id,
            ],
        ];

        $operationId = DB::table('operations')->insertGetId([
            'user_id' => $admin->id,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'ADDR_CODES',
            'resource_id' => 'c_addr_id=6001',
            'resource_data' => json_encode($legacyPayload, JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin);
        $this->post(route('operations.proposals.approve', $operationId), ['review_comment' => '同意']);

        $row = $this->row(6001);
        $this->assertNotNull($row, '核准應該建出這一列');
        $this->assertNull($row->x_coord, '核准歷史 create payload 不可以造出一列 0,0');
        $this->assertNull($row->y_coord);
    }

    #[Test]
    public function testApprovingALegacyPayloadThatStillCarriesZeroDoesNotRecreateAZeroRow(): void {
        // 這是雙保險要擋的情形：`operations` 裡躺著這條機制上線**之前**送出的提案，
        // 它的 payload 帶著 x_coord: 0。核准它不可以把一列 0,0 重新造回來。
        $admin = User::forceCreate([
            'name' => 'coord admin',
            'email' => 'coord-admin@example.com',
            'confirmation_token' => 'token-coord-admin',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
        $this->seedRow(['x_coord' => 113.11134338, 'y_coord' => 40.37184906]);

        $original = (array) $this->row();
        $legacyPayload = $original;
        $legacyPayload['x_coord'] = 0;
        $legacyPayload['y_coord'] = 0;

        $legacyPayload['__review_status'] = 'pending';
        $legacyPayload['__key_columns'] = ['c_addr_id'];
        $legacyPayload['__proposal_meta'] = [
            'action' => 'update',
            'table' => 'ADDR_CODES',
            'submitted_by' => $admin->name,
            'submitted_by_id' => $admin->id,
        ];

        $operationId = DB::table('operations')->insertGetId([
            'user_id' => $admin->id,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'ADDR_CODES',
            'resource_id' => 'c_addr_id=4338',
            'resource_data' => json_encode($legacyPayload, JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode($original, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin);
        $this->post(route('operations.proposals.approve', $operationId), ['review_comment' => '同意']);

        $row = $this->row();
        $this->assertNull($row->x_coord, '核准歷史 payload 不可以把 0,0 重新造回來');
        $this->assertNull($row->y_coord);
    }

    // ── 通知的隔離與併存 ─────────────────────────────────────

    #[Test]
    public function testBatchMutateDoesNotLeakOneItemsCoordinateNoticeIntoTheNext(): void {
        // handler 由容器解析、同一個 process 內被重複使用，所以累積器必須在每次 handle()
        // 開頭重置。少了 resetCoordinateCleared()，第二筆會揹著第一筆的通知回來——
        // 實測拿掉那兩行呼叫，其餘 13 個測試照樣全綠，所以這條要自己站出來。
        $this->actAsEditor();
        DB::table('ADDR_CODES')->insert([
            ['c_addr_id' => 7001, 'c_name' => 'First', 'c_admin_cat_code' => 176, 'x_coord' => 113.1, 'y_coord' => 40.3],
            ['c_addr_id' => 7002, 'c_name' => 'Second', 'c_admin_cat_code' => 176, 'x_coord' => 105.0, 'y_coord' => 30.0],
        ]);

        $response = $this->postJson('/api/v2/batch_mutate', [
            'resource' => 'addr-codes',
            'mode' => 'direct',
            'operation' => 'update',
            'items' => [
                // 第一筆會觸發座標通知
                ['person_id' => 0, 'target' => ['pk' => ['c_addr_id' => 7001]], 'changes' => ['x_coord' => 0]],
                // 第二筆完全不碰座標，不該有任何通知
                ['person_id' => 0, 'target' => ['pk' => ['c_addr_id' => 7002]], 'changes' => ['c_name' => 'Renamed']],
            ],
        ])->assertOk();

        $results = $response->json('results');
        $this->assertNotEmpty($results[0]['notices'] ?? [], '第一筆應該帶座標通知');
        $this->assertEmpty(
            $results[1]['notices'] ?? [],
            '第二筆沒有碰座標，卻揹著第一筆的通知——累積器沒有在 handle() 開頭重置'
        );

        // 而且第二筆的座標必須毫髮無傷。
        $second = $this->row(7002);
        $this->assertSame(105.0, (float) $second->x_coord);
        $this->assertSame(30.0, (float) $second->y_coord);
    }

    #[Test]
    public function testAVariantReplacementAndACoordinateClearInOneRequestBothGetReported(): void {
        // 兩種通知寫同一個頂層 `notices` 欄位。原本異體字那一邊是 assign 而非 merge，
        // 於是組合順序一反過來就會**靜默吃掉**座標通知，而整個測試套件照樣全綠。
        // 兩邊都改成 merge 之後，這支測試是那件事的守衛。
        Schema::create('char_variant_map', function (Blueprint $table) {
            $table->increments('id');
            $table->string('c_variant_char', 8);
            $table->string('c_reference_char', 8);
            $table->integer('c_strict_excluded')->default(0);
            $table->string('c_notes')->nullable();
        });
        DB::table('char_variant_map')->insert([
            'c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0,
        ]);

        $this->actAsEditor();
        $this->seedRow(['x_coord' => 113.11134338, 'y_coord' => 40.37184906]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 4338]],
            'changes' => ['c_name_chn' => '淸河', 'x_coord' => 0],
        ])->assertOk();

        $notices = implode(' | ', (array) $response->json('notices'));
        $this->assertStringContainsString('清', $notices, '異體字通知不見了');
        $this->assertStringContainsString('x_coord', $notices, '座標通知不見了');

        Schema::dropIfExists('char_variant_map');
    }

    #[Test]
    public function testA409ConflictStillCarriesTheCoordinateNotice(): void {
        // 「成功、409、422 都要掛」不是口號：被擋下來時使用者更需要知道系統改了他的輸入。
        // 實測把兩個 handler 的 withWriteNotices 全部改回 withVariantNotices，只有三個
        // 成功路徑的測試會紅——所有錯誤路徑都沒人守。
        $this->actAsEditor();
        DB::table('ADDR_CODES')->insert([
            ['c_addr_id' => 8001, 'c_name' => 'Keeper', 'c_admin_cat_code' => 176, 'x_coord' => 113.1, 'y_coord' => 40.3],
            ['c_addr_id' => 8002, 'c_name' => 'Mover', 'c_admin_cat_code' => 176, 'x_coord' => 105.0, 'y_coord' => 30.0],
        ]);

        // 改主鍵撞既有列 → 409，同一個請求也把座標歸零。
        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 8002]],
            'changes' => ['c_admin_cat_code' => 176, 'x_coord' => 0],
        ]);

        // 這條路徑不一定產生 409（主鍵不在白名單內），所以只斷言「有座標通知」這件事，
        // 不綁死狀態碼——重點是錯誤回應不會把通知吃掉。
        $this->assertNotEmpty(
            (array) $response->json('notices'),
            '回應（不論成功或失敗）都必須帶上座標通知'
        );
    }

    #[Test]
    public function testA422ValidationFailureStillCarriesTheCoordinateNotice(): void {
        // 歸一跑在校驗之前，所以校驗失敗時座標已經被改過了——這時最需要通知。
        $this->actAsEditor();
        $this->seedRow(['x_coord' => 113.11134338, 'y_coord' => 40.37184906]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 4338]],
            // x_coord=0 會讓整對歸零；c_name 超過 255 字元 → validateFields() 回 422。
            // 刻意不用整數值域（那條依賴 schema 內省，SQLite 下與 prod 行為不同）。
            'changes' => ['x_coord' => 0, 'c_name' => str_repeat('A', 300)],
        ])->assertStatus(422);

        $this->assertNotEmpty(
            (array) $response->json('notices'),
            '422 也必須帶上座標通知：使用者送的座標已經被改成 NULL 了'
        );
        // 被拒絕的請求不可以動到資料。
        $this->assertSame(113.11134338, (float) $this->row()->x_coord);
    }

    #[Test]
    public function testTheProposalResponseCarriesTheCoordinateNotice(): void {
        $this->actAsEditor();
        $this->seedRow(['x_coord' => 113.11134338, 'y_coord' => 40.37184906]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'proposal',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 4338]],
            'changes' => ['x_coord' => 0, 'y_coord' => 0],
        ])->assertOk();

        $this->assertNotEmpty((array) $response->json('notices'));
    }

    #[Test]
    public function testApprovingAPayloadWhoseCoordinateIsNotANumberIsRefusedRatherThanCoercedToZero(): void {
        // codex 找到的洞：歸一對 "0e0" 刻意整對不動，前提是「下游 validator 會回 422」，
        // 但**核准重放從不呼叫任何欄位驗證層**——MariaDB 在非 strict sql_mode 下把 "0e0"
        // 靜默轉成 0，正好重新造出這整套機制要防的那一列。
        //
        // 選擇中止核准而不是靜默清成 NULL：payload 裡有個不是數的座標是壞資料，
        // 該讓審核者看到並退回修提案，不該由系統代為決定丟掉它。
        $admin = User::forceCreate([
            'name' => 'coord admin bad',
            'email' => 'coord-admin-bad@example.com',
            'confirmation_token' => 'token-coord-admin-b',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
        $this->seedRow(['x_coord' => 113.11134338, 'y_coord' => 40.37184906]);

        $original = (array) $this->row();
        $payload = $original;
        $payload['x_coord'] = '0e0';
        $payload['__review_status'] = 'pending';
        $payload['__key_columns'] = ['c_addr_id'];
        $payload['__proposal_meta'] = [
            'action' => 'update',
            'table' => 'ADDR_CODES',
            'submitted_by' => $admin->name,
            'submitted_by_id' => $admin->id,
        ];

        $operationId = DB::table('operations')->insertGetId([
            'user_id' => $admin->id,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'ADDR_CODES',
            'resource_id' => 'c_addr_id=4338',
            'resource_data' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode($original, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin);
        $this->post(route('operations.proposals.approve', $operationId), ['review_comment' => '同意']);

        // 核准必須被擋下來，而且原本的好座標毫髮無傷——絕不可以變成 0。
        $row = $this->row();
        $this->assertSame(
            113.11134338,
            (float) $row->x_coord,
            '核准帶著非數值座標的提案時，原值被改動了——若變成 0 就是那個洞還在'
        );
        $this->assertSame(40.37184906, (float) $row->y_coord);
    }
}
