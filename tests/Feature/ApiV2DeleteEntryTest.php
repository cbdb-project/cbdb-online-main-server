<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2DeleteEntryTest extends TestCase {
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
            $table->integer('c_entry_nh_id')->nullable(); // 對齊真實欄名（2026_01_22 rename c_nianhao_id->c_entry_nh_id）
            $table->integer('c_entry_nh_year')->nullable();
            $table->integer('c_entry_range')->nullable();
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

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'delete-entry-tester@example.com'): User {
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
            'resource' => 'entries',
            'person_id' => 1000,
            'mode' => 'direct',
            'target' => [
                'pk' => [
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
                ],
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    // ── Direct Delete Tests ─────────────────────────────────

    #[Test]
    public function testDirectEntryDeleteSucceeds(): void {
        $user = $this->makeUser(email: 'delete-entry-direct@example.com');
        $this->actingAs($user);
        $this->seedEntry();

        $response = $this->postJson('/api/v2/delete', $this->deletePayload());

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'entries',
                'mode' => 'direct',
                'operation' => 'delete',
                'result' => [
                    'pk' => [
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
                    ],
                ],
            ]);

        $data = $response->json();
        $this->assertNotNull($data['result']['operation_id']);

        $this->assertDatabaseMissing('ENTRY_DATA', [
            'c_personid' => 1000,
            'c_entry_code' => 36,
            'c_sequence' => 1,
            'c_year' => 1057,
        ]);
    }

    #[Test]
    public function testDirectEntryDeleteWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'delete-entry-op@example.com');
        $this->actingAs($user);
        $this->seedEntry();

        $this->postJson('/api/v2/delete', $this->deletePayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'ENTRY_DATA',
            'op_type' => Operation::TYPE_DELETE,
        ]);
    }

    #[Test]
    public function testDirectEntryDeleteWritesAuditLog(): void {
        $user = $this->makeUser(email: 'delete-entry-audit@example.com');
        $this->actingAs($user);
        $this->seedEntry();

        $this->postJson('/api/v2/delete', $this->deletePayload());

        $audit = DB::table('audit_log')->where('table_name', 'ENTRY_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('DELETE', $audit->operation);
        $this->assertNotNull($audit->old_data);
        $this->assertNull($audit->new_data);
    }

    // ── Proposal Delete Tests ───────────────────────────────

    #[Test]
    public function testProposalEntryDeleteWritesPendingProposal(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'delete-entry-proposal@example.com');
        $this->actingAs($user);
        $this->seedEntry();

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
            'resource' => 'ENTRY_DATA',
            'op_type' => Operation::TYPE_PROPOSAL_DELETE,
        ]);
        $op = DB::table('operations')->where('op_type', Operation::TYPE_PROPOSAL_DELETE)->first();
        $payload = json_decode($op->resource_data, true);
        $this->assertSame('pending', $payload['__review_status']);

        // 目標列未被實際刪除
        $this->assertDatabaseHas('ENTRY_DATA', [
            'c_personid' => 1000,
            'c_entry_code' => 36,
            'c_sequence' => 1,
            'c_year' => 1057,
        ]);

        $this->assertSame(0, DB::table('audit_log')->where('table_name', 'ENTRY_DATA')->where('operation', 'DELETE')->count());
    }

    // ── Error Cases ─────────────────────────────────────────

    #[Test]
    public function testDeleteTargetMissingReturns404(): void {
        $user = $this->makeUser(email: 'delete-entry-404@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/delete', $this->deletePayload());

        $response->assertStatus(404);
    }

    #[Test]
    public function testDeleteWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'delete-entry-mismatch@example.com');
        $this->actingAs($user);
        $this->seedEntry();

        $response = $this->postJson('/api/v2/delete', $this->deletePayload([
            'person_id' => 9999,
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    #[Test]
    public function testDeleteRejectsUnauthenticatedUser(): void {
        $this->seedEntry();

        $response = $this->postJson('/api/v2/delete', $this->deletePayload());

        $response->assertStatus(401);
    }

    #[Test]
    public function testDeleteRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'delete-entry-inactive@example.com');
        $this->actingAs($user);
        $this->seedEntry();

        $response = $this->postJson('/api/v2/delete', $this->deletePayload());

        $response->assertStatus(403);
    }

    #[Test]
    public function testDirectDeleteRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'delete-entry-crowd@example.com');
        $this->actingAs($user);
        $this->seedEntry();

        $response = $this->postJson('/api/v2/delete', $this->deletePayload([
            'mode' => 'direct',
        ]));

        $response->assertStatus(403);
    }
}
