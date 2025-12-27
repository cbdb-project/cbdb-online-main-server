<?php

namespace Tests\Unit;

use App\Services\DatabaseSchemaService;
use App\Services\NaturalLanguageQueryService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NaturalLanguageQueryServiceTest extends TestCase {
    protected NaturalLanguageQueryService $service;
    protected DatabaseSchemaService $schemaService;

    protected function setUp(): void {
        parent::setUp();

        // Mock config - 必须在创建服务之前设置
        Config::set('services.gemini.api_key', 'test-api-key');

        $this->schemaService = $this->createMock(DatabaseSchemaService::class);
        $this->service = new NaturalLanguageQueryService($this->schemaService);
    }

    /** @test */
    public function it_returns_error_when_api_key_is_not_configured() {
        Config::set('services.gemini.api_key', '');

        // 重新创建服务以使用新的配置
        $schemaService = $this->createMock(DatabaseSchemaService::class);
        $service = new NaturalLanguageQueryService($schemaService);

        $result = $service->generateSQL('test question');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('API Key', $result['error']);
    }

    /** @test */
    public function it_generates_sql_successfully() {
        // Mock schema service
        $this->schemaService->method('generateSchemaPrompt')
            ->willReturn('Mock schema info');

        // Mock HTTP response with structured output (JSON)
        Http::fake([
            '*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'sql' => 'SELECT * FROM DYNASTIES',
                                        'explanation' => '此查詢選擇所有朝代。',
                                    ]),
                                ],
                            ],
                        ],
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
    }

    /** @test */
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

    /** @test */
    public function it_handles_invalid_json_response() {
        $this->schemaService->method('generateSchemaPrompt')
            ->willReturn('Mock schema info');

        // Mock HTTP response with invalid JSON
        Http::fake([
            '*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => 'This is not valid JSON',
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->generateSQL('test question');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('JSON', $result['error']);
    }

    /** @test */
    public function it_handles_missing_sql_field() {
        $this->schemaService->method('generateSchemaPrompt')
            ->willReturn('Mock schema info');

        // Mock HTTP response with missing sql field
        Http::fake([
            '*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'explanation' => 'Some explanation',
                                    ]),
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->generateSQL('test question');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('缺少 SQL 字段', $result['error']);
    }
}
