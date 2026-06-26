<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2MutatePossessionTest extends TestCase {
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
        $this->createPossessionTable();
        $this->createPossessionAddrTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('POSSESSION_ADDR');
        Schema::dropIfExists('POSSESSION_DATA');
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

    protected function createPossessionTable(): void {
        Schema::create('POSSESSION_DATA', function (Blueprint $table) {
            $table->integer('c_possession_record_id');
            $table->integer('c_personid');
            $table->integer('c_sequence')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->integer('c_possession_act_code')->default(0);
            $table->string('c_possession_desc', 255)->nullable();
            $table->string('c_possession_desc_chn', 255)->nullable();
            $table->string('c_quantity', 255)->nullable();
            $table->integer('c_measure_code')->default(0);
            $table->integer('c_possession_yr')->nullable();
            $table->integer('c_possession_nh_code')->nullable();
            $table->integer('c_possession_nh_yr')->nullable();
            $table->integer('c_possession_yr_range')->nullable();
            // 真實 POSSESSION_DATA 含建檔/修改稽核欄；legacy possessionUpdateById（提案核准路徑）會經
            // ToolsRepository::timestamp 寫入 c_modified_*，測試表須補齊 nullable 欄否則核准會靜默回滾。
            $table->string('c_created_by', 255)->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->dateTime('c_modified_date')->nullable();
            $table->primary('c_possession_record_id');
        });
    }

    protected function createPossessionAddrTable(): void {
        Schema::create('POSSESSION_ADDR', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_possession_record_id');
            $table->integer('c_addr_id')->default(0);
            $table->primary(['c_possession_record_id', 'c_addr_id']);
        });
    }

    protected function seedPossessionAddr(int $addrId): void {
        DB::table('POSSESSION_ADDR')->insert(['c_personid' => 1000, 'c_possession_record_id' => 500, 'c_addr_id' => $addrId]);
    }

    // ── Helpers ──────────────────────────────────────────────

    protected function seedPossession(array $overrides = []): void {
        DB::table('POSSESSION_DATA')->insert(array_replace([
            'c_possession_record_id' => 500,
            'c_personid' => 1000,
            'c_sequence' => 1,
            'c_source' => 10,
            'c_notes' => null,
        ], $overrides));
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'poss-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function possessionPayload(array $overrides = []): array {
        $payload = [
            'resource' => 'possessions',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_possession_record_id' => 500,
                ],
            ],
            'changes' => [
                'c_notes' => '新的備註',
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    // ── Sentinel 完全幂等（碼欄 0/null/''/-999 規範化一致；§M 「每頁≥10 多樣案例」）──────

    #[Test]
    public function testPossessionCodeFieldsSentinelFullyIdempotent(): void {
        // 三個碼欄 c_source/c_measure_code/c_possession_act_code（legacy 哨兵 0=Unknown（DDL 實為 nullable））的
        // 所有空表示（null/''/-999/'0'/0）一律→0、永不寫 null、合法值保留、來回不翻；與「表單送 0」的 legacy 一致。
        $this->actingAs($this->makeUser(email: 'poss-sentinel@example.com'));
        $pk = ['c_possession_record_id' => 500];
        $fields = ['c_source', 'c_measure_code', 'c_possession_act_code'];

        // A. 各碼欄 × 各空表示 → 0（從非 0 seed 確保是真寫入而非巧合）。3×5=15 案例。
        foreach ($fields as $field) {
            foreach ([null, '', -999, '0', 0] as $sent) {
                DB::table('POSSESSION_DATA')->delete();
                $this->seedPossession([$field => 5, 'c_notes' => '初始']);
                $this->postJson('/api/v2/mutate', $this->possessionPayload([
                    'changes' => [$field => $sent, 'c_notes' => '改'.$field.var_export($sent, true)],
                ]))->assertOk();
                $stored = DB::table('POSSESSION_DATA')->where($pk)->value($field);
                $this->assertSame(0, (int) $stored, "{$field} 送 ".var_export($sent, true)." 應規範化為 0");
                $this->assertNotNull($stored, "{$field} 不得為 null（NOT NULL 欄）");
            }
        }

        // B. 合法非 0 值保留（規範化不得誤清真實值）。
        DB::table('POSSESSION_DATA')->delete();
        $this->seedPossession(['c_source' => 1, 'c_notes' => 'x']);
        $this->postJson('/api/v2/mutate', $this->possessionPayload(['changes' => ['c_source' => 7, 'c_notes' => '合法值']]))->assertOk();
        $this->assertSame(7, (int) DB::table('POSSESSION_DATA')->where($pk)->value('c_source'));

        // C. 組合 + 幂等：三欄同送 null → 全 0；再送 '' → 仍全 0（不翻）。
        DB::table('POSSESSION_DATA')->delete();
        $this->seedPossession(['c_source' => 5, 'c_measure_code' => 5, 'c_possession_act_code' => 5, 'c_notes' => 'y']);
        $this->postJson('/api/v2/mutate', $this->possessionPayload(['changes' => ['c_source' => null, 'c_measure_code' => null, 'c_possession_act_code' => null, 'c_notes' => '組合空1']]))->assertOk();
        foreach ($fields as $f) {
            $this->assertSame(0, (int) DB::table('POSSESSION_DATA')->where($pk)->value($f), "組合：{$f} 應→0");
        }
        $this->postJson('/api/v2/mutate', $this->possessionPayload(['changes' => ['c_source' => '', 'c_measure_code' => '', 'c_possession_act_code' => '', 'c_notes' => '組合空2']]))->assertOk();
        foreach ($fields as $f) {
            $this->assertSame(0, (int) DB::table('POSSESSION_DATA')->where($pk)->value($f), "幂等：{$f} 仍為 0");
        }
    }

    // ── 地址副表（POSSESSION_ADDR）同步 ─────────────────────

    #[Test]
    public function testDirectPossessionUpdateSyncsAddrSubtable(): void {
        // 改備註 + c_addr_id 一併送 → POSSESSION_ADDR 同步為新集合（刪舊重插）。
        $this->actingAs($this->makeUser(email: 'poss-addr-sync@example.com'));
        $this->seedPossession();
        $this->seedPossessionAddr(111);

        $this->postJson('/api/v2/mutate', $this->possessionPayload([
            'changes' => ['c_notes' => '改後', 'c_addr_id' => [222, 333]],
        ]))->assertOk();

        $this->assertDatabaseMissing('POSSESSION_ADDR', ['c_possession_record_id' => 500, 'c_addr_id' => 111]);
        $this->assertDatabaseHas('POSSESSION_ADDR', ['c_possession_record_id' => 500, 'c_addr_id' => 222]);
        $this->assertDatabaseHas('POSSESSION_ADDR', ['c_possession_record_id' => 500, 'c_addr_id' => 333]);
    }

    #[Test]
    public function testDirectPossessionUpdateAddrOnlyChange(): void {
        // 僅改地址（無財產欄）→ 走 address-only 路徑，POSSESSION_ADDR 更新成功。
        $this->actingAs($this->makeUser(email: 'poss-addr-only@example.com'));
        $this->seedPossession();
        $this->seedPossessionAddr(111);

        $this->postJson('/api/v2/mutate', $this->possessionPayload([
            'changes' => ['c_addr_id' => [222]],
        ]))->assertOk();

        $this->assertDatabaseHas('POSSESSION_ADDR', ['c_possession_record_id' => 500, 'c_addr_id' => 222]);
        $this->assertDatabaseMissing('POSSESSION_ADDR', ['c_possession_record_id' => 500, 'c_addr_id' => 111]);
    }

    #[Test]
    public function testDirectPossessionUpdateNotesKeepsAddresses(): void {
        // 未送 c_addr_id（僅改備註）→ 既有 POSSESSION_ADDR 保留（不誤刪）。
        $this->actingAs($this->makeUser(email: 'poss-addr-keep@example.com'));
        $this->seedPossession();
        $this->seedPossessionAddr(111);

        $this->postJson('/api/v2/mutate', $this->possessionPayload([
            'changes' => ['c_notes' => '只改備註'],
        ]))->assertOk();

        $this->assertDatabaseHas('POSSESSION_ADDR', ['c_possession_record_id' => 500, 'c_addr_id' => 111]);
    }

    // ── Direct Update Tests ─────────────────────────────────

    #[Test]
    public function testDirectPossessionUpdateSucceeds(): void {
        $user = $this->makeUser(email: 'poss-direct@example.com');
        $this->actingAs($user);
        $this->seedPossession();

        $response = $this->postJson('/api/v2/mutate', $this->possessionPayload([
            'changes' => ['c_notes' => '更新備註', 'c_pages' => '10-20'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'possessions',
                'mode' => 'direct',
                'operation' => 'update',
                'result' => [
                    'pk' => [
                        'c_possession_record_id' => 500,
                    ],
                    'updated_fields' => ['c_notes', 'c_pages'],
                ],
            ]);

        $this->assertDatabaseHas('POSSESSION_DATA', [
            'c_possession_record_id' => 500,
            'c_notes' => '更新備註',
            'c_pages' => '10-20',
        ]);
    }

    #[Test]
    public function testDirectPossessionUpdateReturnsOperationIdAndRow(): void {
        $user = $this->makeUser(email: 'poss-result@example.com');
        $this->actingAs($user);
        $this->seedPossession();

        $response = $this->postJson('/api/v2/mutate', $this->possessionPayload());
        $data = $response->json();

        $this->assertNotNull($data['result']['operation_id']);
        $this->assertNotNull($data['result']['row']);
        $this->assertSame('新的備註', $data['result']['row']['c_notes']);
    }

    #[Test]
    public function testDirectPossessionUpdateWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'poss-op@example.com');
        $this->actingAs($user);
        $this->seedPossession();

        $this->postJson('/api/v2/mutate', $this->possessionPayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'POSSESSION_DATA',
            'op_type' => Operation::TYPE_UPDATE,
        ]);
    }

    #[Test]
    public function testDirectPossessionUpdateWritesAuditLog(): void {
        $user = $this->makeUser(email: 'poss-audit@example.com');
        $this->actingAs($user);
        $this->seedPossession();

        $this->postJson('/api/v2/mutate', $this->possessionPayload());

        $audit = DB::table('audit_log')->where('table_name', 'POSSESSION_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('UPDATE', $audit->operation);
    }

    #[Test]
    public function testDirectPossessionUpdateOnlyNotesPreservesMeasureCode(): void {
        // 回歸：編輯只改備註時，c_measure_code（單位）不得被清空。
        $user = $this->makeUser(email: 'poss-measure@example.com');
        $this->actingAs($user);
        $this->seedPossession(['c_measure_code' => 7, 'c_notes' => '舊備註']);

        $response = $this->postJson('/api/v2/mutate', $this->possessionPayload([
            'changes' => ['c_notes' => '只改備註'],
        ]));

        $response->assertOk();

        $this->assertDatabaseHas('POSSESSION_DATA', [
            'c_possession_record_id' => 500,
            'c_notes' => '只改備註',
            'c_measure_code' => 7,
        ]);
    }

    // ── Proposal Update Tests ───────────────────────────────

    #[Test]
    public function testProposalPossessionUpdateCreatesPendingOperation(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'poss-proposal@example.com');
        $this->actingAs($user);
        $this->seedPossession();

        $response = $this->postJson('/api/v2/mutate', $this->possessionPayload([
            'mode' => 'proposal',
            'changes' => ['c_notes' => '提案備註'],
            'meta' => ['comment' => '提案修改備註'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'possessions',
                'mode' => 'proposal',
                'operation' => 'update',
                'result' => [
                    'updated_fields' => ['c_notes'],
                    'status' => 'proposal_updated',
                ],
            ]);

        // 原始資料未被修改
        $this->assertDatabaseHas('POSSESSION_DATA', [
            'c_possession_record_id' => 500,
            'c_notes' => null,
        ]);

        // 已寫入提案操作紀錄
        $this->assertDatabaseHas('operations', [
            'resource' => 'POSSESSION_DATA',
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
        ]);

        $operation = DB::table('operations')->where('resource', 'POSSESSION_DATA')->first();
        $resourceData = json_decode($operation->resource_data, true);

        $this->assertSame('pending', $resourceData['__review_status']);
        $this->assertSame('update', $resourceData['__proposal_meta']['action']);
        $this->assertSame('possessions', $resourceData['__proposal_meta']['resource_type']);
        $this->assertSame('提案修改備註', $resourceData['__proposal_meta']['comment']);

        // 無 audit_log
        $this->assertDatabaseMissing('audit_log', ['table_name' => 'POSSESSION_DATA']);
    }

    #[Test]
    public function testApprovingPossessionUpdateProposalSyncsAddrSubtable(): void {
        // 完整往返：眾包提案改地址 → __proposal_aux 帶 c_addr_id；管理員核准後 applyPossessionUpdateProposal
        // 重用 possessionUpdateById（is_array 守衛）同步 POSSESSION_ADDR（刪舊重插），而非掉進泛用單表 apply 漏掉地址。
        $proposer = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'poss-prop-approve@example.com');
        $this->actingAs($proposer);
        $this->seedPossession();
        $this->seedPossessionAddr(111);

        $this->postJson('/api/v2/mutate', $this->possessionPayload([
            'mode' => 'proposal',
            'changes' => ['c_notes' => '提案改地址', 'c_addr_id' => [222, 333]],
            'meta' => ['comment' => '提案：改地址'],
        ]))->assertOk();

        // 提案階段：原資料與副表皆未變動
        $this->assertDatabaseHas('POSSESSION_ADDR', ['c_possession_record_id' => 500, 'c_addr_id' => 111]);
        $this->assertDatabaseMissing('POSSESSION_ADDR', ['c_possession_record_id' => 500, 'c_addr_id' => 222]);

        $operation = Operation::query()
            ->where('resource', 'POSSESSION_DATA')
            ->where('op_type', Operation::TYPE_PROPOSAL_UPDATE)
            ->firstOrFail();
        $aux = json_decode($operation->resource_data, true)['__proposal_aux'] ?? [];
        $this->assertSame([222, 333], $aux['c_addr_id'] ?? null);

        // 管理員核准 → 副表落庫
        $admin = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_SUPER_ADMIN, 'poss-admin@example.com');
        $this->actingAs($admin);
        $this->post(route('operations.proposals.approve', $operation), ['review_comment' => '同意'])
            ->assertRedirect();

        $this->assertDatabaseMissing('POSSESSION_ADDR', ['c_possession_record_id' => 500, 'c_addr_id' => 111]);
        $this->assertDatabaseHas('POSSESSION_ADDR', ['c_possession_record_id' => 500, 'c_addr_id' => 222]);
        $this->assertDatabaseHas('POSSESSION_ADDR', ['c_possession_record_id' => 500, 'c_addr_id' => 333]);
        $this->assertDatabaseHas('POSSESSION_DATA', ['c_possession_record_id' => 500, 'c_notes' => '提案改地址']);
    }

    // ── Error Cases ─────────────────────────────────────────

    #[Test]
    public function testPossessionUpdateWithInvalidPkReturns404(): void {
        $user = $this->makeUser(email: 'poss-404@example.com');
        $this->actingAs($user);
        $this->seedPossession();

        $response = $this->postJson('/api/v2/mutate', $this->possessionPayload([
            'target' => ['pk' => [
                'c_possession_record_id' => 999,
            ]],
        ]));

        $response->assertStatus(404);
    }

    #[Test]
    public function testPossessionUpdateWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'poss-mismatch@example.com');
        $this->actingAs($user);
        $this->seedPossession();

        $response = $this->postJson('/api/v2/mutate', $this->possessionPayload([
            'person_id' => 9999,
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    #[Test]
    public function testPossessionUpdateWithEmptyChangesReturns422(): void {
        $user = $this->makeUser(email: 'poss-empty@example.com');
        $this->actingAs($user);
        $this->seedPossession();

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'possessions',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_possession_record_id' => 500,
                ],
            ],
            'changes' => [],
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function testPossessionUpdateWithDisallowedFieldReturns422(): void {
        $user = $this->makeUser(email: 'poss-disallowed@example.com');
        $this->actingAs($user);
        $this->seedPossession();

        $response = $this->postJson('/api/v2/mutate', $this->possessionPayload([
            'changes' => ['c_personid' => 9999],
        ]));

        $response->assertStatus(422);
        $this->assertStringContainsString('不允許', $response->json('message'));
    }

    #[Test]
    public function testPossessionUpdateWithNoEffectiveChangeReturns422(): void {
        $user = $this->makeUser(email: 'poss-nochange@example.com');
        $this->actingAs($user);
        $this->seedPossession(['c_notes' => '已有備註']);

        $response = $this->postJson('/api/v2/mutate', $this->possessionPayload([
            'changes' => ['c_notes' => '已有備註'],
        ]));

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'errors' => ['changes' => ['no_effective_changes']],
            ]);
    }

    #[Test]
    public function testPossessionUpdateRejectsUnauthenticatedUser(): void {
        $this->seedPossession();
        $response = $this->postJson('/api/v2/mutate', $this->possessionPayload());
        $response->assertStatus(401);
    }

    #[Test]
    public function testPossessionUpdateRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'poss-inactive@example.com');
        $this->actingAs($user);
        $this->seedPossession();

        $response = $this->postJson('/api/v2/mutate', $this->possessionPayload());
        $response->assertStatus(403);
    }

    #[Test]
    public function testPossessionDirectUpdateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'poss-crowd@example.com');
        $this->actingAs($user);
        $this->seedPossession();

        $response = $this->postJson('/api/v2/mutate', $this->possessionPayload());
        $response->assertStatus(403);
    }

    // ── #56 M 寫入等價（update 路徑，地址副表 POSSESSION_ADDR；possession 無鏡像）──────

    #[Test]
    public function testPossessionUpdateAcceptsAlias(): void {
        $user = $this->makeUser(email: 'poss-alias@example.com');
        $this->actingAs($user);
        $this->seedPossession();

        $response = $this->postJson('/api/v2/mutate', $this->possessionPayload([
            'resource' => 'possession',
        ]));

        $response->assertOk()
            ->assertJson(['ok' => true, 'resource' => 'possessions']);
    }
}
