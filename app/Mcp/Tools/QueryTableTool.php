<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\ReadOnlyTableQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class QueryTableTool extends Tool {
    public string $name = 'query_table';

    public string $description = 'Query an allowlisted table with optional filters, selected columns, and pagination.';

    public function schema(JsonSchema $schema): array {
        return [
            'table_name' => $schema->string()->description('Allowlisted table name')->required(),
            'filters' => $schema->object()->description('Key-value filters; use %value% for LIKE matching'),
            'columns' => $schema->array()->description('Optional selected columns. Defaults to all columns'),
            'limit' => $schema->integer()->description('Max rows to return (1-100)')->default(10),
            'offset' => $schema->integer()->description('Rows to skip')->default(0),
        ];
    }

    public function handle(Request $request): Response {
        $service = app(ReadOnlyTableQueryService::class);

        return Response::text(json_encode(
            $service->queryTable(
                (string) $request->get('table_name'),
                $request->get('filters'),
                $request->get('columns'),
                (int) $request->get('limit', 10),
                (int) $request->get('offset', 0)
            ),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ));
    }
}
