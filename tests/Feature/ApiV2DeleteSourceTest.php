<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2DeleteSourceTest extends TestCase {
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
        $this->createSourceTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('BIOG_SOURCE_DATA');
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

    protected function createSourceTable(): void {
        Schema::create('BIOG_SOURCE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_textid')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->integer('c_source')->default(0);
            $table->text('c_notes')->nullable();
            $table->primary(['c_personid', 'c_textid', 'c_pages']);
        });
    }

    protected function seedSource(array $overrides = []): void {
        DB::table('BIOG_SOURCE_DATA')->insert(array_replace([
            'c_personid' => 1000,
            'c_textid' => 500,
            'c_pages' => '12-15',
            'c_source' => 10,
        ], $overrides));
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'delete-source-tester@example.com'): User {
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
            'resource' => 'sources',
            'person_id' => 1000,
            'mode' => 'direct',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_textid' => 500,
                    'c_pages' => '12-15',
                ],
            ],
        ], $overrides);
    }

    #[Test]
    public function testDirectSourceDeleteSucceeds(): void {
        $user = $this->makeUser(email: 'delete-source-direct@example.com');
        $this->actingAs($user);
        $this->seedSource();

        $response = $this->postJson('/api/v2/delete', $this->deletePayload());

        $response->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'sources',
            'mode' => 'direct',
            'operation' => 'delete',
        ]);
        $this->assertNotNull($response->json('result.operation_id'));

        $this->assertDatabaseMissing('BIOG_SOURCE_DATA', [
            'c_personid' => 1000,
            'c_textid' => 500,
            'c_pages' => '12-15',
        ]);
    }

    #[Test]
    public function testDirectSourceDeleteWithEmptyPagesSucceeds(): void {
        // c_pages 空字串為 create/update 的 canonical 形式（BiogSourceRepository::normalizePk）。
        // 省略 c_pages（→ middleware 轉 null → normalizeTargetPk 轉 ''）應命中存為 '' 的記錄，確保 round-trip。
        $user = $this->makeUser(email: 'delete-source-emptypages@example.com');
        $this->actingAs($user);
        $this->seedSource(['c_pages' => '', 'c_textid' => 700]);

        $payload = $this->deletePayload();
        $payload['target']['pk'] = ['c_personid' => 1000, 'c_textid' => 700];

        $response = $this->postJson('/api/v2/delete', $payload);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(0, DB::table('BIOG_SOURCE_DATA')->where('c_textid', 700)->count());
    }

    #[Test]
    public function testDirectSourceDeleteWritesOperationAndAudit(): void {
        $user = $this->makeUser(email: 'delete-source-op@example.com');
        $this->actingAs($user);
        $this->seedSource();

        $this->postJson('/api/v2/delete', $this->deletePayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'BIOG_SOURCE_DATA',
            'op_type' => Operation::TYPE_DELETE,
        ]);
        $audit = DB::table('audit_log')->where('table_name', 'BIOG_SOURCE_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('DELETE', $audit->operation);
    }

    #[Test]
    public function testProposalSourceDeleteWritesPendingProposal(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'delete-source-proposal@example.com');
        $this->actingAs($user);
        $this->seedSource();

        $this->postJson('/api/v2/delete', $this->deletePayload(['mode' => 'proposal']))
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'mode' => 'proposal',
                'operation' => 'delete',
                'result' => ['status' => 'proposal_deleted'],
            ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'BIOG_SOURCE_DATA',
            'op_type' => Operation::TYPE_PROPOSAL_DELETE,
        ]);
        $op = DB::table('operations')->where('op_type', Operation::TYPE_PROPOSAL_DELETE)->first();
        $payload = json_decode($op->resource_data, true);
        $this->assertSame('pending', $payload['__review_status']);

        $this->assertDatabaseHas('BIOG_SOURCE_DATA', ['c_personid' => 1000, 'c_textid' => 500, 'c_pages' => '12-15']);

        $this->assertSame(0, DB::table('audit_log')->where('table_name', 'BIOG_SOURCE_DATA')->where('operation', 'DELETE')->count());
    }

    #[Test]
    public function testDeleteTargetMissingReturns404(): void {
        $user = $this->makeUser(email: 'delete-source-404@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/delete', $this->deletePayload())->assertStatus(404);
    }

    #[Test]
    public function testDeleteWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'delete-source-mismatch@example.com');
        $this->actingAs($user);
        $this->seedSource();

        $this->postJson('/api/v2/delete', $this->deletePayload(['person_id' => 9999]))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    #[Test]
    public function testDeleteRejectsUnauthenticatedUser(): void {
        $this->seedSource();
        $this->postJson('/api/v2/delete', $this->deletePayload())->assertStatus(401);
    }

    #[Test]
    public function testDeleteRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'delete-source-inactive@example.com');
        $this->actingAs($user);
        $this->seedSource();

        $this->postJson('/api/v2/delete', $this->deletePayload())->assertStatus(403);
    }

    #[Test]
    public function testDirectDeleteRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'delete-source-crowd@example.com');
        $this->actingAs($user);
        $this->seedSource();

        $this->postJson('/api/v2/delete', $this->deletePayload(['mode' => 'direct']))->assertStatus(403);
    }
}
