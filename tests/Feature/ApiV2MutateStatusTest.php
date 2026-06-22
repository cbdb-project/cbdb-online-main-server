<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2MutateStatusTest extends TestCase {
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

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'status-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function statusPayload(array $overrides = []): array {
        $payload = [
            'resource' => 'statuses',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_sequence' => 1,
                    'c_status_code' => 50,
                ],
            ],
            'changes' => [
                'c_notes' => '新的備註',
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    // ── Direct Update Tests ─────────────────────────────────

    #[Test]
    public function testDirectStatusUpdateSucceeds(): void {
        $user = $this->makeUser(email: 'status-direct@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/mutate', $this->statusPayload([
            'changes' => ['c_notes' => '更新備註', 'c_pages' => '10-20'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'statuses',
                'mode' => 'direct',
                'operation' => 'update',
                'result' => [
                    'pk' => [
                        'c_personid' => 1000,
                        'c_sequence' => 1,
                        'c_status_code' => 50,
                    ],
                    'updated_fields' => ['c_notes', 'c_pages'],
                ],
            ]);

        $this->assertDatabaseHas('STATUS_DATA', [
            'c_personid' => 1000,
            'c_notes' => '更新備註',
            'c_pages' => '10-20',
        ]);
    }

    #[Test]
    public function testDirectStatusUpdatePersistsSupplementAndDoesNotNullOtherFields(): void {
        // 回歸（Task 27）：補欄 c_supplement 必須能寫入；且只改單一欄位時，未送出的欄位
        // （c_supplement / c_firstyear）不可被清成 null —— 防護「保存即清空」資料流失 bug。
        $user = $this->makeUser(email: 'status-supplement@example.com');
        $this->actingAs($user);
        $this->seedStatus(['c_supplement' => '原始補充', 'c_firstyear' => 1050]);

        // (a) 直接更新 c_supplement 應成功寫入。
        $this->postJson('/api/v2/mutate', $this->statusPayload([
            'changes' => ['c_supplement' => '新補充'],
        ]))->assertOk();
        $this->assertDatabaseHas('STATUS_DATA', [
            'c_personid' => 1000, 'c_sequence' => 1, 'c_status_code' => 50,
            'c_supplement' => '新補充', 'c_firstyear' => 1050,
        ]);

        // (b) 只改 c_notes（payload 不含 c_supplement/c_firstyear）後，兩者仍保留、未被清空。
        $this->postJson('/api/v2/mutate', $this->statusPayload([
            'changes' => ['c_notes' => '只改備註'],
        ]))->assertOk();
        $this->assertDatabaseHas('STATUS_DATA', [
            'c_personid' => 1000, 'c_sequence' => 1, 'c_status_code' => 50,
            'c_notes' => '只改備註', 'c_supplement' => '新補充', 'c_firstyear' => 1050,
        ]);
    }

    #[Test]
    public function testDirectStatusUpdateReturnsOperationIdAndRow(): void {
        $user = $this->makeUser(email: 'status-result@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/mutate', $this->statusPayload());
        $data = $response->json();

        $this->assertNotNull($data['result']['operation_id']);
        $this->assertNotNull($data['result']['row']);
        $this->assertSame('新的備註', $data['result']['row']['c_notes']);
    }

    #[Test]
    public function testDirectStatusUpdateWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'status-op@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $this->postJson('/api/v2/mutate', $this->statusPayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'STATUS_DATA',
            'op_type' => Operation::TYPE_UPDATE,
        ]);
    }

    #[Test]
    public function testDirectStatusUpdateWritesAuditLog(): void {
        $user = $this->makeUser(email: 'status-audit@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $this->postJson('/api/v2/mutate', $this->statusPayload());

        $audit = DB::table('audit_log')->where('table_name', 'STATUS_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('UPDATE', $audit->operation);
    }

    // ── Proposal Update Tests ───────────────────────────────

    #[Test]
    public function testProposalStatusUpdateCreatesPendingOperation(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'status-proposal@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/mutate', $this->statusPayload([
            'mode' => 'proposal',
            'changes' => ['c_notes' => '提案備註'],
            'meta' => ['comment' => '提案修改備註'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'statuses',
                'mode' => 'proposal',
                'operation' => 'update',
                'result' => [
                    'updated_fields' => ['c_notes'],
                    'status' => 'proposal_updated',
                ],
            ]);

        // 原始資料未被修改
        $this->assertDatabaseHas('STATUS_DATA', [
            'c_personid' => 1000,
            'c_notes' => null,
        ]);

        // 已寫入提案操作紀錄
        $this->assertDatabaseHas('operations', [
            'resource' => 'STATUS_DATA',
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
        ]);

        $operation = DB::table('operations')->where('resource', 'STATUS_DATA')->first();
        $resourceData = json_decode($operation->resource_data, true);

        $this->assertSame('pending', $resourceData['__review_status']);
        $this->assertSame('update', $resourceData['__proposal_meta']['action']);
        $this->assertSame('statuses', $resourceData['__proposal_meta']['resource_type']);
        $this->assertSame('提案修改備註', $resourceData['__proposal_meta']['comment']);

        // 無 audit_log
        $this->assertDatabaseMissing('audit_log', ['table_name' => 'STATUS_DATA']);
    }

    // ── Error Cases ─────────────────────────────────────────

    #[Test]
    public function testStatusUpdateWithInvalidPkReturns404(): void {
        $user = $this->makeUser(email: 'status-404@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/mutate', $this->statusPayload([
            'target' => ['pk' => [
                'c_personid' => 1000,
                'c_sequence' => 999,
                'c_status_code' => 50,
            ]],
        ]));

        $response->assertStatus(404);
    }

    #[Test]
    public function testStatusUpdateWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'status-mismatch@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/mutate', $this->statusPayload([
            'person_id' => 9999,
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    #[Test]
    public function testStatusUpdateWithEmptyChangesReturns422(): void {
        $user = $this->makeUser(email: 'status-empty@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'statuses',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_sequence' => 1,
                    'c_status_code' => 50,
                ],
            ],
            'changes' => [],
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function testStatusUpdateWithDisallowedFieldReturns422(): void {
        $user = $this->makeUser(email: 'status-disallowed@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/mutate', $this->statusPayload([
            'changes' => ['c_personid' => 9999],
        ]));

        $response->assertStatus(422);
        $this->assertStringContainsString('不允許', $response->json('message'));
    }

    #[Test]
    public function testStatusUpdateWithNoEffectiveChangeReturns422(): void {
        $user = $this->makeUser(email: 'status-nochange@example.com');
        $this->actingAs($user);
        $this->seedStatus(['c_notes' => '已有備註']);

        $response = $this->postJson('/api/v2/mutate', $this->statusPayload([
            'changes' => ['c_notes' => '已有備註'],
        ]));

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'errors' => ['changes' => ['no_effective_changes']],
            ]);
    }

    #[Test]
    public function testStatusUpdateRejectsUnauthenticatedUser(): void {
        $this->seedStatus();
        $response = $this->postJson('/api/v2/mutate', $this->statusPayload());
        $response->assertStatus(401);
    }

    #[Test]
    public function testStatusUpdateRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'status-inactive@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/mutate', $this->statusPayload());
        $response->assertStatus(403);
    }

    #[Test]
    public function testStatusDirectUpdateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'status-crowd@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/mutate', $this->statusPayload());
        $response->assertStatus(403);
    }

    #[Test]
    public function testStatusUpdateAcceptsAlias(): void {
        $user = $this->makeUser(email: 'status-alias@example.com');
        $this->actingAs($user);
        $this->seedStatus();

        $response = $this->postJson('/api/v2/mutate', $this->statusPayload([
            'resource' => 'status_data',
        ]));

        $response->assertOk()
            ->assertJson(['ok' => true, 'resource' => 'statuses']);
    }

    #[Test]
    public function testStatusUpdateRecordNotExist(): void {
        $user = $this->makeUser(email: 'status-notexist@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/mutate', $this->statusPayload());
        $response->assertStatus(404);
    }
}
