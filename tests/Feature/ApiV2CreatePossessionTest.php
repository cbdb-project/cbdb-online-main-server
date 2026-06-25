<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2CreatePossessionTest extends TestCase {
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
        $this->createPossessionTables();
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

    protected function createPossessionTables(): void {
        Schema::create('POSSESSION_DATA', function (Blueprint $table) {
            $table->integer('c_possession_record_id')->primary();
            $table->integer('c_personid')->default(0);
            $table->integer('c_sequence')->nullable();
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->integer('c_possession_act_code')->nullable();
            $table->string('c_possession_desc', 255)->nullable();
            $table->string('c_possession_desc_chn', 255)->nullable();
            $table->string('c_quantity', 255)->nullable();
            $table->integer('c_measure_code')->nullable();
            $table->integer('c_possession_yr')->nullable();
            $table->integer('c_possession_nh_code')->nullable();
            $table->integer('c_possession_nh_yr')->nullable();
            $table->integer('c_possession_yr_range')->nullable();
            $table->string('c_created_by', 255)->nullable();
            $table->string('c_created_date', 255)->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->string('c_modified_date', 255)->nullable();
        });

        Schema::create('POSSESSION_ADDR', function (Blueprint $table) {
            $table->integer('c_personid')->default(0);
            $table->integer('c_possession_record_id')->default(0);
            $table->integer('c_addr_id')->default(0);
        });
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'create-poss-tester@example.com'): User {
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
            'resource' => 'possessions',
            'person_id' => 1000,
            'mode' => 'direct',
            'target' => ['pk' => []],
            'changes' => [
                'c_source' => 20,
                'c_notes' => '新增財產',
                'c_possession_yr' => 1050,
                'c_addr_id' => [130, 200],
            ],
        ], $overrides);
    }

    #[Test]
    public function testPossessionCreateCodeFieldSentinelFullyIdempotent(): void {
        // #71：create 路徑三個非 PK 碼欄（c_source / c_measure_code / c_possession_act_code）完全幂等——
        // null/''/'-999'/-999/'0'/0 落庫皆 0、永不寫 null/''；c_source 合法非 0 保留。≥10 案例（3 欄×6 空 + 3 合法）。
        $this->actingAs($this->makeUser(email: 'poss-create-sentinel@example.com'));
        $T = 'POSSESSION_DATA';
        foreach (['c_source', 'c_measure_code', 'c_possession_act_code'] as $field) {
            foreach ([null, '', '-999', -999, '0', 0] as $sent) {
                $resp = $this->postJson('/api/v2/create', $this->createPayload([
                    'changes' => [$field => $sent],
                ]))->assertOk();
                $id = $resp->json('result.pk.c_possession_record_id');
                $stored = DB::table($T)->where('c_possession_record_id', $id)->value($field);
                $this->assertNotNull($stored, $field.' 送 '.var_export($sent, true).' 不得為 null');
                $this->assertSame('0', (string) $stored, $field.' 送 '.var_export($sent, true).' 應規範化為 0');
            }
        }
        foreach ([5, 7, 999] as $sent) {
            $resp = $this->postJson('/api/v2/create', $this->createPayload([
                'changes' => ['c_source' => $sent],
            ]))->assertOk();
            $id = $resp->json('result.pk.c_possession_record_id');
            $this->assertSame($sent, (int) DB::table($T)->where('c_possession_record_id', $id)->value('c_source'), '合法非 0 值不得被誤清：'.$sent);
        }
        // 缺鍵（完全不送 c_source）→ 補哨兵 0（CREATE 語義：未填＝0）。亦驗證消除 legacy possessionStoreById
        // 直接讀 $data['c_source']（無 ??）的 undefined-index / 落 null（codex SERIOUS）。手動建 payload 以略過預設 c_source。
        $resp = $this->postJson('/api/v2/create', [
            'resource' => 'possessions', 'person_id' => 1000, 'mode' => 'direct',
            'target' => ['pk' => []],
            'changes' => ['c_notes' => '無 source', 'c_possession_yr' => 1050, 'c_addr_id' => [130]],
        ])->assertOk();
        $id = $resp->json('result.pk.c_possession_record_id');
        $stored = DB::table($T)->where('c_possession_record_id', $id)->value('c_source');
        $this->assertNotNull($stored, '缺鍵 c_source 不得為 null');
        $this->assertSame('0', (string) $stored, '缺鍵 c_source 應補哨兵 0');
    }

    #[Test]
    public function testDirectPossessionCreateSucceedsAndAllocatesId(): void {
        $user = $this->makeUser(email: 'create-poss-direct@example.com');
        $this->actingAs($user);

        // 既有一筆 id=5，新增應配發 6（max+1）
        DB::table('POSSESSION_DATA')->insert([
            'c_possession_record_id' => 5,
            'c_personid' => 1000,
            'c_source' => 1,
        ]);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'possessions',
            'mode' => 'direct',
            'operation' => 'create',
        ]);

        $this->assertSame(6, $response->json('result.pk.c_possession_record_id'));

        $this->assertDatabaseHas('POSSESSION_DATA', [
            'c_possession_record_id' => 6,
            'c_personid' => 1000,
            'c_source' => 20,
            'c_notes' => '新增財產',
            'c_possession_yr' => 1050,
        ]);
    }

    #[Test]
    public function testDirectPossessionCreatePersistsPossessionActCodeZero(): void {
        // React PossessionEditor 新增時對齊 legacy，未動的占有行為仍送 '0'（未詳碼）而非省略；
        // 確認 '0' 經白名單往返後正確落庫為 0（而非 NULL）。
        $user = $this->makeUser(email: 'create-poss-act0@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_possession_act_code' => '0', 'c_source' => '0'],
        ]));

        $response->assertOk()->assertJson(['ok' => true]);
        $newId = $response->json('result.pk.c_possession_record_id');
        $this->assertDatabaseHas('POSSESSION_DATA', [
            'c_possession_record_id' => $newId,
            'c_possession_act_code' => 0,
            'c_source' => 0,
        ]);
    }

    #[Test]
    public function testDirectPossessionCreateWritesAddressSideTable(): void {
        $user = $this->makeUser(email: 'create-poss-addr@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload());
        $newId = $response->json('result.pk.c_possession_record_id');

        $this->assertDatabaseHas('POSSESSION_ADDR', [
            'c_possession_record_id' => $newId,
            'c_personid' => 1000,
            'c_addr_id' => 130,
        ]);
        $this->assertDatabaseHas('POSSESSION_ADDR', [
            'c_possession_record_id' => $newId,
            'c_personid' => 1000,
            'c_addr_id' => 200,
        ]);
    }

    #[Test]
    public function testDirectPossessionCreateWritesOperationAndAudit(): void {
        $user = $this->makeUser(email: 'create-poss-op@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'POSSESSION_DATA',
            'op_type' => Operation::TYPE_CREATE,
        ]);

        $audit = DB::table('audit_log')->where('table_name', 'POSSESSION_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('INSERT', $audit->operation);
    }

    #[Test]
    public function testCreateDropsDisallowedFields(): void {
        $user = $this->makeUser(email: 'create-poss-whitelist@example.com');
        $this->actingAs($user);

        // 非白名單欄位應被丟棄、不致 insert 失敗
        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_not_a_real_column' => 'x'],
        ]));

        $response->assertOk()->assertJson(['ok' => true]);
        $newId = $response->json('result.pk.c_possession_record_id');
        $this->assertDatabaseHas('POSSESSION_DATA', ['c_possession_record_id' => $newId]);
    }

    #[Test]
    public function testProposalPossessionCreateWritesProposalOperationWithoutInsertingMainTable(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-poss-proposal@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload(['mode' => 'proposal']));

        $response->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'possessions',
            'mode' => 'proposal',
            'operation' => 'create',
            'result' => ['status' => 'proposal_created'],
        ]);

        $operation = Operation::where('resource', 'POSSESSION_DATA')
            ->where('op_type', Operation::TYPE_PROPOSAL_CREATE)
            ->first();
        $this->assertNotNull($operation);

        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('pending', $payload['__review_status']);
        $this->assertSame(['c_possession_record_id'], $payload['__key_columns']);
        $this->assertSame([130, 200], $payload['__proposal_aux']['c_addr_id']);

        // 提交時不應實際 insert 主表與副表（以 __review_status=pending 判定，無「已套用」列）
        $this->assertSame(0, DB::table('POSSESSION_DATA')->count());
        $this->assertSame(0, DB::table('POSSESSION_ADDR')->count());
    }

    #[Test]
    public function testProposalPossessionCreateApprovalWritesMainAndAddressTables(): void {
        $author = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-poss-approve-author@example.com');
        $this->actingAs($author);
        $this->postJson('/api/v2/create', $this->createPayload(['mode' => 'proposal']))->assertOk();

        $operation = Operation::where('resource', 'POSSESSION_DATA')
            ->where('op_type', Operation::TYPE_PROPOSAL_CREATE)
            ->firstOrFail();

        $reviewer = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_EXPERT, 'create-poss-reviewer@example.com');
        $this->actingAs($reviewer);

        $this->post(route('operations.proposals.approve', $operation))->assertRedirect();

        // 核准後配發 c_possession_record_id、寫主表
        $this->assertSame(1, DB::table('POSSESSION_DATA')->count());
        $row = DB::table('POSSESSION_DATA')->first();
        $this->assertSame(1000, (int) $row->c_personid);
        $this->assertSame(20, (int) $row->c_source);
        $this->assertSame('新增財產', $row->c_notes);

        // 副表 POSSESSION_ADDR 正確寫入
        $this->assertDatabaseHas('POSSESSION_ADDR', [
            'c_possession_record_id' => $row->c_possession_record_id,
            'c_personid' => 1000,
            'c_addr_id' => 130,
        ]);
        $this->assertDatabaseHas('POSSESSION_ADDR', [
            'c_possession_record_id' => $row->c_possession_record_id,
            'c_personid' => 1000,
            'c_addr_id' => 200,
        ]);

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status']);

        // operation/audit 不重複：核准只應產生 1 筆 TYPE_CREATE operation
        $this->assertSame(
            1,
            Operation::where('resource', 'POSSESSION_DATA')->where('op_type', Operation::TYPE_CREATE)->count()
        );
        $this->assertSame(
            1,
            DB::table('audit_log')->where('table_name', 'POSSESSION_DATA')->where('operation', 'INSERT')->count()
        );
    }

    #[Test]
    public function testProposalPossessionCreateAbsentSourceApprovesToSentinel(): void {
        // #71（proposal 路徑，codex SERIOUS 的另一半）：缺 c_source 的提案經正規化後，c_source='0' 入 operation；
        // 核准時 applyPossessionProposal→possessionStoreById 讀到 '0'（非缺鍵），不 undefined-index、主表落 0。
        $author = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-poss-absent-author@example.com');
        $this->actingAs($author);
        $this->postJson('/api/v2/create', [
            'resource' => 'possessions', 'person_id' => 1000, 'mode' => 'proposal',
            'target' => ['pk' => []],
            'changes' => ['c_notes' => '無 source 提案', 'c_possession_yr' => 1050, 'c_addr_id' => [130]],
            'meta' => ['comment' => '請審'],
        ])->assertOk()->assertJson(['ok' => true, 'mode' => 'proposal']);

        $operation = Operation::where('resource', 'POSSESSION_DATA')
            ->where('op_type', Operation::TYPE_PROPOSAL_CREATE)->firstOrFail();
        // 提案 payload 已正規化：缺 c_source → '0'。
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('0', (string) ($payload['c_source'] ?? null), '提案 payload c_source 應正規化為 0');

        $reviewer = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_EXPERT, 'create-poss-absent-reviewer@example.com');
        $this->actingAs($reviewer);
        $this->post(route('operations.proposals.approve', $operation))->assertRedirect();

        // 核准後主表落庫 c_source=0（非 null、無 undefined-index）。
        $row = DB::table('POSSESSION_DATA')->first();
        $this->assertNotNull($row, '核准後應寫主表');
        $this->assertNotNull($row->c_source, '核准後 c_source 不得為 null');
        $this->assertSame('0', (string) $row->c_source, '核准後 c_source 應為哨兵 0');
    }

    #[Test]
    public function testProposalPossessionCreateRejectDoesNotWriteTables(): void {
        $author = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-poss-reject-author@example.com');
        $this->actingAs($author);
        $this->postJson('/api/v2/create', $this->createPayload(['mode' => 'proposal']))->assertOk();

        $operation = Operation::where('resource', 'POSSESSION_DATA')
            ->where('op_type', Operation::TYPE_PROPOSAL_CREATE)
            ->firstOrFail();

        $reviewer = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_EXPERT, 'create-poss-reject-reviewer@example.com');
        $this->actingAs($reviewer);

        $this->post(route('operations.proposals.reject', $operation))->assertRedirect();

        $this->assertSame(0, DB::table('POSSESSION_DATA')->count());
        $this->assertSame(0, DB::table('POSSESSION_ADDR')->count());

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('rejected', $payload['__review_status']);
    }

    #[Test]
    public function testCreateRejectsUnknownPersonZero(): void {
        $user = $this->makeUser(email: 'create-poss-zero@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload(['person_id' => 0]))
            ->assertStatus(422);
    }

    #[Test]
    public function testCreateRejectsUnauthenticatedUser(): void {
        $this->postJson('/api/v2/create', $this->createPayload())->assertStatus(401);
    }

    #[Test]
    public function testCreateRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'create-poss-inactive@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload())->assertStatus(403);
    }

    #[Test]
    public function testDirectCreateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-poss-crowd@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload(['mode' => 'direct']))->assertStatus(403);
    }

    // ── #56 M 寫入等價（legacy Blade vs v2）——主列 + 地址副表 POSSESSION_ADDR ──────

    #[Test]
    #[Group('legacy-parity')] // 旧版下线时連同 legacy 路徑一併移除（v2 行為另有 v2-only 測試覆蓋）
    public function testPossessionCreateWriteEquivalenceLegacyVsV2WithAddressSubtable(): void {
        // #56（M 維度，副表）：財產同語義輸入分別經 ① legacy Blade store（possessionStoreById）
        // ② v2 /api/v2/create 寫入（各自配發不同自增 c_possession_record_id），斷言主列 POSSESSION_DATA 內容欄等價，
        // 且**地址副表 POSSESSION_ADDR 兩路都落庫**（addr 130 與 200）——「副表靜默不落庫」探針。
        // legacy 與 v2 皆以 c_addr_id=>[陣列] 送多地址。
        $this->actingAs($this->makeUser(email: 'poss-mwrite@example.com'));

        // ① legacy。
        $this->post('/basicinformation/1000/possession', [
            'action' => 'save',
            'c_source' => 20, 'c_notes' => 'M 等價', 'c_possession_yr' => 1050,
            'c_addr_id' => [130, 200],
        ]);
        $legacyId = (int) DB::table('POSSESSION_DATA')->where('c_personid', 1000)->value('c_possession_record_id');

        // ② v2：同語義輸入。
        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_source' => 20, 'c_notes' => 'M 等價', 'c_possession_yr' => 1050, 'c_addr_id' => [130, 200]],
        ]))->assertOk()->assertJson(['ok' => true, 'operation' => 'create']);
        $v2Id = (int) DB::table('POSSESSION_DATA')->where('c_personid', 1000)->where('c_possession_record_id', '!=', $legacyId)->value('c_possession_record_id');

        $this->assertNotSame(0, $legacyId, 'legacy 未寫主列');
        $this->assertNotSame(0, $v2Id, 'v2 未寫主列');
        $this->assertNotSame($legacyId, $v2Id, '兩路應寫不同 record id（各自自增）');

        // 主列內容欄等價（排除差異 c_possession_record_id + 稽核欄）。
        $legacyMain = (array) DB::table('POSSESSION_DATA')->where('c_possession_record_id', $legacyId)->first();
        $v2Main = (array) DB::table('POSSESSION_DATA')->where('c_possession_record_id', $v2Id)->first();
        foreach (['c_personid', 'c_source', 'c_notes', 'c_possession_yr'] as $col) {
            $this->assertSame((string) $legacyMain[$col], (string) $v2Main[$col], "主列欄 {$col} 新舊不等價");
        }

        // 地址副表：兩路都寫了 130 與 200（核心斷言——多地址副表不得靜默不落庫）。
        foreach ([130, 200] as $addrId) {
            $this->assertNotNull(
                DB::table('POSSESSION_ADDR')->where(['c_possession_record_id' => $legacyId, 'c_addr_id' => $addrId])->first(),
                "legacy 未寫 POSSESSION_ADDR addr {$addrId}"
            );
            $this->assertNotNull(
                DB::table('POSSESSION_ADDR')->where(['c_possession_record_id' => $v2Id, 'c_addr_id' => $addrId])->first(),
                "v2 未寫 POSSESSION_ADDR addr {$addrId}（副表靜默不落庫）"
            );
        }
    }
}
