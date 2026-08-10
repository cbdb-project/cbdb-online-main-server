<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2CreateAddressTest extends TestCase {
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

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'create-addr-tester@example.com'): User {
        return User::forceCreate([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function createPayload(array $overrides = []): array {
        $payload = [
            'resource' => 'addresses',
            'person_id' => 1000,
            'mode' => 'direct',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_addr_id' => 200,
                    'c_addr_type' => 2,
                    'c_sequence' => 1,
                ],
            ],
            'changes' => [
                'c_firstyear' => 1060,
                'c_source' => 20,
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    #[Test]
    public function testAddressCreateCodeFieldSentinelFullyIdempotent(): void {
        // #71（確認 parity）：address create 既以 inline null/'' fix + normalizeSentinelValues 達成 c_source 完全幂等——
        // null/''/'-999'/-999/'0'/0 落庫皆 0、永不寫 null/''；合法非 0 保留。每案用不同 c_sequence 取獨立 PK。≥10 案例。
        $this->actingAs($this->makeUser(email: 'addr-create-sentinel@example.com'));
        $T = 'BIOG_ADDR_DATA';
        foreach ([null, '', '-999', -999, '0', 0] as $i => $sent) {
            $seq = 10 + $i;
            $this->postJson('/api/v2/create', $this->createPayload([
                'target' => ['pk' => ['c_sequence' => $seq]],
                'changes' => ['c_source' => $sent],
            ]))->assertOk()->assertJson(['ok' => true]);
            $stored = DB::table($T)->where(['c_personid' => 1000, 'c_addr_id' => 200, 'c_addr_type' => 2, 'c_sequence' => $seq])->value('c_source');
            $this->assertNotNull($stored, 'c_source 送 '.var_export($sent, true).' 不得為 null');
            $this->assertSame('0', (string) $stored, 'c_source 送 '.var_export($sent, true).' 應規範化為 0');
        }
        foreach ([5, 7, 999, 42] as $i => $sent) {
            $seq = 20 + $i;
            $this->postJson('/api/v2/create', $this->createPayload([
                'target' => ['pk' => ['c_sequence' => $seq]],
                'changes' => ['c_source' => $sent],
            ]))->assertOk();
            $stored = DB::table($T)->where(['c_personid' => 1000, 'c_addr_id' => 200, 'c_addr_type' => 2, 'c_sequence' => $seq])->value('c_source');
            $this->assertSame($sent, (int) $stored, '合法非 0 值不得被誤清：'.$sent);
        }
    }

    // ── Direct Create Tests ─────────────────────────────────

    #[Test]
    public function testDirectAddressCreateSucceeds(): void {
        $user = $this->makeUser(email: 'create-addr-direct@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'addresses',
                'mode' => 'direct',
                'operation' => 'create',
            ]);

        $data = $response->json();
        $this->assertNotNull($data['result']['operation_id']);
        $this->assertNotNull($data['result']['row']);

        $this->assertDatabaseHas('BIOG_ADDR_DATA', [
            'c_personid' => 1000,
            'c_addr_id' => 200,
            'c_addr_type' => 2,
            'c_sequence' => 1,
            'c_firstyear' => 1060,
            'c_source' => 20,
        ]);
    }

    #[Test]
    public function testDirectAddressCreatePersistsLunarFields(): void {
        // 回歸（人物編輯重做）：新增地址時 EraTimeField showLunar 的農曆月/日/干支須在 create allowlist 內。
        $user = $this->makeUser(email: 'create-addr-lunar@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => [
                'c_firstyear' => 1060, 'c_source' => 20,
                'c_fy_month' => 3, 'c_fy_day' => 15, 'c_fy_day_gz' => 12,
                'c_ly_month' => 8, 'c_ly_day' => 20, 'c_ly_day_gz' => 30,
            ],
        ]))->assertOk();

        $this->assertDatabaseHas('BIOG_ADDR_DATA', [
            'c_personid' => 1000, 'c_addr_id' => 200, 'c_addr_type' => 2, 'c_sequence' => 1,
            'c_fy_month' => 3, 'c_fy_day' => 15, 'c_fy_day_gz' => 12,
            'c_ly_month' => 8, 'c_ly_day' => 20, 'c_ly_day_gz' => 30,
        ]);
    }

    #[Test]
    public function testDirectAddressCreatePersistsNatal(): void {
        // 回歸（Task 27）：補欄 c_natal（是否本貫）在 create 路徑須真的寫入 BIOG_ADDR_DATA。
        $user = $this->makeUser(email: 'create-addr-natal@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_firstyear' => 1060, 'c_source' => 20, 'c_natal' => 1],
        ]))->assertOk();

        $this->assertDatabaseHas('BIOG_ADDR_DATA', [
            'c_personid' => 1000,
            'c_addr_id' => 200,
            'c_addr_type' => 2,
            'c_sequence' => 1,
            'c_natal' => 1,
        ]);
    }

    #[Test]
    public function testDirectAddressCreateWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'create-addr-op@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'BIOG_ADDR_DATA',
            'op_type' => Operation::TYPE_CREATE,
        ]);
    }

    #[Test]
    public function testDirectAddressCreateWritesAuditLog(): void {
        $user = $this->makeUser(email: 'create-addr-audit@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload());

        $audit = DB::table('audit_log')->where('table_name', 'BIOG_ADDR_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('INSERT', $audit->operation);
    }

    #[Test]
    public function testDirectAddressCreatePopulatesTimestampFields(): void {
        $user = $this->makeUser(email: 'create-addr-ts@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload());

        $row = DB::table('BIOG_ADDR_DATA')
            ->where('c_personid', 1000)
            ->where('c_addr_id', 200)
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('tester', $row->c_created_by);
        $this->assertNotNull($row->c_created_date);
        $this->assertNull($row->c_modified_by);
        $this->assertNull($row->c_modified_date);
    }

    // ── Proposal Create Tests ───────────────────────────────

    #[Test]
    public function testProposalAddressCreateSucceeds(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-addr-proposal@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'proposal',
            'meta' => ['comment' => '提案新增地址'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'addresses',
                'mode' => 'proposal',
                'operation' => 'create',
                'result' => [
                    'status' => 'proposal_created',
                ],
            ]);

        // 原始資料表不應有新增的資料
        $this->assertDatabaseMissing('BIOG_ADDR_DATA', [
            'c_personid' => 1000,
            'c_addr_id' => 200,
            'c_addr_type' => 2,
            'c_sequence' => 1,
        ]);
    }

    #[Test]
    public function testProposalAddressCreateWritesProposalMeta(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-addr-prop-meta@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'proposal',
            'meta' => ['comment' => '提案新增地址'],
        ]));

        $this->assertDatabaseHas('operations', [
            'resource' => 'BIOG_ADDR_DATA',
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
        ]);

        $operation = DB::table('operations')->where('resource', 'BIOG_ADDR_DATA')->first();
        $resourceData = json_decode($operation->resource_data, true);

        $this->assertSame('pending', $resourceData['__review_status']);
        $this->assertSame('create', $resourceData['__proposal_meta']['action']);
        $this->assertSame('addresses', $resourceData['__proposal_meta']['resource_type']);
        $this->assertSame('提案新增地址', $resourceData['__proposal_meta']['comment']);

        // 無 audit_log
        $this->assertDatabaseMissing('audit_log', ['table_name' => 'BIOG_ADDR_DATA']);
    }

    // ── Error Cases ─────────────────────────────────────────

    #[Test]
    public function testCreateDuplicateTargetPkReturns409(): void {
        $user = $this->makeUser(email: 'create-addr-dup@example.com');
        $this->actingAs($user);

        // 先建立一筆與 createPayload PK 相同的資料
        $this->seedAddress([
            'c_personid' => 1000,
            'c_addr_id' => 200,
            'c_addr_type' => 2,
            'c_sequence' => 1,
        ]);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertStatus(409)
            ->assertJson(['ok' => false]);
    }

    #[Test]
    public function testCreateDuplicatePendingProposalReturns409(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-addr-dup-prop@example.com');
        $this->actingAs($user);

        $payload = $this->createPayload([
            'mode' => 'proposal',
        ]);

        // 第一次提案成功
        $first = $this->postJson('/api/v2/create', $payload);
        $first->assertOk();

        // 第二次相同提案被拒絕
        $second = $this->postJson('/api/v2/create', $payload);
        $second->assertStatus(409)
            ->assertJson(['ok' => false, 'errors' => ['target.pk' => ['pending_proposal_exists']]]);

        // 只有一筆提案
        $this->assertSame(1, DB::table('operations')->where('resource', 'BIOG_ADDR_DATA')->count());
    }

    #[Test]
    public function testCreateWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'create-addr-mismatch@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'person_id' => 9999,
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    // ── Auth / Permission Tests ─────────────────────────────

    #[Test]
    public function testCreateRejectsUnauthenticatedUser(): void {
        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertStatus(401);
    }

    #[Test]
    public function testCreateRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'create-addr-inactive@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertStatus(403);
    }

    #[Test]
    public function testDirectCreateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-addr-crowd@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'direct',
        ]));

        $response->assertStatus(403);
    }
}
