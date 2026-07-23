<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2CreateAssociationTest extends TestCase {
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
        $this->createAssocTable();
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

    protected function createAssocTable(): void {
        Schema::create('ASSOC_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_assoc_code')->default(0);
            $table->integer('c_assoc_id')->default(0);
            $table->integer('c_kin_code')->default(0);
            $table->integer('c_kin_id')->default(0);
            $table->integer('c_assoc_kin_code')->default(0);
            $table->integer('c_assoc_kin_id')->default(0);
            $table->string('c_text_title', 255)->default('');
            $table->integer('c_assoc_first_year')->default(-9999);
            $table->integer('c_assoc_last_year')->nullable();
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->text('c_supplement')->nullable();
            $table->integer('c_sequence')->nullable();
            $table->integer('c_assoc_count')->nullable();
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
                'c_personid', 'c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id',
                'c_assoc_kin_code', 'c_assoc_kin_id', 'c_text_title', 'c_assoc_first_year',
            ]);
        });
    }

    /**
     * ASSOC_CODES：反向配對碼來源（c_assoc_pair 主反向 / c_assoc_pair2 次反向）。
     * code 100 → 主反向 101、次反向 198；code 300 無反向（c_assoc_pair=null）。
     * 用於驗證鏡像關係碼以權威值補齊（非哨兵 0「未详」），及手選覆寫。
     */
    protected function createAssocCodesTable(): void {
        Schema::create('ASSOC_CODES', function (Blueprint $table) {
            $table->integer('c_assoc_code')->primary();
            $table->integer('c_assoc_pair')->nullable();
            $table->integer('c_assoc_pair2')->nullable();
            $table->string('c_assoc_desc', 255)->nullable();
            $table->string('c_assoc_desc_chn', 255)->nullable();
        });
        DB::table('ASSOC_CODES')->insert([
            ['c_assoc_code' => 100, 'c_assoc_pair' => 101, 'c_assoc_pair2' => 198, 'c_assoc_desc' => 'friend of', 'c_assoc_desc_chn' => '友人'],
            ['c_assoc_code' => 101, 'c_assoc_pair' => 100, 'c_assoc_pair2' => null, 'c_assoc_desc' => 'befriended by', 'c_assoc_desc_chn' => '被友'],
            ['c_assoc_code' => 198, 'c_assoc_pair' => 100, 'c_assoc_pair2' => null, 'c_assoc_desc' => 'alt reverse', 'c_assoc_desc_chn' => '替代反向'],
            ['c_assoc_code' => 300, 'c_assoc_pair' => null, 'c_assoc_pair2' => null, 'c_assoc_desc' => 'unpaired', 'c_assoc_desc_chn' => '無配對'],
        ]);
    }

    /** KINSHIP_CODES：lookupKinPair 來源；create 測試 c_kin_code 皆 0（短路不查），建表僅為穩健。 */
    protected function createKinshipCodesTable(): void {
        Schema::create('KINSHIP_CODES', function (Blueprint $table) {
            $table->integer('c_kincode')->primary();
            $table->integer('c_kin_pair1')->nullable();
            $table->integer('c_kin_pair2')->nullable();
        });
    }

    protected function pk(array $overrides = []): array {
        return array_replace([
            'c_personid' => 1000,
            'c_assoc_code' => 100,
            'c_assoc_id' => 2000,
            'c_kin_code' => 0,
            'c_kin_id' => 0,
            'c_assoc_kin_code' => 0,
            'c_assoc_kin_id' => 0,
            'c_text_title' => '史記',
            'c_assoc_first_year' => 1080,
        ], $overrides);
    }

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
            'ai_matched' => json_encode(['matched_codes' => [['code_id' => 100]]]),
            'success' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function seedAssoc(array $overrides = []): void {
        DB::table('ASSOC_DATA')->insert(array_replace($this->pk(), [
            'c_source' => 10,
            'c_pages' => '1-5',
        ], $overrides));
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'create-assoc-tester@example.com'): User {
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
            'resource' => 'associations',
            'person_id' => 1000,
            'mode' => 'direct',
            'target' => ['pk' => $this->pk()],
            'changes' => [
                'c_source' => 20,
                'c_notes' => '新增社會關係',
            ],
        ], $overrides);
    }

    // ── AI 智能識別回寫 ai_fill_logs（回歸：React 遷移後 v2 create 未回寫 → 全部誤顯示「未提交」）─────

    #[Test]
    public function testDirectAssociationCreateWithAiFillLogIdMarksLogSubmitted(): void {
        $user = $this->makeUser(email: 'create-assoc-ailog@example.com');
        $this->actingAs($user);
        $logId = $this->insertAiFillLog($user->id);

        $this->postJson('/api/v2/create', $this->createPayload([
            'meta' => ['ai_fill_log_id' => $logId],
        ]))->assertOk();

        $log = DB::table('ai_fill_logs')->where('id', $logId)->first();
        $this->assertNotNull($log->user_submitted, 'user_submitted 應回寫（已提交）');
        $this->assertNotNull($log->submitted_at, 'submitted_at 應回寫');
        $submitted = json_decode($log->user_submitted, true);
        $this->assertSame(100, (int) $submitted['c_assoc_code']);
    }

    #[Test]
    public function testDirectAssociationCreateWithoutAiFillLogIdLeavesLogUntouched(): void {
        $user = $this->makeUser(email: 'create-assoc-noailog@example.com');
        $this->actingAs($user);
        $logId = $this->insertAiFillLog($user->id);

        $this->postJson('/api/v2/create', $this->createPayload())->assertOk();

        $log = DB::table('ai_fill_logs')->where('id', $logId)->first();
        $this->assertNull($log->user_submitted, '無 log id 不應回寫');
        $this->assertNull($log->submitted_at);
    }

    #[Test]
    public function testAssocAiFillLogNotOverwrittenForOtherUsersLog(): void {
        $owner = $this->makeUser(email: 'create-assoc-ailog-owner@example.com');
        $actor = $this->makeUser(email: 'create-assoc-ailog-actor@example.com');
        $logId = $this->insertAiFillLog($owner->id);

        $this->actingAs($actor);
        $this->postJson('/api/v2/create', $this->createPayload([
            'meta' => ['ai_fill_log_id' => $logId],
        ]))->assertOk();

        $log = DB::table('ai_fill_logs')->where('id', $logId)->first();
        $this->assertNull($log->user_submitted, '他人日誌不得被覆寫');
    }

    #[Test]
    public function testAssocAiFillLogNotOverwrittenForWrongCategory(): void {
        // category 守衛：帶入非 assoc 類（如 status）的 log id 不得被 assoc handler 回寫。
        $user = $this->makeUser(email: 'create-assoc-ailog-cat@example.com');
        $this->actingAs($user);
        $logId = $this->insertAiFillLog($user->id, 1000, 'status');

        $this->postJson('/api/v2/create', $this->createPayload([
            'meta' => ['ai_fill_log_id' => $logId],
        ]))->assertOk();

        $log = DB::table('ai_fill_logs')->where('id', $logId)->first();
        $this->assertNull($log->user_submitted, '不同 category 的日誌不得被回寫');
    }

    #[Test]
    public function testAssocAiFillLogNotOverwrittenForDifferentPerson(): void {
        $user = $this->makeUser(email: 'create-assoc-ailog-person@example.com');
        $this->actingAs($user);
        $logId = $this->insertAiFillLog($user->id, 999);

        $this->postJson('/api/v2/create', $this->createPayload([
            'person_id' => 1000,
            'meta' => ['ai_fill_log_id' => $logId],
        ]))->assertOk();

        $log = DB::table('ai_fill_logs')->where('id', $logId)->first();
        $this->assertNull($log->user_submitted, '不同人物的日誌不得被回寫');
    }

    #[Test]
    public function testProposalAssociationCreateDoesNotRecordAiFillSubmission(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-assoc-ailog-proposal@example.com');
        $this->actingAs($user);
        $logId = $this->insertAiFillLog($user->id);

        $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'proposal',
            'meta' => ['ai_fill_log_id' => $logId],
        ]))->assertOk();

        $log = DB::table('ai_fill_logs')->where('id', $logId)->first();
        $this->assertNull($log->user_submitted, 'proposal 提交當下不回寫');
    }

    #[Test]
    public function testAssocCreateCodeFieldSentinelFullyIdempotent(): void {
        // #71（確認 parity）：assoc create 既以 emptyToSentinel 達成 c_source 完全幂等——null/''/'-999'/-999/'0'/0
        // 落庫皆 0、永不寫 null/''；合法非 0 保留。每案用不同 c_assoc_id 取獨立正向 PK。≥10 案例。
        $this->actingAs($this->makeUser(email: 'assoc-create-sentinel@example.com'));
        $T = 'ASSOC_DATA';
        foreach ([null, '', '-999', -999, '0', 0] as $i => $sent) {
            $other = 3100 + $i;
            $this->postJson('/api/v2/create', $this->createPayload([
                'target' => ['pk' => $this->pk(['c_assoc_id' => $other])],
                'changes' => ['c_source' => $sent],
            ]))->assertOk()->assertJson(['ok' => true]);
            $stored = DB::table($T)->where(['c_personid' => 1000, 'c_assoc_id' => $other, 'c_assoc_code' => 100])->value('c_source');
            $this->assertNotNull($stored, 'c_source 送 '.var_export($sent, true).' 不得為 null');
            $this->assertSame('0', (string) $stored, 'c_source 送 '.var_export($sent, true).' 應規範化為 0');
        }
        foreach ([5, 7, 999, 42] as $i => $sent) {
            $other = 3200 + $i;
            $this->postJson('/api/v2/create', $this->createPayload([
                'target' => ['pk' => $this->pk(['c_assoc_id' => $other])],
                'changes' => ['c_source' => $sent],
            ]))->assertOk();
            $stored = DB::table($T)->where(['c_personid' => 1000, 'c_assoc_id' => $other, 'c_assoc_code' => 100])->value('c_source');
            $this->assertSame($sent, (int) $stored, '合法非 0 值不得被誤清：'.$sent);
        }
    }

    #[Test]
    public function testDirectAssociationCreatePersistsEraLunarFields(): void {
        // 回歸：legacy x-inline-time-fields 送出 c_assoc_fy_*/c_assoc_ly_* era 農曆欄；v2 白名單原漏 → 靜默流失。
        $this->actingAs($this->makeUser(email: 'assoc-era@example.com'));

        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => [
                'c_source' => 20,
                'c_assocship_pair' => 101,
                'c_assoc_fy_nh_code' => 5, 'c_assoc_fy_nh_year' => 3, 'c_assoc_fy_range' => 2,
                'c_assoc_fy_intercalary' => 1, 'c_assoc_fy_month' => 6, 'c_assoc_fy_day' => 12, 'c_assoc_fy_day_gz' => 7,
                'c_assoc_ly_nh_code' => 8, 'c_assoc_ly_month' => 9, 'c_assoc_ly_day' => 30, 'c_assoc_ly_day_gz' => 21,
            ],
        ]))->assertOk();

        // 正向落 era。
        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000,
            'c_assoc_fy_nh_code' => 5, 'c_assoc_fy_month' => 6, 'c_assoc_fy_day_gz' => 7,
            'c_assoc_ly_nh_code' => 8, 'c_assoc_ly_day' => 30,
        ]);
        // 互逆鏡像也含 era（時間欄沿用正向）。
        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000,
            'c_assoc_fy_nh_code' => 5, 'c_assoc_fy_month' => 6, 'c_assoc_ly_nh_code' => 8,
        ]);
    }

    #[Test]
    public function testDirectAssociationCreateWritesReciprocalMirror(): void {
        // 後台自動雙向同步（32a）：新增正向關係時，於同交易內無條件寫入互逆鏡像列
        // （對齊 legacy assocStoreById；對方為主體、原人為客體、用反向關係碼）。
        $this->actingAs($this->makeUser(email: 'assoc-mirror@example.com'));

        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => [
                'c_source' => 20,
                'c_notes' => '甲對乙',
                'c_assocship_pair' => 101,
                'c_kinship_pair' => 0,
                'c_assoc_kinship_pair' => 0,
            ],
        ]))->assertOk()->assertJson(['ok' => true, 'operation' => 'create']);

        // 正向（主）列。
        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000,
            'c_text_title' => '史記', 'c_assoc_first_year' => 1080, 'c_notes' => '甲對乙',
        ]);
        // 互逆鏡像列：對方(2000)為主體、原人(1000)為客體、反向關係碼 101。
        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000,
            'c_text_title' => '史記', 'c_assoc_first_year' => 1080, 'c_notes' => '甲對乙',
        ]);
    }

    #[Test]
    public function testCreateMirrorUsesAuthoritativePairWhenPairNotSent(): void {
        // 惡性 bug 回歸：使用者新增社會關係但編輯器未送 c_assocship_pair 時，反向鏡像列的關係碼
        // 必須以 ASSOC_CODES.c_assoc_pair 權威值補齊（code 100 → 101），**不可**落成哨兵 0（「未详」）。
        // 先前 CreateHandler 未補齊（只有 MutationHandler 有），導致對方人物出現一條未详的成對關係。
        $this->actingAs($this->makeUser(email: 'assoc-mirror-backfill@example.com'));

        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_source' => 20, 'c_notes' => '甲對乙（未送配對碼）'],
        ]))->assertOk()->assertJson(['ok' => true]);

        // 正向（主）列。
        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000,
        ]);
        // 互逆鏡像：對方(2000)為主體、反向碼=權威 101（非 0）。
        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000,
        ]);
        // 反向碼絕不可是 0（未详）。
        $this->assertDatabaseMissing('ASSOC_DATA', [
            'c_personid' => 2000, 'c_assoc_code' => 0, 'c_assoc_id' => 1000,
        ]);
    }

    #[Test]
    public function testCreateMirrorRespectsExplicitPairOverride(): void {
        // 反向有歧義時使用者手選（c_assoc_pair2=198 取代預設 c_assoc_pair=101）→ 鏡像須用手選值 198。
        $this->actingAs($this->makeUser(email: 'assoc-mirror-override@example.com'));

        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_source' => 20, 'c_assocship_pair' => 198],
        ]))->assertOk();

        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 2000, 'c_assoc_code' => 198, 'c_assoc_id' => 1000,
        ]);
        $this->assertDatabaseMissing('ASSOC_DATA', [
            'c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000,
        ]);
    }

    #[Test]
    public function testCreateMirrorUsesSentinelOnlyWhenCodeTrulyUnpaired(): void {
        // code 300 無 ASSOC_CODES 反向（c_assoc_pair=null）且未送 c_assocship_pair → 鏡像碼為哨兵 0（合理，對齊 legacy）。
        $this->actingAs($this->makeUser(email: 'assoc-mirror-unpaired@example.com'));

        $this->postJson('/api/v2/create', $this->createPayload([
            'target' => ['pk' => $this->pk(['c_assoc_code' => 300])],
            'changes' => ['c_source' => 20],
        ]))->assertOk();

        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 2000, 'c_assoc_code' => 0, 'c_assoc_id' => 1000,
        ]);
    }

    #[Test]
    public function testProposalAssociationCreateStoresPairsInAux(): void {
        // proposal 模式不直接寫列（含鏡像），但須把互逆配對碼存入 __proposal_aux，核准時建鏡像。
        $this->actingAs($this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'assoc-prop@example.com'));

        $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'proposal',
            'changes' => ['c_source' => 20, 'c_assocship_pair' => 101],
            'meta' => ['comment' => '請審'],
        ]))->assertOk()->assertJson(['ok' => true, 'mode' => 'proposal']);

        $op = DB::table('operations')->where('resource', 'ASSOC_DATA')
            ->where('op_type', Operation::TYPE_PROPOSAL_CREATE)->latest('id')->first();
        $this->assertNotNull($op);
        $data = json_decode($op->resource_data, true);
        $this->assertSame(101, (int) ($data['__proposal_aux']['c_assocship_pair'] ?? null));
        // 缺送的配對碼必須以哨兵 0 補齊（核准時 assocStoreById 無條件讀三鍵，缺鍵會變 null）。
        $this->assertSame(0, (int) ($data['__proposal_aux']['c_kinship_pair'] ?? null));
        $this->assertSame(0, (int) ($data['__proposal_aux']['c_assoc_kinship_pair'] ?? null));
        // 提案不應實際寫入正式列（含鏡像）。
        $this->assertDatabaseMissing('ASSOC_DATA', ['c_personid' => 1000, 'c_assoc_code' => 100]);
        $this->assertDatabaseMissing('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 101]);
    }

    #[Test]
    public function testDirectAssociationCreateSucceeds(): void {
        $user = $this->makeUser(email: 'create-assoc-direct@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'associations',
                'mode' => 'direct',
                'operation' => 'create',
            ]);

        $this->assertNotNull($response->json('result.operation_id'));
        $this->assertNotNull($response->json('result.row'));

        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 1000,
            'c_assoc_code' => 100,
            'c_assoc_id' => 2000,
            'c_text_title' => '史記',
            'c_assoc_first_year' => 1080,
            'c_source' => 20,
            'c_notes' => '新增社會關係',
        ]);
    }

    #[Test]
    public function testDirectAssociationCreatePersistsRestoredFields(): void {
        // 回歸（Task 27）：補回的 7 欄位（topic/occasion/tertiary 人物+說明/claimer/addr/inst 兩欄）
        // 在 create 路徑必須真的寫入 ASSOC_DATA，否則新編輯器填了卻存不進＝內容流失。
        $user = $this->makeUser(email: 'create-assoc-restored@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => [
                'c_source' => 20,
                'c_notes' => '新增',
                'c_topic_code' => 5,
                'c_occasion_code' => 7,
                'c_tertiary_personid' => 3000,
                'c_tertiary_type_notes' => '中間人說明',
                'c_assoc_claimer_id' => 4000,
                'c_addr_id' => 101117,
                'c_inst_code' => 12,
                'c_inst_name_code' => 34,
            ],
        ]))->assertOk();

        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 1000,
            'c_assoc_code' => 100,
            'c_assoc_id' => 2000,
            'c_topic_code' => 5,
            'c_occasion_code' => 7,
            'c_tertiary_personid' => 3000,
            'c_tertiary_type_notes' => '中間人說明',
            'c_assoc_claimer_id' => 4000,
            'c_addr_id' => 101117,
            'c_inst_code' => 12,
            'c_inst_name_code' => 34,
        ]);
    }

    #[Test]
    public function testDirectAssociationCreateWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'create-assoc-op@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'ASSOC_DATA',
            'op_type' => Operation::TYPE_CREATE,
        ]);
    }

    #[Test]
    public function testDirectAssociationCreateWritesAuditLog(): void {
        $user = $this->makeUser(email: 'create-assoc-audit@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload());

        $audit = DB::table('audit_log')->where('table_name', 'ASSOC_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('INSERT', $audit->operation);
    }

    #[Test]
    public function testProposalAssociationCreateSucceeds(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-assoc-proposal@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'proposal',
            'meta' => ['comment' => '提案新增社會關係'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'associations',
                'mode' => 'proposal',
                'operation' => 'create',
                'result' => ['status' => 'proposal_created'],
            ]);

        $this->assertDatabaseMissing('ASSOC_DATA', [
            'c_personid' => 1000,
            'c_assoc_code' => 100,
            'c_assoc_id' => 2000,
            'c_text_title' => '史記',
        ]);
    }

    #[Test]
    public function testProposalAssociationCreateWritesProposalMeta(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-assoc-prop-meta@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'proposal',
            'meta' => ['comment' => '提案新增社會關係'],
        ]));

        $operation = DB::table('operations')->where('resource', 'ASSOC_DATA')->first();
        $this->assertNotNull($operation);
        $this->assertSame(Operation::TYPE_PROPOSAL_CREATE, (int) $operation->op_type);

        $resourceData = json_decode($operation->resource_data, true);
        $this->assertSame('pending', $resourceData['__review_status']);
        $this->assertSame('create', $resourceData['__proposal_meta']['action']);
        $this->assertSame('associations', $resourceData['__proposal_meta']['resource_type']);
        $this->assertSame('提案新增社會關係', $resourceData['__proposal_meta']['comment']);

        $this->assertDatabaseMissing('audit_log', ['table_name' => 'ASSOC_DATA']);
    }

    #[Test]
    public function testCreateDuplicateTargetPkReturns409(): void {
        $user = $this->makeUser(email: 'create-assoc-dup@example.com');
        $this->actingAs($user);

        $this->seedAssoc();

        $this->postJson('/api/v2/create', $this->createPayload())
            ->assertStatus(409)->assertJson(['ok' => false]);
    }

    #[Test]
    public function testCreateDuplicatePendingProposalReturns409(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-assoc-dup-prop@example.com');
        $this->actingAs($user);

        $payload = $this->createPayload(['mode' => 'proposal']);

        $this->postJson('/api/v2/create', $payload)->assertOk();
        $this->postJson('/api/v2/create', $payload)
            ->assertStatus(409)
            ->assertJson(['ok' => false, 'errors' => ['target.pk' => ['pending_proposal_exists']]]);

        $this->assertSame(1, DB::table('operations')->where('resource', 'ASSOC_DATA')->count());
    }

    #[Test]
    public function testCreateWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'create-assoc-mismatch@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload(['person_id' => 9999]))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    #[Test]
    public function testCreateRejectsDisallowedField(): void {
        $user = $this->makeUser(email: 'create-assoc-disallowed@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_not_a_real_column' => 'x'],
        ]));

        $response->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertArrayHasKey('changes', $response->json('errors'));
        $this->assertDatabaseMissing('ASSOC_DATA', [
            'c_personid' => 1000,
            'c_assoc_code' => 100,
            'c_assoc_id' => 2000,
        ]);
    }

    #[Test]
    public function testCreateWithHyphenInTextTitleRoundTrips(): void {
        $user = $this->makeUser(email: 'create-assoc-hyphen@example.com');
        $this->actingAs($user);

        // c_text_title 含「-」：驗證 v2 結構化 PK 不會誤拆（對照 legacy path-based 的 explode('-') 陷阱）
        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'target' => ['pk' => $this->pk(['c_text_title' => '論語-註釋'])],
        ]));

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('論語-註釋', $response->json('result.pk.c_text_title'));

        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 1000,
            'c_assoc_code' => 100,
            'c_assoc_id' => 2000,
            'c_text_title' => '論語-註釋',
            'c_assoc_first_year' => 1080,
        ]);
    }

    #[Test]
    public function testCreateNormalizesSentinelPkValues(): void {
        // 對齊 legacy store() 的 emptyToSentinel：-999 哨兵 → 數值關聯 PK 轉 '0'、c_assoc_first_year 轉 '-9999'。
        // 註：空字串 PK 會先被 ConvertEmptyStringsToNull middleware 轉為 null 並由 validateOrFail 以 400 擋下，
        //     故 v2 client 表達「未知」應送 -999 哨兵（或直接送 '[n/a]' / '-9999'）。
        $user = $this->makeUser(email: 'create-assoc-sentinel@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'target' => ['pk' => $this->pk([
                'c_kin_code' => -999,
                'c_assoc_first_year' => -999,
            ])],
        ]));

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('0', $response->json('result.pk.c_kin_code'));
        $this->assertSame('-9999', $response->json('result.pk.c_assoc_first_year'));

        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => 1000,
            'c_assoc_code' => 100,
            'c_assoc_id' => 2000,
            'c_kin_code' => 0,
            'c_assoc_first_year' => -9999,
        ]);
    }

    #[Test]
    public function testCreateRejectsUnauthenticatedUser(): void {
        $this->postJson('/api/v2/create', $this->createPayload())->assertStatus(401);
    }

    #[Test]
    public function testCreateRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'create-assoc-inactive@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload())->assertStatus(403);
    }

    #[Test]
    public function testDirectCreateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-assoc-crowd@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload(['mode' => 'direct']))->assertStatus(403);
    }

    // ── #56 M 寫入等價（legacy Blade vs v2）+ 幂等 ──────────────────

    #[Test]
    public function testAssocV2CreateIdempotentResendNoDuplicate(): void {
        // #56（幂等）：v2 create 同輸入重送不得產生重複列——既有 PK 應被擋（非靜默再插一筆）。
        $this->actingAs($this->makeUser(email: 'assoc-midem@example.com'));
        $payload = [
            'resource' => 'associations', 'person_id' => 1000, 'mode' => 'direct',
            'target' => ['pk' => $this->pk(['c_assoc_id' => 4000])],
            'changes' => ['c_source' => 20, 'c_notes' => '幂等', 'c_assocship_pair' => 101],
        ];

        $this->postJson('/api/v2/create', $payload)->assertOk()->assertJson(['ok' => true]);
        $countAfterFirst = DB::table('ASSOC_DATA')->count(); // 正向 + 鏡像 = 2
        // 幂等須涵蓋「聯動表」：重送被拒時，operations / audit_log 也不得多寫一筆（否則側效泄漏）。
        $opsAfterFirst = DB::table('operations')->count();
        $auditAfterFirst = DB::table('audit_log')->count();

        // 重送同 PK → create handler 契約為 409 conflict（鎖死，不接受其他失敗碼以免假綠）。
        $this->postJson('/api/v2/create', $payload)
            ->assertStatus(409)
            ->assertJson(['ok' => false])
            ->assertJsonFragment(['target.pk' => ['conflict']]); // 鍵名含點為字面 key，非巢狀

        $this->assertSame($countAfterFirst, DB::table('ASSOC_DATA')->count(), '重送不得新增重複列（含鏡像）');
        $this->assertSame($opsAfterFirst, DB::table('operations')->count(), '重送被拒不得新增 operations 列');
        $this->assertSame($auditAfterFirst, DB::table('audit_log')->count(), '重送被拒不得新增 audit_log 列');
    }

    // ── #70 鏡像疑似匹配（CREATE 路徑）─────────────────────────────
    // create 與 update 共用 syncAssocMirrorOnUpdate 的 Option 2 安全判別：建立反向鏡像前，若對面已有「碼漂移
    // （∉ 合法 ASSOC_CODE）」的疑似同關係列 → 非 force 時拋 409 errors.mirror_suspected（整筆回滾、正向也不建），
    // force 時就地收斂該漂移列為權威反向碼（不補出重複鏡像）；碼∈合法 code 的他段關係**絕不覆寫**。

    /** 在對面（2000→1000）預埋一條反向鏡像列；預設碼 99（漂移垃圾值，∉ ASSOC_CODES）。書名/首年對齊正向以入放寬探測。 */
    protected function seedReverseAssoc(array $overrides = []): void {
        DB::table('ASSOC_DATA')->insert(array_replace($this->pk([
            'c_personid' => 2000, 'c_assoc_code' => 99, 'c_assoc_id' => 1000,
            'c_text_title' => '史記', 'c_assoc_first_year' => 1080,
        ]), [
            'c_source' => 5, 'c_pages' => '舊', 'c_notes' => '舊鏡像',
        ], $overrides));
    }

    #[Test]
    public function testAssocCreateMirrorSuspectedDetectedAborts(): void {
        // 對面已有碼漂移（99）疑似列、嚴格反向集 {101} 落空 → 非 force 建立時偵測疑似 → 409 + 整筆回滾。
        $this->actingAs($this->makeUser(email: 'assoc-c-suspect@example.com'));
        $this->seedReverseAssoc(); // (2000,99,1000) 史記/1080

        $res = $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_source' => 20, 'c_notes' => '甲對乙'],
        ]));

        $res->assertStatus(409)->assertJson(['ok' => false]);
        $this->assertSame('ASSOC_DATA', $res->json('errors.mirror_suspected.table'));
        $this->assertSame(101, $res->json('errors.mirror_suspected.authoritative_code'));
        $this->assertSame(1, $res->json('errors.mirror_suspected.count'));
        // 整筆回滾：正向列不得建立；漂移列維持原碼 99 不被觸碰。
        $this->assertDatabaseMissing('ASSOC_DATA', ['c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000]);
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 99, 'c_assoc_id' => 1000]);
        // 不得補出第二條反向鏡像（101）。
        $this->assertDatabaseMissing('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000]);
    }

    #[Test]
    public function testAssocCreateMirrorSuspectedForceCollapses(): void {
        // force=true → 就地收斂漂移列（99→權威反向碼 101、套用新內容），不補出重複鏡像；正向列建立。
        $this->actingAs($this->makeUser(email: 'assoc-c-force@example.com'));
        $this->seedReverseAssoc();

        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_source' => 20, 'c_notes' => '甲對乙'],
            'meta' => ['force' => true],
        ]))->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000]);
        // 漂移列被收斂：99 消失、101 出現（內容更新為新鏡像 c_source=20）。
        $this->assertDatabaseMissing('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 99, 'c_assoc_id' => 1000]);
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000, 'c_source' => 20]);
        // 收斂列整列套用新鏡像內容（非僅改碼）：c_kin_id / c_assoc_kin_id 皆=本人 1000（原預埋為 0）。
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000, 'c_kin_id' => 1000, 'c_assoc_kin_id' => 1000]);
        // 對面 (2000→1000) 只剩一條反向列（無重複）。
        $this->assertSame(1, DB::table('ASSOC_DATA')->where(['c_personid' => 2000, 'c_assoc_id' => 1000])->count());
    }

    #[Test]
    public function testAssocCreateStrictReverseDifferingContentConflicts(): void {
        // 非對稱安全（#66 套用 create）：對面已有「權威反向碼 101 嚴格命中」但內容不同（c_source=5≠欲寫 20）的既有列
        // → 非 force 拋 MirrorConflictException→409，整筆回滾（正向不建、既有列不被靜默覆寫）；force 才覆寫。
        $this->actingAs($this->makeUser(email: 'assoc-c-strict-conf@example.com'));
        $this->seedReverseAssoc(['c_assoc_code' => 101, 'c_source' => 5]); // (2000,101,1000) 既有、不同內容

        $res = $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_source' => 20, 'c_notes' => '甲對乙'],
        ]));
        $res->assertStatus(409)->assertJson(['ok' => false]);
        $this->assertSame('ASSOC_DATA', $res->json('errors.mirror_conflict.table'));
        // 回滾：正向未建、既有反向列內容（c_source=5）未被觸碰。
        $this->assertDatabaseMissing('ASSOC_DATA', ['c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000]);
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000, 'c_source' => 5]);

        // force=true → 覆寫既有反向列為新內容（c_source 5→20），正向建立，對面仍只一條（無重複）。
        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_source' => 20, 'c_notes' => '甲對乙'],
            'meta' => ['force' => true],
        ]))->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000]);
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000, 'c_source' => 20]);
        $this->assertSame(1, DB::table('ASSOC_DATA')->where(['c_personid' => 2000, 'c_assoc_id' => 1000])->count());
    }

    #[Test]
    public function testAssocCreateStrictReverseIdenticalContentIdempotent(): void {
        // 對面既有嚴格命中列「內容相同」→ 不視為分歧（同內容鏡像可冪等通過），正常建立、對面仍一條（無重複/無 409）。
        $this->actingAs($this->makeUser(email: 'assoc-c-strict-idem@example.com'));
        // 與下方 create 的鏡像內容一致：c_source=20、c_notes=甲對乙、c_pages=null（createPayload 未送）。
        $this->seedReverseAssoc(['c_assoc_code' => 101, 'c_source' => 20, 'c_notes' => '甲對乙', 'c_pages' => null]);

        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_source' => 20, 'c_notes' => '甲對乙'],
        ]))->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000]);
        $this->assertSame(1, DB::table('ASSOC_DATA')->where(['c_personid' => 2000, 'c_assoc_id' => 1000])->count());
    }

    #[Test]
    public function testAssocCreateMultiDriftForceCollapsesFirstNoExtraInsert(): void {
        // 多條漂移 + force：就地收斂「第一條」漂移列為權威反向碼 101，其餘漂移列保留（待人工刪），
        // **不**再 backfill 補新列（修掉舊「多條→落 backfill→殘留多條垃圾」）。對面列數維持 2（收斂 1 + 殘留 1），非 3。
        $this->actingAs($this->makeUser(email: 'assoc-c-multidrift@example.com'));
        // 兩條漂移（碼 99 / 98，皆∉ ASSOC_CODES），以 c_kin_code 區分 PK。
        $this->seedReverseAssoc(['c_assoc_code' => 99, 'c_kin_code' => 0, 'c_source' => 5]);
        $this->seedReverseAssoc(['c_assoc_code' => 98, 'c_kin_code' => 7, 'c_source' => 6]);

        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_source' => 20, 'c_notes' => '甲對乙'],
            'meta' => ['force' => true],
        ]))->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000]);
        // 恰一條被收斂為 101；對面總列數 = 2（無第三條 backfill）。
        $this->assertSame(1, DB::table('ASSOC_DATA')->where(['c_personid' => 2000, 'c_assoc_id' => 1000, 'c_assoc_code' => 101])->count());
        $this->assertSame(2, DB::table('ASSOC_DATA')->where(['c_personid' => 2000, 'c_assoc_id' => 1000])->count());
    }

    #[Test]
    public function testAssocCreateValidCodeOtherReverseNotClobbered(): void {
        // Option 2 安全：對面預埋的是合法碼（300，∉ 嚴格反向集 {101}）的「他段關係」→ 視為合法、絕不覆寫，
        // 本段鏡像照常 backfill（101）；非 force 也不誤判為疑似。
        $this->actingAs($this->makeUser(email: 'assoc-c-valid@example.com'));
        $this->seedReverseAssoc(['c_assoc_code' => 300]); // (2000,300,1000) 合法碼、他段關係

        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_source' => 20, 'c_notes' => '甲對乙'],
        ]))->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000]);
        // 他段合法關係 300 不被觸碰。
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 300, 'c_assoc_id' => 1000, 'c_source' => 5]);
        // 本段反向鏡像 101 照常補建。
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000, 'c_source' => 20]);
    }

    #[Test]
    public function testAssocCreateNoExistingReverseBackfillsNormally(): void {
        // 對面無任何相符列 → 正常 backfill 寫反向鏡像（委派 sync 後 create 預設行為不變）。
        $this->actingAs($this->makeUser(email: 'assoc-c-backfill@example.com'));

        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_source' => 20, 'c_notes' => '甲對乙'],
        ]))->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000]);
        $this->assertDatabaseHas('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000]);
    }
}
