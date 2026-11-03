<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 主通道走 /v1/responses、備援 _1 走 chat/completions 的分支驗證（以任官 AI 填表為載體，
 * 三個 LLM 服務共用同一個 LlmFallbackTrait 接入點）。
 */
class LlmResponsesApiRoutingTest extends TestCase {
    use RefreshDatabase;

    private const PRIMARY = 'https://primary.example.com/v2/responses';
    private const FALLBACK = 'https://fallback.example.com/chat/completions';

    protected function setUp(): void {
        parent::setUp();

        config([
            'services.gemini.api_key' => 'primary-key',
            'services.gemini.api_endpoint' => self::PRIMARY,
            'services.gemini.model' => 'gpt-6-luna',
            'services.gemini_fallback.api_key' => 'fallback-key',
            'services.gemini_fallback.api_endpoint' => self::FALLBACK,
            'services.gemini_fallback.model' => 'gemini-flash-latest',
        ]);

        DB::table('DYNASTIES')->insert([
            ['c_dy' => 20, 'c_dynasty_chn' => '清', 'c_dynasty' => 'Qing', 'c_start' => 1644, 'c_end' => 1911, 'c_sort' => 20],
        ]);
    }

    private function postingJson(): string {
        return json_encode(['postings' => [[
            'title_str' => '知縣', 'addr_str' => '新城', 'c_firstyear' => 1723,
            'c_fy_nh_code' => null, 'c_fy_nh_year' => null, 'c_fy_range' => null,
            'c_fy_intercalary' => false, 'c_fy_month' => null, 'c_fy_day' => null, 'c_fy_day_gz' => null,
            'c_lastyear' => null, 'c_ly_nh_code' => null, 'c_ly_nh_year' => null, 'c_ly_range' => null,
            'c_ly_intercalary' => null, 'c_ly_month' => null, 'c_ly_day' => null, 'c_ly_day_gz' => null,
            'c_appt_code' => null, 'c_assume_office_code' => null,
        ]]]);
    }

    private function extract() {
        $user = User::factory()->create(['is_active' => User::STATUS_ACTIVE, 'is_admin' => 1]);

        return $this->actingAs($user)->postJson('/api/ai/posting/extract', [
            'source_text' => '雍正元年正月初三知陝西新城縣',
            'person_id' => 1,
        ]);
    }

    public function test_primary_responses_endpoint_gets_responses_body_and_reply_is_parsed() {
        Http::fake([
            self::PRIMARY => Http::response([
                'status' => 'completed',
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $this->postingJson()]]]],
            ], 200),
        ]);

        $this->extract()->assertStatus(200)->assertJson(['success' => true]);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === self::PRIMARY
                && ($body['reasoning']['effort'] ?? null) === 'low'
                && isset($body['input'])
                && !isset($body['messages'])
                && ($body['text']['format']['type'] ?? null) === 'json_schema';
        });
    }

    public function test_primary_failure_falls_back_to_chat_completions_body() {
        Http::fake([
            self::PRIMARY => Http::response(['error' => ['message' => 'bad']], 400),
            self::FALLBACK => Http::response([
                'choices' => [['message' => ['content' => $this->postingJson()]]],
            ], 200),
        ]);

        $this->extract()->assertStatus(200)->assertJson(['success' => true]);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === self::FALLBACK
                && isset($body['messages'])
                && !isset($body['input'])
                && !isset($body['reasoning']);
        });
    }
}
