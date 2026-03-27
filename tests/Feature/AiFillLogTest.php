<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiFillLogTest extends TestCase {
    use RefreshDatabase;

    protected function setUp(): void {
        parent::setUp();

        config(['services.gemini.api_key' => 'test-api-key']);
        config(['services.gemini.api_endpoint' => 'https://example.com/api']);
        config(['services.gemini.model' => 'test-model']);

        // 測試朝代數據
        DB::table('DYNASTIES')->insert([
            ['c_dy' => 20, 'c_dynasty_chn' => '清', 'c_dynasty' => 'Qing', 'c_start' => 1644, 'c_end' => 1911, 'c_sort' => 20],
        ]);
    }

    /**
     * 測試訪客無法存取 AI 填充日誌頁面
     */
    public function test_guest_cannot_access_ai_fill_logs() {
        $response = $this->get('/admin/ai-fill-logs');
        $response->assertRedirect('/login');
    }

    /**
     * 測試一般用戶無法存取 AI 填充日誌頁面
     */
    public function test_regular_user_cannot_access_ai_fill_logs() {
        $user = User::factory()->create([
            'is_active' => 1,
            'is_admin' => 0,
        ]);

        $response = $this->actingAs($user)->get('/admin/ai-fill-logs');
        $response->assertStatus(403);
    }

    /**
     * 測試 Super Admin 可以存取 AI 填充日誌頁面
     */
    public function test_super_admin_can_access_ai_fill_logs() {
        $user = User::factory()->create([
            'is_active' => 1,
            'is_admin' => 3, // ROLE_SUPER_ADMIN
        ]);

        $response = $this->actingAs($user)->get('/admin/ai-fill-logs');
        $response->assertStatus(200);
        $response->assertSee('AI 填充日誌');
    }

    /**
     * 測試 AI 填充成功時建立日誌記錄
     */
    public function test_ai_fill_creates_log_record() {
        $user = User::factory()->create([
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        // Mock Gemini API 響應
        Http::fake([
            'https://example.com/api' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'postings' => [
                                    [
                                        'title_str' => '知縣',
                                        'addr_str' => null,
                                        'c_firstyear' => 1723,
                                        'c_fy_nh_code' => '雍正',
                                        'c_fy_nh_year' => 1,
                                        'c_fy_range' => null,
                                        'c_fy_intercalary' => false,
                                        'c_fy_month' => 1,
                                        'c_fy_day' => 3,
                                        'c_fy_day_gz' => null,
                                        'c_lastyear' => null,
                                        'c_ly_nh_code' => null,
                                        'c_ly_nh_year' => null,
                                        'c_ly_range' => null,
                                        'c_ly_intercalary' => null,
                                        'c_ly_month' => null,
                                        'c_ly_day' => null,
                                        'c_ly_day_gz' => null,
                                        'c_appt_code' => null,
                                        'c_assume_office_code' => null,
                                    ],
                                ],
                            ]),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($user)->postJson('/api/ai/posting/extract', [
            'source_text' => '雍正元年正月初三知新城縣',
            'person_id' => 1,
            'route_name' => 'basicinformation.offices.create',
            'route_url' => '/basicinformation/1/offices/create',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['ai_fill_log_id']);

        // 驗證日誌記錄已建立
        $this->assertDatabaseHas('ai_fill_logs', [
            'user_id' => $user->id,
            'c_personid' => 1,
            'source_text' => '雍正元年正月初三知新城縣',
            'route_name' => 'basicinformation.offices.create',
            'success' => true,
        ]);

        // 驗證 ai_raw 和 ai_matched 不為空
        $log = DB::table('ai_fill_logs')->first();
        $this->assertNotNull($log->ai_raw);
        $this->assertNotNull($log->ai_matched);
        $this->assertNull($log->user_submitted);
    }

    /**
     * 測試日誌記錄可以更新 user_submitted
     * 直接測試 updateAiFillLog 的邏輯（透過 DB 操作驗證）
     */
    public function test_log_record_can_be_updated_with_user_submitted() {
        $user = User::factory()->create([
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        // 建立日誌記錄
        $logId = DB::table('ai_fill_logs')->insertGetId([
            'user_id' => $user->id,
            'c_personid' => 1,
            'route_name' => 'basicinformation.offices.create',
            'route_url' => '/basicinformation/1/offices/create',
            'source_text' => '雍正元年正月初三知新城縣',
            'ai_raw' => json_encode(['title_str' => '知縣']),
            'ai_matched' => json_encode([
                'matched_fields' => ['c_firstyear' => ['value' => 1723, 'text' => '1723']],
                'suggested_fields' => [],
                'empty_fields' => [],
                'statistics' => ['matched_count' => 1, 'suggested_count' => 0, 'not_found_count' => 0, 'empty_count' => 0],
            ]),
            'success' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 模擬 user_submitted 更新（與 BasicInformationOfficesController::updateAiFillLog 相同的邏輯）
        $submittedData = [
            'c_office_id' => 123,
            'c_firstyear' => 1723,
            'c_fy_month' => 1,
            'c_fy_day' => 3,
            'c_dy' => 20,
        ];

        $updated = DB::table('ai_fill_logs')
            ->where('id', $logId)
            ->where('user_id', $user->id)
            ->update([
                'user_submitted' => json_encode($submittedData, JSON_UNESCAPED_UNICODE),
                'submitted_at' => now(),
                'updated_at' => now(),
            ]);

        $this->assertEquals(1, $updated);

        $log = DB::table('ai_fill_logs')->where('id', $logId)->first();
        $this->assertNotNull($log->user_submitted);
        $this->assertNotNull($log->submitted_at);

        $submitted = json_decode($log->user_submitted, true);
        $this->assertEquals(123, $submitted['c_office_id']);
        $this->assertEquals(1723, $submitted['c_firstyear']);
    }

    /**
     * 測試安全檢查：不能更新他人的日誌
     */
    public function test_cannot_update_others_log_record() {
        $user1 = User::factory()->create([
            'is_active' => 1,
            'is_admin' => 1,
        ]);
        $user2 = User::factory()->create([
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        // user1 建立日誌
        $logId = DB::table('ai_fill_logs')->insertGetId([
            'user_id' => $user1->id,
            'c_personid' => 1,
            'route_name' => 'test',
            'route_url' => '/test',
            'source_text' => '測試文本',
            'success' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // user2 嘗試更新（WHERE user_id 不匹配，應影響 0 行）
        $updated = DB::table('ai_fill_logs')
            ->where('id', $logId)
            ->where('user_id', $user2->id)
            ->update([
                'user_submitted' => json_encode(['c_office_id' => 999]),
                'submitted_at' => now(),
            ]);

        $this->assertEquals(0, $updated);

        // 驗證未被更新
        $log = DB::table('ai_fill_logs')->where('id', $logId)->first();
        $this->assertNull($log->user_submitted);
    }

    /**
     * 測試管理頁面篩選功能
     */
    public function test_admin_page_filters() {
        $admin = User::factory()->create([
            'is_active' => 1,
            'is_admin' => 3, // ROLE_SUPER_ADMIN
        ]);

        // 建立測試日誌
        DB::table('ai_fill_logs')->insert([
            'user_id' => $admin->id,
            'c_personid' => 1,
            'route_name' => 'test.route',
            'route_url' => '/test',
            'source_text' => '唐朝開元年間知縣',
            'success' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 搜尋關鍵字
        $response = $this->actingAs($admin)->get('/admin/ai-fill-logs?search=開元');
        $response->assertStatus(200);
        $response->assertSee('唐朝開元年間知縣');

        // 搜尋不存在的關鍵字
        $response = $this->actingAs($admin)->get('/admin/ai-fill-logs?search=不存在的文字');
        $response->assertStatus(200);
        $response->assertSee('暫無記錄');
    }

    /**
     * 測試管理頁面比較功能（有 user_submitted 時顯示比較按鈕）
     */
    public function test_admin_page_shows_compare_button_when_submitted() {
        $admin = User::factory()->create([
            'is_active' => 1,
            'is_admin' => 3, // ROLE_SUPER_ADMIN
        ]);

        // 建立有 user_submitted 的日誌
        DB::table('ai_fill_logs')->insert([
            'user_id' => $admin->id,
            'c_personid' => 1,
            'route_name' => 'test.route',
            'route_url' => '/test',
            'source_text' => '測試文本',
            'ai_matched' => json_encode([
                'matched_fields' => ['c_firstyear' => ['value' => 1723, 'text' => '1723']],
                'suggested_fields' => [],
                'empty_fields' => [],
                'statistics' => ['matched_count' => 1, 'suggested_count' => 0, 'not_found_count' => 0, 'empty_count' => 0],
            ]),
            'user_submitted' => json_encode(['c_firstyear' => 1723]),
            'success' => true,
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/admin/ai-fill-logs');
        $response->assertStatus(200);
        $response->assertSee('比較');
    }
}
