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
    }

    protected function tearDown(): void {
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

    // ── #56 M 寫入等價（legacy Blade vs v2）——主列 + 地址副表 ──────────

    #[Test]
    #[Group('legacy-parity')] // 旧版下线时連同 legacy 路徑一併移除（v2 行為另有 v2-only 測試覆蓋）
    public function testPostingCreateWriteEquivalenceLegacyVsV2WithAddressSubtable(): void {
        // #56（M 維度，副表）：任官同語義輸入分別經 ① legacy Blade store（OfficePostingRepository）
        // ② v2 /api/v2/create 寫入（各自配發不同自增 c_posting_id），斷言主列 POSTED_TO_OFFICE_DATA 內容欄等價，
        // 且**地址副表 POSTED_TO_ADDR_DATA 兩路都落庫**——直接打到測試計畫點名的「副表靜默不落庫」風險。
        // offices 因 c_posting_id 自增、重送必產生新列（設計如此），故不在此驗「重送 409」式幂等。
        $this->actingAs($this->makeUser(email: 'post-mwrite@example.com'));

        // ① legacy：1000 任官 office 87473、地址 130。
        $this->post('/basicinformation/1000/offices', [
            'action' => 'save',
            'c_office_id' => 87473, 'c_inst_code' => '0',
            'c_source' => 20, 'c_notes' => 'M 等價', 'c_firstyear' => 1050,
            'c_fy_intercalary' => 0, 'c_ly_intercalary' => 0,
            'c_addr' => [130],
        ]);
        $legacyPid = (int) DB::table('POSTED_TO_OFFICE_DATA')->where(['c_personid' => 1000, 'c_office_id' => 87473])->value('c_posting_id');

        // ② v2：同語義輸入。
        $res = $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_office_id' => 87473, 'c_source' => 20, 'c_notes' => 'M 等價', 'c_firstyear' => 1050, 'c_addr' => [130]],
        ]))->assertOk()->assertJson(['ok' => true, 'operation' => 'create']);
        $v2Pid = (int) $res->json('result.pk.c_posting_id');

        $this->assertNotSame(0, $legacyPid, 'legacy 未配發 posting id / 未寫主列');
        $this->assertNotSame(0, $v2Pid, 'v2 未配發 posting id');
        $this->assertNotSame($legacyPid, $v2Pid, '兩路應寫不同 posting id（各自自增）');

        // 主列內容欄等價（排除差異 c_posting_id + 稽核欄）。
        $legacyOffice = (array) DB::table('POSTED_TO_OFFICE_DATA')->where(['c_posting_id' => $legacyPid, 'c_office_id' => 87473])->first();
        $v2Office = (array) DB::table('POSTED_TO_OFFICE_DATA')->where(['c_posting_id' => $v2Pid, 'c_office_id' => 87473])->first();
        foreach (['c_personid', 'c_office_id', 'c_source', 'c_notes', 'c_firstyear'] as $col) {
            $this->assertSame((string) $legacyOffice[$col], (string) $v2Office[$col], "主列欄 {$col} 新舊不等價");
        }

        // 地址副表：兩路都寫了 addr 130（核心斷言——副表不得靜默不落庫）。
        $legacyAddr = DB::table('POSTED_TO_ADDR_DATA')->where(['c_posting_id' => $legacyPid, 'c_office_id' => 87473, 'c_addr_id' => 130])->first();
        $v2Addr = DB::table('POSTED_TO_ADDR_DATA')->where(['c_posting_id' => $v2Pid, 'c_office_id' => 87473, 'c_addr_id' => 130])->first();
        $this->assertNotNull($legacyAddr, 'legacy 未寫地址副表 POSTED_TO_ADDR_DATA');
        $this->assertNotNull($v2Addr, 'v2 未寫地址副表 POSTED_TO_ADDR_DATA（副表靜默不落庫）');
        $this->assertSame((int) $legacyAddr->c_personid, (int) $v2Addr->c_personid, '副表 c_personid 新舊不等價');
        $this->assertSame((int) $legacyAddr->c_addr_id, (int) $v2Addr->c_addr_id, '副表 c_addr_id 新舊不等價');
    }
}
