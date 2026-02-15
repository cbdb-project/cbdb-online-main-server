<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\ReadOnlyTableQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetTableRowByIdTool extends Tool {
    public string $name = 'get_table_row_by_id';

    public string $description = 'Fetch one row from an allowlisted table by ID column and value.';

    public function schema(JsonSchema $schema): array {
        return [
            'table_name' => $schema->string()->description('Allowlisted table name')->required(),
            'id_column' => $schema->string()->description('ID column name')->required(),
            'id_value' => $schema->string()->description('ID value to match')->required(),
        ];
    }

    public function handle(Request $request): Response {
        $service = app(ReadOnlyTableQueryService::class);

        return Response::text(json_encode(
            $service->getTableRowById(
                (string) $request->get('table_name'),
                (string) $request->get('id_column'),
                $request->get('id_value')
            ),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ));
    }
}
