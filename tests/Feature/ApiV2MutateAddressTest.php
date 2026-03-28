<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            $table->integer('c_fy_nh_code')->nullable();
            $table->integer('c_fy_nh_year')->nullable();
            $table->integer('c_fy_range')->nullable();
            $table->integer('c_fy_intercalary')->default(0);
            $table->integer('c_ly_nh_code')->nullable();
            $table->integer('c_ly_nh_year')->nullable();
            $table->integer('c_ly_range')->nullable();
            $table->integer('c_ly_intercalary')->default(0);
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
