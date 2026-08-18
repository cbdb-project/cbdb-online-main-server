<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel {
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array
     */
    protected $middleware = [
        \Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
        // 20200324建安新增 0909遮除
        // \App\Http\Middleware\EnableCrossRequestMiddleware::class,
        // 20200909建安新增
        \App\Http\Middleware\Cors::class,
        // Prometheus metrics 收集
        \App\Http\Middleware\PrometheusMetrics::class,
        // 「帶 Bearer token 卻認證失敗」的次數封頂（#1254）。**必須留在全域 stack**：
        // 全域 middleware 不受 $middlewarePriority 排序影響，所以它一定在路由的 auth 之前執行
        //（框架把 AuthenticatesRequests 排在 ThrottleRequests 之前，未認證請求本來會在認證階段
        // 就返回、完全繞過路由自己的限流），而且路由的 withoutMiddleware() 也排除不掉它。
        // 排在 Cors 與 PrometheusMetrics 之後，讓它們包住這一層：429 一樣帶 CORS 標頭、
        // 一樣會被指標記到（不過短路發生在路由 dispatch 之前，指標的 path label 會是 __unknown__）。
        \App\Http\Middleware\ThrottleFailedAuthentication::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            // \Illuminate\Session\Middleware\AuthenticateSession::class,
            \App\Http\Middleware\SetLocaleMiddleware::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
        /**
         *20211112建安修改
         *Laravel has a default throttle limit for all api routes.
         *60 >> 600 attempts then locked out for 1 minute
         *
         * Laravel Sanctum for SPA and API token authentication
         */
        'api' => [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            'throttle:600,1',
            'bindings',
        ],
    ];

    /**
     * The application's middleware aliases.
     *
     * Aliases may be used instead of class names to assign middleware to routes and groups.
     *
     * @var array<string, class-string|string>
     */
    protected $middlewareAliases = [
        // 覆寫版：認證通過後再複查 is_active（同時覆蓋 auth 與 auth:sanctum）。
        'auth' => \App\Http\Middleware\Authenticate::class,
        'legacy.form' => \App\Http\Middleware\LegacyBladeFormGate::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'auth.optional' => \App\Http\Middleware\OptionalAuthentication::class,
        'superadmin' => \App\Http\Middleware\RequireSuperAdmin::class,
        'mcp.ability' => \App\Http\Middleware\EnsureMcpAbility::class,
        'bindings' => \Illuminate\Routing\Middleware\SubstituteBindings::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        // 未認證表單端點（註冊／忘記密碼／重設密碼）的限流，超額回應是 302 + 欄位錯誤而非 HTML 429
        //（Inertia 收到非 Inertia 回應會彈黑底 modal），見 #1264。
        'throttle.guest' => \App\Http\Middleware\ThrottleGuestAuthRequests::class,
        // 20200909建安新增
        'cors' => \App\Http\Middleware\Cors::class,
        'inertia' => \App\Http\Middleware\HandleInertiaRequests::class,
    ];
}
