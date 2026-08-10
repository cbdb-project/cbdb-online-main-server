<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2CreateKinshipTest extends TestCase {
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
        $this->createKinTable();
        $this->createKinshipCodesTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('KINSHIP_CODES');
        Schema::dropIfExists('KIN_DATA');
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

    protected function createKinTable(): void {
        Schema::create('KIN_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_kin_id')->default(0);
            $table->integer('c_kin_code')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->text('c_autogen_notes')->nullable();
            $table->string('c_created_by', 255)->nullable();
            $table->string('c_created_date', 255)->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->string('c_modified_date', 255)->nullable();
            $table->primary(['c_personid', 'c_kin_id', 'c_kin_code']);
        });
    }

    /**
     * 親屬碼配對表：未送 c_kinship_pair 時以 c_kin_pair1 查權威反向碼。75↔76、80↔81 互為配對。
     * 另加 82（c_kin_pair1=80）模擬「80 有第二個合法反向碼」的歧義場景：searchKinPair(80)={81,82}，
     * 故 c_kinship_pair=82 為合法覆寫、76/999 非法。
     */
    protected function createKinshipCodesTable(): void {
        Schema::create('KINSHIP_CODES', function (Blueprint $table) {
            $table->integer('c_kincode')->primary();
            $table->integer('c_kin_pair1')->nullable();
            $table->integer('c_kin_pair2')->nullable();
        });
        DB::table('KINSHIP_CODES')->insert([
            ['c_kincode' => 75, 'c_kin_pair1' => 76, 'c_kin_pair2' => null],
            ['c_kincode' => 76, 'c_kin_pair1' => 75, 'c_kin_pair2' => null],
            ['c_kincode' => 80, 'c_kin_pair1' => 81, 'c_kin_pair2' => null],
            ['c_kincode' => 81, 'c_kin_pair1' => 80, 'c_kin_pair2' => null],
            ['c_kincode' => 82, 'c_kin_pair1' => 80, 'c_kin_pair2' => null],
        ]);
    }

    protected function seedKin(array $overrides = []): void {
        DB::table('KIN_DATA')->insert(array_replace([
            'c_personid' => 1000,
            'c_kin_id' => 200,
            'c_kin_code' => 75,
            'c_source' => 10,
            'c_pages' => '1-5',
            'c_notes' => null,
        ], $overrides));
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'create-kin-tester@example.com'): User {
        return User::forceCreate([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function createPayload(array $overrides = []): array {
        return array_replace_recursive([
            'resource' => 'kinship',
            'person_id' => 1000,
            'mode' => 'direct',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_kin_id' => 300,
                    'c_kin_code' => 80,
                ],
            ],
            'changes' => [
                'c_source' => 20,
                'c_notes' => '新增親屬',
            ],
        ], $overrides);
    }

    #[Test]
    public function testDirectKinshipCreateWritesReciprocalMirror(): void {
        // 後台自動雙向同步：新增親屬關係時於同交易內無條件寫互逆鏡像列（對齊 legacy kinshipStoreById）。
        // 未送 c_kinship_pair → 後端以 KINSHIP_CODES[80].c_kin_pair1=81 權威補齊。
        $this->actingAs($this->makeUser(email: 'kin-mirror@example.com'));

        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_source' => 20, 'c_notes' => '甲之親'],
        ]))->assertOk()->assertJson(['ok' => true, 'operation' => 'create']);

        // 正向 (1000,300,80)。
        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 1000, 'c_kin_id' => 300, 'c_kin_code' => 80, 'c_notes' => '甲之親']);
        // 互逆鏡像 (300,1000,81)：對方為主體、原人為客體、反向親屬碼 81。
        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 300, 'c_kin_id' => 1000, 'c_kin_code' => 81, 'c_notes' => '甲之親']);
    }

    #[Test]
    public function testDirectKinshipCreateWithValidReversePairOverride(): void {
        // 使用者手選合法反向碼（82∈searchKinPair(80)={81,82}）→ 鏡像用 82 而非預設 81。
        $this->actingAs($this->makeUser(email: 'kin-pair-override@example.com'));

        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_source' => 20, 'c_notes' => '甲之親', 'c_kinship_pair' => 82],
        ]))->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 1000, 'c_kin_id' => 300, 'c_kin_code' => 80]);
        // 鏡像用手選的 82，非預設 c_kin_pair1=81。
        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 300, 'c_kin_id' => 1000, 'c_kin_code' => 82]);
        $this->assertDatabaseMissing('KIN_DATA', ['c_personid' => 300, 'c_kin_id' => 1000, 'c_kin_code' => 81]);
    }

    #[Test]
    public function testDirectKinshipCreateRejectsUnknownReversePair(): void {
        // 反向碼 999 不存在 → 422、整筆回滾（fail-closed，正向列也不寫）。
        $this->actingAs($this->makeUser(email: 'kin-pair-unknown@example.com'));

        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_source' => 20, 'c_notes' => 'x', 'c_kinship_pair' => 999],
        ]))->assertStatus(422)->assertJson(['ok' => false]);

        $this->assertDatabaseMissing('KIN_DATA', ['c_personid' => 1000, 'c_kin_id' => 300, 'c_kin_code' => 80]);
    }

    #[Test]
    public function testDirectKinshipCreateRejectsNonPairReverseCode(): void {
        // 76 是真實碼但非 80 的合法配對（searchKinPair(80)={81,82}）→ 422、回滾。
        $this->actingAs($this->makeUser(email: 'kin-pair-nonpair@example.com'));

        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_source' => 20, 'c_notes' => 'x', 'c_kinship_pair' => 76],
        ]))->assertStatus(422)->assertJson(['ok' => false]);

        $this->assertDatabaseMissing('KIN_DATA', ['c_personid' => 1000, 'c_kin_id' => 300, 'c_kin_code' => 80]);
    }

    #[Test]
    public function testDirectKinshipCreateSucceeds(): void {
        $user = $this->makeUser(email: 'create-kin-direct@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'kinship',
                'mode' => 'direct',
                'operation' => 'create',
            ]);

        $this->assertNotNull($response->json('result.operation_id'));
        $this->assertNotNull($response->json('result.row'));

        $this->assertDatabaseHas('KIN_DATA', [
            'c_personid' => 1000,
            'c_kin_id' => 300,
            'c_kin_code' => 80,
            'c_source' => 20,
            'c_notes' => '新增親屬',
        ]);
    }

    #[Test]
    public function testDirectKinshipCreatePersistsAutogenNotes(): void {
        // 回歸（Task 27）：補欄 c_autogen_notes 在 create 路徑須真的寫入 KIN_DATA。
        $user = $this->makeUser(email: 'create-kin-autogen@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_source' => 20, 'c_notes' => '新增', 'c_autogen_notes' => '自動備註X'],
        ]))->assertOk();

        $this->assertDatabaseHas('KIN_DATA', [
            'c_personid' => 1000, 'c_kin_id' => 300, 'c_kin_code' => 80,
            'c_autogen_notes' => '自動備註X',
        ]);
    }

    #[Test]
    public function testDirectKinshipCreateWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'create-kin-op@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'KIN_DATA',
            'op_type' => Operation::TYPE_CREATE,
        ]);
    }

    #[Test]
    public function testDirectKinshipCreateWritesAuditLog(): void {
        $user = $this->makeUser(email: 'create-kin-audit@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload());

        $audit = DB::table('audit_log')->where('table_name', 'KIN_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('INSERT', $audit->operation);
    }

    #[Test]
    public function testProposalKinshipCreateSucceeds(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-kin-proposal@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'proposal',
            'meta' => ['comment' => '提案新增親屬'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'kinship',
                'mode' => 'proposal',
                'operation' => 'create',
                'result' => ['status' => 'proposal_created'],
            ]);

        $this->assertDatabaseMissing('KIN_DATA', [
            'c_personid' => 1000,
            'c_kin_id' => 300,
            'c_kin_code' => 80,
        ]);
    }

    #[Test]
    public function testProposalKinshipCreateWritesProposalMeta(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-kin-prop-meta@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'proposal',
            'meta' => ['comment' => '提案新增親屬'],
        ]));

        $operation = DB::table('operations')->where('resource', 'KIN_DATA')->first();
        $this->assertNotNull($operation);
        $this->assertSame(Operation::TYPE_PROPOSAL_CREATE, (int) $operation->op_type);

        $resourceData = json_decode($operation->resource_data, true);
        $this->assertSame('pending', $resourceData['__review_status']);
        $this->assertSame('create', $resourceData['__proposal_meta']['action']);
        $this->assertSame('kinship', $resourceData['__proposal_meta']['resource_type']);
        $this->assertSame('提案新增親屬', $resourceData['__proposal_meta']['comment']);

        $this->assertDatabaseMissing('audit_log', ['table_name' => 'KIN_DATA']);
    }

    #[Test]
    public function testCreateDuplicateTargetPkReturns409(): void {
        $user = $this->makeUser(email: 'create-kin-dup@example.com');
        $this->actingAs($user);

        $this->seedKin([
            'c_personid' => 1000,
            'c_kin_id' => 300,
            'c_kin_code' => 80,
        ]);

        $this->postJson('/api/v2/create', $this->createPayload())
            ->assertStatus(409)->assertJson(['ok' => false]);
    }

    #[Test]
    public function testCreateDuplicatePendingProposalReturns409(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-kin-dup-prop@example.com');
        $this->actingAs($user);

        $payload = $this->createPayload(['mode' => 'proposal']);

        $this->postJson('/api/v2/create', $payload)->assertOk();
        $this->postJson('/api/v2/create', $payload)
            ->assertStatus(409)
            ->assertJson(['ok' => false, 'errors' => ['target.pk' => ['pending_proposal_exists']]]);

        $this->assertSame(1, DB::table('operations')->where('resource', 'KIN_DATA')->count());
    }

    #[Test]
    public function testCreateWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'create-kin-mismatch@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload(['person_id' => 9999]))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    #[Test]
    public function testCreateRejectsDisallowedField(): void {
        $user = $this->makeUser(email: 'create-kin-disallowed@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_not_a_real_column' => 'x'],
        ]));

        $response->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertArrayHasKey('changes', $response->json('errors'));
        $this->assertDatabaseMissing('KIN_DATA', [
            'c_personid' => 1000,
            'c_kin_id' => 300,
            'c_kin_code' => 80,
        ]);
    }

    #[Test]
    public function testCreateRejectsUnauthenticatedUser(): void {
        $this->postJson('/api/v2/create', $this->createPayload())->assertStatus(401);
    }

    #[Test]
    public function testCreateRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'create-kin-inactive@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload())->assertStatus(403);
    }

    #[Test]
    public function testDirectCreateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-kin-crowd@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload(['mode' => 'direct']))->assertStatus(403);
    }

    // ── #56 M 寫入等價（legacy Blade vs v2）+ 幂等 ──────────────────

    #[Test]
    public function testKinshipCreateCodeFieldSentinelFullyIdempotent(): void {
        // #71：create 路徑 c_source（legacy 哨兵 0=Unknown）完全幂等——null/''/'-999'/-999/'0'/0 落庫皆為 0、
        // 永不寫 null/''；合法非 0 值保留。每案用不同 c_kin_id 取得獨立 PK。≥10 案例。
        $this->actingAs($this->makeUser(email: 'kin-create-sentinel@example.com'));
        $T = 'KIN_DATA';
        $empties = [null, '', '-999', -999, '0', 0];
        foreach ($empties as $i => $sent) {
            $kinId = 400 + $i;
            $this->postJson('/api/v2/create', $this->createPayload([
                'target' => ['pk' => ['c_personid' => 1000, 'c_kin_id' => $kinId, 'c_kin_code' => 80]],
                'changes' => ['c_source' => $sent, 'c_notes' => '哨兵'.var_export($sent, true)],
            ]))->assertOk()->assertJson(['ok' => true]);
            $stored = DB::table($T)->where(['c_personid' => 1000, 'c_kin_id' => $kinId, 'c_kin_code' => 80])->value('c_source');
            $this->assertNotNull($stored, 'c_source 送 '.var_export($sent, true).' 不得為 null');
            $this->assertSame('0', (string) $stored, 'c_source 送 '.var_export($sent, true).' 應規範化為 0');
        }
        $legals = [5, 7, 999, 42];
        foreach ($legals as $i => $sent) {
            $kinId = 500 + $i;
            $this->postJson('/api/v2/create', $this->createPayload([
                'target' => ['pk' => ['c_personid' => 1000, 'c_kin_id' => $kinId, 'c_kin_code' => 80]],
                'changes' => ['c_source' => $sent, 'c_notes' => '合法'.$sent],
            ]))->assertOk();
            $stored = DB::table($T)->where(['c_personid' => 1000, 'c_kin_id' => $kinId, 'c_kin_code' => 80])->value('c_source');
            $this->assertSame($sent, (int) $stored, '合法非 0 值不得被誤清：'.$sent);
        }
    }

    // ── #70 鏡像疑似匹配（CREATE 路徑）─────────────────────────────
    // create 與 update 共用 syncKinMirrorOnUpdate 的 Option 2 安全判別：建立反向鏡像前，若對面已有「碼漂移
    // （∉ 合法 KINSHIP_CODE）」的疑似同關係列 → 非 force 時拋 409 errors.mirror_suspected（整筆回滾、正向也不建），
    // force 時就地收斂該漂移列為權威反向碼（不補出重複鏡像）；碼∈合法 code 的他段關係**絕不覆寫**。

    /** 在對面（300→1000）預埋一條反向鏡像列；預設碼 99（漂移垃圾值，∉ KINSHIP_CODES）。 */
    protected function seedReverseKin(array $overrides = []): void {
        DB::table('KIN_DATA')->insert(array_replace([
            'c_personid' => 300, 'c_kin_id' => 1000, 'c_kin_code' => 99,
            'c_source' => 5, 'c_pages' => '舊', 'c_notes' => '舊鏡像', 'c_autogen_notes' => 'AG',
        ], $overrides));
    }

    #[Test]
    public function testKinshipCreateMirrorSuspectedDetectedAborts(): void {
        // 對面已有碼漂移（99）疑似列、嚴格反向集 {81,82} 落空 → 非 force 建立時偵測疑似 → 409 + 整筆回滾。
        $this->actingAs($this->makeUser(email: 'kin-c-suspect@example.com'));
        $this->seedReverseKin(); // (300,1000,99) autogen=AG

        $res = $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_source' => 20, 'c_notes' => '甲之親', 'c_autogen_notes' => 'AG'],
        ]));

        $res->assertStatus(409)->assertJson(['ok' => false]);
        $this->assertSame('KIN_DATA', $res->json('errors.mirror_suspected.table'));
        $this->assertSame(81, $res->json('errors.mirror_suspected.authoritative_code'));
        $this->assertSame(1, $res->json('errors.mirror_suspected.count'));
        // 整筆回滾：正向列不得建立；漂移列維持原碼 99 不被觸碰。
        $this->assertDatabaseMissing('KIN_DATA', ['c_personid' => 1000, 'c_kin_id' => 300, 'c_kin_code' => 80]);
        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 300, 'c_kin_id' => 1000, 'c_kin_code' => 99]);
        // 不得補出第二條反向鏡像（81）。
        $this->assertDatabaseMissing('KIN_DATA', ['c_personid' => 300, 'c_kin_id' => 1000, 'c_kin_code' => 81]);
    }

    #[Test]
    public function testKinshipCreateMirrorSuspectedDetectedDespiteAutogenMismatch(): void {
        // #87（relaxed autogen 一致性）：對面漂移列 99 的 c_autogen_notes='AG'，但本次 create autogen='other'（不對稱）。
        // relaxed 分支不再認 c_autogen_notes → 仍偵測到漂移疑似 → 409 整筆回滾，而非因 autogen 不符漏抓→backfill 補出重複鏡像。
        $this->actingAs($this->makeUser(email: 'kin-c-suspect-autogen@example.com'));
        $this->seedReverseKin(); // (300,1000,99) autogen='AG'

        $res = $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_source' => 20, 'c_notes' => '甲之親', 'c_autogen_notes' => 'other'], // 與漂移列 autogen 不對稱
        ]));

        $res->assertStatus(409)->assertJson(['ok' => false]);
        $this->assertSame('KIN_DATA', $res->json('errors.mirror_suspected.table'));
        // 整筆回滾：正向未建、漂移 99 未動、不得補出第二條反向鏡像（81）。
        $this->assertDatabaseMissing('KIN_DATA', ['c_personid' => 1000, 'c_kin_id' => 300, 'c_kin_code' => 80]);
        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 300, 'c_kin_id' => 1000, 'c_kin_code' => 99]);
        $this->assertDatabaseMissing('KIN_DATA', ['c_personid' => 300, 'c_kin_id' => 1000, 'c_kin_code' => 81]);
    }

    #[Test]
    public function testKinshipCreateMirrorSuspectedForceCollapses(): void {
        // force=true → 就地收斂漂移列（99→權威反向碼 81、套用新內容），不補出重複鏡像；正向列建立。
        $this->actingAs($this->makeUser(email: 'kin-c-force@example.com'));
        $this->seedReverseKin();

        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_source' => 20, 'c_notes' => '甲之親', 'c_autogen_notes' => 'AG'],
            'meta' => ['force' => true],
        ]))->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 1000, 'c_kin_id' => 300, 'c_kin_code' => 80]);
        // 漂移列被收斂：99 消失、81 出現（內容更新為新鏡像 c_source=20）。
        $this->assertDatabaseMissing('KIN_DATA', ['c_personid' => 300, 'c_kin_id' => 1000, 'c_kin_code' => 99]);
        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 300, 'c_kin_id' => 1000, 'c_kin_code' => 81, 'c_source' => 20]);
        // 對面 (300→1000) 只剩一條反向列（無重複）。
        $this->assertSame(1, DB::table('KIN_DATA')->where(['c_personid' => 300, 'c_kin_id' => 1000])->count());
    }

    #[Test]
    public function testKinshipCreateValidCodeOtherReverseNotClobbered(): void {
        // Option 2 安全：對面預埋的是合法碼（75，∉ 嚴格反向集 {81,82}）的「他段關係」→ 視為合法、絕不覆寫，
        // 本段鏡像照常 backfill（81）；非 force 也不誤判為疑似。
        $this->actingAs($this->makeUser(email: 'kin-c-valid@example.com'));
        $this->seedReverseKin(['c_kin_code' => 75]); // (300,1000,75) 合法碼、他段關係

        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_source' => 20, 'c_notes' => '甲之親', 'c_autogen_notes' => 'AG'],
        ]))->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 1000, 'c_kin_id' => 300, 'c_kin_code' => 80]);
        // 他段合法關係 75 不被觸碰。
        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 300, 'c_kin_id' => 1000, 'c_kin_code' => 75, 'c_source' => 5]);
        // 本段反向鏡像 81 照常補建。
        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 300, 'c_kin_id' => 1000, 'c_kin_code' => 81, 'c_source' => 20]);
    }

    #[Test]
    public function testKinshipCreateNoExistingReverseBackfillsNormally(): void {
        // 對面無任何相符列 → 正常 backfill 寫反向鏡像（委派 sync 後 create 預設行為不變）。
        $this->actingAs($this->makeUser(email: 'kin-c-backfill@example.com'));

        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_source' => 20, 'c_notes' => '甲之親', 'c_autogen_notes' => 'AG'],
        ]))->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 1000, 'c_kin_id' => 300, 'c_kin_code' => 80]);
        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 300, 'c_kin_id' => 1000, 'c_kin_code' => 81]);
    }

    #[Test]
    public function testKinshipCreateStrictReverseDifferingContentConflicts(): void {
        // 非對稱安全（#66 套用 create）：對面已有「權威反向碼 81 嚴格命中」但內容不同（c_source=5≠欲寫 20）的既有列
        // → 非 force 拋 MirrorConflictException→409，整筆回滾（正向不建、既有列不被靜默覆寫）；force 才覆寫。
        $this->actingAs($this->makeUser(email: 'kin-c-strict-conf@example.com'));
        $this->seedReverseKin(['c_kin_code' => 81, 'c_source' => 5]); // (300,1000,81) 既有、不同內容、autogen=AG

        $res = $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_source' => 20, 'c_notes' => '甲之親', 'c_autogen_notes' => 'AG'],
        ]));
        $res->assertStatus(409)->assertJson(['ok' => false]);
        $this->assertSame('KIN_DATA', $res->json('errors.mirror_conflict.table'));
        // 回滾：正向未建、既有反向列內容（c_source=5）未被觸碰。
        $this->assertDatabaseMissing('KIN_DATA', ['c_personid' => 1000, 'c_kin_id' => 300, 'c_kin_code' => 80]);
        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 300, 'c_kin_id' => 1000, 'c_kin_code' => 81, 'c_source' => 5]);

        // force=true → 覆寫既有反向列為新內容（c_source 5→20），正向建立，對面仍只一條（無重複）。
        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_source' => 20, 'c_notes' => '甲之親', 'c_autogen_notes' => 'AG'],
            'meta' => ['force' => true],
        ]))->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 1000, 'c_kin_id' => 300, 'c_kin_code' => 80]);
        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 300, 'c_kin_id' => 1000, 'c_kin_code' => 81, 'c_source' => 20]);
        $this->assertSame(1, DB::table('KIN_DATA')->where(['c_personid' => 300, 'c_kin_id' => 1000])->count());
    }

    #[Test]
    public function testKinshipCreateStrictReverseIdenticalContentIdempotent(): void {
        // 對面既有嚴格命中列「內容相同」→ 不視為分歧（同內容鏡像可冪等通過），正常建立、對面仍一條（無重複/無 409）。
        $this->actingAs($this->makeUser(email: 'kin-c-strict-idem@example.com'));
        // 與下方 create 的鏡像內容一致：c_source=20、c_notes=甲之親、c_pages=null（createPayload 未送）。
        $this->seedReverseKin(['c_kin_code' => 81, 'c_source' => 20, 'c_notes' => '甲之親', 'c_pages' => null]);

        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_source' => 20, 'c_notes' => '甲之親', 'c_autogen_notes' => 'AG'],
        ]))->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 1000, 'c_kin_id' => 300, 'c_kin_code' => 80]);
        $this->assertSame(1, DB::table('KIN_DATA')->where(['c_personid' => 300, 'c_kin_id' => 1000])->count());
    }

    #[Test]
    public function testKinshipV2CreateIdempotentResendNoDuplicate(): void {
        // #56（幂等）：v2 create 同 PK 重送鎖死 409 + errors['target.pk']=['conflict']，KIN_DATA 行數不變。
        $this->actingAs($this->makeUser(email: 'kin-midem@example.com'));
        $payload = $this->createPayload([
            'target' => ['pk' => ['c_personid' => 1000, 'c_kin_id' => 303, 'c_kin_code' => 80]],
            'changes' => ['c_source' => 20, 'c_notes' => '幂等'],
        ]);

        $this->postJson('/api/v2/create', $payload)->assertOk()->assertJson(['ok' => true]);
        $countAfterFirst = DB::table('KIN_DATA')->count(); // 正向 + 鏡像 = 2
        // 幂等須涵蓋「聯動表」：重送被拒時，operations / audit_log 也不得多寫一筆（否則側效泄漏）。
        $opsAfterFirst = DB::table('operations')->count();
        $auditAfterFirst = DB::table('audit_log')->count();

        $this->postJson('/api/v2/create', $payload)
            ->assertStatus(409)
            ->assertJson(['ok' => false])
            ->assertJsonFragment(['target.pk' => ['conflict']]);
        $this->assertSame($countAfterFirst, DB::table('KIN_DATA')->count(), '重送不得新增重複列（含鏡像）');
        $this->assertSame($opsAfterFirst, DB::table('operations')->count(), '重送被拒不得新增 operations 列');
        $this->assertSame($auditAfterFirst, DB::table('audit_log')->count(), '重送被拒不得新增 audit_log 列');
    }
}
