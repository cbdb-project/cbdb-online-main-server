<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * `GET /oauth/authorize` 強制 PKCE（S256）。
 *
 * league/oauth2-server 只對 public client 強制 `code_challenge`；有 secret 的 confidential client
 * 預設可以不帶，也允許 `plain` 方法。自動核准（first-party 免同意頁）下，授權請求只要有已登入的瀏覽器
 * 造訪就會直接發 code，PKCE 是少數真正綁住「發起授權的 RP」與「兌換授權碼的 RP」的機制，
 * 所以對所有 client 一律要求，且只接受 S256。
 *
 * 缺少或不合法時直接回 400，**不**重導向到 redirect_uri：這一步發生在 redirect_uri 被驗證之前，
 * 往未驗證的位址送錯誤就是開放轉址。
 *
 * docs/OIDC_PROVIDER_PLAN.md §6.4。
 */
class RequirePkceS256 {
    public function handle(Request $request, Closure $next) {
        $challenge = $request->query('code_challenge');
        $method = $request->query('code_challenge_method');

        // RFC 7636 §4.2：43–128 個 [A-Za-z0-9-._~]。
        $valid = is_string($challenge)
            && preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $challenge) === 1
            && $method === 'S256';

        if (!$valid) {
            return response()->json([
                'error' => 'invalid_request',
                'error_description' => 'PKCE is required: send code_challenge (43-128 chars) with code_challenge_method=S256.',
            ], 400);
        }

        return $next($request);
    }
}
