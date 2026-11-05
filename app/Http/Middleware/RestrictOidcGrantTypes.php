<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * `POST /oauth/token` 目前只放行 `authorization_code`。
 *
 * Passport 無條件註冊 `refresh_token`、`client_credentials`（密碼與隱含授權預設關閉），只靠 client 自己的
 * `grant_types` 欄位擋。這不夠：
 *  - **`client_credentials`** 不需要使用者——管理員若誤建了帶這個 grant 的 client（例如
 *    `passport:client --client`），就能無使用者地簽發 token；
 *  - **`refresh_token`**：本專案**不簽發** refresh token（NoRefreshTokenRepository；下游只在登入那一刻要 id_token），
 *    所以也不接受這個 grant——不依賴「沒有人持有 refresh token」。若日後要開放，必須有意地同時：恢復簽發、
 *    把它加進 ALLOWED，並補「refresh 時重查 isActive()、輪換、停用時撤銷」的測試。
 *
 * 在 client 的 `grant_types` 之外再擋一層，管理員建錯 client 也不會變成漏洞。
 * docs/OIDC_PROVIDER_PLAN.md §6.3／§6.4。
 */
class RestrictOidcGrantTypes {
    /** @var list<string> */
    public const ALLOWED = ['authorization_code'];

    public function handle(Request $request, Closure $next) {
        $grantType = $request->input('grant_type');

        if (!is_string($grantType) || !in_array($grantType, self::ALLOWED, true)) {
            return response()->json([
                'error' => 'unsupported_grant_type',
                'error_description' => 'Only grant_type=authorization_code is supported.',
            ], 400);
        }

        return $next($request);
    }
}
