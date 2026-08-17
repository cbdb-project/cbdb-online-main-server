<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option controls the default authentication "guard" and password
    | reset options for your application. You may change these defaults
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | here which uses session storage and the Eloquent user provider.
    |
    | All authentication drivers have a user provider. This defines how the
    | users are actually retrieved out of your database or other storage
    | mechanisms used by this application to persist your user's data.
    |
    | Supported: "session", "token"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        'api' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication drivers have a user provider. This defines how the
    | users are actually retrieved out of your database or other storage
    | mechanisms used by this application to persist your user's data.
    |
    | If you have multiple user tables or models you may configure multiple
    | sources which represent each model / table. These sources may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | You may specify multiple password reset configurations if you have more
    | than one user table or model in the application and you want to have
    | separate password reset settings based on the specific user types.
    |
    | The expire time is the number of minutes that the reset token should be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_resets',
            'expire' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Authentication Throttle
    |--------------------------------------------------------------------------
    |
    | 同一個來源 IP 每分鐘允許的「帶 Bearer token 卻認證失敗」次數上限（#1254）。
    |
    | 範圍刻意收窄到帶了 Authorization: Bearer 的請求：沒帶憑證的請求（未登入的瀏覽器、
    | 公開端點、MCP 規範要求的未認證握手、session 過期的站內 XHR）既不累加也不會被擋，
    | 所以被擋期間該 IP 仍然可以開登入頁、可以登入。認證成功的流量也不累加。
    |
    | 框架把 auth 排在 throttle 之前，未認證請求原本會在認證階段就返回、完全繞過路由自己的
    | 限流；這個上限由全域 middleware ThrottleFailedAuthentication 在 auth 之前執行。
    | 0／負數／非數字會退回程式碼裡的預設值（那種值幾乎一定是設定錯誤，若照字面解讀，
    | 一個錯字就會讓某個來源的 API 客戶端在第一次失敗之後完全打不進來），上限夾在 600。
    |
    */

    'failed_attempt_throttle' => [
        'per_minute' => env('FAILED_AUTH_THROTTLE_PER_MINUTE', 60),
    ],

];
