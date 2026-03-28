<?php

namespace Tests\Unit;

use App\Services\DatabaseSchemaService;
use App\Services\NaturalLanguageQueryService;
use App\Services\NlQueryToolsService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for enriched tool trace payload (arguments, status, result_summary).
 *
 * We test the behaviour of executeToolCalls() and summarizeToolResult()
 * by injecting mock NlQueryToolsService instances rather than hitting the DB.
 */
class ToolTracePayloadTest extends TestCase {
    private function makeService(NlQueryToolsService $toolsService): NaturalLanguageQueryService {
        $schemaService = $this->createMock(DatabaseSchemaService::class);
        $schemaService->method('generateSchemaPrompt')->willReturn('');
        $schemaService->method('getSchemaInfo')->willReturn([]);

        // Ensure config values are set to prevent TypeError on typed string properties
        config([
            'services.gemini.api_key' => 'test-key',
            'services.gemini.api_endpoint' => 'https://example.com/api',
            'services.gemini.model' => 'test-model',
            'nl_query_tools.enabled' => true,
            'nl_query_tools.max_tool_calls' => 5,
        ]);

        $service = new NaturalLanguageQueryService($schemaService, $toolsService);

        return $service;
    }

    /** Build an LLM-style tool_calls array (the format LLM returns) */
    private function llmToolCall(string $name, array $arguments, string $id = 'call-001'): array {
        return [
            'id' => $id,
            'function' => [
                'name' => $name,
                'arguments' => json_encode($arguments),
            ],
        ];
    }

    /** Access protected method via reflection */
    private function callProtected(object $object, string $method, array $args = []): mixed {
        $ref = new \ReflectionMethod($object, $method);

        return $ref->invokeArgs($object, $args);
    }

    // ──── executeToolCalls: payload structure ────────────────────────────────

    #[Test]
    public function tool_trace_payload_contains_arguments(): void {
        $toolsService = $this->createMock(NlQueryToolsService::class);
        $toolsService->method('executeTool')->willReturn([
            'success' => true,
            'data' => [],
            'error' => null,
        ]);

        $service = $this->makeService($toolsService);
        $llmCalls = [$this->llmToolCall('list_allowed_tables', [])];

        $result = $this->callProtected($service, 'executeToolCalls', [$llmCalls]);

        $this->assertArrayHasKey('arguments', $result[0]);
    }

    #[Test]
    public function tool_trace_payload_contains_status(): void {
        $toolsService = $this->createMock(NlQueryToolsService::class);
        $toolsService->method('executeTool')->willReturn([
            'success' => true,
            'data' => [],
            'error' => null,
        ]);

        $service = $this->makeService($toolsService);
        $llmCalls = [$this->llmToolCall('list_allowed_tables', [])];

        $result = $this->callProtected($service, 'executeToolCalls', [$llmCalls]);

        $this->assertArrayHasKey('status', $result[0]);
        $this->assertSame('completed', $result[0]['status']);
    }

    #[Test]
    public function tool_trace_payload_contains_result_summary(): void {
        $toolsService = $this->createMock(NlQueryToolsService::class);
        $toolsService->method('executeTool')->willReturn([
            'success' => true,
            'data' => ['table_a', 'table_b'],
            'error' => null,
        ]);

        $service = $this->makeService($toolsService);
        $llmCalls = [$this->llmToolCall('list_allowed_tables', [])];

        $result = $this->callProtected($service, 'executeToolCalls', [$llmCalls]);

        $this->assertArrayHasKey('result_summary', $result[0]);
        $this->assertIsArray($result[0]['result_summary']);
    }

    #[Test]
    public function error_tool_trace_has_status_error(): void {
        $toolsService = $this->createMock(NlQueryToolsService::class);
        $toolsService->method('executeTool')->willReturn([
            'success' => false,
            'data' => null,
            'error' => '工具執行失敗，表格不在白名單',
        ]);

        $service = $this->makeService($toolsService);
        $llmCalls = [$this->llmToolCall('query_table', ['table_name' => 'FORBIDDEN'])];

        $result = $this->callProtected($service, 'executeToolCalls', [$llmCalls]);

        $this->assertSame('error', $result[0]['status']);
        $this->assertSame('error', $result[0]['result_summary']['status']);
        $this->assertStringContainsString('失敗', $result[0]['result_summary']['error']);
    }

