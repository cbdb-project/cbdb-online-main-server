<?php

namespace Tests\Feature;

use App\BiogMain;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnidirectionalRelationshipRepairControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        // 創建管理員用戶
        $this->adminUser = User::factory()->create([
            'is_admin' => 1,
            'c_user_privilege' => 'batch_import',
        ]);

        // 創建普通用戶
        $this->regularUser = User::factory()->create([
            'is_admin' => 0,
            'c_user_privilege' => 'normal',
        ]);
    }

    /** @test */
    public function guest_cannot_access_repair_page()
    {
        $response = $this->get(route('admin.unidirectional-relationship-repair'));

        $response->assertRedirect(route('login'));
    }

    /** @test */
    public function regular_user_cannot_access_repair_page()
    {
        $response = $this->actingAs($this->regularUser)
            ->get(route('admin.unidirectional-relationship-repair'));

        $response->assertStatus(403);
    }

    /** @test */
    public function admin_can_access_repair_page()
    {
        $response = $this->actingAs($this->adminUser)
            ->get(route('admin.unidirectional-relationship-repair'));

        $response->assertStatus(200);
        $response->assertSee('單向關係修復');
        $response->assertSee('親屬關係修復');
        $response->assertSee('社會關係修復');
    }

    /** @test */
    public function kinship_repair_validates_required_fields()
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.kinship'), []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['c_personid', 'c_kin_id', 'c_kin_code', 'new_c_kin_code']);
    }

    /** @test */
    public function kinship_repair_returns_error_when_record_not_found()
    {
        // 創建測試人物
        $person1 = BiogMain::factory()->create();
        $person2 = BiogMain::factory()->create();

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.kinship'), [
                'c_personid' => $person1->c_personid,
                'c_kin_id' => $person2->c_personid,
                'c_kin_code' => 999,
                'new_c_kin_code' => 998,
            ]);

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => '未找到符合條件的親屬關係記錄。',
        ]);
    }

    /** @test */
    public function kinship_repair_returns_error_when_multiple_records_found()
    {
        // 創建測試人物
        $person1 = BiogMain::factory()->create();
        $person2 = BiogMain::factory()->create();

        // 創建兩條相同的親屬關係記錄（模擬重複資料）
        DB::table('KIN_DATA')->insert([
            [
                'c_personid' => $person1->c_personid,
                'c_kin_id' => $person2->c_personid,
                'c_kin_code' => 2,
            ],
            [
                'c_personid' => $person1->c_personid,
                'c_kin_id' => $person2->c_personid,
                'c_kin_code' => 2,
            ],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.kinship'), [
                'c_personid' => $person1->c_personid,
                'c_kin_id' => $person2->c_personid,
                'c_kin_code' => 2,
                'new_c_kin_code' => 303,
            ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
        ]);
        $response->assertJsonFragment([
            'message' => '檢索到多條記錄（2 條），請檢查輸入參數是否正確。',
        ]);
    }

    /** @test */
    public function kinship_repair_returns_error_when_reverse_already_exists()
    {
        // 創建測試人物
        $person1 = BiogMain::factory()->create();
        $person2 = BiogMain::factory()->create();

        // 創建原始關係：person1 是 person2 的親屬（關係代碼 2）
        DB::table('KIN_DATA')->insert([
            'c_personid' => $person1->c_personid,
            'c_kin_id' => $person2->c_personid,
            'c_kin_code' => 2,
        ]);

        // 創建反向關係：person2 是 person1 的親屬（關係代碼 303）
        DB::table('KIN_DATA')->insert([
            'c_personid' => $person2->c_personid,
            'c_kin_id' => $person1->c_personid,
            'c_kin_code' => 303,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.kinship'), [
                'c_personid' => $person1->c_personid,
                'c_kin_id' => $person2->c_personid,
                'c_kin_code' => 2,
                'new_c_kin_code' => 303,
            ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'message' => '反向關係已存在，無需創建。',
        ]);
    }

    /** @test */
    public function kinship_repair_successfully_creates_reverse_relationship()
    {
        // 創建測試人物
        $person1 = BiogMain::factory()->create();
        $person2 = BiogMain::factory()->create();

        // 創建原始關係：person1 是 person2 的親屬（關係代碼 2）
        DB::table('KIN_DATA')->insert([
            'c_personid' => $person1->c_personid,
            'c_kin_id' => $person2->c_personid,
            'c_kin_code' => 2,
            'c_source' => 100,
            'c_pages' => '10-15',
            'c_notes' => '原始備註',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.kinship'), [
                'c_personid' => $person1->c_personid,
                'c_kin_id' => $person2->c_personid,
                'c_kin_code' => 2,
                'new_c_kin_code' => 303,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => '親屬關係修復成功！已創建反向關係記錄。',
            'original' => [
                'c_personid' => $person1->c_personid,
                'c_kin_id' => $person2->c_personid,
                'c_kin_code' => 2,
            ],
            'created' => [
                'c_personid' => $person2->c_personid,
                'c_kin_id' => $person1->c_personid,
                'c_kin_code' => 303,
            ],
        ]);

        // 驗證反向關係已創建
        $this->assertDatabaseHas('KIN_DATA', [
            'c_personid' => $person2->c_personid,
            'c_kin_id' => $person1->c_personid,
            'c_kin_code' => 303,
            'c_source' => 100,
            'c_pages' => '10-15',
            'c_notes' => '原始備註',
        ]);

        // 驗證自動生成備註
        $reverseRecord = DB::table('KIN_DATA')
            ->where('c_personid', $person2->c_personid)
            ->where('c_kin_id', $person1->c_personid)
            ->where('c_kin_code', 303)
            ->first();

        $this->assertStringContainsString('由單向關係修復工具自動創建', $reverseRecord->c_autogen_notes);
        $this->assertNotEmpty($reverseRecord->c_created_by);
        $this->assertNotEmpty($reverseRecord->c_created_date);
    }

    /** @test */
    public function assoc_repair_validates_required_fields()
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.assoc'), []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['c_personid', 'c_assoc_id', 'c_assoc_code', 'new_c_assoc_code']);
    }

    /** @test */
    public function assoc_repair_returns_error_when_record_not_found()
    {
        // 創建測試人物
        $person1 = BiogMain::factory()->create();
        $person2 = BiogMain::factory()->create();

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.assoc'), [
                'c_personid' => $person1->c_personid,
                'c_assoc_id' => $person2->c_personid,
                'c_assoc_code' => 999,
                'new_c_assoc_code' => 998,
            ]);

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => '未找到符合條件的社會關係記錄。',
        ]);
    }

    /** @test */
    public function assoc_repair_successfully_creates_reverse_relationship()
    {
        // 創建測試人物
        $person1 = BiogMain::factory()->create();
        $person2 = BiogMain::factory()->create();

        // 創建原始社會關係
        DB::table('ASSOC_DATA')->insert([
            'c_personid' => $person1->c_personid,
            'c_assoc_id' => $person2->c_personid,
            'c_assoc_code' => 4,
            'c_kin_code' => 0,
            'c_kin_id' => 0,
            'c_assoc_kin_code' => 0,
            'c_assoc_kin_id' => 0,
            'c_text_title' => '測試文獻',
            'c_assoc_first_year' => 1000,
            'c_inst_code' => 0,
            'c_inst_name_code' => 0,
            'c_source' => 200,
            'c_pages' => '20-25',
            'c_notes' => '社會關係備註',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.assoc'), [
                'c_personid' => $person1->c_personid,
                'c_assoc_id' => $person2->c_personid,
                'c_assoc_code' => 4,
                'new_c_assoc_code' => 5,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => '社會關係修復成功！已創建反向關係記錄。',
            'original' => [
                'c_personid' => $person1->c_personid,
                'c_assoc_id' => $person2->c_personid,
                'c_assoc_code' => 4,
            ],
            'created' => [
                'c_personid' => $person2->c_personid,
                'c_assoc_id' => $person1->c_personid,
                'c_assoc_code' => 5,
            ],
        ]);

        // 驗證反向關係已創建
        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => $person2->c_personid,
            'c_assoc_id' => $person1->c_personid,
            'c_assoc_code' => 5,
            'c_text_title' => '測試文獻',
            'c_assoc_first_year' => 1000,
            'c_source' => 200,
            'c_pages' => '20-25',
            'c_notes' => '社會關係備註',
        ]);

        // 驗證創建資訊
        $reverseRecord = DB::table('ASSOC_DATA')
            ->where('c_personid', $person2->c_personid)
            ->where('c_assoc_id', $person1->c_personid)
            ->where('c_assoc_code', 5)
            ->first();

        $this->assertNotEmpty($reverseRecord->c_created_by);
        $this->assertNotEmpty($reverseRecord->c_created_date);
    }

    /** @test */
    public function kinship_repair_uses_database_transaction()
    {
        // 創建測試人物
        $person1 = BiogMain::factory()->create();
        $person2 = BiogMain::factory()->create();

        // 創建原始關係
        DB::table('KIN_DATA')->insert([
            'c_personid' => $person1->c_personid,
            'c_kin_id' => $person2->c_personid,
            'c_kin_code' => 3,
        ]);

        // 模擬資料庫錯誤（使用不存在的關係代碼，會導致外鍵約束失敗）
        // 注意：此測試假設 KINSHIP_CODES 表有外鍵約束
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.kinship'), [
                'c_personid' => $person1->c_personid,
                'c_kin_id' => $person2->c_personid,
                'c_kin_code' => 3,
                'new_c_kin_code' => 99999, // 不存在的關係代碼
            ]);

        // 應該返回錯誤
        $response->assertStatus(500);
        $response->assertJson([
            'success' => false,
        ]);

        // 驗證沒有創建任何新記錄（事務回滾）
        $this->assertDatabaseMissing('KIN_DATA', [
            'c_personid' => $person2->c_personid,
            'c_kin_id' => $person1->c_personid,
            'c_kin_code' => 99999,
        ]);
    }
}
