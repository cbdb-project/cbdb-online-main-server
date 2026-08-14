<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider {
    /**
     * This namespace is applied to your controller routes.
     *
     * In addition, it is set as the URL generator's root namespace.
     *
     * @var string
     */
    protected $namespace = 'App\Http\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot() {
        $this->configureRateLimiting();

        parent::boot();
    }

    /**
     * 見 docs/QUERY_PLAYGROUND_QA_MULTITURN_PLAN.md 第 6.4 節：QA 模式多輪追問每輪都會呼叫
     * 一次 LLM API，按登入使用者限流。用具名 limiter 而非路由字串內插數字，讓 callback
     * 於每次請求時才讀取 config，可在測試中用 config(['query_playground.qa_rate_limit_per_minute' => N])
     * 動態調整，避免路由註冊當下就把上限值定死。
     *
     * 註冊時機不依賴 boot() 內呼叫順序：Laravel 基底 RouteServiceProvider::boot() 是空實作，
     * 實際路由載入是 register() 內透過 $this->app->booted(...) 註冊的延遲回呼，要等所有
     * provider 的 boot() 都執行完才會觸發，所以只要 configureRateLimiting() 在 boot() 階段
     * 執行過（不論呼叫順序），路由解析當下這個具名 limiter 一定已註冊。
     */
    protected function configureRateLimiting() {
        RateLimiter::for('qa-answer', function (Request $request) {
            $limit = (int) config('query_playground.qa_rate_limit_per_minute', 10);

            return Limit::perMinute($limit)->by($request->user()?->id ?: $request->ip());
        });

        /*
         * MCP 端點（#1249）。**必須是具名 limiter，不能寫成 `throttle:120,1`**：
         *
         * 數值型 throttle 的 key 是 `sha1(domain|ip)`——不含路由、方法與上限值
         * （ThrottleRequests::resolveRequestSignature()）。因此路由上的 `throttle:120,1`
         * 會與 api 群組的 `throttle:600,1` **共用同一個計數器**，實測後果有兩個：
         *   1. 每個請求被 hit() 兩次 → 120 的上限實際只有約 60/分；
         *   2. 該 IP 打其他 /api/* 端點也會吃掉 MCP 的額度（反之亦然），於是同一個 NAT
         *      後面的機構共用這個桶，合法 MCP 客戶端可能在探測階段就拿到 429。
         *
         * 具名 limiter 的 key 是 `md5($limiterName.$limit->key)`，有命名空間隔離，
         * 上限才是真正的 config 值。與上面 qa-answer 同一個理由：callback 在每次請求時
         * 才讀 config，測試可用 config([...]) 動態調整。
         */
        RateLimiter::for('mcp', function (Request $request) {
            return Limit::perMinute(self::mcpRateLimit())->by($request->user()?->id ?: $request->ip());
        });
    }

    /** MCP 端點在設定值不合理時退回的預設上限（與 config/mcp.php 的預設一致）。 */
    public const MCP_RATE_LIMIT_DEFAULT = 120;

    /**
     * MCP 端點限流的上限，且**永不放寬到超過 api 群組的 600/分**。
     *
     * 為什麼需要夾範圍（#1249 codex 覆核）：
     *  - `MCP_RATE_LIMIT_PER_MINUTE=0`／負數不會變成「零額度」，`Limit::perMinute(0)` 實測是
     *    「第一個請求仍放行、第二個才 429」——一個想關閉端點的設定值反而變成幾乎不設限。
     *    這種值只可能是設定錯誤，退回預設比照字面解讀安全。
     *  - MCP 群組已用 withoutMiddleware() 拿掉 api 群組繼承的 `throttle:600,1`（否則其他 /api
     *    流量會吃掉 MCP 的額度），代價是這裡若填一個大於 600 的值，就會**放寬**原本的全站
     *    上限。上限夾在 600 讓「不放寬任何東西」這個承諾在設定層也成立。
     */
    public static function mcpRateLimit(): int {
        $configured = (int) config('mcp.cbdb.rate_limit_per_minute', self::MCP_RATE_LIMIT_DEFAULT);

        if ($configured < 1) {
            $configured = self::MCP_RATE_LIMIT_DEFAULT;
        }

        return min($configured, 600);
    }

    /**
     * Define the routes for the application.
     *
     * @return void
     */
    public function map() {
        $this->mapApiRoutes();

        $this->mapAiRoutes();

        $this->mapWebRoutes();

        //
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     *
     * @return void
     */
    protected function mapWebRoutes() {
        Route::middleware('web')
             ->namespace($this->namespace)
             ->group(base_path('routes/web.php'));
    }

    /**
     * Define the "api" routes for the application.
     *
     * These routes are typically stateless.
     *
     * @return void
     */
    protected function mapApiRoutes() {
        Route::prefix('api')
             ->middleware('api')
             ->namespace($this->namespace)
             ->group(base_path('routes/api.php'));
    }

    /**
     * Define MCP routes.
     *
     * These routes are API-like and stateless.
     *
     * @return void
     */
    protected function mapAiRoutes() {
        Route::middleware('api')
            ->namespace($this->namespace)
            ->group(base_path('routes/ai.php'));
    }
}
