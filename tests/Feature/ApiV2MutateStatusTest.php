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

class ApiV2MutateStatusTest extends TestCase {
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
        $this->createStatusTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('STATUS_DATA');
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

    protected function createStatusTable(): void {
        Schema::create('STATUS_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_sequence')->default(0);
            $table->integer('c_status_code')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->text('c_supplement')->nullable();
            $table->integer('c_firstyear')->nullable();
            $table->integer('c_fy_nh_code')->nullable();
            $table->integer('c_fy_nh_year')->nullable();
            $table->integer('c_fy_range')->nullable();
            $table->integer('c_lastyear')->nullable();
            $table->integer('c_ly_nh_code')->nullable();
            $table->integer('c_ly_nh_year')->nullable();
            $table->integer('c_ly_range')->nullable();
            $table->string('c_created_by', 255)->nullable();
            $table->string('c_created_date', 255)->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->string('c_modified_date', 255)->nullable();
            $table->primary(['c_personid', 'c_sequence', 'c_status_code']);
        });
    }

    // ── Helpers ──────────────────────────────────────────────

    protected function seedStatus(array $overrides = []): void {
        DB::table('STATUS_DATA')->insert(array_replace([
            'c_personid' => 1000,
            'c_sequence' => 1,
            'c_status_code' => 50,
            'c_source' => 10,
            'c_pages' => '1-5',
            'c_notes' => null,
            'c_firstyear' => 1050,
            'c_lastyear' => 1100,
        ], $overrides));
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'status-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function statusPayload(array $overrides = []): array {
        $payload = [
            'resource' => 'statuses',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_sequence' => 1,
                    'c_status_code' => 50,
                ],
            ],
            'changes' => [
                'c_notes' => '新的備註',
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    // ── Direct Update Tests ─────────────────────────────────

    #[Test]
    public function testDirectStatusUpdateSucceeds(): void {
        $user = $this->makeUser(email: 'status-direct@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/mutate', $this->statusPayload([
            'changes' => ['c_notes' => '更新備註', 'c_pages' => '10-20'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'statuses',
                'mode' => 'direct',
                'operation' => 'update',
                'result' => [
                    'pk' => [
                        'c_personid' => 1000,
                        'c_sequence' => 1,
                        'c_status_code' => 50,
                    ],
                    'updated_fields' => ['c_notes', 'c_pages'],
                ],
            ]);

        $this->assertDatabaseHas('STATUS_DATA', [
            'c_personid' => 1000,
            'c_notes' => '更新備註',
            'c_pages' => '10-20',
        ]);
    }

    #[Test]
    public function testDirectStatusUpdatePersistsSupplementAndDoesNotNullOtherFields(): void {
        // 回歸（Task 27）：補欄 c_supplement 必須能寫入；且只改單一欄位時，未送出的欄位
        // （c_supplement / c_firstyear）不可被清成 null —— 防護「保存即清空」資料流失 bug。
        $user = $this->makeUser(email: 'status-supplement@example.com');
        $this->actingAs($user);
        $this->seedStatus(['c_supplement' => '原始補充', 'c_firstyear' => 1050]);

        // (a) 直接更新 c_supplement 應成功寫入。
        $this->postJson('/api/v2/mutate', $this->statusPayload([
            'changes' => ['c_supplement' => '新補充'],
        ]))->assertOk();
        $this->assertDatabaseHas('STATUS_DATA', [
            'c_personid' => 1000, 'c_sequence' => 1, 'c_status_code' => 50,
            'c_supplement' => '新補充', 'c_firstyear' => 1050,
        ]);

        // (b) 只改 c_notes（payload 不含 c_supplement/c_firstyear）後，兩者仍保留、未被清空。
        $this->postJson('/api/v2/mutate', $this->statusPayload([
            'changes' => ['c_notes' => '只改備註'],
        ]))->assertOk();
        $this->assertDatabaseHas('STATUS_DATA', [
            'c_personid' => 1000, 'c_sequence' => 1, 'c_status_code' => 50,
            'c_notes' => '只改備註', 'c_supplement' => '新補充', 'c_firstyear' => 1050,
        ]);
    }

    #[Test]
    public function testDirectStatusUpdateRekeyStatusCodePreservesUnchangedFields(): void {
        // 回歸（StatusEditor V2）：改鍵 c_status_code（同時改一非主鍵欄 c_notes），
        // 舊 PK row 必須消失、新 PK row 出現，且所有未變更欄位（c_source / c_pages /
        // c_supplement / 起終年 / 年號）一個都不能漂移或被清成 null。
        $user = $this->makeUser(email: 'status-rekey@example.com');
        $this->actingAs($user);
        $this->seedStatus([
            'c_status_code' => 50,
            'c_source' => 10,
            'c_pages' => '1-5',
            'c_supplement' => '原始補充',
            'c_firstyear' => 1050,
            'c_fy_nh_code' => 7,
            'c_fy_nh_year' => 3,
            'c_fy_range' => 0,
            'c_lastyear' => 1100,
            'c_ly_nh_code' => 8,
            'c_ly_nh_year' => 5,
            'c_ly_range' => 0,
        ]);

        $response = $this->postJson('/api/v2/mutate', $this->statusPayload([
            'changes' => [
                'c_status_code' => 60, // 改鍵
                'c_notes' => '改鍵後備註',
            ],
        ]));
        $response->assertOk();

        // 舊 PK row 消失
        $this->assertDatabaseMissing('STATUS_DATA', [
            'c_personid' => 1000, 'c_sequence' => 1, 'c_status_code' => 50,
        ]);
        // 新 PK row 出現，未變更欄位全數保留
        $this->assertDatabaseHas('STATUS_DATA', [
            'c_personid' => 1000, 'c_sequence' => 1, 'c_status_code' => 60,
            'c_notes' => '改鍵後備註',
            'c_source' => 10, 'c_pages' => '1-5', 'c_supplement' => '原始補充',
            'c_firstyear' => 1050, 'c_fy_nh_code' => 7, 'c_fy_nh_year' => 3, 'c_fy_range' => 0,
            'c_lastyear' => 1100, 'c_ly_nh_code' => 8, 'c_ly_nh_year' => 5, 'c_ly_range' => 0,
        ]);
    }

    #[Test]
    public function testDirectStatusUpdateAcceptsAllEraColumns(): void {
        // 回歸（trap #1 allowlist 全覆蓋）：起/終年的 8 個年號/時限欄位皆須在白名單內，
        // 任一缺漏會在編輯起終年時造成 422 或靜默資料流失。
        $user = $this->makeUser(email: 'status-era@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $this->postJson('/api/v2/mutate', $this->statusPayload([
            'changes' => [
                'c_firstyear' => 1060, 'c_fy_nh_code' => 11, 'c_fy_nh_year' => 2, 'c_fy_range' => 1,
                'c_lastyear' => 1090, 'c_ly_nh_code' => 12, 'c_ly_nh_year' => 4, 'c_ly_range' => 1,
            ],
        ]))->assertOk();

        $this->assertDatabaseHas('STATUS_DATA', [
            'c_personid' => 1000, 'c_sequence' => 1, 'c_status_code' => 50,
            'c_firstyear' => 1060, 'c_fy_nh_code' => 11, 'c_fy_nh_year' => 2, 'c_fy_range' => 1,
            'c_lastyear' => 1090, 'c_ly_nh_code' => 12, 'c_ly_nh_year' => 4, 'c_ly_range' => 1,
        ]);
    }

    #[Test]
    public function testDirectStatusUpdateReturnsOperationIdAndRow(): void {
        $user = $this->makeUser(email: 'status-result@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/mutate', $this->statusPayload());
        $data = $response->json();

        $this->assertNotNull($data['result']['operation_id']);
        $this->assertNotNull($data['result']['row']);
        $this->assertSame('新的備註', $data['result']['row']['c_notes']);
    }

    #[Test]
    public function testDirectStatusUpdateWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'status-op@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $this->postJson('/api/v2/mutate', $this->statusPayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'STATUS_DATA',
            'op_type' => Operation::TYPE_UPDATE,
        ]);
    }

    #[Test]
    public function testDirectStatusUpdateWritesAuditLog(): void {
        $user = $this->makeUser(email: 'status-audit@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $this->postJson('/api/v2/mutate', $this->statusPayload());

        $audit = DB::table('audit_log')->where('table_name', 'STATUS_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('UPDATE', $audit->operation);
    }

    // ── Proposal Update Tests ───────────────────────────────

    #[Test]
    public function testProposalStatusUpdateCreatesPendingOperation(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'status-proposal@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/mutate', $this->statusPayload([
            'mode' => 'proposal',
            'changes' => ['c_notes' => '提案備註'],
            'meta' => ['comment' => '提案修改備註'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'statuses',
                'mode' => 'proposal',
                'operation' => 'update',
                'result' => [
                    'updated_fields' => ['c_notes'],
                    'status' => 'proposal_updated',
                ],
            ]);

        // 原始資料未被修改
        $this->assertDatabaseHas('STATUS_DATA', [
            'c_personid' => 1000,
            'c_notes' => null,
        ]);

        // 已寫入提案操作紀錄
        $this->assertDatabaseHas('operations', [
            'resource' => 'STATUS_DATA',
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
        ]);

        $operation = DB::table('operations')->where('resource', 'STATUS_DATA')->first();
        $resourceData = json_decode($operation->resource_data, true);

        $this->assertSame('pending', $resourceData['__review_status']);
        $this->assertSame('update', $resourceData['__proposal_meta']['action']);
        $this->assertSame('statuses', $resourceData['__proposal_meta']['resource_type']);
        $this->assertSame('提案修改備註', $resourceData['__proposal_meta']['comment']);

        // 無 audit_log
        $this->assertDatabaseMissing('audit_log', ['table_name' => 'STATUS_DATA']);
    }

    // ── Error Cases ─────────────────────────────────────────

    #[Test]
    public function testStatusUpdateWithInvalidPkReturns404(): void {
        $user = $this->makeUser(email: 'status-404@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/mutate', $this->statusPayload([
            'target' => ['pk' => [
                'c_personid' => 1000,
                'c_sequence' => 999,
                'c_status_code' => 50,
            ]],
        ]));

        $response->assertStatus(404);
    }

    #[Test]
    public function testStatusUpdateWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'status-mismatch@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/mutate', $this->statusPayload([
            'person_id' => 9999,
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    #[Test]
    public function testStatusUpdateWithEmptyChangesReturns422(): void {
        $user = $this->makeUser(email: 'status-empty@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'statuses',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_sequence' => 1,
                    'c_status_code' => 50,
                ],
            ],
            'changes' => [],
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function testStatusUpdateWithDisallowedFieldReturns422(): void {
        $user = $this->makeUser(email: 'status-disallowed@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/mutate', $this->statusPayload([
            'changes' => ['c_personid' => 9999],
        ]));

        $response->assertStatus(422);
        $this->assertStringContainsString('不允許', $response->json('message'));
    }

    #[Test]
    public function testStatusUpdateWithNoEffectiveChangeReturns422(): void {
        $user = $this->makeUser(email: 'status-nochange@example.com');
        $this->actingAs($user);
        $this->seedStatus(['c_notes' => '已有備註']);

        $response = $this->postJson('/api/v2/mutate', $this->statusPayload([
            'changes' => ['c_notes' => '已有備註'],
        ]));

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'errors' => ['changes' => ['no_effective_changes']],
            ]);
    }

    #[Test]
    public function testStatusUpdateRejectsUnauthenticatedUser(): void {
        $this->seedStatus();
        $response = $this->postJson('/api/v2/mutate', $this->statusPayload());
        $response->assertStatus(401);
    }

    #[Test]
    public function testStatusUpdateRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'status-inactive@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/mutate', $this->statusPayload());
        $response->assertStatus(403);
    }

    #[Test]
    public function testStatusDirectUpdateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'status-crowd@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/mutate', $this->statusPayload());
        $response->assertStatus(403);
    }

    // ── #56 M 寫入等價（update 路徑，純單表；statuses 無鏡像/副表）──────

    #[Test]
    #[Group('legacy-parity')] // 旧版下线時連同 legacy update 路徑一併移除
    public function testStatusUpdateWriteEquivalenceLegacyVsV2(): void {
        // #56 M（update，純單表）——復原-重做實驗：改 c_notes+c_pages。legacy statuseUpdateById 無條件讀
        // c_status_code/c_source（缺了會 warning 且整包寫），故 body 必送此二欄「原值」+ c_sequence 定位用；只送 v2 會改的欄即等價。
        $this->actingAs($this->makeUser(email: 'status-mupd@example.com'));

        $pk = ['c_personid' => 1000, 'c_sequence' => 1, 'c_status_code' => 50];
        $seedInitial = function (): void {
            DB::table('STATUS_DATA')->delete();
            $this->seedStatus(['c_notes' => '初始備註', 'c_pages' => '1-5', 'c_source' => 10, 'c_firstyear' => 1050, 'c_lastyear' => 1100]);
        };
        $cols = ['c_personid', 'c_sequence', 'c_status_code', 'c_source', 'c_pages', 'c_notes', 'c_firstyear', 'c_lastyear'];
        $pick = function ($row) use ($cols): ?array {
            if (!$row) {
                return null;
            }
            $a = array_intersect_key((array) $row, array_flip($cols));
            ksort($a);

            return $a;
        };

        // ① 旧版 PUT（PK 走 query；body 必送 c_status_code/c_source 原值避免 warning/誤寫，再加要改的兩欄）。
        $seedInitial();
        $this->put('/basicinformation/1000/statuses/update?' . http_build_query($pk), [
            'c_sequence' => 1, 'c_status_code' => 50, 'c_source' => 10,
            'c_notes' => '改後備註', 'c_pages' => '10-20', 'action' => 'save',
        ])->assertStatus(302);
        $legacy = $pick(DB::table('STATUS_DATA')->where($pk)->first());

        // ② 復原初始 → ③ 新版改同一筆。
        $seedInitial();
        $this->postJson('/api/v2/mutate', $this->statusPayload([
            'changes' => ['c_notes' => '改後備註', 'c_pages' => '10-20'],
        ]))->assertOk()->assertJson(['ok' => true]);
        $v2 = $pick(DB::table('STATUS_DATA')->where($pk)->first());

        $this->assertNotNull($legacy, 'legacy 更新後列不存在');
        $this->assertNotNull($v2, 'v2 更新後列不存在');
        $this->assertSame('改後備註', $v2['c_notes'], 'v2 c_notes 應更新');
        $this->assertSame('改後備註', $legacy['c_notes'], 'legacy c_notes 應更新（鎖 legacy 確有寫入）');
        $this->assertSame('10-20', $v2['c_pages'], 'v2 c_pages 應更新');
        $this->assertSame($legacy, $v2, 'STATUS_DATA 落庫列 legacy vs v2 不等價');
    }

    #[Test]
    public function testStatusUpdateAcceptsAlias(): void {
        $user = $this->makeUser(email: 'status-alias@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/mutate', $this->statusPayload([
            'resource' => 'status_data',
        ]));

        $response->assertOk()
            ->assertJson(['ok' => true, 'resource' => 'statuses']);
    }

    #[Test]
    public function testStatusUpdateRecordNotExist(): void {
        $user = $this->makeUser(email: 'status-notexist@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/mutate', $this->statusPayload());
        $response->assertStatus(404);
    }
}
