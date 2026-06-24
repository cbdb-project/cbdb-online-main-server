<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2MutateAddressTest extends TestCase {
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

        $this->createUsersTable();
        $this->createSanctumTables();
        $this->createOperationsTable();
        $this->createAuditLogTable();
        $this->createAddressTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('BIOG_ADDR_DATA');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    // ── Table Setup ─────────────────────────────────────────

    protected function createUsersTable(): void {
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
    }

    protected function createSanctumTables(): void {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    protected function createOperationsTable(): void {
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
    }

    protected function createAuditLogTable(): void {
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
    }

    protected function createAddressTable(): void {
        Schema::create('BIOG_ADDR_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_addr_id')->default(0);
            $table->integer('c_addr_type')->default(0);
            $table->integer('c_sequence')->default(0);
            $table->integer('c_firstyear')->nullable();
            $table->integer('c_lastyear')->nullable();
            $table->text('c_notes')->nullable();
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->integer('c_natal')->nullable();
            $table->integer('c_fy_nh_code')->nullable();
            $table->integer('c_fy_nh_year')->nullable();
            $table->integer('c_fy_range')->nullable();
            $table->integer('c_fy_intercalary')->default(0);
            $table->integer('c_fy_month')->nullable();
            $table->integer('c_fy_day')->nullable();
            $table->integer('c_fy_day_gz')->nullable();
            $table->integer('c_ly_nh_code')->nullable();
            $table->integer('c_ly_nh_year')->nullable();
            $table->integer('c_ly_range')->nullable();
            $table->integer('c_ly_intercalary')->default(0);
            $table->integer('c_ly_month')->nullable();
            $table->integer('c_ly_day')->nullable();
            $table->integer('c_ly_day_gz')->nullable();
            $table->string('c_created_by', 255)->nullable();
            $table->string('c_created_date', 255)->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->string('c_modified_date', 255)->nullable();
            $table->primary(['c_personid', 'c_addr_id', 'c_addr_type', 'c_sequence']);
        });
    }

    // ── Helpers ──────────────────────────────────────────────

    protected function seedAddress(array $overrides = []): void {
        DB::table('BIOG_ADDR_DATA')->insert(array_replace([
            'c_personid' => 1000,
            'c_addr_id' => 100,
            'c_addr_type' => 1,
            'c_sequence' => 1,
            'c_firstyear' => 1050,
            'c_lastyear' => 1100,
            'c_notes' => null,
            'c_source' => 10,
        ], $overrides));
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'addr-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function addressPayload(array $overrides = []): array {
        $payload = [
            'resource' => 'addresses',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_addr_id' => 100,
                    'c_addr_type' => 1,
                    'c_sequence' => 1,
                ],
            ],
            'changes' => [
                'c_firstyear' => 1060,
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    // ── Direct Update Tests ─────────────────────────────────

    #[Test]
    public function testDirectAddressUpdateSucceeds(): void {
        $user = $this->makeUser(email: 'addr-direct@example.com');
        $this->actingAs($user);
        $this->seedAddress();

        $response = $this->postJson('/api/v2/mutate', $this->addressPayload([
            'changes' => ['c_firstyear' => 1060, 'c_notes' => '測試備註'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'addresses',
                'mode' => 'direct',
                'operation' => 'update',
                'result' => [
                    'pk' => [
                        'c_personid' => 1000,
                        'c_addr_id' => 100,
                        'c_addr_type' => 1,
                        'c_sequence' => 1,
                    ],
                    'updated_fields' => ['c_firstyear', 'c_notes'],
                ],
            ]);

        $this->assertDatabaseHas('BIOG_ADDR_DATA', [
            'c_personid' => 1000,
            'c_firstyear' => 1060,
            'c_notes' => '測試備註',
        ]);
    }

    #[Test]
    public function testDirectAddressUpdatePersistsNatalAndDoesNotNullOthers(): void {
        // 回歸（Task 27）：補欄 c_natal（是否本貫）須能寫入；且只改單一欄位時，未送出的
        // c_natal / c_firstyear 不可被清成 null —— 防護「保存即清空」資料流失 bug。
        $user = $this->makeUser(email: 'addr-natal@example.com');
        $this->actingAs($user);
        // 注意：addressPayload 預設 changes 含 c_firstyear=1060（會被 array_replace_recursive 併入），
        // 故 seed 與斷言一律用 1060；本測試重點是 c_natal 的寫入與「不送即不被清空」。
        $this->seedAddress(['c_natal' => 1, 'c_firstyear' => 1060]);

        // (a) 直接更新 c_natal 應成功寫入。
        $this->postJson('/api/v2/mutate', $this->addressPayload([
            'changes' => ['c_natal' => 0],
        ]))->assertOk();
        $this->assertDatabaseHas('BIOG_ADDR_DATA', [
            'c_personid' => 1000, 'c_addr_id' => 100, 'c_addr_type' => 1, 'c_sequence' => 1,
            'c_natal' => 0, 'c_firstyear' => 1060,
        ]);

        // (b) changes 不含 c_natal（僅改 c_notes）後，c_natal 仍保留、未被清空。
        $this->postJson('/api/v2/mutate', $this->addressPayload([
            'changes' => ['c_notes' => '只改備註'],
        ]))->assertOk();
        $this->assertDatabaseHas('BIOG_ADDR_DATA', [
            'c_personid' => 1000, 'c_addr_id' => 100, 'c_addr_type' => 1, 'c_sequence' => 1,
            'c_notes' => '只改備註', 'c_natal' => 0, 'c_firstyear' => 1060,
        ]);
    }

    #[Test]
    public function testDirectAddressUpdatePersistsLunarFields(): void {
        // 回歸（人物編輯重做）：地址編輯器 EraTimeField showLunar 會送出農曆月/日/干支
        // （c_fy_month/c_fy_day/c_fy_day_gz、c_ly_*）；這些欄位須在 allowlist 內，否則整筆保存 422。
        $user = $this->makeUser(email: 'addr-lunar@example.com');
        $this->actingAs($user);
        $this->seedAddress();

        $this->postJson('/api/v2/mutate', $this->addressPayload([
            'changes' => [
                'c_fy_month' => 3, 'c_fy_day' => 15, 'c_fy_day_gz' => 12,
                'c_ly_month' => 8, 'c_ly_day' => 20, 'c_ly_day_gz' => 30,
            ],
        ]))->assertOk();

        $this->assertDatabaseHas('BIOG_ADDR_DATA', [
            'c_personid' => 1000, 'c_addr_id' => 100, 'c_addr_type' => 1, 'c_sequence' => 1,
            'c_fy_month' => 3, 'c_fy_day' => 15, 'c_fy_day_gz' => 12,
            'c_ly_month' => 8, 'c_ly_day' => 20, 'c_ly_day_gz' => 30,
        ]);
    }

    #[Test]
    public function testDirectAddressUpdateClearingSourceNormalizesToSentinelZero(): void {
        // 回歸（人物編輯重做）：清空出處（c_source）時前端送 null，後端須對齊 legacy emptyToSentinel
        // 正規化為 0（Unknown），不可寫成 NULL（real schema c_source NOT NULL default 0）。
        $user = $this->makeUser(email: 'addr-clearsource@example.com');
        $this->actingAs($user);
        $this->seedAddress(['c_source' => 10]);

        $this->postJson('/api/v2/mutate', $this->addressPayload([
            'changes' => ['c_source' => null],
        ]))->assertOk();

        $this->assertDatabaseHas('BIOG_ADDR_DATA', [
            'c_personid' => 1000, 'c_addr_id' => 100, 'c_addr_type' => 1, 'c_sequence' => 1,
            'c_source' => 0,
        ]);
    }

    #[Test]
    public function testDirectAddressUpdateReturnsOperationIdAndRow(): void {
        $user = $this->makeUser(email: 'addr-result@example.com');
        $this->actingAs($user);
        $this->seedAddress();

        $response = $this->postJson('/api/v2/mutate', $this->addressPayload());
        $data = $response->json();

        $this->assertNotNull($data['result']['operation_id']);
        $this->assertNotNull($data['result']['row']);
        $this->assertEquals(1060, $data['result']['row']['c_firstyear']);
    }

    #[Test]
    public function testDirectAddressUpdateWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'addr-op@example.com');
        $this->actingAs($user);
        $this->seedAddress();

        $this->postJson('/api/v2/mutate', $this->addressPayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'BIOG_ADDR_DATA',
            'op_type' => Operation::TYPE_UPDATE,
        ]);
    }

    #[Test]
    public function testDirectAddressUpdateWritesAuditLog(): void {
        $user = $this->makeUser(email: 'addr-audit@example.com');
        $this->actingAs($user);
        $this->seedAddress();

        $this->postJson('/api/v2/mutate', $this->addressPayload());

        $audit = DB::table('audit_log')->where('table_name', 'BIOG_ADDR_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('UPDATE', $audit->operation);
    }

    // ── Proposal Update Tests ───────────────────────────────

    #[Test]
    public function testProposalAddressUpdateCreatesPendingOperation(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'addr-proposal@example.com');
        $this->actingAs($user);
        $this->seedAddress();

        $response = $this->postJson('/api/v2/mutate', $this->addressPayload([
            'mode' => 'proposal',
            'changes' => ['c_firstyear' => 1060],
            'meta' => ['comment' => '提案修改年份'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'addresses',
                'mode' => 'proposal',
                'operation' => 'update',
                'result' => [
                    'updated_fields' => ['c_firstyear'],
                    'status' => 'proposal_updated',
                ],
            ]);

        // 原始資料未被修改
        $this->assertDatabaseHas('BIOG_ADDR_DATA', [
            'c_personid' => 1000,
            'c_firstyear' => 1050,
        ]);

        // 已寫入提案操作紀錄
        $this->assertDatabaseHas('operations', [
            'resource' => 'BIOG_ADDR_DATA',
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
        ]);

        $operation = DB::table('operations')->where('resource', 'BIOG_ADDR_DATA')->first();
        $resourceData = json_decode($operation->resource_data, true);

        $this->assertSame('pending', $resourceData['__review_status']);
        $this->assertSame('update', $resourceData['__proposal_meta']['action']);
        $this->assertSame('addresses', $resourceData['__proposal_meta']['resource_type']);
        $this->assertSame('提案修改年份', $resourceData['__proposal_meta']['comment']);
        $this->assertEquals(1060, $resourceData['c_firstyear']);

        // 無 audit_log
        $this->assertDatabaseMissing('audit_log', ['table_name' => 'BIOG_ADDR_DATA']);
    }

    // ── Error Cases ─────────────────────────────────────────

    #[Test]
    public function testAddressUpdateWithInvalidPkReturns404(): void {
        $user = $this->makeUser(email: 'addr-404@example.com');
        $this->actingAs($user);
        $this->seedAddress();

        $response = $this->postJson('/api/v2/mutate', $this->addressPayload([
            'target' => ['pk' => [
                'c_personid' => 1000,
                'c_addr_id' => 999,
                'c_addr_type' => 1,
                'c_sequence' => 1,
            ]],
        ]));

        $response->assertStatus(404);
    }

    #[Test]
    public function testAddressUpdateWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'addr-mismatch@example.com');
        $this->actingAs($user);
        $this->seedAddress();

        $response = $this->postJson('/api/v2/mutate', $this->addressPayload([
            'person_id' => 9999,
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    #[Test]
    public function testAddressUpdateWithEmptyChangesReturns422(): void {
        $user = $this->makeUser(email: 'addr-empty@example.com');
        $this->actingAs($user);
        $this->seedAddress();

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'addresses',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_addr_id' => 100,
                    'c_addr_type' => 1,
                    'c_sequence' => 1,
                ],
            ],
            'changes' => [],
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function testAddressUpdateWithDisallowedFieldReturns422(): void {
        $user = $this->makeUser(email: 'addr-disallowed@example.com');
        $this->actingAs($user);
        $this->seedAddress();

        $response = $this->postJson('/api/v2/mutate', $this->addressPayload([
            'changes' => ['c_personid' => 9999],
        ]));

        $response->assertStatus(422);
        $this->assertStringContainsString('不允許', $response->json('message'));
    }

    #[Test]
    public function testAddressUpdateWithNoEffectiveChangeReturns422(): void {
        $user = $this->makeUser(email: 'addr-nochange@example.com');
        $this->actingAs($user);
        $this->seedAddress();

        $response = $this->postJson('/api/v2/mutate', $this->addressPayload([
            'changes' => ['c_firstyear' => 1050],
        ]));

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'errors' => ['changes' => ['no_effective_changes']],
            ]);
    }

    #[Test]
    public function testAddressUpdateRejectsUnauthenticatedUser(): void {
        $this->seedAddress();
        $response = $this->postJson('/api/v2/mutate', $this->addressPayload());
        $response->assertStatus(401);
    }

    #[Test]
    public function testAddressUpdateRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'addr-inactive@example.com');
        $this->actingAs($user);
        $this->seedAddress();

        $response = $this->postJson('/api/v2/mutate', $this->addressPayload());
        $response->assertStatus(403);
    }

    #[Test]
    public function testAddressDirectUpdateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'addr-crowd@example.com');
        $this->actingAs($user);
        $this->seedAddress();

        $response = $this->postJson('/api/v2/mutate', $this->addressPayload());
        $response->assertStatus(403);
    }

    // ── #56 M 寫入等價（update 路徑，純單表；addresses 無鏡像/副表）──────

    #[Test]
    #[Group('legacy-parity')] // 旧版下线時連同 legacy update 路徑一併移除
    public function testAddressUpdateWriteEquivalenceLegacyVsV2(): void {
        // #56 M（update，純單表，4 段 PK）——復原-重做實驗：改 c_firstyear+c_notes。legacy body 僅送 v2 會改的兩欄；
        // PK 定位走 query，但 controller updateQuery 的 $request->merge(emptyToSentinel) 會把 PK 三欄注入 body 後一併
        // 寫回（值不變，故不漂移；$cols 含 4 段 PK 可抓漂移）。
        //
        // 已知 legacy↔v2 行為分歧（顯式化，非 v2 bug；與 possession c_measure_code 同類）：legacy addrUpdateById
        // 永遠 (int) 寫 c_fy_intercalary/c_ly_intercalary（?? 0），即未送也清成 0（旧版資料流失）；v2 為 partial update，
        // 未送即保留 seed。v2 保留才正確。故 seed 此二欄為 1，把它們移出等價 $cols、改以獨立斷言鎖住預期差異
        // （legacy=0 清掉 / v2=1 保留），避免 seed=0 遮掩此分歧（codex 回饋）。
        $this->actingAs($this->makeUser(email: 'addr-mupd@example.com'));

        $pk = ['c_personid' => 1000, 'c_addr_id' => 100, 'c_addr_type' => 1, 'c_sequence' => 1];
        $seedInitial = function (): void {
            DB::table('BIOG_ADDR_DATA')->delete();
            $this->seedAddress([
                'c_firstyear' => 1050, 'c_lastyear' => 1100, 'c_notes' => '初始備註', 'c_source' => 10,
                'c_fy_intercalary' => 1, 'c_ly_intercalary' => 1,
            ]);
        };
        // 「應等價」欄（排除稽核欄與上述已知分歧的兩個 intercalary 欄）。
        $cols = ['c_personid', 'c_addr_id', 'c_addr_type', 'c_sequence', 'c_firstyear', 'c_lastyear', 'c_notes', 'c_source'];
        $pick = function ($row) use ($cols): ?array {
            if (!$row) {
                return null;
            }
            $a = array_intersect_key((array) $row, array_flip($cols));
            ksort($a);

            return $a;
        };

        // ① 旧版 PUT（PK 走 query；body 僅送 v2 會改的兩欄）。
        $seedInitial();
        $this->put('/basicinformation/1000/addresses/update?' . http_build_query($pk), [
            'c_firstyear' => 1060, 'c_notes' => '改後備註', 'action' => 'save',
        ])->assertStatus(302);
        $legacyRow = (array) DB::table('BIOG_ADDR_DATA')->where($pk)->first();
        $legacy = $pick($legacyRow);

        // ② 復原初始 → ③ 新版改同一筆。
        $seedInitial();
        $this->postJson('/api/v2/mutate', $this->addressPayload([
            'changes' => ['c_firstyear' => 1060, 'c_notes' => '改後備註'],
        ]))->assertOk()->assertJson(['ok' => true]);
        $v2Row = (array) DB::table('BIOG_ADDR_DATA')->where($pk)->first();
        $v2 = $pick($v2Row);

        $this->assertNotEmpty($legacyRow, 'legacy 更新後列不存在');
        $this->assertNotEmpty($v2Row, 'v2 更新後列不存在');
        $this->assertSame('改後備註', $v2['c_notes'], 'v2 c_notes 應更新');
        $this->assertSame('改後備註', $legacy['c_notes'], 'legacy c_notes 應更新（鎖 legacy 確有寫入）');
        $this->assertSame(1060, (int) $v2['c_firstyear'], 'v2 c_firstyear 應更新為 1060');
        $this->assertSame(1100, (int) $v2['c_lastyear'], 'v2 未送 c_lastyear 應保留=1100（partial update）');
        $this->assertSame($legacy, $v2, 'BIOG_ADDR_DATA 應等價欄 legacy vs v2 不等價');

        // 已知分歧（顯式化，非 bug）：未送的 intercalary 欄——legacy 永遠清 0（資料流失）、v2 partial 保留 seed=1。
        $this->assertSame(0, (int) $legacyRow['c_fy_intercalary'], 'legacy 應把未送的 c_fy_intercalary 清成 0（旧版資料流失行為）');
        $this->assertSame(0, (int) $legacyRow['c_ly_intercalary'], 'legacy 應把未送的 c_ly_intercalary 清成 0');
        $this->assertSame(1, (int) $v2Row['c_fy_intercalary'], 'v2 應保留未送的 c_fy_intercalary=1（partial update 不清空）');
        $this->assertSame(1, (int) $v2Row['c_ly_intercalary'], 'v2 應保留未送的 c_ly_intercalary=1');
    }

    #[Test]
    public function testAddressUpdateAcceptsAlias(): void {
        $user = $this->makeUser(email: 'addr-alias@example.com');
        $this->actingAs($user);
        $this->seedAddress();

        $response = $this->postJson('/api/v2/mutate', $this->addressPayload([
            'resource' => 'biog_addr_data',
        ]));

        $response->assertOk()
            ->assertJson(['ok' => true, 'resource' => 'addresses']);
    }

    #[Test]
    public function testAddressUpdateRecordNotExist(): void {
        $user = $this->makeUser(email: 'addr-notexist@example.com');
        $this->actingAs($user);
        // 不 seed 任何資料

        $response = $this->postJson('/api/v2/mutate', $this->addressPayload());
        $response->assertStatus(404);
    }

    // ── PK Conflict Tests ────────────────────────────────────

    #[Test]
    public function testDirectAddressUpdateWithPkCollisionReturns409(): void {
        $user = $this->makeUser(email: 'addr-conflict@example.com');
        $this->actingAs($user);
        // Seed two addresses with the same c_personid and c_addr_id but different c_addr_type
        $this->seedAddress(['c_addr_type' => 1, 'c_sequence' => 1]);
        $this->seedAddress(['c_addr_type' => 2, 'c_sequence' => 1]);

        // Try to change the first address's type to match the second (PK collision)
        $response = $this->postJson('/api/v2/mutate', $this->addressPayload([
            'changes' => ['c_addr_type' => 2],
        ]));

        $response->assertStatus(409)
            ->assertJson(['ok' => false, 'errors' => ['target.pk' => ['conflict']]]);

        // Original row must be unchanged
        $this->assertDatabaseHas('BIOG_ADDR_DATA', [
            'c_personid' => 1000,
            'c_addr_type' => 1,
            'c_sequence' => 1,
        ]);
    }
}
