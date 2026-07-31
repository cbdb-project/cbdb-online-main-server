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