    // ──── SSE event enrichment ───────────────────────────────────────────────

    #[Test]
    public function tool_execution_complete_event_contains_result_summary(): void {
        $toolsService = $this->createMock(NlQueryToolsService::class);
        $toolsService->method('executeTool')->willReturn([
            'success' => true,
            'data' => [],
            'count' => 0,
            'error' => null,
        ]);

        $service = $this->makeService($toolsService);
        $llmCalls = [$this->llmToolCall('get_person_ids', ['person_name' => '李白'])];

        $captured = [];
        $callback = function ($event, $data) use (&$captured) {
            $captured[] = ['event' => $event, 'data' => $data];
        };

        $this->callProtected($service, 'executeToolCalls', [$llmCalls, $callback]);

        $completeEvents = array_filter($captured, fn ($e) => $e['event'] === 'tool_execution_complete');
        $this->assertNotEmpty($completeEvents, 'Should have a tool_execution_complete event');
        $completeEvent = array_values($completeEvents)[0];
        $this->assertArrayHasKey('result_summary', $completeEvent['data']);
        $this->assertArrayHasKey('arguments', $completeEvent['data']);
        $this->assertArrayHasKey('status', $completeEvent['data']);
    }

    #[Test]
    public function tool_execution_start_event_contains_arguments(): void {
        $toolsService = $this->createMock(NlQueryToolsService::class);
        $toolsService->method('executeTool')->willReturn([
            'success' => true,
            'data' => null,
            'error' => null,
        ]);

        $service = $this->makeService($toolsService);
        $args = ['person_name' => '王安石', 'limit' => 5];
        $llmCalls = [$this->llmToolCall('get_person_ids', $args)];

        $captured = [];
        $callback = function ($event, $data) use (&$captured) {
            $captured[] = ['event' => $event, 'data' => $data];
        };

        $this->callProtected($service, 'executeToolCalls', [$llmCalls, $callback]);

        $startEvents = array_filter($captured, fn ($e) => $e['event'] === 'tool_execution_start');
        $this->assertNotEmpty($startEvents);
        $startEvent = array_values($startEvents)[0];
        $this->assertArrayHasKey('arguments', $startEvent['data']);
        $this->assertSame($args, $startEvent['data']['arguments']);
    }

    // ──── summarizeToolResult: per-tool summaries ────────────────────────────

    #[Test]
    public function summarize_query_read_only_sql_includes_sql_and_row_count(): void {
        $service = $this->makeService($this->createMock(NlQueryToolsService::class));
        $sql = 'SELECT c_personid, c_name_chn FROM BIOG_MAIN WHERE c_personid = 1';
        $result = [
            'success' => true,
            'data' => [
                'sql' => $sql,
                'tables' => ['BIOG_MAIN'],
                'returned_rows' => 1,
                'rows' => [['c_personid' => 1, 'c_name_chn' => '李白']],
            ],
            'error' => null,
        ];

        $summary = $this->callProtected($service, 'summarizeToolResult', [
            'query_read_only_sql',
            ['sql' => $sql, 'limit' => 20],
            $result,
        ]);

        $this->assertSame('completed', $summary['status']);
        $this->assertSame($sql, $summary['sql']);
        $this->assertSame(1, $summary['row_count']);
        $this->assertContains('c_personid', $summary['columns']);
        $this->assertNotEmpty($summary['preview']);
    }

    #[Test]
    public function summarize_get_person_ids_includes_count_and_names(): void {
        $service = $this->makeService($this->createMock(NlQueryToolsService::class));
        $result = [
            'success' => true,
            'data' => [
                ['c_personid' => 123, 'c_name_chn' => '李白', 'c_name' => 'Li Bai', 'c_dynasty_chn' => '唐'],
                ['c_personid' => 456, 'c_name_chn' => '李白2', 'c_name' => 'Li Bai 2', 'c_dynasty_chn' => '唐'],
            ],
            'count' => 2,
            'error' => null,
        ];

        $summary = $this->callProtected($service, 'summarizeToolResult', [
            'get_person_ids',
            ['person_name' => '李白'],
            $result,
        ]);

        $this->assertSame('completed', $summary['status']);
        $this->assertSame(2, $summary['count']);
        $this->assertContains(123, $summary['person_ids']);
        $this->assertContains('李白', $summary['names']);
    }

