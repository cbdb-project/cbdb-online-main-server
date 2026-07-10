<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
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
    }

    protected function tearDown(): void {
        Schema::dropIfExists('BIOG_MAIN');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('pinyin');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    protected function makeActiveUser(): User {
        return User::create([
            'name' => 'activeuser',
            'email' => 'active@example.com',
            'is_active' => 1,
            'is_admin' => 0,
        ]);
    }

    protected function makeAdmin(): User {
        return User::create([
            'name' => 'admin',
            'email' => 'admin@example.com',
            'is_active' => 1,
            'is_admin' => 1,
        ]);
    }

    #[Test]
    public function testBiogMainUpdateWithProposalActionCreatesProposal() {
        $user = $this->makeActiveUser();
        $this->actingAs($user);

        $personId = 1;
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => $personId,
            'c_name_chn' => '張三',
            'c_notes' => 'Original notes',
        ]);

        $response = $this->patch(route('basicinformation.update', $personId), [
            'action' => 'proposal',
            'c_surname_chn' => '張',
            'c_mingzi_chn' => '三',
            'c_notes' => 'Proposed notes',
            '__proposal_comment' => '修改個人簡介',
        ]);

        $response->assertRedirect(route('basicinformation.edit', $personId));
        $flash = session('flash_notification', collect())->toArray();
        $this->assertNotEmpty($flash);
        $this->assertStringContainsString('已提交修改提案', $flash[0]['message'] ?? '');

        $this->assertDatabaseHas('operations', [
            'user_id' => $user->id,
            'c_personid' => $personId,
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource' => 'BIOG_MAIN',
            'resource_id' => \App\Support\CompositePrimaryKey::buildStoredResourceId(['c_personid' => $personId]),
        ]);

        $operation = Operation::where('resource', 'BIOG_MAIN')->first();
        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('Proposed notes', $payload['c_notes']);
        $this->assertSame('pending', $payload['__review_status']);
        $this->assertSame('修改個人簡介', $payload['__proposal_meta']['comment']);

        // 驗證數據庫原始資料未變更
        $this->assertSame('Original notes', DB::table('BIOG_MAIN')->where('c_personid', $personId)->value('c_notes'));
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
        // 核准端「不可清空」守衛：payload 把名（中）寫成空、而該列當下有值 → 拒絕核准、資料不變、提案維持 pending。
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
        $this->assertStringContainsString('清空既有的「名（中）」', $flash[0]['message'] ?? '');

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
    public function testBiogMainProposalDoesNotRewriteMingziFieldsWhenNotChanged() {
        $user = $this->makeActiveUser();
        $this->actingAs($user);

        $personId = 3;
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => $personId,
            'c_name_chn' => '歐陽修',
            'c_name' => 'Ouyang Xiu',
            'c_surname_chn' => '歐陽',
            'c_surname' => 'Ouyang',
            'c_mingzi_chn' => '修',
            'c_mingzi' => 'Xiu',
            'c_notes' => 'Original notes',
        ]);

        DB::table('pinyin')->insert([
            'c_chn' => '李',
            'c_pinyin' => 'Li',
            'c_lastname' => 1,
        ]);

        $response = $this->patch(route('basicinformation.update', $personId), [
            'action' => 'proposal',
            'c_surname_chn' => '歐陽',
            'c_mingzi_chn' => '修',
            'c_surname' => 'Ouyang',
            'c_mingzi' => 'Xiu',
            'c_name_chn' => '歐陽修',
            'c_name' => 'Ouyang Xiu',
            'c_notes' => 'Only notes changed',
            '__proposal_comment' => '僅修改註解',
        ]);

        $response->assertRedirect(route('basicinformation.edit', $personId));

        $operation = Operation::where('resource', 'BIOG_MAIN')
            ->where('c_personid', $personId)
            ->latest('id')
            ->first();

        $this->assertNotNull($operation);
        $payload = json_decode($operation->resource_data, true);

        $this->assertSame('修', $payload['c_mingzi_chn']);
        $this->assertSame('Xiu', $payload['c_mingzi']);
        $this->assertSame('歐陽修', $payload['c_name_chn']);
        $this->assertSame('Ouyang Xiu', $payload['c_name']);
    }
}
