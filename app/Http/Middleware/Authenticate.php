<?php

namespace App\Http\Middleware;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware {
    /**
     * 認證通過後再複查帳號啟用狀態。
     *
     * `auth` 與 `auth:sanctum` 共用同一個 middleware alias（後者只是多帶 guard 參數），
     * 所以覆寫這一處就同時蓋住 session 與 bearer token 兩條認證路徑：被停用（或從未啟用）
     * 的帳號即使 session 仍在、API token 仍未過期，也一律拒絕——讓「停用」真正等於「斷訪問」，
     * 而不是只擋住寫入端點。
     *
     * 回 403 而非導向 /login：使用者的憑證是有效的，問題在帳號狀態，導向登入頁只會讓人
     * 反覆登入卻不知道為什麼進不去（`LoginController` 掛 guest middleware，已登入者還會被彈開）。
     *
     * 注意這裡刻意不動 `auth.optional`（見 OptionalAuthentication）：那組路由允許訪客讀取，
     * 一律 403 會讓「已登入但未啟用」比登出還不如。
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    protected function authenticate($request, array $guards) {
        parent::authenticate($request, $guards);

        // fail-closed：父類已保證有一個 guard check() 為真，但解析不到使用者就當未認證處理，
        // 不要因為拿到 null 而放行（OptionalAuthentication 就是會動 Auth::shouldUse() 的先例）。
        $user = $this->auth->guard()->user();
        if ($user === null) {
            // 明示丟出，不倚賴 unauthenticated() 現在一定會拋例外這件事——否則哪天父類改成
            // 可回傳，這裡就會變成 null->isActive() 的 TypeError。
            throw new AuthenticationException(
                'Unauthenticated.',
                $guards,
                $request->expectsJson() ? null : $this->redirectTo($request),
            );
        }

        abort_if(!$user->isActive(), 403, __('auth.account_inactive'));
    }
}
