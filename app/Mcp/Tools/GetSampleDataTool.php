<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\ReadOnlyTableQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetSampleDataTool extends Tool {
    public string $name = 'get_sample_data';

    public string $description = 'Get sample rows from an allowlisted table with pagination.';

    public function schema(JsonSchema $schema): array {
        return [
            'table_name' => $schema->string()->description('Allowlisted table name')->required(),
            'limit' => $schema->integer()->description('Max rows to return (1-100)')->default(10),
            'offset' => $schema->integer()->description('Rows to skip')->default(0),
        ];
    }

    public function handle(Request $request): Response {
        $service = app(ReadOnlyTableQueryService::class);

        return Response::text(json_encode(
            $service->getSampleData(
                (string) $request->get('table_name'),
                (int) $request->get('limit', 10),
                (int) $request->get('offset', 0)
            ),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ));
    }
}
