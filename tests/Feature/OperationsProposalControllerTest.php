<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use App\Services\CharVariantMapService;
use App\Support\VariantReplaceScope;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OperationsProposalControllerTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('confirmation_token')->nullable();
            $table->boolean('is_active')->default(0);
            $table->boolean('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::dropIfExists('operations');
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

        Schema::dropIfExists('audit_log');
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

        Schema::dropIfExists('TEST_CODES');
        Schema::create('TEST_CODES', function (Blueprint $table) {
            $table->string('code_id');
            $table->string('code_sub');
            $table->string('description')->nullable();
        });

        Schema::dropIfExists('TEST_SINGLE');
        Schema::create('TEST_SINGLE', function (Blueprint $table) {
            $table->integer('id');
            $table->string('description')->nullable();
        });

        Schema::dropIfExists('BIOG_SOURCE_DATA');
        Schema::create('BIOG_SOURCE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_textid');
            $table->string('c_pages');
            $table->text('c_notes')->nullable();
            $table->integer('c_main_source')->default(0);
            $table->integer('c_self_bio')->default(0);
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });

        // BIOG_SOURCE_DATA 提案核准改走 SourceMutationHandler，會校驗 c_textid 存在於 TEXT_CODES
        // （對齊 direct sources API 的引用完整性）。seed 哨兵 0 與測試用合法 textid。
        Schema::dropIfExists('TEXT_CODES');
        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
            $table->string('c_title')->nullable();
        });
        DB::table('TEXT_CODES')->insert([
            ['c_textid' => 0, 'c_title' => '未詳'],
            ['c_textid' => 500, 'c_title' => 'Book A'],
            ['c_textid' => 700, 'c_title' => 'Book B'],
        ]);

        Schema::dropIfExists('POSTED_TO_OFFICE_DATA');
        Schema::create('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_office_id');
            $table->integer('c_posting_id');
            $table->text('c_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });

        Schema::dropIfExists('POSTED_TO_ADDR_DATA');
        Schema::create('POSTED_TO_ADDR_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_posting_id');
            $table->integer('c_office_id');
            $table->integer('c_addr_id')->default(0);
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });

        Schema::dropIfExists('POSTING_DATA');
        Schema::create('POSTING_DATA', function (Blueprint $table) {
            $table->integer('c_posting_id')->primary();
            $table->integer('c_personid')->default(0);
        });

        Schema::dropIfExists('ENTRY_DATA');
        Schema::create('ENTRY_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_entry_code');
            $table->integer('c_sequence');
            $table->integer('c_kin_code');
            $table->integer('c_assoc_code');
            $table->integer('c_kin_id');
            $table->integer('c_year');
            $table->integer('c_assoc_id');
            $table->integer('c_inst_code');
            $table->integer('c_inst_name_code');
            // 對齊真實 ENTRY_DATA：create handler 依 legacy 幂等會補寫這兩個哨兵 0 欄（缺列會令 insert 失敗）。
            $table->integer('c_entry_addr_id')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });

        Schema::dropIfExists('ASSOC_DATA');
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
            $table->integer('c_source')->default(0);
            $table->text('c_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
            $table->primary([
                'c_personid', 'c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id',
                'c_assoc_kin_code', 'c_assoc_kin_id', 'c_text_title', 'c_assoc_first_year',
            ]);
        });

        Schema::dropIfExists('ASSOC_CODES');
        Schema::create('ASSOC_CODES', function (Blueprint $table) {
            $table->integer('c_assoc_code')->primary();
            $table->integer('c_assoc_pair')->nullable();
            $table->integer('c_assoc_pair2')->nullable();
        });
        DB::table('ASSOC_CODES')->insert([
            ['c_assoc_code' => 100, 'c_assoc_pair' => 101, 'c_assoc_pair2' => null],
            ['c_assoc_code' => 101, 'c_assoc_pair' => 100, 'c_assoc_pair2' => null],
        ]);

        Schema::dropIfExists('KIN_DATA');
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

        Schema::dropIfExists('KINSHIP_CODES');
        Schema::create('KINSHIP_CODES', function (Blueprint $table) {
            $table->integer('c_kincode')->primary();
            $table->integer('c_kin_pair1')->nullable();
            $table->integer('c_kin_pair2')->nullable();
        });
        DB::table('KINSHIP_CODES')->insert([
            ['c_kincode' => 100, 'c_kin_pair1' => 101, 'c_kin_pair2' => null],
            ['c_kincode' => 101, 'c_kin_pair1' => 100, 'c_kin_pair2' => null],
        ]);
    }

    protected function tearDown(): void {
        Schema::dropIfExists('char_variant_map');
        Schema::dropIfExists('KINSHIP_CODES');
        Schema::dropIfExists('KIN_DATA');
        Schema::dropIfExists('ASSOC_CODES');
        Schema::dropIfExists('ASSOC_DATA');
        Schema::dropIfExists('POSTING_DATA');
        Schema::dropIfExists('POSTED_TO_ADDR_DATA');
        Schema::dropIfExists('POSTED_TO_OFFICE_DATA');
        Schema::dropIfExists('ENTRY_DATA');
        Schema::dropIfExists('BIOG_SOURCE_DATA');
        Schema::dropIfExists('TEST_SINGLE');
        Schema::dropIfExists('TEST_CODES');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    protected function makeAdmin(): User {
        $user = new User([
            'name' => 'admin',
            'email' => 'admin@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 100;
        $user->is_active = 1;
        $user->is_admin = 1;
        $user->save();

        return $user;
    }

    protected function proposalOperation(array $attributes = []): Operation {
        $operation = new Operation();
        $operation->user_id = $attributes['user_id'] ?? 100;
        $operation->c_personid = 0;
        $operation->op_type = $attributes['op_type'] ?? Operation::TYPE_PROPOSAL_CREATE;
        $operation->resource = $attributes['resource'] ?? 'TEST_CODES';
        $operation->resource_id = $attributes['resource_id'] ?? 'TEST';
        $operation->resource_data = json_encode($attributes['resource_data'], JSON_UNESCAPED_UNICODE);
        $operation->resource_original = json_encode($attributes['resource_original'] ?? [], JSON_UNESCAPED_UNICODE);
        $operation->save();

        return $operation;
    }

    #[Test]
    public function testApproveCreateProposalInsertsRow() {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $resourceData = [
            'code_id' => 'AP',
            'code_sub' => '01',
            'description' => 'Approved create',
            '__key_columns' => ['code_id', 'code_sub'],
            '__review_status' => 'pending',
            '__proposal_meta' => ['submitted_by' => 'tester', 'submitted_at' => Carbon::now()->format('Y-m-d H:i:s')],
        ];

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource_id' => 'AP_._01',
            'resource_data' => $resourceData,
        ]);

        $storedPayload = json_decode($operation->resource_data, true);
        $this->assertSame('AP', $storedPayload['code_id']);
        $this->assertSame(['code_id', 'code_sub'], $storedPayload['__key_columns']);

        $response = $this->post(route('operations.proposals.approve', $operation));

        $response->assertRedirect();

        $flash = session('flash_notification', collect())->toArray();
        $this->assertNotEmpty($flash);
        $this->assertStringContainsString('已核准', $flash[0]['message'] ?? '');

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status'] ?? null);

        $this->assertDatabaseHas('TEST_CODES', [
            'code_id' => 'AP',
            'code_sub' => '01',
            'description' => 'Approved create',
        ]);

        $this->assertSame($admin->name, $payload['__reviewed_by']);

        $this->assertDatabaseHas('operations', [
            'resource' => 'TEST_CODES',
            'op_type' => Operation::TYPE_CREATE,
        ]);
    }

    #[Test]
    public function testApproveCreateProposalReassignsSingleNumericKey() {
        DB::table('TEST_SINGLE')->insert([
            'id' => 5,
            'description' => 'Existing',
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $resourceData = [
            'id' => 5,
            'description' => 'Approved create',
            '__key_columns' => ['id'],
            '__review_status' => 'pending',
            '__proposal_meta' => ['submitted_by' => 'tester', 'submitted_at' => Carbon::now()->format('Y-m-d H:i:s')],
        ];

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'TEST_SINGLE',
            'resource_id' => '5',
            'resource_data' => $resourceData,
        ]);

        $response = $this->post(route('operations.proposals.approve', $operation));
        $response->assertRedirect();

        $this->assertDatabaseHas('TEST_SINGLE', [
            'id' => 6,
            'description' => 'Approved create',
        ]);

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status']);
        $this->assertSame(6, $payload['id']);
        $this->assertSame('6', $payload['__proposal_meta']['approved_resource_id'] ?? null);
        $this->assertSame('6', $operation->resource_id);
    }

    #[Test]
    public function testApproveUpdateProposalUpdatesRow() {
        \DB::table('TEST_CODES')->insert([
            'code_id' => 'UP',
            'code_sub' => '02',
            'description' => 'Before',
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $resourceData = [
            'code_id' => 'UP',
            'code_sub' => '02',
            'description' => 'After',
            '__key_columns' => ['code_id', 'code_sub'],
            '__review_status' => 'pending',
        ];

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource_id' => 'UP_._02',
            'resource_data' => $resourceData,
            'resource_original' => [
                'code_id' => 'UP',
                'code_sub' => '02',
                'description' => 'Before',
            ],
        ]);

        $response = $this->post(route('operations.proposals.approve', $operation), [
            'review_comment' => 'Looks good',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('TEST_CODES', [
            'code_id' => 'UP',
            'code_sub' => '02',
            'description' => 'After',
        ]);

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status']);
        $this->assertSame('Looks good', $payload['__review_comment']);

        $this->assertDatabaseHas('operations', [
            'resource' => 'TEST_CODES',
            'op_type' => Operation::TYPE_UPDATE,
        ]);
    }

    #[Test]
    public function testApproveCreateProposalAllowsEmptySourcePages() {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $resourceData = [
            'c_personid' => 138841,
            'c_textid' => 500,
            'c_pages' => '',
            'c_notes' => 'Approved source',
            'c_main_source' => 1,
            'c_self_bio' => 0,
            '__key_columns' => ['c_personid', 'c_textid', 'c_pages'],
            '__review_status' => 'pending',
            '__proposal_meta' => ['submitted_by' => 'tester', 'submitted_at' => Carbon::now()->format('Y-m-d H:i:s')],
        ];

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'BIOG_SOURCE_DATA',
            'resource_id' => 'c_personid=138841&c_textid=500&c_pages=',
            'resource_data' => $resourceData,
        ]);

        $response = $this->post(route('operations.proposals.approve', $operation));

        $response->assertRedirect();

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status'] ?? null);

        $this->assertDatabaseHas('BIOG_SOURCE_DATA', [
            'c_personid' => 138841,
            'c_textid' => 500,
            'c_pages' => '',
            'c_notes' => 'Approved source',
            'c_main_source' => 1,
        ]);

        $row = DB::table('BIOG_SOURCE_DATA')->where([
            ['c_personid', '=', 138841],
            ['c_textid', '=', 500],
            ['c_pages', '=', ''],
        ])->first();
        $this->assertSame('admin', $row->c_created_by);
        $this->assertNotNull($row->c_created_date);
    }

    /**
     * §4.5 段一：核准 create 提案改由 v2 handler 重放，handler 的引用完整性校驗因此生效——
     * c_textid 不存在於 TEXT_CODES 時 fail-closed（舊通用路徑會盲插一列殘缺資料）。
     */
    #[Test]
    public function testApproveCreateProposalEnforcesHandlerValidation(): void {
        $this->actingAs($this->makeAdmin());

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'BIOG_SOURCE_DATA',
            'resource_id' => 'c_personid=138841&c_textid=424242&c_pages=',
            'resource_data' => [
                'c_personid' => 138841,
                'c_textid' => 424242, // 不在 TEXT_CODES
                'c_pages' => '',
                'c_notes' => 'bad ref',
                '__key_columns' => ['c_personid', 'c_textid', 'c_pages'],
                '__review_status' => 'pending',
            ],
        ]);

        $this->post(route('operations.proposals.approve', $operation))->assertRedirect();

        // 未落庫、提案維持待審。
        $this->assertSame(0, DB::table('BIOG_SOURCE_DATA')->where('c_textid', 424242)->count());
        $operation->refresh();
        $this->assertSame('pending', json_decode($operation->resource_data, true)['__review_status'] ?? null);
    }

    #[Test]
    public function testApproveCreateEntryProposalSetsCreatedAuditFields() {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $resourceData = [
            'c_personid' => 138841,
            'c_entry_code' => 39,
            'c_sequence' => 1,
            'c_kin_code' => 0,
            'c_assoc_code' => 0,
            'c_kin_id' => 0,
            'c_year' => 1351,
            'c_assoc_id' => 0,
            'c_inst_code' => 0,
            'c_inst_name_code' => 0,
            '__key_columns' => ['c_personid', 'c_entry_code', 'c_sequence', 'c_kin_code', 'c_assoc_code', 'c_kin_id', 'c_year', 'c_assoc_id', 'c_inst_code', 'c_inst_name_code'],
            '__review_status' => 'pending',
            '__proposal_meta' => ['submitted_by' => 'tester', 'submitted_at' => Carbon::now()->format('Y-m-d H:i:s')],
        ];

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'ENTRY_DATA',
            'resource_id' => 'entry-proposal',
            'resource_data' => $resourceData,
        ]);

        $response = $this->post(route('operations.proposals.approve', $operation));

        $response->assertRedirect();

        $row = DB::table('ENTRY_DATA')->where([
            ['c_personid', '=', 138841],
            ['c_entry_code', '=', 39],
            ['c_sequence', '=', 1],
            ['c_kin_code', '=', 0],
            ['c_assoc_code', '=', 0],
            ['c_kin_id', '=', 0],
            ['c_year', '=', 1351],
            ['c_assoc_id', '=', 0],
            ['c_inst_code', '=', 0],
            ['c_inst_name_code', '=', 0],
        ])->first();

        $this->assertNotNull($row);
        // 核准署名採雙人名「審核人 (Proposed by: 提案人)」（2026-08-05 語義定案）。
        $this->assertSame('admin (Proposed by: tester)', $row->c_created_by);
        $this->assertNotNull($row->c_created_date);
    }

    #[Test]
    public function testApproveUpdateProposalAllowsCompositePrimaryKeyChange(): void {
        DB::table('TEST_CODES')->insert([
            'code_id' => 'UP',
            'code_sub' => '02',
            'description' => 'Before',
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $resourceData = [
            'code_id' => 'UP',
            'code_sub' => '03',
            'description' => 'After PK changed',
            '__key_columns' => ['code_id', 'code_sub'],
            '__review_status' => 'pending',
        ];

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource_id' => 'UP_._02',
            'resource_data' => $resourceData,
            'resource_original' => [
                'code_id' => 'UP',
                'code_sub' => '02',
                'description' => 'Before',
            ],
        ]);

        $response = $this->post(route('operations.proposals.approve', $operation), [
            'review_comment' => '允許修改主鍵欄位',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseMissing('TEST_CODES', [
            'code_id' => 'UP',
            'code_sub' => '02',
        ]);
        $this->assertDatabaseHas('TEST_CODES', [
            'code_id' => 'UP',
            'code_sub' => '03',
            'description' => 'After PK changed',
        ]);

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status']);
    }

    #[Test]
    public function testApproveUpdateProposalReKeyCollisionRejected(): void {
        // #117：提案核准改鍵時，若變更後新主鍵已被另一列佔用 → 明確擋下（不覆寫他列、不冒未處理 500），提案維持待審。
        DB::table('BIOG_SOURCE_DATA')->insert([
            ['c_personid' => 200, 'c_textid' => 500, 'c_pages' => '12-15', 'c_notes' => 'orig'],
            ['c_personid' => 200, 'c_textid' => 700, 'c_pages' => '88', 'c_notes' => 'occupier'],
        ]);

        $this->actingAs($this->makeAdmin());

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'BIOG_SOURCE_DATA',
            'resource_id' => 'c_personid=200&c_textid=500&c_pages=12-15',
            'resource_data' => [
                'c_personid' => 200, 'c_textid' => 700, 'c_pages' => '88', 'c_notes' => 'orig',
                '__key_columns' => ['c_personid', 'c_textid', 'c_pages'],
                '__review_status' => 'pending',
            ],
            'resource_original' => ['c_personid' => 200, 'c_textid' => 500, 'c_pages' => '12-15', 'c_notes' => 'orig'],
        ]);

        $response = $this->post(route('operations.proposals.approve', $operation), [
            'review_comment' => '改鍵到已佔用主鍵',
        ]);
        $response->assertRedirect(); // 乾淨擋下，非 500

        // 兩列皆原樣保留（未覆寫、未刪除）。
        $this->assertDatabaseHas('BIOG_SOURCE_DATA', ['c_personid' => 200, 'c_textid' => 500, 'c_pages' => '12-15', 'c_notes' => 'orig']);
        $this->assertDatabaseHas('BIOG_SOURCE_DATA', ['c_personid' => 200, 'c_textid' => 700, 'c_pages' => '88', 'c_notes' => 'occupier']);
        // 提案未核准（維持待審）。
        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertNotSame('approved', $payload['__review_status'] ?? null);
    }

    #[Test]
    public function testApproveUpdateProposalReadbackUsesUnchangedOriginalKeyRepresentation(): void {
        DB::table('TEST_CODES')->insert([
            'code_id' => 'UP',
            'code_sub' => '02',
            'description' => 'Before',
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $resourceData = [
            'code_id' => 'UP',
            'code_sub' => '2',
            'description' => 'After normalize-equal key',
            '__key_columns' => ['code_id', 'code_sub'],
            '__review_status' => 'pending',
        ];

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource_id' => 'UP_._02',
            'resource_data' => $resourceData,
            'resource_original' => [
                'code_id' => 'UP',
                'code_sub' => '02',
                'description' => 'Before',
            ],
        ]);

        $response = $this->post(route('operations.proposals.approve', $operation));
        $response->assertRedirect();

        $flash = session('flash_notification', collect())->toArray();
        $this->assertNotEmpty($flash);
        $this->assertStringContainsString('提案已核准並套用至資料表', $flash[0]['message'] ?? '');

        $this->assertDatabaseHas('TEST_CODES', [
            'code_id' => 'UP',
            'code_sub' => '02',
            'description' => 'After normalize-equal key',
        ]);

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status']);
    }

    #[Test]
    public function testRejectProposalUpdatesStatus() {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $resourceData = [
            'code_id' => 'RJ',
            'code_sub' => '03',
            'description' => 'Reject me',
            '__key_columns' => ['code_id', 'code_sub'],
            '__review_status' => 'pending',
        ];

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource_id' => 'RJ_._03',
            'resource_data' => $resourceData,
        ]);

        $response = $this->post(route('operations.proposals.reject', $operation), [
            'review_comment' => 'Not acceptable',
        ]);

        $response->assertRedirect();

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('rejected', $payload['__review_status']);
        $this->assertSame('Not acceptable', $payload['__review_comment']);
    }

    #[Test]
    public function testApproveDeleteProposalRemovesRow(): void {
        DB::table('TEST_CODES')->insert([
            'code_id' => 'DL',
            'code_sub' => '01',
            'description' => 'Delete me',
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $original = [
            'code_id' => 'DL',
            'code_sub' => '01',
            'description' => 'Delete me',
        ];

        $resourceData = array_merge($original, [
            '__key_columns' => ['code_id', 'code_sub'],
            '__review_status' => 'pending',
            '__proposal_meta' => ['action' => 'delete', 'submitted_by' => 'tester'],
        ]);

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_DELETE,
            'resource_id' => 'DL_._01',
            'resource_data' => $resourceData,
            'resource_original' => $original,
        ]);

        $response = $this->post(route('operations.proposals.approve', $operation), [
            'review_comment' => '同意刪除',
        ]);
        $response->assertRedirect();

        // 目標列確實被刪
        $this->assertDatabaseMissing('TEST_CODES', ['code_id' => 'DL', 'code_sub' => '01']);

        // __review_status=approved
        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status']);
        $this->assertSame('同意刪除', $payload['__review_comment']);

        // 寫入 TYPE_DELETE final operation
        $this->assertDatabaseHas('operations', [
            'resource' => 'TEST_CODES',
            'op_type' => Operation::TYPE_DELETE,
        ]);

        // audit DELETE 寫入：old=original, new=null
        $audit = DB::table('audit_log')->where('table_name', 'TEST_CODES')->where('operation', 'DELETE')->first();
        $this->assertNotNull($audit);
        $this->assertNotNull($audit->old_data);
        $this->assertNull($audit->new_data);
    }

    #[Test]
    public function testApproveDeleteAssocProposalRemovesReciprocalMirror(): void {
        // SEVERE 修復：核准社會關係刪除提案時，反向鏡像列須同步刪除（避免留下單向孤兒）。
        $assocPk = [
            'c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000,
            'c_kin_code' => 0, 'c_kin_id' => 0, 'c_assoc_kin_code' => 0, 'c_assoc_kin_id' => 0,
            'c_text_title' => '史記', 'c_assoc_first_year' => 1080,
        ];
        DB::table('ASSOC_DATA')->insert(array_merge($assocPk, ['c_source' => 10]));
        // 反向鏡像（對方 2000 擁有、反向碼 101、對稱 0,0 → 策略 1 可定位）。
        DB::table('ASSOC_DATA')->insert(array_merge($assocPk, [
            'c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000, 'c_source' => 10,
        ]));

        $this->actingAs($this->makeAdmin());

        $resourceData = array_merge($assocPk, [
            '__key_columns' => array_keys($assocPk),
            '__review_status' => 'pending',
            '__proposal_meta' => ['action' => 'delete', 'submitted_by' => 'tester'],
        ]);
        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_DELETE,
            'resource' => 'ASSOC_DATA',
            'resource_id' => '1000-100-2000-0-0-0-0-史記-1080',
            'resource_data' => $resourceData,
            'resource_original' => $assocPk,
        ]);

        $this->post(route('operations.proposals.approve', $operation), ['review_comment' => '同意刪除'])
            ->assertRedirect();

        // 正向已刪。
        $this->assertDatabaseMissing('ASSOC_DATA', ['c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000]);
        // 反向鏡像同步刪除（雙向，修復前會殘留）。
        $this->assertDatabaseMissing('ASSOC_DATA', ['c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000]);

        // 審計鏈完整：反向鏡像 DELETE audit 掛 final delete operation id（與正向一致，非 null）。
        $finalOp = DB::table('operations')->where('resource', 'ASSOC_DATA')
            ->where('op_type', Operation::TYPE_DELETE)->latest('id')->first();
        $this->assertNotNull($finalOp);
        $mirrorAudit = DB::table('audit_log')->where('table_name', 'ASSOC_DATA')->where('operation', 'DELETE')
            ->where('old_data', 'like', '%"c_personid":2000%')->first();
        $this->assertNotNull($mirrorAudit);
        $this->assertSame((string) $finalOp->id, (string) $mirrorAudit->operation_id);
    }

    // ── #77 提案核准接上 #66 鏡像衝突偵測（fail-safe：對面分歧 → 中止核准、不靜默覆寫）──────────────

    /** 建一筆社會關係 UPDATE 提案 operation（正向新內容 + 互逆配對碼入 aux；原始列供定位）。 */
    private function makeAssocUpdateProposal(array $forwardPk, array $newContent, array $original): Operation {
        $resourceData = array_merge($forwardPk, $newContent, [
            'c_assocship_pair' => 101, 'c_kinship_pair' => 0, 'c_assoc_kinship_pair' => 0,
            '__key_columns' => array_keys($forwardPk),
            '__review_status' => 'pending',
            '__proposal_meta' => ['action' => 'update', 'submitted_by' => 'tester'],
        ]);

        $op = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'ASSOC_DATA',
            'resource_id' => '1000-100-2000-0-0-0-0-史記-1080',
            'resource_data' => $resourceData,
            'resource_original' => array_merge($forwardPk, $original),
        ]);
        // proposalOperation 預設 c_personid=0；真實提案帶實際人物 id，核准時用於定位反向鏡像，須設為 1000。
        $op->c_personid = 1000;
        $op->save();

        return $op;
    }

    #[Test]
    public function testApproveAssocUpdateProposalBlockedWhenMirrorContentDiverged(): void {
        // #77：提案待審期間對面互逆鏡像被獨立改過（c_notes 分歧）→ 核准時偵測衝突 → 整筆回滾、提案維持 pending，
        // 正向不更新、對面鏡像不被靜默覆寫。修正前 detectConflict=false 會靜默覆寫對方資料。
        $fwd = [
            'c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000,
            'c_kin_code' => 0, 'c_kin_id' => 0, 'c_assoc_kin_code' => 0, 'c_assoc_kin_id' => 0,
            'c_text_title' => '史記', 'c_assoc_first_year' => 1080,
        ];
        DB::table('ASSOC_DATA')->insert(array_merge($fwd, ['c_source' => 10, 'c_notes' => '正向原備註']));
        // 對面鏡像（碼 101）被獨立改成不同內容。
        DB::table('ASSOC_DATA')->insert(array_merge($fwd, [
            'c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000,
            'c_source' => 10, 'c_notes' => '對面被獨立改過',
        ]));

        $this->actingAs($this->makeAdmin());
        $operation = $this->makeAssocUpdateProposal(
            $fwd,
            ['c_source' => 10, 'c_notes' => '提案改後'],
            ['c_source' => 10, 'c_notes' => '正向原備註']
        );

        $this->post(route('operations.proposals.approve', $operation), ['review_comment' => '核准'])
            ->assertRedirect();

        // 提案維持 pending（未核准）。
        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('pending', $payload['__review_status'] ?? null, '衝突應中止核准、維持 pending');
        // 整筆回滾：正向 c_notes 未變、對面鏡像未被覆寫。
        $this->assertSame('正向原備註', DB::table('ASSOC_DATA')->where(['c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000])->value('c_notes'));
        $this->assertSame('對面被獨立改過', DB::table('ASSOC_DATA')->where(['c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000])->value('c_notes'));
        // 友善錯誤提示（不外洩 SQL）。
        $flash = session('flash_notification', collect())->toArray();
        $this->assertStringContainsString('審核未通過', $flash[0]['message'] ?? '');
    }

    #[Test]
    public function testApproveAssocUpdateProposalSucceedsWhenMirrorInSync(): void {
        // #77 對照（不誤擋）：對面鏡像與正向舊值一致（仍同步）→ 核准照常通過，正向與鏡像一併更新。
        $fwd = [
            'c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000,
            'c_kin_code' => 0, 'c_kin_id' => 0, 'c_assoc_kin_code' => 0, 'c_assoc_kin_id' => 0,
            'c_text_title' => '史記', 'c_assoc_first_year' => 1080,
        ];
        DB::table('ASSOC_DATA')->insert(array_merge($fwd, ['c_source' => 10, 'c_notes' => '正向原備註']));
        DB::table('ASSOC_DATA')->insert(array_merge($fwd, [
            'c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000,
            'c_source' => 10, 'c_notes' => '正向原備註', // 與正向舊值一致＝仍同步
        ]));

        $this->actingAs($this->makeAdmin());
        $operation = $this->makeAssocUpdateProposal(
            $fwd,
            ['c_source' => 10, 'c_notes' => '提案改後'],
            ['c_source' => 10, 'c_notes' => '正向原備註']
        );

        $this->post(route('operations.proposals.approve', $operation), ['review_comment' => '核准'])
            ->assertRedirect();

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status'] ?? null, '同步狀態下核准應通過');
        $this->assertSame('提案改後', DB::table('ASSOC_DATA')->where(['c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000])->value('c_notes'));
        // 鏡像一併同步為新內容。
        $this->assertSame('提案改後', DB::table('ASSOC_DATA')->where(['c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000])->value('c_notes'));
    }

    #[Test]
    public function testApproveAssocUpdateProposalBlockedWhenMirrorCodeDrifted(): void {
        // #77（codex SERIOUS 補洞）：對面鏡像「關係碼漂移」成 ∉ ASSOC_CODES 的垃圾值（99）→ 嚴格定位落空。
        // 核准（allowBackfill=true + detectConflict=true）放寬查到漂移疑似 → 拋 MirrorSuspectedException → 中止核准、回滾。
        // 修正前 allowBackfill=false 會在偵測前 early-return → 核准「成功」但鏡像沒同步（false-green）。
        $fwd = [
            'c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000,
            'c_kin_code' => 0, 'c_kin_id' => 0, 'c_assoc_kin_code' => 0, 'c_assoc_kin_id' => 0,
            'c_text_title' => '史記', 'c_assoc_first_year' => 1080,
        ];
        DB::table('ASSOC_DATA')->insert(array_merge($fwd, ['c_source' => 10, 'c_notes' => '正向原備註']));
        // 對面鏡像碼漂移成 99（嚴格定位 {101} 落空、放寬可查到）。
        DB::table('ASSOC_DATA')->insert(array_merge($fwd, [
            'c_personid' => 2000, 'c_assoc_code' => 99, 'c_assoc_id' => 1000, 'c_source' => 10, 'c_notes' => '漂移鏡像',
        ]));

        $this->actingAs($this->makeAdmin());
        $operation = $this->makeAssocUpdateProposal($fwd, ['c_source' => 10, 'c_notes' => '提案改後'], ['c_source' => 10, 'c_notes' => '正向原備註']);

        $this->post(route('operations.proposals.approve', $operation), ['review_comment' => '核准'])->assertRedirect();

        $operation->refresh();
        $this->assertSame('pending', (json_decode($operation->resource_data, true)['__review_status'] ?? null), '漂移疑似應中止核准');
        $this->assertSame('正向原備註', DB::table('ASSOC_DATA')->where(['c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000])->value('c_notes'));
        $this->assertSame('漂移鏡像', DB::table('ASSOC_DATA')->where(['c_personid' => 2000, 'c_assoc_code' => 99, 'c_assoc_id' => 1000])->value('c_notes'));
        $this->assertSame(2, DB::table('ASSOC_DATA')->count(), '回滾：不得補出第三條鏡像');
    }

    #[Test]
    public function testApproveAssocUpdateProposalBackfillsMissingMirror(): void {
        // #77：對面完全無反向鏡像（合法單邊）→ 核准（allowBackfill=true）補建鏡像＝雙向同步（對齊 v2 direct）。
        $fwd = [
            'c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000,
            'c_kin_code' => 0, 'c_kin_id' => 0, 'c_assoc_kin_code' => 0, 'c_assoc_kin_id' => 0,
            'c_text_title' => '史記', 'c_assoc_first_year' => 1080,
        ];
        DB::table('ASSOC_DATA')->insert(array_merge($fwd, ['c_source' => 10, 'c_notes' => '正向原備註']));

        $this->actingAs($this->makeAdmin());
        $operation = $this->makeAssocUpdateProposal($fwd, ['c_source' => 10, 'c_notes' => '提案改後'], ['c_source' => 10, 'c_notes' => '正向原備註']);

        $this->post(route('operations.proposals.approve', $operation), ['review_comment' => '核准'])->assertRedirect();

        $operation->refresh();
        $this->assertSame('approved', (json_decode($operation->resource_data, true)['__review_status'] ?? null));
        $this->assertSame('提案改後', DB::table('ASSOC_DATA')->where(['c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000])->value('c_notes'));
        // 反向鏡像補建（碼 101、對方為主體）。
        $this->assertSame('提案改後', DB::table('ASSOC_DATA')->where(['c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000])->value('c_notes'));
    }

    #[Test]
    public function testApproveAssocCreateProposalBlockedWhenOppositeDiverged(): void {
        // #82（D1）：核准 CREATE 時，對面已存在「以權威反向碼(101)嚴格命中」但內容分歧的反向列 → 偵測衝突 → 中止核准、
        // 整筆回滾（正向未插入、對面不被覆寫）。修正前 legacy assocStoreById 盲插會靜默補出衝突/重複鏡像。
        DB::table('ASSOC_DATA')->insert([
            'c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000,
            'c_kin_code' => 0, 'c_kin_id' => 0, 'c_assoc_kin_code' => 0, 'c_assoc_kin_id' => 0,
            'c_text_title' => '史記', 'c_assoc_first_year' => 1080,
            'c_source' => 10, 'c_notes' => '對面既有不同內容',
        ]);

        $this->actingAs($this->makeAdmin());
        $operation = $this->makeAssocCreateProposal('提案內容');

        $this->post(route('operations.proposals.approve', $operation), ['review_comment' => '核准'])->assertRedirect();

        $operation->refresh();
        $this->assertSame('pending', (json_decode($operation->resource_data, true)['__review_status'] ?? null), '對面分歧應中止核准');
        $this->assertNull(DB::table('ASSOC_DATA')->where(['c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000])->first(), '回滾：正向未插入');
        $this->assertSame('對面既有不同內容', DB::table('ASSOC_DATA')->where(['c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000])->value('c_notes'));
        $this->assertSame(1, DB::table('ASSOC_DATA')->count(), '回滾：不得補出衝突/重複鏡像');
    }

    #[Test]
    public function testApproveAssocCreateProposalBackfillsWhenNoOpposite(): void {
        // #82（D1 對照，不誤擋）：對面無任何反向列 → 核准 CREATE 照常插入正向 + 補建反向鏡像（碼 101），雙向同步。
        $this->actingAs($this->makeAdmin());
        $operation = $this->makeAssocCreateProposal('提案內容');

        $this->post(route('operations.proposals.approve', $operation), ['review_comment' => '核准'])->assertRedirect();

        $operation->refresh();
        $this->assertSame('approved', (json_decode($operation->resource_data, true)['__review_status'] ?? null), '無對面應照常核准');
        $this->assertSame('提案內容', DB::table('ASSOC_DATA')->where(['c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000])->value('c_notes'));
        $this->assertSame('提案內容', DB::table('ASSOC_DATA')->where(['c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000])->value('c_notes'), '反向鏡像補建');
        $this->assertSame(2, DB::table('ASSOC_DATA')->count());
    }

    /** 建一筆社會關係 CREATE 提案（正向 (1000,100,2000,...,史記,1080)；c_assocship_pair=101；c_personid=1000）。 */
    private function makeAssocCreateProposal(string $notes): Operation {
        $resourceData = [
            'c_personid' => 1000, 'c_assoc_code' => 100, 'c_assoc_id' => 2000,
            'c_kin_code' => 0, 'c_kin_id' => 0, 'c_assoc_kin_code' => 0, 'c_assoc_kin_id' => 0,
            'c_text_title' => '史記', 'c_assoc_first_year' => 1080,
            'c_source' => 10, 'c_notes' => $notes,
            'c_assocship_pair' => 101, 'c_kinship_pair' => 0, 'c_assoc_kinship_pair' => 0,
            '__key_columns' => ['c_personid', 'c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id', 'c_assoc_kin_code', 'c_assoc_kin_id', 'c_text_title', 'c_assoc_first_year'],
            '__review_status' => 'pending',
            '__proposal_meta' => ['action' => 'create', 'submitted_by' => 'tester'],
        ];
        $op = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'ASSOC_DATA',
            'resource_id' => '1000-100-2000-0-0-0-0-史記-1080',
            'resource_data' => $resourceData,
        ]);
        $op->c_personid = 1000;
        $op->save();

        return $op;
    }

    #[Test]
    public function testApproveKinCreateProposalBlockedWhenOppositeDiverged(): void {
        // #82（D1，kin）：核准 CREATE 時對面已存在嚴格命中(碼101)但內容分歧的反向列 → 偵測衝突 → 中止核准、回滾、不盲插。
        DB::table('KIN_DATA')->insert(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 101, 'c_source' => 10, 'c_notes' => '對面既有不同內容', 'c_autogen_notes' => 'auto-x']);

        $this->actingAs($this->makeAdmin());
        $resourceData = [
            'c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 100,
            'c_source' => 10, 'c_notes' => '提案內容', 'c_autogen_notes' => 'auto-x',
            'c_kinship_pair' => 101,
            '__key_columns' => ['c_personid', 'c_kin_id', 'c_kin_code'],
            '__review_status' => 'pending',
            '__proposal_meta' => ['action' => 'create', 'submitted_by' => 'tester'],
        ];
        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'KIN_DATA',
            'resource_id' => '1000-2000-100',
            'resource_data' => $resourceData,
        ]);
        $operation->c_personid = 1000;
        $operation->save();

        $this->post(route('operations.proposals.approve', $operation), ['review_comment' => '核准'])->assertRedirect();

        $operation->refresh();
        $this->assertSame('pending', (json_decode($operation->resource_data, true)['__review_status'] ?? null), 'kin 對面分歧應中止核准');
        $this->assertNull(DB::table('KIN_DATA')->where(['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 100])->first(), '回滾：正向未插入');
        $this->assertSame('對面既有不同內容', DB::table('KIN_DATA')->where(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 101])->value('c_notes'));
        $this->assertSame(1, DB::table('KIN_DATA')->count(), '回滾：不得補出衝突/重複鏡像');
    }

    #[Test]
    public function testApproveKinUpdateProposalBlockedWhenMirrorContentDiverged(): void {
        // #77（kin）：親屬 UPDATE 提案核准時，對面鏡像被獨立改過 → 偵測衝突 → 中止核准、回滾、不覆寫。
        DB::table('KIN_DATA')->insert(['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 100, 'c_source' => 10, 'c_notes' => '正向原備註', 'c_autogen_notes' => 'auto-x']);
        DB::table('KIN_DATA')->insert(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 101, 'c_source' => 10, 'c_notes' => '對面被獨立改過', 'c_autogen_notes' => 'auto-x']);

        $this->actingAs($this->makeAdmin());
        $fwdPk = ['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 100];
        $resourceData = array_merge($fwdPk, [
            'c_source' => 10, 'c_notes' => '提案改後', 'c_autogen_notes' => 'auto-x',
            'c_kinship_pair' => 101,
            '__key_columns' => ['c_personid', 'c_kin_id', 'c_kin_code'],
            '__review_status' => 'pending',
            '__proposal_meta' => ['action' => 'update', 'submitted_by' => 'tester'],
        ]);
        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'KIN_DATA',
            'resource_id' => '1000-2000-100',
            'resource_data' => $resourceData,
            'resource_original' => array_merge($fwdPk, ['c_source' => 10, 'c_notes' => '正向原備註', 'c_autogen_notes' => 'auto-x']),
        ]);
        $operation->c_personid = 1000;
        $operation->save();

        $this->post(route('operations.proposals.approve', $operation), ['review_comment' => '核准'])
            ->assertRedirect();

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('pending', $payload['__review_status'] ?? null, '衝突應中止核准、維持 pending');
        $this->assertSame('正向原備註', DB::table('KIN_DATA')->where(['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 100])->value('c_notes'));
        $this->assertSame('對面被獨立改過', DB::table('KIN_DATA')->where(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 101])->value('c_notes'));
    }

    #[Test]
    public function testApproveKinUpdateProposalSucceedsWhenMirrorInSync(): void {
        // #77（kin 對照，不誤擋）：對面鏡像與正向舊值一致 → 核准照常通過，正向與鏡像一併更新。
        DB::table('KIN_DATA')->insert(['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 100, 'c_source' => 10, 'c_notes' => '正向原備註', 'c_autogen_notes' => 'auto-x']);
        DB::table('KIN_DATA')->insert(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 101, 'c_source' => 10, 'c_notes' => '正向原備註', 'c_autogen_notes' => 'auto-x']);

        $this->actingAs($this->makeAdmin());
        $fwdPk = ['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 100];
        $resourceData = array_merge($fwdPk, [
            'c_source' => 10, 'c_notes' => '提案改後', 'c_autogen_notes' => 'auto-x',
            'c_kinship_pair' => 101,
            '__key_columns' => ['c_personid', 'c_kin_id', 'c_kin_code'],
            '__review_status' => 'pending',
            '__proposal_meta' => ['action' => 'update', 'submitted_by' => 'tester'],
        ]);
        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'KIN_DATA',
            'resource_id' => '1000-2000-100',
            'resource_data' => $resourceData,
            'resource_original' => array_merge($fwdPk, ['c_source' => 10, 'c_notes' => '正向原備註', 'c_autogen_notes' => 'auto-x']),
        ]);
        $operation->c_personid = 1000;
        $operation->save();

        $this->post(route('operations.proposals.approve', $operation), ['review_comment' => '核准'])
            ->assertRedirect();

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status'] ?? null, '同步狀態下核准應通過');
        $this->assertSame('提案改後', DB::table('KIN_DATA')->where(['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 100])->value('c_notes'));
        $this->assertSame('提案改後', DB::table('KIN_DATA')->where(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 101])->value('c_notes'));
    }

    #[Test]
    public function testApproveKinUpdateProposalUsesLegitReverseDespiteAutogenMismatch(): void {
        // #87：proposal approve 的 strict 定位也不認 autogen。對面合法反向碼 101 的 autogen 與正向不對稱時，
        // 仍須命中並同步；不可誤落 relaxed，把漂移列收斂/撞 PK 或維持 pending。
        DB::table('KIN_DATA')->insert(['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 100, 'c_source' => 10, 'c_notes' => '正向原備註', 'c_autogen_notes' => 'auto-x']);
        DB::table('KIN_DATA')->insert(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 99, 'c_source' => 10, 'c_notes' => '漂移鏡像', 'c_autogen_notes' => 'auto-x']);
        DB::table('KIN_DATA')->insert(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 101, 'c_source' => 10, 'c_notes' => '正向原備註', 'c_autogen_notes' => 'other']);

        $this->actingAs($this->makeAdmin());
        $operation = $this->makeKinUpdateProposal(['c_source' => 10, 'c_notes' => '提案改後']);

        $this->post(route('operations.proposals.approve', $operation), ['review_comment' => '核准'])->assertRedirect();

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status'] ?? null, '合法反向列不因 autogen 不對稱而卡 pending');
        $this->assertSame('提案改後', DB::table('KIN_DATA')->where(['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 100])->value('c_notes'));
        $this->assertSame('提案改後', DB::table('KIN_DATA')->where(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 101])->value('c_notes'));
        $this->assertSame('漂移鏡像', DB::table('KIN_DATA')->where(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 99])->value('c_notes'));
        $this->assertSame(3, DB::table('KIN_DATA')->count());
    }

    /** 建一筆親屬 UPDATE 提案 operation（正向 (1000,2000,100)→新內容；c_kinship_pair=101；c_personid=1000）。 */
    private function makeKinUpdateProposal(array $newContent): Operation {
        $fwdPk = ['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 100];
        $resourceData = array_merge($fwdPk, $newContent, [
            'c_autogen_notes' => 'auto-x', 'c_kinship_pair' => 101,
            '__key_columns' => ['c_personid', 'c_kin_id', 'c_kin_code'],
            '__review_status' => 'pending',
            '__proposal_meta' => ['action' => 'update', 'submitted_by' => 'tester'],
        ]);
        $op = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'KIN_DATA',
            'resource_id' => '1000-2000-100',
            'resource_data' => $resourceData,
            'resource_original' => array_merge($fwdPk, ['c_source' => 10, 'c_notes' => '正向原備註', 'c_autogen_notes' => 'auto-x']),
        ]);
        $op->c_personid = 1000;
        $op->save();

        return $op;
    }

    #[Test]
    public function testApproveKinUpdateProposalBlockedWhenMirrorCodeDrifted(): void {
        // #77（kin，codex MINOR 補測）：對面親屬碼漂移成 ∉ KINSHIP_CODES 的 99 → 嚴格落空 + 放寬查到漂移疑似 →
        // 核准（detectConflict=true，kin allowBackfill=false——其疑似偵測不受 allowBackfill 閘控）拋
        // MirrorSuspectedException → 中止核准、回滾、不補第三條。
        DB::table('KIN_DATA')->insert(['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 100, 'c_source' => 10, 'c_notes' => '正向原備註', 'c_autogen_notes' => 'auto-x']);
        DB::table('KIN_DATA')->insert(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 99, 'c_source' => 10, 'c_notes' => '漂移鏡像', 'c_autogen_notes' => 'auto-x']);

        $this->actingAs($this->makeAdmin());
        $operation = $this->makeKinUpdateProposal(['c_source' => 10, 'c_notes' => '提案改後']);

        $this->post(route('operations.proposals.approve', $operation), ['review_comment' => '核准'])->assertRedirect();

        $operation->refresh();
        $this->assertSame('pending', (json_decode($operation->resource_data, true)['__review_status'] ?? null), '漂移疑似應中止核准');
        $this->assertSame('正向原備註', DB::table('KIN_DATA')->where(['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 100])->value('c_notes'));
        $this->assertSame('漂移鏡像', DB::table('KIN_DATA')->where(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 99])->value('c_notes'));
        $this->assertSame(2, DB::table('KIN_DATA')->count(), '回滾：不得補出第三條鏡像');
    }

    #[Test]
    public function testApproveKinUpdateProposalBlockedWhenMirrorMissing(): void {
        // #77（kin，與 assoc 行為差異）：kin allowBackfill=false，對面無反向鏡像 → sumCount=0 → applyKinshipProposal
        // 既有 guard 拋「對應的親屬資料更新失敗」→ 中止核准、回滾（不補建、不單邊更新）。亦屬 fail-safe（不靜默不一致）。
        // 註：assoc 因 #70 偵測受 allowBackfill 閘控、改 true 後對「無鏡像」採補建；kin 則由 guard 擋下，兩者皆安全。
        DB::table('KIN_DATA')->insert(['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 100, 'c_source' => 10, 'c_notes' => '正向原備註', 'c_autogen_notes' => 'auto-x']);

        $this->actingAs($this->makeAdmin());
        $operation = $this->makeKinUpdateProposal(['c_source' => 10, 'c_notes' => '提案改後']);

        $this->post(route('operations.proposals.approve', $operation), ['review_comment' => '核准'])->assertRedirect();

        $operation->refresh();
        $this->assertSame('pending', (json_decode($operation->resource_data, true)['__review_status'] ?? null), '無鏡像應中止核准');
        // 回滾：正向未單邊更新、對面未補建。
        $this->assertSame('正向原備註', DB::table('KIN_DATA')->where(['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 100])->value('c_notes'));
        $this->assertSame(1, DB::table('KIN_DATA')->count(), '不得補建鏡像（kin 由 guard 擋下）');
    }

    #[Test]
    public function testApproveDeleteProposalRemovesOfficeAndAuxiliaryTables(): void {
        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => 1000,
            'c_office_id' => 50,
            'c_posting_id' => 7,
            'c_notes' => 'office',
        ]);
        DB::table('POSTED_TO_ADDR_DATA')->insert([
            ['c_personid' => 1000, 'c_posting_id' => 7, 'c_office_id' => 50, 'c_addr_id' => 130],
            ['c_personid' => 1000, 'c_posting_id' => 7, 'c_office_id' => 50, 'c_addr_id' => 200],
        ]);
        DB::table('POSTING_DATA')->insert(['c_posting_id' => 7, 'c_personid' => 1000]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $original = [
            'c_personid' => 1000,
            'c_office_id' => 50,
            'c_posting_id' => 7,
            'c_notes' => 'office',
        ];

        $resourceData = array_merge($original, [
            '__key_columns' => ['c_office_id', 'c_posting_id'],
            '__review_status' => 'pending',
            '__proposal_meta' => ['action' => 'delete'],
        ]);

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_DELETE,
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'resource_id' => '50_._7',
            'resource_data' => $resourceData,
            'resource_original' => $original,
        ]);
        $operation->c_personid = 1000;
        $operation->save();

        $response = $this->post(route('operations.proposals.approve', $operation));
        $response->assertRedirect();

        // 主表與副表一併刪除
        $this->assertDatabaseMissing('POSTED_TO_OFFICE_DATA', ['c_office_id' => 50, 'c_posting_id' => 7]);
        $this->assertSame(0, DB::table('POSTED_TO_ADDR_DATA')->where('c_posting_id', 7)->count());
        $this->assertSame(0, DB::table('POSTING_DATA')->where('c_posting_id', 7)->count());

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status']);

        $this->assertDatabaseHas('operations', [
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'op_type' => Operation::TYPE_DELETE,
        ]);
    }

    /**
     * 段二收斂回歸：postings 走 HANDLER_ROUTED_RESOURCES 重放 PostingMutationHandler 之後，
     * 地址副表意圖（c_addr）從 __proposal_aux（而非主表欄位快照）正確合併進 changes 並同步
     * POSTED_TO_ADDR_DATA——這是 applyViaMutationHandler() 新增 $auxiliaryPayload 合併要保住的行為。
     */
    #[Test]
    public function testApproveOfficeUpdateProposalSyncsAddressAuxiliaryTable(): void {
        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => 1000,
            'c_office_id' => 50,
            'c_posting_id' => 7,
            'c_notes' => 'original notes',
        ]);
        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => 1000, 'c_posting_id' => 7, 'c_office_id' => 50, 'c_addr_id' => 130,
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $original = [
            'c_personid' => 1000,
            'c_office_id' => 50,
            'c_posting_id' => 7,
            'c_notes' => 'original notes',
        ];
        $data = array_merge($original, ['c_notes' => 'updated notes']);

        $resourceData = array_merge($data, [
            '__key_columns' => ['c_office_id', 'c_posting_id'],
            '__review_status' => 'pending',
            '__proposal_meta' => ['action' => 'update'],
            '__proposal_aux' => ['c_addr' => [140, 200]],
        ]);

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'resource_id' => '50_._7',
            'resource_data' => $resourceData,
            'resource_original' => $original,
        ]);
        $operation->c_personid = 1000;
        $operation->save();

        $response = $this->post(route('operations.proposals.approve', $operation));
        $response->assertRedirect();

        $this->assertSame(
            'updated notes',
            DB::table('POSTED_TO_OFFICE_DATA')->where(['c_office_id' => 50, 'c_posting_id' => 7])->value('c_notes')
        );

        $addrIds = DB::table('POSTED_TO_ADDR_DATA')->where('c_posting_id', 7)
            ->pluck('c_addr_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
        $this->assertSame([140, 200], $addrIds, '__proposal_aux 的 c_addr 應合併進 changes 並同步副表，而非被丟棄');

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status']);
    }

    #[Test]
    public function testRejectDeleteProposalKeepsRow(): void {
        DB::table('TEST_CODES')->insert([
            'code_id' => 'RK',
            'code_sub' => '02',
            'description' => 'Keep me',
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $original = [
            'code_id' => 'RK',
            'code_sub' => '02',
            'description' => 'Keep me',
        ];

        $resourceData = array_merge($original, [
            '__key_columns' => ['code_id', 'code_sub'],
            '__review_status' => 'pending',
            '__proposal_meta' => ['action' => 'delete'],
        ]);

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_DELETE,
            'resource_id' => 'RK_._02',
            'resource_data' => $resourceData,
            'resource_original' => $original,
        ]);

        $response = $this->post(route('operations.proposals.reject', $operation), [
            'review_comment' => '不同意刪除',
        ]);
        $response->assertRedirect();

        // 目標列保留
        $this->assertDatabaseHas('TEST_CODES', ['code_id' => 'RK', 'code_sub' => '02', 'description' => 'Keep me']);

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('rejected', $payload['__review_status']);
        $this->assertSame('不同意刪除', $payload['__review_comment']);

        // 無 final DELETE operation、無 audit DELETE
        $this->assertDatabaseMissing('operations', ['resource' => 'TEST_CODES', 'op_type' => Operation::TYPE_DELETE]);
        $this->assertSame(0, DB::table('audit_log')->where('operation', 'DELETE')->count());
    }

    #[Test]
    public function testApproveDeleteKinProposalFailsClosedWhenCodeMissingFromCodeTable(): void {
        DB::table('KIN_DATA')->insert([
            'c_personid' => 1000,
            'c_kin_id' => 2000,
            'c_kin_code' => 999,
            'c_source' => 10,
            'c_notes' => '正向待刪',
            'c_autogen_notes' => 'auto-x',
        ]);
        DB::table('KIN_DATA')->insert([
            'c_personid' => 2000,
            'c_kin_id' => 1000,
            'c_kin_code' => 101,
            'c_source' => 10,
            'c_notes' => '對面鏡像',
            'c_autogen_notes' => 'auto-x',
        ]);

        $this->actingAs($this->makeAdmin());

        $original = [
            'c_personid' => 1000,
            'c_kin_id' => 2000,
            'c_kin_code' => 999,
            'c_source' => 10,
            'c_notes' => '正向待刪',
            'c_autogen_notes' => 'auto-x',
        ];
        $resourceData = array_merge($original, [
            '__key_columns' => ['c_personid', 'c_kin_id', 'c_kin_code'],
            '__review_status' => 'pending',
            '__proposal_meta' => ['action' => 'delete', 'submitted_by' => 'tester'],
        ]);
        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_DELETE,
            'resource' => 'KIN_DATA',
            'resource_id' => '1000-2000-999',
            'resource_data' => $resourceData,
            'resource_original' => $original,
        ]);
        $operation->c_personid = 1000;
        $operation->save();

        $this->post(route('operations.proposals.approve', $operation), ['review_comment' => '核准'])
            ->assertRedirect();

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('pending', $payload['__review_status'] ?? null, 'fail-closed 應中止核准並維持 pending');
        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 999]);
        $this->assertDatabaseHas('KIN_DATA', ['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 101]);
        $this->assertSame(2, DB::table('KIN_DATA')->count(), '回滾：正反向皆不得半刪');
        $this->assertSame(0, DB::table('operations')->where('resource', 'KIN_DATA')->where('op_type', Operation::TYPE_DELETE)->count(), '不得寫入 final delete operation');
    }
    // ── 異體字落地替換：提案核准端（plan S6）────────────────

    /**
     * 最小 char_variant_map 種子（「淸→清」）。本檔其餘測試不建這張表，走
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
     * 通用核准分支（applyCreateProposal）：**歷史遺留 payload** 的補網。
     *
     * 提案建立端（S2／S3／S5）存進 payload 的已是替換後值，所以這條測試刻意手造一筆
     * 「落地替換上線前送出」的提案（payload 裡還是變體形），核准時必須替換後才落庫。
     * TEXT_CODES 不在 HANDLER_ROUTED_RESOURCES，所以走的正是這條通用分支。
     */
    #[Test]
    public function testApproveCreateProposalReplacesVariantsInLegacyPayload(): void {
        $this->seedCharVariantMap();
        $this->actingAs($this->makeAdmin());

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'TEXT_CODES',
            'resource_id' => '90001',
            'resource_data' => [
                'c_textid' => 90001,
                'c_title' => '淸嘉錄',
                '__key_columns' => ['c_textid'],
                '__review_status' => 'pending',
                '__proposal_meta' => ['action' => 'create', 'submitted_by' => 'tester'],
            ],
        ]);

        $this->post(route('operations.proposals.approve', $operation))->assertRedirect();

        $this->assertSame('清嘉錄', DB::table('TEXT_CODES')->where('c_textid', 90001)->value('c_title'));
    }

    /** 通用核准分支（applyUpdateProposal）同理。 */
    #[Test]
    public function testApproveUpdateProposalReplacesVariantsInLegacyPayload(): void {
        $this->seedCharVariantMap();
        DB::table('TEXT_CODES')->insert(['c_textid' => 90002, 'c_title' => '舊名']);
        $this->actingAs($this->makeAdmin());

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'TEXT_CODES',
            'resource_id' => '90002',
            'resource_data' => [
                'c_textid' => 90002,
                'c_title' => '淸嘉錄',
                '__key_columns' => ['c_textid'],
                '__review_status' => 'pending',
                '__proposal_meta' => ['action' => 'update', 'submitted_by' => 'tester'],
            ],
            'resource_original' => ['c_textid' => 90002, 'c_title' => '舊名'],
        ]);

        $this->post(route('operations.proposals.approve', $operation))->assertRedirect();

        $this->assertSame('清嘉錄', DB::table('TEXT_CODES')->where('c_textid', 90002)->value('c_title'));
    }

    /**
     * 親屬提案核准不重放 v2 handler（直接呼叫 BiogMainRepository::kinshipStoreById），
     * 所以 S3 的基底掛鉤覆蓋不到，必須在 repository 那層獨立掛。
     */
    #[Test]
    public function testApproveKinshipCreateProposalReplacesVariants(): void {
        $this->seedCharVariantMap();
        $this->actingAs($this->makeAdmin());

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'KIN_DATA',
            'resource_id' => '1000-2000-100',
            'resource_data' => [
                'c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 100,
                'c_source' => 10, 'c_notes' => '淸代族譜',
                'c_kinship_pair' => 0,
                '__key_columns' => ['c_personid', 'c_kin_id', 'c_kin_code'],
                '__review_status' => 'pending',
                '__proposal_meta' => ['action' => 'create', 'submitted_by' => 'tester'],
            ],
        ]);
        $operation->c_personid = 1000;
        $operation->save();

        $this->post(route('operations.proposals.approve', $operation))->assertRedirect();

        $this->assertSame(
            '清代族譜',
            DB::table('KIN_DATA')->where(['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 100])->value('c_notes')
        );
    }

    /**
     * 社會關係提案核准同理，而且 `c_text_title` 是**主鍵成員**——替換等於改鍵，
     * 所以落庫的列與 operations 的 resource_id 必須看到同一個字形。
     */
    #[Test]
    public function testApproveAssocCreateProposalReplacesVariantsInPrimaryKeyMember(): void {
        $this->seedCharVariantMap();
        $this->actingAs($this->makeAdmin());

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'ASSOC_DATA',
            'resource_id' => '1000-1-2000-0-0-0-0-淸書-1060',
            'resource_data' => [
                'c_personid' => 1000, 'c_assoc_code' => 1, 'c_assoc_id' => 2000,
                'c_kin_code' => 0, 'c_kin_id' => 0, 'c_assoc_kin_code' => 0, 'c_assoc_kin_id' => 0,
                'c_text_title' => '淸書', 'c_assoc_first_year' => 1060, 'c_source' => 10,
                'c_assocship_pair' => 0, 'c_kinship_pair' => 0, 'c_assoc_kinship_pair' => 0,
                '__key_columns' => [
                    'c_personid', 'c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id',
                    'c_assoc_kin_code', 'c_assoc_kin_id', 'c_text_title', 'c_assoc_first_year',
                ],
                '__review_status' => 'pending',
                '__proposal_meta' => ['action' => 'create', 'submitted_by' => 'tester'],
            ],
        ]);
        $operation->c_personid = 1000;
        $operation->save();

        $this->post(route('operations.proposals.approve', $operation))->assertRedirect();

        $this->assertTrue(
            DB::table('ASSOC_DATA')->where('c_personid', 1000)->where('c_text_title', '清書')->exists(),
            'PK 成員應以參考形落庫'
        );
        $this->assertFalse(
            DB::table('ASSOC_DATA')->where('c_personid', 1000)->where('c_text_title', '淸書')->exists()
        );

        // 落庫後寫的 operation（TYPE_CREATE）其 resource_id 必須用替換後的字形，
        // 否則還原／稽核會指向一個不存在的列。
        $created = DB::table('operations')
            ->where('resource', 'ASSOC_DATA')
            ->where('op_type', Operation::TYPE_CREATE)
            ->latest('id')
            ->first();
        $this->assertNotNull($created);
        // resource_id 是 http_build_query()（值 percent-encoded），先解碼再比字形。
        $decodedResourceId = urldecode((string) $created->resource_id);
        $this->assertStringContainsString('清書', $decodedResourceId);
        $this->assertStringNotContainsString('淸書', $decodedResourceId);
    }

    /**
     * review：`assocPerformUpdate` 的替換也要測（原本只測了 create）。
     *
     * 這條同時斷言 plan 要求的「四處看到同一個字形」：落庫列、`operations.resource_id`、
     * `audit_log.row_pk`、**以及鏡像列**（鏡像是 `$data_mirror = $data` 直接繼承替換值，
     * 是重構時最容易失手的地方）。
     */
    #[Test]
    public function testApproveAssocUpdateProposalReplacesVariantsEverywhere(): void {
        $this->seedCharVariantMap();

        // 正向列與鏡像列都存舊書名。
        DB::table('ASSOC_DATA')->insert([
            ['c_personid' => 1000, 'c_assoc_code' => 1, 'c_assoc_id' => 2000, 'c_kin_code' => 0, 'c_kin_id' => 0,
                'c_assoc_kin_code' => 0, 'c_assoc_kin_id' => 0, 'c_text_title' => '舊書', 'c_assoc_first_year' => 1060, 'c_source' => 10],
            ['c_personid' => 2000, 'c_assoc_code' => 2, 'c_assoc_id' => 1000, 'c_kin_code' => 0, 'c_kin_id' => 1000,
                'c_assoc_kin_code' => 0, 'c_assoc_kin_id' => 1000, 'c_text_title' => '舊書', 'c_assoc_first_year' => 1060, 'c_source' => 10],
        ]);

        $this->actingAs($this->makeAdmin());

        $fwdPk = [
            'c_personid' => 1000, 'c_assoc_code' => 1, 'c_assoc_id' => 2000, 'c_kin_code' => 0, 'c_kin_id' => 0,
            'c_assoc_kin_code' => 0, 'c_assoc_kin_id' => 0, 'c_text_title' => '舊書', 'c_assoc_first_year' => 1060,
        ];
        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'ASSOC_DATA',
            'resource_id' => '1000-1-2000-0-0-0-0-舊書-1060',
            'resource_data' => array_merge($fwdPk, [
                'c_text_title' => '淸書', // 改書名，字形是變體形
                'c_source' => 10,
                'c_assocship_pair' => 2, 'c_kinship_pair' => 0, 'c_assoc_kinship_pair' => 0,
                '__key_columns' => array_keys($fwdPk),
                '__review_status' => 'pending',
                '__proposal_meta' => ['action' => 'update', 'submitted_by' => 'tester'],
            ]),
            'resource_original' => array_merge($fwdPk, ['c_source' => 10]),
        ]);
        $operation->c_personid = 1000;
        $operation->save();

        $this->post(route('operations.proposals.approve', $operation), ['review_comment' => '核准'])
            ->assertRedirect();

        // 1) 落庫列
        $this->assertTrue(
            DB::table('ASSOC_DATA')->where('c_personid', 1000)->where('c_text_title', '清書')->exists(),
            '正向列應以參考形落庫'
        );
        $this->assertFalse(DB::table('ASSOC_DATA')->where('c_text_title', '淸書')->exists(), '不得留下變體形');

        // 2) 鏡像列
        $this->assertTrue(
            DB::table('ASSOC_DATA')->where('c_personid', 2000)->where('c_text_title', '清書')->exists(),
            '鏡像列也要看到同一個字形'
        );

        // 3) operations.resource_id（percent-encoded，需先解碼）
        $updated = DB::table('operations')
            ->where('resource', 'ASSOC_DATA')
            ->where('op_type', Operation::TYPE_UPDATE)
            ->latest('id')
            ->first();
        $this->assertNotNull($updated);
        $this->assertStringContainsString('清書', urldecode((string) $updated->resource_id));

        // 4) audit_log.row_pk
        $audit = DB::table('audit_log')
            ->where('table_name', 'ASSOC_DATA')
            ->where('operation', 'UPDATE')
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('清書', (string) $audit->row_pk);
        $this->assertStringNotContainsString('淸書', (string) $audit->row_pk);
    }

    /** review：`kinshipUpdateById` 的替換也要測。 */
    #[Test]
    public function testApproveKinshipUpdateProposalReplacesVariants(): void {
        $this->seedCharVariantMap();
        // 親屬更新要求對面鏡像存在（否則 kinshipUpdateById 回 err、核准中止），
        // 比照既有的 testApproveKinUpdateProposalSucceedsWhenMirrorInSync 建雙邊。
        DB::table('KIN_DATA')->insert([
            ['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 100,
                'c_source' => 10, 'c_notes' => '舊注', 'c_autogen_notes' => 'auto-x'],
            ['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 101,
                'c_source' => 10, 'c_notes' => '舊注', 'c_autogen_notes' => 'auto-x'],
        ]);
        $this->actingAs($this->makeAdmin());

        $fwdPk = ['c_personid' => 1000, 'c_kin_id' => 2000, 'c_kin_code' => 100];
        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'KIN_DATA',
            'resource_id' => '1000-2000-100',
            'resource_data' => array_merge($fwdPk, [
                'c_source' => 10, 'c_notes' => '淸代族譜', 'c_autogen_notes' => 'auto-x',
                'c_kinship_pair' => 101,
                '__key_columns' => array_keys($fwdPk),
                '__review_status' => 'pending',
                '__proposal_meta' => ['action' => 'update', 'submitted_by' => 'tester'],
            ]),
            'resource_original' => array_merge($fwdPk, ['c_source' => 10, 'c_notes' => '舊注', 'c_autogen_notes' => 'auto-x']),
        ]);
        $operation->c_personid = 1000;
        $operation->save();

        $this->post(route('operations.proposals.approve', $operation), ['review_comment' => '核准'])
            ->assertRedirect();

        $this->assertSame(
            '清代族譜',
            DB::table('KIN_DATA')->where($fwdPk)->value('c_notes')
        );
        $this->assertSame(
            '清代族譜',
            DB::table('KIN_DATA')->where(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 101])->value('c_notes'),
            '鏡像列也要看到替換後的值'
        );
    }

    /**
     * review（HIGH）：核准時替換把 `c_text_title` 改成參考形之後，若已有一列「歸一後相同
     * 但字形不同」的資料，必須擋下——**不可靜默鑄出兩形並存的重複列**。
     *
     * 在這道守衛之前，同一筆輸入是乾淨的 1062（approve() 有專屬 QueryException catch）；
     * 加了替換卻不加守衛，就是把「乾淨拒絕」換成「靜默重複」。
     */
    #[Test]
    public function testApproveAssocCreateProposalBlocksVariantEquivalentDuplicate(): void {
        $this->seedCharVariantMap();
        // 既有列存變體形（D6 不做回溯校正）。
        DB::table('ASSOC_DATA')->insert([
            'c_personid' => 1000, 'c_assoc_code' => 1, 'c_assoc_id' => 2000, 'c_kin_code' => 0, 'c_kin_id' => 0,
            'c_assoc_kin_code' => 0, 'c_assoc_kin_id' => 0, 'c_text_title' => '淸書', 'c_assoc_first_year' => 1060, 'c_source' => 10,
        ]);
        $this->actingAs($this->makeAdmin());

        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'ASSOC_DATA',
            'resource_id' => '1000-1-2000-0-0-0-0-清書-1060',
            'resource_data' => [
                'c_personid' => 1000, 'c_assoc_code' => 1, 'c_assoc_id' => 2000,
                'c_kin_code' => 0, 'c_kin_id' => 0, 'c_assoc_kin_code' => 0, 'c_assoc_kin_id' => 0,
                'c_text_title' => '清書', 'c_assoc_first_year' => 1060, 'c_source' => 10,
                'c_assocship_pair' => 0, 'c_kinship_pair' => 0, 'c_assoc_kinship_pair' => 0,
                '__key_columns' => [
                    'c_personid', 'c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id',
                    'c_assoc_kin_code', 'c_assoc_kin_id', 'c_text_title', 'c_assoc_first_year',
                ],
                '__review_status' => 'pending',
                '__proposal_meta' => ['action' => 'create', 'submitted_by' => 'tester'],
            ],
        ]);
        $operation->c_personid = 1000;
        $operation->save();

        $this->post(route('operations.proposals.approve', $operation))->assertRedirect();

        $this->assertSame(1, DB::table('ASSOC_DATA')->count(), '不得鑄出第二列語義重複的關係');
        $this->assertSame('淸書', DB::table('ASSOC_DATA')->value('c_text_title'), '既有列不變（整筆回滾）');

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('pending', $payload['__review_status'] ?? null, '核准應失敗、提案維持 pending（不是被標成 rejected）');
    }

    /**
     * codex：delete 提案的兩形探測——目標列已被另一筆核准改名成參考形時，
     * 舊字形的定位器落空，必須用歸一後的 PK 再探一次並真的刪掉；
     * 不可當成「冪等成功」而寫下一筆沒發生的 DELETE 稽核。
     */
    #[Test]
    public function testApproveDeleteProposalFindsRowRenamedByVariantNormalization(): void {
        $this->seedCharVariantMap();

        // 現況：列已是**參考形**（被另一筆核准歸一過）。
        DB::table('ASSOC_DATA')->insert([
            'c_personid' => 1000, 'c_assoc_code' => 1, 'c_assoc_id' => 2000, 'c_kin_code' => 0, 'c_kin_id' => 0,
            'c_assoc_kin_code' => 0, 'c_assoc_kin_id' => 0, 'c_text_title' => '清書', 'c_assoc_first_year' => 1060, 'c_source' => 10,
        ]);
        $this->actingAs($this->makeAdmin());

        // 待審的 delete 提案仍指向**舊字形**。
        $oldPk = [
            'c_personid' => 1000, 'c_assoc_code' => 1, 'c_assoc_id' => 2000, 'c_kin_code' => 0, 'c_kin_id' => 0,
            'c_assoc_kin_code' => 0, 'c_assoc_kin_id' => 0, 'c_text_title' => '淸書', 'c_assoc_first_year' => 1060,
        ];
        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_DELETE,
            'resource' => 'ASSOC_DATA',
            'resource_id' => '1000-1-2000-0-0-0-0-淸書-1060',
            'resource_data' => array_merge($oldPk, [
                '__key_columns' => array_keys($oldPk),
                '__review_status' => 'pending',
                '__proposal_meta' => ['action' => 'delete', 'submitted_by' => 'tester'],
            ]),
            'resource_original' => array_merge($oldPk, ['c_source' => 10]),
        ]);
        $operation->c_personid = 1000;
        $operation->save();

        $this->post(route('operations.proposals.approve', $operation), ['review_comment' => '核准'])
            ->assertRedirect();

        $this->assertSame(0, DB::table('ASSOC_DATA')->count(), '歸一後的那一列才是目標，必須真的被刪除');

        // codex：最終 DELETE operation 的 resource_id 必須跟著**實際刪除的列**（參考形），
        // 否則同一筆稽核的 resource_id（舊字形）與 resource_data／audit row_pk（新字形）互相矛盾。
        $deleted = DB::table('operations')
            ->where('resource', 'ASSOC_DATA')
            ->where('op_type', Operation::TYPE_DELETE)
            ->latest('id')
            ->first();
        $this->assertNotNull($deleted);
        $this->assertStringContainsString('清書', urldecode((string) $deleted->resource_id));
        $this->assertStringNotContainsString('淸書', urldecode((string) $deleted->resource_id));

        // 快照（resource_data／resource_original）與 audit row_pk 也必須是同一個字形，
        // 否則「還原這筆刪除」會以舊字形重建一列不存在過的資料。
        $deletedPayload = json_decode((string) $deleted->resource_data, true);
        $this->assertSame('清書', $deletedPayload['c_text_title'] ?? null);
        $deletedOriginal = json_decode((string) $deleted->resource_original, true);
        $this->assertSame('清書', $deletedOriginal['c_text_title'] ?? null);

        $auditDelete = DB::table('audit_log')
            ->where('table_name', 'ASSOC_DATA')
            ->where('operation', 'DELETE')
            ->latest('id')
            ->first();
        $this->assertNotNull($auditDelete);
        $this->assertStringContainsString('清書', (string) $auditDelete->row_pk);
        $this->assertStringNotContainsString('淸書', (string) $auditDelete->row_pk);
    }

    /**
     * codex：D7 守衛在 update 側必須排除自己、且**只在真的改鍵時**檢查——
     * 既有資料可能早就兩形並存，只改 c_notes 是合法操作，不可被擋成「永遠改不了」。
     */
    #[Test]
    public function testApproveAssocUpdateOfNonKeyFieldIsNotBlockedByCoexistingVariantForms(): void {
        $this->seedCharVariantMap();

        // 兩列**只有 c_text_title 的字形不同**、歸一後相同——這才是「歷史上就已兩形並存」，
        // 也才是無條件檢查會誤擋的形狀（其餘 PK 欄都相同，所以互為等價候選）。
        DB::table('ASSOC_DATA')->insert([
            ['c_personid' => 1000, 'c_assoc_code' => 1, 'c_assoc_id' => 2000, 'c_kin_code' => 0, 'c_kin_id' => 0,
                'c_assoc_kin_code' => 0, 'c_assoc_kin_id' => 0, 'c_text_title' => '清書', 'c_assoc_first_year' => 1060, 'c_source' => 10],
            ['c_personid' => 1000, 'c_assoc_code' => 1, 'c_assoc_id' => 2000, 'c_kin_code' => 0, 'c_kin_id' => 0,
                'c_assoc_kin_code' => 0, 'c_assoc_kin_id' => 0, 'c_text_title' => '淸書', 'c_assoc_first_year' => 1060, 'c_source' => 10],
        ]);
        $this->actingAs($this->makeAdmin());

        $fwdPk = [
            'c_personid' => 1000, 'c_assoc_code' => 1, 'c_assoc_id' => 2000, 'c_kin_code' => 0, 'c_kin_id' => 0,
            'c_assoc_kin_code' => 0, 'c_assoc_kin_id' => 0, 'c_text_title' => '清書', 'c_assoc_first_year' => 1060,
        ];
        $operation = $this->proposalOperation([
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'ASSOC_DATA',
            'resource_id' => '1000-1-2000-0-0-0-0-清書-1060',
            'resource_data' => array_merge($fwdPk, [
                'c_source' => 10, 'c_notes' => '只改備註',
                'c_assocship_pair' => 0, 'c_kinship_pair' => 0, 'c_assoc_kinship_pair' => 0,
                '__key_columns' => array_keys($fwdPk),
                '__review_status' => 'pending',
                '__proposal_meta' => ['action' => 'update', 'submitted_by' => 'tester'],
            ]),
            'resource_original' => array_merge($fwdPk, ['c_source' => 10]),
        ]);
        $operation->c_personid = 1000;
        $operation->save();

        $this->post(route('operations.proposals.approve', $operation), ['review_comment' => '核准'])
            ->assertRedirect();

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status'] ?? null, '沒有改鍵 ⇒ 不該被 D7 擋下');
        $this->assertSame(
            '只改備註',
            DB::table('ASSOC_DATA')->where('c_personid', 1000)->where('c_text_title', '清書')->value('c_notes')
        );
        $this->assertNull(
            DB::table('ASSOC_DATA')->where('c_text_title', '淸書')->value('c_notes'),
            '另一形那列不該被動到'
        );
    }
}
