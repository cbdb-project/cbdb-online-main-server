<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2MutateSocialInstitutionTest extends TestCase {
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
        $this->createSocialInstitutionTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('BIOG_INST_DATA');
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

    protected function createSocialInstitutionTable(): void {
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

    // ── Helpers ──────────────────────────────────────────────

    protected function seedSocialInstitution(array $overrides = []): void {
        DB::table('BIOG_INST_DATA')->insert(array_replace([
            'c_personid' => 1000,
            'c_inst_code' => 10,
            'c_inst_name_code' => 20,
            'c_bi_role_code' => 1,
            'c_source' => 10,
            'c_notes' => null,
        ], $overrides));
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'socinst-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function socialInstitutionPayload(array $overrides = []): array {
        $payload = [
            'resource' => 'social_institutions',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_inst_code' => 10,
                    'c_inst_name_code' => 20,
                    'c_bi_role_code' => 1,
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
    public function testDirectSocialInstitutionUpdateSucceeds(): void {
        $user = $this->makeUser(email: 'socinst-direct@example.com');
        $this->actingAs($user);
        $this->seedSocialInstitution();

        $response = $this->postJson('/api/v2/mutate', $this->socialInstitutionPayload([
            'changes' => ['c_notes' => '更新備註', 'c_pages' => '10-20'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'social_institutions',
                'mode' => 'direct',
                'operation' => 'update',
                'result' => [
                    'pk' => [
                        'c_personid' => 1000,
                        'c_inst_code' => 10,
                        'c_inst_name_code' => 20,
                        'c_bi_role_code' => 1,
                    ],
                    'updated_fields' => ['c_notes', 'c_pages'],
                ],
            ]);

        $this->assertDatabaseHas('BIOG_INST_DATA', [
            'c_personid' => 1000,
            'c_notes' => '更新備註',
            'c_pages' => '10-20',
        ]);
    }

    #[Test]
    public function testDirectSocialInstitutionRekeyPreservesUnchangedFields(): void {
        // React SocialInstEditor 編輯模式可改鍵（c_bi_role_code）；改鍵時未變更的非鍵欄位不得遺失。
        $user = $this->makeUser(email: 'socinst-rekey@example.com');
        $this->actingAs($user);
        $this->seedSocialInstitution(['c_pages' => 'p.99', 'c_notes' => '原備註']);

        $response = $this->postJson('/api/v2/mutate', $this->socialInstitutionPayload([
            'changes' => ['c_bi_role_code' => 2, 'c_notes' => '新備註'],
        ]));

        $response->assertOk()->assertJson(['ok' => true]);

        // 新鍵列存在，且未變更的 c_pages 仍保留（無欄位遺失）。
        $this->assertDatabaseHas('BIOG_INST_DATA', [
            'c_personid' => 1000,
            'c_inst_code' => 10,
            'c_inst_name_code' => 20,
            'c_bi_role_code' => 2,
            'c_notes' => '新備註',
            'c_pages' => 'p.99',
        ]);
        // 舊鍵列已移除（改鍵而非新增）。
        $this->assertDatabaseMissing('BIOG_INST_DATA', [
            'c_personid' => 1000,
            'c_inst_code' => 10,
            'c_inst_name_code' => 20,
            'c_bi_role_code' => 1,
        ]);
    }

    #[Test]
    public function testDirectSocialInstitutionUpdateReturnsOperationIdAndRow(): void {
        $user = $this->makeUser(email: 'socinst-result@example.com');
        $this->actingAs($user);
        $this->seedSocialInstitution();

        $response = $this->postJson('/api/v2/mutate', $this->socialInstitutionPayload());
        $data = $response->json();

        $this->assertNotNull($data['result']['operation_id']);
        $this->assertNotNull($data['result']['row']);
        $this->assertSame('新的備註', $data['result']['row']['c_notes']);
    }

    #[Test]
    public function testDirectSocialInstitutionUpdateWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'socinst-op@example.com');
        $this->actingAs($user);
        $this->seedSocialInstitution();

        $this->postJson('/api/v2/mutate', $this->socialInstitutionPayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'BIOG_INST_DATA',
            'op_type' => Operation::TYPE_UPDATE,
        ]);
    }

    #[Test]
    public function testDirectSocialInstitutionUpdateWritesAuditLog(): void {
        $user = $this->makeUser(email: 'socinst-audit@example.com');
        $this->actingAs($user);
        $this->seedSocialInstitution();

        $this->postJson('/api/v2/mutate', $this->socialInstitutionPayload());

        $audit = DB::table('audit_log')->where('table_name', 'BIOG_INST_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('UPDATE', $audit->operation);
    }

    // ── Proposal Update Tests ───────────────────────────────

    #[Test]
    public function testProposalSocialInstitutionUpdateCreatesPendingOperation(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'socinst-proposal@example.com');
        $this->actingAs($user);
        $this->seedSocialInstitution();

        $response = $this->postJson('/api/v2/mutate', $this->socialInstitutionPayload([
            'mode' => 'proposal',
            'changes' => ['c_notes' => '提案備註'],
            'meta' => ['comment' => '提案修改備註'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'social_institutions',
                'mode' => 'proposal',
                'operation' => 'update',
                'result' => [
                    'updated_fields' => ['c_notes'],
                    'status' => 'proposal_updated',
                ],
            ]);

        // 原始資料未被修改
        $this->assertDatabaseHas('BIOG_INST_DATA', [
            'c_personid' => 1000,
            'c_notes' => null,
        ]);

        // 已寫入提案操作紀錄
        $this->assertDatabaseHas('operations', [
            'resource' => 'BIOG_INST_DATA',
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
        ]);

        $operation = DB::table('operations')->where('resource', 'BIOG_INST_DATA')->first();
        $resourceData = json_decode($operation->resource_data, true);

        $this->assertSame('pending', $resourceData['__review_status']);
        $this->assertSame('update', $resourceData['__proposal_meta']['action']);
        $this->assertSame('social_institutions', $resourceData['__proposal_meta']['resource_type']);
        $this->assertSame('提案修改備註', $resourceData['__proposal_meta']['comment']);

        // 無 audit_log
        $this->assertDatabaseMissing('audit_log', ['table_name' => 'BIOG_INST_DATA']);
    }

    // ── Error Cases ─────────────────────────────────────────

    #[Test]
    public function testSocialInstitutionUpdateWithInvalidPkReturns404(): void {
        $user = $this->makeUser(email: 'socinst-404@example.com');
        $this->actingAs($user);
        $this->seedSocialInstitution();

        $response = $this->postJson('/api/v2/mutate', $this->socialInstitutionPayload([
            'target' => ['pk' => [
                'c_personid' => 1000,
                'c_inst_code' => 999,
                'c_inst_name_code' => 20,
                'c_bi_role_code' => 1,
            ]],
        ]));

        $response->assertStatus(404);
    }

    #[Test]
    public function testSocialInstitutionUpdateWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'socinst-mismatch@example.com');
        $this->actingAs($user);
        $this->seedSocialInstitution();

        $response = $this->postJson('/api/v2/mutate', $this->socialInstitutionPayload([
            'person_id' => 9999,
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    #[Test]
    public function testSocialInstitutionUpdateWithEmptyChangesReturns422(): void {
        $user = $this->makeUser(email: 'socinst-empty@example.com');
        $this->actingAs($user);
        $this->seedSocialInstitution();

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'social_institutions',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_inst_code' => 10,
                    'c_inst_name_code' => 20,
                    'c_bi_role_code' => 1,
                ],
            ],
            'changes' => [],
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function testSocialInstitutionUpdateWithDisallowedFieldReturns422(): void {
        $user = $this->makeUser(email: 'socinst-disallowed@example.com');
        $this->actingAs($user);
        $this->seedSocialInstitution();

        $response = $this->postJson('/api/v2/mutate', $this->socialInstitutionPayload([
            'changes' => ['c_personid' => 9999],
        ]));

        $response->assertStatus(422);
        $this->assertStringContainsString('不允許', $response->json('message'));
    }

    #[Test]
    public function testSocialInstitutionUpdateWithNoEffectiveChangeReturns422(): void {
        $user = $this->makeUser(email: 'socinst-nochange@example.com');
        $this->actingAs($user);
        $this->seedSocialInstitution(['c_notes' => '已有備註']);

        $response = $this->postJson('/api/v2/mutate', $this->socialInstitutionPayload([
            'changes' => ['c_notes' => '已有備註'],
        ]));

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'errors' => ['changes' => ['no_effective_changes']],
            ]);
    }

    #[Test]
    public function testSocialInstitutionUpdateRejectsUnauthenticatedUser(): void {
        $this->seedSocialInstitution();
        $response = $this->postJson('/api/v2/mutate', $this->socialInstitutionPayload());
        $response->assertStatus(401);
    }

    #[Test]
    public function testSocialInstitutionUpdateRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'socinst-inactive@example.com');
        $this->actingAs($user);
        $this->seedSocialInstitution();

        $response = $this->postJson('/api/v2/mutate', $this->socialInstitutionPayload());
        $response->assertStatus(403);
    }

    #[Test]
    public function testSocialInstitutionDirectUpdateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'socinst-crowd@example.com');
        $this->actingAs($user);
        $this->seedSocialInstitution();

        $response = $this->postJson('/api/v2/mutate', $this->socialInstitutionPayload());
        $response->assertStatus(403);
    }

    #[Test]
    public function testSocialInstitutionUpdateAcceptsAlias(): void {
        $user = $this->makeUser(email: 'socinst-alias@example.com');
        $this->actingAs($user);
        $this->seedSocialInstitution();

        $response = $this->postJson('/api/v2/mutate', $this->socialInstitutionPayload([
            'resource' => 'social_institution',
        ]));

        $response->assertOk()
            ->assertJson(['ok' => true, 'resource' => 'social_institutions']);
    }
}
