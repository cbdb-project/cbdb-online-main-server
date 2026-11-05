<?php

/*
 * OIDC Provider（把本專案的後台登入提供給其他專案）。設計與分步計畫見 docs/OIDC_PROVIDER_PLAN.md。
 *
 * 套件的 Passport 路由一律由 AuthServiceProvider 關閉；啟用時由 OidcServiceProvider 只註冊
 * `GET /oauth/authorize` 與 `POST /oauth/token` 兩條。**不要**改成放行 Passport 的完整路由集：
 * 那會連 `/oauth/clients`（任何已登入使用者都能建立 OAuth client）等 JSON API 一起打開。
 */
return [
    /*
     * 總開關。預設關閉；關閉時兩個端點都不存在（404）。
     *
     * 開啟前先跑 `php artisan oidc:check`（sodium、金鑰、issuer、scope…），見 docs/OIDC_PROVIDER_PLAN.md §8。
     * 路由是在 boot 時註冊的：`php artisan route:cache` 之後改這個值要重跑 route:cache 與 config:cache
     * 才會生效（與 routes/ai.php 依 MCP_ENABLED 決定路由同理）。
     */
    'enabled' => (bool) env('OIDC_PROVIDER_ENABLED', false),

    /*
     * access token 壽命（分鐘）。沒有單點登出，所以這是「帳號被停用後，已簽出的 access token 還能活多久」
     * 的上限，要夠短。Passport 預設是一年——一定要在這裡明確指定。
     */
    'access_token_ttl_minutes' => max(1, (int) env('OIDC_ACCESS_TOKEN_TTL_MINUTES', 10)),

    /*
     * `POST /oauth/token` 每分鐘每 IP 的上限（具名限流器 oidc-token，見 RouteServiceProvider）。
     * 用途是封頂 client_secret 暴力猜測。計數存在預設 cache store：多節點部署必須用共享 store。
     */
    'token_rate_limit_per_minute' => (int) env('OIDC_TOKEN_RATE_LIMIT_PER_MINUTE', 60),
];
