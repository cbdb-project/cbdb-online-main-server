<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2CreatePostingTest extends TestCase {
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
        $this->createPostingTables();
        $this->createAiFillLogsTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('ai_fill_logs');
        Schema::dropIfExists('POSTED_TO_ADDR_DATA');
        Schema::dropIfExists('POSTED_TO_OFFICE_DATA');
        Schema::dropIfExists('POSTING_DATA');
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

    protected function createPostingTables(): void {
        Schema::create('POSTING_DATA', function (Blueprint $table) {
            $table->integer('c_personid')->default(0);
            $table->integer('c_posting_id')->primary();
            $table->string('c_created_by', 255)->nullable();
            $table->string('c_created_date', 255)->nullable();
        });

        Schema::create('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
            $table->integer('c_personid')->default(0);
            $table->integer('c_posting_id')->default(0);
            $table->integer('c_office_id')->default(0);
            $table->integer('c_sequence')->nullable();
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->text('c_supplement')->nullable();
            $table->integer('c_firstyear')->nullable();
            $table->integer('c_fy_nh_code')->nullable();
            $table->integer('c_fy_nh_year')->nullable();
            $table->integer('c_fy_range')->nullable();
            $table->integer('c_fy_intercalary')->default(0);
            $table->integer('c_fy_month')->nullable();
            $table->integer('c_fy_day')->nullable();
            $table->integer('c_fy_day_gz')->nullable();
            $table->integer('c_lastyear')->nullable();
            $table->integer('c_ly_nh_code')->nullable();
            $table->integer('c_ly_nh_year')->nullable();
            $table->integer('c_ly_range')->nullable();
            $table->integer('c_ly_intercalary')->default(0);
            $table->integer('c_ly_month')->nullable();
            $table->integer('c_ly_day')->nullable();
            $table->integer('c_ly_day_gz')->nullable();
            $table->integer('c_appt_code')->default(0);
            $table->integer('c_assume_office_code')->nullable();
            $table->integer('c_dy')->nullable();
            $table->integer('c_inst_code')->default(0);
            $table->integer('c_inst_name_code')->default(0);
            $table->integer('c_office_category_id')->nullable();
            $table->string('c_created_by', 255)->nullable();
            $table->string('c_created_date', 255)->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->string('c_modified_date', 255)->nullable();
            $table->primary(['c_office_id', 'c_posting_id']);
        });

        Schema::create('POSTED_TO_ADDR_DATA', function (Blueprint $table) {
            $table->integer('c_personid')->default(0);
            $table->integer('c_posting_id')->default(0);
            $table->integer('c_office_id')->default(0);
            $table->integer('c_addr_id')->default(0);
            $table->string('c_created_by', 255)->nullable();
            $table->string('c_created_date', 255)->nullable();
        });
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

    /** 建立一筆待提交的 AI 填充日誌，回傳 log id。 */
    protected function insertAiFillLog(int $userId, int $personId = 1000, string $category = 'posting'): int {
        return DB::table('ai_fill_logs')->insertGetId([
            'user_id' => $userId,
            'c_personid' => $personId,
            'category' => $category,
            'route_name' => 'app.basicinformation.office.edit',
            'route_url' => '/app/basicinformation/'.$personId.'/office',
            'source_text' => '至和年間知某縣',
            'ai_matched' => json_encode(['matched_fields' => []]),
            'success' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'create-post-tester@example.com'): User {
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
            'resource' => 'postings',
            'person_id' => 1000,
            'mode' => 'direct',
            'target' => ['pk' => []],
            'changes' => [
                'c_office_id' => 87473,
                'c_source' => 20,
                'c_notes' => '新增任官',
                'c_firstyear' => 1050,
                'c_addr' => [130],
            ],
        ], $overrides);
    }

    #[Test]
    public function testPostingCreateCodeFieldSentinelFullyIdempotent(): void {
        // #71：create c_source 完全幂等——null/''/'-999'/-999/'0'/0 落庫皆 0、永不寫 null/''；合法非 0 保留。
        // 每案配發新 c_posting_id（同 c_office_id）取獨立 PK。≥10 案例。
        $this->actingAs($this->makeUser(email: 'posting-create-sentinel@example.com'));
        $T = 'POSTED_TO_OFFICE_DATA';
        foreach ([null, '', '-999', -999, '0', 0] as $sent) {
            $pk = $this->postJson('/api/v2/create', $this->createPayload([
                'changes' => ['c_source' => $sent],
            ]))->assertOk()->json('result.pk');
            $stored = DB::table($T)->where(['c_office_id' => $pk['c_office_id'], 'c_posting_id' => $pk['c_posting_id']])->value('c_source');
            $this->assertNotNull($stored, 'c_source 送 '.var_export($sent, true).' 不得為 null');
            $this->assertSame('0', (string) $stored, 'c_source 送 '.var_export($sent, true).' 應規範化為 0');
        }
        foreach ([5, 7, 999, 42] as $sent) {
            $pk = $this->postJson('/api/v2/create', $this->createPayload([
                'changes' => ['c_source' => $sent],
            ]))->assertOk()->json('result.pk');
            $stored = DB::table($T)->where(['c_office_id' => $pk['c_office_id'], 'c_posting_id' => $pk['c_posting_id']])->value('c_source');
            $this->assertSame($sent, (int) $stored, '合法非 0 值不得被誤清：'.$sent);
        }
    }

    #[Test]
    public function testDirectPostingCreateSucceedsAndAllocatesPostingId(): void {
        $user = $this->makeUser(email: 'create-post-direct@example.com');
        $this->actingAs($user);

        // 既有 posting id=5 → 新增應配發 6
        DB::table('POSTING_DATA')->insert(['c_personid' => 999, 'c_posting_id' => 5]);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'postings',
            'mode' => 'direct',
            'operation' => 'create',
        ]);

        $this->assertSame(87473, (int) $response->json('result.pk.c_office_id'));
        $this->assertSame(6, (int) $response->json('result.pk.c_posting_id'));

        $this->assertDatabaseHas('POSTED_TO_OFFICE_DATA', [
            'c_office_id' => 87473,
            'c_posting_id' => 6,
            'c_personid' => 1000,
            'c_source' => 20,
            'c_notes' => '新增任官',
            'c_firstyear' => 1050,
        ]);
        $this->assertDatabaseHas('POSTING_DATA', ['c_posting_id' => 6, 'c_personid' => 1000]);
    }

    #[Test]
    public function testDirectPostingCreatePersistsLunarFields(): void {
        // 回歸：legacy 表單（showLunar=true）送出農曆 month/day/day_gz，officeStoreById 會寫入；
        // v2 create 白名單原本漏掉這些欄 → 新編輯器填了卻存不進＝內容流失。
        $user = $this->makeUser(email: 'create-post-lunar@example.com');
        $this->actingAs($user);
        DB::table('POSTING_DATA')->insert(['c_personid' => 999, 'c_posting_id' => 5]);

        $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => [
                'c_office_id' => 87473,
                'c_addr' => [],
                'c_fy_month' => 3, 'c_fy_day' => 8, 'c_fy_day_gz' => 11,
                'c_ly_month' => 11, 'c_ly_day' => 28, 'c_ly_day_gz' => 47,
            ],
        ]))->assertOk();

        $this->assertDatabaseHas('POSTED_TO_OFFICE_DATA', [
            'c_office_id' => 87473, 'c_posting_id' => 6,
            'c_fy_month' => 3, 'c_fy_day' => 8, 'c_fy_day_gz' => 11,
            'c_ly_month' => 11, 'c_ly_day' => 28, 'c_ly_day_gz' => 47,
        ]);
    }

    #[Test]
    public function testDirectPostingCreatePersistsRestoredFields(): void {
        // 回歸（Task 27）：補回的 c_assume_office_code/c_dy/c_inst_code/c_inst_name_code/c_office_category_id
        // 在 create 路徑必須真的寫入，否則新編輯器填了卻存不進＝內容流失。
        $user = $this->makeUser(email: 'create-post-restored@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => [
                'c_office_id' => 87473,
                'c_source' => 20,
                'c_notes' => '新增',
                'c_addr' => [],
                'c_assume_office_code' => 1,
                'c_dy' => 15,
                'c_inst_code' => 12,
                'c_inst_name_code' => 34,
                'c_office_category_id' => 2,
            ],
        ]));
        $response->assertOk();

        $this->assertDatabaseHas('POSTED_TO_OFFICE_DATA', [
            'c_office_id' => 87473,
            'c_personid' => 1000,
            'c_assume_office_code' => 1,
            'c_dy' => 15,
            'c_inst_code' => 12,
            'c_inst_name_code' => 34,
            'c_office_category_id' => 2,
        ]);
    }

    #[Test]
    public function testDirectPostingCreateWritesAddressSideTable(): void {
        $user = $this->makeUser(email: 'create-post-addr@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload());
        $pk = $response->json('result.pk');

        $this->assertDatabaseHas('POSTED_TO_ADDR_DATA', [
            'c_personid' => 1000,
            'c_posting_id' => $pk['c_posting_id'],
            'c_office_id' => 87473,
            'c_addr_id' => 130,
        ]);
    }

    #[Test]
    public function testDirectPostingCreateWritesOperationAndAudit(): void {
        $user = $this->makeUser(email: 'create-post-op@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'op_type' => Operation::TYPE_CREATE,
        ]);

        $audit = DB::table('audit_log')->where('table_name', 'POSTED_TO_OFFICE_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('INSERT', $audit->operation);
    }

    #[Test]
    public function testDirectPostingCreateWithAiFillLogIdMarksLogSubmitted(): void {
        // 回歸：React 遷移後，v2 direct create 未回寫 ai_fill_logs → /admin/ai-fill-logs 全部誤顯示
        // 「Not Submitted」。修法：meta.ai_fill_log_id 傳入時回寫 user_submitted + submitted_at。
        $user = $this->makeUser(email: 'create-post-ailog@example.com');
        $this->actingAs($user);

        $logId = $this->insertAiFillLog($user->id);

        $this->postJson('/api/v2/create', $this->createPayload([
            'meta' => ['ai_fill_log_id' => $logId],
        ]))->assertOk();

        $log = DB::table('ai_fill_logs')->where('id', $logId)->first();
        $this->assertNotNull($log->user_submitted, 'user_submitted 應回寫（已提交）');
        $this->assertNotNull($log->submitted_at, 'submitted_at 應回寫');

        $submitted = json_decode($log->user_submitted, true);
        // 使用者實際提交的欄位值（不論是否人工修改過 AI 建議，皆以 log id 連結、原樣記錄）。
        $this->assertSame(87473, (int) $submitted['c_office_id']);
        $this->assertSame([130], $submitted['c_addr']);
    }

    #[Test]
    public function testDirectPostingCreateWithoutAiFillLogIdLeavesLogUntouched(): void {
        // 未使用 AI 自動填（無 meta.ai_fill_log_id）時，不得誤動任何既有日誌。
        $user = $this->makeUser(email: 'create-post-noailog@example.com');
        $this->actingAs($user);

        $logId = $this->insertAiFillLog($user->id);

        $this->postJson('/api/v2/create', $this->createPayload())->assertOk();

        $log = DB::table('ai_fill_logs')->where('id', $logId)->first();
        $this->assertNull($log->user_submitted, '無 log id 不應回寫');
        $this->assertNull($log->submitted_at);
    }

    #[Test]
    public function testAiFillLogNotOverwrittenForOtherUsersLog(): void {
        // 安全：只能回寫自己的日誌。傳入他人的 log id 不得覆寫（WHERE user_id 守衛）。
        $owner = $this->makeUser(email: 'create-post-ailog-owner@example.com');
        $actor = $this->makeUser(email: 'create-post-ailog-actor@example.com');
        $logId = $this->insertAiFillLog($owner->id);

        $this->actingAs($actor);
        $this->postJson('/api/v2/create', $this->createPayload([
            'meta' => ['ai_fill_log_id' => $logId],
        ]))->assertOk();

        $log = DB::table('ai_fill_logs')->where('id', $logId)->first();
        $this->assertNull($log->user_submitted, '他人日誌不得被覆寫');
    }

    #[Test]
    public function testAiFillLogNotOverwrittenForDifferentPerson(): void {
        // 連結完整性：只回寫「同一人物」的日誌。log 屬於人物 999，卻在人物 1000 存檔時帶入其 log id，
        // c_personid 守衛應擋下（$personId 為 handler 權威值，非脆弱推導）。
        $user = $this->makeUser(email: 'create-post-ailog-person@example.com');
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
    public function testProposalPostingCreateDoesNotRecordAiFillSubmission(): void {
        // 提案模式於核准時才落庫，提交當下不回寫 user_submitted（另計）。
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-post-ailog-proposal@example.com');
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
    public function testCreateMissingOfficeIdReturns422(): void {
        $user = $this->makeUser(email: 'create-post-nooffice@example.com');
        $this->actingAs($user);

        $payload = $this->createPayload();
        unset($payload['changes']['c_office_id']);

        $this->postJson('/api/v2/create', $payload)
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    #[Test]
    public function testProposalPostingCreateWritesProposalOperationWithoutInsertingMainTable(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-post-proposal@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload(['mode' => 'proposal']));

        $response->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'postings',
            'mode' => 'proposal',
            'operation' => 'create',
            'result' => ['status' => 'proposal_created'],
        ]);

        // 寫 TYPE_PROPOSAL_CREATE operation
        $operation = Operation::where('resource', 'POSTED_TO_OFFICE_DATA')
            ->where('op_type', Operation::TYPE_PROPOSAL_CREATE)
            ->first();
        $this->assertNotNull($operation);

        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('pending', $payload['__review_status']);
        $this->assertSame(['c_office_id', 'c_posting_id'], $payload['__key_columns']);
        $this->assertSame([130], $payload['__proposal_aux']['c_addr']);

        // 提交時不應實際 insert 主表與副表
        $this->assertDatabaseMissing('POSTED_TO_OFFICE_DATA', ['c_office_id' => 87473]);
        $this->assertSame(0, DB::table('POSTED_TO_ADDR_DATA')->count());
    }

    #[Test]
    public function testProposalPostingCreateRejectsDuplicatePendingProposal(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-post-dup@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload(['mode' => 'proposal']))->assertOk();

        $this->postJson('/api/v2/create', $this->createPayload(['mode' => 'proposal']))
            ->assertStatus(409)
            ->assertJson(['ok' => false]);
    }

    #[Test]
    public function testProposalPostingCreateApprovalWritesMainAndAddressTables(): void {
        $author = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-post-approve-author@example.com');
        $this->actingAs($author);
        $this->postJson('/api/v2/create', $this->createPayload(['mode' => 'proposal']))->assertOk();

        $operation = Operation::where('resource', 'POSTED_TO_OFFICE_DATA')
            ->where('op_type', Operation::TYPE_PROPOSAL_CREATE)
            ->firstOrFail();

        $reviewer = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_EXPERT, 'create-post-reviewer@example.com');
        $this->actingAs($reviewer);

        $this->post(route('operations.proposals.approve', $operation))->assertRedirect();

        // 核准後寫入主表（c_posting_id 於核准時配發）
        $this->assertDatabaseHas('POSTED_TO_OFFICE_DATA', [
            'c_office_id' => 87473,
            'c_personid' => 1000,
            'c_source' => 20,
            'c_notes' => '新增任官',
            'c_firstyear' => 1050,
        ]);

        $row = DB::table('POSTED_TO_OFFICE_DATA')->where('c_office_id', 87473)->first();
        // 副表 POSTED_TO_ADDR_DATA 正確寫入
        $this->assertDatabaseHas('POSTED_TO_ADDR_DATA', [
            'c_office_id' => 87473,
            'c_posting_id' => $row->c_posting_id,
            'c_personid' => 1000,
            'c_addr_id' => 130,
        ]);

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status']);
    }

    #[Test]
    public function testProposalPostingCreateRejectDoesNotWriteTables(): void {
        $author = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-post-reject-author@example.com');
        $this->actingAs($author);
        $this->postJson('/api/v2/create', $this->createPayload(['mode' => 'proposal']))->assertOk();

        $operation = Operation::where('resource', 'POSTED_TO_OFFICE_DATA')
            ->where('op_type', Operation::TYPE_PROPOSAL_CREATE)
            ->firstOrFail();

        $reviewer = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_EXPERT, 'create-post-reject-reviewer@example.com');
        $this->actingAs($reviewer);

        $this->post(route('operations.proposals.reject', $operation))->assertRedirect();

        $this->assertDatabaseMissing('POSTED_TO_OFFICE_DATA', ['c_office_id' => 87473]);
        $this->assertSame(0, DB::table('POSTED_TO_ADDR_DATA')->count());

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('rejected', $payload['__review_status']);
    }

    #[Test]
    public function testCreateRejectsUnknownPersonZero(): void {
        $user = $this->makeUser(email: 'create-post-zero@example.com');
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
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'create-post-inactive@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload())->assertStatus(403);
    }

    #[Test]
    public function testDirectCreateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-post-crowd@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload(['mode' => 'direct']))->assertStatus(403);
    }
}
