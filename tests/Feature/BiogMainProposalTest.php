<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use App\Services\CharVariantMapService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BiogMainProposalTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

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

        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
            $table->string('c_surname_chn')->nullable();
            $table->string('c_surname')->nullable();
            $table->string('c_mingzi_chn')->nullable();
            $table->string('c_mingzi')->nullable();
            $table->string('c_name_proper')->nullable();
            $table->string('c_name_rm')->nullable();
            $table->string('c_surname_proper')->nullable();
            $table->string('c_mingzi_proper')->nullable();
            $table->string('c_surname_rm')->nullable();
            $table->string('c_mingzi_rm')->nullable();
            $table->text('c_notes')->nullable();
            $table->integer('c_female')->default(0);
            $table->integer('c_by_intercalary')->default(0);
            $table->integer('c_dy_intercalary')->default(0);
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
        });

        // audit_log 表
        Schema::create('audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');
            $table->string('table_name', 64);
            $table->string('operation', 16);
            $table->string('actor_type', 32);
            $table->string('actor_id', 128);
            $table->char('operation_id', 26);
            $table->json('row_pk');
            $table->string('row_pk_text', 512);
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
        });

        Schema::create('pinyin', function (Blueprint $table) {
            $table->increments('id');
            $table->string('c_chn');
            $table->string('c_pinyin')->nullable();
            $table->tinyInteger('c_lastname')->default(0);
            $table->unique(['c_chn', 'c_lastname']);
        });

        // char_variant_map：與 database/migrations/2026_07_15_000000_create_char_variant_map_table.php
        // 相同的 7 筆種子資料，供 legacy Blade 提案路徑（BasicInformationProposalController::
        // normalizePayloadForTable()）的異體字落地替換測試使用。
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
            ['c_variant_char' => '愼', 'c_reference_char' => '慎', 'c_strict_excluded' => 0],
            ['c_variant_char' => '槀', 'c_reference_char' => '稿', 'c_strict_excluded' => 0],
            ['c_variant_char' => '峯', 'c_reference_char' => '峰', 'c_strict_excluded' => 1],
            ['c_variant_char' => '靑', 'c_reference_char' => '青', 'c_strict_excluded' => 0],
            ['c_variant_char' => '頴', 'c_reference_char' => '穎', 'c_strict_excluded' => 0],
            ['c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
            ['c_variant_char' => '厰', 'c_reference_char' => '廠', 'c_strict_excluded' => 0],
        ]);
        CharVariantMapService::reset();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('BIOG_MAIN');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('pinyin');
        Schema::dropIfExists('char_variant_map');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    protected function makeActiveUser(): User {
        return User::forceCreate([
            'name' => 'activeuser',
            'email' => 'active@example.com',
            'is_active' => 1,
            'is_admin' => 0,
        ]);
    }

    protected function makeAdmin(): User {
        return User::forceCreate([
            'name' => 'admin',
            'email' => 'admin@example.com',
            'is_active' => 1,
            'is_admin' => 1,
        ]);
    }

    #[Test]
    public function testApproveBiogMainProposalUpdatesTable() {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $personId = 2;
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => $personId,
            'c_name_chn' => '李四',
            'c_notes' => 'Old notes',
        ]);

        $resourceData = [
            'c_personid' => $personId,
            'c_name_chn' => '李四',
            'c_notes' => 'New notes',
            '__key_columns' => ['c_personid'],
            '__review_status' => 'pending',
        ];

        $operation = Operation::create([
            'user_id' => 100,
            'c_personid' => $personId,
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'BIOG_MAIN',
            'resource_id' => (string)$personId,
            'resource_data' => json_encode($resourceData),
            'resource_original' => json_encode([
                'c_personid' => $personId,
                'c_name_chn' => '李四',
                'c_notes' => 'Old notes',
            ]),
        ]);

        $response = $this->post(route('operations.proposals.approve', $operation), [
            'review_comment' => 'Approve biog main update',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('BIOG_MAIN', [
            'c_personid' => $personId,
            'c_notes' => 'New notes',
        ]);

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status']);

        // 驗證審計日誌
        $this->assertDatabaseHas('audit_log', [
            'table_name' => 'BIOG_MAIN',
            'operation' => 'UPDATE',
        ]);
    }

    #[Test]
    public function testApproveRejectsProposalThatWouldClearExistingMingzi() {
        // 「不可清空」語義（核准＝重放 BiogMainMutationHandler direct）：payload 把名（中）寫成空、
        // 而該列當下有值 → handler 驗證擋下（名不能為空）、資料不變、提案維持 pending。
        // 模擬「提交端驗證修復前的存量 pending 提案」與 legacy 路徑提交的提案。
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $personId = 4;
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => $personId,
            'c_name_chn' => '王五',
            'c_surname_chn' => '王',
            'c_mingzi_chn' => '五',
            'c_mingzi' => 'Wu',
            'c_notes' => 'Old notes',
        ]);

        $operation = Operation::create([
            'user_id' => 100,
            'c_personid' => $personId,
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'BIOG_MAIN',
            'resource_id' => (string) $personId,
            'resource_data' => json_encode([
                'c_personid' => $personId,
                'c_surname_chn' => '王',
                'c_mingzi_chn' => '',
                'c_name_chn' => '王',
                'c_notes' => 'New notes',
                '__key_columns' => ['c_personid'],
                '__review_status' => 'pending',
            ]),
            'resource_original' => json_encode([
                'c_personid' => $personId,
                'c_surname_chn' => '王',
                'c_mingzi_chn' => '五',
                'c_notes' => 'Old notes',
            ]),
        ]);

        $response = $this->post(route('operations.proposals.approve', $operation), [
            'review_comment' => 'try approve',
        ]);

        $response->assertRedirect();
        $flash = session('flash_notification', collect())->toArray();
        $this->assertStringContainsString('名不能為空', $flash[0]['message'] ?? '');

        // 資料未變、提案未被標記 approved（交易整筆回滾）。
        $this->assertDatabaseHas('BIOG_MAIN', [
            'c_personid' => $personId,
            'c_mingzi_chn' => '五',
            'c_notes' => 'Old notes',
        ]);
        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('pending', $payload['__review_status']);
        $this->assertDatabaseCount('audit_log', 0);
    }

    #[Test]
    public function testApproveAllowsProposalKeepingMingziEmptyWhenRowEmpty() {
        // 守衛的另一半：該列名（中）當下即為空，提案維持空、只改其他欄位 → 照常核准。
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $personId = 5;
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => $personId,
            'c_name_chn' => '趙',
            'c_surname_chn' => '趙',
            'c_mingzi_chn' => '',
            'c_notes' => 'Old notes',
        ]);

        $operation = Operation::create([
            'user_id' => 100,
            'c_personid' => $personId,
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'BIOG_MAIN',
            'resource_id' => (string) $personId,
            'resource_data' => json_encode([
                'c_personid' => $personId,
                'c_surname_chn' => '趙',
                'c_mingzi_chn' => '',
                'c_notes' => 'New notes',
                '__key_columns' => ['c_personid'],
                '__review_status' => 'pending',
            ]),
            'resource_original' => json_encode([
                'c_personid' => $personId,
                'c_surname_chn' => '趙',
                'c_mingzi_chn' => '',
                'c_notes' => 'Old notes',
            ]),
        ]);

        $this->post(route('operations.proposals.approve', $operation), [
            'review_comment' => 'ok',
        ])->assertRedirect();

        $this->assertDatabaseHas('BIOG_MAIN', [
            'c_personid' => $personId,
            'c_mingzi_chn' => '',
            'c_notes' => 'New notes',
        ]);
        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status']);
    }

    #[Test]
    public function testApproveBiogMainDeleteProposalSoftDeletesInsteadOfPhysicalDelete() {
        // BIOG_MAIN 刪除提案核准＝重放 BiogMainDeleteHandler（軟刪除：c_name_chn='<待删除>' 的 UPDATE）。
        // 收斂前通用 applyDeleteProposal() 會對 BIOG_MAIN 做物理 DELETE——與 direct 語義相反，
        // 且在入邊 FK 尚為 CASCADE 期間會靜默連鎖刪除子表資料。
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        $personId = 6;
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => $personId,
            'c_name_chn' => '孫六',
            'c_notes' => 'Some notes',
        ]);

        $operation = Operation::create([
            'user_id' => 100,
            'c_personid' => $personId,
            'op_type' => Operation::TYPE_PROPOSAL_DELETE,
            'resource' => 'BIOG_MAIN',
            'resource_id' => (string) $personId,
            'resource_data' => json_encode([
                'c_personid' => $personId,
                'c_name_chn' => '孫六',
                '__key_columns' => ['c_personid'],
                '__review_status' => 'pending',
            ]),
            'resource_original' => json_encode([
                'c_personid' => $personId,
                'c_name_chn' => '孫六',
                'c_notes' => 'Some notes',
            ]),
        ]);

        $this->post(route('operations.proposals.approve', $operation), [
            'review_comment' => 'ok to delete',
        ])->assertRedirect();

        // 原列仍在（軟刪除），僅改名為刪除標記；notes 等其他欄位不動。
        $this->assertDatabaseHas('BIOG_MAIN', [
            'c_personid' => $personId,
            'c_name_chn' => '<待删除>',
            'c_notes' => 'Some notes',
        ]);

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status']);

        // handler 自寫 op_type=4（TYPE_DELETE）final operation 與 audit（operation='UPDATE'，軟刪除語義）。
        $this->assertDatabaseHas('operations', [
            'c_personid' => $personId,
            'op_type' => Operation::TYPE_DELETE,
            'resource' => 'BIOG_MAIN',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'table_name' => 'BIOG_MAIN',
            'operation' => 'UPDATE',
        ]);
    }

    #[Test]
    public function testApproveBiogMainCreateProposalRejectedWhenPersonIdExists() {
        // BIOG_MAIN create 提案核准＝重放 BiogMainCreateHandler：c_personid 已存在 → fail-closed，
        // 不再走收斂前的盲 Eloquent create。
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 7,
            'c_name_chn' => '既有人物',
        ]);

        $operation = Operation::create([
            'user_id' => 100,
            'c_personid' => 7,
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'BIOG_MAIN',
            'resource_id' => '7',
            'resource_data' => json_encode([
                'c_personid' => 7,
                'c_surname_chn' => '錢',
                'c_mingzi_chn' => '七',
                'c_mingzi' => 'Qi',
                '__key_columns' => ['c_personid'],
                '__review_status' => 'pending',
            ]),
        ]);

        $this->post(route('operations.proposals.approve', $operation), [
            'review_comment' => 'try approve',
        ])->assertRedirect();

        $flash = session('flash_notification', collect())->toArray();
        $this->assertStringContainsString('審核失敗', $flash[0]['message'] ?? '');

        // 既有列未被覆寫；提案維持 pending。
        $this->assertDatabaseHas('BIOG_MAIN', [
            'c_personid' => 7,
            'c_name_chn' => '既有人物',
        ]);
        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('pending', $payload['__review_status']);
    }

    #[Test]
    public function testApproveBiogMainCreateProposalCreatesViaHandler() {
        // create 提案核准成功路徑：經 BiogMainCreateHandler 白名單＋c_personid 驗證後由
        // repository store 落庫（事務＋operation＋audit）。
        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 1,
            'c_name_chn' => '既有人物',
        ]);

        $operation = Operation::create([
            'user_id' => 100,
            'c_personid' => 8,
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'BIOG_MAIN',
            'resource_id' => '8',
            'resource_data' => json_encode([
                'c_personid' => 8,
                'c_surname_chn' => '錢',
                'c_mingzi_chn' => '八',
                'c_surname' => 'Qian',
                'c_mingzi' => 'Ba',
                'c_notes' => 'Created via proposal',
                '__key_columns' => ['c_personid'],
                '__review_status' => 'pending',
            ]),
        ]);

        $this->post(route('operations.proposals.approve', $operation), [
            'review_comment' => 'ok to create',
        ])->assertRedirect();

        $this->assertDatabaseHas('BIOG_MAIN', [
            'c_personid' => 8,
            'c_name_chn' => '錢八',
            'c_notes' => 'Created via proposal',
        ]);

        $operation->refresh();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('approved', $payload['__review_status']);

        // handler 自寫 op_type=1（TYPE_CREATE）final operation 與 INSERT audit。
        $this->assertDatabaseHas('operations', [
            'c_personid' => 8,
            'op_type' => Operation::TYPE_CREATE,
            'resource' => 'BIOG_MAIN',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'table_name' => 'BIOG_MAIN',
            'operation' => 'INSERT',
        ]);
    }
}
