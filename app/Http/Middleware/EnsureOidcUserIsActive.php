<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * `GET /oauth/authorize` 的 isActive() 把關。
 *
 * Passport 的授權路由只掛 `web` 群組，登入與否只看 guard——**不會**套用本專案覆寫的 `auth` 別名
 * （App\Http\Middleware\Authenticate，含 isActive() 檢查）。於是被停用、但 session 仍有效的帳號
 * 會直接拿到授權碼。
 *
 * 這裡不回 403，而是把已停用的帳號當成「未登入」處理：登出並作廢 session，再交給 Passport 的控制器——
 * 它會依 `prompt` 走登入頁（`prompt=none` 則回 `login_required` 給 RP）。這樣 RP 看到的行為
 * 與「根本沒登入」一致，不會洩漏「這個帳號存在但被停用」。
 *
 * docs/OIDC_PROVIDER_PLAN.md §6.3。
 */
class EnsureOidcUserIsActive {
    public function handle(Request $request, Closure $next) {
        $user = $request->user();

        if ($user !== null && !$user->isActive()) {
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $next($request);
    }
}
