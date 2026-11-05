<?php

/*
 * OIDC Provider（jeremy379/laravel-openid-connect）的**覆寫項**。
 *
 * 套件自己會 mergeConfigFrom 它的預設值；這裡只放本專案要改的鍵。合併是**淺層**的（頂層鍵整個取代），
 * 所以 `routes` 這種巢狀陣列必須寫全，不能只寫要改的那一格。
 *
 * 計畫與分步落地見 docs/OIDC_PROVIDER_PLAN.md。目前處於「已安裝、全部關閉」階段：
 * 路由全關，避免在授權端的 isActive() 把關與登入回跳測試完成前暴露任何端點。
 * 護欄測試：tests/Feature/OidcProviderDormantTest.php。
 */
return [
    'routes' => [
        // 第 3 步（id_token／claims／discovery／JWKS）才有意地打開，並補測試。
        'discovery' => false,
        'jwks' => false,
        'jwks_url' => '/oauth/jwks',
        // 刻意長期關閉：計畫決議不啟用 /userinfo（id_token 已含所需 claims；
        // Bearer 失敗還會被全域 ThrottleFailedAuthentication 計入），見 docs/OIDC_PROVIDER_PLAN.md §6.7。
        'userinfo' => false,
    ],

    /*
     * id_token 的 `iss`。套件預設 'laravel' 會從當下請求推導 scheme＋host——放在反向代理後面時
     * 容易推出內網位址，而 iss 一旦發出就是 RP 驗證的依據，不能漂移。明確指定。
     * 優先 OIDC_ISSUER，退而用 APP_URL（兩者都沒設時回到套件的 'laravel' 推導行為）。
     */
    'issuedBy' => env('OIDC_ISSUER') ?: (env('APP_URL') ?: 'laravel'),
];
