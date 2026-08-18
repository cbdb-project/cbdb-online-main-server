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
    | `throttle` 是「同一個 email 在幾秒內不重發重設信」（#1264；broker 的檢查與寫入在框架內部不是
    | 原子操作，不過上游的 per-IP 閘已用 cache lock 把並發量壓住）。**這個鍵原本不存在**，
    | 而框架取的是 `$config['throttle'] ?? 0`（PasswordBrokerManager），`DatabaseTokenRepository`
    | 又寫 `if ($this->throttle <= 0) return false;`，於是 `recentlyCreatedToken()` 永遠回 false
    | ——同一個 email 可以被無限次觸發「查 users ＋ 寫 password_resets ＋ 寄一封信」，是一個
    | 無上限的信件放大器（受害者是那個信箱的擁有者，以及我們自己的寄信信譽）。
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_resets',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Guest Endpoint Throttle
    |--------------------------------------------------------------------------
    |
    | 未認證表單端點每分鐘每 IP 的請求上限（#1264）：`POST /register`、`POST /password/email`、
    | `POST /password/reset`。這三條原本完全沒有限流，而每次請求都有成本（寫庫、同步寄信）。
    | 對照組是 `POST /login`——它早就有 ThrottlesLogins 的 5 次／分鐘。
    |
    | 數字按「每次請求的成本」與「真人會重試幾次」分別定，不是統一值。注意**被 validator 打回的
    | 請求也算一次**（密碼太短、confirmed 不符、email 已存在），所以要留出真人重試的餘裕：
    |   register 30       —— 成本只有一次 SELECT ＋ 一次 INSERT，沒有 SMTP；機構 NAT 後面一整班
    |                        同時註冊是 CBDB 的真實情境，這裡刻意留寬。
    |   password-email 5  —— 唯一會同步連 SMTP 寄信的一條，成本最高；另有一道按 email 的節流
    |                        （下面的 passwords.users.throttle），兩個維度互補。
    |   password-reset 10 —— 一次 token 查詢 ＋ 一次 bcrypt。
    | 0／負數／非數字會退回程式碼裡的預設值，上限夾在 600。
    | 實作見 App\Http\Middleware\ThrottleGuestAuthRequests（別名 throttle.guest）。
    |
    */

    'guest_endpoint_throttle' => [
        'register' => env('AUTH_THROTTLE_REGISTER_PER_MINUTE', 30),
        'password-email' => env('AUTH_THROTTLE_PASSWORD_EMAIL_PER_MINUTE', 5),
        'password-reset' => env('AUTH_THROTTLE_PASSWORD_RESET_PER_MINUTE', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Endpoint Throttle
    |--------------------------------------------------------------------------
    |
    | 眾包舊通道的憑證簽發端點 `GET|POST /api/operations/token`（#1264）：它拿 email＋密碼換
    | 長期有效的 confirmation_token，原本只有 api 群組共用的 600／分鐘＝每分鐘 600 次密碼嘗試。
    |
    | 兩個維度一起套：per_email（擋針對某帳號猜密碼，比照 ThrottlesLogins 的 5 次／分鐘）與
    | per_ip（擋換帳號繼續猜）。0／負數／非數字退回程式碼預設值，上限夾在 600。
    | 具名 limiter 定義在 App\Providers\RouteServiceProvider::configureRateLimiting()。
    |
    */

    'api_endpoint_throttle' => [
        'crowdsourcing_token' => [
            'per_email' => env('AUTH_THROTTLE_CROWDSOURCING_TOKEN_PER_EMAIL', 5),
            'per_ip' => env('AUTH_THROTTLE_CROWDSOURCING_TOKEN_PER_IP', 20),
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
