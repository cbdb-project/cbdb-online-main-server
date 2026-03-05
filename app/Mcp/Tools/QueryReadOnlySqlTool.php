<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\ReadOnlyTableQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use JsonException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Throwable;

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

        try {
            $result = $service->queryReadOnlySql(
                (string) $request->get('sql'),
                (int) $request->get('limit', 20),
                (int) $request->get('offset', 0)
            );

            return Response::text(json_encode(
                $result,
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ));
        } catch (JsonException $e) {
            return Response::error('query_read_only_sql 返回结果无法编码为 JSON（可能包含无效字符）');
        } catch (Throwable $e) {
            return Response::error('query_read_only_sql 执行失败：' . $e->getMessage());
        }
    }
}
