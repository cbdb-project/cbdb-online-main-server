<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NaturalLanguageQueryService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HistoricalQaTest extends TestCase {
    protected User $adminUser;
    protected User $regularUser;
    protected User $inactiveUser;

    protected function setUp(): void {
        parent::setUp();

        Config::set('codes.tables', [
            'DYNASTIES' => 'Dynasties Table',
            'ALTNAME_DATA' => 'Altname Data',
            'BIOG_MAIN' => 'Biography Main',
        ]);
        Config::set('services.gemini.model', 'gemini-test-model');
        Config::set('services.gemini.api_key', 'test-api-key');

        $this->adminUser = new User();
        $this->adminUser->forceFill([
            'id' => 1,
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'is_admin' => User::ROLE_EXPERT,
            'is_active' => User::STATUS_ACTIVE,
        ]);

        $this->regularUser = new User();
        $this->regularUser->forceFill([
            'id' => 2,
            'name' => 'Regular User',
            'email' => 'reg@example.com',
            'is_admin' => User::ROLE_REGULAR,
            'is_active' => User::STATUS_ACTIVE,
        ]);

        $this->inactiveUser = new User();
        $this->inactiveUser->forceFill([
            'id' => 3,
            'name' => 'Inactive User',
            'email' => 'inactive@example.com',
            'is_admin' => User::ROLE_REGULAR,
            'is_active' => User::STATUS_INACTIVE,
        ]);
    }

    // ──── Page Props ────

    #[Test]
    public function app_playground_returns_qa_endpoint_props() {
        $this->be($this->adminUser);
        $response = $this->get(route('app.query-playground.index'));

        $response->assertInertia(function (AssertableInertia $page) {
            $page->component('QueryPlayground/Index')
                ->has('answerFromNlEndpoint')
                ->has('answerFromNlStreamEndpoint');
        });
    }

    #[Test]
    public function qa_endpoint_props_are_relative_urls() {
        $this->be($this->adminUser);
        $response = $this->get(route('app.query-playground.index'));

        $response->assertInertia(function (AssertableInertia $page) {
            $page->component('QueryPlayground/Index')
                ->where('answerFromNlEndpoint', '/query-playground/answer-from-nl')
                ->where('answerFromNlStreamEndpoint', '/query-playground/answer-from-nl-stream');
        });
    }

    #[Test]
    public function qa_props_do_not_break_existing_props() {
        $this->be($this->adminUser);
        $response = $this->get(route('app.query-playground.index'));

        $response->assertInertia(function (AssertableInertia $page) {
            $page->component('QueryPlayground/Index')
                ->has('initialSql')
                ->has('nlModel')
                ->has('qbeTables')
                ->has('pageUrl')
                ->has('runEndpoint')
                ->has('schemaEndpoint')
                ->has('generateFromNlEndpoint')
                ->has('generateFromNlStreamEndpoint')
                ->has('answerFromNlEndpoint')
                ->has('answerFromNlStreamEndpoint');
        });
    }

    #[Test]
    public function qa_max_turns_prop_reflects_config() {
        Config::set('query_playground.qa_max_turns', 7);

        $this->be($this->adminUser);
        $response = $this->get(route('app.query-playground.index'));

        $response->assertInertia(function (AssertableInertia $page) {
            $page->component('QueryPlayground/Index')
                ->where('qaMaxTurns', 7);
        });
    }

    // ──── Authorization ────

    #[Test]
    public function guests_cannot_access_answer_from_nl() {
        auth()->logout();
        $response = $this->postJson(route('query-playground.answer-from-nl'), [
            'question' => '李白是什麼時代的人？',
        ]);
        // Will redirect or 401
        $this->assertTrue(in_array($response->status(), [302, 401]));
    }

    #[Test]
    public function inactive_users_cannot_access_answer_from_nl() {
        $this->be($this->inactiveUser);
        $response = $this->postJson(route('query-playground.answer-from-nl'), [
            'question' => '李白是什麼時代的人？',
        ]);
        $response->assertStatus(403);
    }

    #[Test]
    public function guests_cannot_access_answer_from_nl_stream() {
        auth()->logout();
        $response = $this->post(route('query-playground.answer-from-nl-stream'), [
            'question' => '李白是什麼時代的人？',
        ]);
        $this->assertTrue(in_array($response->status(), [302, 401]));
    }

    #[Test]
    public function inactive_users_cannot_access_answer_from_nl_stream() {
        $this->be($this->inactiveUser);
        $response = $this->postJson(route('query-playground.answer-from-nl-stream'), [
            'question' => '李白是什麼時代的人？',
        ]);
        $response->assertStatus(403);
    }

    // ──── Validation ────

    #[Test]
    public function answer_from_nl_requires_question() {
        $this->be($this->adminUser);
        $response = $this->postJson(route('query-playground.answer-from-nl'), []);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['question']);
    }

    #[Test]
    public function answer_from_nl_rejects_question_exceeding_max_length() {
        $this->be($this->adminUser);
        $response = $this->postJson(route('query-playground.answer-from-nl'), [
            'question' => str_repeat('x', 1001),
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['question']);
    }

    #[Test]
    public function answer_from_nl_accepts_request_without_conversation_history() {
        // 向後相容：不帶 conversation_history（或帶空陣列）與現行單輪行為完全一致。
        $this->be($this->adminUser);

        $mockService = $this->createMock(NaturalLanguageQueryService::class);
        $mockService->method('answerQuestion')->willReturn([
            'success' => true,
            'answer_markdown' => '李白是唐代詩人。',
            'summary' => '唐代詩人',
            'sql_used' => [],
            'tool_calls' => [],
            'evidence' => [],
            'caveat' => '',
            'model' => 'gemini-test-model',
        ]);
        $this->app->instance(NaturalLanguageQueryService::class, $mockService);

        $response = $this->postJson(route('query-playground.answer-from-nl'), [
            'question' => '李白是什麼時代的人？',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertArrayNotHasKey('conversation_id', $response->json());
        $this->assertArrayNotHasKey('turn_index', $response->json());
    }

    #[Test]
    public function answer_from_nl_rejects_conversation_history_exceeding_max_turns() {
        Config::set('query_playground.qa_max_turns', 5);
        $this->be($this->adminUser);

        // qa_max_turns=5 → conversation_history 上限 4 筆，送 5 筆應被拒。
        $history = array_map(fn ($i) => ['question' => "問題 {$i}", 'summary' => "摘要 {$i}"], range(1, 5));

        $response = $this->postJson(route('query-playground.answer-from-nl'), [
            'question' => '追問',
            'conversation_history' => $history,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['conversation_history']);
    }

    #[Test]
    public function answer_from_nl_accepts_conversation_history_at_max_turns_boundary() {
        Config::set('query_playground.qa_max_turns', 5);
        $this->be($this->adminUser);

        // 剛好 4 筆歷史（qa_max_turns - 1）應通過驗證，不被 max 規則擋下。
        $history = array_map(fn ($i) => ['question' => "問題 {$i}", 'summary' => "摘要 {$i}"], range(1, 4));

        $mockService = $this->createMock(NaturalLanguageQueryService::class);
        $mockService->method('answerQuestion')->willReturn([
            'success' => true,
            'answer_markdown' => 'ok',
            'summary' => 'ok',
            'sql_used' => [],
            'tool_calls' => [],
            'evidence' => [],
            'caveat' => '',
            'model' => 'gemini-test-model',
        ]);
        $this->app->instance(NaturalLanguageQueryService::class, $mockService);

        $response = $this->postJson(route('query-playground.answer-from-nl'), [
            'question' => '追問',
            'conversation_history' => $history,
        ]);

        $response->assertOk();
    }

    #[Test]
    public function answer_from_nl_rejects_conversation_history_missing_question() {
        $this->be($this->adminUser);

        $response = $this->postJson(route('query-playground.answer-from-nl'), [
            'question' => '追問',
            'conversation_history' => [
                ['summary' => '只有摘要，沒有 question'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['conversation_history.0.question']);
    }

    #[Test]
    public function answer_from_nl_rejects_conversation_history_exceeding_char_limit() {
        Config::set('query_playground.qa_history_char_limit', 100);
        $this->be($this->adminUser);

        // 單筆 summary 80 字，2 筆共 160 字，超過門檻 100。
        $response = $this->postJson(route('query-playground.answer-from-nl'), [
            'question' => '追問',
            'conversation_history' => [
                ['question' => 'q1', 'summary' => str_repeat('字', 80)],
                ['question' => 'q2', 'summary' => str_repeat('字', 80)],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['conversation_history']);
    }

    #[Test]
    public function answer_from_nl_accepts_conversation_history_exactly_at_char_limit() {
        Config::set('query_playground.qa_history_char_limit', 100);
        $this->be($this->adminUser);

        $mockService = $this->createMock(NaturalLanguageQueryService::class);
        $mockService->method('answerQuestion')->willReturn([
            'success' => true,
            'answer_markdown' => 'ok',
            'summary' => 'ok',
            'sql_used' => [],
            'tool_calls' => [],
            'evidence' => [],
            'caveat' => '',
            'model' => 'gemini-test-model',
        ]);
        $this->app->instance(NaturalLanguageQueryService::class, $mockService);

        // question 2 字 + summary 98 字 = 剛好 100，門檻是「超過才擋」，等於門檻應通過。
        $response = $this->postJson(route('query-playground.answer-from-nl'), [
            'question' => '追問',
            'conversation_history' => [
                ['question' => 'q1', 'summary' => str_repeat('字', 98)],
            ],
        ]);

        $response->assertOk();
    }

    #[Test]
    public function answer_from_nl_with_qa_max_turns_zero_rejects_any_conversation_history() {
        // qa_max_turns 若被誤設為 0/負值：max(0, qa_max_turns - 1) 降級為 0，
        // 效果是「不允許任何歷史紀錄」，但仍允許不帶 conversation_history 的首輪問題。
        Config::set('query_playground.qa_max_turns', 0);
        $this->be($this->adminUser);

        $response = $this->postJson(route('query-playground.answer-from-nl'), [
            'question' => '追問',
            'conversation_history' => [
                ['question' => 'q1', 'summary' => 's1'],
            ],
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['conversation_history']);

        $mockService = $this->createMock(NaturalLanguageQueryService::class);
        $mockService->method('answerQuestion')->willReturn([
            'success' => true,
            'answer_markdown' => 'ok',
            'summary' => 'ok',
            'sql_used' => [],
            'tool_calls' => [],
            'evidence' => [],
            'caveat' => '',
            'model' => 'gemini-test-model',
        ]);
        $this->app->instance(NaturalLanguageQueryService::class, $mockService);

        $this->postJson(route('query-playground.answer-from-nl'), [
            'question' => '首輪問題',
        ])->assertOk();
    }

    #[Test]
    public function answer_from_nl_forwards_conversation_history_to_service() {
        $this->be($this->adminUser);

        $history = [
            ['question' => '李白是誰？', 'summary' => '李白是唐代詩人。'],
        ];

        $mockService = $this->createMock(NaturalLanguageQueryService::class);
        $mockService->expects($this->once())
            ->method('answerQuestion')
            ->with(
                '他還有哪些作品？',
                null,
                null,
                true,
                null,
                null,
                $history
            )
            ->willReturn([
                'success' => true,
                'answer_markdown' => 'ok',
                'summary' => 'ok',
                'sql_used' => [],
                'tool_calls' => [],
                'evidence' => [],
                'caveat' => '',
                'model' => 'gemini-test-model',
            ]);
        $this->app->instance(NaturalLanguageQueryService::class, $mockService);

        $response = $this->postJson(route('query-playground.answer-from-nl'), [
            'question' => '他還有哪些作品？',
            'conversation_history' => $history,
        ]);

        $response->assertOk();
    }

    #[Test]
    public function answer_from_nl_stream_requires_question() {
        $this->be($this->adminUser);
        $response = $this->postJson(route('query-playground.answer-from-nl-stream'), []);
        $response->assertStatus(422);
    }

    // ──── Successful answer (mocked service) ────

    #[Test]
    public function answer_from_nl_returns_answer_markdown_on_success() {
        $this->be($this->adminUser);

        $mockService = $this->createMock(NaturalLanguageQueryService::class);
        $mockService->method('answerQuestion')
            ->willReturn([
                'success' => true,
                'answer_markdown' => '## 李白\n\n李白是唐代詩人。',
                'summary' => '唐代詩人',
                'sql_used' => ['SELECT * FROM BIOG_MAIN WHERE c_name_chn = \'李白\''],
                'tool_calls' => [],
                'evidence' => [
                    ['type' => 'database', 'label' => 'BIOG_MAIN', 'detail' => '人物基本資料'],
                ],
                'caveat' => '部分歷史背景為模型補充。',
                'model' => 'gemini-test-model',
            ]);

        $this->app->instance(NaturalLanguageQueryService::class, $mockService);

        $response = $this->postJson(route('query-playground.answer-from-nl'), [
            'question' => '李白是什麼時代的人？',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'answer_markdown',
            'summary',
            'sql_used',
            'tool_calls',
            'evidence',
            'caveat',
            'model',
        ]);
        $response->assertJson(['success' => true]);
        $this->assertNotEmpty($response->json('answer_markdown'));
    }

    #[Test]
    public function answer_from_nl_returns_error_on_failure() {
        $this->be($this->adminUser);

        $mockService = $this->createMock(NaturalLanguageQueryService::class);
        $mockService->method('answerQuestion')
            ->willReturn([
                'success' => false,
                'error' => 'LLM API 調用失敗',
            ]);

        $this->app->instance(NaturalLanguageQueryService::class, $mockService);

        $response = $this->postJson(route('query-playground.answer-from-nl'), [
            'question' => '李白是什麼時代的人？',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'error' => 'LLM API 調用失敗',
        ]);
    }

    #[Test]
    public function answer_from_nl_works_with_use_tools_false() {
        $this->be($this->adminUser);

        $mockService = $this->createMock(NaturalLanguageQueryService::class);
        $mockService->expects($this->once())
            ->method('answerQuestion')
            ->with(
                '李白是什麼時代的人？',
                null,
                null,
                false
            )
            ->willReturn([
                'success' => true,
                'answer_markdown' => '李白是唐代詩人。',
                'summary' => '唐代詩人',
                'sql_used' => [],
                'tool_calls' => [],
                'evidence' => [],
                'caveat' => '',
                'model' => 'gemini-test-model',
            ]);

        $this->app->instance(NaturalLanguageQueryService::class, $mockService);

        $response = $this->postJson(route('query-playground.answer-from-nl'), [
            'question' => '李白是什麼時代的人？',
            'use_tools' => false,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    // ──── Streaming basic tests ────

    #[Test]
    public function answer_from_nl_stream_returns_200_with_sse_headers() {
        $this->be($this->adminUser);

        $mockService = $this->createMock(NaturalLanguageQueryService::class);
        $mockService->method('answerQuestion')
            ->willReturn([
                'success' => true,
                'answer_markdown' => '李白是唐代詩人。',
                'summary' => '唐代詩人',
                'sql_used' => [],
                'tool_calls' => [],
                'evidence' => [],
                'caveat' => '',
                'model' => 'gemini-test-model',
            ]);

        $this->app->instance(NaturalLanguageQueryService::class, $mockService);

        $response = $this->call('POST', route('query-playground.answer-from-nl-stream'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'text/event-stream',
        ], json_encode(['question' => '李白是什麼時代的人？']));

        $response->assertStatus(200);
        $this->assertStringContainsString('text/event-stream', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-cache, no-transform', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }

    #[Test]
    public function answer_from_nl_stream_forwards_conversation_history_and_completes() {
        $this->be($this->adminUser);

        $history = [
            ['question' => '李白是誰？', 'summary' => '李白是唐代詩人。'],
        ];

        $mockService = $this->createMock(NaturalLanguageQueryService::class);
        $mockService->expects($this->once())
            ->method('answerQuestion')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $history
            )
            ->willReturn([
                'success' => true,
                'answer_markdown' => '他還寫過很多詩。',
                'summary' => '李白的其他作品',
                'sql_used' => [],
                'tool_calls' => [],
                'evidence' => [],
                'caveat' => '',
                'model' => 'gemini-test-model',
            ]);

        $this->app->instance(NaturalLanguageQueryService::class, $mockService);

        $response = $this->call('POST', route('query-playground.answer-from-nl-stream'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'text/event-stream',
        ], json_encode(['question' => '他還有哪些作品？', 'conversation_history' => $history]));

        $response->assertStatus(200);
        $response->assertStreamed();
        $this->assertStringContainsString('event: complete', $response->streamedContent());
    }

    #[Test]
    public function answer_from_nl_stream_rejects_conversation_history_exceeding_max_turns() {
        Config::set('query_playground.qa_max_turns', 5);
        $this->be($this->adminUser);

        $history = array_map(fn ($i) => ['question' => "問題 {$i}", 'summary' => "摘要 {$i}"], range(1, 5));

        $response = $this->postJson(route('query-playground.answer-from-nl-stream'), [
            'question' => '追問',
            'conversation_history' => $history,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['conversation_history']);
    }

    #[Test]
    public function answer_from_nl_stream_includes_keep_alive_comments_when_service_requests_heartbeat() {
        Config::set('query_playground.sse_heartbeat_seconds', 0);

        $this->be($this->adminUser);

        $mockService = $this->createMock(NaturalLanguageQueryService::class);
        $mockService->method('answerQuestion')
            ->willReturnCallback(function (
                string $question,
                ?array $tables,
                ?callable $progressCallback,
                ?bool $useTools,
                ?callable $heartbeatCallback,
                ?callable $abortCheck
            ) {
                $this->assertNotNull($heartbeatCallback);
                $this->assertNotNull($abortCheck);
                $this->assertFalse($abortCheck());

                $heartbeatCallback();

                return [
                    'success' => true,
                    'answer_markdown' => '李白是唐代詩人。',
                    'summary' => '唐代詩人',
                    'sql_used' => [],
                    'tool_calls' => [],
                    'evidence' => [],
                    'caveat' => '',
                    'model' => 'gemini-test-model',
                ];
            });

        $this->app->instance(NaturalLanguageQueryService::class, $mockService);

        $response = $this->call('POST', route('query-playground.answer-from-nl-stream'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'text/event-stream',
        ], json_encode(['question' => '李白是什麼時代的人？']));

        $response->assertStatus(200);
        $response->assertStreamed();

        $content = $response->streamedContent();

        $this->assertStringContainsString(': keep-alive', $content);
        $this->assertStringContainsString('event: complete', $content);
    }

    // ──── Regression: existing NL endpoints still work ────

    #[Test]
    public function old_generate_from_nl_endpoint_still_works() {
        $this->be($this->adminUser);

        $mockService = $this->createMock(NaturalLanguageQueryService::class);
        $mockService->method('generateSQL')
            ->willReturn([
                'success' => true,
                'sql' => 'SELECT * FROM DYNASTIES',
                'explanation' => '查詢所有朝代',
                'model' => 'gemini-test-model',
                'tool_calls' => null,
            ]);

        $this->app->instance(NaturalLanguageQueryService::class, $mockService);

        $response = $this->postJson(route('query-playground.generate-from-nl'), [
            'question' => '顯示所有朝代',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'sql' => 'SELECT * FROM DYNASTIES',
        ]);
    }

    #[Test]
    public function old_generate_from_nl_stream_endpoint_still_works() {
        $this->be($this->adminUser);

        $mockService = $this->createMock(NaturalLanguageQueryService::class);
        $mockService->method('generateSQL')
            ->willReturn([
                'success' => true,
                'sql' => 'SELECT * FROM DYNASTIES',
                'explanation' => '查詢所有朝代',
                'model' => 'gemini-test-model',
                'tool_calls' => null,
            ]);

        $this->app->instance(NaturalLanguageQueryService::class, $mockService);

        $response = $this->call('POST', route('query-playground.generate-from-nl-stream'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'text/event-stream',
        ], json_encode(['question' => '顯示所有朝代']));

        $response->assertStatus(200);
        $this->assertStringContainsString('text/event-stream', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-cache, no-transform', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }

    // ──── LLM 空 content 處理 ────

    /**
     * 輔助：建立使用 partial mock 的 NaturalLanguageQueryService，
     * 攔截 performLlmHttpRequest 以避免依賴 Http::fake() 或真實 curl。
     * 這確保不論內部走 Laravel Http 或 curl_multi 路徑，測試都能正確攔截。
     *
     * @param  array|callable  $responses  固定回應陣列或依序返回的 callable
     * @param  bool  $stubToolExecution  是否同時 stub executeToolCalls（避免碰到測試 DB 缺少資料表）
     * @return NaturalLanguageQueryService
     */
    protected function mockLlmService(array|callable $responses, bool $stubToolExecution = false): NaturalLanguageQueryService {
        Config::set('services.gemini.api_key', 'test-api-key');
        Config::set('services.gemini.api_endpoint', 'https://fake-gemini.test/v1/chat/completions');

        $methods = ['performLlmHttpRequest'];
        if ($stubToolExecution) {
            $methods[] = 'executeToolCalls';
        }

        $service = $this->getMockBuilder(NaturalLanguageQueryService::class)
            ->setConstructorArgs([
                $this->app->make(\App\Services\DatabaseSchemaService::class),
                $this->app->make(\App\Services\NlQueryToolsService::class),
            ])
            ->onlyMethods($methods)
            ->getMock();

        if (is_callable($responses)) {
            $service->method('performLlmHttpRequest')
                ->willReturnCallback($responses);
        } else {
            $service->method('performLlmHttpRequest')
                ->willReturn($responses);
        }

        if ($stubToolExecution) {
            $service->method('executeToolCalls')
                ->willReturnCallback(function (array $toolCalls) {
                    return array_map(fn ($tc) => [
                        'tool_call_id' => $tc['id'] ?? 'stub_id',
                        'tool_name' => $tc['function']['name'] ?? 'unknown',
                        'arguments' => json_decode($tc['function']['arguments'] ?? '{}', true),
                        'status' => 'completed',
                        'result' => ['stub' => true],
                        'result_summary' => ['row_count' => 0],
                    ], $toolCalls);
                });
        }

        return $service;
    }

    #[Test]
    public function qa_returns_error_when_llm_content_is_null() {
        Config::set('nl_query_tools.enabled', true);
        Config::set('nl_query_tools.max_tool_calls', 5);

        $service = $this->mockLlmService([
            'successful' => true,
            'status' => 200,
            'body' => '',
            'json' => [
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => null],
                    'finish_reason' => 'stop',
                ]],
            ],
        ]);

        $result = $service->answerQuestion('清代所有人物的任官記錄');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('未能生成回答', $result['error']);
    }

    #[Test]
    public function qa_returns_error_when_llm_content_is_empty_string() {
        Config::set('nl_query_tools.enabled', true);
        Config::set('nl_query_tools.max_tool_calls', 5);

        $service = $this->mockLlmService([
            'successful' => true,
            'status' => 200,
            'body' => '',
            'json' => [
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => ''],
                    'finish_reason' => 'stop',
                ]],
            ],
        ]);

        $result = $service->answerQuestion('清代所有人物的任官記錄');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('未能生成回答', $result['error']);
    }

    // ──── QA 最終輪不套用 SQL structured output ────

    #[Test]
    public function qa_last_round_does_not_apply_sql_structured_output() {
        Config::set('nl_query_tools.enabled', true);
        Config::set('nl_query_tools.max_tool_calls', 2);

        $callCount = 0;
        $capturedRequests = [];

        $service = $this->mockLlmService(function (array $requestData) use (&$callCount, &$capturedRequests) {
            $callCount++;
            $capturedRequests[] = $requestData;

            if ($callCount === 1) {
                return [
                    'successful' => true,
                    'status' => 200,
                    'body' => '',
                    'json' => [
                        'choices' => [[
                            'message' => [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [[
                                    'id' => 'call_1',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'get_code_values',
                                        'arguments' => json_encode(['code_type' => 'dynasties']),
                                    ],
                                ]],
                            ],
                            'finish_reason' => 'tool_calls',
                        ]],
                    ],
                ];
            }

            // 第 2 輪（最後一輪）
            return [
                'successful' => true,
                'status' => 200,
                'body' => '',
                'json' => [
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'answer_markdown' => '## 清代人物任官記錄查詢結果',
                                'summary' => '清代人物任官記錄。',
                                'sql_used' => [],
                                'evidence' => [],
                                'caveat' => '部分歷史背景為模型補充。',
                            ]),
                        ],
                        'finish_reason' => 'stop',
                    ]],
                ],
            ];
        }, true);  // stubToolExecution: 避免碰到測試 DB 缺少資料表

        $result = $service->answerQuestion('清代所有人物的任官記錄');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('清代人物任官記錄', $result['answer_markdown']);

        // 驗證確實發了 2 次 LLM 請求
        $this->assertCount(2, $capturedRequests, '應發出 2 次 LLM 請求（1 次工具輪 + 1 次最終輪）');

        // 驗證最後一輪沒有被套用 response_format
        $lastRequest = $capturedRequests[count($capturedRequests) - 1];
        $this->assertArrayNotHasKey('response_format', $lastRequest, '最後一輪 QA 請求不應套用 SQL 的 response_format');
    }

    // ──── conversation_history 組裝進 LLM messages[] ────

    #[Test]
    public function qa_conversation_history_is_assembled_into_llm_messages_using_summary() {
        Config::set('nl_query_tools.enabled', false);

        $capturedRequests = [];
        $service = $this->mockLlmService(function (array $requestData) use (&$capturedRequests) {
            $capturedRequests[] = $requestData;

            return [
                'successful' => true,
                'status' => 200,
                'body' => '',
                'json' => [
                    'choices' => [[
                        'message' => ['role' => 'assistant', 'content' => json_encode([
                            'answer_markdown' => '他還寫過很多詩。',
                            'summary' => '李白的其他作品',
                            'sql_used' => [],
                            'evidence' => [],
                            'caveat' => '',
                        ])],
                        'finish_reason' => 'stop',
                    ]],
                ],
            ];
        });

        $conversationHistory = [
            ['question' => '李白是誰？', 'summary' => '李白是唐代詩人。'],
            ['question' => '他生於哪一年？', 'summary' => ''], // 空 summary：只應組出 user 訊息
        ];

        $result = $service->answerQuestion('他還有哪些作品？', null, null, null, null, null, $conversationHistory);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $capturedRequests);

        $messages = $capturedRequests[0]['messages'];
        // [system, user(Q1), assistant(A1), user(Q2)（無 assistant，因 summary 為空）, user(本輪新問題)]
        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame('user', $messages[1]['role']);
        $this->assertSame('李白是誰？', $messages[1]['content']);
        $this->assertSame('assistant', $messages[2]['role']);
        $this->assertSame('李白是唐代詩人。', $messages[2]['content']);
        $this->assertSame('user', $messages[3]['role']);
        $this->assertSame('他生於哪一年？', $messages[3]['content']);
        $this->assertSame('user', $messages[4]['role']);
        $this->assertSame('他還有哪些作品？', $messages[4]['content']);
        $this->assertCount(5, $messages, 'messages 應恰為 [system, user, assistant, user, user]，空 summary 的一輪不應多出空的 assistant 訊息');
    }

    #[Test]
    public function qa_without_conversation_history_still_assembles_two_messages() {
        // 向後相容：不帶 conversation_history 時，messages 應與現行單輪行為一致（只有 system+user）。
        Config::set('nl_query_tools.enabled', false);

        $capturedRequests = [];
        $service = $this->mockLlmService(function (array $requestData) use (&$capturedRequests) {
            $capturedRequests[] = $requestData;

            return [
                'successful' => true,
                'status' => 200,
                'body' => '',
                'json' => [
                    'choices' => [[
                        'message' => ['role' => 'assistant', 'content' => json_encode([
                            'answer_markdown' => '李白是唐代詩人。',
                            'summary' => '唐代詩人',
                            'sql_used' => [],
                            'evidence' => [],
                            'caveat' => '',
                        ])],
                        'finish_reason' => 'stop',
                    ]],
                ],
            ];
        });

        $result = $service->answerQuestion('李白是什麼時代的人？');

        $this->assertTrue($result['success']);
        $messages = $capturedRequests[0]['messages'];
        $this->assertCount(2, $messages);
        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame('user', $messages[1]['role']);
        $this->assertSame('李白是什麼時代的人？', $messages[1]['content']);
    }

    // ──── Rate limiting ────

    #[Test]
    public function answer_from_nl_returns_429_after_exceeding_rate_limit() {
        Config::set('query_playground.qa_rate_limit_per_minute', 2);
        $this->be($this->adminUser);

        $mockService = $this->createMock(NaturalLanguageQueryService::class);
        $mockService->method('answerQuestion')->willReturn([
            'success' => true,
            'answer_markdown' => 'ok',
            'summary' => 'ok',
            'sql_used' => [],
            'tool_calls' => [],
            'evidence' => [],
            'caveat' => '',
            'model' => 'gemini-test-model',
        ]);
        $this->app->instance(NaturalLanguageQueryService::class, $mockService);

        $payload = ['question' => '李白是什麼時代的人？'];

        $this->postJson(route('query-playground.answer-from-nl'), $payload)->assertOk();
        $this->postJson(route('query-playground.answer-from-nl'), $payload)->assertOk();
        $third = $this->postJson(route('query-playground.answer-from-nl'), $payload);

        $third->assertStatus(429);
    }

    #[Test]
    public function answer_from_nl_rate_limit_is_keyed_per_user() {
        Config::set('query_playground.qa_rate_limit_per_minute', 1);

        $mockService = $this->createMock(NaturalLanguageQueryService::class);
        $mockService->method('answerQuestion')->willReturn([
            'success' => true,
            'answer_markdown' => 'ok',
            'summary' => 'ok',
            'sql_used' => [],
            'tool_calls' => [],
            'evidence' => [],
            'caveat' => '',
            'model' => 'gemini-test-model',
        ]);
        $this->app->instance(NaturalLanguageQueryService::class, $mockService);

        $payload = ['question' => '李白是什麼時代的人？'];

        $this->be($this->adminUser);
        $this->postJson(route('query-playground.answer-from-nl'), $payload)->assertOk();
        $this->postJson(route('query-playground.answer-from-nl'), $payload)->assertStatus(429);

        // 換一個使用者，限流額度應該是獨立的，不受上一位使用者已用額度影響。
        $this->be($this->regularUser);
        $this->postJson(route('query-playground.answer-from-nl'), $payload)->assertOk();
    }
}
