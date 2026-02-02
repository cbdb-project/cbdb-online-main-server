<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiPostingAutofillTest extends TestCase {
    use RefreshDatabase;

    protected function setUp(): void {
        parent::setUp();

        // 設置測試環境變數
        config(['services.gemini.api_key' => 'test-api-key']);
        config(['services.gemini.api_endpoint' => 'https://example.com/api']);
        config(['services.gemini.model' => 'test-model']);
    }

    /**
     * 測試未登入用戶無法訪問 AI 提取 API
     */
    public function test_guest_cannot_access_ai_extract() {
        $response = $this->postJson('/api/ai/posting/extract', [
            'source_text' => '雍正元年正月初三知陝西新城縣',
            'person_id' => 1,
        ]);

        $response->assertStatus(401);
    }

    /**
     * 測試無寫入權限用戶無法使用 AI 功能
     */
    public function test_user_without_write_permission_cannot_use_ai() {
        // 創建無寫入權限的用戶（is_active = 0）
        $user = User::factory()->create([
            'is_active' => 0,
            'is_admin' => 0,
        ]);

        $response = $this->actingAs($user)->postJson('/api/ai/posting/extract', [
            'source_text' => '雍正元年正月初三知陝西新城縣',
            'person_id' => 1,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => '您沒有使用 AI 功能的權限',
            ]);
    }

    /**
     * 測試缺少 API Key 時的錯誤處理
     */
    public function test_missing_api_key_returns_error() {
        // 清除 API Key
        config(['services.gemini.api_key' => '']);

        $user = User::factory()->create([
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        $response = $this->actingAs($user)->postJson('/api/ai/posting/extract', [
            'source_text' => '雍正元年正月初三知陝西新城縣',
            'person_id' => 1,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'error' => 'AI API 未配置，請聯繫管理員',
            ]);
    }

    /**
     * 測試缺少必要參數時的驗證錯誤
     */
    public function test_missing_parameters_returns_validation_error() {
        $user = User::factory()->create([
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        // 缺少 source_text
        $response = $this->actingAs($user)->postJson('/api/ai/posting/extract', [
            'person_id' => 1,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['source_text']);

        // 缺少 person_id
        $response = $this->actingAs($user)->postJson('/api/ai/posting/extract', [
            'source_text' => '雍正元年正月初三知陝西新城縣',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['person_id']);
    }

    /**
     * 測試 AI 提取成功的場景（Mock API 響應）
     */
    public function test_successful_ai_extraction() {
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
                                        'posting_str' => '知縣',
                                        'addr_str' => '新城',
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
            'source_text' => '雍正元年正月初三知陝西新城縣',
            'person_id' => 1,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'ai_extracted',
                    'matched_fields',
                    'suggested_fields',
                    'empty_fields',
                    'statistics' => [
                        'matched_count',
                        'suggested_count',
                        'empty_count',
                    ],
                ],
                'error',
            ]);
    }

    /**
     * 測試 AI API 調用失敗時的錯誤處理
     */
    public function test_ai_api_failure_returns_error() {
        $user = User::factory()->create([
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        // Mock API 失敗響應
        Http::fake([
            'https://example.com/api' => Http::response([
                'error' => ['message' => 'API quota exceeded'],
            ], 429),
        ]);

        $response = $this->actingAs($user)->postJson('/api/ai/posting/extract', [
            'source_text' => '雍正元年正月初三知陝西新城縣',
            'person_id' => 1,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
            ])
            ->assertJsonPath('error', function ($error) {
                return str_contains($error, 'AI API 調用失敗');
            });
    }
}
