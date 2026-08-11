<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class RedirectIfAuthenticated {
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string|null  $guard
     * @return mixed
     */
    public function handle($request, Closure $next, $guard = null) {
        // 只把「已登入且帳號啟用」的人彈開。停用（含從未啟用）帳號若還握著舊 session，
        // 光看 check() 會把他們從 /login 彈回 /home，於是永遠看不到
        // LoginController::attemptLogin 準備好的「此帳號尚未啟用」說明，
        // 也沒有任何自助脫困的入口——被停用的管理員就只能靠 CLI 救。
        $user = Auth::guard($guard)->user();

        if ($user !== null && $user->isActive()) {
            return redirect('/home');
        }

        return $next($request);
    }
}
