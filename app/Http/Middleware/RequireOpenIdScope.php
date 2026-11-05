<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * `GET /oauth/authorize` 必須帶 `openid` scope。
 *
 * 這不只是「這是 OIDC Provider 所以要有 openid」的規範性要求，更是**把關的一環**：停用帳號的
 * isActive() 檢查在 UserIdentityRepository，而它只在簽發 id_token 時被呼叫——套件的
 * IdTokenResponse 在 token **沒有 openid scope** 時直接跳過整段（含身分查詢）。`scope` 又是授權請求的
 * 選用參數，client 可以不帶、或只帶 `profile`／`email`，於是兌換授權碼與 refresh 都會完全不經過那個檢查。
 * 強制 openid 之後，每一張授權碼、以及它衍生的每一張 token，scope 都含 openid。
 *
 * 缺漏時回 400，**不**重導向：此時 redirect_uri 還沒被驗證。docs/OIDC_PROVIDER_PLAN.md §6.3。
 */
class RequireOpenIdScope {
    public function handle(Request $request, Closure $next) {
        $scope = $request->query('scope');
        $scopes = is_string($scope) ? preg_split('/\s+/', trim($scope), -1, PREG_SPLIT_NO_EMPTY) : [];

        if (!in_array('openid', $scopes, true)) {
            return response()->json([
                'error' => 'invalid_scope',
                'error_description' => 'The openid scope is required.',
            ], 400);
        }

        return $next($request);
    }
}
