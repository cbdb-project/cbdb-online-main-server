<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProposalNormalizationTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        // 使用 SQLite 記憶體資料庫進行測試
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->boolean('is_active')->default(0);
            $table->boolean('is_admin')->default(0);
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
            $table->timestamps();
        });

        Schema::create('audit_log', function (Blueprint $table) {
            $table->increments('id');
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');
            $table->string('table_name');
            $table->string('operation');
            $table->string('actor_type');
            $table->string('actor_id');
            $table->string('operation_id');
            $table->text('row_pk')->nullable();
            $table->text('row_pk_text')->nullable();
            $table->longText('old_data')->nullable();
            $table->longText('new_data')->nullable();
        });

        Schema::create('ASSOC_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_assoc_code');
            $table->integer('c_assoc_id');
            $table->integer('c_kin_code')->default(0);
            $table->integer('c_kin_id')->default(0);
            $table->integer('c_assoc_kin_code')->default(0);
            $table->integer('c_assoc_kin_id')->default(0);
            $table->string('c_text_title')->default('');
            $table->integer('c_assoc_first_year')->default(-9999);
            $table->string('c_notes')->nullable();
        });

        Schema::create('ENTRY_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_entry_code');
            $table->integer('c_sequence');
            $table->integer('c_kin_code')->default(0);
            $table->integer('c_assoc_code')->default(0);
            $table->integer('c_kin_id')->default(0);
            $table->integer('c_year')->default(0);
            $table->integer('c_assoc_id')->default(0);
            $table->integer('c_inst_code')->default(0);
            $table->integer('c_inst_name_code')->default(0);
            $table->integer('c_source')->default(0);
            $table->integer('c_entry_addr_id')->default(0);
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });
    }

    protected function makeActiveUser(): User {
        return User::create([
            'name' => 'activeuser',
            'email' => 'active@example.com',
            'is_active' => 1,
            'is_admin' => 0,
        ]);
    }

    /**
     * 驗證 P2: ASSOC_DATA 提案正確處理空字串 c_text_title
     */
    #[Test]
    public function testAssocProposalWithEmptyTextTitle() {
        $user = $this->makeActiveUser();
        $this->actingAs($user);

        // 插入一條 c_text_title 為空字串的原始資料
        DB::table('ASSOC_DATA')->insert([
            'c_personid' => 100,
            'c_assoc_code' => 1,
            'c_assoc_id' => 2,
            'c_text_title' => '', // 空字串
            'c_assoc_first_year' => 1000,
        ]);

        // 模擬修改提案
        // URL 包含 c_text_title= (空字串)
        $response = $this->patch(route('basicinformation.assoc.update.query', [
            'id' => 100,
            'action' => 'proposal',
            'c_personid' => 100,
            'c_assoc_code' => 1,
            'c_assoc_id' => 2,
            'c_kin_code' => 0,
            'c_kin_id' => 0,
            'c_assoc_kin_code' => 0,
            'c_assoc_kin_id' => 0,
            'c_text_title' => '', // 關鍵：空字串 PK 欄位
            'c_assoc_first_year' => 1000,
        ]), [
            'c_notes' => '更新後的備註',
            '__proposal_comment' => '測試空字串 PK',
        ]);

        $response->assertRedirect();

        // 驗證提案是否成功建立，且 c_text_title 被正確識別
        $operation = Operation::where('resource', 'ASSOC_DATA')->first();
        $this->assertNotNull($operation, '提案未建立，可能是 PK 匹配失敗');

        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('更新後的備註', $payload['c_notes']);

        // 驗證 resource_id 包含 c_text_title 的空字串 (應被編碼為 NULL 或空，取決於 buildCompositeId)
        // 在 BasicInformationProposalController 中，'' 或 null 會被轉為 'NULL'
        $this->assertStringContainsString('NULL', $operation->resource_id);
    }

    /**
     * 驗證 ASSOC_DATA 新增提案允許空字串 c_text_title
     */
    #[Test]
    public function testAssocCreateProposalAllowsEmptyTextTitle() {
        $user = $this->makeActiveUser();
        $this->actingAs($user);

        $response = $this->post(route('basicinformation.proposal.store', [
            'personid' => 100,
            'resource' => 'assoc',
        ]), [
            'c_assoc_code' => 1,
            'c_assoc_id' => 2,
            'c_kin_code' => 0,
            'c_kin_id' => 0,
            'c_assoc_kin_code' => 0,
            'c_assoc_kin_id' => 0,
            'c_text_title' => '',
            'c_assoc_first_year' => 1000,
            '__proposal_comment' => '空標題提案',
        ]);

        $response->assertRedirect();

        $operation = Operation::where('resource', 'ASSOC_DATA')
            ->where('op_type', Operation::TYPE_PROPOSAL_CREATE)
            ->latest('id')
            ->first();
        $this->assertNotNull($operation, 'ASSOC_DATA 空標題新增提案未建立');
    }

    /**
     * 驗證 P1: ENTRY_DATA 提案正確處理合併後的 c_inst_code (123-4)
     */
    #[Test]
    public function testEntryProposalWithCombinedInstitutionId() {
        $user = $this->makeActiveUser();
        $this->actingAs($user);

        // 測試新增提案，提供所有必要的 PK 欄位
        $response = $this->post(route('basicinformation.entries.store', [
            'basicinformation' => 100,
            'action' => 'proposal',
        ]), [
            'c_entry_code' => 1,
            'c_sequence' => 1,
            'c_kin_code' => 0,
            'c_assoc_code' => 0,
            'c_kin_id' => 0,
            'c_year' => 0,
            'c_assoc_id' => 0,
            'c_inst_code' => '123-4', // 關鍵：合併格式
            '__proposal_comment' => '測試合併機構 ID',
        ]);

        $response->assertRedirect();

        $operation = Operation::where('resource', 'ENTRY_DATA')->first();
        $this->assertNotNull($operation, '提案未建立，可能是 PK 驗證失敗');

        $payload = json_decode($operation->resource_data, true);
        // 驗證機構 ID 是否已被正確分割
        $this->assertEquals('123', $payload['c_inst_code']);
        $this->assertEquals('4', $payload['c_inst_name_code']);
    }

    /**
     * 驗證 -999 轉為 0 的正規化
     */
    #[Test]
    public function testEntryProposalWithNegative999Normalization() {
        $user = $this->makeActiveUser();
        $this->actingAs($user);

        // 測試新增提案，提供所有必要的 PK 欄位
        $response = $this->post(route('basicinformation.entries.store', [
            'basicinformation' => 100,
            'action' => 'proposal',
        ]), [
            'c_entry_code' => 1,
            'c_sequence' => 1,
            'c_kin_code' => 0,
            'c_assoc_code' => 0,
            'c_kin_id' => 0,
            'c_year' => 0,
            'c_assoc_id' => 0,
            'c_inst_code' => '0-0',
            'c_source' => -999, // 關鍵：-999 應轉為 0
            '__proposal_comment' => '測試 -999 正規化',
        ]);

        $response->assertRedirect();

        $operation = Operation::where('resource', 'ENTRY_DATA')->first();
        $this->assertNotNull($operation, '提案未建立，可能是 PK 驗證失敗');

        $payload = json_decode($operation->resource_data, true);
        $this->assertEquals('0', $payload['c_source']);
    }

    /**
     * 驗證 ENTRY_DATA 直接儲存會忽略 __proposal_comment 欄位
     */
    #[Test]
    public function testEntryDirectSaveIgnoresProposalCommentField() {
        $user = $this->makeActiveUser();
        $this->actingAs($user);

        $response = $this->post(route('basicinformation.entries.store', [
            'basicinformation' => 100,
            'action' => 'save',
        ]), [
            'action' => 'save',
            'c_entry_code' => 1,
            'c_sequence' => 1,
            'c_kin_code' => 0,
            'c_assoc_code' => 0,
            'c_kin_id' => 0,
            'c_year' => 0,
            'c_assoc_id' => 0,
            'c_inst_code' => '0-0',
            'c_source' => 0,
            '__proposal_comment' => '這個欄位不應寫入 ENTRY_DATA',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('ENTRY_DATA', [
            'c_personid' => 100,
            'c_entry_code' => 1,
            'c_sequence' => 1,
            'c_inst_code' => 0,
            'c_inst_name_code' => 0,
        ]);
    }
}
