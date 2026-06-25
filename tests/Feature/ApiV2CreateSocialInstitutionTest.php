<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2CreateSocialInstitutionTest extends TestCase {
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
        $this->createInstTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('BIOG_INST_DATA');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

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

    protected function createInstTable(): void {
        Schema::create('BIOG_INST_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_inst_code')->default(0);
            $table->integer('c_inst_name_code')->default(0);
            $table->integer('c_bi_role_code')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->integer('c_bi_begin_year')->nullable();
            $table->integer('c_bi_by_nh_code')->nullable();
            $table->integer('c_bi_by_nh_year')->nullable();
            $table->integer('c_bi_by_range')->nullable();
            $table->integer('c_bi_end_year')->nullable();
            $table->integer('c_bi_ey_nh_code')->nullable();
            $table->integer('c_bi_ey_nh_year')->nullable();
            $table->integer('c_bi_ey_range')->nullable();
            $table->string('c_created_by', 255)->nullable();
            $table->string('c_created_date', 255)->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->string('c_modified_date', 255)->nullable();
            $table->primary(['c_personid', 'c_inst_code', 'c_inst_name_code', 'c_bi_role_code']);
        });
    }

    protected function seedInst(array $overrides = []): void {
        DB::table('BIOG_INST_DATA')->insert(array_replace([
            'c_personid' => 1000,
            'c_inst_code' => 10,
            'c_inst_name_code' => 5,
            'c_bi_role_code' => 1,
            'c_source' => 10,
            'c_pages' => '1-5',
            'c_notes' => null,
        ], $overrides));
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'create-inst-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function createPayload(array $overrides = []): array {
        return array_replace_recursive([
            'resource' => 'social_institutions',
            'person_id' => 1000,
            'mode' => 'direct',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_inst_code' => 20,
                    'c_inst_name_code' => 6,
                    'c_bi_role_code' => 3,
                ],
            ],
            'changes' => [
                'c_source' => 20,
                'c_notes' => '新增社會機構',
            ],
        ], $overrides);
    }

    #[Test]
    public function testSocialInstitutionCreateCodeFieldSentinelFullyIdempotent(): void {
        // #71：create c_source 完全幂等——null/''/'-999'/-999/'0'/0 落庫皆 0、永不寫 null/''；合法非 0 保留。
        // 每案用不同 c_inst_name_code 取獨立 PK。≥10 案例。
        $this->actingAs($this->makeUser(email: 'inst-create-sentinel@example.com'));
        $T = 'BIOG_INST_DATA';
        foreach ([null, '', '-999', -999, '0', 0] as $i => $sent) {
            $nameCode = 100 + $i;
            $this->postJson('/api/v2/create', $this->createPayload([
                'target' => ['pk' => ['c_inst_name_code' => $nameCode]],
                'changes' => ['c_source' => $sent],
            ]))->assertOk()->assertJson(['ok' => true]);
            $stored = DB::table($T)->where(['c_personid' => 1000, 'c_inst_code' => 20, 'c_inst_name_code' => $nameCode, 'c_bi_role_code' => 3])->value('c_source');
            $this->assertNotNull($stored, 'c_source 送 '.var_export($sent, true).' 不得為 null');
            $this->assertSame('0', (string) $stored, 'c_source 送 '.var_export($sent, true).' 應規範化為 0');
        }
        foreach ([5, 7, 999, 42] as $i => $sent) {
            $nameCode = 200 + $i;
            $this->postJson('/api/v2/create', $this->createPayload([
                'target' => ['pk' => ['c_inst_name_code' => $nameCode]],
                'changes' => ['c_source' => $sent],
            ]))->assertOk();
            $stored = DB::table($T)->where(['c_personid' => 1000, 'c_inst_code' => 20, 'c_inst_name_code' => $nameCode, 'c_bi_role_code' => 3])->value('c_source');
            $this->assertSame($sent, (int) $stored, '合法非 0 值不得被誤清：'.$sent);
        }
    }

    #[Test]
    public function testDirectSocialInstitutionCreateSucceeds(): void {
        $user = $this->makeUser(email: 'create-inst-direct@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'social_institutions',
                'mode' => 'direct',
                'operation' => 'create',
            ]);

        $this->assertNotNull($response->json('result.operation_id'));
        $this->assertNotNull($response->json('result.row'));

        $this->assertDatabaseHas('BIOG_INST_DATA', [
            'c_personid' => 1000,
            'c_inst_code' => 20,
            'c_inst_name_code' => 6,
            'c_bi_role_code' => 3,
            'c_source' => 20,
            'c_notes' => '新增社會機構',
        ]);
    }

    #[Test]
    public function testDirectSocialInstitutionCreateWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'create-inst-op@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'BIOG_INST_DATA',
            'op_type' => Operation::TYPE_CREATE,
        ]);
    }

    #[Test]
    public function testDirectSocialInstitutionCreateWritesAuditLog(): void {
        $user = $this->makeUser(email: 'create-inst-audit@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload());

        $audit = DB::table('audit_log')->where('table_name', 'BIOG_INST_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('INSERT', $audit->operation);
    }

    #[Test]
    public function testProposalSocialInstitutionCreateSucceeds(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-inst-proposal@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'proposal',
            'meta' => ['comment' => '提案新增社會機構'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'social_institutions',
                'mode' => 'proposal',
                'operation' => 'create',
                'result' => ['status' => 'proposal_created'],
            ]);

        $this->assertDatabaseMissing('BIOG_INST_DATA', [
            'c_personid' => 1000,
            'c_inst_code' => 20,
            'c_inst_name_code' => 6,
            'c_bi_role_code' => 3,
        ]);
    }

    #[Test]
    public function testProposalSocialInstitutionCreateWritesProposalMeta(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-inst-prop-meta@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'proposal',
            'meta' => ['comment' => '提案新增社會機構'],
        ]));

        $operation = DB::table('operations')->where('resource', 'BIOG_INST_DATA')->first();
        $this->assertNotNull($operation);
        $this->assertSame(Operation::TYPE_PROPOSAL_CREATE, (int) $operation->op_type);

        $resourceData = json_decode($operation->resource_data, true);
        $this->assertSame('pending', $resourceData['__review_status']);
        $this->assertSame('create', $resourceData['__proposal_meta']['action']);
        $this->assertSame('social_institutions', $resourceData['__proposal_meta']['resource_type']);
        $this->assertSame('提案新增社會機構', $resourceData['__proposal_meta']['comment']);

        $this->assertDatabaseMissing('audit_log', ['table_name' => 'BIOG_INST_DATA']);
    }

    #[Test]
    public function testCreateDuplicateTargetPkReturns409(): void {
        $user = $this->makeUser(email: 'create-inst-dup@example.com');
        $this->actingAs($user);

        $this->seedInst([
            'c_personid' => 1000,
            'c_inst_code' => 20,
            'c_inst_name_code' => 6,
            'c_bi_role_code' => 3,
        ]);

        $this->postJson('/api/v2/create', $this->createPayload())
            ->assertStatus(409)->assertJson(['ok' => false]);
    }

    #[Test]
    public function testCreateDuplicatePendingProposalReturns409(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-inst-dup-prop@example.com');
        $this->actingAs($user);

        $payload = $this->createPayload(['mode' => 'proposal']);

        $this->postJson('/api/v2/create', $payload)->assertOk();
        $this->postJson('/api/v2/create', $payload)
            ->assertStatus(409)
            ->assertJson(['ok' => false, 'errors' => ['target.pk' => ['pending_proposal_exists']]]);

        $this->assertSame(1, DB::table('operations')->where('resource', 'BIOG_INST_DATA')->count());
    }

    #[Test]
    public function testCreateWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'create-inst-mismatch@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload(['person_id' => 9999]))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    #[Test]
    public function testCreateRejectsDisallowedField(): void {
        $user = $this->makeUser(email: 'create-inst-disallowed@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_not_a_real_column' => 'x'],
        ]));

        $response->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertArrayHasKey('changes', $response->json('errors'));
        $this->assertDatabaseMissing('BIOG_INST_DATA', [
            'c_personid' => 1000,
            'c_inst_code' => 20,
            'c_inst_name_code' => 6,
            'c_bi_role_code' => 3,
        ]);
    }

    #[Test]
    public function testCreateRejectsUnauthenticatedUser(): void {
        $this->postJson('/api/v2/create', $this->createPayload())->assertStatus(401);
    }

    #[Test]
    public function testCreateRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'create-inst-inactive@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload())->assertStatus(403);
    }

    #[Test]
    public function testDirectCreateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-inst-crowd@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload(['mode' => 'direct']))->assertStatus(403);
    }
}
