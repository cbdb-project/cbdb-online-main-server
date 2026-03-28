<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2DeleteAddressTest extends TestCase {
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

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'delete-addr-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function deletePayload(array $overrides = []): array {
        $payload = [
            'resource' => 'addresses',
            'person_id' => 1000,
            'mode' => 'direct',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_addr_id' => 100,
                    'c_addr_type' => 1,
                    'c_sequence' => 1,
                ],
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    // ── Direct Delete Tests ─────────────────────────────────

    #[Test]
    public function testDirectAddressDeleteSucceeds(): void {
        $user = $this->makeUser(email: 'delete-addr-direct@example.com');
        $this->actingAs($user);
        $this->seedAddress();

        $response = $this->postJson('/api/v2/delete', $this->deletePayload());

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'addresses',
                'mode' => 'direct',
                'operation' => 'delete',
                'result' => [
                    'pk' => [
                        'c_personid' => 1000,
                        'c_addr_id' => 100,
                        'c_addr_type' => 1,
                        'c_sequence' => 1,
                    ],
                ],
            ]);

        $data = $response->json();
        $this->assertNotNull($data['result']['operation_id']);

        $this->assertDatabaseMissing('BIOG_ADDR_DATA', [
            'c_personid' => 1000,
            'c_addr_id' => 100,
            'c_addr_type' => 1,
            'c_sequence' => 1,
        ]);
    }

    #[Test]
    public function testDirectAddressDeleteWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'delete-addr-op@example.com');
        $this->actingAs($user);
        $this->seedAddress();

        $this->postJson('/api/v2/delete', $this->deletePayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'BIOG_ADDR_DATA',
            'op_type' => Operation::TYPE_DELETE,
        ]);
    }

    #[Test]
    public function testDirectAddressDeleteWritesAuditLog(): void {
        $user = $this->makeUser(email: 'delete-addr-audit@example.com');
        $this->actingAs($user);
        $this->seedAddress();

        $this->postJson('/api/v2/delete', $this->deletePayload());

        $audit = DB::table('audit_log')->where('table_name', 'BIOG_ADDR_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('DELETE', $audit->operation);
        $this->assertNotNull($audit->old_data);
        $this->assertNull($audit->new_data);
    }

    // ── Proposal Delete Tests ───────────────────────────────

    #[Test]
    public function testProposalAddressDeleteReturns501(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'delete-addr-proposal@example.com');
        $this->actingAs($user);
        $this->seedAddress();

        $response = $this->postJson('/api/v2/delete', $this->deletePayload([
            'mode' => 'proposal',
        ]));

        $response->assertStatus(501)
            ->assertJson([
                'ok' => false,
                'errors' => [
                    'mode' => 'proposal',
                    'operation' => 'delete',
                ],
            ]);

        // 原始資料未被刪除
        $this->assertDatabaseHas('BIOG_ADDR_DATA', [
            'c_personid' => 1000,
            'c_addr_id' => 100,
            'c_addr_type' => 1,
            'c_sequence' => 1,
        ]);
    }

    // ── Error Cases ─────────────────────────────────────────

    #[Test]
    public function testDeleteTargetMissingReturns404(): void {
        $user = $this->makeUser(email: 'delete-addr-404@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/delete', $this->deletePayload());

        $response->assertStatus(404);
    }

    #[Test]
    public function testDeleteWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'delete-addr-mismatch@example.com');
        $this->actingAs($user);
        $this->seedAddress();

        $response = $this->postJson('/api/v2/delete', $this->deletePayload([
            'person_id' => 9999,
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    #[Test]
    public function testDeleteRejectsUnauthenticatedUser(): void {
        $this->seedAddress();

        $response = $this->postJson('/api/v2/delete', $this->deletePayload());

        $response->assertStatus(401);
    }

    #[Test]
    public function testDeleteRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'delete-addr-inactive@example.com');
        $this->actingAs($user);
        $this->seedAddress();

        $response = $this->postJson('/api/v2/delete', $this->deletePayload());

        $response->assertStatus(403);
    }

    #[Test]
    public function testDirectDeleteRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'delete-addr-crowd@example.com');
        $this->actingAs($user);
        $this->seedAddress();

        $response = $this->postJson('/api/v2/delete', $this->deletePayload([
            'mode' => 'direct',
        ]));

        $response->assertStatus(403);
    }
}
