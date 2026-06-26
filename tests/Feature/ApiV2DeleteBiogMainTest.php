<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2DeleteBiogMainTest extends TestCase {
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
        $this->createBiogMainTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('BIOG_MAIN');
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

    protected function createBiogMainTable(): void {
        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
            $table->string('c_surname_chn')->nullable();
            $table->string('c_mingzi_chn')->nullable();
            $table->integer('c_female')->nullable();
            $table->integer('c_index_year')->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });
    }

    // ── Helpers ──────────────────────────────────────────────

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'delete-biog-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function seedBiogMain(int $personId = 3000, array $overrides = []): void {
        DB::table('BIOG_MAIN')->insert(array_replace([
            'c_personid' => $personId,
            'c_name_chn' => '張三',
            'c_name' => 'Zhang San',
            'c_surname_chn' => '張',
            'c_mingzi_chn' => '三',
            'c_female' => 0,
            'c_index_year' => 1050,
            'c_created_by' => 'seed',
            'c_created_date' => now(),
        ], $overrides));
    }

    protected function deletePayload(array $overrides = []): array {
        return array_replace_recursive([
            'resource' => 'basicinformation',
            'person_id' => 3000,
            'mode' => 'direct',
            'target' => [
                'pk' => [
                    'c_personid' => 3000,
                ],
            ],
        ], $overrides);
    }

    // ── Direct Delete (Soft Delete) Tests ───────────────────

    #[Test]
    public function testDirectBiogMainDeleteSoftDeletesRow(): void {
        $user = $this->makeUser(email: 'delete-biog-direct@example.com');
        $this->actingAs($user);
        $this->seedBiogMain(3000);

        $response = $this->postJson('/api/v2/delete', $this->deletePayload());

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'basicinformation',
                'mode' => 'direct',
                'operation' => 'delete',
                'result' => [
                    'pk' => ['c_personid' => 3000],
                ],
            ]);

        // 軟刪除：原列仍在，c_name_chn 變為標記
        $this->assertDatabaseHas('BIOG_MAIN', [
            'c_personid' => 3000,
            'c_name_chn' => '<待删除>',
        ]);

        // 列數不變（非真刪除）
        $this->assertSame(1, DB::table('BIOG_MAIN')->where('c_personid', 3000)->count());

        // 其他欄位不受影響
        $row = DB::table('BIOG_MAIN')->where('c_personid', 3000)->first();
        $this->assertSame('張', $row->c_surname_chn);
    }

    #[Test]
    public function testDirectBiogMainDeleteWritesOperationWithTypeDelete(): void {
        $user = $this->makeUser(email: 'delete-biog-op@example.com');
        $this->actingAs($user);
        $this->seedBiogMain(3000);

        $this->postJson('/api/v2/delete', $this->deletePayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'BIOG_MAIN',
            'c_personid' => 3000,
            'op_type' => Operation::TYPE_DELETE,
        ]);
    }

    #[Test]
    public function testDirectBiogMainDeleteWritesAuditLogAsUpdate(): void {
        $user = $this->makeUser(email: 'delete-biog-audit@example.com');
        $this->actingAs($user);
        $this->seedBiogMain(3000);

        $this->postJson('/api/v2/delete', $this->deletePayload());

        $audit = DB::table('audit_log')->where('table_name', 'BIOG_MAIN')->first();
        $this->assertNotNull($audit);
        // 軟刪除底層是 UPDATE
        $this->assertSame('UPDATE', $audit->operation);

        $oldData = json_decode($audit->old_data, true);
        $newData = json_decode($audit->new_data, true);
        $this->assertSame('張三', $oldData['c_name_chn']);
        $this->assertSame('<待删除>', $newData['c_name_chn']);
    }

    #[Test]
    public function testDirectBiogMainDeleteSucceedsWhenFtsTableMissing(): void {
        $this->assertFalse(Schema::hasTable('CBDB__NAME_FTS'));

        $user = $this->makeUser(email: 'delete-biog-nofts@example.com');
        $this->actingAs($user);
        $this->seedBiogMain(3000);

        $response = $this->postJson('/api/v2/delete', $this->deletePayload());

        $response->assertOk()->assertJson(['ok' => true]);
    }

    // ── Error Cases ─────────────────────────────────────────

    #[Test]
    public function testDeleteNonExistentPersonReturns404(): void {
        $user = $this->makeUser(email: 'delete-biog-404@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/delete', $this->deletePayload());

        $response->assertStatus(404)->assertJson(['ok' => false]);
    }

    #[Test]
    public function testDeleteRejectsPersonIdMismatch(): void {
        $user = $this->makeUser(email: 'delete-biog-mismatch@example.com');
        $this->actingAs($user);
        $this->seedBiogMain(3000);

        $response = $this->postJson('/api/v2/delete', $this->deletePayload([
            'person_id' => 9999,
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    // ── Proposal Mode (501) ─────────────────────────────────

    #[Test]
    public function testProposalModeReturns501(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'delete-biog-proposal@example.com');
        $this->actingAs($user);
        $this->seedBiogMain(3000);

        $response = $this->postJson('/api/v2/delete', $this->deletePayload([
            'mode' => 'proposal',
        ]));

        $response->assertStatus(501)->assertJson(['ok' => false]);
    }

    // ── Auth / Permission Tests ─────────────────────────────

    #[Test]
    public function testDeleteRejectsUnauthenticatedUser(): void {
        $this->seedBiogMain(3000);

        $response = $this->postJson('/api/v2/delete', $this->deletePayload());

        $response->assertStatus(401);
    }

    #[Test]
    public function testDeleteRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'delete-biog-inactive@example.com');
        $this->actingAs($user);
        $this->seedBiogMain(3000);

        $response = $this->postJson('/api/v2/delete', $this->deletePayload());

        $response->assertStatus(403);
    }

    #[Test]
    public function testDirectDeleteRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'delete-biog-crowd@example.com');
        $this->actingAs($user);
        $this->seedBiogMain(3000);

        $response = $this->postJson('/api/v2/delete', $this->deletePayload([
            'mode' => 'direct',
        ]));

        $response->assertStatus(403);
    }
}
