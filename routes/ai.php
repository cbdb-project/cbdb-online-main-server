<?php

use App\Mcp\Servers\CbdbReadOnlyServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

if (
    class_exists(Mcp::class)
    && class_exists(CbdbReadOnlyServer::class)
    && config('mcp.cbdb.enabled', true)
) {
    $requiredAbility = (string) config('mcp.cbdb.required_ability', 'mcp:read');

    /*
     * #1249：限流必須用**群組**套上，不能只掛在 Mcp::web() 回傳的那條路由上。
     *
     * Mcp::web() 會註冊兩條路由：一條 GET（MCP 規範要求的 SSE 佔位，恆回 405）與一條
     * POST（真正的伺服器）；它只回傳後者，所以 `->middleware()` 也只套到 POST。GET 因此
     * 原本沒有任何路由層 middleware，僅靠 api 群組寬鬆的 600/分把關——同一個 URI 一半有閘
     * 一半沒有，成了便宜的請求放大面。改用群組後兩條都涵蓋，且不必依賴「後註冊的同 URI
     * 路由會逐出前者」這種實作細節。
     *
     * throttle 用具名 limiter `mcp`（定義見 RouteServiceProvider::configureRateLimiting()）：
     * 數值型 `throttle:120,1` 的 key 只由 IP 決定，會與 api 群組的 600 桶相撞，導致實際上限
     * 腰斬成約 60/分、且與該 IP 的其他 /api 流量互相排擠。
     *
     * 另以 withoutMiddleware() 拿掉 api 群組繼承來的 `throttle:600,1`——只換成具名 limiter
     * 還不夠：那條 600 仍會套用，於是同 IP 的其他 /api 流量把 600 桶打滿時，MCP 自己的 120
     * 還有額度卻照樣 429。拿掉之後 MCP 才真的有一份獨立預算（120 本來就比 600 嚴格，不會放寬
     * 任何東西）。字串必須與 app/Http/Kernel.php 的 api 群組完全一致，這個耦合由
     * McpEndpointMiddlewareTest 斷言 resolved middleware 守住。
     *
     * GET 刻意**不掛 auth:sanctum**：回應是固定的 405、不碰資料庫、不含任何資料，加認證沒有
     * 保護到東西，卻會讓未認證的 MCP 客戶端在探測階段拿到 401 而非規範所期待的「本伺服器
     * 不支援 SSE」405。
     *
     * 已知且不在本次範圍：未認證的 POST 會在 401 時完全繞過限流——框架的
     * $middlewarePriority 把 AuthenticatesRequests 排在 ThrottleRequests 之前，這是全站
     * auth 路由的共同行為，不是此處的設定問題。
     */
    Route::middleware('throttle:mcp')->withoutMiddleware('throttle:600,1')->group(function () use ($requiredAbility) {
        Mcp::web('/api/mcp', CbdbReadOnlyServer::class)
            ->middleware([
                'auth:sanctum',
                "mcp.ability:{$requiredAbility}",
            ])
            ->name('mcp.cbdb');
    });
} else {
    Route::post('/api/mcp', static function () {
        return response()->json([
            'message' => 'MCP server is unavailable. Install laravel/mcp to enable this endpoint.',
        ], 503);
    })->middleware(['api']);
}
