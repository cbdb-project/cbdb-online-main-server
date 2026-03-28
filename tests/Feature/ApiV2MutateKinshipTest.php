<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2MutateKinshipTest extends TestCase {
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
        $this->createKinshipTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('KIN_DATA');
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

    protected function createKinshipTable(): void {
        Schema::create('KIN_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_kin_id')->default(0);
            $table->integer('c_kin_code')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->text('c_supplement')->nullable();
            $table->primary(['c_personid', 'c_kin_id', 'c_kin_code']);
        });
    }

    // ── Helpers ──────────────────────────────────────────────

    protected function seedKinship(array $overrides = []): void {
        DB::table('KIN_DATA')->insert(array_replace([
            'c_personid' => 1000,
            'c_kin_id' => 2000,
            'c_kin_code' => 72,
            'c_source' => 10,
            'c_pages' => '1-5',
            'c_notes' => null,
        ], $overrides));
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'kin-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function kinshipPayload(array $overrides = []): array {
        $payload = [
            'resource' => 'kinship',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_kin_id' => 2000,
                    'c_kin_code' => 72,
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
    public function testDirectKinshipUpdateSucceeds(): void {
        $user = $this->makeUser(email: 'kin-direct@example.com');
        $this->actingAs($user);
        $this->seedKinship();

        $response = $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'changes' => ['c_notes' => '更新備註', 'c_pages' => '10-20'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'kinship',
                'mode' => 'direct',
                'operation' => 'update',
                'result' => [
                    'pk' => [
                        'c_personid' => 1000,
                        'c_kin_id' => 2000,
                        'c_kin_code' => 72,
                    ],
                    'updated_fields' => ['c_notes', 'c_pages'],
                ],
            ]);

        $this->assertDatabaseHas('KIN_DATA', [
            'c_personid' => 1000,
            'c_notes' => '更新備註',
            'c_pages' => '10-20',
        ]);
    }

    #[Test]
    public function testDirectKinshipUpdateReturnsOperationIdAndRow(): void {
        $user = $this->makeUser(email: 'kin-result@example.com');
        $this->actingAs($user);
        $this->seedKinship();

        $response = $this->postJson('/api/v2/mutate', $this->kinshipPayload());
        $data = $response->json();

        $this->assertNotNull($data['result']['operation_id']);
        $this->assertNotNull($data['result']['row']);
        $this->assertSame('新的備註', $data['result']['row']['c_notes']);
    }

    #[Test]
    public function testDirectKinshipUpdateWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'kin-op@example.com');
        $this->actingAs($user);
        $this->seedKinship();

        $this->postJson('/api/v2/mutate', $this->kinshipPayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'KIN_DATA',
            'op_type' => Operation::TYPE_UPDATE,
        ]);
    }

    #[Test]
    public function testDirectKinshipUpdateWritesAuditLog(): void {
        $user = $this->makeUser(email: 'kin-audit@example.com');
        $this->actingAs($user);
        $this->seedKinship();

        $this->postJson('/api/v2/mutate', $this->kinshipPayload());

        $audit = DB::table('audit_log')->where('table_name', 'KIN_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('UPDATE', $audit->operation);
    }

    // ── Proposal Update Tests ───────────────────────────────

    #[Test]
    public function testProposalKinshipUpdateCreatesPendingOperation(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'kin-proposal@example.com');
        $this->actingAs($user);
        $this->seedKinship();

        $response = $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'mode' => 'proposal',
            'changes' => ['c_notes' => '提案備註'],
            'meta' => ['comment' => '提案修改備註'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'kinship',
                'mode' => 'proposal',
                'operation' => 'update',
                'result' => [
                    'updated_fields' => ['c_notes'],
                    'status' => 'proposal_updated',
                ],
            ]);

        // 原始資料未被修改
        $this->assertDatabaseHas('KIN_DATA', [
            'c_personid' => 1000,
            'c_notes' => null,
        ]);

        // 已寫入提案操作紀錄
        $this->assertDatabaseHas('operations', [
            'resource' => 'KIN_DATA',
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
        ]);

        $operation = DB::table('operations')->where('resource', 'KIN_DATA')->first();
        $resourceData = json_decode($operation->resource_data, true);

        $this->assertSame('pending', $resourceData['__review_status']);
        $this->assertSame('update', $resourceData['__proposal_meta']['action']);
        $this->assertSame('kinship', $resourceData['__proposal_meta']['resource_type']);
        $this->assertSame('提案修改備註', $resourceData['__proposal_meta']['comment']);

        // 無 audit_log
        $this->assertDatabaseMissing('audit_log', ['table_name' => 'KIN_DATA']);
    }

    // ── Error Cases ─────────────────────────────────────────

    #[Test]
    public function testKinshipUpdateWithInvalidPkReturns404(): void {
        $user = $this->makeUser(email: 'kin-404@example.com');
        $this->actingAs($user);
        $this->seedKinship();

        $response = $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'target' => ['pk' => [
                'c_personid' => 1000,
                'c_kin_id' => 999,
                'c_kin_code' => 72,
            ]],
        ]));

        $response->assertStatus(404);
    }

    #[Test]
    public function testKinshipUpdateWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'kin-mismatch@example.com');
        $this->actingAs($user);
        $this->seedKinship();

        $response = $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'person_id' => 9999,
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    #[Test]
    public function testKinshipUpdateWithEmptyChangesReturns422(): void {
        $user = $this->makeUser(email: 'kin-empty@example.com');
        $this->actingAs($user);
        $this->seedKinship();

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'kinship',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_kin_id' => 2000,
                    'c_kin_code' => 72,
                ],
            ],
            'changes' => [],
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function testKinshipUpdateWithDisallowedFieldReturns422(): void {
        $user = $this->makeUser(email: 'kin-disallowed@example.com');
        $this->actingAs($user);
        $this->seedKinship();

        $response = $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'changes' => ['c_personid' => 9999],
        ]));

        $response->assertStatus(422);
        $this->assertStringContainsString('不允許', $response->json('message'));
    }

    #[Test]
    public function testKinshipUpdateWithNoEffectiveChangeReturns422(): void {
        $user = $this->makeUser(email: 'kin-nochange@example.com');
        $this->actingAs($user);
        $this->seedKinship(['c_notes' => '已有備註']);

        $response = $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'changes' => ['c_notes' => '已有備註'],
        ]));

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'errors' => ['changes' => ['no_effective_changes']],
            ]);
    }

    #[Test]
    public function testKinshipUpdateRejectsUnauthenticatedUser(): void {
        $this->seedKinship();
        $response = $this->postJson('/api/v2/mutate', $this->kinshipPayload());
        $response->assertStatus(401);
    }

    #[Test]
    public function testKinshipUpdateRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'kin-inactive@example.com');
        $this->actingAs($user);
        $this->seedKinship();

        $response = $this->postJson('/api/v2/mutate', $this->kinshipPayload());
        $response->assertStatus(403);
    }

    #[Test]
    public function testKinshipDirectUpdateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'kin-crowd@example.com');
        $this->actingAs($user);
        $this->seedKinship();

        $response = $this->postJson('/api/v2/mutate', $this->kinshipPayload());
        $response->assertStatus(403);
    }

    #[Test]
    public function testKinshipUpdateAcceptsAlias(): void {
        $user = $this->makeUser(email: 'kin-alias@example.com');
        $this->actingAs($user);
        $this->seedKinship();

        $response = $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'resource' => 'kin',
        ]));

        $response->assertOk()
            ->assertJson(['ok' => true, 'resource' => 'kinship']);
    }
}
