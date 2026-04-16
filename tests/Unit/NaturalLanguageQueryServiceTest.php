<?php

namespace Tests\Unit;

use App\Services\DatabaseSchemaService;
use App\Services\NaturalLanguageQueryService;
use App\Services\NlQueryToolsService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NaturalLanguageQueryServiceTest extends TestCase {
    protected NaturalLanguageQueryService $service;
    protected DatabaseSchemaService $schemaService;
    protected NlQueryToolsService $toolsService;

    protected function setUp(): void {
        parent::setUp();

        // Mock config - 必须在创建服务之前設定
        Config::set('services.gemini.api_key', 'test-api-key');

        $this->schemaService = $this->createMock(DatabaseSchemaService::class);
        $this->toolsService = $this->createMock(NlQueryToolsService::class);
        $this->service = new NaturalLanguageQueryService($this->schemaService, $this->toolsService);
    }

    #[Test]
    public function it_returns_error_when_api_key_is_not_configured() {
        Config::set('services.gemini.api_key', '');

        // 重新创建服务以使用新的配置
        $schemaService = $this->createMock(DatabaseSchemaService::class);
        $toolsService = $this->createMock(NlQueryToolsService::class);
        $service = new NaturalLanguageQueryService($schemaService, $toolsService);

        $result = $service->generateSQL('test question');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('API Key', $result['error']);
    }

    #[Test]
    public function it_generates_sql_successfully() {
        // Mock schema service
        $this->schemaService->method('generateSchemaPrompt')
            ->willReturn('Mock schema info');

        // Mock HTTP response with OpenAI-compatible structured output
        Http::fake([
            '*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'sql' => 'SELECT * FROM DYNASTIES',
                                'explanation' => '此查詢選擇所有朝代。',
                                'error' => null,
                            ]),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->generateSQL('顯示所有朝代');

        // Debug output if test fails
        if (!$result['success']) {
            $this->fail("Expected success but got error: " . ($result['error'] ?? 'unknown'));
        }

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('SELECT', $result['sql']);
        $this->assertNotNull($result['explanation']);
        $this->assertNull($result['error']);
    }

    #[Test]
    public function it_handles_api_errors() {
        $this->schemaService->method('generateSchemaPrompt')
            ->willReturn('Mock schema info');

        // Mock HTTP error response
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'API quota exceeded',
                ],
            ], 429),
        ]);

        $result = $this->service->generateSQL('test question');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('API', $result['error']);
    }

    #[Test]
    public function it_falls_back_to_secondary_llm_on_primary_api_error() {
        Config::set('services.gemini_fallback.api_key', 'fallback-key');
        Config::set('services.gemini_fallback.api_endpoint', 'https://fallback.example.com/api');
        Config::set('services.gemini_fallback.model', 'fallback-model');

        $schemaService = $this->createMock(DatabaseSchemaService::class);
        $schemaService->method('generateSchemaPrompt')->willReturn('Mock schema info');
        $toolsService = $this->createMock(NlQueryToolsService::class);
        $service = new NaturalLanguageQueryService($schemaService, $toolsService);

        Http::fake([
            // 主要 API 回 429（所有重試都會打到 * 或明確的 endpoint）
            Config::get('services.gemini.api_endpoint', '*') => Http::response([
                'error' => ['message' => 'Rate limit exceeded'],
            ], 429),
            // 備援 API 成功
            'https://fallback.example.com/api' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'sql' => 'SELECT 1',
                                'explanation' => '備援回應',
                                'error' => null,
                            ]),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
            ], 200),
        ]);

        $result = $service->generateSQL('test question');

        $this->assertTrue($result['success']);
        $this->assertSame('SELECT 1', $result['sql']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'fallback.example.com'));
    }

    #[Test]
    public function it_handles_invalid_json_response() {
        $this->schemaService->method('generateSchemaPrompt')
            ->willReturn('Mock schema info');

        // Mock HTTP response with invalid JSON (OpenAI format)
        Http::fake([
            '*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'This is not valid JSON',
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->generateSQL('test question');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('JSON', $result['error']);
    }

    #[Test]
    public function it_handles_missing_sql_field() {
        $this->schemaService->method('generateSchemaPrompt')
            ->willReturn('Mock schema info');

        // Mock HTTP response with missing sql field (OpenAI format)
        Http::fake([
            '*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'sql' => null,
                                'explanation' => null,
                                'error' => null,
                            ]),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->generateSQL('test question');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('缺少 SQL 字段', $result['error']);
    }

    #[Test]
    public function it_handles_llm_returned_error() {
        $this->schemaService->method('generateSchemaPrompt')
            ->willReturn('Mock schema info');

        // Mock HTTP response where LLM returns an error (cannot generate SQL)
        Http::fake([
            '*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'sql' => null,
                                'explanation' => null,
                                'error' => '無法執行此操作，系統僅支援查詢（SELECT）操作，不支援刪除（DELETE）操作。',
                            ]),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->generateSQL('刪除所有朝代');

        $this->assertFalse($result['success']);
        $this->assertNull($result['sql']);
        $this->assertNull($result['explanation']);
        $this->assertStringContainsString('無法執行此操作', $result['error']);
        $this->assertStringContainsString('僅支援查詢', $result['error']);
    }

    #[Test]
    public function it_triggers_heartbeat_callback_during_blocking_llm_requests() {
        Config::set('nl_query_tools.enabled', false);

        $this->schemaService->method('generateSchemaPrompt')
            ->willReturn('Mock schema info');

        $service = new class ($this->schemaService, $this->toolsService) extends NaturalLanguageQueryService {
            protected function performHeartbeatAwareLlmRequest(
                array $requestData,
                ?callable $heartbeatCallback = null,
                ?callable $abortCheck = null
            ): array {
                if ($heartbeatCallback) {
                    $heartbeatCallback();
                    $heartbeatCallback();
                }

                return [
                    'successful' => true,
                    'status' => 200,
                    'body' => json_encode([
                        'choices' => [
                            [
                                'message' => [
                                    'role' => 'assistant',
                                    'content' => json_encode([
                                        'sql' => 'SELECT * FROM DYNASTIES',
                                        'explanation' => '此查詢選擇所有朝代。',
                                        'error' => null,
                                    ]),
                                ],
                                'finish_reason' => 'stop',
                            ],
                        ],
                    ], JSON_UNESCAPED_UNICODE),
                    'json' => [
                        'choices' => [
                            [
                                'message' => [
                                    'role' => 'assistant',
                                    'content' => json_encode([
                                        'sql' => 'SELECT * FROM DYNASTIES',
                                        'explanation' => '此查詢選擇所有朝代。',
                                        'error' => null,
                                    ]),
                                ],
                                'finish_reason' => 'stop',
                            ],
                        ],
                    ],
                ];
            }
        };

        $heartbeatCount = 0;
        $statusMessages = [];

        $result = $service->generateSQL(
            '顯示所有朝代',
            null,
            function (string $event, array $data) use (&$statusMessages) {
                if ($event === 'status') {
                    $statusMessages[] = $data['message'] ?? '';
                }
            },
            false,
            function () use (&$heartbeatCount) {
                $heartbeatCount++;
            },
            fn () => false
        );

        $this->assertTrue($result['success']);
        $this->assertGreaterThanOrEqual(2, $heartbeatCount);
        $this->assertContains('正在等待 LLM 第 1 輪回應', $statusMessages);
    }

    #[Test]
    public function it_triggers_heartbeat_callback_during_tool_execution() {
        Config::set('nl_query_tools.enabled', true);
        Config::set('nl_query_tools.max_tool_calls', 2);

        $this->schemaService->method('generateSchemaPrompt')
            ->willReturn('Mock schema info');

        $this->toolsService->method('executeTool')
            ->willReturn([
                'success' => true,
                'data' => ['rows' => [['c_dy' => 1, 'c_dynasty_chn' => '唐']]],
                'error' => null,
            ]);

        $this->toolsService->method('getToolDefinitions')
            ->willReturn([]);

        $service = new class ($this->schemaService, $this->toolsService) extends NaturalLanguageQueryService {
            private int $localCallCount = 0;

            protected function performHeartbeatAwareLlmRequest(
                array $requestData,
                ?callable $heartbeatCallback = null,
                ?callable $abortCheck = null
            ): array {
                $this->localCallCount++;

                // 第一次：回傳 tool_calls
                if ($this->localCallCount === 1) {
                    $data = [
                        'choices' => [
                            [
                                'message' => [
                                    'role' => 'assistant',
                                    'content' => null,
                                    'tool_calls' => [
                                        [
                                            'id' => 'call_001',
                                            'type' => 'function',
                                            'function' => [
                                                'name' => 'query_table_schema',
                                                'arguments' => json_encode(['table_name' => 'DYNASTIES']),
                                            ],
                                        ],
                                    ],
                                ],
                                'finish_reason' => 'tool_calls',
                            ],
                        ],
                    ];

                    return [
                        'successful' => true,
                        'status' => 200,
                        'body' => json_encode($data, JSON_UNESCAPED_UNICODE),
                        'json' => $data,
                    ];
                }

                // 第二次：回傳最終 SQL
                $data = [
                    'choices' => [
                        [
                            'message' => [
                                'role' => 'assistant',
                                'content' => json_encode([
                                    'sql' => 'SELECT * FROM DYNASTIES',
                                    'explanation' => '此查詢選擇所有朝代。',
                                    'error' => null,
                                ]),
                            ],
                            'finish_reason' => 'stop',
                        ],
                    ],
                ];

                return [
                    'successful' => true,
                    'status' => 200,
                    'body' => json_encode($data, JSON_UNESCAPED_UNICODE),
                    'json' => $data,
                ];
            }
        };

        $heartbeatCount = 0;

        $result = $service->generateSQL(
            '顯示所有朝代',
            null,
            function (string $event, array $data) {},
            true,
            function () use (&$heartbeatCount) {
                $heartbeatCount++;
            },
            fn () => false
        );

        $this->assertTrue($result['success']);
        // heartbeat 應至少在 tool execution 前後各觸發一次
        $this->assertGreaterThanOrEqual(2, $heartbeatCount);
    }

    #[Test]
    public function it_triggers_heartbeat_during_tool_retry_sleep() {
        Config::set('nl_query_tools.enabled', true);
        Config::set('nl_query_tools.max_tool_calls', 2);

        $this->schemaService->method('generateSchemaPrompt')
            ->willReturn('Mock schema info');

        $toolCallCount = 0;
        $this->toolsService->method('executeTool')
            ->willReturnCallback(function () use (&$toolCallCount) {
                $toolCallCount++;
                if ($toolCallCount === 1) {
                    // 第一次失敗（可重試的錯誤）
                    return [
                        'success' => false,
                        'data' => null,
                        'error' => '連線超時',
                    ];
                }

                // 第二次成功
                return [
                    'success' => true,
                    'data' => ['rows' => []],
                    'error' => null,
                ];
            });

        $this->toolsService->method('getToolDefinitions')
            ->willReturn([]);

        $service = new class ($this->schemaService, $this->toolsService) extends NaturalLanguageQueryService {
            private int $localCallCount = 0;

            protected function performHeartbeatAwareLlmRequest(
                array $requestData,
                ?callable $heartbeatCallback = null,
                ?callable $abortCheck = null
            ): array {
                $this->localCallCount++;

                if ($this->localCallCount === 1) {
                    $data = [
                        'choices' => [
                            [
                                'message' => [
                                    'role' => 'assistant',
                                    'content' => null,
                                    'tool_calls' => [
                                        [
                                            'id' => 'call_001',
                                            'type' => 'function',
                                            'function' => [
                                                'name' => 'query_table_schema',
                                                'arguments' => json_encode(['table_name' => 'DYNASTIES']),
                                            ],
                                        ],
                                    ],
                                ],
                                'finish_reason' => 'tool_calls',
                            ],
                        ],
                    ];

                    return [
                        'successful' => true,
                        'status' => 200,
                        'body' => json_encode($data, JSON_UNESCAPED_UNICODE),
                        'json' => $data,
                    ];
                }

                $data = [
                    'choices' => [
                        [
                            'message' => [
                                'role' => 'assistant',
                                'content' => json_encode([
                                    'sql' => 'SELECT * FROM DYNASTIES',
                                    'explanation' => '此查詢選擇所有朝代。',
                                    'error' => null,
                                ]),
                            ],
                            'finish_reason' => 'stop',
                        ],
                    ],
                ];

                return [
                    'successful' => true,
                    'status' => 200,
                    'body' => json_encode($data, JSON_UNESCAPED_UNICODE),
                    'json' => $data,
                ];
            }
        };

        $heartbeatCount = 0;

        $result = $service->generateSQL(
            '顯示所有朝代',
            null,
            function (string $event, array $data) {},
            true,
            function () use (&$heartbeatCount) {
                $heartbeatCount++;
            },
            fn () => false
        );

        $this->assertTrue($result['success']);
        // retry sleep (400ms) 使用 sleepWithHeartbeat，每 200ms 觸發一次
        // 加上 tool 執行前後的 heartbeat，總計應至少 4 次
        $this->assertGreaterThanOrEqual(4, $heartbeatCount);
    }

    #[Test]
    public function it_stops_tool_execution_when_abort_check_returns_true() {
        Config::set('nl_query_tools.enabled', true);
        Config::set('nl_query_tools.max_tool_calls', 2);

        $this->schemaService->method('generateSchemaPrompt')
            ->willReturn('Mock schema info');

        $toolExecutionCount = 0;
        $this->toolsService->method('executeTool')
            ->willReturnCallback(function () use (&$toolExecutionCount) {
                $toolExecutionCount++;

                return [
                    'success' => true,
                    'data' => ['rows' => []],
                    'error' => null,
                ];
            });

        $this->toolsService->method('getToolDefinitions')
            ->willReturn([]);

        $service = new class ($this->schemaService, $this->toolsService) extends NaturalLanguageQueryService {
            protected function performHeartbeatAwareLlmRequest(
                array $requestData,
                ?callable $heartbeatCallback = null,
                ?callable $abortCheck = null
            ): array {
                // 回傳兩個 tool calls
                $data = [
                    'choices' => [
                        [
                            'message' => [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [
                                    [
                                        'id' => 'call_001',
                                        'type' => 'function',
                                        'function' => [
                                            'name' => 'query_table_schema',
                                            'arguments' => json_encode(['table_name' => 'DYNASTIES']),
                                        ],
                                    ],
                                    [
                                        'id' => 'call_002',
                                        'type' => 'function',
                                        'function' => [
                                            'name' => 'query_table_schema',
                                            'arguments' => json_encode(['table_name' => 'BIOG_MAIN']),
                                        ],
                                    ],
                                ],
                            ],
                            'finish_reason' => 'tool_calls',
                        ],
                    ],
                ];

                return [
                    'successful' => true,
                    'status' => 200,
                    'body' => json_encode($data, JSON_UNESCAPED_UNICODE),
                    'json' => $data,
                ];
            }
        };

        $result = $service->generateSQL(
            '顯示所有朝代',
            null,
            function (string $event, array $data) {},
            true,
            null,
            function () use (&$toolExecutionCount) {
                // 第一個 tool 執行完後設定中止
                if ($toolExecutionCount >= 1) {
                    return true;
                }

                return false;
            }
        );

        // 應在第二個 tool 執行前中止
        $this->assertEquals(1, $toolExecutionCount);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Client disconnected', $result['error']);
    }
}
