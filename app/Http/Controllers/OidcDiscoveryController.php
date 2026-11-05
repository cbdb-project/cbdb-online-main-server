<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RestrictOidcGrantTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\URL;
use OpenIDConnect\Laravel\LaravelCurrentRequestService;
use OpenIDConnect\Services\IssuedByGetter;

/**
 * `GET /.well-known/openid-configuration`（OpenID Connect Discovery 1.0 §3）。
 *
 * 取代套件自己的 DiscoveryController——它**對外宣稱了我們不支援的東西**，下游的函式庫會據此行事：
 *  - `grant_types_supported` 寫死包含 `client_credentials` 與 `refresh_token`（我們只放行 authorization_code）；
 *  - `code_challenge_methods_supported` 含 `plain`（我們只接受 S256）；
 *  - 沒有 `claims_supported`。
 * 宣稱與實際行為不一致，比不宣稱更糟：RP 看到 refresh_token 就會去用，然後吃 `unsupported_grant_type`。
 *
 * 這裡的每一項都對應一個實際的強制點：
 *  - grant 來自 RestrictOidcGrantTypes::ALLOWED（單一真源，第 4 步開放 refresh 時 discovery 自動跟上）；
 *  - code challenge 方法對應 RequirePkceS256；response_type 只有 code；
 *  - scope 來自 config('openid.passport.tokens_can')（OidcServiceProvider 以它設定 Passport）；
 *  - claims 對應 UserIdentityRepository 的白名單。
 * 一致性由 tests/Feature/OidcDiscoveryAndJwksTest.php 鎖住。
 *
 * 套件的 discovery 路由（config openid.routes.discovery）必須保持關閉，否則兩條路由撞名撞路徑。
 */
class OidcDiscoveryController {
    public function __invoke(LaravelCurrentRequestService $currentRequestService): JsonResponse {
        if (config('openid.forceHttps', true)) {
            URL::forceScheme('https'); // 下面的 route() 呼叫
        }

        return response()->json([
            'issuer' => IssuedByGetter::get($currentRequestService, (string) config('openid.issuedBy', 'laravel')),
            'authorization_endpoint' => route('passport.authorizations.authorize'),
            'token_endpoint' => route('passport.token'),
            'jwks_uri' => route('openid.jwks'),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => RestrictOidcGrantTypes::ALLOWED,
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => array_keys((array) config('openid.passport.tokens_can')),
            // 有 secret 的 confidential client 用前兩種，public client（靠 PKCE）用 none。
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'code_challenge_methods_supported' => ['S256'],
            'claim_types_supported' => ['normal'],
            'claims_supported' => ['sub', 'iss', 'aud', 'exp', 'iat', 'nonce', 'name', 'email', 'email_verified'],
        ]);
    }
}
