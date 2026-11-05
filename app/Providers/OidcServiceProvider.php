<?php

namespace App\Providers;

use App\Http\Middleware\EnsureOidcUserIsActive;
use App\Http\Middleware\RequireOpenIdScope;
use App\Http\Middleware\RequirePkceS256;
use App\Http\Middleware\RestrictOidcGrantTypes;
use App\Http\Responses\OidcConsentRequiredResponse;
use App\Models\OidcClient;
use DateInterval;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Passport;

/**
 * OIDC Provider（docs/OIDC_PROVIDER_PLAN.md）：把本專案的後台登入提供給其他專案。
 *
 * 總開關 config('oidc.enabled')（env OIDC_PROVIDER_ENABLED，預設 false）。**關閉時什麼都不做**——
 * Passport 的路由本來就由 AuthServiceProvider::register() 關閉，所以兩個端點都不存在。
 *
 * 開啟時**只**註冊 `GET /oauth/authorize` 與 `POST /oauth/token`。Passport 的完整路由集還有
 * `/oauth/clients`（任何已登入使用者都能建立 OAuth client）、`/oauth/personal-access-tokens`、
 * `/oauth/tokens`、裝置授權等，全都不要，所以不能靠拿掉 `Passport::ignoreRoutes()` 來放行。
 *
 * 路由用 class 參照而非 closure，才能通過 `route:cache`。
 */
class OidcServiceProvider extends ServiceProvider {
    public function boot(): void {
        if (!config('oidc.enabled')) {
            return;
        }

        $this->configurePassport();
        $this->registerRoutes();
    }

    private function configurePassport(): void {
        Passport::useClientModel(OidcClient::class);

        // 套件預設還有 phone／address；本專案只開放 config/openid.php 列出的 scope。
        //
        // 放在 booted()：laravel/mcp 的 McpServiceProvider 也在 booted() 回調裡把 `mcp:use` **合併**進
        // Passport::$scopes（它的回調先註冊、先執行）。這裡用 tokensCan() **整個取代**，才會把它蓋掉——
        // 否則 RP 能申請 `mcp:use` 這個與登入無關的 scope。順序依賴由 OidcAuthorizationFlowTest 鎖住。
        $this->app->booted(function (): void {
            Passport::tokensCan(config('openid.passport.tokens_can'));
        });

        // Passport 的預設壽命是一年。沒有單點登出，這兩個值就是「帳號被停用後已簽出的 token 還能活多久」。
        Passport::tokensExpireIn(new DateInterval('PT' . (int) config('oidc.access_token_ttl_minutes') . 'M'));
        Passport::refreshTokensExpireIn(new DateInterval('P' . (int) config('oidc.refresh_token_ttl_days') . 'D'));

        // 沒有同意頁：需要同意的情況（prompt=consent、非 first-party client）回 OIDC 錯誤。
        // Passport 13 若沒綁這個，/oauth/authorize 會 BindingResolutionException。
        $this->app->bind(AuthorizationViewResponse::class, OidcConsentRequiredResponse::class);
    }

    private function registerRoutes(): void {
        Route::prefix('oauth')->group(function () {
            // 路由名稱沿用 Passport 的，套件內部會以名稱反查。
            Route::get('/authorize', [AuthorizationController::class, 'authorize'])
                ->middleware(['web', EnsureOidcUserIsActive::class, RequireOpenIdScope::class, RequirePkceS256::class])
                ->name('passport.authorizations.authorize');

            // 伺服器對伺服器呼叫：不掛 web 群組（沒有 session、沒有 CSRF）。
            Route::post('/token', [AccessTokenController::class, 'issueToken'])
                // 只放行 authorization_code（RestrictOidcGrantTypes）：refresh_token 的把關是計畫第 4 步，
                // client_credentials 不需要使用者，兩者都不能只靠 client 的 grant_types 欄位擋。
                ->middleware(['throttle:60,1', RestrictOidcGrantTypes::class])
                ->name('passport.token');
        });
    }
}
