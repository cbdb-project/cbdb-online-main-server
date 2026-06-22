<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2DeleteKinshipTest extends TestCase {
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
        $this->createKinTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('KIN_DATA');
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

    protected function createKinTable(): void {
        Schema::create('KIN_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_kin_id')->default(0);
            $table->integer('c_kin_code')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->primary(['c_personid', 'c_kin_id', 'c_kin_code']);
        });
    }

    protected function seedKin(array $overrides = []): void {
        DB::table('KIN_DATA')->insert(array_replace([
            'c_personid' => 1000,
            'c_kin_id' => 200,
            'c_kin_code' => 75,
            'c_source' => 10,
        ], $overrides));
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'delete-kin-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function deletePayload(array $overrides = []): array {
        return array_replace_recursive([
            'resource' => 'kinship',
            'person_id' => 1000,
            'mode' => 'direct',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_kin_id' => 200,
                    'c_kin_code' => 75,
                ],
            ],
        ], $overrides);
    }

    #[Test]
    public function testDirectKinshipDeleteSucceeds(): void {
        $user = $this->makeUser(email: 'delete-kin-direct@example.com');
        $this->actingAs($user);
        $this->seedKin();

        $response = $this->postJson('/api/v2/delete', $this->deletePayload());

        $response->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'kinship',
            'mode' => 'direct',
            'operation' => 'delete',
        ]);
        $this->assertNotNull($response->json('result.operation_id'));

        $this->assertDatabaseMissing('KIN_DATA', [
            'c_personid' => 1000,
            'c_kin_id' => 200,
            'c_kin_code' => 75,
        ]);
    }

    #[Test]
    public function testDirectKinshipDeleteWritesOperationAndAudit(): void {
        $user = $this->makeUser(email: 'delete-kin-op@example.com');
        $this->actingAs($user);
        $this->seedKin();

        $this->postJson('/api/v2/delete', $this->deletePayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'KIN_DATA',
            'op_type' => Operation::TYPE_DELETE,
        ]);
        $audit = DB::table('audit_log')->where('table_name', 'KIN_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('DELETE', $audit->operation);
    }

    #[Test]
    public function testProposalKinshipDeleteWritesPendingProposal(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'delete-kin-proposal@example.com');
        $this->actingAs($user);
        $this->seedKin();

        $this->postJson('/api/v2/delete', $this->deletePayload(['mode' => 'proposal']))
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'mode' => 'proposal',
                'operation' => 'delete',
                'result' => ['status' => 'proposal_deleted'],
            ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'KIN_DATA',
            'op_type' => Operation::TYPE_PROPOSAL_DELETE,
        ]);
        $op = DB::table('operations')->where('op_type', Operation::TYPE_PROPOSAL_DELETE)->first();
        $payload = json_decode($op->resource_data, true);
        $this->assertSame('pending', $payload['__review_status']);

        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 1000, 'c_kin_id' => 200, 'c_kin_code' => 75]);

        $this->assertSame(0, DB::table('audit_log')->where('table_name', 'KIN_DATA')->where('operation', 'DELETE')->count());
    }

    #[Test]
    public function testDeleteTargetMissingReturns404(): void {
        $user = $this->makeUser(email: 'delete-kin-404@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/delete', $this->deletePayload())->assertStatus(404);
    }

    #[Test]
    public function testDeleteWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'delete-kin-mismatch@example.com');
        $this->actingAs($user);
        $this->seedKin();

        $this->postJson('/api/v2/delete', $this->deletePayload(['person_id' => 9999]))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    #[Test]
    public function testDeleteRejectsUnauthenticatedUser(): void {
        $this->seedKin();
        $this->postJson('/api/v2/delete', $this->deletePayload())->assertStatus(401);
    }

    #[Test]
    public function testDeleteRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'delete-kin-inactive@example.com');
        $this->actingAs($user);
        $this->seedKin();

        $this->postJson('/api/v2/delete', $this->deletePayload())->assertStatus(403);
    }

    #[Test]
    public function testDirectDeleteRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'delete-kin-crowd@example.com');
        $this->actingAs($user);
        $this->seedKin();

        $this->postJson('/api/v2/delete', $this->deletePayload(['mode' => 'direct']))->assertStatus(403);
    }
}
