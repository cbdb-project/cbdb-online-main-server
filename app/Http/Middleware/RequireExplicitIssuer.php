<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * OIDC 端點在**沒有明確 issuer** 時一律回 503（fail-closed）。
 *
 * 套件的 `issuedBy` 設為 `'laravel'`（或 `'server'`）時，會從**當下請求的 Host 標頭**推導 issuer——
 * 不只 discovery 文件，連 id_token 的 `iss` 也是。Host 是攻擊者可以控制的輸入，iss 一旦發出就是 RP 驗證
 * 的依據。`config/openid.php` 的 `issuedBy` 在 `OIDC_ISSUER` 與 `APP_URL` 都沒設時會退回 `'laravel'`，
 * 所以這是「忘了設環境變數」就會發生的事。
 *
 * `oidc:check` 也會報這個錯誤，但那是人要記得去跑；這裡讓它在執行期就**不可能**被忽略。
 * 掛在 authorize、token、discovery 三條路由上（JWKS 是套件的路由、不依賴 issuer，也不含任何請求推導的值）。
 */
class RequireExplicitIssuer {
    /** 套件會從請求推導 issuer 的值。 */
    private const DERIVED = ['', 'laravel', 'server'];

    public function handle(Request $request, Closure $next) {
        $issuer = (string) config('openid.issuedBy');

        if (in_array($issuer, self::DERIVED, true)) {
            return response()->json([
                'error' => 'server_error',
                'error_description' => 'The OIDC issuer is not configured (set OIDC_ISSUER to the public https URL of this server).',
            ], 503);
        }

        return $next($request);
    }
}
