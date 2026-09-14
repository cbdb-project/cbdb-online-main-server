<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AI 官職抽取（`POST /api/ai/posting/extract`）必須落一筆 `ai_fill_logs`。
 *
 * 這是唯一守著「AI 抽取有留紀錄」的測試——`ai_raw`／`ai_matched` 要有內容、`user_submitted`
 * 在提交前必須是 null、回應要帶 `ai_fill_log_id` 讓前端之後能回寫。
 *
 * ── 2026-09-14（Blade 下架環節 4a-1）─────────────────────────────
 *
 * 本檔原有 8 條並列在 legacy-parity 群組裡。分流後：2 條已由 `AiFillLogInertiaTest` 等價覆蓋；
 * 2 條是**自導自演**（自己下 `DB::table()->update()` 再驗它更新了，完全不執行生產碼）；
 * 1 條（比較按鈕）**有**執行生產碼，但斷言落在 Blade 文案上，其底層的 `comparison_rows`
 * 已由 React 側兩條更嚴的測試覆蓋；2 條已移植到 `AiFillLogInertiaTest`
 * （guest 重導、`?search=` 的三欄 OR 過濾，並補上 legacy 從未測過的 `users.name`／
 * `users.email` 兩個分支）。這條與 Blade 無關（該端點從來沒被封路），只是被 setUp 的
 * opt-out 連坐，所以留在原地。
 *
 * ⚠️ **已知的真空白**（不是本次造成，也不在本次範圍）：被刪掉的那兩條 B 類原本想守的是
 * 「React 編輯器提交後回寫 `ai_fill_logs.user_submitted`」，但它們用自己下 SQL 的方式寫，
 * 從來沒真的守到。那個行為目前**沒有任何測試守**（正是 `ai-fill-logs` 全顯示 Not Submitted
 * 那個回歸的成因）。要補的話是一條「走 v2 mutation 提交後 `user_submitted` 與 `submitted_at`
 * 被填上、且他人的 log 不受影響」的新測試——屬新功能覆蓋而非移植，獨立排程。
 */
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
}
