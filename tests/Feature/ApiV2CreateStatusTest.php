<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2CreateStatusTest extends TestCase {
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
        $this->createStatusTable();
        $this->createAiFillLogsTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('ai_fill_logs');
        Schema::dropIfExists('STATUS_DATA');
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

    protected function createStatusTable(): void {
        Schema::create('STATUS_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_sequence')->default(0);
            $table->integer('c_status_code')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->text('c_supplement')->nullable();
            $table->integer('c_firstyear')->nullable();
            $table->integer('c_fy_nh_code')->nullable();
            $table->integer('c_fy_nh_year')->nullable();
            $table->integer('c_fy_range')->nullable();
            $table->integer('c_lastyear')->nullable();
            $table->integer('c_ly_nh_code')->nullable();
            $table->integer('c_ly_nh_year')->nullable();
            $table->integer('c_ly_range')->nullable();
            $table->string('c_created_by', 255)->nullable();
            $table->string('c_created_date', 255)->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->string('c_modified_date', 255)->nullable();
            $table->primary(['c_personid', 'c_sequence', 'c_status_code']);
        });
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

    /** 建立一筆待提交的 AI 填充日誌（預設 category='status'），回傳 log id。 */
    protected function insertAiFillLog(int $userId, int $personId = 1000, string $category = 'status'): int {
        return DB::table('ai_fill_logs')->insertGetId([
            'user_id' => $userId,
            'c_personid' => $personId,
            'category' => $category,
            'route_name' => 'app.basicinformation.statuses.editv2',
            'route_url' => '/app/basicinformation/'.$personId.'/statuses/edit-v2',
            'source_text' => '儒童',
            'ai_matched' => json_encode(['matched_codes' => [['code_id' => 60]]]),
            'success' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function seedStatus(array $overrides = []): void {
        DB::table('STATUS_DATA')->insert(array_replace([
            'c_personid' => 1000,
            'c_sequence' => 1,
            'c_status_code' => 50,
            'c_source' => 10,
            'c_pages' => '1-5',
            'c_notes' => null,
            'c_firstyear' => 1050,
            'c_lastyear' => 1100,
        ], $overrides));
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'create-status-tester@example.com'): User {
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
            'resource' => 'statuses',
            'person_id' => 1000,
            'mode' => 'direct',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_sequence' => 2,
                    'c_status_code' => 60,
                ],
            ],
            'changes' => [
                'c_source' => 20,
                'c_notes' => '新增狀態',
            ],
        ], $overrides);
    }

    // ── AI 智能識別回寫 ai_fill_logs（回歸：React 遷移後 v2 create 未回寫 → 全部誤顯示「未提交」）─────

    #[Test]
    public function testDirectStatusCreateWithAiFillLogIdMarksLogSubmitted(): void {
        $user = $this->makeUser(email: 'create-status-ailog@example.com');
        $this->actingAs($user);
        $logId = $this->insertAiFillLog($user->id);

        $this->postJson('/api/v2/create', $this->createPayload([
            'meta' => ['ai_fill_log_id' => $logId],
        ]))->assertOk();

        $log = DB::table('ai_fill_logs')->where('id', $logId)->first();
        $this->assertNotNull($log->user_submitted, 'user_submitted 應回寫（已提交）');
        $this->assertNotNull($log->submitted_at, 'submitted_at 應回寫');
        $submitted = json_decode($log->user_submitted, true);
        $this->assertSame(60, (int) $submitted['c_status_code']);
        $this->assertSame(20, (int) $submitted['c_source']);
    }

    #[Test]
    public function testDirectStatusCreateWithoutAiFillLogIdLeavesLogUntouched(): void {
        $user = $this->makeUser(email: 'create-status-noailog@example.com');
        $this->actingAs($user);
        $logId = $this->insertAiFillLog($user->id);

        $this->postJson('/api/v2/create', $this->createPayload())->assertOk();

        $log = DB::table('ai_fill_logs')->where('id', $logId)->first();
        $this->assertNull($log->user_submitted, '無 log id 不應回寫');
        $this->assertNull($log->submitted_at);
    }

    #[Test]
    public function testStatusAiFillLogNotOverwrittenForOtherUsersLog(): void {
        // 安全：只能回寫自己的日誌（WHERE user_id 守衛）。
        $owner = $this->makeUser(email: 'create-status-ailog-owner@example.com');
        $actor = $this->makeUser(email: 'create-status-ailog-actor@example.com');
        $logId = $this->insertAiFillLog($owner->id);

        $this->actingAs($actor);
        $this->postJson('/api/v2/create', $this->createPayload([
            'meta' => ['ai_fill_log_id' => $logId],
        ]))->assertOk();

        $log = DB::table('ai_fill_logs')->where('id', $logId)->first();
        $this->assertNull($log->user_submitted, '他人日誌不得被覆寫');
    }

    #[Test]
    public function testStatusAiFillLogNotOverwrittenForWrongCategory(): void {
        // category 守衛：帶入非 status 類（如 posting）的 log id 不得被 status handler 回寫。
        $user = $this->makeUser(email: 'create-status-ailog-cat@example.com');
        $this->actingAs($user);
        $logId = $this->insertAiFillLog($user->id, 1000, 'posting');

        $this->postJson('/api/v2/create', $this->createPayload([
            'meta' => ['ai_fill_log_id' => $logId],
        ]))->assertOk();

        $log = DB::table('ai_fill_logs')->where('id', $logId)->first();
        $this->assertNull($log->user_submitted, '不同 category 的日誌不得被回寫');
    }

    #[Test]
    public function testStatusAiFillLogNotOverwrittenForDifferentPerson(): void {
        // 連結完整性：只回寫「同一人物」的日誌（c_personid 守衛，$personId 為 handler 權威值）。
        $user = $this->makeUser(email: 'create-status-ailog-person@example.com');
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
    public function testProposalStatusCreateDoesNotRecordAiFillSubmission(): void {
        // 提案模式於核准時才落庫，提交當下不回寫 user_submitted（另計）。
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-status-ailog-proposal@example.com');
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
    public function testStatusCreateCodeFieldSentinelFullyIdempotent(): void {
        // #71：create c_source 完全幂等——null/''/'-999'/-999/'0'/0 落庫皆 0、永不寫 null/''；合法非 0 保留。
        // 每案用不同 c_sequence 取獨立 PK。≥10 案例。
        $this->actingAs($this->makeUser(email: 'status-create-sentinel@example.com'));
        $T = 'STATUS_DATA';
        foreach ([null, '', '-999', -999, '0', 0] as $i => $sent) {
            $seq = 10 + $i;
            $this->postJson('/api/v2/create', $this->createPayload([
                'target' => ['pk' => ['c_sequence' => $seq]],
                'changes' => ['c_source' => $sent],
            ]))->assertOk()->assertJson(['ok' => true]);
            $stored = DB::table($T)->where(['c_personid' => 1000, 'c_sequence' => $seq, 'c_status_code' => 60])->value('c_source');
            $this->assertNotNull($stored, 'c_source 送 '.var_export($sent, true).' 不得為 null');
            $this->assertSame('0', (string) $stored, 'c_source 送 '.var_export($sent, true).' 應規範化為 0');
        }
        foreach ([5, 7, 999, 42] as $i => $sent) {
            $seq = 20 + $i;
            $this->postJson('/api/v2/create', $this->createPayload([
                'target' => ['pk' => ['c_sequence' => $seq]],
                'changes' => ['c_source' => $sent],
            ]))->assertOk();
            $stored = DB::table($T)->where(['c_personid' => 1000, 'c_sequence' => $seq, 'c_status_code' => 60])->value('c_source');
            $this->assertSame($sent, (int) $stored, '合法非 0 值不得被誤清：'.$sent);
        }
    }

    // ── Direct Create Tests ─────────────────────────────────

    #[Test]
    public function testDirectStatusCreateSucceeds(): void {
        $user = $this->makeUser(email: 'create-status-direct@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'statuses',
                'mode' => 'direct',
                'operation' => 'create',
            ]);

        $data = $response->json();
        $this->assertNotNull($data['result']['operation_id']);
        $this->assertNotNull($data['result']['row']);

        $this->assertDatabaseHas('STATUS_DATA', [
            'c_personid' => 1000,
            'c_sequence' => 2,
            'c_status_code' => 60,
            'c_source' => 20,
            'c_notes' => '新增狀態',
        ]);
    }

    #[Test]
    public function testDirectStatusCreateWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'create-status-op@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'STATUS_DATA',
            'op_type' => Operation::TYPE_CREATE,
        ]);
    }

    #[Test]
    public function testDirectStatusCreateWritesAuditLog(): void {
        $user = $this->makeUser(email: 'create-status-audit@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload());

        $audit = DB::table('audit_log')->where('table_name', 'STATUS_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('INSERT', $audit->operation);
    }

    // ── Proposal Create Tests ───────────────────────────────

    #[Test]
    public function testProposalStatusCreateSucceeds(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-status-proposal@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'proposal',
            'meta' => ['comment' => '提案新增狀態'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'statuses',
                'mode' => 'proposal',
                'operation' => 'create',
                'result' => [
                    'status' => 'proposal_created',
                ],
            ]);

        // 原始資料表不應有新增的資料
        $this->assertDatabaseMissing('STATUS_DATA', [
            'c_personid' => 1000,
            'c_sequence' => 2,
            'c_status_code' => 60,
        ]);
    }

    #[Test]
    public function testProposalStatusCreateWritesProposalMeta(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-status-prop-meta@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'proposal',
            'meta' => ['comment' => '提案新增狀態'],
        ]));

        $this->assertDatabaseHas('operations', [
            'resource' => 'STATUS_DATA',
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
        ]);

        $operation = DB::table('operations')->where('resource', 'STATUS_DATA')->first();
        $resourceData = json_decode($operation->resource_data, true);

        $this->assertSame('pending', $resourceData['__review_status']);
        $this->assertSame('create', $resourceData['__proposal_meta']['action']);
        $this->assertSame('statuses', $resourceData['__proposal_meta']['resource_type']);
        $this->assertSame('提案新增狀態', $resourceData['__proposal_meta']['comment']);

        // 無 audit_log
        $this->assertDatabaseMissing('audit_log', ['table_name' => 'STATUS_DATA']);
    }

    // ── Error Cases ─────────────────────────────────────────

    #[Test]
    public function testCreateDuplicateTargetPkReturns409(): void {
        $user = $this->makeUser(email: 'create-status-dup@example.com');
        $this->actingAs($user);

        // 先建立一筆與 createPayload PK 相同的資料
        $this->seedStatus([
            'c_personid' => 1000,
            'c_sequence' => 2,
            'c_status_code' => 60,
        ]);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertStatus(409)
            ->assertJson(['ok' => false]);
    }

    #[Test]
    public function testCreateDuplicatePendingProposalReturns409(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-status-dup-prop@example.com');
        $this->actingAs($user);

        $payload = $this->createPayload([
            'mode' => 'proposal',
        ]);

        // 第一次提案成功
        $first = $this->postJson('/api/v2/create', $payload);
        $first->assertOk();

        // 第二次相同提案被拒絕
        $second = $this->postJson('/api/v2/create', $payload);
        $second->assertStatus(409)
            ->assertJson(['ok' => false, 'errors' => ['target.pk' => ['pending_proposal_exists']]]);

        // 只有一筆提案
        $this->assertSame(1, DB::table('operations')->where('resource', 'STATUS_DATA')->count());
    }

    #[Test]
    public function testCreateWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'create-status-mismatch@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'person_id' => 9999,
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    // ── Auth / Permission Tests ─────────────────────────────

    #[Test]
    public function testCreateRejectsUnauthenticatedUser(): void {
        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertStatus(401);
    }

    #[Test]
    public function testCreateRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'create-status-inactive@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertStatus(403);
    }

    #[Test]
    public function testDirectCreateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-status-crowd@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'direct',
        ]));

        $response->assertStatus(403);
    }
}
