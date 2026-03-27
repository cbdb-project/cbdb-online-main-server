<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2MutateNianHaoTest extends TestCase {
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
        $this->createNianHaoTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('NIAN_HAO');
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

    protected function createNianHaoTable(): void {
        Schema::create('NIAN_HAO', function (Blueprint $table) {
            $table->smallInteger('c_nianhao_id')->primary();
            $table->smallInteger('c_dy')->nullable();
            $table->string('c_dynasty_chn')->nullable();
            $table->string('c_nianhao_chn')->nullable();
            $table->string('c_nianhao_pin')->nullable();
            $table->smallInteger('c_firstyear')->nullable();
            $table->smallInteger('c_lastyear')->nullable();
        });
    }

    protected function seedNianHao(array $overrides = []): void {
        DB::table('NIAN_HAO')->insert(array_replace([
            'c_nianhao_id' => 100,
            'c_dy' => 15,
            'c_dynasty_chn' => '宋',
            'c_nianhao_chn' => '建隆',
            'c_nianhao_pin' => null,
            'c_firstyear' => 960,
            'c_lastyear' => 963,
        ], $overrides));
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'nianhao-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function nianhaoUpdatePayload(array $overrides = []): array {
        $payload = [
            'resource' => 'nianhao',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_nianhao_id' => 100,
                ],
            ],
            'changes' => [
                'c_nianhao_pin' => 'Jianlong',
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    // ── Direct Update Tests ─────────────────────────────────────────

    #[Test]
    public function testDirectNianHaoUpdateCanPatchPinyinField(): void {
        $user = $this->makeUser(email: 'nianhao-direct@example.com');
        $this->actingAs($user);
        $this->seedNianHao();

        $response = $this->postJson('/api/v2/mutate', $this->nianhaoUpdatePayload());

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'nianhao',
                'mode' => 'direct',
                'operation' => 'update',
                'result' => [
                    'pk' => ['c_nianhao_id' => 100],
                    'updated_fields' => ['c_nianhao_pin'],
                ],
            ]);

        $this->assertDatabaseHas('NIAN_HAO', [
            'c_nianhao_id' => 100,
            'c_nianhao_pin' => 'Jianlong',
        ]);
    }

    #[Test]
    public function testDirectNianHaoUpdateWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'nianhao-op@example.com');
        $this->actingAs($user);
        $this->seedNianHao();

        $this->postJson('/api/v2/mutate', $this->nianhaoUpdatePayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'NIAN_HAO',
            'op_type' => Operation::TYPE_UPDATE,
        ]);

        $operation = DB::table('operations')->where('resource', 'NIAN_HAO')->first();
        $resourceData = json_decode($operation->resource_data, true);
        $resourceOriginal = json_decode($operation->resource_original, true);

        $this->assertSame('Jianlong', $resourceData['c_nianhao_pin']);
        $this->assertNull($resourceOriginal['c_nianhao_pin']);
    }

    #[Test]
    public function testDirectNianHaoUpdateWritesAuditLog(): void {
        $user = $this->makeUser(email: 'nianhao-audit@example.com');
        $this->actingAs($user);
        $this->seedNianHao();

        $this->postJson('/api/v2/mutate', $this->nianhaoUpdatePayload());

        $audit = DB::table('audit_log')->where('table_name', 'NIAN_HAO')->first();
        $this->assertNotNull($audit);
        $this->assertSame('UPDATE', $audit->operation);
        $this->assertSame('c_nianhao_id=100', $audit->row_pk_text);

        $oldData = json_decode($audit->old_data, true);
        $newData = json_decode($audit->new_data, true);

        $this->assertNull($oldData['c_nianhao_pin']);
        $this->assertSame('Jianlong', $newData['c_nianhao_pin']);
    }

    #[Test]
    public function testDirectNianHaoUpdateReturnsOperationIdAndRow(): void {
        $user = $this->makeUser(email: 'nianhao-result@example.com');
        $this->actingAs($user);
        $this->seedNianHao();

        $response = $this->postJson('/api/v2/mutate', $this->nianhaoUpdatePayload());
        $data = $response->json();

        $this->assertNotNull($data['result']['operation_id']);
        $this->assertNotNull($data['result']['row']);
        $this->assertSame('Jianlong', $data['result']['row']['c_nianhao_pin']);
        $this->assertSame(100, $data['result']['row']['c_nianhao_id']);
    }

    #[Test]
    public function testNianHaoUpdateAlwaysStoresPersonIdAsZero(): void {
        $user = $this->makeUser(email: 'nianhao-personid@example.com');
        $this->actingAs($user);
        $this->seedNianHao();

        // 呼叫端傳入任意 person_id，應被忽略
        $this->postJson('/api/v2/mutate', $this->nianhaoUpdatePayload([
            'person_id' => 99999,
        ]));

        $operation = DB::table('operations')->where('resource', 'NIAN_HAO')->first();
        $this->assertNotNull($operation);
        $this->assertSame(0, $operation->c_personid);
    }

    #[Test]
    public function testProposalNianHaoUpdateAlwaysStoresPersonIdAsZero(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'nianhao-proposal-pid@example.com');
        $this->actingAs($user);
        $this->seedNianHao();

        $this->postJson('/api/v2/mutate', $this->nianhaoUpdatePayload([
            'mode' => 'proposal',
            'person_id' => 12345,
        ]));

        $operation = DB::table('operations')->where('resource', 'NIAN_HAO')->first();
        $this->assertNotNull($operation);
        $this->assertSame(0, $operation->c_personid);
    }

    #[Test]
    public function testDirectNianHaoUpdateWithCommentIncludesNoteInOperation(): void {
        $user = $this->makeUser(email: 'nianhao-comment@example.com');
        $this->actingAs($user);
        $this->seedNianHao();

        $this->postJson('/api/v2/mutate', $this->nianhaoUpdatePayload([
            'meta' => ['comment' => '補註拼音'],
        ]));

        $operation = DB::table('operations')->where('resource', 'NIAN_HAO')->first();
        $resourceData = json_decode($operation->resource_data, true);
        $this->assertSame('補註拼音', $resourceData['__note']);
    }

    #[Test]
    public function testDirectNianHaoUpdateAcceptsNianHaoAlias(): void {
        $user = $this->makeUser(email: 'nianhao-alias@example.com');
        $this->actingAs($user);
        $this->seedNianHao();

        $response = $this->postJson('/api/v2/mutate', $this->nianhaoUpdatePayload([
            'resource' => 'nian_hao',
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'nianhao',
            ]);

        $this->assertDatabaseHas('NIAN_HAO', [
            'c_nianhao_id' => 100,
            'c_nianhao_pin' => 'Jianlong',
        ]);
    }

    #[Test]
    public function testDirectNianHaoUpdateCanSetPinyinToNull(): void {
        $user = $this->makeUser(email: 'nianhao-null@example.com');
        $this->actingAs($user);
        $this->seedNianHao(['c_nianhao_pin' => 'OldPinyin']);

        $response = $this->postJson('/api/v2/mutate', $this->nianhaoUpdatePayload([
            'changes' => ['c_nianhao_pin' => null],
        ]));

        $response->assertOk();
        $this->assertDatabaseHas('NIAN_HAO', [
            'c_nianhao_id' => 100,
            'c_nianhao_pin' => null,
        ]);
    }

    // ── Proposal Update Tests ───────────────────────────────────────

    #[Test]
    public function testProposalNianHaoUpdateCreatesPendingOperationWithoutChangingRow(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'nianhao-proposal@example.com');
        $this->actingAs($user);
        $this->seedNianHao();

        $response = $this->postJson('/api/v2/mutate', $this->nianhaoUpdatePayload([
            'mode' => 'proposal',
            'meta' => ['comment' => '提案修正拼音'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'nianhao',
                'mode' => 'proposal',
                'operation' => 'update',
                'result' => [
                    'pk' => ['c_nianhao_id' => 100],
                    'updated_fields' => ['c_nianhao_pin'],
                    'status' => 'proposal_updated',
                ],
            ]);

        // 原始資料未被修改
        $this->assertDatabaseHas('NIAN_HAO', [
            'c_nianhao_id' => 100,
            'c_nianhao_pin' => null,
        ]);

        // 已寫入提案操作紀錄
        $this->assertDatabaseHas('operations', [
            'resource' => 'NIAN_HAO',
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
        ]);

        $operation = DB::table('operations')->where('resource', 'NIAN_HAO')->first();
        $resourceData = json_decode($operation->resource_data, true);

        $this->assertSame('pending', $resourceData['__review_status']);
        $this->assertSame('update', $resourceData['__proposal_meta']['action']);
        $this->assertSame('nianhao', $resourceData['__proposal_meta']['resource_type']);
        $this->assertSame('提案修正拼音', $resourceData['__proposal_meta']['comment']);
        $this->assertSame('Jianlong', $resourceData['c_nianhao_pin']);

        // 無 audit_log
        $this->assertDatabaseMissing('audit_log', ['table_name' => 'NIAN_HAO']);
    }

    // ── Error Cases ─────────────────────────────────────────────────

    #[Test]
    public function testNianHaoUpdateWithInvalidPkReturns404(): void {
        $user = $this->makeUser(email: 'nianhao-404@example.com');
        $this->actingAs($user);
        $this->seedNianHao();

        $response = $this->postJson('/api/v2/mutate', $this->nianhaoUpdatePayload([
            'target' => ['pk' => ['c_nianhao_id' => 99999]],
        ]));

        $response->assertStatus(404)
            ->assertJson([
                'ok' => false,
                'message' => 'NIAN_HAO 記錄不存在',
            ]);
    }

    #[Test]
    public function testNianHaoUpdateWithEmptyChangesReturns422(): void {
        $user = $this->makeUser(email: 'nianhao-empty@example.com');
        $this->actingAs($user);
        $this->seedNianHao();

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'nianhao',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_nianhao_id' => 100]],
            'changes' => [],
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
            ]);
    }

    #[Test]
    public function testNianHaoUpdateWithDisallowedFieldReturns422(): void {
        $user = $this->makeUser(email: 'nianhao-disallowed@example.com');
        $this->actingAs($user);
        $this->seedNianHao();

        $response = $this->postJson('/api/v2/mutate', $this->nianhaoUpdatePayload([
            'changes' => ['c_nianhao_chn' => '太平'],
        ]));

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
            ]);
        $this->assertStringContainsString('不允許', $response->json('message'));
    }

    #[Test]
    public function testNianHaoUpdateWithNoEffectiveChangeReturns422(): void {
        $user = $this->makeUser(email: 'nianhao-nochange@example.com');
        $this->actingAs($user);
        $this->seedNianHao(['c_nianhao_pin' => 'Jianlong']);

        $response = $this->postJson('/api/v2/mutate', $this->nianhaoUpdatePayload([
            'changes' => ['c_nianhao_pin' => 'Jianlong'],
        ]));

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'errors' => [
                    'changes' => ['no_effective_changes'],
                ],
            ]);
    }

    #[Test]
    public function testNianHaoUpdateRejectsUnauthenticatedUser(): void {
        $this->seedNianHao();

        $response = $this->postJson('/api/v2/mutate', $this->nianhaoUpdatePayload());

        $response->assertStatus(401);
    }

    #[Test]
    public function testNianHaoUpdateRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'nianhao-inactive@example.com');
        $this->actingAs($user);
        $this->seedNianHao();

        $response = $this->postJson('/api/v2/mutate', $this->nianhaoUpdatePayload());

        $response->assertStatus(403);
    }

    #[Test]
    public function testNianHaoDirectUpdateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'nianhao-crowd@example.com');
        $this->actingAs($user);
        $this->seedNianHao();

        $response = $this->postJson('/api/v2/mutate', $this->nianhaoUpdatePayload([
            'mode' => 'direct',
        ]));

        $response->assertStatus(403);
    }

    #[Test]
    public function testNianHaoUpdateWithTooLongPinyinReturns422(): void {
        $user = $this->makeUser(email: 'nianhao-long@example.com');
        $this->actingAs($user);
        $this->seedNianHao();

        $response = $this->postJson('/api/v2/mutate', $this->nianhaoUpdatePayload([
            'changes' => ['c_nianhao_pin' => str_repeat('a', 256)],
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    #[Test]
    public function testNianHaoUpdateWithMixedAllowedAndDisallowedFieldsRejects(): void {
        $user = $this->makeUser(email: 'nianhao-mixed@example.com');
        $this->actingAs($user);
        $this->seedNianHao();

        $response = $this->postJson('/api/v2/mutate', $this->nianhaoUpdatePayload([
            'changes' => [
                'c_nianhao_pin' => 'Jianlong',
                'c_nianhao_id' => 999,
            ],
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false]);
    }
}
