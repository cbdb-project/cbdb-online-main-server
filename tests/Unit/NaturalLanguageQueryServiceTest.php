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
}
