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

class ApiV2MutateKinshipTest extends TestCase {
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
        $this->createKinshipTable();
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

    protected function createKinshipTable(): void {
        Schema::create('KIN_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_kin_id')->default(0);
            $table->integer('c_kin_code')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->text('c_autogen_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
            $table->primary(['c_personid', 'c_kin_id', 'c_kin_code']);
        });
    }

    /** 親屬碼配對表：反向碼一律以 c_kin_pair1 權威推導。72↔73、75↔76 互為配對。 */
    protected function createKinshipCodesTable(): void {
        Schema::create('KINSHIP_CODES', function (Blueprint $table) {
            $table->integer('c_kincode')->primary();
            $table->integer('c_kin_pair1')->nullable();
            $table->integer('c_kin_pair2')->nullable();
        });
        DB::table('KINSHIP_CODES')->insert([
            ['c_kincode' => 72, 'c_kin_pair1' => 73, 'c_kin_pair2' => null],
            ['c_kincode' => 73, 'c_kin_pair1' => 72, 'c_kin_pair2' => null],
            ['c_kincode' => 75, 'c_kin_pair1' => 76, 'c_kin_pair2' => null],
            ['c_kincode' => 76, 'c_kin_pair1' => 75, 'c_kin_pair2' => null],
            // 74 為 72 的第二個合法反向碼（searchKinPair(72)={73,74}）以測手選覆寫。
            ['c_kincode' => 74, 'c_kin_pair1' => 72, 'c_kin_pair2' => null],
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────

    protected function seedKinship(array $overrides = []): void {
        DB::table('KIN_DATA')->insert(array_replace([
            'c_personid' => 1000,
            'c_kin_id' => 2000,
            'c_kin_code' => 72,
            'c_source' => 10,
            'c_pages' => '1-5',
            'c_notes' => null,
        ], $overrides));
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'kin-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function kinshipPayload(array $overrides = []): array {
        $payload = [
            'resource' => 'kinship',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_kin_id' => 2000,
                    'c_kin_code' => 72,
                ],
            ],
            'changes' => [
                'c_notes' => '新的備註',
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    // ── 反向鏡像同步（update）─────────────────────────────────

    #[Test]
    public function testDirectKinshipUpdateSyncsReciprocalMirror(): void {
        // 正向 (1000,2000,72) 與反向鏡像 (2000,1000,73)（72↔73 配對）同備註以利定位；
        // 更新正向 c_notes 後，反向鏡像應同步且配對碼維持 73、不被洗成 0。
        $user = $this->makeUser(email: 'kin-mirror-upd@example.com');
        $this->actingAs($user);
        $this->seedKinship(['c_kin_code' => 72, 'c_notes' => '原備註', 'c_autogen_notes' => 'auto-x']);
        DB::table('KIN_DATA')->insert([
            'c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 73,
            'c_source' => 10, 'c_notes' => '原備註', 'c_autogen_notes' => 'auto-x',
        ]);

        // #66：鏡像既有 c_notes='原備註' 與本次寫入 '改後備註' 不同會觸發衝突閘；本測試專驗同步機制，故 force 略過。
        $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'changes' => ['c_notes' => '改後備註'],
            'meta' => ['force' => true],
        ]))->assertOk();

        // 正向更新
        $this->assertDatabaseHas('KIN_DATA', [
            'c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 72, 'c_notes' => '改後備註',
        ]);
        // 反向鏡像同步（備註同步、配對碼維持 73）
        $this->assertDatabaseHas('KIN_DATA', [
            'c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 73, 'c_notes' => '改後備註',
        ]);
        // 反向鏡像配對碼未被洗成 0
        $this->assertDatabaseMissing('KIN_DATA', [
            'c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 0,
        ]);
    }

    #[Test]
    public function testDirectKinshipUpdateWithValidReversePairOverride(): void {
        // 使用者手選合法反向碼（74∈searchKinPair(72)={73,74}）+ 改備註 → 鏡像關係碼改為 74。
        $this->actingAs($this->makeUser(email: 'kin-upd-pair-override@example.com'));
        $this->seedKinship(['c_kin_code' => 72, 'c_notes' => '原備註', 'c_autogen_notes' => 'auto-z']);
        DB::table('KIN_DATA')->insert([
            'c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 73,
            'c_source' => 10, 'c_notes' => '原備註', 'c_autogen_notes' => 'auto-z',
        ]);

        // #66：鏡像既有 c_notes='原備註'/c_kin_code=73 與本次寫入不同會觸發衝突閘；本測試專驗手選覆寫機制，故 force 略過。
        $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'changes' => ['c_notes' => '改後', 'c_kinship_pair' => 74],
            'meta' => ['force' => true],
        ]))->assertOk();

        // 反向鏡像由 73 改為手選的 74（以舊碼 72 的配對 {73} 定位既有列後更新）。
        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 74, 'c_notes' => '改後']);
        $this->assertDatabaseMissing('KIN_DATA', ['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 73]);
    }

    #[Test]
    public function testDirectKinshipUpdateRejectsInvalidReversePair(): void {
        // 非法反向碼 999 → 422、整筆回滾（正向備註不變）。
        $this->actingAs($this->makeUser(email: 'kin-upd-pair-bad@example.com'));
        $this->seedKinship(['c_kin_code' => 72, 'c_notes' => '原備註']);

        $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'changes' => ['c_notes' => '改後', 'c_kinship_pair' => 999],
        ]))->assertStatus(422)->assertJson(['ok' => false]);

        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 72, 'c_notes' => '原備註']);
    }

    #[Test]
    public function testDirectKinshipUpdateNotesPreservesOverriddenReversePair(): void {
        // 既有鏡像反向碼為 74（先前手選覆寫，非預設 73）；僅改備註、未送覆寫
        // → 鏡像 c_kin_code 應「保留 74」，不被非關係編輯洗回 c_kin_pair1=73。
        $this->actingAs($this->makeUser(email: 'kin-upd-preserve@example.com'));
        $this->seedKinship(['c_kin_code' => 72, 'c_notes' => '原備註', 'c_autogen_notes' => 'auto-p']);
        DB::table('KIN_DATA')->insert([
            'c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 74,
            'c_source' => 10, 'c_notes' => '原備註', 'c_autogen_notes' => 'auto-p',
        ]);

        // #66：鏡像既有 c_notes='原備註' 與本次寫入 '改後' 不同會觸發衝突閘；本測試專驗保留覆寫碼機制，故 force 略過。
        $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'changes' => ['c_notes' => '改後'],
            'meta' => ['force' => true],
        ]))->assertOk();

        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 74, 'c_notes' => '改後']);
        $this->assertDatabaseMissing('KIN_DATA', ['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 73]);
    }

    // ── #66 雙向鏡像衝突偵測 ──────────────────────────────────

    #[Test]
    public function testDirectKinshipUpdateConflictBlockedAndRolledBack(): void {
        // #66（真分歧）：對面鏡像被獨立改過——鏡像 c_notes='對面被獨立改'，與正向「編輯前舊值」'原備註' 不同。
        // direct 改正向 notes 為 '改後'（未 force）→ 偵測到真分歧 → 409 + 明細；整筆回滾：正向維持舊值、鏡像不被覆寫。
        $this->actingAs($this->makeUser(email: 'kin-conflict@example.com'));
        $this->seedKinship(['c_kin_code' => 72, 'c_notes' => '原備註', 'c_autogen_notes' => 'auto-c']);
        DB::table('KIN_DATA')->insert([
            'c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 73,
            'c_source' => 10, 'c_notes' => '對面被獨立改', 'c_autogen_notes' => 'auto-c', // ≠ 正向舊值 → 真分歧
        ]);

        $res = $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'changes' => ['c_notes' => '改後'],
        ]))->assertStatus(409);

        $res->assertJsonPath('errors.mirror_conflict.table', 'KIN_DATA');
        $res->assertJsonPath('errors.mirror_conflict.pk.c_personid', 2000);
        $res->assertJsonPath('errors.mirror_conflict.pk.c_kin_id', 1000);
        $this->assertContains('c_notes', array_map(static fn ($c) => $c['field'], $res->json('errors.mirror_conflict.fields')));

        // 整筆回滾：正向維持 '原備註'、鏡像維持 '對面被獨立改'。
        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 72, 'c_notes' => '原備註']);
        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 73, 'c_notes' => '對面被獨立改']);
    }

    #[Test]
    public function testDirectKinshipUpdateInSyncEditNotBlocked(): void {
        // #66（修過度觸發）：正常同步編輯——鏡像 c_notes 與正向「編輯前舊值」相同（本來同步）。
        // 把正向 notes 從 '同步值' 改成 '新值'（未 force）→ 不應誤報衝突 → 200，鏡像靜默同步為 '新值'。
        $this->actingAs($this->makeUser(email: 'kin-insync@example.com'));
        $this->seedKinship(['c_kin_code' => 72, 'c_notes' => '同步值', 'c_autogen_notes' => 'auto-s']);
        DB::table('KIN_DATA')->insert([
            'c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 73,
            'c_source' => 10, 'c_notes' => '同步值', 'c_autogen_notes' => 'auto-s', // == 正向舊值 → 同步、非分歧
        ]);

        $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'changes' => ['c_notes' => '新值'],
        ]))->assertOk()->assertJson(['ok' => true]);

        // 正常同步：正向與鏡像皆更新為 '新值'（不被 #66 誤擋）。
        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 72, 'c_notes' => '新值']);
        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 73, 'c_notes' => '新值']);
    }

    #[Test]
    public function testDirectKinshipUpdateForceOverwritesConflict(): void {
        // #66：同「真分歧」情境，帶 meta.force=true → 跳過偵測、照常覆寫；正向與鏡像 notes 皆更新為 '改後'。
        $this->actingAs($this->makeUser(email: 'kin-conflict-force@example.com'));
        $this->seedKinship(['c_kin_code' => 72, 'c_notes' => '原備註', 'c_autogen_notes' => 'auto-cf']);
        DB::table('KIN_DATA')->insert([
            'c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 73,
            'c_source' => 10, 'c_notes' => '對面被獨立改', 'c_autogen_notes' => 'auto-cf', // ≠ 正向舊值 → 真分歧
        ]);

        $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'changes' => ['c_notes' => '改後'],
            'meta' => ['force' => true],
        ]))->assertOk();

        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 72, 'c_notes' => '改後']);
        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 73, 'c_notes' => '改後']);
    }

    #[Test]
    public function testProposalKinshipUpdateNotBlockedByMirrorConflict(): void {
        // #66：proposal 流程不偵測鏡像衝突（決策：僅 direct）。即使對面真分歧也應正常送出提案（寫 operations、不直改），不回 409。
        $this->actingAs($this->makeUser(email: 'kin-conflict-proposal@example.com'));
        $this->seedKinship(['c_kin_code' => 72, 'c_notes' => '原備註', 'c_autogen_notes' => 'auto-cp']);
        DB::table('KIN_DATA')->insert([
            'c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 73,
            'c_source' => 10, 'c_notes' => '對面被獨立改', 'c_autogen_notes' => 'auto-cp', // 真分歧；proposal 仍不擋
        ]);

        $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'mode' => 'proposal',
            'changes' => ['c_notes' => '改後'],
            'meta' => ['comment' => '提案改備註'],
        ]))->assertOk()->assertJson(['ok' => true, 'mode' => 'proposal']);

        // proposal 不直改：正向維持 '原備註'、鏡像維持 '對面被獨立改'。
        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 72, 'c_notes' => '原備註']);
        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 73, 'c_notes' => '對面被獨立改']);
    }

    #[Test]
    public function testDirectKinshipUpdateWithoutReciprocalMirrorDoesNotFabricate(): void {
        // allowBackfill=false（對齊 legacy）：找不到反向列時不臆造鏡像；非關係編輯（改備註）不應產生新列。
        $user = $this->makeUser(email: 'kin-mirror-none@example.com');
        $this->actingAs($user);
        $this->seedKinship(['c_kin_code' => 72, 'c_autogen_notes' => 'auto-y']);

        $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'changes' => ['c_notes' => '只改備註'],
        ]))->assertOk();

        // 仍只有 1 筆（正向），未補建反向。
        $this->assertSame(1, DB::table('KIN_DATA')->count());
        $this->assertDatabaseMissing('KIN_DATA', ['c_personid' => 2000, 'c_kin_id' => 1000]);
    }

    // ── Direct Update Tests ─────────────────────────────────

    #[Test]
    public function testDirectKinshipUpdateSucceeds(): void {
        $user = $this->makeUser(email: 'kin-direct@example.com');
        $this->actingAs($user);
        $this->seedKinship();

        $response = $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'changes' => ['c_notes' => '更新備註', 'c_pages' => '10-20'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'kinship',
                'mode' => 'direct',
                'operation' => 'update',
                'result' => [
                    'pk' => [
                        'c_personid' => 1000,
                        'c_kin_id' => 2000,
                        'c_kin_code' => 72,
                    ],
                    'updated_fields' => ['c_notes', 'c_pages'],
                ],
            ]);

        $this->assertDatabaseHas('KIN_DATA', [
            'c_personid' => 1000,
            'c_notes' => '更新備註',
            'c_pages' => '10-20',
        ]);
    }

    #[Test]
    public function testDirectKinshipUpdatePersistsAutogenNotesAndDoesNotNullOthers(): void {
        // 回歸（Task 27）：補欄 c_autogen_notes（KIN_DATA 真實欄）須能寫入；只改一個欄位時，
        // 未送出的 c_autogen_notes / c_pages 不可被清成 null —— 防「保存即清空」資料流失。
        $user = $this->makeUser(email: 'kin-autogen@example.com');
        $this->actingAs($user);
        $this->seedKinship(['c_autogen_notes' => '原始自動備註', 'c_pages' => '7-9']);

        // (a) 直接更新 c_autogen_notes 應成功寫入。
        $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'changes' => ['c_autogen_notes' => '新自動備註'],
        ]))->assertOk();
        $this->assertDatabaseHas('KIN_DATA', [
            'c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 72,
            'c_autogen_notes' => '新自動備註', 'c_pages' => '7-9',
        ]);

        // (b) 只改 c_notes（不送 c_autogen_notes/c_pages）後，兩者仍保留、未被清空。
        $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'changes' => ['c_notes' => '只改備註'],
        ]))->assertOk();
        $this->assertDatabaseHas('KIN_DATA', [
            'c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 72,
            'c_notes' => '只改備註', 'c_autogen_notes' => '新自動備註', 'c_pages' => '7-9',
        ]);
    }

    #[Test]
    public function testDirectKinshipUpdateReturnsOperationIdAndRow(): void {
        $user = $this->makeUser(email: 'kin-result@example.com');
        $this->actingAs($user);
        $this->seedKinship();

        $response = $this->postJson('/api/v2/mutate', $this->kinshipPayload());
        $data = $response->json();

        $this->assertNotNull($data['result']['operation_id']);
        $this->assertNotNull($data['result']['row']);
        $this->assertSame('新的備註', $data['result']['row']['c_notes']);
    }

    #[Test]
    public function testDirectKinshipUpdateWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'kin-op@example.com');
        $this->actingAs($user);
        $this->seedKinship();

        $this->postJson('/api/v2/mutate', $this->kinshipPayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'KIN_DATA',
            'op_type' => Operation::TYPE_UPDATE,
        ]);
    }

    #[Test]
    public function testDirectKinshipUpdateWritesAuditLog(): void {
        $user = $this->makeUser(email: 'kin-audit@example.com');
        $this->actingAs($user);
        $this->seedKinship();

        $this->postJson('/api/v2/mutate', $this->kinshipPayload());

        $audit = DB::table('audit_log')->where('table_name', 'KIN_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('UPDATE', $audit->operation);
    }

    // ── Proposal Update Tests ───────────────────────────────

    #[Test]
    public function testProposalKinshipUpdateCreatesPendingOperation(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'kin-proposal@example.com');
        $this->actingAs($user);
        $this->seedKinship();

        $response = $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'mode' => 'proposal',
            'changes' => ['c_notes' => '提案備註'],
            'meta' => ['comment' => '提案修改備註'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'kinship',
                'mode' => 'proposal',
                'operation' => 'update',
                'result' => [
                    'updated_fields' => ['c_notes'],
                    'status' => 'proposal_updated',
                ],
            ]);

        // 原始資料未被修改
        $this->assertDatabaseHas('KIN_DATA', [
            'c_personid' => 1000,
            'c_notes' => null,
        ]);

        // 已寫入提案操作紀錄
        $this->assertDatabaseHas('operations', [
            'resource' => 'KIN_DATA',
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
        ]);

        $operation = DB::table('operations')->where('resource', 'KIN_DATA')->first();
        $resourceData = json_decode($operation->resource_data, true);

        $this->assertSame('pending', $resourceData['__review_status']);
        $this->assertSame('update', $resourceData['__proposal_meta']['action']);
        $this->assertSame('kinship', $resourceData['__proposal_meta']['resource_type']);
        $this->assertSame('提案修改備註', $resourceData['__proposal_meta']['comment']);

        // 無 audit_log
        $this->assertDatabaseMissing('audit_log', ['table_name' => 'KIN_DATA']);
    }

    // ── Error Cases ─────────────────────────────────────────

    #[Test]
    public function testKinshipUpdateWithInvalidPkReturns404(): void {
        $user = $this->makeUser(email: 'kin-404@example.com');
        $this->actingAs($user);
        $this->seedKinship();

        $response = $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'target' => ['pk' => [
                'c_personid' => 1000,
                'c_kin_id' => 999,
                'c_kin_code' => 72,
            ]],
        ]));

        $response->assertStatus(404);
    }

    #[Test]
    public function testKinshipUpdateWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'kin-mismatch@example.com');
        $this->actingAs($user);
        $this->seedKinship();

        $response = $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'person_id' => 9999,
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    #[Test]
    public function testKinshipUpdateWithEmptyChangesReturns422(): void {
        $user = $this->makeUser(email: 'kin-empty@example.com');
        $this->actingAs($user);
        $this->seedKinship();

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'kinship',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_kin_id' => 2000,
                    'c_kin_code' => 72,
                ],
            ],
            'changes' => [],
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function testKinshipUpdateWithDisallowedFieldReturns422(): void {
        $user = $this->makeUser(email: 'kin-disallowed@example.com');
        $this->actingAs($user);
        $this->seedKinship();

        $response = $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'changes' => ['c_personid' => 9999],
        ]));

        $response->assertStatus(422);
        $this->assertStringContainsString('不允許', $response->json('message'));
    }

    #[Test]
    public function testKinshipUpdateWithNoEffectiveChangeReturns422(): void {
        $user = $this->makeUser(email: 'kin-nochange@example.com');
        $this->actingAs($user);
        $this->seedKinship(['c_notes' => '已有備註']);

        $response = $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'changes' => ['c_notes' => '已有備註'],
        ]));

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'errors' => ['changes' => ['no_effective_changes']],
            ]);
    }

    #[Test]
    public function testKinshipUpdateRejectsUnauthenticatedUser(): void {
        $this->seedKinship();
        $response = $this->postJson('/api/v2/mutate', $this->kinshipPayload());
        $response->assertStatus(401);
    }

    #[Test]
    public function testKinshipUpdateRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'kin-inactive@example.com');
        $this->actingAs($user);
        $this->seedKinship();

        $response = $this->postJson('/api/v2/mutate', $this->kinshipPayload());
        $response->assertStatus(403);
    }

    #[Test]
    public function testKinshipDirectUpdateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'kin-crowd@example.com');
        $this->actingAs($user);
        $this->seedKinship();

        $response = $this->postJson('/api/v2/mutate', $this->kinshipPayload());
        $response->assertStatus(403);
    }

    #[Test]
    #[Group('legacy-parity')] // 旧版下线時連同 legacy update 路徑一併移除
    public function testKinUpdateWriteEquivalenceLegacyVsV2(): void {
        // #56 M（update，鏡像）+ #66 端到端驗證（kinship，與 assoc 對稱）：同一初始 in-sync 狀態下，
        // ① 旧版改一筆 notes→記錄結果 → ② 復原初始 → ③ 新版改同一筆（**無 force**，#66 修復後 in-sync 編輯不再誤擋）
        // → 對比兩版落庫結果（正向 + 反向鏡像）等價。正常同步編輯下默認 v2 == 旧版，本測試即其端到端證明。
        $this->actingAs($this->makeUser(email: 'kin-mupd@example.com'));

        $pk = ['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 72];
        $seedInitial = function (): void {
            DB::table('KIN_DATA')->delete();
            // 正向 (1000,2000,72) 與反向鏡像 (2000,1000,73)（72↔73 配對）；備註/出處/autogen 同值＝in-sync。
            $this->seedKinship(['c_kin_code' => 72, 'c_notes' => '初始備註', 'c_source' => 10, 'c_pages' => '1-5', 'c_autogen_notes' => 'auto-x']);
            DB::table('KIN_DATA')->insert([
                'c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 73,
                'c_source' => 10, 'c_pages' => '1-5', 'c_notes' => '初始備註', 'c_autogen_notes' => 'auto-x',
            ]);
        };
        // 比對涵蓋內容欄；亦保留 c_kin_id/c_kin_code（鏡像方向寫錯主要由 fetch 的 where 抓出，此處為冗餘佐證）。排除稽核時間欄。
        $cols = ['c_kin_code', 'c_kin_id', 'c_source', 'c_pages', 'c_notes', 'c_autogen_notes'];
        $pick = function ($row) use ($cols): ?array {
            if (!$row) {
                return null;
            }
            $a = array_intersect_key((array) $row, array_flip($cols));
            ksort($a);

            return $a;
        };

        // ① 旧版改一筆：notes→改後、且 c_source 10→20（同改一個偏離 seed 初值的內容欄，
        //    使 assertSame 真正鎖「兩版對同一改動落庫一致」而非「兩版都沒動到該欄」）。PK 不變、送反向碼 73。
        $seedInitial();
        $this->put('/basicinformation/1000/kinship/update?' . http_build_query($pk), array_merge($pk, [
            'c_source' => 20, 'c_notes' => '改後', 'c_kinship_pair' => 73, 'action' => 'save',
        ]))->assertStatus(302); // legacy 成功後 redirect（非寫入成功的充分證明；真正的鎖在下方內容斷言）
        $this->assertSame(2, DB::table('KIN_DATA')->count(), 'legacy 更新後應仍為正向+鏡像各一（無孤兒/重複列）');
        $legacyFwd = $pick(DB::table('KIN_DATA')->where(['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 72])->first());
        $legacyMir = $pick(DB::table('KIN_DATA')->where(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 73])->first());

        // ② 復原初始 → ③ 新版改同一筆（無 force；同樣改 c_source→20）。
        $seedInitial();
        $this->postJson('/api/v2/mutate', [
            'resource' => 'kinship', 'person_id' => 1000, 'mode' => 'direct', 'operation' => 'update',
            'target' => ['pk' => $pk],
            'changes' => ['c_notes' => '改後', 'c_source' => 20, 'c_kinship_pair' => 73],
        ])->assertOk()->assertJson(['ok' => true]); // #66 修復後 in-sync 不再 409
        $this->assertSame(2, DB::table('KIN_DATA')->count(), 'v2 更新後應仍為正向+鏡像各一（無孤兒/重複列）');
        $v2Fwd = $pick(DB::table('KIN_DATA')->where(['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 72])->first());
        $v2Mir = $pick(DB::table('KIN_DATA')->where(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 73])->first());

        // 兩版落庫結果等價（正向 + 反向鏡像）；先確認改動真的落庫（c_notes/c_source 偏離 seed），再比兩版一致。
        $this->assertNotNull($legacyFwd, 'legacy 更新後正向列不存在');
        $this->assertNotNull($v2Fwd, 'v2 更新後正向列不存在');
        $this->assertSame('改後', $v2Fwd['c_notes'], 'v2 正向 notes 應更新為改後（in-sync 不被 #66 誤擋）');
        $this->assertSame(20, (int) $v2Fwd['c_source'], 'v2 正向 c_source 應更新為 20（partial update 確有落庫）');
        $this->assertSame($legacyFwd, $v2Fwd, '正向列 legacy vs v2 更新結果不等價');
        $this->assertNotNull($legacyMir, 'legacy 更新後反向鏡像不存在');
        $this->assertNotNull($v2Mir, 'v2 更新後反向鏡像不存在');
        $this->assertSame($legacyMir, $v2Mir, '反向鏡像 legacy vs v2 同步結果不等價');
        $this->assertSame('改後', $v2Mir['c_notes'], '鏡像 notes 應同步為改後');
        $this->assertSame(20, (int) $v2Mir['c_source'], '鏡像 c_source 應同步為 20');
    }

    #[Test]
    public function testKinshipCodeFieldSentinelFullyIdempotent(): void {
        // c_source（legacy 哨兵 0=Unknown）所有空表示 null/''/-999/'0'/0 → 0、合法值保留、來回不翻。≥10 案例。
        $this->actingAs($this->makeUser(email: 'kin-sentinel@example.com'));
        $T = 'KIN_DATA';
        $f = 'c_source';
        foreach ([null, '', -999, '0', 0] as $sent) {
            DB::table($T)->delete();
            $this->seedKinship([$f => 5, 'c_notes' => '初始']);
            $this->postJson('/api/v2/mutate', $this->kinshipPayload(['changes' => [$f => $sent, 'c_notes' => '改'.var_export($sent, true)]]))->assertOk();
            $this->assertSame(0, (int) DB::table($T)->value($f), $f.' 送 '.var_export($sent, true).' 應規範化為 0');
            $this->assertNotNull(DB::table($T)->value($f), $f.' 不得為 null');
        }
        DB::table($T)->delete();
        $this->seedKinship([$f => 1, 'c_notes' => 'x']);
        $this->postJson('/api/v2/mutate', $this->kinshipPayload(['changes' => [$f => 7, 'c_notes' => '合法值']]))->assertOk();
        $this->assertSame(7, (int) DB::table($T)->value($f), '合法非 0 值不得被誤清');
        foreach ([null, '', -999, 0] as $i => $sent) {
            $this->postJson('/api/v2/mutate', $this->kinshipPayload(['changes' => [$f => $sent, 'c_notes' => '再'.$i]]))->assertOk();
            $this->assertSame(0, (int) DB::table($T)->value($f), '幂等重送仍為 0（第'.$i.'輪）');
        }
    }

    #[Test]
    public function testKinshipUpdateAcceptsAlias(): void {
        $user = $this->makeUser(email: 'kin-alias@example.com');
        $this->actingAs($user);
        $this->seedKinship();

        $response = $this->postJson('/api/v2/mutate', $this->kinshipPayload([
            'resource' => 'kin',
        ]));

        $response->assertOk()
            ->assertJson(['ok' => true, 'resource' => 'kinship']);
    }
}
