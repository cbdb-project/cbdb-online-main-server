<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\ReadOnlyTableQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class QueryReadOnlySqlTool extends Tool {
    public string $name = 'query_read_only_sql';

    public string $description = 'Execute a read-only SQL query (SELECT/WITH only) against allowlisted tables.';

    public function schema(JsonSchema $schema): array {
        return [
            'sql' => $schema->string()->description('Read-only SQL (SELECT/WITH only)')->required(),
            'limit' => $schema->integer()->description('Max rows to return (1-100)')->default(20),
            'offset' => $schema->integer()->description('Rows to skip')->default(0),
        ];
    }

    public function handle(Request $request): Response {
        $service = app(ReadOnlyTableQueryService::class);

        return Response::text(json_encode(
            $service->queryReadOnlySql(
                (string) $request->get('sql'),
                (int) $request->get('limit', 20),
                (int) $request->get('offset', 0)
            ),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ));
    }
}
