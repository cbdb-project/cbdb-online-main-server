<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RestrictOidcGrantTypes;
use Illuminate\Http\JsonResponse;
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
 *  - grant 來自 RestrictOidcGrantTypes::ALLOWED（單一真源，日後若開放 refresh，discovery 自動跟上）；
 *  - code challenge 方法對應 RequirePkceS256；response_type 只有 code；
 *  - scope 來自 config('openid.passport.tokens_can')（OidcServiceProvider 以它設定 Passport）；
 *  - claims 對應 UserIdentityRepository 的白名單。
 * 一致性由 tests/Feature/OidcDiscoveryAndJwksTest.php 鎖住。
 *
 * 端點網址**以 issuer 為基底**，不是用 `route()` 依當下請求的 Host 產生：issuer 來自設定（可信），
 * 請求的 Host 標頭則不是——兩者不一致時，文件會公布指向別的主機或 http 的端點。scheme 因此也由 issuer 決定
 * （`oidc:check` 要求 prod 的 issuer 是 https），不必再用 URL::forceScheme()——那會改動共用的 URL 產生器，
 * 在常駐行程（Octane）裡會影響之後的請求。
 *
 * 套件的 discovery 路由（config openid.routes.discovery）必須保持關閉，否則兩條路由撞名撞路徑。
 */
class OidcDiscoveryController {
    public function __invoke(LaravelCurrentRequestService $currentRequestService): JsonResponse {
        $issuer = IssuedByGetter::get($currentRequestService, (string) config('openid.issuedBy', 'laravel'));
        $base = rtrim($issuer, '/');

        return response()->json([
            'issuer' => $issuer,
            'authorization_endpoint' => $base . route('passport.authorizations.authorize', [], false),
            'token_endpoint' => $base . route('passport.token', [], false),
            'jwks_uri' => $base . route('openid.jwks', [], false),
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
