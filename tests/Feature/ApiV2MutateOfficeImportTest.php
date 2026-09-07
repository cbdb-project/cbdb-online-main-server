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
 * 「新增官職」mutation（resource=office → OFFICE_CODES + OFFICE_CODE_TYPE_REL）回歸測試。
 *
 * 驗證 OfficeImportHandler / OfficeImportService 的複合存儲過程：兩表原子寫入、自動 office_id、
 * 拼音/朝代碼派生、operations + audit_log、以及來源/類型/朝代校驗。
 */
class ApiV2MutateOfficeImportTest extends TestCase {
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
        // 生產 OFFICE_CODES 無 c_created_by/date 審計欄，schema 須如實反映（服務直接 plain insert）。
        Schema::create('OFFICE_CODES', function (Blueprint $table) {
            $table->integer('c_office_id')->primary();
            $table->integer('c_dy')->nullable();
            $table->string('c_office_pinyin')->nullable();
            $table->string('c_office_chn')->nullable();
            $table->string('c_office_pinyin_alt')->nullable();
            $table->string('c_office_chn_alt')->nullable();
            $table->string('c_office_trans')->nullable();
            $table->string('c_office_trans_alt')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
        });
        Schema::create('OFFICE_CODE_TYPE_REL', function (Blueprint $table) {
            $table->integer('c_office_id');
            $table->string('c_office_tree_id');
            $table->primary(['c_office_id', 'c_office_tree_id']);
        });
        Schema::create('OFFICE_TYPE_TREE', function (Blueprint $table) {
            $table->string('c_office_type_node_id')->primary();
            // 表單頁的 picker 標籤查此欄（修改提案預填測試會渲染 /app/office 表單頁）。
            $table->string('c_office_type_desc_chn')->nullable();
        });
        Schema::create('DYNASTIES', function (Blueprint $table) {
            $table->integer('c_dy')->primary();
            $table->string('c_dynasty_chn')->nullable();
        });
        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
        });
        Schema::create('pinyin', function (Blueprint $table) {
            $table->increments('id');
            $table->string('c_chn')->nullable();
            $table->string('c_pinyin')->nullable();
            $table->integer('c_lastname')->default(0);
        });
        // 刪除護欄：referenceCount() 查此表是否仍有人物任官引用該官職。
        Schema::create('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_office_id');
            $table->integer('c_posting_id');
        });

        DB::table('DYNASTIES')->insert(['c_dy' => 15, 'c_dynasty_chn' => '宋']);
        DB::table('OFFICE_TYPE_TREE')->insert([['c_office_type_node_id' => 'x01'], ['c_office_type_node_id' => 'x02']]);
        DB::table('TEXT_CODES')->insert([['c_textid' => 7596], ['c_textid' => 8000]]);
    }

    protected function tearDown(): void {
        // char_variant_map 的清理放在這裡而不是各測試方法尾：斷言失敗時方法尾不會執行。
        Schema::dropIfExists('char_variant_map');
        foreach (['POSTED_TO_OFFICE_DATA', 'pinyin', 'TEXT_CODES', 'DYNASTIES', 'OFFICE_TYPE_TREE', 'OFFICE_CODE_TYPE_REL', 'OFFICE_CODES', 'audit_log', 'operations', 'users'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'of@example.com'): User {
        return User::forceCreate([
            'name' => 'Office Tester',
            'email' => $email,
            'confirmation_token' => 'tok',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function payload(array $overrides = []): array {
        return array_merge([
            'resource' => 'office',
            'person_id' => 0,
            'target' => ['pk' => []],
            'changes' => [
                'name' => '知府',
                'translation' => 'Prefect',
                'dynasty_code' => 15,
                'type_id' => 'x01',
                'source_id' => 7596,
            ],
        ], $overrides);
    }

    #[Test]
    public function testCreateWritesBothTablesAtomically(): void {
        DB::table('OFFICE_CODES')->insert(['c_office_id' => 100, 'c_office_chn' => '既有']);
        $this->actingAs($this->makeUser(email: 'of-create@example.com'));

        $res = $this->postJson('/api/v2/create', $this->payload());

        $res->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'office',
            'operation' => 'create',
            'result' => ['pk' => ['c_office_id' => 101]],
        ]);
        $this->assertDatabaseHas('OFFICE_CODES', ['c_office_id' => 101, 'c_office_chn' => '知府', 'c_dy' => 15, 'c_source' => 7596]);
        $this->assertDatabaseHas('OFFICE_CODE_TYPE_REL', ['c_office_id' => 101, 'c_office_tree_id' => 'x01']);
        // operations + audit：OFFICE_CODES 與 OFFICE_CODE_TYPE_REL 各一
        $this->assertSame(1, DB::table('operations')->where('resource', 'OFFICE_CODES')->count());
        $this->assertSame(1, DB::table('operations')->where('resource', 'OFFICE_CODE_TYPE_REL')->count());
        $this->assertSame(2, DB::table('audit_log')->count());
    }

    #[Test]
    public function testMissingSourceReturns422AndWritesNothing(): void {
        $this->actingAs($this->makeUser(email: 'of-src@example.com'));

        $p = $this->payload();
        $p['changes']['source_id'] = 999999; // 不在 TEXT_CODES
        $this->postJson('/api/v2/create', $p)->assertStatus(422);
        $this->assertSame(0, DB::table('OFFICE_CODES')->count());
    }

    #[Test]
    public function testMissingOfficeTypeReturns422(): void {
        $this->actingAs($this->makeUser(email: 'of-type@example.com'));

        $p = $this->payload();
        $p['changes']['type_id'] = 'nope'; // 不在 OFFICE_TYPE_TREE
        $this->postJson('/api/v2/create', $p)->assertStatus(422);
        $this->assertSame(0, DB::table('OFFICE_CODES')->count());
    }

    #[Test]
    public function testNonScalarTypeIdReturns422(): void {
        $this->actingAs($this->makeUser(email: 'of-arr@example.com'));

        $p = $this->payload();
        $p['changes']['type_id'] = ['x01']; // 非純量（JSON 陣列）須回 422，不可流入 whereIn/insert 造成 500
        $this->postJson('/api/v2/create', $p)->assertStatus(422);
        $this->assertSame(0, DB::table('OFFICE_CODES')->count());
    }

    #[Test]
    public function testDynastyLabelResolvesToCode(): void {
        $this->actingAs($this->makeUser(email: 'of-dyn@example.com'));

        $p = $this->payload();
        unset($p['changes']['dynasty_code']);
        $p['changes']['dynasty_label'] = '宋';
        $this->postJson('/api/v2/create', $p)->assertOk();
        $this->assertDatabaseHas('OFFICE_CODES', ['c_office_chn' => '知府', 'c_dy' => 15]);
    }

    #[Test]
    public function testUpdateOverwritesFieldsAndReconcilesTypes(): void {
        $this->actingAs($this->makeUser(email: 'of-upd@example.com'));
        // 先建一個 type=x01 的官職。
        $this->postJson('/api/v2/create', $this->payload())->assertOk();
        $officeId = (int) DB::table('OFFICE_CODES')->where('c_office_chn', '知府')->value('c_office_id');

        // 更新：改名/來源，類型集合改為 [x01, x02]（x01 保留、x02 新增）。
        $res = $this->postJson('/api/v2/mutate', [
            'resource' => 'office',
            'operation' => 'update',
            'person_id' => 0,
            'target' => ['pk' => ['c_office_id' => $officeId]],
            'changes' => [
                'name' => '知州',
                'translation' => 'Prefect II',
                'dynasty_code' => 15,
                'type_ids' => ['x01', 'x02'],
                'source_id' => 8000,
            ],
        ]);
        $res->assertOk()->assertJson(['operation' => 'update', 'result' => ['status' => 'updated']]);
        $this->assertDatabaseHas('OFFICE_CODES', ['c_office_id' => $officeId, 'c_office_chn' => '知州', 'c_source' => 8000]);
        $this->assertSame(2, DB::table('OFFICE_CODE_TYPE_REL')->where('c_office_id', $officeId)->count());

        // 再更新：類型集合縮為 [x02]（x01 應被對賬刪除，非整批重寫多刪）。
        $this->postJson('/api/v2/mutate', [
            'resource' => 'office',
            'operation' => 'update',
            'person_id' => 0,
            'target' => ['pk' => ['c_office_id' => $officeId]],
            'changes' => [
                'name' => '知州',
                'dynasty_code' => 15,
                'type_ids' => ['x02'],
                'source_id' => 8000,
            ],
        ])->assertOk();
        $this->assertDatabaseMissing('OFFICE_CODE_TYPE_REL', ['c_office_id' => $officeId, 'c_office_tree_id' => 'x01']);
        $this->assertDatabaseHas('OFFICE_CODE_TYPE_REL', ['c_office_id' => $officeId, 'c_office_tree_id' => 'x02']);
    }

    #[Test]
    public function testFullFieldsRoundTripAndPinyinAutoWhenBlank(): void {
        $this->actingAs($this->makeUser(email: 'of-full@example.com'));

        // create：帶齊選填欄 + 手動拼音（手動值須逐字採用、不派生）。
        $p = $this->payload();
        $p['changes'] = array_merge($p['changes'], [
            'name_alt' => '知府別名',
            'translation_alt' => 'Prefect alt',
            'pinyin' => 'zhi fu manual',
            'pinyin_alt' => 'zhi fu bie ming',
            'pages' => 'p.12',
            'notes' => '測試備註',
        ]);
        $this->postJson('/api/v2/create', $p)->assertOk();
        $this->assertDatabaseHas('OFFICE_CODES', [
            'c_office_chn' => '知府',
            'c_office_chn_alt' => '知府別名',
            'c_office_pinyin' => 'zhi fu manual',
            'c_office_pinyin_alt' => 'zhi fu bie ming',
            'c_office_trans_alt' => 'Prefect alt',
            'c_pages' => 'p.12',
            'c_notes' => '測試備註',
        ]);
        $officeId = (int) DB::table('OFFICE_CODES')->where('c_office_chn', '知府')->value('c_office_id');

        // update：留空 pinyin → 依名稱自動派生；不帶 name_alt → 折成 null；改 notes。
        $this->postJson('/api/v2/mutate', [
            'resource' => 'office',
            'operation' => 'update',
            'person_id' => 0,
            'target' => ['pk' => ['c_office_id' => $officeId]],
            'changes' => [
                'name' => '知州',
                'dynasty_code' => 15,
                'type_ids' => ['x01'],
                'source_id' => 7596,
                'pinyin' => '',
                'notes' => '改後備註',
            ],
        ])->assertOk();

        $row = DB::table('OFFICE_CODES')->where('c_office_id', $officeId)->first();
        $this->assertSame('改後備註', $row->c_notes);
        $this->assertNotSame('', (string) $row->c_office_pinyin); // 留空後派生出非空拼音
        $this->assertNull($row->c_office_chn_alt); // update 未帶 name_alt → null
        $this->assertNull($row->c_pages); // update 未帶 pages → null
    }

    #[Test]
    public function testUpdateMissingOfficeReturns404(): void {
        $this->actingAs($this->makeUser(email: 'of-u404@example.com'));
        $this->postJson('/api/v2/mutate', [
            'resource' => 'office',
            'operation' => 'update',
            'person_id' => 0,
            'target' => ['pk' => ['c_office_id' => 999999]],
            'changes' => ['name' => '知府', 'dynasty_code' => 15, 'type_ids' => ['x01'], 'source_id' => 7596],
        ])->assertStatus(404);
    }

    #[Test]
    public function testDeleteRemovesAggregateWhenUnreferenced(): void {
        $this->actingAs($this->makeUser(email: 'of-del@example.com'));
        $this->postJson('/api/v2/create', $this->payload())->assertOk();
        $officeId = (int) DB::table('OFFICE_CODES')->where('c_office_chn', '知府')->value('c_office_id');

        $this->postJson('/api/v2/delete', [
            'resource' => 'office',
            'person_id' => 0,
            'target' => ['pk' => ['c_office_id' => $officeId]],
        ])->assertOk()->assertJson(['operation' => 'delete', 'result' => ['status' => 'deleted']]);

        $this->assertDatabaseMissing('OFFICE_CODES', ['c_office_id' => $officeId]);
        $this->assertSame(0, DB::table('OFFICE_CODE_TYPE_REL')->where('c_office_id', $officeId)->count());
    }

    #[Test]
    public function testDeleteBlockedWhenReferencedByPostings(): void {
        $this->actingAs($this->makeUser(email: 'of-delref@example.com'));
        $this->postJson('/api/v2/create', $this->payload())->assertOk();
        $officeId = (int) DB::table('OFFICE_CODES')->where('c_office_chn', '知府')->value('c_office_id');
        DB::table('POSTED_TO_OFFICE_DATA')->insert(['c_personid' => 7, 'c_office_id' => $officeId, 'c_posting_id' => 1]);

        $this->postJson('/api/v2/delete', [
            'resource' => 'office',
            'person_id' => 0,
            'target' => ['pk' => ['c_office_id' => $officeId]],
        ])->assertStatus(409);

        // 未刪：仍在。
        $this->assertDatabaseHas('OFFICE_CODES', ['c_office_id' => $officeId]);
    }

    // ── 實體級提案（§4.5）：mode=proposal 存意圖、核准時重放聚合 handler ──────────────

    /** create 提案：存一筆 op_type=8 的 office operation，且**不落庫**（OFFICE_CODES 無新列）。 */
    public function testProposalCreateStoresIntentWithoutWriting(): void {
        $this->actingAs($this->makeUser(email: 'of-p-create@example.com'));

        $res = $this->postJson('/api/v2/create', $this->payload(['mode' => 'proposal']));
        $res->assertOk()->assertJson(['ok' => true, 'resource' => 'office', 'mode' => 'proposal', 'operation' => 'create']);

        $this->assertSame(0, DB::table('OFFICE_CODES')->where('c_office_chn', '知府')->count());
        $this->assertSame(1, DB::table('operations')
            ->where('resource', 'office')->where('op_type', Operation::TYPE_PROPOSAL_CREATE)->count());
    }

    /** 核准 create 提案：重放聚合 create，OFFICE_CODES + TYPE_REL 同時落庫、提案標記 approved。 */
    public function testApproveCreateProposalAppliesAggregate(): void {
        $this->actingAs($this->makeUser(email: 'of-p-createok@example.com'));
        $this->postJson('/api/v2/create', $this->payload(['mode' => 'proposal']))->assertOk();
        $operation = Operation::where('resource', 'office')->firstOrFail();

        $this->post(route('operations.proposals.approve', $operation))->assertRedirect();

        $officeId = (int) DB::table('OFFICE_CODES')->where('c_office_chn', '知府')->value('c_office_id');
        $this->assertGreaterThan(0, $officeId);
        $this->assertDatabaseHas('OFFICE_CODES', ['c_office_id' => $officeId, 'c_dy' => 15, 'c_source' => 7596]);
        $this->assertDatabaseHas('OFFICE_CODE_TYPE_REL', ['c_office_id' => $officeId, 'c_office_tree_id' => 'x01']);

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status']);
        // handler 落庫的 direct operation id 記回提案（operations 列表據此認領 audit、「比較」才可用）；
        // 配發的識別鍵也記回，列表的「資源」連結據此指向新建的官職。
        $appliedId = DB::table('operations')->where('resource', 'OFFICE_CODES')->where('op_type', Operation::TYPE_CREATE)->value('id');
        $this->assertNotNull($appliedId);
        $this->assertSame((string) $appliedId, $payload['__applied_operation_id']);
        $this->assertSame($officeId, (int) $payload['c_office_id']);
    }

    /** update 提案：先直建一官職，改名＋改類型提案，核准前不動，核准後聚合套用。 */
    public function testProposalUpdateThenApprove(): void {
        $this->actingAs($this->makeUser(email: 'of-p-upd@example.com'));
        $this->postJson('/api/v2/create', $this->payload())->assertOk();
        $officeId = (int) DB::table('OFFICE_CODES')->where('c_office_chn', '知府')->value('c_office_id');

        $this->postJson('/api/v2/mutate', [
            'resource' => 'office', 'mode' => 'proposal', 'operation' => 'update', 'person_id' => 0,
            'target' => ['pk' => ['c_office_id' => $officeId]],
            'changes' => ['name' => '知州', 'dynasty_code' => 15, 'type_id' => 'x02', 'source_id' => 7596],
        ])->assertOk()->assertJson(['mode' => 'proposal', 'operation' => 'update']);

        // 核准前：名字未變。
        $this->assertDatabaseHas('OFFICE_CODES', ['c_office_id' => $officeId, 'c_office_chn' => '知府']);

        $operation = Operation::where('op_type', Operation::TYPE_PROPOSAL_UPDATE)->firstOrFail();
        $this->post(route('operations.proposals.approve', $operation))->assertRedirect();

        $this->assertDatabaseHas('OFFICE_CODES', ['c_office_id' => $officeId, 'c_office_chn' => '知州']);
        $this->assertDatabaseHas('OFFICE_CODE_TYPE_REL', ['c_office_id' => $officeId, 'c_office_tree_id' => 'x02']);
    }

    /** delete 提案的引用護欄在提交端即擋（guardWrite 與 direct 一致）：被任官引用 → 409、不存提案。 */
    public function testProposalDeleteBlockedWhenReferenced(): void {
        $this->actingAs($this->makeUser(email: 'of-p-delref@example.com'));
        $this->postJson('/api/v2/create', $this->payload())->assertOk();
        $officeId = (int) DB::table('OFFICE_CODES')->where('c_office_chn', '知府')->value('c_office_id');
        DB::table('POSTED_TO_OFFICE_DATA')->insert(['c_personid' => 7, 'c_office_id' => $officeId, 'c_posting_id' => 1]);

        $this->postJson('/api/v2/delete', [
            'resource' => 'office', 'mode' => 'proposal', 'person_id' => 0,
            'target' => ['pk' => ['c_office_id' => $officeId]],
        ])->assertStatus(409);

        $this->assertSame(0, DB::table('operations')->where('op_type', Operation::TYPE_PROPOSAL_DELETE)->count());
    }

    /** delete 提案（未被引用）：核准後聚合刪除官職與其類型關聯。 */
    public function testProposalDeleteThenApprove(): void {
        $this->actingAs($this->makeUser(email: 'of-p-del@example.com'));
        $this->postJson('/api/v2/create', $this->payload())->assertOk();
        $officeId = (int) DB::table('OFFICE_CODES')->where('c_office_chn', '知府')->value('c_office_id');

        $this->postJson('/api/v2/delete', [
            'resource' => 'office', 'mode' => 'proposal', 'person_id' => 0,
            'target' => ['pk' => ['c_office_id' => $officeId]],
        ])->assertOk()->assertJson(['mode' => 'proposal', 'operation' => 'delete']);

        $this->assertDatabaseHas('OFFICE_CODES', ['c_office_id' => $officeId]);

        $operation = Operation::where('op_type', Operation::TYPE_PROPOSAL_DELETE)->firstOrFail();
        $this->post(route('operations.proposals.approve', $operation))->assertRedirect();

        $this->assertSame(0, DB::table('OFFICE_CODES')->where('c_office_id', $officeId)->count());
        $this->assertSame(0, DB::table('OFFICE_CODE_TYPE_REL')->where('c_office_id', $officeId)->count());
    }

    /** 眾包用戶不可 direct 寫實體，但可提案（authorizeProposal 只要 active）。 */
    public function testCrowdsourcingUserCanProposeButNotDirect(): void {
        $this->actingAs($this->makeUser(role: User::ROLE_CROWDSOURCING, email: 'of-p-crowd@example.com'));

        $this->postJson('/api/v2/create', $this->payload())->assertStatus(403);
        $this->postJson('/api/v2/create', $this->payload(['mode' => 'proposal']))->assertOk();
        $this->assertSame(1, DB::table('operations')->where('op_type', Operation::TYPE_PROPOSAL_CREATE)->count());
    }

    // ── 修改提案（resubmit）與撤回：與人物子資源同一套契約，表單預填的是聚合意圖 ──

    /**
     * 新增提案 → 新增頁以 ?proposal 預填 → 以 resubmit 端點重發：舊提案 cancelled＋superseded_by、
     * 新提案 pending＋resubmit_of、仍是聚合意圖（__entity_aggregate）且未落庫。
     */
    #[Test]
    public function testCreateProposalCanBePrefilledOnTheCreatePageAndResubmitted(): void {
        $proposer = $this->makeUser(role: User::ROLE_CROWDSOURCING, email: 'of-resubmit@example.com');
        $this->actingAs($proposer);
        $old = Operation::find($this->postJson('/api/v2/create', $this->payload([
            'mode' => 'proposal', 'meta' => ['comment' => '原始說明'],
        ]))->json('result.operation_id'));

        $this->get("/app/office/create?proposal={$old->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Office/Create')
                ->where('can_propose', true)
                ->where('can_edit', false)
                ->where('proposal_overlay.name', '知府')
                ->where('proposal_overlay.dynasty_code', 15)
                ->where('initial_labels.dynasty', '宋')
                ->where('resubmit.resubmit_proposal_id', $old->id)
                ->where('resubmit.initial_comment', '原始說明')
                ->where('resubmit.resubmit_endpoint', "/api/v2/proposals/{$old->id}/resubmit"));

        // resubmit 端點 create／update 共用、缺 operation 時當 update：新增提案重發要標明 create。
        $p = $this->payload(['operation' => 'create', 'meta' => ['comment' => '改名']]);
        $p['changes']['name'] = '知州';
        $res = $this->postJson("/api/v2/proposals/{$old->id}/resubmit", $p)->assertOk();
        $newId = (int) $res->json('result.operation_id');

        $oldPayload = json_decode($old->fresh()->resource_data, true);
        $this->assertSame('cancelled', $oldPayload['__review_status']);
        $this->assertSame($newId, $oldPayload['__proposal_meta']['superseded_by']);

        $newPayload = json_decode(Operation::findOrFail($newId)->resource_data, true);
        $this->assertTrue($newPayload['__entity_aggregate']);
        $this->assertSame('知州', $newPayload['changes']['name']);
        $this->assertSame('改名', $newPayload['__proposal_meta']['comment']);
        $this->assertSame($old->id, $newPayload['__proposal_meta']['resubmit_of']);
        $this->assertSame(0, DB::table('OFFICE_CODES')->count(), 'resubmit 只是重發提案，不得落庫');
    }

    /** 編輯頁的 ?proposal 預填必須與本頁對得上：同實體、同操作、同識別鍵；身分與狀態同 resubmit 規則。 */
    #[Test]
    public function testEditPagePrefillRequiresAMatchingProposalAndAnEligibleViewer(): void {
        $proposer = $this->makeUser(role: User::ROLE_CROWDSOURCING, email: 'of-prefill@example.com');
        $this->actingAs($this->makeUser(email: 'of-prefill-writer@example.com'));
        $this->postJson('/api/v2/create', $this->payload())->assertOk();
        $this->postJson('/api/v2/create', $this->payload(['changes' => ['name' => '另一官', 'dynasty_code' => 15, 'type_id' => 'x01', 'source_id' => 7596]]))->assertOk();
        [$officeId, $otherId] = DB::table('OFFICE_CODES')->orderBy('c_office_id')->pluck('c_office_id')->map(fn ($v) => (int) $v)->all();

        $this->actingAs($proposer);
        $proposal = Operation::find($this->postJson('/api/v2/mutate', [
            'resource' => 'office', 'mode' => 'proposal', 'operation' => 'update', 'person_id' => 0,
            'target' => ['pk' => ['c_office_id' => $officeId]],
            'changes' => ['name' => '知州', 'dynasty_code' => 15, 'type_id' => 'x02', 'source_id' => 7596],
        ])->json('result.operation_id'));

        // 對得上：以提案值覆蓋（標籤也照提案值查——type x02 不在原聚合裡）。
        $this->get("/app/office/{$officeId}/edit?proposal={$proposal->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Office/Edit')
                ->where('office.name', '知府')
                ->where('proposal_overlay.name', '知州')
                ->where('resubmit.resubmit_proposal_id', $proposal->id));

        // 對不上一律 404：新增頁收到修改提案、另一個官職的編輯頁收到這筆提案。
        $this->get("/app/office/create?proposal={$proposal->id}")->assertNotFound();
        $this->get("/app/office/{$otherId}/edit?proposal={$proposal->id}")->assertNotFound();

        // 非提案人的眾包帳號 403；審核人可以。
        $this->actingAs($this->makeUser(role: User::ROLE_CROWDSOURCING, email: 'of-prefill-other@example.com'));
        $this->get("/app/office/{$officeId}/edit?proposal={$proposal->id}")->assertForbidden();
        $this->actingAs($this->makeUser(email: 'of-prefill-reviewer@example.com'));
        $this->get("/app/office/{$officeId}/edit?proposal={$proposal->id}")->assertOk();

        // 已審結：409。
        $this->post(route('operations.proposals.approve', $proposal))->assertRedirect();
        $this->actingAs($proposer);
        $this->get("/app/office/{$officeId}/edit?proposal={$proposal->id}")->assertStatus(409);
    }

    /**
     * 交易內的狀態重驗（核准／退回進交易後鎖列重讀）撞到已撤回的提案時要回明確的 409，
     * 不能是會被 approve() 通用 catch 吞成 redirect、或在 reject() 落成 500 的一般例外。
     * 競爭本身（交易外看到 pending、進交易後已被改掉）在單執行緒測試裡做不出來，
     * 這裡直接對守衛本體驗證回應型別。
     */
    #[Test]
    public function testInTransactionStatusRecheckRespondsWithConflict(): void {
        $proposer = $this->makeUser(role: User::ROLE_CROWDSOURCING, email: 'of-recheck@example.com');
        $this->actingAs($proposer);
        $proposal = Operation::find($this->postJson('/api/v2/create', $this->payload(['mode' => 'proposal']))->json('result.operation_id'));
        $this->delete(route('operations.proposals.cancel', $proposal))->assertRedirect();

        $guard = new \ReflectionMethod(\App\Http\Controllers\OperationsProposalController::class, 'lockPendingProposal');

        try {
            $guard->invoke(app(\App\Http\Controllers\OperationsProposalController::class), $proposal->fresh());
            $this->fail('已撤回的提案通過了交易內重驗');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    /** 撤回走與資源無關的端點：提案人本人可撤、他人 403、已撤回不可再撤；payload 標記與 codes 撤回一致。 */
    #[Test]
    public function testProposerCanCancelAnEntityProposalWithoutATableName(): void {
        $proposer = $this->makeUser(role: User::ROLE_CROWDSOURCING, email: 'of-cancel@example.com');
        $this->actingAs($proposer);
        $proposal = Operation::find($this->postJson('/api/v2/create', $this->payload(['mode' => 'proposal']))->json('result.operation_id'));

        $this->actingAs($this->makeUser(email: 'of-cancel-other@example.com'));
        $this->delete(route('operations.proposals.cancel', $proposal))->assertForbidden();

        $this->actingAs($proposer);
        $this->delete(route('operations.proposals.cancel', $proposal), ['reason' => '送錯了'])->assertRedirect();

        $payload = json_decode($proposal->fresh()->resource_data, true);
        $this->assertSame('cancelled', $payload['__review_status']);
        $this->assertSame('送錯了', $payload['__proposal_meta']['cancel_reason']);
        $this->assertSame($proposer->id, $payload['__proposal_meta']['cancelled_by_id']);
        $this->assertTrue($payload['__entity_aggregate'], '撤回只改狀態，聚合意圖原樣保留');

        $this->delete(route('operations.proposals.cancel', $proposal))->assertForbidden();
        // 撤回後核准端不再認它是待審提案（409），資料表不得被寫入。
        $this->actingAs($this->makeUser(email: 'of-cancel-reviewer@example.com'));
        $this->post(route('operations.proposals.approve', $proposal))->assertStatus(409);
        $this->assertSame(0, DB::table('OFFICE_CODES')->count());
    }
    // ── 異體字落地替換（plan S4；review 補的 v2／React 覆蓋）──────

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
     * v2／React 路徑：職名、別名、備註、頁碼四欄都要落地替換，**回應要回落庫值並帶
     * notices**。
     *
     * 回 `$input['name']` 是這一步 review 抓到的缺陷：DB 寫參考形、回應回變體形，
     * React 編輯器儲存後畫面與資料庫不一致，使用者也不知道字被改過（批次匯入使用者
     * 反而看得到提示）。
     */
    #[Test]
    public function testOfficeCreateReplacesVariantsInAllTextColumnsAndReturnsStoredValues(): void {
        $this->seedCharVariantMap();
        $this->actingAs($this->makeUser(email: 'office-variant@example.com'));

        $response = $this->postJson('/api/v2/create', $this->payload([
            'changes' => [
                'name' => '淸吏司',
                'name_alt' => '淸吏司別名',
                'notes' => '淸代備註',
                'pages' => '淸頁',
                'dynasty_code' => 15,
                'type_id' => 'x01',
                'source_id' => 7596,
            ],
        ]))->assertOk();

        $row = DB::table('OFFICE_CODES')->first();
        $this->assertSame('清吏司', $row->c_office_chn);
        $this->assertSame('清吏司別名', $row->c_office_chn_alt);
        $this->assertSame('清代備註', $row->c_notes);
        $this->assertSame('清頁', $row->c_pages);
        $this->assertSame('qing li si', $row->c_office_pinyin, '拼音須由參考形派生');

        $this->assertSame('清吏司', $response->json('result.row.c_office_chn'), '回應必須回落庫值');
        $this->assertNotEmpty($response->json('notices'), '回應必須帶異體字通知');

    }

    /**
     * 提案核准重放時，異體字替換與標籤歸一同樣生效（核准走
     * `approveEntityAggregateProposal` → 以 direct 重放同一個 handler ⇒ 同 validate、
     * 同 service、同替換）。提案存的是**原始 changes**，替換發生在核准當下（§4.5 存意圖）。
     */
    #[Test]
    public function testApprovingProposalAppliesVariantReplacementAndLabelNormalization(): void {
        $this->seedCharVariantMap();
        // 代碼表寫變體形，提案的標籤送參考形 ⇒ 需要 map 鍵歸一才對得上。
        DB::table('DYNASTIES')->insert(['c_dy' => 40, 'c_dynasty_chn' => '淸']);
        $this->actingAs($this->makeUser(email: 'of-p-variant@example.com'));

        $p = $this->payload(['mode' => 'proposal']);
        $p['changes']['name'] = '淸吏司';
        unset($p['changes']['dynasty_code']);
        $p['changes']['dynasty_label'] = '清';
        $this->postJson('/api/v2/create', $p)->assertOk();

        // 提案存的是原始輸入（尚未替換）。
        $operation = Operation::where('resource', 'office')->firstOrFail();
        $stored = json_decode($operation->resource_data, true);
        $this->assertSame('淸吏司', $stored['changes']['name'] ?? ($stored['name'] ?? null));

        $this->post(route('operations.proposals.approve', $operation))->assertRedirect();

        $row = DB::table('OFFICE_CODES')->where('c_office_chn', '清吏司')->first();
        $this->assertNotNull($row, '核准落庫時職名應為參考形');
        $this->assertSame(40, (int) $row->c_dy, '朝代標籤歸一後應對上變體形的代碼表列');
        $this->assertSame('qing li si', $row->c_office_pinyin);

    }
}
