<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2CreateEventTest extends TestCase {
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
        $this->createEventsTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('EVENTS_DATA');
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

    protected function createEventsTable(): void {
        Schema::create('EVENTS_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_sequence')->default(0);
            $table->integer('c_event_code')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->integer('c_year')->nullable();
            $table->integer('c_month')->nullable();
            $table->integer('c_day')->nullable();
            $table->integer('c_day_ganzhi')->nullable();
            $table->integer('c_nh_code')->nullable();
            $table->integer('c_nh_year')->nullable();
            $table->integer('c_yr_range')->nullable();
            $table->integer('c_intercalary')->default(0);
            $table->integer('c_addr_id')->nullable();
            $table->longText('c_event')->nullable();
            $table->string('c_role', 255)->nullable();
            $table->string('c_created_by', 255)->nullable();
            $table->string('c_created_date', 255)->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->string('c_modified_date', 255)->nullable();
            $table->primary(['c_personid', 'c_sequence', 'c_event_code']);
        });
    }

    protected function seedEvent(array $overrides = []): void {
        DB::table('EVENTS_DATA')->insert(array_replace([
            'c_personid' => 1000,
            'c_sequence' => 1,
            'c_event_code' => 40,
            'c_source' => 10,
            'c_pages' => '1-5',
            'c_notes' => null,
        ], $overrides));
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'create-event-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function createPayload(array $overrides = []): array {
        return array_replace_recursive([
            'resource' => 'events',
            'person_id' => 1000,
            'mode' => 'direct',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_sequence' => 2,
                    'c_event_code' => 50,
                ],
            ],
            'changes' => [
                'c_source' => 20,
                'c_notes' => '新增事件',
            ],
        ], $overrides);
    }

    #[Test]
    public function testEventCreateRejectsAddrId(): void {
        // c_addr_id 屬 EVENTS_ADDR 副表，不得經 v2 單表寫入 EVENTS_DATA.c_addr_id；應以「不允許欄位」拒絕。
        $user = $this->makeUser(email: 'create-event-addr-reject@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_notes' => '新增事件', 'c_addr_id' => 123],
        ]))->assertStatus(422);
    }

    #[Test]
    public function testDirectEventCreatePersistsDayGanzhi(): void {
        // c_day_ganzhi 先前漏列於 create 白名單；已補回，確認新增可落庫（與 React EventEditor 農曆干支日對齊）。
        $user = $this->makeUser(email: 'create-event-ganzhi@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_notes' => '新增事件', 'c_day_ganzhi' => 9],
        ]));

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('EVENTS_DATA', [
            'c_personid' => 1000,
            'c_sequence' => 2,
            'c_event_code' => 50,
            'c_day_ganzhi' => 9,
        ]);
    }

    #[Test]
    public function testDirectEventCreateSucceeds(): void {
        $user = $this->makeUser(email: 'create-event-direct@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'events',
                'mode' => 'direct',
                'operation' => 'create',
            ]);

        $this->assertNotNull($response->json('result.operation_id'));
        $this->assertNotNull($response->json('result.row'));

        $this->assertDatabaseHas('EVENTS_DATA', [
            'c_personid' => 1000,
            'c_sequence' => 2,
            'c_event_code' => 50,
            'c_source' => 20,
            'c_notes' => '新增事件',
        ]);
    }

    #[Test]
    public function testDirectEventCreateWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'create-event-op@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'EVENTS_DATA',
            'op_type' => Operation::TYPE_CREATE,
        ]);
    }

    #[Test]
    public function testDirectEventCreateWritesAuditLog(): void {
        $user = $this->makeUser(email: 'create-event-audit@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload());

        $audit = DB::table('audit_log')->where('table_name', 'EVENTS_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('INSERT', $audit->operation);
    }

    #[Test]
    public function testProposalEventCreateSucceeds(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-event-proposal@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'proposal',
            'meta' => ['comment' => '提案新增事件'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'events',
                'mode' => 'proposal',
                'operation' => 'create',
                'result' => ['status' => 'proposal_created'],
            ]);

        $this->assertDatabaseMissing('EVENTS_DATA', [
            'c_personid' => 1000,
            'c_sequence' => 2,
            'c_event_code' => 50,
        ]);
    }

    #[Test]
    public function testProposalEventCreateWritesProposalMeta(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-event-prop-meta@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'proposal',
            'meta' => ['comment' => '提案新增事件'],
        ]));

        $operation = DB::table('operations')->where('resource', 'EVENTS_DATA')->first();
        $this->assertNotNull($operation);
        $this->assertSame(Operation::TYPE_PROPOSAL_CREATE, (int) $operation->op_type);

        $resourceData = json_decode($operation->resource_data, true);
        $this->assertSame('pending', $resourceData['__review_status']);
        $this->assertSame('create', $resourceData['__proposal_meta']['action']);
        $this->assertSame('events', $resourceData['__proposal_meta']['resource_type']);
        $this->assertSame('提案新增事件', $resourceData['__proposal_meta']['comment']);

        $this->assertDatabaseMissing('audit_log', ['table_name' => 'EVENTS_DATA']);
    }

    #[Test]
    public function testCreateDuplicateTargetPkReturns409(): void {
        $user = $this->makeUser(email: 'create-event-dup@example.com');
        $this->actingAs($user);

        $this->seedEvent([
            'c_personid' => 1000,
            'c_sequence' => 2,
            'c_event_code' => 50,
        ]);

        $this->postJson('/api/v2/create', $this->createPayload())
            ->assertStatus(409)->assertJson(['ok' => false]);
    }

    #[Test]
    public function testCreateDuplicatePendingProposalReturns409(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-event-dup-prop@example.com');
        $this->actingAs($user);

        $payload = $this->createPayload(['mode' => 'proposal']);

        $this->postJson('/api/v2/create', $payload)->assertOk();
        $this->postJson('/api/v2/create', $payload)
            ->assertStatus(409)
            ->assertJson(['ok' => false, 'errors' => ['target.pk' => ['pending_proposal_exists']]]);

        $this->assertSame(1, DB::table('operations')->where('resource', 'EVENTS_DATA')->count());
    }

    #[Test]
    public function testCreateWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'create-event-mismatch@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload(['person_id' => 9999]))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    #[Test]
    public function testCreateRejectsDisallowedField(): void {
        $user = $this->makeUser(email: 'create-event-disallowed@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_not_a_real_column' => 'x'],
        ]));

        $response->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertArrayHasKey('changes', $response->json('errors'));
        $this->assertDatabaseMissing('EVENTS_DATA', [
            'c_personid' => 1000,
            'c_sequence' => 2,
            'c_event_code' => 50,
        ]);
    }

    #[Test]
    public function testCreateRejectsUnauthenticatedUser(): void {
        $this->postJson('/api/v2/create', $this->createPayload())->assertStatus(401);
    }

    #[Test]
    public function testCreateRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'create-event-inactive@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload())->assertStatus(403);
    }

    #[Test]
    public function testDirectCreateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-event-crowd@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload(['mode' => 'direct']))->assertStatus(403);
    }
}
