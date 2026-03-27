<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        // 創建測試用的朝代數據（從真實數據庫結構複製）
        // 這些數據必須存在，因為 PostingAutofillService 依賴 DYNASTIES 表來確定朝代
        DB::table('DYNASTIES')->insert([
            ['c_dy' => 6, 'c_dynasty_chn' => '唐', 'c_dynasty' => 'Tang', 'c_start' => 618, 'c_end' => 907, 'c_sort' => 6],
            ['c_dy' => 15, 'c_dynasty_chn' => '宋', 'c_dynasty' => 'Song', 'c_start' => 960, 'c_end' => 1279, 'c_sort' => 15],
            ['c_dy' => 18, 'c_dynasty_chn' => '元', 'c_dynasty' => 'Yuan', 'c_start' => 1271, 'c_end' => 1368, 'c_sort' => 18],
            ['c_dy' => 19, 'c_dynasty_chn' => '明', 'c_dynasty' => 'Ming', 'c_start' => 1368, 'c_end' => 1644, 'c_sort' => 19],
            ['c_dy' => 20, 'c_dynasty_chn' => '清', 'c_dynasty' => 'Qing', 'c_start' => 1644, 'c_end' => 1911, 'c_sort' => 20],
        ]);
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
                                        'title_str' => '知縣',
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
                        'not_found_count',
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

    /**
     * 測試智能朝代確定邏輯：只有 firstYear（清朝）
     */
    public function test_dynasty_determination_with_only_first_year() {
        $user = User::factory()->create([
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        // Mock Gemini API 響應：雍正元年（1723年，清朝）
        Http::fake([
            'https://example.com/api' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'postings' => [
                                    [
                                        'title_str' => '知縣',
                                        'addr_str' => ['full_text' => '新城', 'parent' => '陝西', 'name' => '新城', 'admin_type' => '縣'],
                                        'c_firstyear' => 1723, // 清朝
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
            ->assertJsonPath('data.matched_fields.c_dy.value', 20)
            ->assertJsonPath('data.matched_fields.c_dy.text', '清');
    }

    /**
     * 測試智能朝代確定邏輯：firstYear 和 lastYear 屬於同一朝代（明朝）
     */
    public function test_dynasty_determination_with_same_dynasty() {
        $user = User::factory()->create([
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        // Mock Gemini API 響應：萬曆元年至萬曆五年（1573-1577年，明朝）
        Http::fake([
            'https://example.com/api' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'postings' => [
                                    [
                                        'title_str' => '知府',
                                        'addr_str' => ['full_text' => '杭州', 'parent' => '浙江', 'name' => '杭州', 'admin_type' => '府'],
                                        'c_firstyear' => 1573, // 明朝
                                        'c_fy_nh_code' => '萬曆',
                                        'c_fy_nh_year' => 1,
                                        'c_fy_range' => null,
                                        'c_fy_intercalary' => false,
                                        'c_fy_month' => null,
                                        'c_fy_day' => null,
                                        'c_fy_day_gz' => null,
                                        'c_lastyear' => 1577, // 明朝
                                        'c_ly_nh_code' => '萬曆',
                                        'c_ly_nh_year' => 5,
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
            'source_text' => '萬曆元年至萬曆五年知杭州府',
            'person_id' => 1,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    /**
     * 測試智能朝代確定邏輯：firstYear 和 lastYear 跨越不同朝代（元明交替）
     */
    public function test_dynasty_determination_with_different_dynasties() {
        $user = User::factory()->create([
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        // Mock Gemini API 響應：至正二十七年至洪武三年（1367-1370年，元明交替）
        Http::fake([
            'https://example.com/api' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'postings' => [
                                    [
                                        'title_str' => '知府',
                                        'addr_str' => ['full_text' => '南京', 'parent' => '應天', 'name' => '南京', 'admin_type' => '府'],
                                        'c_firstyear' => 1367, // 元朝
                                        'c_fy_nh_code' => '至正',
                                        'c_fy_nh_year' => 27,
                                        'c_fy_range' => null,
                                        'c_fy_intercalary' => false,
                                        'c_fy_month' => null,
                                        'c_fy_day' => null,
                                        'c_fy_day_gz' => null,
                                        'c_lastyear' => 1370, // 明朝
                                        'c_ly_nh_code' => '洪武',
                                        'c_ly_nh_year' => 3,
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
            'source_text' => '至正二十七年至洪武三年知南京府',
            'person_id' => 1,
        ]);

        // 應該 fallback 到人物朝代，仍然成功返回
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    /**
     * 測試朝代邊界重叠處理：1368年同時屬於元和明，無法確定則 fallback
     */
    public function test_dynasty_boundary_overlap_fallback_to_person_dynasty() {
        $user = User::factory()->create([
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        // setUp 中已經插入了朝代數據，其中 1368 年同時屬於元（1271-1368）和明（1368-1644）
        // 新邏輯：當有多個朝代匹配時，排除可排除朝代（南明、朝鮮、大順、大西）
        // 元和明都不是可排除朝代，排除後仍有兩個，所以 fallback 到人物朝代

        // Mock Gemini API 響應：1368年（邊界年份）
        Http::fake([
            'https://example.com/api' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'postings' => [
                                    [
                                        'title_str' => '知府',
                                        'addr_str' => ['full_text' => '應天', 'parent' => null, 'name' => '應天', 'admin_type' => '府'],
                                        'c_firstyear' => 1368, // 邊界年份：同時屬於元和明
                                        'c_fy_nh_code' => null,
                                        'c_fy_nh_year' => null,
                                        'c_fy_range' => null,
                                        'c_fy_intercalary' => null,
                                        'c_fy_month' => null,
                                        'c_fy_day' => null,
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
            'source_text' => '洪武元年知應天府',
            'person_id' => 1,
        ]);

        // 1368年同時屬於元和明（都是主要朝代），無法確定，應該 fallback 到人物朝代
        // 在此測試中人物朝代為 null，所以不應該填充 c_dy 字段
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        // 驗證沒有填充朝代字段（因為無法確定唯一主要朝代）
        $data = $response->json('data');
        $this->assertArrayNotHasKey('c_dy', $data['matched_fields'] ?? []);
    }
}
