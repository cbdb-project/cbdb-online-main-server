<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\ReadOnlyTableQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListAllowedTablesTool extends Tool {
    public string $name = 'list_allowed_tables';

    public string $description = 'List all allowlisted tables that MCP can query.';

    public function schema(JsonSchema $schema): array {
        return [
            'keyword' => $schema->string()
                ->description('Optional keyword filter for table names (case-insensitive).'),
        ];
    }

    public function handle(Request $request): Response {
        $service = app(ReadOnlyTableQueryService::class);
        $tables = $service->listAllowedTables();
        $keyword = trim((string) $request->get('keyword', ''));

        if ($keyword !== '') {
            $tables = array_values(array_filter($tables, static function ($table) use ($keyword) {
                return stripos((string) $table, $keyword) !== false;
            }));
        }

        return Response::text(json_encode([
            'allowed_tables' => $tables,
            'count' => count($tables),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
