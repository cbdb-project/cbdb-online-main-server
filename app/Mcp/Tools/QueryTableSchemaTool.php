<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\ReadOnlyTableQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class QueryTableSchemaTool extends Tool {
    public string $name = 'query_table_schema';

    public string $description = 'Get schema, indexes, and table metadata for an allowlisted table.';

    public function schema(JsonSchema $schema): array {
        return [
            'table_name' => $schema->string()->description('Allowlisted table name')->required(),
        ];
    }

    public function handle(Request $request): Response {
        $service = app(ReadOnlyTableQueryService::class);

        return Response::text(json_encode(
            $service->queryTableSchema((string) $request->get('table_name')),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ));
    }
}
