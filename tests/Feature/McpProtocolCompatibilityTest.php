<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * laravel/mcp 1.0 起預設改說 MCP 2026-07-28（以 server/discover 取代 initialize 握手，
 * 每個請求在 params._meta 帶協議版本，HTTP 另需 MCP-Protocol-Version／Mcp-Method／Mcp-Name 標頭）。
 *
 * 官方升級指南（UPGRADE.md）承諾仍以 initialize 開場的舊客戶端照常運作——我們對外提供的
 * POST /api/mcp 有外部客戶端（Claude Desktop、Cursor 等）在用，升級是否真的不斷線，
 * 以前沒有任何測試走過真實的協議流程，這份測試就是補這個洞。
 */
class McpProtocolCompatibilityTest extends TestCase {
    use RefreshDatabase;

    private const MODERN = '2026-07-28';

    private const EXPECTED_TOOLS = [
        'list_allowed_tables',
        'query_table_schema',
        'get_table_row_by_id',
        'get_sample_data',
        'query_table',
        'query_read_only_sql',
        'search_person_by_name',
    ];

    protected function setUp(): void {
        parent::setUp();

        Sanctum::actingAs(
            User::factory()->create(['is_active' => User::STATUS_ACTIVE, 'is_admin' => User::ROLE_REGULAR]),
            ['mcp:read']
        );
    }

