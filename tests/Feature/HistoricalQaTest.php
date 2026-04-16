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
}
