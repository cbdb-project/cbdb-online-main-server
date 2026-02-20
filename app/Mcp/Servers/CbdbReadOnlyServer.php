<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetSampleDataTool;
use App\Mcp\Tools\GetTableRowByIdTool;
use App\Mcp\Tools\ListAllowedTablesTool;
use App\Mcp\Tools\QueryReadOnlySqlTool;
use App\Mcp\Tools\QueryTableSchemaTool;
use App\Mcp\Tools\QueryTableTool;
use Laravel\Mcp\Server;

class CbdbReadOnlyServer extends Server {
    protected string $name = 'CBDB Read-Only Database MCP Server';

    protected string $version = '1.0.0';

    protected string $instructions = 'Read-only MCP tools for allowlisted CBDB tables.';

    protected array $tools = [
        ListAllowedTablesTool::class,
        QueryTableSchemaTool::class,
        GetTableRowByIdTool::class,
        GetSampleDataTool::class,
        QueryTableTool::class,
        QueryReadOnlySqlTool::class,
    ];
}
