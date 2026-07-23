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
        $this->createAssocCodesTable();
        $this->createKinshipCodesTable();
        $this->createAiFillLogsTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('ai_fill_logs');
        Schema::dropIfExists('KINSHIP_CODES');
        Schema::dropIfExists('ASSOC_CODES');
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
            $table->integer('c_assoc_fy_nh_code')->nullable();
            $table->integer('c_assoc_fy_nh_year')->nullable();
            $table->integer('c_assoc_fy_range')->nullable();
            $table->integer('c_assoc_fy_intercalary')->nullable();
            $table->integer('c_assoc_fy_month')->nullable();
            $table->integer('c_assoc_fy_day')->nullable();
            $table->integer('c_assoc_fy_day_gz')->nullable();
            $table->integer('c_assoc_ly_nh_code')->nullable();
            $table->integer('c_assoc_ly_nh_year')->nullable();
            $table->integer('c_assoc_ly_range')->nullable();
            $table->integer('c_assoc_ly_intercalary')->nullable();
            $table->integer('c_assoc_ly_month')->nullable();
            $table->integer('c_assoc_ly_day')->nullable();
            $table->integer('c_assoc_ly_day_gz')->nullable();
            $table->string('c_created_by', 255)->nullable();
            $table->string('c_created_date', 255)->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->string('c_modified_date', 255)->nullable();
            $table->primary([
                'c_personid', 'c_assoc_code', 'c_assoc_id',
                'c_kin_code', 'c_kin_id', 'c_assoc_kin_code',
                'c_assoc_kin_id', 'c_text_title', 'c_assoc_first_year',
            ]);
        });
    }

    /** 關係碼配對表：afterDirectUpdate 以舊關係碼查其配對碼定位反向鏡像列。code 1↔2 互為配對。 */
    protected function createAssocCodesTable(): void {
        Schema::create('ASSOC_CODES', function (Blueprint $table) {
            $table->integer('c_assoc_code')->primary();
            $table->integer('c_assoc_pair')->nullable();
            $table->integer('c_assoc_pair2')->nullable();
        });
        DB::table('ASSOC_CODES')->insert([
            ['c_assoc_code' => 1, 'c_assoc_pair' => 2, 'c_assoc_pair2' => null],
            ['c_assoc_code' => 2, 'c_assoc_pair' => 1, 'c_assoc_pair2' => null],
            // 5↔6：代表「同對方+同書+同首年」的另一段合法社會關係（#70 Option 2：碼∈合法 code 的疑似列不可覆寫）。
            ['c_assoc_code' => 5, 'c_assoc_pair' => 6, 'c_assoc_pair2' => null],
            ['c_assoc_code' => 6, 'c_assoc_pair' => 5, 'c_assoc_pair2' => null],
        ]);
    }

    /** 親屬碼配對表：未送 kin 配對碼時 afterDirectUpdate 以 c_kin_pair1 查權威反向碼。75↔76 互為配對。 */
    protected function createKinshipCodesTable(): void {
        Schema::create('KINSHIP_CODES', function (Blueprint $table) {
            $table->integer('c_kincode')->primary();
            $table->integer('c_kin_pair1')->nullable();
            $table->integer('c_kin_pair2')->nullable();
        });
        DB::table('KINSHIP_CODES')->insert([
            ['c_kincode' => 0, 'c_kin_pair1' => null, 'c_kin_pair2' => null],
            ['c_kincode' => 75, 'c_kin_pair1' => 76, 'c_kin_pair2' => null],
            ['c_kincode' => 76, 'c_kin_pair1' => 75, 'c_kin_pair2' => null],
        ]);
    }

    /** 反向鏡像列（對方 2000 擁有、指回原人 1000、反向關係碼 2）。 */
    protected function seedMirror(array $overrides = []): void {
        DB::table('ASSOC_DATA')->insert(array_replace([
            'c_personid' => 2000,
            'c_assoc_code' => 2,
            'c_assoc_id' => 1000,
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

    // ── Helpers ──────────────────────────────────────────────

    protected function createAiFillLogsTable(): void {
        Schema::create('ai_fill_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->integer('c_personid');
            $table->string('category', 20)->default('posting');
            $table->string('route_name', 255)->nullable();
            $table->string('route_url', 500)->nullable();
            $table->text('source_text')->nullable();
            $table->longText('ai_raw')->nullable();
            $table->longText('ai_matched')->nullable();
            $table->longText('user_submitted')->nullable();
            $table->boolean('success')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    /** 建立一筆待提交的 AI 填充日誌（預設 category='assoc'），回傳 log id。 */
    protected function insertAiFillLog(int $userId, int $personId = 1000, string $category = 'assoc'): int {
        return DB::table('ai_fill_logs')->insertGetId([
            'user_id' => $userId,
            'c_personid' => $personId,
            'category' => $category,
            'route_name' => 'app.basicinformation.assoc.editv2',
            'route_url' => '/app/basicinformation/'.$personId.'/assoc/edit-v2',
            'source_text' => '同年進士',
            'ai_matched' => json_encode(['matched_codes' => [['code_id' => 1]]]),
            'success' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

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

    // ── AI 智能識別回寫 ai_fill_logs（回歸：React 遷移後 v2 update 未回寫 → 誤顯示「未提交」）─────

    #[Test]
    public function testDirectAssociationUpdateWithAiFillLogIdMarksLogSubmitted(): void {
        $user = $this->makeUser(email: 'assoc-ailog@example.com');
        $this->actingAs($user);
        $this->seedAssociation();
        $logId = $this->insertAiFillLog($user->id);

        $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_notes' => '更新備註'],
            'meta' => ['ai_fill_log_id' => $logId],
        ]))->assertOk();

        $log = DB::table('ai_fill_logs')->where('id', $logId)->first();
        $this->assertNotNull($log->user_submitted, 'user_submitted 應回寫（已提交）');
        $this->assertNotNull($log->submitted_at, 'submitted_at 應回寫');
        $submitted = json_decode($log->user_submitted, true);
        $this->assertSame('更新備註', $submitted['c_notes']);
        $this->assertArrayNotHasKey('c_modified_by', $submitted, '不應含自動蓋的稽核欄');
    }

    #[Test]
    public function testDirectAssociationUpdateWithoutAiFillLogIdLeavesLogUntouched(): void {
        $user = $this->makeUser(email: 'assoc-noailog@example.com');
        $this->actingAs($user);
        $this->seedAssociation();
        $logId = $this->insertAiFillLog($user->id);

        $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_notes' => '更新備註'],
        ]))->assertOk();

        $log = DB::table('ai_fill_logs')->where('id', $logId)->first();
        $this->assertNull($log->user_submitted, '無 log id 不應回寫');
        $this->assertNull($log->submitted_at);
    }

    #[Test]
    public function testAssocUpdateAiFillLogNotOverwrittenForOtherUsersLog(): void {
        $owner = $this->makeUser(email: 'assoc-ailog-owner@example.com');
        $actor = $this->makeUser(email: 'assoc-ailog-actor@example.com');
        $logId = $this->insertAiFillLog($owner->id);
        $this->actingAs($actor);
        $this->seedAssociation();

        $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_notes' => '更新備註'],
            'meta' => ['ai_fill_log_id' => $logId],
        ]))->assertOk();

        $log = DB::table('ai_fill_logs')->where('id', $logId)->first();
        $this->assertNull($log->user_submitted, '他人日誌不得被覆寫');
    }

    #[Test]
    public function testAssocUpdateAiFillLogNotOverwrittenForWrongCategory(): void {
        $user = $this->makeUser(email: 'assoc-ailog-cat@example.com');
        $this->actingAs($user);
        $this->seedAssociation();
        $logId = $this->insertAiFillLog($user->id, 1000, 'status');

        $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_notes' => '更新備註'],
            'meta' => ['ai_fill_log_id' => $logId],
        ]))->assertOk();

        $log = DB::table('ai_fill_logs')->where('id', $logId)->first();
        $this->assertNull($log->user_submitted, '不同 category 的日誌不得被回寫');
    }

    #[Test]
    public function testAssocUpdateAiFillLogNotOverwrittenForDifferentPerson(): void {
        // 連結完整性：只回寫「同一人物」的日誌（c_personid 守衛，$personId 為 handler 權威值）。
        $user = $this->makeUser(email: 'assoc-ailog-person@example.com');
        $this->actingAs($user);
        $this->seedAssociation();
        $logId = $this->insertAiFillLog($user->id, 999);

        $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_notes' => '更新備註'],
            'meta' => ['ai_fill_log_id' => $logId],
        ]))->assertOk();

        $log = DB::table('ai_fill_logs')->where('id', $logId)->first();
        $this->assertNull($log->user_submitted, '不同人物的日誌不得被回寫');
    }

    #[Test]
    public function testProposalAssociationUpdateDoesNotRecordAiFillSubmission(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'assoc-ailog-proposal@example.com');
        $this->actingAs($user);
        $this->seedAssociation();
        $logId = $this->insertAiFillLog($user->id);

        $this->postJson('/api/v2/mutate', $this->associationPayload([
            'mode' => 'proposal',
            'changes' => ['c_notes' => '更新備註'],
            'meta' => ['ai_fill_log_id' => $logId],
        ]))->assertOk();

        $log = DB::table('ai_fill_logs')->where('id', $logId)->first();
        $this->assertNull($log->user_submitted, 'proposal 提交當下不回寫');
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
    public function testDirectAssociationUpdatePersistsEraLunarFields(): void {
        // 回歸：legacy x-inline-time-fields 送出 era 農曆欄；v2 白名單原漏 → 編輯時靜默流失。
        $this->actingAs($this->makeUser(email: 'assoc-era-upd@example.com'));
        $this->seedAssociation();

        $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_assoc_fy_nh_code' => 5, 'c_assoc_fy_month' => 6, 'c_assoc_ly_day_gz' => 21],
        ]))->assertOk();

        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 1000, 'c_assoc_code' => 1, 'c_assoc_id' => 2000,
            'c_assoc_fy_nh_code' => 5, 'c_assoc_fy_month' => 6, 'c_assoc_ly_day_gz' => 21,
        ]);
    }

    #[Test]
    public function testDirectAssociationUpdateSyncsExistingMirror(): void {
        // 後台自動雙向同步（32a-update）：更新正向關係時，反向鏡像列同步更新（重用 syncAssocMirrorOnUpdate）。
        $this->actingAs($this->makeUser(email: 'assoc-mirror-upd@example.com'));
        $this->seedAssociation();
        $this->seedMirror();

        $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_notes' => '改後備註', 'c_assocship_pair' => 2],
        ]))->assertOk()->assertJson(['ok' => true]);

        // 正向更新。
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 1000, 'c_assoc_code' => 1, 'c_assoc_id' => 2000, 'c_notes' => '改後備註']);
        // 反向鏡像同步更新。
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 2, 'c_assoc_id' => 1000, 'c_notes' => '改後備註']);
    }

    #[Test]
    public function testDirectAssociationUpdateConflictBlockedAndRolledBack(): void {
        // #66：對面鏡像列 c_notes 已有不同內容（'原鏡像備註'），direct 改正向 notes 為 '改後'（未 force）→
        // 偵測到衝突 → 409 + mirror_conflict 明細（ASSOC_DATA/PK/欄）；整筆回滾：正向與鏡像皆不變。
        $this->actingAs($this->makeUser(email: 'assoc-conflict@example.com'));
        $this->seedAssociation(['c_notes' => '正向原備註']);
        $this->seedMirror(['c_notes' => '原鏡像備註']);

        $res = $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_notes' => '改後'],
        ]))->assertStatus(409);

        $res->assertJsonPath('errors.mirror_conflict.table', 'ASSOC_DATA');
        $res->assertJsonPath('errors.mirror_conflict.pk.c_personid', 2000);
        $this->assertContains('c_notes', array_map(static fn ($c) => $c['field'], $res->json('errors.mirror_conflict.fields')));

        // 整筆回滾：正向維持 '正向原備註'、鏡像維持 '原鏡像備註'。
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 1000, 'c_assoc_code' => 1, 'c_assoc_id' => 2000, 'c_notes' => '正向原備註']);
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 2, 'c_assoc_id' => 1000, 'c_notes' => '原鏡像備註']);
    }

    #[Test]
    public function testDirectAssociationUpdateForceOverwritesConflict(): void {
        // #66：同上衝突情境，帶 meta.force=true → 跳過偵測、照常覆寫；鏡像 notes 更新為 '改後'。
        $this->actingAs($this->makeUser(email: 'assoc-conflict-force@example.com'));
        $this->seedAssociation(['c_notes' => '正向原備註']);
        $this->seedMirror(['c_notes' => '原鏡像備註']);

        $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_notes' => '改後'],
            'meta' => ['force' => true],
        ]))->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 1000, 'c_assoc_code' => 1, 'c_assoc_id' => 2000, 'c_notes' => '改後']);
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 2, 'c_assoc_id' => 1000, 'c_notes' => '改後']);
    }

    #[Test]
    public function testDirectAssociationUpdateMirrorKinCodeDivergenceBlocked(): void {
        // #66（碼真分歧，B 方案）：鏡像的次要親屬碼 c_kin_code 被改成無關碼 99（∉ 正向舊碼 75 的合法反向 {76}）→ 真分歧 → 409。
        // 注：定位器以 c_assoc_code 定位鏡像、不約束 c_kin_code，故此碼分歧可被偵測（主碼 c_assoc_code 受定位器約束、檢測為防禦性）。
        $this->actingAs($this->makeUser(email: 'assoc-kincode-div@example.com'));
        $this->seedAssociation(['c_kin_code' => 75, 'c_kin_id' => 3000, 'c_notes' => '同步值']);
        $this->seedMirror(['c_kin_code' => 99, 'c_kin_id' => 1000, 'c_notes' => '同步值']); // c_kin_code=99 ∉ validReverses(75)={76}

        $res = $this->postJson('/api/v2/mutate', $this->associationPayload([
            'target' => ['pk' => ['c_kin_code' => 75, 'c_kin_id' => 3000]],
            'changes' => ['c_notes' => '改後'], // 內容同步（不衝突）→ 隔離出 c_kin_code 碼分歧
        ]))->assertStatus(409);

        $res->assertJsonPath('errors.mirror_conflict.table', 'ASSOC_DATA');
        $this->assertContains('c_kin_code', array_map(static fn ($c) => $c['field'], $res->json('errors.mirror_conflict.fields')));
        // 回滾：鏡像 c_kin_code 維持 99（未被覆寫成 76）。
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 2, 'c_assoc_id' => 1000, 'c_kin_code' => 99]);
    }

    #[Test]
    public function testDirectAssociationUpdateInSyncEditNotBlocked(): void {
        // #66（修過度觸發）：正常同步編輯——鏡像 c_notes 與正向「編輯前舊值」相同（本來同步）。
        // 改正向 notes（未 force）→ 不應誤報衝突 → 200，鏡像靜默同步為新值。
        $this->actingAs($this->makeUser(email: 'assoc-insync@example.com'));
        $this->seedAssociation(['c_notes' => '同步值']);
        $this->seedMirror(['c_notes' => '同步值']); // == 正向舊值 → 同步、非分歧

        $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_notes' => '新值'],
        ]))->assertOk()->assertJson(['ok' => true]);

        // 正常同步：正向與鏡像皆更新為 '新值'（不被 #66 誤擋）。
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 1000, 'c_assoc_code' => 1, 'c_assoc_id' => 2000, 'c_notes' => '新值']);
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 2, 'c_assoc_id' => 1000, 'c_notes' => '新值']);
    }

    #[Test]
    public function testDirectAssociationUpdateBackfillsMissingMirror(): void {
        // 「永遠同步」改進：反向鏡像缺失（舊資料單邊）時，更新正向會補建反向，修正 legacy 選擇性跳過。
        $this->actingAs($this->makeUser(email: 'assoc-mirror-bf@example.com'));
        $this->seedAssociation();

        $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_notes' => '補建備註', 'c_assocship_pair' => 2],
        ]))->assertOk()->assertJson(['ok' => true]);

        // 反向鏡像被補建（對方 2000 擁有、指回 1000、反向碼 2、同出處/年份）。
        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 2000, 'c_assoc_code' => 2, 'c_assoc_id' => 1000,
            'c_text_title' => '書名', 'c_assoc_first_year' => 1060, 'c_notes' => '補建備註',
        ]);
    }

    #[Test]
    public function testDirectUpdateWithoutSentPairsPreservesMirrorKinCode(): void {
        // 回歸（codex MAJOR）：只改內容、未送 kin 配對碼時，不可把既有鏡像的親屬配對碼洗成 0；
        // 以 KINSHIP_CODES 權威反向碼（75→76）保持。
        $this->actingAs($this->makeUser(email: 'assoc-kinpair@example.com'));
        $this->seedAssociation(['c_kin_code' => 75]);                                  // 正向 kin code 75
        $this->seedMirror(['c_kin_code' => 76, 'c_kin_id' => 1000, 'c_assoc_kin_id' => 1000]); // 反向 kin code 76

        // 只改 c_notes，完全不送任何配對碼。
        $this->postJson('/api/v2/mutate', $this->associationPayload([
            'target' => ['pk' => ['c_kin_code' => 75]],
            'changes' => ['c_notes' => '只改備註'],
        ]))->assertOk();

        // 鏡像親屬碼仍為 76（權威反向碼），未被洗成 0。
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 2, 'c_assoc_id' => 1000, 'c_kin_code' => 76]);
        $this->assertDatabaseMissing('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 2, 'c_assoc_id' => 1000, 'c_kin_code' => 0]);
    }

    #[Test]
    public function testProposalUpdateStoresAuthoritativePairsInAux(): void {
        // 回歸（codex MAJOR）：proposal 只改內容、未送配對碼時，aux 須存「權威反向碼」（非 0），
        // 否則核准時 legacy 無條件讀三鍵會用 0 洗掉既有鏡像的關係碼。
        $this->actingAs($this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'assoc-prop-aux@example.com'));
        $this->seedAssociation();

        $this->postJson('/api/v2/mutate', $this->associationPayload([
            'mode' => 'proposal',
            'changes' => ['c_notes' => '提案改備註'],
            'meta' => ['comment' => '請審'],
        ]))->assertOk()->assertJson(['ok' => true, 'mode' => 'proposal']);

        $op = DB::table('operations')->where('resource', 'ASSOC_DATA')
            ->where('op_type', Operation::TYPE_PROPOSAL_UPDATE)->latest('id')->first();
        $this->assertNotNull($op);
        $data = json_decode($op->resource_data, true);
        // 未送 → 以 ASSOC_CODES[1].c_assoc_pair=2 權威值補齊，而非 0。
        $this->assertSame(2, (int) ($data['__proposal_aux']['c_assocship_pair'] ?? null));
    }

    #[Test]
    public function testPairOnlyDirectUpdateBackfillsMirror(): void {
        // pair-only：只送 c_assocship_pair、不改任何 ASSOC_DATA 欄（顯式修復缺失反向鏡像）。
        $this->actingAs($this->makeUser(email: 'assoc-paironly@example.com'));
        $this->seedAssociation();

        $this->postJson('/api/v2/mutate', [
            'resource' => 'associations',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => [
                'c_personid' => 1000, 'c_assoc_code' => 1, 'c_assoc_id' => 2000,
                'c_kin_code' => 0, 'c_kin_id' => 0, 'c_assoc_kin_code' => 0, 'c_assoc_kin_id' => 0,
                'c_text_title' => '書名', 'c_assoc_first_year' => 1060,
            ]],
            'changes' => ['c_assocship_pair' => 2],
        ])->assertOk()->assertJson(['ok' => true, 'operation' => 'update']);

        // 反向鏡像被補建。
        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 2000, 'c_assoc_code' => 2, 'c_assoc_id' => 1000,
            'c_text_title' => '書名', 'c_assoc_first_year' => 1060,
        ]);
    }

    #[Test]
    public function testPairOnlyKinPairUpdateBackfillsMirror(): void {
        // 回歸（Task 58）：只送親屬互逆配對碼 c_kinship_pair、不改任何 ASSOC_DATA 欄。
        // pair-only bypass 過去僅認 c_assocship_pair，kin-only 會落到父類以「changes 空」被 422。
        $this->actingAs($this->makeUser(email: 'assoc-kinpaironly@example.com'));
        $this->seedAssociation(['c_kin_code' => 75, 'c_kin_id' => 3000]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'associations',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => [
                'c_personid' => 1000, 'c_assoc_code' => 1, 'c_assoc_id' => 2000,
                'c_kin_code' => 75, 'c_kin_id' => 3000, 'c_assoc_kin_code' => 0, 'c_assoc_kin_id' => 0,
                'c_text_title' => '書名', 'c_assoc_first_year' => 1060,
            ]],
            'changes' => ['c_kinship_pair' => 76],
        ])->assertOk()
            ->assertJson(['ok' => true, 'operation' => 'update'])
            ->assertJsonPath('result.updated_fields', ['c_kinship_pair']);

        // 反向鏡像被補建，且帶入送出的親屬反向碼（c_kin_code=76）。
        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 2000, 'c_assoc_code' => 2, 'c_assoc_id' => 1000,
            'c_kin_code' => 76, 'c_text_title' => '書名', 'c_assoc_first_year' => 1060,
        ]);
    }

    #[Test]
    public function testPairOnlyAssocKinPairUpdateBackfillsMirror(): void {
        // 回歸（Task 58）：只送關聯親屬互逆配對碼 c_assoc_kinship_pair、不改任何 ASSOC_DATA 欄。
        $this->actingAs($this->makeUser(email: 'assoc-assockinpaironly@example.com'));
        $this->seedAssociation(['c_assoc_kin_code' => 75, 'c_assoc_kin_id' => 4000]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'associations',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => [
                'c_personid' => 1000, 'c_assoc_code' => 1, 'c_assoc_id' => 2000,
                'c_kin_code' => 0, 'c_kin_id' => 0, 'c_assoc_kin_code' => 75, 'c_assoc_kin_id' => 4000,
                'c_text_title' => '書名', 'c_assoc_first_year' => 1060,
            ]],
            'changes' => ['c_assoc_kinship_pair' => 76],
        ])->assertOk()
            ->assertJson(['ok' => true, 'operation' => 'update'])
            ->assertJsonPath('result.updated_fields', ['c_assoc_kinship_pair']);

        // 反向鏡像被補建，且帶入送出的關聯親屬反向碼（c_assoc_kin_code=76）。
        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 2000, 'c_assoc_code' => 2, 'c_assoc_id' => 1000,
            'c_assoc_kin_code' => 76, 'c_text_title' => '書名', 'c_assoc_first_year' => 1060,
        ]);
    }

    #[Test]
    public function testDirectUpdateWithKinPairAndOtherFieldBackfillsMissingMirror(): void {
        // 回歸（codex serious，Task 58）：非 pair-only 路徑——同時改真實欄（c_notes）與親屬互逆碼（c_kinship_pair）、
        // 且反向鏡像缺失時，maintain 須啟用以補建鏡像。修復前 maintain 僅認 c_assocship_pair，此組合（最常見的
        // 「順手改備註 + 修親屬 pair」）不會補建，留下單邊髒資料且與畫面「會建立鏡像」文案矛盾。
        $this->actingAs($this->makeUser(email: 'assoc-kinpair-mixed@example.com'));
        $this->seedAssociation(['c_kin_code' => 75, 'c_kin_id' => 3000]); // 僅正向；未 seedMirror＝反向缺失

        $this->postJson('/api/v2/mutate', [
            'resource' => 'associations',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => [
                'c_personid' => 1000, 'c_assoc_code' => 1, 'c_assoc_id' => 2000,
                'c_kin_code' => 75, 'c_kin_id' => 3000, 'c_assoc_kin_code' => 0, 'c_assoc_kin_id' => 0,
                'c_text_title' => '書名', 'c_assoc_first_year' => 1060,
            ]],
            'changes' => ['c_notes' => '順手改備註', 'c_kinship_pair' => 76],
        ])->assertOk()
            ->assertJson(['ok' => true, 'operation' => 'update'])
            ->assertJsonPath('result.updated_fields', ['c_notes']);

        // 正向列備註已更新。
        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 1000, 'c_assoc_code' => 1, 'c_assoc_id' => 2000, 'c_notes' => '順手改備註',
        ]);
        // 反向鏡像被補建，帶入送出的親屬反向碼（c_kin_code=76）。
        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 2000, 'c_assoc_code' => 2, 'c_assoc_id' => 1000,
            'c_kin_code' => 76, 'c_text_title' => '書名', 'c_assoc_first_year' => 1060,
        ]);
    }

    #[Test]
    public function testDirectUpdateStampsModifiedAuditFields(): void {
        // 回歸（Task 62）：direct 子資源更新須於主列蓋更新者/更新時間（對齊 legacy ToolsRepository::timestamp），
        // 並於 result.row 回傳刷新後稽核欄供前端即時刷新；updated_fields 不含自動蓋的稽核欄。
        $this->actingAs($this->makeUser(email: 'assoc-modstamp@example.com'));
        $this->seedAssociation(['c_modified_by' => '舊使用者', 'c_modified_date' => '2000-01-01 00:00:00']);

        $res = $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_notes' => '蓋章測試'],
        ]))->assertOk();

        $res->assertJsonPath('result.row.c_modified_by', 'tester');
        $this->assertNotContains('c_modified_by', $res->json('result.updated_fields'));
        $this->assertNotContains('c_modified_date', $res->json('result.updated_fields'));

        $row = DB::table('ASSOC_DATA')->where('c_personid', 1000)->where('c_assoc_code', 1)->first();
        $this->assertSame('tester', $row->c_modified_by);
        $this->assertNotSame('2000-01-01 00:00:00', (string) $row->c_modified_date);
        $this->assertNotSame('', (string) $row->c_modified_date);
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

    // ── #70 鏡像「疑似匹配」（嚴格落空 + 對面有碼漂移的疑似列）──────────────

    #[Test]
    public function testAssocUpdateSuspectedMirrorBlockedAndRolledBack(): void {
        // #70：對面鏡像碼漂移出合法反向集（99 ∉ {2}）→ 嚴格定位落空。非強制下放寬查到疑似 → 不自動同步、不 backfill，
        // 拋疑似中止 → 409 + mirror_suspected 明細；整筆回滾：正向未改、漂移列未動、未補出重複 code-2 鏡像。
        $this->actingAs($this->makeUser(email: 'assoc-suspected@example.com'));
        $this->seedAssociation(['c_notes' => '正向原備註']);
        $this->seedMirror(['c_assoc_code' => 99, 'c_notes' => '對面漂移備註']);

        $res = $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_notes' => '改後', 'c_assocship_pair' => 2],
        ]))->assertStatus(409);
        $res->assertJsonPath('errors.mirror_suspected.table', 'ASSOC_DATA');
        $res->assertJsonPath('errors.mirror_suspected.count', 1);
        $res->assertJsonPath('errors.mirror_suspected.authoritative_code', 2);

        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 1000, 'c_assoc_code' => 1, 'c_assoc_id' => 2000, 'c_notes' => '正向原備註']);
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 99, 'c_assoc_id' => 1000, 'c_notes' => '對面漂移備註']);
        $this->assertDatabaseMissing('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 2, 'c_assoc_id' => 1000]);
        $this->assertSame(2, DB::table('ASSOC_DATA')->count(), '不得補出重複鏡像（仍只有正向 + 漂移列）');
    }

    #[Test]
    public function testAssocUpdateSuspectedForceCollapsesInPlace(): void {
        // #70 強制（單條疑似）：就地把漂移列碼 99 收斂為權威反向碼 2 + 覆寫內容，不新建第三條。
        $this->actingAs($this->makeUser(email: 'assoc-suspected-force1@example.com'));
        $this->seedAssociation(['c_notes' => '正向原備註']);
        $this->seedMirror(['c_assoc_code' => 99, 'c_notes' => '對面漂移備註']);

        $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_notes' => '改後', 'c_assocship_pair' => 2],
            'meta' => ['force' => true],
        ]))->assertOk();

        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 2, 'c_assoc_id' => 1000, 'c_notes' => '改後']);
        $this->assertDatabaseMissing('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 99, 'c_assoc_id' => 1000]);
        $this->assertSame(2, DB::table('ASSOC_DATA')->count(), '就地收斂：正向 + 收斂後鏡像，無重複');
    }

    #[Test]
    public function testAssocUpdateSuspectedForceMultiCollapsesFirstLeavesStrays(): void {
        // #70 強制（多條疑似）：就地收斂「第一條」漂移列為權威反向碼 2 + 覆寫內容，其餘漂移列留待人工去對面刪，
        // **不**再 backfill 補新列（對齊 kin；修掉舊「多條→落 backfill→殘留多條垃圾、總列數膨脹」codex MINOR）。
        $this->actingAs($this->makeUser(email: 'assoc-suspected-forceN@example.com'));
        $this->seedAssociation(['c_notes' => '正向原備註']);
        $this->seedMirror(['c_assoc_code' => 99, 'c_notes' => '漂移A']);
        $this->seedMirror(['c_assoc_code' => 88, 'c_notes' => '漂移B']);

        $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_notes' => '改後', 'c_assocship_pair' => 2],
            'meta' => ['force' => true],
        ]))->assertOk();

        // 恰一條漂移列被收斂為權威碼 2 + 新內容；總列數 = 3（正向 + 收斂列 + 1 殘留漂移），非 4（無 backfill 新列）。
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 2, 'c_assoc_id' => 1000, 'c_notes' => '改後']);
        $this->assertSame(1, DB::table('ASSOC_DATA')->where(['c_personid' => 2000, 'c_assoc_id' => 1000, 'c_assoc_code' => 2])->count());
        $this->assertSame(1, DB::table('ASSOC_DATA')->whereIn('c_assoc_code', [99, 88])->where(['c_personid' => 2000, 'c_assoc_id' => 1000])->count(), '應僅殘留一條未收斂漂移列');
        $this->assertSame(3, DB::table('ASSOC_DATA')->count(), '正向 + 收斂後鏡像 + 1 殘留漂移列（無 backfill 第四列）');
    }

    #[Test]
    public function testAssocUpdateNoMirrorStillBackfillsWhenNoSuspect(): void {
        // #70 邊界：嚴格落空且放寬「也查無」（對面完全無此關係）→ 維持現狀 backfill，不誤報疑似、不 409。
        $this->actingAs($this->makeUser(email: 'assoc-no-suspect@example.com'));
        $this->seedAssociation(['c_notes' => '正向原備註']); // 僅正向，無任何反向列

        $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_notes' => '改後', 'c_assocship_pair' => 2],
        ]))->assertOk();

        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 2, 'c_assoc_id' => 1000, 'c_notes' => '改後']);
        $this->assertSame(2, DB::table('ASSOC_DATA')->count(), '靜默 backfill：正向 + 新建鏡像');
    }

    #[Test]
    public function testAssocUpdateValidCodeSuspectNotClobberedBackfillsOwn(): void {
        // #70 Option 2 防資料損壞：同對方+同書+同首年存在「另一段合法關係」的鏡像（碼 6 ∈ ASSOC_CODES）。
        // 編輯本段（正向碼 1、本段鏡像缺）→ 放寬命中碼 6，但 6 是合法 code → 判為他段關係、非本段漂移 →
        // 不誤報疑似、不覆寫他段，改 backfill 本段鏡像（碼 2）；他段鏡像（碼 6）原封不動。
        $this->actingAs($this->makeUser(email: 'assoc-validcode-suspect@example.com'));
        $this->seedAssociation(['c_notes' => '本段正向']);
        $this->seedMirror(['c_assoc_code' => 6, 'c_notes' => '他段關係鏡像']);

        $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_notes' => '改後', 'c_assocship_pair' => 2],
        ]))->assertOk();

        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 2, 'c_assoc_id' => 1000, 'c_notes' => '改後']);
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 6, 'c_assoc_id' => 1000, 'c_notes' => '他段關係鏡像']);
        $this->assertSame(3, DB::table('ASSOC_DATA')->count(), '本段鏡像補上 + 他段鏡像保留（不吞）');
    }

    #[Test]
    public function testAssocUpdateValidCodeSuspectForceDoesNotClobberOther(): void {
        // #70 Option 2 防資料損壞（強制）：即便強制，也絕不就地覆寫「合法碼的他段關係」→ backfill 本段鏡像、他段不動。
        $this->actingAs($this->makeUser(email: 'assoc-validcode-force@example.com'));
        $this->seedAssociation(['c_notes' => '本段正向']);
        $this->seedMirror(['c_assoc_code' => 6, 'c_notes' => '他段關係鏡像']);

        $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_notes' => '改後', 'c_assocship_pair' => 2],
            'meta' => ['force' => true],
        ]))->assertOk();

        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 2, 'c_assoc_id' => 1000, 'c_notes' => '改後']);
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 6, 'c_assoc_id' => 1000, 'c_notes' => '他段關係鏡像']);
        $this->assertSame(3, DB::table('ASSOC_DATA')->count(), '強制亦不動他段合法關係');
    }

    #[Test]
    public function testAssocUpdateMixedDriftAndValidCodeForceCollapsesOnlyDrift(): void {
        // #70 Option 2 混合場景（強制）：對面同時有「漂移列(碼 99 ∉ codes)」與「他段合法關係(碼 6 ∈ codes)」。
        // 強制單條漂移 → 只就地收斂 99→2 + 覆寫內容；他段合法列 6 原封不動。鎖死判別：只動漂移、不碰他段。
        $this->actingAs($this->makeUser(email: 'assoc-mixed-force@example.com'));
        $this->seedAssociation(['c_notes' => '本段正向']);
        $this->seedMirror(['c_assoc_code' => 99, 'c_notes' => '漂移鏡像']);
        $this->seedMirror(['c_assoc_code' => 6, 'c_notes' => '他段關係鏡像']);

        $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_notes' => '改後', 'c_assocship_pair' => 2],
            'meta' => ['force' => true],
        ]))->assertOk();

        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 2, 'c_assoc_id' => 1000, 'c_notes' => '改後']);
        $this->assertDatabaseMissing('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 99, 'c_assoc_id' => 1000]);
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 6, 'c_assoc_id' => 1000, 'c_notes' => '他段關係鏡像']);
        $this->assertSame(3, DB::table('ASSOC_DATA')->count(), '只收斂漂移列（99→2），他段(6)保留');
    }

    #[Test]
    public function testAssocPairOnlySuspectedMirrorReturns409NotEscapeTo500(): void {
        // #70（codex 揪出）：pair-only 修鏡像路徑自帶交易、不經 handleDirect，須自行把 MirrorSuspectedException 轉 409。
        // 只送 c_assocship_pair（無內容欄變更）、對面有漂移疑似列(99)、非 force → 應回 409 mirror_suspected，不可逃逸成 500。
        $this->actingAs($this->makeUser(email: 'assoc-paironly-suspected@example.com'));
        $this->seedAssociation(['c_notes' => '本段正向']);
        $this->seedMirror(['c_assoc_code' => 99, 'c_notes' => '漂移鏡像']);

        $res = $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_assocship_pair' => 2], // 僅送配對碼，無 c_notes 等內容欄
        ]))->assertStatus(409);
        $res->assertJsonPath('errors.mirror_suspected.table', 'ASSOC_DATA');
        $res->assertJsonPath('errors.mirror_suspected.count', 1);
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 99, 'c_assoc_id' => 1000]);
        $this->assertDatabaseMissing('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 2, 'c_assoc_id' => 1000]);
    }

    #[Test]
    public function testAssocPairOnlySuspectedForceCollapses(): void {
        // #70：pair-only + 強制 → 漂移列就地收斂 99→2（pair-only 路徑亦不誤吞他段，與一般 update 一致）。
        $this->actingAs($this->makeUser(email: 'assoc-paironly-force@example.com'));
        $this->seedAssociation(['c_notes' => '本段正向']);
        $this->seedMirror(['c_assoc_code' => 99, 'c_notes' => '漂移鏡像']);

        $this->postJson('/api/v2/mutate', $this->associationPayload([
            'changes' => ['c_assocship_pair' => 2],
            'meta' => ['force' => true],
        ]))->assertOk();

        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 2, 'c_assoc_id' => 1000]);
        $this->assertDatabaseMissing('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 99, 'c_assoc_id' => 1000]);
        $this->assertSame(2, DB::table('ASSOC_DATA')->count());
    }

    #[Test]
    public function testAssociationCodeFieldSentinelFullyIdempotent(): void {
        // c_source（legacy 哨兵 0=Unknown）所有空表示 null/''/-999/'0'/0 → 0、合法值保留、來回不翻。≥10 案例。
        // 不送配對碼 → maintain=false、無鏡像 backfill/疑似，純驗正向碼欄規範化。
        $this->actingAs($this->makeUser(email: 'assoc-sentinel@example.com'));
        $T = 'ASSOC_DATA';
        $f = 'c_source';
        foreach ([null, '', -999, '0', 0] as $sent) {
            DB::table($T)->delete();
            $this->seedAssociation([$f => 5, 'c_notes' => '初始']);
            $this->postJson('/api/v2/mutate', $this->associationPayload(['changes' => [$f => $sent, 'c_notes' => '改'.var_export($sent, true)]]))->assertOk();
            $this->assertSame(0, (int) DB::table($T)->value($f), $f.' 送 '.var_export($sent, true).' 應規範化為 0');
            $this->assertNotNull(DB::table($T)->value($f), $f.' 不得為 null');
        }
        DB::table($T)->delete();
        $this->seedAssociation([$f => 1, 'c_notes' => 'x']);
        $this->postJson('/api/v2/mutate', $this->associationPayload(['changes' => [$f => 7, 'c_notes' => '合法值']]))->assertOk();
        $this->assertSame(7, (int) DB::table($T)->value($f), '合法非 0 值不得被誤清');
        foreach ([null, '', -999, 0] as $i => $sent) {
            $this->postJson('/api/v2/mutate', $this->associationPayload(['changes' => [$f => $sent, 'c_notes' => '再'.$i]]))->assertOk();
            $this->assertSame(0, (int) DB::table($T)->value($f), '幂等重送仍為 0（第'.$i.'輪）');
        }
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
