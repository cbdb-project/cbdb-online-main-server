<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use App\Services\CharVariantMapService;
use App\Support\VariantReplaceScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 「社會機構實體」update／delete mutation（resource=social-institution）回歸測試。
 *
 * 驗證 SocialInstituteUpdateHandler / SocialInstituteDeleteHandler / SocialInstituteImportService
 * 的聚合語義：實體識別＝c_inst_code 單鍵、名稱去重解析、改名護欄（被引用回 409）、
 * ADDR 集合對賬、刪除護欄（四張人物表引用計數）、名碼不回收。
 */
class ApiV2MutateSocialInstituteEntityTest extends TestCase {
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

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('confirmation_token')->nullable();
            $table->integer('is_active')->default(0);
            $table->integer('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });
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
        Schema::create('SOCIAL_INSTITUTION_NAME_CODES', function (Blueprint $table) {
            $table->integer('c_inst_name_code')->primary();
            $table->string('c_inst_name_hz')->nullable();
            $table->string('c_inst_name_py')->nullable();
        });
        Schema::create('SOCIAL_INSTITUTION_CODES', function (Blueprint $table) {
            $table->integer('c_inst_name_code');
            $table->integer('c_inst_code');
            $table->integer('c_inst_type_code')->nullable();
            $table->integer('c_inst_begin_year')->nullable();
            $table->integer('c_by_nianhao_code')->nullable();
            $table->integer('c_by_nianhao_year')->nullable();
            $table->integer('c_by_year_range')->nullable();
            $table->integer('c_inst_begin_dy')->nullable();
            $table->integer('c_inst_floruit_dy')->nullable();
            $table->integer('c_inst_first_known_year')->nullable();
            $table->integer('c_inst_end_year')->nullable();
            $table->integer('c_ey_nianhao_code')->nullable();
            $table->integer('c_ey_nianhao_year')->nullable();
            $table->integer('c_ey_year_range')->nullable();
            $table->integer('c_inst_end_dy')->nullable();
            $table->integer('c_inst_last_known_year')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
            $table->primary(['c_inst_code', 'c_inst_name_code']);
        });
        Schema::create('SOCIAL_INSTITUTION_ADDR', function (Blueprint $table) {
            $table->integer('c_inst_name_code');
            $table->integer('c_inst_code');
            $table->integer('c_inst_addr_type_code');
            $table->integer('c_inst_addr_begin_year')->nullable();
            $table->integer('c_inst_addr_end_year')->nullable();
            $table->integer('c_inst_addr_id');
            $table->double('inst_xcoord');
            $table->double('inst_ycoord');
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
        });
        // 別名兩表：結構照搬生產（資料表**無主鍵**、全欄可空）。別名資料表是 2026_04_17
        // 遷移之後的 8 欄（c_secondary_source_author 已刪）；照舊建表腳本會讓測試在生產沒有的欄位上變綠。
        Schema::create('SOCIAL_INSTITUTION_ALTNAME_CODES', function (Blueprint $table) {
            $table->integer('c_inst_altname_type')->nullable();
            $table->string('c_inst_altname_desc')->nullable();
            $table->string('c_inst_altname_chn')->nullable();
            $table->string('c_notes')->nullable();
        });
        Schema::create('SOCIAL_INSTITUTION_ALTNAME_DATA', function (Blueprint $table) {
            $table->integer('c_inst_name_code')->nullable();
            $table->integer('c_inst_code')->nullable();
            $table->integer('c_inst_altname_type')->nullable();
            $table->string('c_inst_altname_hz')->nullable();
            $table->string('c_inst_altname_py')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->longText('c_notes')->nullable();
        });
        Schema::create('SOCIAL_INSTITUTION_TYPES', function (Blueprint $table) {
            $table->integer('c_inst_type_code')->primary();
            $table->string('c_inst_type_hz')->nullable();
            $table->string('c_inst_type_py')->nullable();
        });
        Schema::create('DYNASTIES', function (Blueprint $table) {
            $table->integer('c_dy')->primary();
            $table->string('c_dynasty_chn')->nullable();
        });
        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
        });
        Schema::create('ADDR_CODES', function (Blueprint $table) {
            $table->integer('c_addr_id')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
        });
        Schema::create('NIAN_HAO', function (Blueprint $table) {
            $table->integer('c_nianhao_id')->primary();
        });
        Schema::create('YEAR_RANGE_CODES', function (Blueprint $table) {
            $table->integer('c_range_code')->primary();
        });
        Schema::create('pinyin', function (Blueprint $table) {
            $table->increments('id');
            $table->string('c_chn')->nullable();
            $table->string('c_pinyin')->nullable();
            $table->integer('c_lastname')->default(0);
        });
        // 刪除／改名護欄：referenceCount() 數這四張人物表。
        foreach (['BIOG_INST_DATA', 'ENTRY_DATA', 'ASSOC_DATA', 'POSTED_TO_OFFICE_DATA'] as $t) {
            Schema::create($t, function (Blueprint $table) {
                $table->integer('c_personid');
                $table->integer('c_inst_code');
                $table->integer('c_inst_name_code');
            });
        }

        DB::table('DYNASTIES')->insert([
            ['c_dy' => 15, 'c_dynasty_chn' => '宋'],
            ['c_dy' => 19, 'c_dynasty_chn' => '明'],
        ]);
        DB::table('SOCIAL_INSTITUTION_TYPES')->insert([
            ['c_inst_type_code' => 1, 'c_inst_type_hz' => '書院', 'c_inst_type_py' => 'shuyuan'],
            ['c_inst_type_code' => 2, 'c_inst_type_hz' => '寺廟', 'c_inst_type_py' => 'simiao'],
        ]);
        DB::table('TEXT_CODES')->insert([['c_textid' => 7596], ['c_textid' => 8000]]);
        DB::table('SOCIAL_INSTITUTION_ALTNAME_CODES')->insert([
            ['c_inst_altname_type' => 0, 'c_inst_altname_desc' => '[Unknown]', 'c_inst_altname_chn' => '[未詳]'],
        ]);
        DB::table('ADDR_CODES')->insert([
            ['c_addr_id' => 101, 'c_name_chn' => '杭州'],
            ['c_addr_id' => 102, 'c_name_chn' => '蘇州'],
        ]);

        // 既有機構：inst_code=10、名碼=5（白鹿洞書院），一列地址。
        DB::table('SOCIAL_INSTITUTION_NAME_CODES')->insert([
            ['c_inst_name_code' => 5, 'c_inst_name_hz' => '白鹿洞書院', 'c_inst_name_py' => 'bailudong shuyuan'],
            ['c_inst_name_code' => 6, 'c_inst_name_hz' => '嶽麓書院', 'c_inst_name_py' => 'yuelu shuyuan'],
        ]);
        DB::table('SOCIAL_INSTITUTION_CODES')->insert([
            'c_inst_name_code' => 5, 'c_inst_code' => 10, 'c_inst_type_code' => 1,
            'c_inst_begin_dy' => 15, 'c_inst_floruit_dy' => 15, 'c_source' => 7596,
        ]);
        DB::table('SOCIAL_INSTITUTION_ADDR')->insert([
            'c_inst_name_code' => 5, 'c_inst_code' => 10, 'c_inst_addr_type_code' => 1,
            'c_inst_addr_id' => 101, 'inst_xcoord' => 0, 'inst_ycoord' => 0, 'c_source' => 7596,
        ]);
    }

    protected function tearDown(): void {
        // char_variant_map 的清理放在這裡而不是各測試方法尾：斷言失敗時方法尾不會執行。
        Schema::dropIfExists('char_variant_map');
        foreach ([
            'POSTED_TO_OFFICE_DATA', 'ASSOC_DATA', 'ENTRY_DATA', 'BIOG_INST_DATA', 'pinyin',
            'YEAR_RANGE_CODES', 'NIAN_HAO', 'ADDR_CODES', 'TEXT_CODES', 'DYNASTIES',
            'SOCIAL_INSTITUTION_TYPES', 'SOCIAL_INSTITUTION_ALTNAME_DATA', 'SOCIAL_INSTITUTION_ALTNAME_CODES',
            'SOCIAL_INSTITUTION_ADDR', 'SOCIAL_INSTITUTION_CODES',
            'SOCIAL_INSTITUTION_NAME_CODES', 'audit_log', 'operations', 'users',
        ] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    protected function makeUser(string $email = 'si@example.com', int $role = User::ROLE_REGULAR): User {
        return User::forceCreate([
            'name' => 'SI Tester',
            'email' => $email,
            'confirmation_token' => 'tok',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => $role,
        ]);
    }

    protected function updatePayload(array $changes = []): array {
        return [
            'resource' => 'social-institution',
            'operation' => 'update',
            'person_id' => 0,
            'target' => ['pk' => ['c_inst_code' => 10]],
            'changes' => array_merge([
                'name' => '白鹿洞書院',
                'type_code' => 1,
                'dynasty_code' => 15,
                'source_id' => 7596,
                'addresses' => [['addr_id' => 101]],
            ], $changes),
        ];
    }

    // ── 實體級提案（§4.5）：mode=proposal 存聚合意圖、核准時以 direct 重放同一 handler ──

    /** create 提案：眾包帳號 direct 403、proposal 200；三張表都不動；核准後三張表一次落庫。 */
    #[Test]
    public function testCreateProposalStoresIntentAndApprovalWritesAllThreeTables(): void {
        $this->actingAs($this->makeUser('si-p-create@example.com', User::ROLE_CROWDSOURCING));
        $body = [
            'resource' => 'social-institution', 'person_id' => 0, 'target' => ['pk' => []],
            'changes' => ['name' => '新書院', 'type_code' => 2, 'dynasty_code' => 19, 'addr_id' => 102, 'source_id' => 8000],
        ];

        $this->postJson('/api/v2/create', $body)->assertStatus(403);
        $res = $this->postJson('/api/v2/create', $body + ['mode' => 'proposal'])
            ->assertOk()
            ->assertJson(['ok' => true, 'resource' => 'social-institution', 'mode' => 'proposal', 'operation' => 'create', 'result' => ['pk' => null]]);

        $this->assertSame(0, DB::table('SOCIAL_INSTITUTION_NAME_CODES')->where('c_inst_name_hz', '新書院')->count());
        $this->assertSame(1, DB::table('SOCIAL_INSTITUTION_CODES')->count());
        $operation = Operation::findOrFail($res->json('result.operation_id'));
        $stored = json_decode($operation->resource_data, true);
        $this->assertTrue($stored['__entity_aggregate']);
        $this->assertSame('social-institution', $stored['__entity_resource']);
        $this->assertSame(['name' => '新書院', 'type_code' => 2, 'dynasty_code' => 19, 'addr_id' => 102, 'source_id' => 8000], $stored['changes']);

        $this->actingAs($this->makeUser('si-p-reviewer@example.com'));
        $this->post(route('operations.proposals.approve', $operation))->assertRedirect();

        $nameCode = (int) DB::table('SOCIAL_INSTITUTION_NAME_CODES')->where('c_inst_name_hz', '新書院')->value('c_inst_name_code');
        $this->assertGreaterThan(0, $nameCode);
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_name_code' => $nameCode, 'c_inst_type_code' => 2, 'c_inst_begin_dy' => 19, 'c_source' => 8000]);
        $instCode = (int) DB::table('SOCIAL_INSTITUTION_CODES')->where('c_inst_name_code', $nameCode)->value('c_inst_code');
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_ADDR', ['c_inst_code' => $instCode, 'c_inst_addr_id' => 102]);

        $payload = json_decode($operation->fresh()->resource_data, true);
        $this->assertSame('approved', $payload['__review_status']);
        $this->assertSame($instCode, (int) $payload['c_inst_code']);
        $appliedId = DB::table('operations')->where('resource', 'SOCIAL_INSTITUTION_CODES')->where('op_type', Operation::TYPE_CREATE)->value('id');
        $this->assertSame((string) $appliedId, $payload['__applied_operation_id'], '核准落庫的 direct operation id 要記回提案，「比較」才認領得到 audit');
    }

    /** update 提案：核准前資料不動，核准後與 direct update 同一份對賬邏輯（地址增列）。 */
    #[Test]
    public function testUpdateProposalIsAppliedOnlyOnApproval(): void {
        $this->actingAs($this->makeUser('si-p-upd@example.com', User::ROLE_CROWDSOURCING));
        $res = $this->postJson('/api/v2/mutate', $this->updatePayload([
            'notes' => '提案備註', 'addresses' => [['addr_id' => 101], ['addr_id' => 102]],
        ]) + ['mode' => 'proposal'])->assertOk()->assertJson(['mode' => 'proposal', 'operation' => 'update', 'result' => ['pk' => ['c_inst_code' => 10]]]);

        $this->assertDatabaseMissing('SOCIAL_INSTITUTION_CODES', ['c_inst_code' => 10, 'c_notes' => '提案備註']);
        $this->assertSame(1, DB::table('SOCIAL_INSTITUTION_ADDR')->where('c_inst_code', 10)->count());

        $this->actingAs($this->makeUser('si-p-upd-reviewer@example.com'));
        $this->post(route('operations.proposals.approve', Operation::findOrFail($res->json('result.operation_id'))))->assertRedirect();

        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_code' => 10, 'c_inst_name_code' => 5, 'c_notes' => '提案備註']);
        $this->assertSame(2, DB::table('SOCIAL_INSTITUTION_ADDR')->where('c_inst_code', 10)->count());
    }

    /** delete 提案：核准後 CODES＋ADDR 隨聚合刪除、名碼不回收（與 direct delete 同語義）。 */
    #[Test]
    public function testDeleteProposalIsAppliedOnlyOnApproval(): void {
        $this->actingAs($this->makeUser('si-p-del@example.com', User::ROLE_CROWDSOURCING));
        $res = $this->postJson('/api/v2/delete', [
            'resource' => 'social-institution', 'mode' => 'proposal', 'person_id' => 0,
            'target' => ['pk' => ['c_inst_code' => 10]],
        ])->assertOk()->assertJson(['mode' => 'proposal', 'operation' => 'delete']);
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_code' => 10]);

        $this->actingAs($this->makeUser('si-p-del-reviewer@example.com'));
        $this->post(route('operations.proposals.approve', Operation::findOrFail($res->json('result.operation_id'))))->assertRedirect();

        $this->assertSame(0, DB::table('SOCIAL_INSTITUTION_CODES')->where('c_inst_code', 10)->count());
        $this->assertSame(0, DB::table('SOCIAL_INSTITUTION_ADDR')->where('c_inst_code', 10)->count());
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_NAME_CODES', ['c_inst_name_code' => 5]);
    }

    /** 提案端與 direct 同一道護欄：被引用時改名 409，且不留下提案（不是等到核准才發現）。 */
    #[Test]
    public function testProposalIsGuardedAtSubmissionLikeDirect(): void {
        DB::table('BIOG_INST_DATA')->insert(['c_personid' => 1, 'c_inst_code' => 10, 'c_inst_name_code' => 5]);
        $this->actingAs($this->makeUser('si-p-guard@example.com', User::ROLE_CROWDSOURCING));

        $this->postJson('/api/v2/mutate', $this->updatePayload(['name' => '改名書院']) + ['mode' => 'proposal'])
            ->assertStatus(409)
            ->assertJsonPath('errors.name.0', 'rename_blocked_while_referenced');

        $this->assertSame(0, DB::table('operations')->where('op_type', Operation::TYPE_PROPOSAL_UPDATE)->count());
    }

    /**
     * 修改提案預填：新增頁以 ?proposal 拿到提案的 changes（形狀與表單送出一致，起始朝代叫
     * dynasty_code、地址叫 addr_id），picker 標籤照**提案值**查，不是照聚合現值。
     */
    #[Test]
    public function testCreatePagePrefillsTheProposalIntentAndLooksUpLabelsForIt(): void {
        $this->actingAs($this->makeUser('si-p-prefill@example.com', User::ROLE_CROWDSOURCING));
        $res = $this->postJson('/api/v2/create', [
            'resource' => 'social-institution', 'mode' => 'proposal', 'person_id' => 0, 'target' => ['pk' => []],
            'changes' => ['name' => '新書院', 'type_code' => 2, 'dynasty_code' => 19, 'addr_id' => 102, 'source_id' => 8000],
        ])->assertOk();
        $proposalId = (int) $res->json('result.operation_id');

        $this->get("/app/social-institution/create?proposal={$proposalId}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SocialInstitution/Create')
                ->where('can_propose', true)
                ->where('can_edit', false)
                ->where('proposal_overlay.name', '新書院')
                ->where('proposal_overlay.dynasty_code', 19)
                ->where('proposal_overlay.addr_id', 102)
                ->where('initial_labels.dynasties.19', '明')
                ->where('initial_labels.addresses.102', '102 蘇州')
                ->where('resubmit.resubmit_endpoint', "/api/v2/proposals/{$proposalId}/resubmit"));

        // 沒帶 ?proposal：一般新增頁，眾包帳號（可提案）也進得來，旗標如實。
        $this->get('/app/social-institution/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('proposal_overlay', [])->where('resubmit', [])->where('can_propose', true));
    }

    // ── update ──────────────────────────────

    #[Test]
    public function testUpdateOverwritesColumnsAndKeepsNameCode(): void {
        $this->actingAs($this->makeUser(email: 'si-upd@example.com'));

        $res = $this->postJson('/api/v2/mutate', $this->updatePayload([
            'type_code' => 2,
            'begin_year' => 940,
            'end_dy' => 19,
            'notes' => '南唐建',
        ]));

        $res->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'social-institution',
            'operation' => 'update',
            'result' => ['pk' => ['c_inst_code' => 10], 'status' => 'updated', 'name_changed' => false],
        ]);
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', [
            'c_inst_code' => 10, 'c_inst_name_code' => 5, 'c_inst_type_code' => 2,
            'c_inst_begin_year' => 940, 'c_inst_end_dy' => 19, 'c_notes' => '南唐建',
        ]);
    }

    #[Test]
    public function testUpdateReconcilesAddressRows(): void {
        $this->actingAs($this->makeUser(email: 'si-addr@example.com'));

        // 101 同鍵改值（補起始年）、新增 102、無其他列 → 對賬結果兩列。
        $res = $this->postJson('/api/v2/mutate', $this->updatePayload([
            'addresses' => [
                ['addr_id' => 101, 'begin_year' => 940],
                ['addr_id' => 102, 'addr_type_code' => 1],
            ],
        ]));

        $res->assertOk()->assertJson(['result' => ['addr_added' => 1, 'addr_removed' => 0]]);
        $this->assertSame(2, DB::table('SOCIAL_INSTITUTION_ADDR')->where('c_inst_code', 10)->count());
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_ADDR', ['c_inst_code' => 10, 'c_inst_addr_id' => 101, 'c_inst_addr_begin_year' => 940]);
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_ADDR', ['c_inst_code' => 10, 'c_inst_addr_id' => 102]);
    }

    #[Test]
    public function testRenameUnreferencedReusesExistingNameCodeAndSyncsAddr(): void {
        $this->actingAs($this->makeUser(email: 'si-rename@example.com'));

        // 改名為既有名「嶽麓書院」→ 複用名碼 6（去重）、不新增 NAME_CODES；ADDR 名碼同步。
        $res = $this->postJson('/api/v2/mutate', $this->updatePayload(['name' => '嶽麓書院']));

        $res->assertOk()->assertJson(['result' => ['name_changed' => true, 'row' => ['c_inst_name_code' => 6]]]);
        $this->assertSame(2, DB::table('SOCIAL_INSTITUTION_NAME_CODES')->count());
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_code' => 10, 'c_inst_name_code' => 6]);
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_ADDR', ['c_inst_code' => 10, 'c_inst_name_code' => 6]);
        // 舊名碼不回收。
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_NAME_CODES', ['c_inst_name_code' => 5]);
    }

    #[Test]
    public function testRenameToNewNameCreatesNameCode(): void {
        $this->actingAs($this->makeUser(email: 'si-rename-new@example.com'));

        $res = $this->postJson('/api/v2/mutate', $this->updatePayload(['name' => '石鼓書院']));

        $res->assertOk()->assertJson(['result' => ['name_changed' => true, 'row' => ['c_inst_name_code' => 7]]]);
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_NAME_CODES', ['c_inst_name_code' => 7, 'c_inst_name_hz' => '石鼓書院']);
    }

    #[Test]
    public function testRenameBlockedWhileReferenced(): void {
        DB::table('BIOG_INST_DATA')->insert(['c_personid' => 1, 'c_inst_code' => 10, 'c_inst_name_code' => 5]);
        $this->actingAs($this->makeUser(email: 'si-rename-blocked@example.com'));

        $res = $this->postJson('/api/v2/mutate', $this->updatePayload(['name' => '嶽麓書院']));

        $res->assertStatus(409);
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_code' => 10, 'c_inst_name_code' => 5]);
    }

    #[Test]
    public function testUpdateOtherFieldsAllowedWhileReferenced(): void {
        DB::table('ENTRY_DATA')->insert(['c_personid' => 1, 'c_inst_code' => 10, 'c_inst_name_code' => 5]);
        $this->actingAs($this->makeUser(email: 'si-upd-ref@example.com'));

        // 同名（名碼不變）僅改其他欄位 → 不受改名護欄影響。
        $this->postJson('/api/v2/mutate', $this->updatePayload(['type_code' => 2]))
            ->assertOk()
            ->assertJson(['result' => ['name_changed' => false]]);
    }

    #[Test]
    public function testUpdateValidation(): void {
        $this->actingAs($this->makeUser(email: 'si-upd-422@example.com'));

        // 缺地址列
        $this->postJson('/api/v2/mutate', $this->updatePayload(['addresses' => []]))->assertStatus(422);
        // 不存在的地址
        $this->postJson('/api/v2/mutate', $this->updatePayload(['addresses' => [['addr_id' => 999]]]))->assertStatus(422);
        // 不存在的年號碼
        $this->postJson('/api/v2/mutate', $this->updatePayload(['by_nianhao_code' => 424242]))->assertStatus(422);
        // 不存在的機構
        $payload = $this->updatePayload();
        $payload['target']['pk']['c_inst_code'] = 999;
        $this->postJson('/api/v2/mutate', $payload)->assertStatus(404);
    }

    // ── delete ──────────────────────────────

    #[Test]
    public function testDeleteRemovesCodesAndAddrButKeepsNameCode(): void {
        $this->actingAs($this->makeUser(email: 'si-del@example.com'));

        $res = $this->postJson('/api/v2/delete', [
            'resource' => 'social-institution',
            'person_id' => 0,
            'target' => ['pk' => ['c_inst_code' => 10]],
        ]);

        $res->assertOk()->assertJson([
            'ok' => true,
            'result' => ['pk' => ['c_inst_code' => 10], 'status' => 'deleted', 'addr_deleted' => 1],
        ]);
        $this->assertDatabaseMissing('SOCIAL_INSTITUTION_CODES', ['c_inst_code' => 10]);
        $this->assertDatabaseMissing('SOCIAL_INSTITUTION_ADDR', ['c_inst_code' => 10]);
        // 名碼不回收。
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_NAME_CODES', ['c_inst_name_code' => 5]);
    }

    #[Test]
    public function testDeleteBlockedWhileReferencedByAnyOfFourTables(): void {
        DB::table('POSTED_TO_OFFICE_DATA')->insert(['c_personid' => 1, 'c_inst_code' => 10, 'c_inst_name_code' => 5]);
        $this->actingAs($this->makeUser(email: 'si-del-blocked@example.com'));

        $this->postJson('/api/v2/delete', [
            'resource' => 'social-institution',
            'person_id' => 0,
            'target' => ['pk' => ['c_inst_code' => 10]],
        ])->assertStatus(409);
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_code' => 10]);
    }
    // ── 異體字：標籤歸一與代碼白名單（plan S4）──────────────

    /**
     * 最小 char_variant_map 種子（「淸→清」）。其餘測試不建這張表，走
     * CharVariantMapService 的「表不存在就降級」路徑、行為不變。
     */
    protected function seedCharVariantMap(): void {
        Schema::create('char_variant_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('c_variant_char', 10);
            $table->string('c_reference_char', 10);
            $table->tinyInteger('c_strict_excluded')->default(1);
            $table->string('c_notes', 255)->nullable();
            $table->timestamps();
            $table->unique('c_variant_char', 'char_variant_map_c_variant_char_unique');
        });
        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
        ]);
        CharVariantMapService::reset();
        VariantReplaceScope::reset();
    }

    /**
     * (c) 代碼表同時有兩形（「淸」40 與「清」41）時，標籤歸一會讓兩列的鍵塌成一個
     * ——但**兩個代碼都必須仍然可用**。
     *
     * 這是把白名單從 `in_array(..., $map)` 改成 `in_array(..., $service->dynastyCodes())`
     * 的理由：拿 map 的值當白名單時，被碰撞吃掉的 41 會開始被判 dynasty invalid，
     * 而它是一個完全合法的 c_dy。
     */
    #[Test]
    public function testBothCodesStayValidWhenTwoDynastyLabelsNormalizeToTheSameKey(): void {
        $this->seedCharVariantMap();
        DB::table('DYNASTIES')->insert([
            ['c_dy' => 40, 'c_dynasty_chn' => '淸'],
            ['c_dy' => 41, 'c_dynasty_chn' => '清'],
        ]);
        $this->actingAs($this->makeUser('si-variant-whitelist@example.com'));

        // 被碰撞「吃掉」的那個碼（41，因為 map 只留最小的 40）仍須被接受。
        $this->postJson('/api/v2/mutate', $this->updatePayload(['dynasty_code' => 41]))
            ->assertOk();
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_code' => 10, 'c_inst_begin_dy' => 41]);

        // 另一個（40）當然也要能用。
        $this->postJson('/api/v2/mutate', $this->updatePayload(['dynasty_code' => 40]))
            ->assertOk();
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_code' => 10, 'c_inst_begin_dy' => 40]);

    }

    /**
     * 機構層與**地址列**的文本欄都要替換，回應回落庫值並帶 notices。
     *
     * 地址列的 c_pages／c_notes 是 review 抓到的缺口：同一次 update 裡機構層的 c_notes
     * 被歸一、地址列的 c_notes 原樣入庫（SOCIAL_INSTITUTION_ADDR 同樣是已知表、兩欄都是
     * 文本型，本來就在替換範圍內）。
     */
    #[Test]
    public function testUpdateReplacesVariantsInInstitutionAndAddressTextColumns(): void {
        $this->seedCharVariantMap();
        $this->actingAs($this->makeUser('si-variant-text@example.com'));

        $response = $this->postJson('/api/v2/mutate', $this->updatePayload([
            'notes' => '淸代重修',
            'pages' => '淸卷一',
            'addresses' => [[
                'addr_id' => 101,
                'notes' => '淸址備註',
                'pages' => '淸址頁',
            ]],
        ]))->assertOk();

        $code = DB::table('SOCIAL_INSTITUTION_CODES')->where('c_inst_code', 10)->first();
        $this->assertSame('清代重修', $code->c_notes);
        $this->assertSame('清卷一', $code->c_pages);

        $addr = DB::table('SOCIAL_INSTITUTION_ADDR')->where('c_inst_code', 10)->first();
        $this->assertSame('清址備註', $addr->c_notes, '地址列的備註也必須歸一');
        $this->assertSame('清址頁', $addr->c_pages);

        $this->assertNotEmpty($response->json('notices'), '回應必須帶異體字通知');
    }

    /**
     * 「只換了字形」的改名在 resolveNameCode() 兩形都探之下其實是 no-op，
     * 不得被改名護欄誤報成 409（該機構仍被人物資料引用）。
     */
    #[Test]
    public function testVariantOnlyRenameIsNotBlockedByReferenceGuard(): void {
        $this->seedCharVariantMap();
        // 既有名稱是參考形，且被人物資料引用。
        DB::table('SOCIAL_INSTITUTION_NAME_CODES')->where('c_inst_name_code', 5)
            ->update(['c_inst_name_hz' => '清溪書院']);
        DB::table('BIOG_INST_DATA')->insert(['c_personid' => 1, 'c_inst_code' => 10, 'c_inst_name_code' => 5]);
        $this->actingAs($this->makeUser('si-variant-rename@example.com'));

        $response = $this->postJson('/api/v2/mutate', $this->updatePayload([
            'name' => '淸溪書院', // 只是字形不同 ⇒ 解析到同一個 name_code
            'notes' => '改備註',
        ]));

        $this->assertSame(200, $response->getStatusCode(), '只換字形不算改名，不該被引用護欄擋下');
        $this->assertSame(5, (int) DB::table('SOCIAL_INSTITUTION_CODES')->where('c_inst_code', 10)->value('c_inst_name_code'));
        $this->assertSame('清溪書院', DB::table('SOCIAL_INSTITUTION_NAME_CODES')->where('c_inst_name_code', 5)->value('c_inst_name_hz'), '既有列不歸一也不被改寫');
        $this->assertSame('清溪書院', $response->json('result.row.c_inst_name_hz'), '回應要回實際生效的名稱');
    }

    /**
     * codex：`typeCodes()` 必須是 hz／py 兩份的**聯集**。schema 允許 c_inst_type_hz 為
     * null（只有拼音名），舊 typeMap() 也是任一有值就收；只取 hz 那份會讓這種列的
     * 合法 type_code 在白名單驗證被錯判 invalid（422）。
     */
    #[Test]
    public function testTypeCodeWithOnlyPinyinLabelStaysValid(): void {
        DB::table('SOCIAL_INSTITUTION_TYPES')->insert([
            'c_inst_type_code' => 9, 'c_inst_type_hz' => null, 'c_inst_type_py' => 'shuyuan-only',
        ]);
        $this->actingAs($this->makeUser('si-py-only@example.com'));

        $this->postJson('/api/v2/mutate', $this->updatePayload(['type_code' => 9]))->assertOk();
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_code' => 10, 'c_inst_type_code' => 9]);
    }

    /**
     * 去重是**單向**的，這條把邊界釘死：輸入參考形而既有列是**另一個**變體形時，
     * 精確比對命中不了（反方向需要列舉輸入的所有前像，plan 明確否決），
     * 所以會像 S4 之前一樣新建一個名稱碼。
     *
     * 這不是本步造成的回歸（S4 之前同樣新建），但也沒被本步修好——寫成測試是為了讓
     * 這個已知缺口有明文、不會被誤以為已解決。真正的修法是加一個歸一後的影子欄或
     * 做一次性資料合併，屬獨立工作。
     */
    #[Test]
    public function testReferenceFormInputDoesNotMergeIntoExistingVariantRow(): void {
        $this->seedCharVariantMap();
        DB::table('SOCIAL_INSTITUTION_NAME_CODES')->insert([
            'c_inst_name_code' => 7, 'c_inst_name_hz' => '淸溪書院', 'c_inst_name_py' => 'qing xi shu yuan',
        ]);
        $this->actingAs($this->makeUser('si-reverse-direction@example.com'));

        $response = $this->postJson('/api/v2/mutate', $this->updatePayload([
            'name' => '清溪書院', // 參考形；既有列是變體形
        ]))->assertOk();

        $newCode = (int) DB::table('SOCIAL_INSTITUTION_CODES')->where('c_inst_code', 10)->value('c_inst_name_code');
        $this->assertNotSame(7, $newCode, '反方向不會併入既有變體形列（已知邊界）');
        $this->assertSame('清溪書院', DB::table('SOCIAL_INSTITUTION_NAME_CODES')->where('c_inst_name_code', $newCode)->value('c_inst_name_hz'));
        // 沒有發生字元替換（輸入本來就是參考形）⇒ 不該有異體字通知。
        $this->assertNull($response->json('notices'));
    }

    /**
     * codex round 2：改名護欄要問「這次儲存會不會真的換掉 c_inst_name_code」，
     * 不能只比歸一後的字串。
     *
     * 反方向（輸入參考形、既有列是另一個變體形）歸一後兩邊字串看起來相同，但
     * resolveNameCode() 會**新建**一個 code ⇒ 對被引用的機構就是既存引用失配，
     * 必須照樣回 409。
     */
    #[Test]
    public function testReferenceFormInputIsStillBlockedWhenInstitutionIsReferenced(): void {
        $this->seedCharVariantMap();
        DB::table('SOCIAL_INSTITUTION_NAME_CODES')->where('c_inst_name_code', 5)
            ->update(['c_inst_name_hz' => '淸溪書院']); // 既有名稱是變體形
        DB::table('BIOG_INST_DATA')->insert(['c_personid' => 1, 'c_inst_code' => 10, 'c_inst_name_code' => 5]);
        $this->actingAs($this->makeUser('si-reverse-guard@example.com'));

        $response = $this->postJson('/api/v2/mutate', $this->updatePayload([
            'name' => '清溪書院', // 參考形：歸一後與既有列「看起來相同」，但會新建 code
        ]));

        $this->assertSame(409, $response->getStatusCode(), '會換掉 name_code ⇒ 仍須被引用護欄擋下');
        $this->assertSame(5, (int) DB::table('SOCIAL_INSTITUTION_CODES')->where('c_inst_code', 10)->value('c_inst_name_code'));
        $this->assertSame(1, DB::table('SOCIAL_INSTITUTION_NAME_CODES')->where('c_inst_name_hz', '淸溪書院')->count());
        $this->assertSame(0, DB::table('SOCIAL_INSTITUTION_NAME_CODES')->where('c_inst_name_hz', '清溪書院')->count(), '不得新建名稱列');
    }

    // ── 機構別名（SOCIAL_INSTITUTION_ALTNAME_DATA）：聚合的 alt_names 清單 ──────────────

    /** 既有別名（直接落庫，模擬既有資料）。 */
    protected function seedAltName(string $name, array $overrides = []): void {
        DB::table('SOCIAL_INSTITUTION_ALTNAME_DATA')->insert(array_merge([
            'c_inst_name_code' => 5, 'c_inst_code' => 10, 'c_inst_altname_type' => 0,
            'c_inst_altname_hz' => $name, 'c_inst_altname_py' => 'kept pinyin',
            'c_source' => 7596, 'c_pages' => null, 'c_notes' => null,
        ], $overrides));
    }

    /** 新增別名：落庫、派生拼音、逐列記 operations＋audit_log，回應帶計數與讀回清單。 */
    #[Test]
    public function testUpdateAddsAltNamesWithOperationsAndAudit(): void {
        $this->actingAs($this->makeUser('si-alt-add@example.com'));

        $res = $this->postJson('/api/v2/mutate', $this->updatePayload([
            'alt_names' => [['name' => '白鹿書院', 'source_id' => 8000, 'pages' => '卷一', 'notes' => '舊稱']],
        ]))->assertOk()
            ->assertJsonPath('result.alt_names_added', 1)
            ->assertJsonPath('result.alt_names_removed', 0)
            ->assertJsonPath('result.alt_names_updated', 0)
            ->assertJsonPath('result.row.alt_names.0.name', '白鹿書院')
            ->assertJsonPath('result.row.alt_names.0.type_code', 0)
            ->assertJsonPath('result.row.alt_names.0.source_id', 8000);

        $this->assertDatabaseHas('SOCIAL_INSTITUTION_ALTNAME_DATA', [
            'c_inst_code' => 10, 'c_inst_name_code' => 5, 'c_inst_altname_type' => 0,
            'c_inst_altname_hz' => '白鹿書院', 'c_source' => 8000, 'c_pages' => '卷一', 'c_notes' => '舊稱',
        ]);
        $op = DB::table('operations')->where('resource', 'SOCIAL_INSTITUTION_ALTNAME_DATA')->first();
        $this->assertNotNull($op, '每一列別名都要有自己的 operations 列');
        $this->assertSame(Operation::TYPE_CREATE, (int) $op->op_type);
        $this->assertSame(
            'c_inst_code=10&c_inst_altname_type=0&c_inst_altname_hz='.urlencode('白鹿書院'),
            $op->resource_id
        );
        $this->assertSame(1, DB::table('audit_log')->where('table_name', 'SOCIAL_INSTITUTION_ALTNAME_DATA')->where('operation', 'INSERT')->count());
        $this->assertNotNull($res->json('result.row.alt_names.0.pinyin'), '沒給拼音時由名稱派生');
        $this->assertSame('bai lu shu yuan', $res->json('result.row.alt_names.0.pinyin'));
    }

    /**
     * **沒帶 alt_names＝不動別名**，刻意不同於聚合其餘欄位的全欄覆寫：既有的整份 payload
     * （React 編輯頁）都沒有這個鍵，若解讀成清空，第一次存檔就會把別名全數刪掉。
     */
    #[Test]
    public function testUpdateWithoutAltNamesLeavesThemUntouched(): void {
        $this->seedAltName('白鹿書院');
        $this->actingAs($this->makeUser('si-alt-keep@example.com'));

        $this->postJson('/api/v2/mutate', $this->updatePayload(['notes' => '只改備註']))
            ->assertOk()
            ->assertJsonPath('result.alt_names_removed', 0)
            ->assertJsonPath('result.row.alt_names.0.name', '白鹿書院');

        $this->assertSame(1, DB::table('SOCIAL_INSTITUTION_ALTNAME_DATA')->where('c_inst_code', 10)->count());
        $this->assertSame(0, DB::table('operations')->where('resource', 'SOCIAL_INSTITUTION_ALTNAME_DATA')->count());
    }

    /** 空陣列＝刪除全部別名，逐列記 DELETE（快照可供復原）。 */
    #[Test]
    public function testUpdateWithEmptyAltNamesRemovesThem(): void {
        $this->seedAltName('白鹿書院');
        $this->seedAltName('白鹿洞', ['c_inst_altname_py' => null]);
        $this->actingAs($this->makeUser('si-alt-clear@example.com'));

        $this->postJson('/api/v2/mutate', $this->updatePayload(['alt_names' => []]))
            ->assertOk()
            ->assertJsonPath('result.alt_names_removed', 2)
            ->assertJsonPath('result.row.alt_names', []);

        $this->assertSame(0, DB::table('SOCIAL_INSTITUTION_ALTNAME_DATA')->count());
        $deletes = DB::table('operations')->where('resource', 'SOCIAL_INSTITUTION_ALTNAME_DATA')->where('op_type', Operation::TYPE_DELETE)->get();
        $this->assertCount(2, $deletes);
        $snapshot = json_decode($deletes->first()->resource_data, true);
        $this->assertSame(10, (int) $snapshot['c_inst_code']);
    }

    /** 同一個別名改非鍵欄＝update（不增不刪）；沒給拼音時保留既有拼音，不重新派生。 */
    #[Test]
    public function testSameAltNameWithNewNotesIsAnUpdateAndKeepsExistingPinyin(): void {
        $this->seedAltName('白鹿書院');
        $this->actingAs($this->makeUser('si-alt-upd@example.com'));

        $this->postJson('/api/v2/mutate', $this->updatePayload([
            'alt_names' => [['name' => '白鹿書院', 'notes' => '新註', 'source_id' => 7596]],
        ]))->assertOk()
            ->assertJsonPath('result.alt_names_added', 0)
            ->assertJsonPath('result.alt_names_removed', 0)
            ->assertJsonPath('result.alt_names_updated', 1);

        $row = DB::table('SOCIAL_INSTITUTION_ALTNAME_DATA')->where('c_inst_code', 10)->first();
        $this->assertSame('新註', $row->c_notes);
        $this->assertSame('kept pinyin', $row->c_inst_altname_py);
        $this->assertSame(1, DB::table('operations')->where('resource', 'SOCIAL_INSTITUTION_ALTNAME_DATA')->where('op_type', Operation::TYPE_UPDATE)->count());
    }

    /** 再送一次相同清單是 no-op：不寫 operations。 */
    #[Test]
    public function testResendingTheSameAltNamesWritesNothing(): void {
        $this->seedAltName('白鹿書院');
        $this->actingAs($this->makeUser('si-alt-noop@example.com'));

        $this->postJson('/api/v2/mutate', $this->updatePayload([
            'alt_names' => [['name' => '白鹿書院', 'source_id' => 7596, 'pinyin' => 'kept pinyin']],
        ]))->assertOk()->assertJsonPath('result.alt_names_updated', 0);

        $this->assertSame(0, DB::table('operations')->where('resource', 'SOCIAL_INSTITUTION_ALTNAME_DATA')->count());
    }

    /** 校驗：缺名、未知類型、不存在的出處、重複、非清單，各回欄位級 422 且不落庫。 */
    #[Test]
    public function testAltNamesValidation(): void {
        $this->actingAs($this->makeUser('si-alt-validate@example.com'));

        $cases = [
            [[['notes' => 'x']], 'alt_names.0.name', 'required'],
            [[['name' => '甲', 'type_code' => 9]], 'alt_names.0.type_code', 'not_found_in_altname_codes'],
            [[['name' => '甲', 'type_code' => 'x']], 'alt_names.0.type_code', 'integer'],
            [[['name' => '甲', 'source_id' => 424242]], 'alt_names.0.source_id', 'not_found_in_text_codes'],
            [[['name' => '甲'], ['name' => '甲']], 'alt_names.1', 'duplicate'],
            [['name' => '甲'], 'alt_names', 'invalid'],
            [[['name' => str_repeat('甲', 256)]], 'alt_names.0.name', 'too_long'],
        ];
        foreach ($cases as [$altNames, $errorKey, $code]) {
            // 錯誤鍵本身含點（alt_names.0.name），不能走 assertJsonPath 的點路徑。
            $errors = $this->postJson('/api/v2/mutate', $this->updatePayload(['alt_names' => $altNames]))
                ->assertStatus(422)
                ->json('errors');
            $this->assertSame([$code], $errors[$errorKey] ?? null, $errorKey);
        }
        $this->assertSame(0, DB::table('SOCIAL_INSTITUTION_ALTNAME_DATA')->count());
    }

    /**
     * 異體字：新別名以參考形落庫並帶 notices；既有列的變體形在同一邏輯鍵下**原字面保留**
     * （D6），送參考形不會刪掉變體形列再新增一列。
     */
    #[Test]
    public function testAltNameVariantsAreReplacedForNewRowsAndMatchExistingVariantRows(): void {
        $this->seedCharVariantMap();
        $this->seedAltName('淸涼寺');
        $this->actingAs($this->makeUser('si-alt-variant@example.com'));

        $res = $this->postJson('/api/v2/mutate', $this->updatePayload([
            'alt_names' => [['name' => '清涼寺'], ['name' => '淸溪寺']],
        ]))->assertOk()
            ->assertJsonPath('result.alt_names_added', 1)
            ->assertJsonPath('result.alt_names_removed', 0);

        $names = DB::table('SOCIAL_INSTITUTION_ALTNAME_DATA')->where('c_inst_code', 10)->orderBy('c_inst_altname_hz')->pluck('c_inst_altname_hz')->all();
        $this->assertContains('淸涼寺', $names, '既有的變體形列原字面保留');
        $this->assertContains('清溪寺', $names, '新列以參考形落庫');
        $this->assertNotContains('清涼寺', $names);
        $this->assertNotEmpty($res->json('notices'));
    }

    /** 同一請求內「歸一後相同」也算重複（落庫後是同一個別名鍵）。 */
    #[Test]
    public function testAltNamesThatNormalizeToTheSameNameAreDuplicates(): void {
        $this->seedCharVariantMap();
        $this->actingAs($this->makeUser('si-alt-variant-dup@example.com'));

        $this->postJson('/api/v2/mutate', $this->updatePayload([
            'alt_names' => [['name' => '淸涼寺'], ['name' => '清涼寺']],
        ]))->assertStatus(422);
        $this->assertSame(['duplicate'], $this->postJson('/api/v2/mutate', $this->updatePayload([
            'alt_names' => [['name' => '淸涼寺'], ['name' => '清涼寺']],
        ]))->json('errors')['alt_names.1'] ?? null);
    }

    /** 改名時別名列的名碼一併改寫（與 ADDR 同）。 */
    #[Test]
    public function testRenameSyncsAltNameRowsToTheNewNameCode(): void {
        $this->seedAltName('白鹿書院');
        $this->actingAs($this->makeUser('si-alt-rename@example.com'));

        $this->postJson('/api/v2/mutate', $this->updatePayload(['name' => '嶽麓書院']))->assertOk();

        $this->assertSame(6, (int) DB::table('SOCIAL_INSTITUTION_ALTNAME_DATA')->where('c_inst_code', 10)->value('c_inst_name_code'));
        // 沒有主鍵、不開放泛用還原：改名對別名列的改寫必須逐列留下前後快照。
        $audit = DB::table('audit_log')->where('table_name', 'SOCIAL_INSTITUTION_ALTNAME_DATA')->where('operation', 'UPDATE')->first();
        $this->assertNotNull($audit);
        $this->assertSame(5, (int) json_decode($audit->old_data, true)['c_inst_name_code']);
        $this->assertSame(6, (int) json_decode($audit->new_data, true)['c_inst_name_code']);
        $this->assertSame(1, DB::table('operations')->where('resource', 'SOCIAL_INSTITUTION_ALTNAME_DATA')->where('op_type', Operation::TYPE_UPDATE)->count());
    }

    /** 刪除機構時別名逐列刪除並記錄（先子後父）。 */
    #[Test]
    public function testDeleteRemovesAltNamesRowByRow(): void {
        $this->seedAltName('白鹿書院');
        $this->seedAltName('白鹿洞');
        $this->actingAs($this->makeUser('si-alt-del@example.com'));

        $this->postJson('/api/v2/delete', [
            'resource' => 'social-institution', 'person_id' => 0,
            'target' => ['pk' => ['c_inst_code' => 10]],
        ])->assertOk()->assertJsonPath('result.alt_names_deleted', 2);

        $this->assertSame(0, DB::table('SOCIAL_INSTITUTION_ALTNAME_DATA')->count());
        $this->assertSame(2, DB::table('audit_log')->where('table_name', 'SOCIAL_INSTITUTION_ALTNAME_DATA')->where('operation', 'DELETE')->count());
        $this->assertSame(2, DB::table('operations')->where('resource', 'SOCIAL_INSTITUTION_ALTNAME_DATA')->where('op_type', Operation::TYPE_DELETE)->count());
    }

    /** create 也收 alt_names。 */
    #[Test]
    public function testCreateAcceptsAltNames(): void {
        $this->actingAs($this->makeUser('si-alt-create@example.com'));

        $res = $this->postJson('/api/v2/create', [
            'resource' => 'social-institution', 'person_id' => 0, 'target' => ['pk' => []],
            'changes' => [
                'name' => '新書院', 'type_code' => 1, 'dynasty_code' => 15, 'addr_id' => 101, 'source_id' => 7596,
                'alt_names' => [['name' => '新院']],
            ],
        ])->assertOk()->assertJsonPath('result.alt_names_added', 1)->assertJsonPath('result.row.alt_names.0.name', '新院');

        $this->assertDatabaseHas('SOCIAL_INSTITUTION_ALTNAME_DATA', [
            'c_inst_code' => $res->json('result.pk.c_inst_code'), 'c_inst_altname_hz' => '新院',
            'c_inst_name_code' => $res->json('result.pk.c_inst_name_code'),
        ]);
    }

    /** 提案：核准前不動，核准後與 direct 同一份對賬。 */
    #[Test]
    public function testAltNamesProposalIsAppliedOnlyOnApproval(): void {
        $this->actingAs($this->makeUser('si-alt-p@example.com', User::ROLE_CROWDSOURCING));
        $res = $this->postJson('/api/v2/mutate', $this->updatePayload([
            'alt_names' => [['name' => '白鹿書院']],
        ]) + ['mode' => 'proposal'])->assertOk();
        $this->assertSame(0, DB::table('SOCIAL_INSTITUTION_ALTNAME_DATA')->count());

        $this->actingAs($this->makeUser('si-alt-p-reviewer@example.com'));
        $this->post(route('operations.proposals.approve', Operation::findOrFail($res->json('result.operation_id'))))->assertRedirect();

        $this->assertDatabaseHas('SOCIAL_INSTITUTION_ALTNAME_DATA', ['c_inst_code' => 10, 'c_inst_altname_hz' => '白鹿書院', 'c_inst_name_code' => 5]);
    }

    /** operations 的別名列能連回機構編輯頁（具名 resource_id 經 SCHEMAS 解析）。 */
    #[Test]
    public function testAltNameOperationLinksBackToTheInstitution(): void {
        $this->actingAs($this->makeUser('si-alt-link@example.com'));
        $this->postJson('/api/v2/mutate', $this->updatePayload(['alt_names' => [['name' => '白鹿書院']]]))->assertOk();

        $resourceId = DB::table('operations')->where('resource', 'SOCIAL_INSTITUTION_ALTNAME_DATA')->value('resource_id');
        $this->assertSame(
            route('app.social-institution.edit', ['id' => 10], false),
            \App\Support\EntityAggregateRegistry::editUrl('SOCIAL_INSTITUTION_ALTNAME_DATA', (string) $resourceId)
        );
    }

    /** 兩形本來就並存時，load() 讀回的清單原樣送回是合法的，且什麼都不寫。 */
    #[Test]
    public function testBothExistingVariantFormsCanBeSentBackUnchanged(): void {
        $this->seedCharVariantMap();
        $this->seedAltName('淸涼寺');
        $this->seedAltName('清涼寺');
        $this->actingAs($this->makeUser('si-alt-two-forms@example.com'));

        $this->postJson('/api/v2/mutate', $this->updatePayload([
            'alt_names' => [
                ['name' => '淸涼寺', 'source_id' => 7596, 'pinyin' => 'kept pinyin'],
                ['name' => '清涼寺', 'source_id' => 7596, 'pinyin' => 'kept pinyin'],
            ],
        ]))->assertOk()
            ->assertJsonPath('result.alt_names_added', 0)
            ->assertJsonPath('result.alt_names_removed', 0)
            ->assertJsonPath('result.alt_names_updated', 0);
        $this->assertSame(0, DB::table('operations')->where('resource', 'SOCIAL_INSTITUTION_ALTNAME_DATA')->count());
    }

    /** 兩形並存時送其中一形：留下**送的那一形**（字面優先），另一形刪除。 */
    #[Test]
    public function testSendingOneOfTwoExistingFormsKeepsTheLiteralThatWasSent(): void {
        $this->seedCharVariantMap();
        $this->seedAltName('淸涼寺');
        $this->seedAltName('清涼寺');
        $this->actingAs($this->makeUser('si-alt-one-form@example.com'));

        $this->postJson('/api/v2/mutate', $this->updatePayload([
            'alt_names' => [['name' => '清涼寺', 'source_id' => 7596, 'pinyin' => 'kept pinyin']],
        ]))->assertOk()->assertJsonPath('result.alt_names_removed', 1);

        $this->assertSame(['清涼寺'], DB::table('SOCIAL_INSTITUTION_ALTNAME_DATA')->pluck('c_inst_altname_hz')->all());
    }

    /** NULL 類型的列：load() 讀回 type_code=null，原樣送回不能變成「刪掉、再以 0 新增」。 */
    #[Test]
    public function testNullTypeRowSurvivesARoundTrip(): void {
        $this->seedAltName('白鹿書院', ['c_inst_altname_type' => null]);
        $this->actingAs($this->makeUser('si-alt-null-type@example.com'));

        $this->postJson('/api/v2/mutate', $this->updatePayload([
            'alt_names' => [['name' => '白鹿書院', 'type_code' => null, 'source_id' => 7596, 'pinyin' => 'kept pinyin']],
        ]))->assertOk()
            ->assertJsonPath('result.alt_names_added', 0)
            ->assertJsonPath('result.alt_names_removed', 0);
        $this->assertNull(DB::table('SOCIAL_INSTITUTION_ALTNAME_DATA')->value('c_inst_altname_type'));
    }

    /**
     * 名稱為 NULL 的既有列 API 無從指稱：對賬不碰（不會出現「記了刪除、列卻還在」的假稽核），
     * 刪除機構時則以 IS NULL 一併刪除，不留孤兒。
     */
    #[Test]
    public function testNullNameRowIsLeftByReconciliationAndRemovedWithTheInstitution(): void {
        $this->seedAltName('placeholder', ['c_inst_altname_hz' => null]);
        $this->actingAs($this->makeUser('si-alt-null-name@example.com'));

        $this->postJson('/api/v2/mutate', $this->updatePayload(['alt_names' => []]))
            ->assertOk()->assertJsonPath('result.alt_names_removed', 0);
        $this->assertSame(1, DB::table('SOCIAL_INSTITUTION_ALTNAME_DATA')->count());
        $this->assertSame(0, DB::table('operations')->where('resource', 'SOCIAL_INSTITUTION_ALTNAME_DATA')->count());

        $this->postJson('/api/v2/delete', [
            'resource' => 'social-institution', 'person_id' => 0, 'target' => ['pk' => ['c_inst_code' => 10]],
        ])->assertOk()->assertJsonPath('result.alt_names_deleted', 1);
        $this->assertSame(0, DB::table('SOCIAL_INSTITUTION_ALTNAME_DATA')->count());
    }

    /**
     * 既有別名有字面完全相同的重複列：where 分不開，對賬一律 409、不動任何列；
     * 刪除機構時兩列一起刪，**每一列各記一筆**（各帶自己的快照）。
     */
    #[Test]
    public function testExactDuplicateRowsBlockReconciliationAndAreEachLoggedOnDelete(): void {
        $this->seedAltName('白鹿書院', ['c_notes' => 'n1']);
        $this->seedAltName('白鹿書院', ['c_notes' => 'n2']);
        $this->actingAs($this->makeUser('si-alt-exact-dup@example.com'));

        $this->postJson('/api/v2/mutate', $this->updatePayload(['alt_names' => [['name' => '白鹿書院']]]))
            ->assertStatus(409)
            ->assertJsonPath('errors.alt_names.0', 'existing_duplicate_rows');
        $this->assertSame(['n1', 'n2'], DB::table('SOCIAL_INSTITUTION_ALTNAME_DATA')->orderBy('c_notes')->pluck('c_notes')->all());

        // 沒帶 alt_names 的一般更新不受影響。
        $this->postJson('/api/v2/mutate', $this->updatePayload(['notes' => '照常']))->assertOk();

        $this->postJson('/api/v2/delete', [
            'resource' => 'social-institution', 'person_id' => 0, 'target' => ['pk' => ['c_inst_code' => 10]],
        ])->assertOk()->assertJsonPath('result.alt_names_deleted', 2);
        $snapshots = DB::table('audit_log')->where('table_name', 'SOCIAL_INSTITUTION_ALTNAME_DATA')->where('operation', 'DELETE')
            ->pluck('old_data')->map(fn ($j) => json_decode($j, true)['c_notes'])->sort()->values()->all();
        $this->assertSame(['n1', 'n2'], $snapshots);
    }

    /** 別名列的 operation 不走泛用還原（無主鍵、general_ci；只經聚合寫入）。 */
    #[Test]
    public function testAltNameOperationsCannotBeRestoredGenerically(): void {
        $this->seedAltName('白鹿書院');
        $this->actingAs($this->makeUser('si-alt-restore@example.com'));
        $this->postJson('/api/v2/mutate', $this->updatePayload(['alt_names' => []]))->assertOk();
        $op = Operation::query()->where('resource', 'SOCIAL_INSTITUTION_ALTNAME_DATA')->firstOrFail();

        $this->actingAs($this->makeUser('si-alt-restore-admin@example.com', User::ROLE_SUPER_ADMIN));
        $this->post(route('operations.restore', $op))->assertRedirect();

        $this->assertSame(0, DB::table('SOCIAL_INSTITUTION_ALTNAME_DATA')->count(), '不得被泛用還原重建');
    }

    /**
     * 經 API 提出、帶 alt_names 的提案，從編輯頁「修改提案」重送時表單沒有別名欄位：
     * 沒帶的 alt_names 要沿用舊提案的值，不能被靜默丟掉（舊提案同時被撤回）。
     * 重送若明示帶了 alt_names，則以重送的為準。
     */
    #[Test]
    public function testResubmitCarriesAltNamesTheFormCannotEdit(): void {
        $this->actingAs($this->makeUser('si-alt-resubmit@example.com', User::ROLE_CROWDSOURCING));
        $old = Operation::findOrFail($this->postJson('/api/v2/mutate', $this->updatePayload([
            'alt_names' => [['name' => '白鹿書院']],
        ]) + ['mode' => 'proposal'])->assertOk()->json('result.operation_id'));

        // 表單重送：沒有 alt_names。
        $res = $this->postJson("/api/v2/proposals/{$old->id}/resubmit", $this->updatePayload(['notes' => '改了備註']))->assertOk();
        $newPayload = json_decode(Operation::findOrFail($res->json('result.operation_id'))->resource_data, true);
        $this->assertSame([['name' => '白鹿書院']], $newPayload['changes']['alt_names']);
        $this->assertSame('改了備註', $newPayload['changes']['notes']);

        // 明示帶了（含空清單）就以重送的為準。
        $second = Operation::findOrFail($res->json('result.operation_id'));
        $res2 = $this->postJson("/api/v2/proposals/{$second->id}/resubmit", $this->updatePayload(['alt_names' => []]))->assertOk();
        $this->assertSame([], json_decode(Operation::findOrFail($res2->json('result.operation_id'))->resource_data, true)['changes']['alt_names']);
    }

    /** 送來的字形被併入既有別名的另一個字形時，回應要說（不是字元替換，走一般通知）。 */
    #[Test]
    public function testMergingIntoAnExistingSpellingIsReported(): void {
        $this->seedCharVariantMap();
        $this->seedAltName('淸涼寺');
        $this->actingAs($this->makeUser('si-alt-merge-notice@example.com'));

        $notices = $this->postJson('/api/v2/mutate', $this->updatePayload([
            'alt_names' => [['name' => '清涼寺', 'source_id' => 7596, 'pinyin' => 'kept pinyin']],
        ]))->assertOk()->json('notices');

        $this->assertNotEmpty($notices);
        $this->assertStringContainsString('沿用既有字形', implode("\n", $notices));
        $this->assertSame(['淸涼寺'], DB::table('SOCIAL_INSTITUTION_ALTNAME_DATA')->pluck('c_inst_altname_hz')->all());
    }

    /**
     * 交易內才發現的資料衝突回 409、整筆回滾，不是裸 500（對 API 呼叫端，寫入回 500 等於
     * 「不確定寫進去沒有」）。重現：下一個 c_inst_code 上已有字面相同的孤兒別名，create 帶 alt_names。
     */
    #[Test]
    public function testAConflictFoundInsideTheTransactionIsA409AndWritesNothing(): void {
        $this->seedAltName('孤兒', ['c_inst_code' => 11]);
        $this->seedAltName('孤兒', ['c_inst_code' => 11]);
        $this->actingAs($this->makeUser('si-alt-409@example.com'));

        $this->postJson('/api/v2/create', [
            'resource' => 'social-institution', 'person_id' => 0, 'target' => ['pk' => []],
            'changes' => [
                'name' => '新書院', 'type_code' => 1, 'dynasty_code' => 15, 'addr_id' => 101, 'source_id' => 7596,
                'alt_names' => [['name' => '新院']],
            ],
        ])->assertStatus(409)->assertJsonPath('errors.alt_names.0', 'existing_duplicate_rows');

        $this->assertSame(0, DB::table('SOCIAL_INSTITUTION_CODES')->where('c_inst_code', 11)->count(), '整筆回滾');
        $this->assertSame(0, DB::table('SOCIAL_INSTITUTION_NAME_CODES')->where('c_inst_name_hz', '新書院')->count());
        $this->assertSame(0, DB::table('operations')->count());
    }

    /** 別名表的機構碼欄是 SMALLINT：超出上限時整筆擋下（409），不讓 MariaDB 靜默截斷、掛錯機構。 */
    #[Test]
    public function testAliasesAreRefusedWhenTheInstitutionCodeExceedsTheAliasColumn(): void {
        DB::table('SOCIAL_INSTITUTION_CODES')->where('c_inst_code', 10)->update(['c_inst_code' => 40000]);
        DB::table('SOCIAL_INSTITUTION_ADDR')->where('c_inst_code', 10)->update(['c_inst_code' => 40000]);
        $this->actingAs($this->makeUser('si-alt-range@example.com'));

        $payload = $this->updatePayload(['alt_names' => [['name' => '白鹿書院']]]);
        $payload['target']['pk']['c_inst_code'] = 40000;
        $this->postJson('/api/v2/mutate', $payload)
            ->assertStatus(409)
            ->assertJsonPath('errors.alt_names.0', 'code_out_of_range');
        $this->assertSame(0, DB::table('SOCIAL_INSTITUTION_ALTNAME_DATA')->count());
    }
}
