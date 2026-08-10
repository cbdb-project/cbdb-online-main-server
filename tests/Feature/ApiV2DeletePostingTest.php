<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2DeletePostingTest extends TestCase {
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
        $this->createPostingTables();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('POSTED_TO_ADDR_DATA');
        Schema::dropIfExists('POSTED_TO_OFFICE_DATA');
        Schema::dropIfExists('POSTING_DATA');
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

    protected function createPostingTables(): void {
        Schema::create('POSTING_DATA', function (Blueprint $table) {
            $table->integer('c_personid')->default(0);
            $table->integer('c_posting_id')->primary();
        });

        Schema::create('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
            $table->integer('c_personid')->default(0);
            $table->integer('c_posting_id')->default(0);
            $table->integer('c_office_id')->default(0);
            $table->integer('c_source')->default(0);
            $table->text('c_notes')->nullable();
            $table->primary(['c_office_id', 'c_posting_id']);
        });

        Schema::create('POSTED_TO_ADDR_DATA', function (Blueprint $table) {
            $table->integer('c_personid')->default(0);
            $table->integer('c_posting_id')->default(0);
            $table->integer('c_office_id')->default(0);
            $table->integer('c_addr_id')->default(0);
        });
    }

    protected function seedPosting(): void {
        DB::table('POSTING_DATA')->insert(['c_personid' => 1000, 'c_posting_id' => 2104406]);
        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => 1000,
            'c_posting_id' => 2104406,
            'c_office_id' => 87473,
            'c_source' => 10,
        ]);
        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => 1000,
            'c_posting_id' => 2104406,
            'c_office_id' => 87473,
            'c_addr_id' => 130,
        ]);
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'delete-post-tester@example.com'): User {
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
            'resource' => 'postings',
            'person_id' => 1000,
            'mode' => 'direct',
            'target' => ['pk' => ['c_office_id' => 87473, 'c_posting_id' => 2104406]],
        ], $overrides);
    }

    #[Test]
    public function testDirectPostingDeleteSucceedsAndRemovesSideTables(): void {
        $user = $this->makeUser(email: 'delete-post-direct@example.com');
        $this->actingAs($user);
        $this->seedPosting();

        $response = $this->postJson('/api/v2/delete', $this->deletePayload());

        $response->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'postings',
            'mode' => 'direct',
            'operation' => 'delete',
        ]);

        $this->assertDatabaseMissing('POSTED_TO_OFFICE_DATA', ['c_office_id' => 87473, 'c_posting_id' => 2104406]);
        $this->assertSame(0, DB::table('POSTED_TO_ADDR_DATA')->where('c_posting_id', 2104406)->count());
        $this->assertDatabaseMissing('POSTING_DATA', ['c_posting_id' => 2104406]);
    }

    #[Test]
    public function testDirectPostingDeleteWritesOperationAndAudit(): void {
        $user = $this->makeUser(email: 'delete-post-op@example.com');
        $this->actingAs($user);
        $this->seedPosting();

        $this->postJson('/api/v2/delete', $this->deletePayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'op_type' => Operation::TYPE_DELETE,
        ]);
        $audit = DB::table('audit_log')->where('table_name', 'POSTED_TO_OFFICE_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('DELETE', $audit->operation);
    }

    #[Test]
    public function testProposalPostingDeleteWritesPendingProposal(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'delete-post-proposal@example.com');
        $this->actingAs($user);
        $this->seedPosting();

        $this->postJson('/api/v2/delete', $this->deletePayload(['mode' => 'proposal']))
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'mode' => 'proposal',
                'operation' => 'delete',
                'result' => ['status' => 'proposal_deleted'],
            ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'op_type' => Operation::TYPE_PROPOSAL_DELETE,
        ]);
        $op = DB::table('operations')->where('op_type', Operation::TYPE_PROPOSAL_DELETE)->first();
        $payload = json_decode($op->resource_data, true);
        $this->assertSame('pending', $payload['__review_status']);

        // 主表與副表皆未被實際刪除（提案僅記錄，核准後才連帶刪除）
        $this->assertDatabaseHas('POSTED_TO_OFFICE_DATA', ['c_office_id' => 87473, 'c_posting_id' => 2104406]);
        $this->assertGreaterThan(0, DB::table('POSTED_TO_ADDR_DATA')->where('c_posting_id', 2104406)->count());

        $this->assertSame(0, DB::table('audit_log')->where('table_name', 'POSTED_TO_OFFICE_DATA')->where('operation', 'DELETE')->count());
    }

    #[Test]
    public function testDeleteTargetMissingReturns404(): void {
        $user = $this->makeUser(email: 'delete-post-404@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/delete', $this->deletePayload())->assertStatus(404);
    }

    #[Test]
    public function testDeleteWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'delete-post-mismatch@example.com');
        $this->actingAs($user);
        $this->seedPosting();

        $this->postJson('/api/v2/delete', $this->deletePayload(['person_id' => 9999]))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);

        $this->assertDatabaseHas('POSTED_TO_OFFICE_DATA', ['c_office_id' => 87473, 'c_posting_id' => 2104406]);
    }

    #[Test]
    public function testDeleteRejectsUnauthenticatedUser(): void {
        $this->seedPosting();
        $this->postJson('/api/v2/delete', $this->deletePayload())->assertStatus(401);
    }

    #[Test]
    public function testDeleteRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'delete-post-inactive@example.com');
        $this->actingAs($user);
        $this->seedPosting();

        $this->postJson('/api/v2/delete', $this->deletePayload())->assertStatus(403);
    }

    #[Test]
    public function testDirectDeleteRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'delete-post-crowd@example.com');
        $this->actingAs($user);
        $this->seedPosting();

        $this->postJson('/api/v2/delete', $this->deletePayload(['mode' => 'direct']))->assertStatus(403);
    }
}