    private function rpc(string $method, array $params = [], array $headers = [], int $id = 1) {
        return $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => (object) $params,
        ], $headers + ['Accept' => 'application/json']);
    }

    private function modernMeta(): array {
        return ['_meta' => [
            'io.modelcontextprotocol/protocolVersion' => self::MODERN,
            'io.modelcontextprotocol/clientCapabilities' => (object) [],
        ]];
    }

    private function modernHeaders(string $method, ?string $name = null): array {
        return array_filter([
            'MCP-Protocol-Version' => self::MODERN,
            'Mcp-Method' => $method,
            'Mcp-Name' => $name,
        ]);
    }

    // ───────── 舊客戶端：以 initialize 開場，之後的請求沒有 _meta、沒有 MCP 標頭 ─────────

    public function test_legacy_client_can_initialize_with_2025_06_18() {
        $response = $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'legacy-client', 'version' => '1.0.0'],
        ]);

        $response->assertOk();
        $this->assertSame('2025-06-18', $response->json('result.protocolVersion'), '應以客戶端要求的協議版本回應。');
        $this->assertSame('CBDB Read-Only Database MCP Server', $response->json('result.serverInfo.name'));
        $this->assertArrayHasKey('tools', (array) $response->json('result.capabilities'));
    }

    public function test_legacy_client_with_2025_11_25_and_2025_03_26_are_answered_with_the_requested_version() {
        foreach (['2025-11-25', '2025-03-26'] as $version) {
            $response = $this->rpc('initialize', [
                'protocolVersion' => $version,
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'legacy-client', 'version' => '1.0.0'],
            ]);

            $response->assertOk();
            $this->assertSame($version, $response->json('result.protocolVersion'));
        }
    }

    public function test_the_oldest_protocol_2024_11_05_is_offered_2025_11_25_instead() {
        $response = $this->rpc('initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'oldest-client', 'version' => '1.0.0'],
        ]);

        $response->assertOk();
        $this->assertSame('2025-11-25', $response->json('result.protocolVersion'), '官方文件：2024-11-05 的客戶端會被提議 2025-11-25。');
    }

    /**
     * 2025-06-18 起的客戶端在 initialize 之後，每個請求都會帶 `MCP-Protocol-Version: <協商出的版本>` 標頭
     * （但仍沒有 _meta、也沒有 Mcp-Method）。這才是舊客戶端的真實流程，不能只測「一個標頭都不帶」。
     */
    public function test_legacy_client_following_the_real_flow_sends_the_negotiated_version_header() {
        foreach (['2025-11-25', '2025-06-18', '2025-03-26'] as $version) {
            $list = $this->rpc('tools/list', [], ['MCP-Protocol-Version' => $version]);
            $list->assertOk();
            $this->assertCount(count(self::EXPECTED_TOOLS), $list->json('result.tools'), "版本頭 {$version}");

            $call = $this->rpc(
                'tools/call',
                ['name' => 'list_allowed_tables', 'arguments' => (object) []],
                ['MCP-Protocol-Version' => $version]
            );
            $call->assertOk();
            $this->assertNull($call->json('error'), "版本頭 {$version}：".json_encode($call->json('error')));
        }
    }

    /**
     * 升級後最可能讓個別舊 SDK 失敗的路徑：沒有 _meta 的舊式請求若帶了「不在 2025-11-25／2025-06-18／2025-03-26」
     * 的 MCP-Protocol-Version 標頭（例如仍停在 2024-11-05 的 SDK），會被拒絕（HTTP 400、-32022）。
     */
    public function test_legacy_request_carrying_an_unsupported_protocol_version_header_is_rejected_with_32022() {
        foreach (['2024-11-05', '2099-01-01'] as $version) {
            $response = $this->rpc('tools/list', [], ['MCP-Protocol-Version' => $version]);

            $response->assertStatus(400);
            $this->assertSame(-32022, $response->json('error.code'), "版本頭 {$version}");
        }
    }

    public function test_initialized_notification_is_accepted_with_202() {
        $response = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ], ['Accept' => 'application/json', 'MCP-Protocol-Version' => '2025-06-18']);

        $response->assertStatus(202);
    }

    /**
     * 行為變動：0.5.7 對每個有回覆的請求（含 JSON-RPC 錯誤）都回 HTTP 200，1.x 把 JSON-RPC 錯誤映射成
     * HTTP 4xx／5xx。只看 HTTP 狀態碼、或遇到 4xx 就丟掉回應本體的客戶端會看到以前沒有的錯誤。
     * 工具「執行失敗」（isError）不是 JSON-RPC 錯誤，仍是 200。
     */
    public function test_json_rpc_errors_are_mapped_to_http_error_statuses() {
        $unknown = $this->rpc('no/such/method');
        $unknown->assertStatus(404);
        $this->assertSame(-32601, $unknown->json('error.code'));

        $invalidTool = $this->rpc('tools/call', ['name' => 'no_such_tool', 'arguments' => (object) []]);
        $invalidTool->assertStatus(400);
        $this->assertSame(-32602, $invalidTool->json('error.code'));
    }

    public function test_delete_on_the_endpoint_is_a_method_not_allowed_placeholder_like_get() {
        $this->delete('/api/mcp')->assertStatus(405);
    }

    public function test_legacy_client_lists_all_seven_tools_without_meta_or_mcp_headers() {
        $response = $this->rpc('tools/list');

        $response->assertOk();
        $names = collect($response->json('result.tools'))->pluck('name')->all();
        foreach (self::EXPECTED_TOOLS as $tool) {
            $this->assertContains($tool, $names, "工具 {$tool} 應仍由 tools/list 對外提供。");
        }
        $this->assertCount(count(self::EXPECTED_TOOLS), $names);

        // 每個工具都要有 inputSchema（客戶端靠它組參數）
        foreach ($response->json('result.tools') as $tool) {
            $this->assertIsArray($tool['inputSchema'] ?? null, "{$tool['name']} 缺少 inputSchema");
        }
    }

    public function test_legacy_client_can_call_a_tool_without_meta_or_mcp_headers() {
        $response = $this->rpc('tools/call', ['name' => 'list_allowed_tables', 'arguments' => (object) []]);

        $response->assertOk();
        $this->assertNull($response->json('error'), 'tools/call 不應回 JSON-RPC 錯誤：'.json_encode($response->json('error')));
        $this->assertFalse((bool) $response->json('result.isError'));

        $payload = json_decode((string) $response->json('result.content.0.text'), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('allowed_tables', $payload);
        $this->assertArrayHasKey('count', $payload);
    }

    // ───────── 新客戶端：2026-07-28 ─────────

    public function test_modern_client_discovers_the_server() {
        $response = $this->rpc('server/discover', $this->modernMeta(), $this->modernHeaders('server/discover'));

        $response->assertOk();
        $this->assertContains(self::MODERN, $response->json('result.supportedVersions'));
        $this->assertArrayHasKey('tools', (array) $response->json('result.capabilities'));
    }

    public function test_modern_client_lists_tools_with_meta_and_headers() {
        $response = $this->rpc('tools/list', $this->modernMeta(), $this->modernHeaders('tools/list'));

        $response->assertOk();
        $names = collect($response->json('result.tools'))->pluck('name')->all();
        foreach (self::EXPECTED_TOOLS as $tool) {
            $this->assertContains($tool, $names);
        }
    }

    public function test_modern_client_calls_a_tool_with_meta_and_headers() {
        $response = $this->rpc(
            'tools/call',
            ['name' => 'list_allowed_tables', 'arguments' => (object) []] + $this->modernMeta(),
            $this->modernHeaders('tools/call', 'list_allowed_tables')
        );

        $response->assertOk();
        $this->assertNull($response->json('error'), json_encode($response->json('error')));
        $this->assertFalse((bool) $response->json('result.isError'));
    }

    public function test_modern_request_with_a_mismatched_method_header_is_rejected_with_32020() {
        $response = $this->rpc('tools/list', $this->modernMeta(), $this->modernHeaders('tools/call'));

        $response->assertStatus(400);
        $this->assertSame(-32020, $response->json('error.code'));
    }

    public function test_modern_request_missing_the_required_headers_is_rejected() {
        $response = $this->rpc('tools/list', $this->modernMeta());

        $response->assertStatus(400);
        $this->assertSame(-32020, $response->json('error.code'));
    }
}
