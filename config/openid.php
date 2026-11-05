<?php

/*
 * OIDC Provider（jeremy379/laravel-openid-connect）的**覆寫項**。
 *
 * 套件自己會 mergeConfigFrom 它的預設值；這裡只放本專案要改的鍵。合併是**淺層**的（頂層鍵整個取代），
 * 所以 `routes`、`passport` 這種巢狀陣列必須寫全，不能只寫要改的那一格。
 *
 * 計畫與分步落地見 docs/OIDC_PROVIDER_PLAN.md；總開關與壽命見 config/oidc.php。
 * 護欄測試：tests/Feature/OidcProviderDormantTest.php、tests/Feature/OidcAuthorizationFlowTest.php。
 */
return [
    'routes' => [
        // discovery 永遠關閉：套件的 discovery 宣稱了我們不支援的 grant 與 PKCE 方法，改由
        // App\Http\Controllers\OidcDiscoveryController 提供（路由由 OidcServiceProvider 註冊）。
        'discovery' => false,
        // 這裡刻意是 false：OidcServiceProvider::register() 在總開關開啟時才把它設成 true，
        // 所以關閉時 /oauth/jwks 不存在。
        'jwks' => false,
        'jwks_url' => '/oauth/jwks',
        // 刻意長期關閉：計畫決議不啟用 /userinfo（id_token 已含所需 claims；
        // Bearer 失敗還會被全域 ThrottleFailedAuthentication 計入），見 docs/OIDC_PROVIDER_PLAN.md §6.7。
        'userinfo' => false,
    ],

    /*
     * 只開放 openid／profile／email。套件預設還有 phone／address：本專案沒有那些資料，
     * 留著只會讓 RP 以為能要到、並讓 discovery 對外宣稱支援。
     */
    'passport' => [
        'tokens_can' => [
            'openid' => 'Enable OpenID Connect',
            'profile' => 'Information about your profile',
            'email' => 'Information about your email address',
        ],
    ],

    /*
     * ⚠️ 套件預設的 IdentityRepository 會回傳**寫死的演示身分**（Jon Snow、jon.snow@dorne.com，
     * 且 `email_verified => true`）。一定要覆寫成本專案的白名單實作，否則每個 id_token 都會帶著
     * 假身分與「已驗證」的 email。
     */
    'repositories' => [
        'identity' => \App\Support\Oidc\UserIdentityRepository::class,
    ],

    /*
     * id_token 的 `iss`。套件預設 'laravel' 會從當下請求推導 scheme＋host——放在反向代理後面時
     * 容易推出內網位址，而 iss 一旦發出就是 RP 驗證的依據，不能漂移。明確指定。
     * 優先 OIDC_ISSUER，退而用 APP_URL（兩者都沒設時回到套件的 'laravel' 推導行為）。
     */
    'issuedBy' => env('OIDC_ISSUER') ?: (env('APP_URL') ?: 'laravel'),
];
