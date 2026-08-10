<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2DeleteStatusTest extends TestCase {
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

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'delete-status-tester@example.com'): User {
        return User::forceCreate([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function deletePayload(array $overrides = []): array {
        return array_replace_recursive([
            'resource' => 'statuses',
            'person_id' => 1000,
            'mode' => 'direct',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_sequence' => 1,
                    'c_status_code' => 50,
                ],
            ],
        ], $overrides);
    }

    // ── Direct Delete Tests ─────────────────────────────────

    #[Test]
    public function testDirectStatusDeleteSucceeds(): void {
        $user = $this->makeUser(email: 'delete-status-direct@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/delete', $this->deletePayload());

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'statuses',
                'mode' => 'direct',
                'operation' => 'delete',
                'result' => [
                    'pk' => [
                        'c_personid' => 1000,
                        'c_sequence' => 1,
                        'c_status_code' => 50,
                    ],
                ],
            ]);

        $data = $response->json();
        $this->assertNotNull($data['result']['operation_id']);

        $this->assertDatabaseMissing('STATUS_DATA', [
            'c_personid' => 1000,
            'c_sequence' => 1,
            'c_status_code' => 50,
        ]);
    }

    #[Test]
    public function testDirectStatusDeleteWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'delete-status-op@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $this->postJson('/api/v2/delete', $this->deletePayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'STATUS_DATA',
            'op_type' => Operation::TYPE_DELETE,
        ]);
    }

    #[Test]
    public function testDirectStatusDeleteWritesAuditLog(): void {
        $user = $this->makeUser(email: 'delete-status-audit@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $this->postJson('/api/v2/delete', $this->deletePayload());

        $audit = DB::table('audit_log')->where('table_name', 'STATUS_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('DELETE', $audit->operation);
        $this->assertNotNull($audit->old_data);
        $this->assertNull($audit->new_data);
    }

    // ── Proposal Delete Tests ───────────────────────────────

    #[Test]
    public function testProposalStatusDeleteWritesPendingProposal(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'delete-status-proposal@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/delete', $this->deletePayload([
            'mode' => 'proposal',
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'mode' => 'proposal',
                'operation' => 'delete',
                'result' => ['status' => 'proposal_deleted'],
            ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'STATUS_DATA',
            'op_type' => Operation::TYPE_PROPOSAL_DELETE,
        ]);
        $op = DB::table('operations')->where('op_type', Operation::TYPE_PROPOSAL_DELETE)->first();
        $payload = json_decode($op->resource_data, true);
        $this->assertSame('pending', $payload['__review_status']);

        // 目標列未被實際刪除
        $this->assertDatabaseHas('STATUS_DATA', [
            'c_personid' => 1000,
            'c_sequence' => 1,
            'c_status_code' => 50,
        ]);

        $this->assertSame(0, DB::table('audit_log')->where('table_name', 'STATUS_DATA')->where('operation', 'DELETE')->count());
    }

    // ── Error Cases ─────────────────────────────────────────

    #[Test]
    public function testDeleteTargetMissingReturns404(): void {
        $user = $this->makeUser(email: 'delete-status-404@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/delete', $this->deletePayload());

        $response->assertStatus(404);
    }

    #[Test]
    public function testDeleteWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'delete-status-mismatch@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/delete', $this->deletePayload([
            'person_id' => 9999,
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    // ── Auth / Permission Tests ─────────────────────────────

    #[Test]
    public function testDeleteRejectsUnauthenticatedUser(): void {
        $this->seedStatus();

        $response = $this->postJson('/api/v2/delete', $this->deletePayload());

        $response->assertStatus(401);
    }

    #[Test]
    public function testDeleteRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'delete-status-inactive@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/delete', $this->deletePayload());

        $response->assertStatus(403);
    }

    #[Test]
    public function testDirectDeleteRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'delete-status-crowd@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/delete', $this->deletePayload([
            'mode' => 'direct',
        ]));

        $response->assertStatus(403);
    }
}
