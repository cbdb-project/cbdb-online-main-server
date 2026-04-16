<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2MutatePostingTest extends TestCase {
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
        $this->createPostingTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('POSTED_TO_OFFICE_DATA');
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

    protected function createPostingTable(): void {
        Schema::create('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_office_id')->default(0);
            $table->integer('c_posting_id');
            $table->integer('c_sequence')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->text('c_supplement')->nullable();
            $table->integer('c_firstyear')->nullable();
            $table->integer('c_fy_nh_code')->nullable();
            $table->integer('c_fy_nh_year')->nullable();
            $table->integer('c_fy_range')->nullable();
            $table->integer('c_fy_intercalary')->default(0);
            $table->integer('c_lastyear')->nullable();
            $table->integer('c_ly_nh_code')->nullable();
            $table->integer('c_ly_nh_year')->nullable();
            $table->integer('c_ly_range')->nullable();
            $table->integer('c_ly_intercalary')->default(0);
            $table->integer('c_appt_code')->nullable();
            $table->integer('c_assume_office_code')->nullable();
            $table->primary(['c_office_id', 'c_posting_id']);
        });
    }

    // ── Helpers ──────────────────────────────────────────────

    protected function seedPosting(array $overrides = []): void {
        DB::table('POSTED_TO_OFFICE_DATA')->insert(array_replace([
            'c_personid' => 1000,
            'c_office_id' => 300,
            'c_posting_id' => 400,
            'c_sequence' => 1,
            'c_source' => 10,
            'c_notes' => null,
        ], $overrides));
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'posting-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function postingPayload(array $overrides = []): array {
        $payload = [
            'resource' => 'postings',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_office_id' => 300,
                    'c_posting_id' => 400,
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
    public function testDirectPostingUpdateSucceeds(): void {
        $user = $this->makeUser(email: 'posting-direct@example.com');
        $this->actingAs($user);
        $this->seedPosting();

        $response = $this->postJson('/api/v2/mutate', $this->postingPayload([
            'changes' => ['c_notes' => '更新備註', 'c_pages' => '10-20'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'postings',
                'mode' => 'direct',
                'operation' => 'update',
                'result' => [
                    'pk' => [
                        'c_office_id' => 300,
                        'c_posting_id' => 400,
                    ],
                    'updated_fields' => ['c_notes', 'c_pages'],
                ],
            ]);

        $this->assertDatabaseHas('POSTED_TO_OFFICE_DATA', [
            'c_office_id' => 300,
            'c_posting_id' => 400,
            'c_notes' => '更新備註',
            'c_pages' => '10-20',
        ]);
    }

    #[Test]
    public function testDirectPostingUpdateReturnsOperationIdAndRow(): void {
        $user = $this->makeUser(email: 'posting-result@example.com');
        $this->actingAs($user);
        $this->seedPosting();

        $response = $this->postJson('/api/v2/mutate', $this->postingPayload());
        $data = $response->json();

        $this->assertNotNull($data['result']['operation_id']);
        $this->assertNotNull($data['result']['row']);
        $this->assertSame('新的備註', $data['result']['row']['c_notes']);
    }

    #[Test]
    public function testDirectPostingUpdateWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'posting-op@example.com');
        $this->actingAs($user);
        $this->seedPosting();

        $this->postJson('/api/v2/mutate', $this->postingPayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'op_type' => Operation::TYPE_UPDATE,
        ]);
    }

    #[Test]
    public function testDirectPostingUpdateWritesAuditLog(): void {
        $user = $this->makeUser(email: 'posting-audit@example.com');
        $this->actingAs($user);
        $this->seedPosting();

        $this->postJson('/api/v2/mutate', $this->postingPayload());

        $audit = DB::table('audit_log')->where('table_name', 'POSTED_TO_OFFICE_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('UPDATE', $audit->operation);
    }

    // ── Proposal Update Tests ───────────────────────────────

    #[Test]
    public function testProposalPostingUpdateCreatesPendingOperation(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'posting-proposal@example.com');
        $this->actingAs($user);
        $this->seedPosting();

        $response = $this->postJson('/api/v2/mutate', $this->postingPayload([
            'mode' => 'proposal',
            'changes' => ['c_notes' => '提案備註'],
            'meta' => ['comment' => '提案修改備註'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'postings',
                'mode' => 'proposal',
                'operation' => 'update',
                'result' => [
                    'updated_fields' => ['c_notes'],
                    'status' => 'proposal_updated',
                ],
            ]);

        // 原始資料未被修改
        $this->assertDatabaseHas('POSTED_TO_OFFICE_DATA', [
            'c_office_id' => 300,
            'c_posting_id' => 400,
            'c_notes' => null,
        ]);

        // 已寫入提案操作紀錄
        $this->assertDatabaseHas('operations', [
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
        ]);

        $operation = DB::table('operations')->where('resource', 'POSTED_TO_OFFICE_DATA')->first();
        $resourceData = json_decode($operation->resource_data, true);

        $this->assertSame('pending', $resourceData['__review_status']);
        $this->assertSame('update', $resourceData['__proposal_meta']['action']);
        $this->assertSame('postings', $resourceData['__proposal_meta']['resource_type']);
        $this->assertSame('提案修改備註', $resourceData['__proposal_meta']['comment']);

        // 無 audit_log
        $this->assertDatabaseMissing('audit_log', ['table_name' => 'POSTED_TO_OFFICE_DATA']);
    }

    // ── Error Cases ─────────────────────────────────────────

    #[Test]
    public function testPostingUpdateWithInvalidPkReturns404(): void {
        $user = $this->makeUser(email: 'posting-404@example.com');
        $this->actingAs($user);
        $this->seedPosting();

        $response = $this->postJson('/api/v2/mutate', $this->postingPayload([
            'target' => ['pk' => [
                'c_office_id' => 300,
                'c_posting_id' => 999,
            ]],
        ]));

        $response->assertStatus(404);
    }

    #[Test]
    public function testPostingUpdateWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'posting-mismatch@example.com');
        $this->actingAs($user);
        $this->seedPosting();

        $response = $this->postJson('/api/v2/mutate', $this->postingPayload([
            'person_id' => 9999,
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    #[Test]
    public function testPostingUpdateWithEmptyChangesReturns422(): void {
        $user = $this->makeUser(email: 'posting-empty@example.com');
        $this->actingAs($user);
        $this->seedPosting();

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'postings',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_office_id' => 300,
                    'c_posting_id' => 400,
                ],
            ],
            'changes' => [],
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function testPostingUpdateWithDisallowedFieldReturns422(): void {
        $user = $this->makeUser(email: 'posting-disallowed@example.com');
        $this->actingAs($user);
        $this->seedPosting();

        $response = $this->postJson('/api/v2/mutate', $this->postingPayload([
            'changes' => ['c_personid' => 9999],
        ]));

        $response->assertStatus(422);
        $this->assertStringContainsString('不允許', $response->json('message'));
    }

    #[Test]
    public function testPostingUpdateWithNoEffectiveChangeReturns422(): void {
        $user = $this->makeUser(email: 'posting-nochange@example.com');
        $this->actingAs($user);
        $this->seedPosting(['c_notes' => '已有備註']);

        $response = $this->postJson('/api/v2/mutate', $this->postingPayload([
            'changes' => ['c_notes' => '已有備註'],
        ]));

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'errors' => ['changes' => ['no_effective_changes']],
            ]);
    }

    #[Test]
    public function testPostingUpdateRejectsUnauthenticatedUser(): void {
        $this->seedPosting();
        $response = $this->postJson('/api/v2/mutate', $this->postingPayload());
        $response->assertStatus(401);
    }

    #[Test]
    public function testPostingUpdateRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'posting-inactive@example.com');
        $this->actingAs($user);
        $this->seedPosting();

        $response = $this->postJson('/api/v2/mutate', $this->postingPayload());
        $response->assertStatus(403);
    }

    #[Test]
    public function testPostingDirectUpdateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'posting-crowd@example.com');
        $this->actingAs($user);
        $this->seedPosting();

        $response = $this->postJson('/api/v2/mutate', $this->postingPayload());
        $response->assertStatus(403);
    }

    #[Test]
    public function testPostingUpdateAcceptsAlias(): void {
        $user = $this->makeUser(email: 'posting-alias@example.com');
        $this->actingAs($user);
        $this->seedPosting();

        $response = $this->postJson('/api/v2/mutate', $this->postingPayload([
            'resource' => 'posting',
        ]));

        $response->assertOk()
            ->assertJson(['ok' => true, 'resource' => 'postings']);
    }
}
