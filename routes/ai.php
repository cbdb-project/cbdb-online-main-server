<?php

use App\Mcp\Servers\CbdbReadOnlyServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

if (
    class_exists(Mcp::class)
    && class_exists(CbdbReadOnlyServer::class)
    && config('mcp.cbdb.enabled', true)
) {
    $rateLimit = (int) config('mcp.cbdb.rate_limit_per_minute', 120);
    $requiredAbility = (string) config('mcp.cbdb.required_ability', 'mcp:read');

    Mcp::web('/api/mcp', CbdbReadOnlyServer::class)
        ->middleware([
            'auth:sanctum',
            "mcp.ability:{$requiredAbility}",
            "throttle:{$rateLimit},1",
        ])
        ->name('mcp.cbdb');
} else {
    Route::post('/api/mcp', static function () {
        return response()->json([
            'message' => 'MCP server is unavailable. Install laravel/mcp to enable this endpoint.',
        ], 503);
    })->middleware(['api']);
}
