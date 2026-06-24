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
    }

    protected function tearDown(): void {
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
}