    #[Test]
    public function summarize_error_result_returns_error_status(): void {
        $service = $this->makeService($this->createMock(NlQueryToolsService::class));
        $result = [
            'success' => false,
            'data' => null,
            'error' => '表格不在白名單中',
        ];

        $summary = $this->callProtected($service, 'summarizeToolResult', [
            'query_table',
            ['table_name' => 'FORBIDDEN'],
            $result,
        ]);

        $this->assertSame('error', $summary['status']);
        $this->assertStringContainsString('白名單', $summary['error']);
    }

    #[Test]
    public function summarize_query_table_includes_row_count_and_columns(): void {
        $service = $this->makeService($this->createMock(NlQueryToolsService::class));
        $result = [
            'success' => true,
            'data' => [
                'table_name' => 'ALTNAME_DATA',
                'filters' => ['c_personid' => 123],
                'columns' => ['*'],
                'total_matching_rows' => 5,
                'limit' => 3,
                'offset' => 0,
                'returned_rows' => 3,
                'rows' => [
                    ['c_personid' => 123, 'c_alt_name_chn' => '太白'],
                    ['c_personid' => 123, 'c_alt_name_chn' => '謫仙人'],
                    ['c_personid' => 123, 'c_alt_name_chn' => '青蓮居士'],
                ],
            ],
            'error' => null,
        ];

        $summary = $this->callProtected($service, 'summarizeToolResult', [
            'query_table',
            ['table_name' => 'ALTNAME_DATA', 'filters' => ['c_personid' => 123]],
            $result,
        ]);

        $this->assertSame('completed', $summary['status']);
        $this->assertSame(3, $summary['row_count']);
        $this->assertSame(5, $summary['total_matching']);
        $this->assertContains('c_alt_name_chn', $summary['columns']);
        $this->assertCount(3, $summary['preview']);
    }

    #[Test]
    public function summarize_get_table_row_by_id_reports_found_status(): void {
        $service = $this->makeService($this->createMock(NlQueryToolsService::class));
        $result = [
            'success' => true,
            'data' => [
                'table_name' => 'BIOG_MAIN',
                'id_column' => 'c_personid',
                'id_value' => 123,
                'row' => ['c_personid' => 123, 'c_name_chn' => '李白', 'c_dynasty_id' => 6],
            ],
            'error' => null,
        ];

        $summary = $this->callProtected($service, 'summarizeToolResult', [
            'get_table_row_by_id',
            ['table_name' => 'BIOG_MAIN', 'id_column' => 'c_personid', 'id_value' => 123],
            $result,
        ]);

        $this->assertSame('completed', $summary['status']);
        $this->assertTrue($summary['found']);
        $this->assertSame('BIOG_MAIN', $summary['table_name']);
        $this->assertArrayHasKey('c_name_chn', $summary['row_preview']);
    }

    #[Test]
    public function summarize_query_read_only_sql_sql_is_accessible_in_arguments(): void {
        // This test verifies that SQL is preserved in the arguments field
        // (which is always passed through to the frontend)
        $toolsService = $this->createMock(NlQueryToolsService::class);
        $expectedSql = 'SELECT * FROM DYNASTIES';
        $toolsService->method('executeTool')->willReturn([
            'success' => true,
            'data' => [
                'sql' => $expectedSql,
                'tables' => ['DYNASTIES'],
                'returned_rows' => 0,
                'rows' => [],
            ],
            'error' => null,
        ]);

        $service = $this->makeService($toolsService);
        $llmCalls = [$this->llmToolCall('query_read_only_sql', ['sql' => $expectedSql, 'limit' => 10])];

        $result = $this->callProtected($service, 'executeToolCalls', [$llmCalls]);

        // SQL is in arguments
        $this->assertSame($expectedSql, $result[0]['arguments']['sql']);
        // SQL is also in result_summary for easy frontend access
        $this->assertSame($expectedSql, $result[0]['result_summary']['sql']);
    }
}
