<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2CreateEntryTest extends TestCase {
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
        $this->createEntryTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('ENTRY_DATA');
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

    protected function createEntryTable(): void {
        Schema::create('ENTRY_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_entry_code')->default(0);
            $table->integer('c_sequence')->default(0);
            $table->integer('c_kin_code')->default(0);
            $table->integer('c_assoc_code')->default(0);
            $table->integer('c_kin_id')->default(0);
            $table->integer('c_year')->default(0);
            $table->integer('c_assoc_id')->default(0);
            $table->integer('c_inst_code')->default(0);
            $table->integer('c_inst_name_code')->default(0);
            $table->integer('c_entry_addr_id')->nullable();
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->integer('c_nianhao_id')->nullable();
            $table->integer('c_entry_nh_year')->nullable();
            $table->integer('c_entry_range')->nullable();
            $table->string('c_exam_rank', 255)->nullable();
            $table->integer('c_attempt_count')->nullable();
            $table->string('c_exam_field', 255)->nullable();
            $table->integer('c_parental_status_code')->nullable();
            $table->integer('c_age')->nullable();
            $table->string('c_posting_notes', 255)->nullable();
            $table->string('c_created_by', 255)->nullable();
            $table->string('c_created_date', 255)->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->string('c_modified_date', 255)->nullable();
            $table->primary([
                'c_personid', 'c_entry_code', 'c_sequence', 'c_kin_code',
                'c_assoc_code', 'c_kin_id', 'c_year', 'c_assoc_id',
                'c_inst_code', 'c_inst_name_code',
            ]);
        });
    }

    // ── Helpers ──────────────────────────────────────────────

    protected function seedEntry(array $overrides = []): void {
        DB::table('ENTRY_DATA')->insert(array_replace([
            'c_personid' => 1000,
            'c_entry_code' => 36,
            'c_sequence' => 1,
            'c_kin_code' => 0,
            'c_assoc_code' => 0,
            'c_kin_id' => 0,
            'c_year' => 1057,
            'c_assoc_id' => 0,
            'c_inst_code' => 0,
            'c_inst_name_code' => 0,
            'c_entry_addr_id' => 100,
            'c_source' => 10,
            'c_pages' => '5',
            'c_notes' => null,
        ], $overrides));
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'create-entry-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function createPayload(array $overrides = []): array {
        $payload = [
            'resource' => 'entries',
            'person_id' => 1000,
            'mode' => 'direct',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_entry_code' => 72,
                    'c_sequence' => 1,
                    'c_kin_code' => 0,
                    'c_assoc_code' => 0,
                    'c_kin_id' => 0,
                    'c_year' => 1070,
                    'c_assoc_id' => 0,
                    'c_inst_code' => 0,
                    'c_inst_name_code' => 0,
                ],
            ],
            'changes' => [
                'c_source' => 20,
                'c_pages' => '10-15',
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    // ── Direct Create Tests ─────────────────────────────────

    #[Test]
    public function testDirectEntryCreateSucceeds(): void {
        $user = $this->makeUser(email: 'create-entry-direct@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'entries',
                'mode' => 'direct',
                'operation' => 'create',
                'result' => [
                    'pk' => [
                        'c_personid' => 1000,
                        'c_entry_code' => 72,
                        'c_sequence' => 1,
                        'c_kin_code' => 0,
                        'c_assoc_code' => 0,
                        'c_kin_id' => 0,
                        'c_year' => 1070,
                        'c_assoc_id' => 0,
                        'c_inst_code' => 0,
                        'c_inst_name_code' => 0,
                    ],
                ],
            ]);

        $data = $response->json();
        $this->assertNotNull($data['result']['operation_id']);
        $this->assertNotNull($data['result']['row']);

        $this->assertDatabaseHas('ENTRY_DATA', [
            'c_personid' => 1000,
            'c_entry_code' => 72,
            'c_sequence' => 1,
            'c_kin_code' => 0,
            'c_assoc_code' => 0,
            'c_kin_id' => 0,
            'c_year' => 1070,
            'c_assoc_id' => 0,
            'c_inst_code' => 0,
            'c_inst_name_code' => 0,
            'c_source' => 20,
            'c_pages' => '10-15',
        ]);
    }

    #[Test]
    public function testDirectEntryCreatePersistsRestoredFields(): void {
        // 回歸（Task 27）：補回的入仕欄位在 create 路徑須真的寫入 ENTRY_DATA。
        $user = $this->makeUser(email: 'create-entry-restored@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => [
                'c_source' => 20,
                'c_exam_rank' => '進士',
                'c_attempt_count' => 3,
                'c_exam_field' => '詩賦',
                'c_parental_status_code' => 2,
                'c_age' => 25,
                'c_posting_notes' => '初任',
            ],
        ]))->assertOk();

        $this->assertDatabaseHas('ENTRY_DATA', [
            'c_personid' => 1000,
            'c_entry_code' => 72,
            'c_sequence' => 1,
            'c_exam_rank' => '進士',
            'c_attempt_count' => 3,
            'c_exam_field' => '詩賦',
            'c_parental_status_code' => 2,
            'c_age' => 25,
            'c_posting_notes' => '初任',
        ]);
    }

    #[Test]
    public function testDirectEntryCreateWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'create-entry-op@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'ENTRY_DATA',
            'op_type' => Operation::TYPE_CREATE,
        ]);
    }

    #[Test]
    public function testDirectEntryCreateWritesAuditLog(): void {
        $user = $this->makeUser(email: 'create-entry-audit@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload());

        $audit = DB::table('audit_log')->where('table_name', 'ENTRY_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('INSERT', $audit->operation);
    }

    // ── Proposal Create Tests ───────────────────────────────

    #[Test]
    public function testProposalEntryCreateSucceeds(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-entry-proposal@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'proposal',
            'meta' => ['comment' => '提案新增入仕'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'entries',
                'mode' => 'proposal',
                'operation' => 'create',
                'result' => [
                    'status' => 'proposal_created',
                ],
            ]);

        // 原始資料表不應有新增的資料
        $this->assertDatabaseMissing('ENTRY_DATA', [
            'c_personid' => 1000,
            'c_entry_code' => 72,
            'c_year' => 1070,
        ]);
    }

    #[Test]
    public function testProposalEntryCreateWritesProposalMeta(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-entry-prop-meta@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'proposal',
            'meta' => ['comment' => '提案新增入仕'],
        ]));

        $this->assertDatabaseHas('operations', [
            'resource' => 'ENTRY_DATA',
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
        ]);

        $operation = DB::table('operations')->where('resource', 'ENTRY_DATA')->first();
        $resourceData = json_decode($operation->resource_data, true);

        $this->assertSame('pending', $resourceData['__review_status']);
        $this->assertSame('create', $resourceData['__proposal_meta']['action']);
        $this->assertSame('entries', $resourceData['__proposal_meta']['resource_type']);
        $this->assertSame('提案新增入仕', $resourceData['__proposal_meta']['comment']);

        // 無 audit_log
        $this->assertDatabaseMissing('audit_log', ['table_name' => 'ENTRY_DATA']);
    }

    // ── Error Cases ─────────────────────────────────────────

    #[Test]
    public function testCreateDuplicateTargetPkReturns409(): void {
        $user = $this->makeUser(email: 'create-entry-dup@example.com');
        $this->actingAs($user);

        // 先建立一筆與 createPayload PK 相同的資料
        $this->seedEntry([
            'c_entry_code' => 72,
            'c_sequence' => 1,
            'c_kin_code' => 0,
            'c_assoc_code' => 0,
            'c_kin_id' => 0,
            'c_year' => 1070,
            'c_assoc_id' => 0,
            'c_inst_code' => 0,
            'c_inst_name_code' => 0,
        ]);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertStatus(409)
            ->assertJson(['ok' => false]);
    }

    #[Test]
    public function testCreateDuplicatePendingProposalReturns409(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-entry-dup-prop@example.com');
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
        $this->assertSame(1, DB::table('operations')->where('resource', 'ENTRY_DATA')->count());
    }

    #[Test]
    public function testCreateWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'create-entry-mismatch@example.com');
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
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'create-entry-inactive@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertStatus(403);
    }

    #[Test]
    public function testDirectCreateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-entry-crowd@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'direct',
        ]));

        $response->assertStatus(403);
    }
}
