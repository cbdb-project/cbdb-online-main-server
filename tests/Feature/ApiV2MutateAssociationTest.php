<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2MutateAssociationTest extends TestCase {
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
        $this->createAssociationTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('ASSOC_DATA');
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

    protected function createAssociationTable(): void {
        Schema::create('ASSOC_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_assoc_code')->default(0);
            $table->integer('c_assoc_id')->default(0);
            $table->integer('c_kin_code')->default(0);
            $table->integer('c_kin_id')->default(0);
            $table->integer('c_assoc_kin_code')->default(0);
            $table->integer('c_assoc_kin_id')->default(0);
            $table->string('c_text_title', 255)->default('');
            $table->integer('c_assoc_first_year')->default(0);
            $table->integer('c_assoc_last_year')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->text('c_supplement')->nullable();
            $table->integer('c_sequence')->default(0);
            $table->integer('c_assoc_count')->default(0);
            // Task 27：補回欄位（皆 ASSOC_DATA 真實欄）。
            $table->integer('c_topic_code')->nullable();
            $table->integer('c_occasion_code')->nullable();
            $table->integer('c_tertiary_personid')->nullable();
            $table->text('c_tertiary_type_notes')->nullable();
            $table->integer('c_assoc_claimer_id')->nullable();
            $table->integer('c_addr_id')->nullable();
            $table->integer('c_inst_code')->default(0);
            $table->integer('c_inst_name_code')->default(0);
            $table->primary([
                'c_personid', 'c_assoc_code', 'c_assoc_id',
                'c_kin_code', 'c_kin_id', 'c_assoc_kin_code',
                'c_assoc_kin_id', 'c_text_title', 'c_assoc_first_year',
            ]);
        });
    }

    // ── Helpers ──────────────────────────────────────────────

    protected function seedAssociation(array $overrides = []): void {
        DB::table('ASSOC_DATA')->insert(array_replace([
            'c_personid' => 1000,
            'c_assoc_code' => 1,
            'c_assoc_id' => 2000,
            'c_kin_code' => 0,
            'c_kin_id' => 0,
            'c_assoc_kin_code' => 0,
            'c_assoc_kin_id' => 0,
            'c_text_title' => '書名',
            'c_assoc_first_year' => 1060,
            'c_source' => 10,
            'c_notes' => null,
        ], $overrides));
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'assoc-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function associationPayload(array $overrides = []): array {
        $payload = [
            'resource' => 'associations',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_assoc_code' => 1,
                    'c_assoc_id' => 2000,
                    'c_kin_code' => 0,
                    'c_kin_id' => 0,
                    'c_assoc_kin_code' => 0,
                    'c_assoc_kin_id' => 0,
                    'c_text_title' => '書名',
                    'c_assoc_first_year' => 1060,
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
    public function testUpdatePreservesSentinelPkFields(): void {
        // 回歸：編輯「未知出處/未知年份」記錄（c_text_title='[n/a]'、c_assoc_first_year=-9999），
        // 前端把哨仔顯示為空、changes 帶空值（middleware 轉 null）；preprocessUpdateData 須轉回哨兵，
        // 否則 PK 會漂移成 '' / 0。
        $user = $this->makeUser(email: 'assoc-sentinel@example.com');
        $this->actingAs($user);
        $this->seedAssociation([
            'c_text_title' => '[n/a]',
            'c_assoc_first_year' => -9999,
            'c_notes' => '原備註',
        ]);

        $response = $this->postJson('/api/v2/mutate', $this->associationPayload([
            'target' => ['pk' => ['c_text_title' => '[n/a]', 'c_assoc_first_year' => -9999]],
            'changes' => ['c_notes' => '改後備註', 'c_text_title' => null, 'c_assoc_first_year' => null],
        ]));

        $response->assertOk()->assertJson(['ok' => true]);

        // PK 哨兵保持，不漂移為 0 / ''
        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 1000,
            'c_assoc_code' => 1,
            'c_assoc_id' => 2000,
            'c_text_title' => '[n/a]',
            'c_assoc_first_year' => -9999,
            'c_notes' => '改後備註',
        ]);
        $this->assertDatabaseMissing('ASSOC_DATA', ['c_assoc_first_year' => 0]);
    }

    #[Test]
    public function testDirectAssociationUpdateSucceeds(): void {
        $user = $this->makeUser(email: 'assoc-direct@example.com');
        $this->actingAs($user);
        $this->seedAssociation();

        $response = $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_notes' => '更新備註', 'c_pages' => '10-20'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'associations',
                'mode' => 'direct',
                'operation' => 'update',
                'result' => [
                    'pk' => [
                        'c_personid' => 1000,
                        'c_assoc_code' => 1,
                        'c_assoc_id' => 2000,
                        'c_kin_code' => 0,
                        'c_kin_id' => 0,
                        'c_assoc_kin_code' => 0,
                        'c_assoc_kin_id' => 0,
                        'c_text_title' => '書名',
                        'c_assoc_first_year' => 1060,
                    ],
                    'updated_fields' => ['c_notes', 'c_pages'],
                ],
            ]);

        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 1000,
            'c_notes' => '更新備註',
            'c_pages' => '10-20',
        ]);
    }

    #[Test]
    public function testDirectAssociationUpdatePersistsRestoredFieldsAndDoesNotNullOthers(): void {
        // 回歸（Task 27）：補回欄位（c_topic_code / c_addr_id / c_tertiary_personid / c_inst_code…）
        // 須能寫入；且只改單一欄位時，未送出的補回欄位不可被清成 null —— 防護「保存即清空」資料流失。
        $user = $this->makeUser(email: 'assoc-restored@example.com');
        $this->actingAs($user);
        $this->seedAssociation([
            'c_topic_code' => 5,
            'c_addr_id' => 100,
            'c_tertiary_personid' => 777,
            'c_inst_code' => 12,
            'c_inst_name_code' => 3,
        ]);

        // (a) 直接更新補回欄位應成功寫入（含 inst 兩欄）。
        $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_topic_code' => 9, 'c_inst_code' => 20, 'c_inst_name_code' => 4],
        ]))->assertOk();
        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 1000, 'c_assoc_id' => 2000,
            'c_topic_code' => 9, 'c_inst_code' => 20, 'c_inst_name_code' => 4,
            'c_addr_id' => 100, 'c_tertiary_personid' => 777,
        ]);

        // (b) 只改 c_notes（payload 不含補回欄位）後，補回欄位仍保留、未被清空。
        $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_notes' => '只改備註'],
        ]))->assertOk();
        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 1000, 'c_assoc_id' => 2000,
            'c_notes' => '只改備註', 'c_topic_code' => 9,
            'c_inst_code' => 20, 'c_addr_id' => 100, 'c_tertiary_personid' => 777,
        ]);
    }

    #[Test]
    public function testDirectAssociationUpdateReturnsOperationIdAndRow(): void {
        $user = $this->makeUser(email: 'assoc-result@example.com');
        $this->actingAs($user);
        $this->seedAssociation();

        $response = $this->postJson('/api/v2/mutate', $this->associationPayload());
        $data = $response->json();

        $this->assertNotNull($data['result']['operation_id']);
        $this->assertNotNull($data['result']['row']);
        $this->assertSame('新的備註', $data['result']['row']['c_notes']);
    }

    #[Test]
    public function testDirectAssociationUpdateWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'assoc-op@example.com');
        $this->actingAs($user);
        $this->seedAssociation();

        $this->postJson('/api/v2/mutate', $this->associationPayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'ASSOC_DATA',
            'op_type' => Operation::TYPE_UPDATE,
        ]);
    }

    #[Test]
    public function testDirectAssociationUpdateWritesAuditLog(): void {
        $user = $this->makeUser(email: 'assoc-audit@example.com');
        $this->actingAs($user);
        $this->seedAssociation();

        $this->postJson('/api/v2/mutate', $this->associationPayload());

        $audit = DB::table('audit_log')->where('table_name', 'ASSOC_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('UPDATE', $audit->operation);
    }

    // ── Proposal Update Tests ───────────────────────────────

    #[Test]
    public function testProposalAssociationUpdateCreatesPendingOperation(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'assoc-proposal@example.com');
        $this->actingAs($user);
        $this->seedAssociation();

        $response = $this->postJson('/api/v2/mutate', $this->associationPayload([
            'mode' => 'proposal',
            'changes' => ['c_notes' => '提案備註'],
            'meta' => ['comment' => '提案修改備註'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'associations',
                'mode' => 'proposal',
                'operation' => 'update',
                'result' => [
                    'updated_fields' => ['c_notes'],
                    'status' => 'proposal_updated',
                ],
            ]);

        // 原始資料未被修改
        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 1000,
            'c_notes' => null,
        ]);

        // 已寫入提案操作紀錄
        $this->assertDatabaseHas('operations', [
            'resource' => 'ASSOC_DATA',
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
        ]);

        $operation = DB::table('operations')->where('resource', 'ASSOC_DATA')->first();
        $resourceData = json_decode($operation->resource_data, true);

        $this->assertSame('pending', $resourceData['__review_status']);
        $this->assertSame('update', $resourceData['__proposal_meta']['action']);
        $this->assertSame('associations', $resourceData['__proposal_meta']['resource_type']);
        $this->assertSame('提案修改備註', $resourceData['__proposal_meta']['comment']);

        // 無 audit_log
        $this->assertDatabaseMissing('audit_log', ['table_name' => 'ASSOC_DATA']);
    }

    // ── Error Cases ─────────────────────────────────────────

    #[Test]
    public function testAssociationUpdateWithInvalidPkReturns404(): void {
        $user = $this->makeUser(email: 'assoc-404@example.com');
        $this->actingAs($user);
        $this->seedAssociation();

        $response = $this->postJson('/api/v2/mutate', $this->associationPayload([
            'target' => ['pk' => [
                'c_personid' => 1000,
                'c_assoc_code' => 999,
                'c_assoc_id' => 2000,
                'c_kin_code' => 0,
                'c_kin_id' => 0,
                'c_assoc_kin_code' => 0,
                'c_assoc_kin_id' => 0,
                'c_text_title' => '書名',
                'c_assoc_first_year' => 1060,
            ]],
        ]));

        $response->assertStatus(404);
    }

    #[Test]
    public function testAssociationUpdateWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'assoc-mismatch@example.com');
        $this->actingAs($user);
        $this->seedAssociation();

        $response = $this->postJson('/api/v2/mutate', $this->associationPayload([
            'person_id' => 9999,
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    #[Test]
    public function testAssociationUpdateWithEmptyChangesReturns422(): void {
        $user = $this->makeUser(email: 'assoc-empty@example.com');
        $this->actingAs($user);
        $this->seedAssociation();

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'associations',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_assoc_code' => 1,
                    'c_assoc_id' => 2000,
                    'c_kin_code' => 0,
                    'c_kin_id' => 0,
                    'c_assoc_kin_code' => 0,
                    'c_assoc_kin_id' => 0,
                    'c_text_title' => '書名',
                    'c_assoc_first_year' => 1060,
                ],
            ],
            'changes' => [],
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function testAssociationUpdateWithDisallowedFieldReturns422(): void {
        $user = $this->makeUser(email: 'assoc-disallowed@example.com');
        $this->actingAs($user);
        $this->seedAssociation();

        $response = $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_personid' => 9999],
        ]));

        $response->assertStatus(422);
        $this->assertStringContainsString('不允許', $response->json('message'));
    }

    #[Test]
    public function testAssociationUpdateWithNoEffectiveChangeReturns422(): void {
        $user = $this->makeUser(email: 'assoc-nochange@example.com');
        $this->actingAs($user);
        $this->seedAssociation(['c_notes' => '已有備註']);

        $response = $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_notes' => '已有備註'],
        ]));

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'errors' => ['changes' => ['no_effective_changes']],
            ]);
    }

    #[Test]
    public function testAssociationUpdateRejectsUnauthenticatedUser(): void {
        $this->seedAssociation();
        $response = $this->postJson('/api/v2/mutate', $this->associationPayload());
        $response->assertStatus(401);
    }

    #[Test]
    public function testAssociationUpdateRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'assoc-inactive@example.com');
        $this->actingAs($user);
        $this->seedAssociation();

        $response = $this->postJson('/api/v2/mutate', $this->associationPayload());
        $response->assertStatus(403);
    }

    #[Test]
    public function testAssociationDirectUpdateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'assoc-crowd@example.com');
        $this->actingAs($user);
        $this->seedAssociation();

        $response = $this->postJson('/api/v2/mutate', $this->associationPayload());
        $response->assertStatus(403);
    }

    #[Test]
    public function testAssociationUpdateAcceptsAlias(): void {
        $user = $this->makeUser(email: 'assoc-alias@example.com');
        $this->actingAs($user);
        $this->seedAssociation();

        $response = $this->postJson('/api/v2/mutate', $this->associationPayload([
            'resource' => 'association',
        ]));

        $response->assertOk()
            ->assertJson(['ok' => true, 'resource' => 'associations']);
    }
}
