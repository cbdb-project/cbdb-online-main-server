<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2CreateBiogMainTest extends TestCase {
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
        $this->createPinyinTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('pinyin');
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
            $table->string('c_name_proper')->nullable();
            $table->string('c_name_rm')->nullable();
            $table->string('c_surname_chn')->nullable();
            $table->string('c_mingzi_chn')->nullable();
            $table->string('c_surname')->nullable();
            $table->string('c_mingzi')->nullable();
            $table->string('c_surname_proper')->nullable();
            $table->string('c_mingzi_proper')->nullable();
            $table->string('c_surname_rm')->nullable();
            $table->string('c_mingzi_rm')->nullable();
            $table->integer('c_female')->nullable();
            $table->integer('c_by_intercalary')->default(0);
            $table->integer('c_dy_intercalary')->default(0);
            $table->integer('c_index_year')->nullable();
            $table->integer('c_dy')->nullable();
            $table->integer('c_death_age')->nullable();
            $table->text('c_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });
    }

    protected function createPinyinTable(): void {
        Schema::create('pinyin', function (Blueprint $table) {
            $table->increments('id');
            $table->string('lastname_chn')->nullable();
            $table->string('lastname_pinyin')->nullable();
        });

        // 提供常見姓氏拼音，讓 auto_pinyin 能拆出姓名
        DB::table('pinyin')->insert([
            ['lastname_chn' => '張', 'lastname_pinyin' => 'zhang'],
            ['lastname_chn' => '李', 'lastname_pinyin' => 'li'],
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'create-biog-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function seedBiogMain(int $personId = 1000, array $overrides = []): void {
        DB::table('BIOG_MAIN')->insert(array_replace([
            'c_personid' => $personId,
            'c_name_chn' => '既有人物',
            'c_name' => 'Existing',
            'c_created_by' => 'seed',
            'c_created_date' => now(),
        ], $overrides));
    }

    protected function createPayload(array $overrides = []): array {
        return array_replace_recursive([
            'resource' => 'basicinformation',
            'person_id' => 2000,
            'mode' => 'direct',
            'target' => [
                'pk' => [
                    'c_personid' => 2000,
                ],
            ],
            'changes' => [
                'c_personid' => 2000,
                'c_name_chn' => '張三',
                'c_female' => 0,
                'c_index_year' => 1050,
            ],
        ], $overrides);
    }

    // ── Direct Create Tests ─────────────────────────────────

    #[Test]
    public function testDirectBiogMainCreateSucceeds(): void {
        $user = $this->makeUser(email: 'create-biog-direct@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'basicinformation',
                'mode' => 'direct',
                'operation' => 'create',
                'result' => [
                    'pk' => ['c_personid' => 2000],
                ],
            ]);

        $this->assertNotNull($response->json('result.row'));

        $this->assertDatabaseHas('BIOG_MAIN', [
            'c_personid' => 2000,
            'c_name_chn' => '張三',
        ]);
    }

    #[Test]
    public function testDirectBiogMainCreateWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'create-biog-op@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'BIOG_MAIN',
            'c_personid' => 2000,
            'op_type' => Operation::TYPE_CREATE,
        ]);
    }

    #[Test]
    public function testDirectBiogMainCreateWritesAuditLog(): void {
        $user = $this->makeUser(email: 'create-biog-audit@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload());

        $audit = DB::table('audit_log')->where('table_name', 'BIOG_MAIN')->first();
        $this->assertNotNull($audit);
        $this->assertSame('INSERT', $audit->operation);
    }

    #[Test]
    public function testDirectBiogMainCreateSucceedsWhenFtsTableMissing(): void {
        // CBDB__NAME_FTS 不存在時不應崩潰（reindex 被跳過）
        $this->assertFalse(Schema::hasTable('CBDB__NAME_FTS'));

        $user = $this->makeUser(email: 'create-biog-nofts@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertOk()->assertJson(['ok' => true]);
    }

    // ── Validation Error Cases ──────────────────────────────

    #[Test]
    public function testCreateRejectsDuplicatePersonId(): void {
        $user = $this->makeUser(email: 'create-biog-dup@example.com');
        $this->actingAs($user);
        $this->seedBiogMain(2000);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['c_personid' => ['exists']]]);
    }

    #[Test]
    public function testCreateRejectsZeroPersonId(): void {
        $user = $this->makeUser(email: 'create-biog-zero@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'person_id' => 0,
            'target' => ['pk' => ['c_personid' => 0]],
            'changes' => ['c_personid' => 0],
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['c_personid' => ['required']]]);
    }

    #[Test]
    public function testCreateRejectsTooLargePersonId(): void {
        $user = $this->makeUser(email: 'create-biog-large@example.com');
        $this->actingAs($user);
        // 既有最大 personid = 1000；2000 - 1000 <= 10000 OK，故設定一筆讓 max 很小
        $this->seedBiogMain(5);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'person_id' => 50000,
            'target' => ['pk' => ['c_personid' => 50000]],
            'changes' => ['c_personid' => 50000, 'c_name_chn' => '張三'],
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['c_personid' => ['too_large']]]);
    }

    #[Test]
    public function testCreateRejectsPersonIdMismatch(): void {
        $user = $this->makeUser(email: 'create-biog-mismatch@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'person_id' => 9999,
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    // ── Proposal Mode (501) ─────────────────────────────────

    #[Test]
    public function testProposalModeReturns501(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-biog-proposal@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'proposal',
        ]));

        $response->assertStatus(501)
            ->assertJson(['ok' => false]);
    }

    // ── Auth / Permission Tests ─────────────────────────────

    #[Test]
    public function testCreateRejectsUnauthenticatedUser(): void {
        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertStatus(401);
    }

    #[Test]
    public function testCreateRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'create-biog-inactive@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertStatus(403);
    }

    #[Test]
    public function testDirectCreateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-biog-crowd@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'direct',
        ]));

        $response->assertStatus(403);
    }
}
