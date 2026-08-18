<?php

namespace Tests\Feature;

use App\Http\Middleware\ThrottleGuestAuthRequests;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * #1264：`POST /register`、`POST /password/email`、`POST /password/reset` 原本完全沒有限流。
 *
 * 三條端點每次請求都有成本（寫庫、同步連 SMTP 寄信），而 `web` 群組沒有 throttle、`Auth::routes()`
 * 也不在任何群組裡；對照組 `POST /login` 早就有 `ThrottlesLogins` 的 5 次／分鐘。
 *
 * 這裡釘住三件事：
 *  1. 三條端點各自真的會被擋（超額後不再進 controller）；
 *  2. **超額的回應形狀對 Inertia 表單是可用的**（302 + 欄位錯誤，與登入被鎖定一致），
 *     不是一頁 HTML 429——後者會讓 Inertia 彈黑底 modal，使用者看不到任何訊息；
 *  3. 三條端點各有獨立的桶，且不會誤傷 GET 表單頁與 `POST /login`。
 */
class GuestAuthEndpointThrottleTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        config(['cache.default' => 'array']);
        config(['session.driver' => 'array']);

        Cache::store(config('cache.default'))->clear();

        // 寄信是同步的（QUEUE_CONNECTION=sync）且 phpunit.xml 沒設 MAIL_MAILER，
        // 不 fake 的話「成功寄出重設信」那條會真的去連 SMTP。
        Notification::fake();

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('institution')->nullable();
            $table->json('settings')->nullable();
            $table->string('avatar')->nullable();
            $table->string('confirmation_token')->nullable();
            $table->smallInteger('is_active')->default(0);
            $table->smallInteger('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::dropIfExists('password_resets');
        Schema::create('password_resets', function (Blueprint $table) {
            $table->string('email')->index();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * 這個回應是不是被**限流**擋下的。
     *
     * 不能只看「session 有沒有 errors」：這三條端點在正常路徑上就會產生欄位錯誤（不存在的 email、
     * 無效的 token、必填欄位缺漏），那些不是限流。所以比對的是限流訊息本身。
     */
    private function wasThrottled(): bool {
        $errors = session('errors');
        if ($errors === null) {
            return false;
        }

        // 逐字比對（秒數從訊息本身抽出來）。刻意不用「前綴 str_contains」：zh-TW 的
        // auth.throttle（登入專用，「登入嘗試次數過多，請 :seconds 秒後再試。」）**包含**
        // auth.throttle_requests 的前綴，於是把 middleware 的訊息換成登入版也會被誤判為通過
        //（review 實測過）。
        $message = (string) $errors->first('email');
        if (!preg_match('/(\d+)/', $message, $matches)) {
            return false;
        }

        return $message === __('auth.throttle_requests', ['seconds' => $matches[1]]);
    }

    /**
     * 三條端點的最小可送出 payload（內容不重要——限流在進 controller 之前就決定，
     * 驗證失敗的請求同樣要算一次，否則「亂送空表單」就能無限次繞過）。
     *
     * @return array<string,array{0:string,1:string,2:array<string,string>}>
     */
    public static function guestEndpoints(): array {
        return [
            'register' => ['register', '/register', ['email' => 'nobody@example.com']],
            'password-email' => ['password-email', '/password/email', ['email' => 'nobody@example.com']],
            'password-reset' => ['password-reset', '/password/reset', ['email' => 'nobody@example.com', 'token' => 'bogus']],
        ];
    }

    #[Test]
    #[DataProvider('guestEndpoints')]
    public function each_guest_endpoint_is_throttled(string $name, string $uri, array $payload): void {
        config(["auth.guest_endpoint_throttle.{$name}" => 2]);

        $this->post($uri, $payload)->assertStatus(302);
        $this->post($uri, $payload)->assertStatus(302);

        // 第三次是被限流擋下的那一次：形狀仍是 302（表單頁），但帶著限流訊息。
        $throttled = $this->post($uri, $payload);
        $throttled->assertStatus(302);
        $throttled->assertSessionHasErrors('email');
        $this->assertTrue($this->wasThrottled(), "{$uri} 超額後應顯示限流訊息");

        // 必須導回**表單頁**：改成 redirect('/') 之類會把使用者帶離表單，flash 的欄位錯誤
        // 就永遠不會被渲染（只驗 302 抓不到，review 實測過）。
        $throttled->assertRedirect($uri === '/password/email' ? '/password/reset' : $uri);

        // 訊息不得是裸翻譯鍵：翻譯檔被刪時 __() 會回 'auth.throttle_requests' 這串，
        // 而 wasThrottled() 兩邊同源、比對不出來（review 實測過刪掉兩個語系仍全綠）。
        $this->assertStringNotContainsString(
            'auth.throttle_requests',
            (string) session('errors')->first('email'),
            '限流訊息顯示成裸翻譯鍵，代表 resources/lang/*/auth.php 缺 throttle_requests'
        );
    }

    #[Test]
    #[DataProvider('guestEndpoints')]
    public function a_throttled_json_client_gets_a_real_429(string $name, string $uri, array $payload): void {
        // 瀏覽器表單要 302 + 欄位錯誤，但 JSON 客戶端（含未來的 API 使用者）要拿到標準的 429，
        // 否則它們會把「302 到登入頁」誤讀成成功。
        config(["auth.guest_endpoint_throttle.{$name}" => 1]);

        $this->postJson($uri, $payload);

        $response = $this->postJson($uri, $payload);
        $response->assertStatus(429);
        $response->assertHeader('X-RateLimit-Limit', 1);

        // Retry-After 要驗**值**：只驗存在的話，改成 0（客戶端會立刻重試）也照樣綠。
        $retryAfter = (int) $response->headers->get('Retry-After');
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(ThrottleGuestAuthRequests::DECAY_SECONDS, $retryAfter);

        // 訊息要逐字相符：只斷言「非空字串」的話，改成 'error' 也會綠。
        $this->assertSame(
            __('auth.throttle_requests', ['seconds' => $retryAfter]),
            (string) $response->json('message')
        );
    }

    #[Test]
    public function the_throttle_stops_the_request_before_the_controller_runs(): void {
        // 這條才是重點：封頂的價值在於「不再付成本」。超額後不得再寄信、也不得再寫 password_resets。
        config(['auth.guest_endpoint_throttle.password-email' => 1]);

        $user = User::unguarded(fn () => User::create([
            'name' => 'Active User',
            'email' => 'active@example.com',
            'password' => bcrypt('secret'),
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_REGULAR,
        ]));

        $this->post('/password/email', ['email' => $user->email])->assertStatus(302);
        Notification::assertSentToTimes($user, \Illuminate\Auth\Notifications\ResetPassword::class, 1);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('password_resets')->count());

        // 額度用完：不得再寄第二封，也不得再動 password_resets。
        $this->post('/password/email', ['email' => $user->email])->assertSessionHasErrors('email');
        Notification::assertSentToTimes($user, \Illuminate\Auth\Notifications\ResetPassword::class, 1);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('password_resets')->count());
    }

    #[Test]
    public function each_endpoint_has_its_own_bucket(): void {
        // 三條端點必須各有一份額度：共用一個桶的話（例如用數值型 throttle:N,1，其 key 是
        // sha1(domain|ip) 而不含路由），註冊被打滿就會連帶擋掉重設密碼。
        config([
            'auth.guest_endpoint_throttle.register' => 1,
            'auth.guest_endpoint_throttle.password-email' => 1,
            'auth.guest_endpoint_throttle.password-reset' => 1,
        ]);

        $this->post('/register', ['email' => 'a@example.com']);
        $this->post('/register', ['email' => 'a@example.com']);
        $this->assertTrue($this->wasThrottled(), '/register 的第二發應被限流擋下');

        // 另兩條的額度必須完好：第一發不得是被限流擋下的（它們仍會有自己的欄位錯誤，
        // 例如「查不到這個 email」或「token 無效」，那些不是限流）。
        $this->post('/password/email', ['email' => 'a@example.com']);
        $this->assertFalse($this->wasThrottled(), '/password/email 不該被 /register 的額度影響');

        $this->post('/password/reset', ['email' => 'a@example.com', 'token' => 'bogus']);
        $this->assertFalse($this->wasThrottled(), '/password/reset 不該被 /register 的額度影響');

        // 而它們各自的第二發才會被限流擋下。
        $this->post('/password/email', ['email' => 'a@example.com']);
        $this->assertTrue($this->wasThrottled(), '/password/email 的第二發應被自己的額度擋下');
    }

    #[Test]
    public function each_source_ip_has_its_own_bucket(): void {
        config(['auth.guest_endpoint_throttle.register' => 1]);

        $this->post('/register', ['email' => 'a@example.com']);
        $this->post('/register', ['email' => 'a@example.com']);
        $this->assertTrue($this->wasThrottled());

        // 刻意用**同一個 email**、只換 IP：若第二發連 email 也換掉，那麼「按 email 分桶」的
        // 實作也會讓這條測試綠（review 實測過那個 mutation 存活），等於沒證明是按 IP 分桶。
        $this->call('POST', '/register', ['email' => 'a@example.com'], [], [], [
            'REMOTE_ADDR' => '203.0.113.9',
        ]);
        $this->assertFalse($this->wasThrottled(), '另一個來源 IP 必須有自己的額度');
    }

    #[Test]
    public function get_form_pages_and_login_are_not_throttled(): void {
        // 這道閘只掛在三個 POST 動作上（controller 建構子的 ->only(...)）：表單頁必須照常打得開，
        // 否則被擋住的人連頁面都看不到；POST /login 也不得被這道閘影響（它自己有 ThrottlesLogins）。
        config([
            'auth.guest_endpoint_throttle.register' => 1,
            'auth.guest_endpoint_throttle.password-email' => 1,
            'auth.guest_endpoint_throttle.password-reset' => 1,
        ]);

        $this->post('/register', ['email' => 'a@example.com']);
        $this->post('/register', ['email' => 'a@example.com']);
        $this->assertTrue($this->wasThrottled());

        $this->get('/register')->assertSuccessful();
        $this->get('/password/reset')->assertSuccessful();
        $this->get('/login')->assertSuccessful();

        // 登入仍走自己的流程（帳密錯誤 → 302 + errors，而不是被 guest 限流擋下）。
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertStatus(302);
    }

    #[Test]
    public function the_middleware_is_attached_to_exactly_the_three_post_actions(): void {
        // 端到端測試只證明「有擋」，這條證明「掛的位置對」：若有人把它掛到整個 Auth::routes()
        // 群組上，GET 表單頁與 POST /login 會一起被限流（而端到端測試可能仍是綠的）。
        $expected = [
            'POST|register' => 'throttle.guest:register',
            'POST|password/email' => 'throttle.guest:password-email',
            'POST|password/reset' => 'throttle.guest:password-reset',
        ];

        $found = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'throttle.guest')) {
                    $found[implode(',', array_diff($route->methods(), ['HEAD'])) . '|' . $route->uri()] = $middleware;
                }
            }
        }

        // 不綁順序：$found 的順序來自 Auth::routes() 的註冊順序，那不是這條測試要釘的東西。
        $this->assertEqualsCanonicalizing(
            $expected,
            $found,
            'throttle.guest 必須恰好掛在三個未認證 POST 動作上——多了會誤傷（例如 GET 表單頁、'
                . 'POST /login），少了就是有端點沒有限流'
        );
    }

    #[Test]
    public function an_unknown_throttle_name_fails_closed(): void {
        // middleware 參數打錯字時不可以靜默變成「沒有限流」。
        $this->expectException(\InvalidArgumentException::class);

        (new ThrottleGuestAuthRequests())->handle(
            \Illuminate\Http\Request::create('/register', 'POST'),
            fn ($request) => response('ok'),
            'typo-name'
        );
    }

    #[Test]
    public function the_configured_limits_are_clamped_to_a_sane_range(): void {
        foreach (array_keys(ThrottleGuestAuthRequests::DEFAULT_MAX_ATTEMPTS) as $name) {
            $default = ThrottleGuestAuthRequests::DEFAULT_MAX_ATTEMPTS[$name];

            config(["auth.guest_endpoint_throttle.{$name}" => 3]);
            $this->assertSame(3, ThrottleGuestAuthRequests::maxAttempts($name));

            // 0／負數／髒值不是「零額度」：照字面解讀會讓所有人都註冊不了、也拿不到重設信。
            foreach ([0, -5, null, 'abc'] as $dirty) {
                config(["auth.guest_endpoint_throttle.{$name}" => $dirty]);
                $this->assertSame(
                    $default,
                    ThrottleGuestAuthRequests::maxAttempts($name),
                    "{$name} 的髒設定值應退回預設 {$default}"
                );
            }

            config(["auth.guest_endpoint_throttle.{$name}" => 99999]);
            $this->assertSame(600, ThrottleGuestAuthRequests::maxAttempts($name));
        }
    }

    #[Test]
    public function the_shipped_configuration_matches_the_documented_defaults(): void {
        // 上面的測試都覆寫 config，所以 config/auth.php 與 .env 的接線本來完全沒被測到。
        $this->assertSame(30, (int) config('auth.guest_endpoint_throttle.register'));
        $this->assertSame(
            ThrottleGuestAuthRequests::DEFAULT_MAX_ATTEMPTS,
            ['register' => 30, 'password-email' => 5, 'password-reset' => 10],
            '程式碼預設值與 config／.env.example／docs 的數字必須一致'
        );
        $this->assertSame(5, (int) config('auth.guest_endpoint_throttle.password-email'));
        $this->assertSame(10, (int) config('auth.guest_endpoint_throttle.password-reset'));

        $source = file_get_contents(config_path('auth.php'));
        foreach ([
            'AUTH_THROTTLE_REGISTER_PER_MINUTE',
            'AUTH_THROTTLE_PASSWORD_EMAIL_PER_MINUTE',
            'AUTH_THROTTLE_PASSWORD_RESET_PER_MINUTE',
        ] as $envKey) {
            $this->assertStringContainsString($envKey, $source, "config/auth.php 必須讀 {$envKey}");
        }
    }

    #[Test]
    public function the_password_reset_broker_throttles_repeat_requests_for_the_same_email(): void {
        // 框架內建的 per-email 節流：原本因為 config/auth.php 的 passwords.users 缺 throttle 鍵而
        // 永久停用（$config['throttle'] ?? 0 → recentlyCreatedToken() 恆 false），同一個 email
        // 可以無限次觸發寄信。這條與上面的 per-IP 閘是兩個維度：換 IP 繞不過這一條。
        $this->assertSame(60, (int) config('auth.passwords.users.throttle'));

        // 把 per-IP 的閘放寬，確保這裡擋下來的是 broker 而不是 middleware。
        config(['auth.guest_endpoint_throttle.password-email' => 100]);

        $user = User::unguarded(fn () => User::create([
            'name' => 'Active User',
            'email' => 'active@example.com',
            'password' => bcrypt('secret'),
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_REGULAR,
        ]));

        $this->post('/password/email', ['email' => $user->email])->assertSessionHasNoErrors();
        Notification::assertSentToTimes($user, \Illuminate\Auth\Notifications\ResetPassword::class, 1);

        $second = $this->post('/password/email', ['email' => $user->email]);
        $second->assertSessionHasErrors('email');
        $this->assertSame(
            __('passwords.throttled'),
            (string) session('errors')->first('email'),
            '第二次應由 broker 的 per-email 節流擋下（passwords.throttled）'
        );
        Notification::assertSentToTimes($user, \Illuminate\Auth\Notifications\ResetPassword::class, 1);
    }

    #[Test]
    public function a_throttled_response_never_flashes_the_password_back_into_the_session(): void {
        // 超額時會 back()->withInput() 讓使用者不用重打表單，但**密碼絕不能進 session**
        // （session 檔案／DB 是明文的，而 password 欄位在註冊與重設密碼兩個表單上都有）。
        // review 實測過：把 DO_NOT_FLASH 拿掉，15 條測試全綠而明文密碼被寫進 session。
        config(['auth.guest_endpoint_throttle.register' => 1]);

        $secret = 'sup3r-s3cret-passw0rd';
        $payload = [
            'name' => '王小明',
            'email' => 'flash@example.com',
            'password' => $secret,
            'password_confirmation' => $secret,
        ];

        $this->post('/register', $payload);
        $this->post('/register', $payload);
        $this->assertTrue($this->wasThrottled(), '前提不成立：第二發應被限流擋下');

        $flashed = session('_old_input', []);
        $this->assertNotSame([], $flashed, '應該有回填其他欄位（否則這條測試證明不了 DO_NOT_FLASH 有生效）');
        $this->assertSame('flash@example.com', $flashed['email'] ?? null);

        foreach (['password', 'password_confirmation', 'current_password'] as $field) {
            $this->assertArrayNotHasKey($field, $flashed, "{$field} 不得被寫回 session");
        }
        $this->assertStringNotContainsString(
            $secret,
            json_encode($flashed, JSON_UNESCAPED_UNICODE),
            '明文密碼出現在回填的表單資料裡'
        );
    }

    #[Test]
    public function registering_without_an_institution_is_a_validation_error_not_a_500(): void {
        // 測這道限流時撞到的既有 bug：institution 不在 validator 規則裡，但 create() 直接讀
        // $data['institution']，於是任何沒帶這個欄位的 POST 都會炸 ErrorException = HTTP 500
        //（未認證端點回 500 既是噪音也會洩漏堆疊資訊）。現在應該正常註冊成功、institution 為 null。
        config(['auth.guest_endpoint_throttle.register' => 10]);

        $response = $this->post('/register', [
            'name' => '無機構使用者',
            'email' => 'no-institution@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $user = User::where('email', 'no-institution@example.com')->first();
        $this->assertNotNull($user, '沒帶 institution 的註冊應該成功');
        $this->assertNull($user->institution);
    }

    #[Test]
    public function an_over_long_institution_is_rejected(): void {
        // 另一半：欄位進了規則就要真的驗。institution 是自由文字且會被存進 users，
        // 沒有長度上限的話 DB 層才會擋（MySQL 嚴格模式下是 SQLSTATE 錯誤＝500）。
        config(['auth.guest_endpoint_throttle.register' => 10]);

        $response = $this->post('/register', [
            'name' => '超長機構',
            'email' => 'long-institution@example.com',
            'institution' => str_repeat('哈', 300),
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ]);

        $response->assertSessionHasErrors('institution');
        $this->assertNull(User::where('email', 'long-institution@example.com')->first());
    }

    // ------------------------------------------------------------------
    // 真實 Inertia 請求（這是上線路徑；review 實測「只用 post()/postJson() 的測試看不見
    // 任何針對 Inertia 的分支」——例如 `!$request->hasHeader('X-Inertia') && tooManyAttempts(...)`
    // 會讓生產環境的限流完全失效而 18 條測試全綠）
    // ------------------------------------------------------------------

    /** `@inertiajs/core` 實際送出的標頭組合。 */
    private function inertiaPost(string $uri, array $payload): \Illuminate\Testing\TestResponse {
        return $this->call('POST', $uri, $payload, [], [], [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'HTTP_ACCEPT' => 'text/html, application/xhtml+xml',
        ]);
    }

    #[Test]
    public function a_real_inertia_form_submission_is_counted_and_gets_a_usable_response(): void {
        config(['auth.guest_endpoint_throttle.register' => 2]);

        $payload = ['name' => '王小明', 'email' => 'inertia@example.com'];

        $this->inertiaPost('/register', $payload);
        $this->inertiaPost('/register', $payload);

        // 必須真的被計數（不能對 Inertia 請求豁免）。
        $throttled = $this->inertiaPost('/register', $payload);
        $this->assertTrue($this->wasThrottled(), 'Inertia 送出的請求沒有被計數：生產路徑等於沒有限流');

        // 而且回應形狀對 Inertia 是可用的：302 導回表單頁（Inertia 會跟著重新渲染並顯示 errors），
        // **不是** 429／不是 JSON——後者會讓 Inertia 彈全螢幕 modal，使用者看不到任何訊息。
        $throttled->assertStatus(302);
        $throttled->assertRedirect(route('register'));
    }

    #[Test]
    public function an_xhr_that_asks_for_json_still_gets_429(): void {
        // 只有「要求 JSON」才走 429 分支；上面那條 Inertia（Accept: text/html）走 302。
        config(['auth.guest_endpoint_throttle.register' => 1]);

        $this->postJson('/register', ['email' => 'json@example.com']);
        $this->postJson('/register', ['email' => 'json@example.com'])->assertStatus(429);
    }

    // ------------------------------------------------------------------
    // 計數維度：只能是 IP
    // ------------------------------------------------------------------

    #[Test]
    public function rotating_the_submitted_email_does_not_grant_a_fresh_bucket(): void {
        // 若 key 用（或含）送出的 email，攻擊者換一個 email 就重獲額度＝無限次寄信。
        config(['auth.guest_endpoint_throttle.password-email' => 2]);

        $this->post('/password/email', ['email' => 'one@example.com']);
        $this->post('/password/email', ['email' => 'two@example.com']);

        $this->post('/password/email', ['email' => 'three@example.com']);
        $this->assertTrue($this->wasThrottled(), '換 email 就拿到新額度：這道閘可以無限繞過');
    }

    #[Test]
    public function client_controlled_headers_do_not_grant_a_fresh_bucket(): void {
        // 專案沒有 TrustProxies，所以計數只能用 $request->ip()（直連 peer）。
        // 若 key 讀 X-Forwarded-For 或 User-Agent，換一個標頭就能重置額度（兩者 review 都實測過）。
        config(['auth.guest_endpoint_throttle.register' => 1]);

        $this->post('/register', ['email' => 'a@example.com']);

        foreach ([
            ['HTTP_X_FORWARDED_FOR' => '198.51.100.7'],
            ['HTTP_USER_AGENT' => 'rotated-agent/1.0'],
        ] as $spoofed) {
            $this->call('POST', '/register', ['email' => 'a@example.com'], [], [], $spoofed);
            $this->assertTrue(
                $this->wasThrottled(),
                '換一個由客戶端自行決定的標頭就拿到新額度：' . json_encode($spoofed)
            );
        }
    }

    // ------------------------------------------------------------------
    // 時間窗
    // ------------------------------------------------------------------

    #[Test]
    public function the_window_is_one_minute(): void {
        // 沒有這條，把 DECAY_SECONDS 從 60 改成 1（吞吐量變 60 倍、「每分鐘」的契約靜默失效）
        // 或改成 86400（一次超額鎖一天）都會存活——所有請求都在同一秒內發完。
        $this->assertSame(60, ThrottleGuestAuthRequests::DECAY_SECONDS);

        config(['auth.guest_endpoint_throttle.register' => 1]);

        $this->post('/register', ['email' => 'a@example.com']);
        $this->post('/register', ['email' => 'a@example.com']);
        $this->assertTrue($this->wasThrottled());

        // 窗口內仍被擋。
        $this->travel(30)->seconds();
        $this->post('/register', ['email' => 'a@example.com']);
        $this->assertTrue($this->wasThrottled(), '窗口還沒過就恢復額度');

        // 窗口過了要恢復。
        $this->travel(ThrottleGuestAuthRequests::DECAY_SECONDS + 1)->seconds();
        $this->post('/register', ['email' => 'a@example.com']);
        $this->assertFalse($this->wasThrottled(), '窗口過了額度沒有恢復：一次超額就把使用者鎖太久');
    }

    // ------------------------------------------------------------------
    // 敏感欄位與 i18n
    // ------------------------------------------------------------------

    #[Test]
    public function a_throttled_password_reset_never_flashes_the_token(): void {
        // 重設 token 是一次性憑證，而 SESSION_DRIVER=file 的 session 是明文落盤的。
        // 回填表單也不需要它（Blade 版 token 來自 route 參數、React 版來自 props）。
        // 這條同時守住 App\Exceptions\Handler::$dontFlash 的內容——它是這份清單的單一真源，
        // 任何一次驗證失敗（不只限流）都會用它來決定回填哪些欄位。
        config(['auth.guest_endpoint_throttle.password-reset' => 1]);

        $payload = [
            'email' => 'a@example.com',
            'token' => 'SECRET-RESET-TOKEN',
            'password' => 'sup3r-s3cret',
            'password_confirmation' => 'sup3r-s3cret',
        ];

        $this->post('/password/reset', $payload);
        $this->post('/password/reset', $payload);
        $this->assertTrue($this->wasThrottled(), '前提不成立：第二發應被限流擋下');

        $flashed = session('_old_input', []);
        $this->assertSame('a@example.com', $flashed['email'] ?? null, '應該有回填 email');
        foreach (['token', 'password', 'password_confirmation'] as $field) {
            $this->assertArrayNotHasKey($field, $flashed, "{$field} 不得被寫回 session");
        }
        $this->assertStringNotContainsString(
            'SECRET-RESET-TOKEN',
            json_encode($flashed, JSON_UNESCAPED_UNICODE)
        );
    }

    #[Test]
    public function the_throttle_message_exists_in_both_locales_with_the_seconds_placeholder(): void {
        // 直接讀兩個語系檔（不經 __()）：否則「兩邊都刪掉」時 __() 會回裸鍵，
        // 而任何拿 __() 去比對 __() 的斷言都是套套邏輯（review 實測過那個 mutation 存活）。
        foreach (['zh-TW', 'en'] as $locale) {
            $path = resource_path("lang/{$locale}/auth.php");
            $this->assertFileExists($path);

            $lines = require $path;
            $this->assertArrayHasKey(
                'throttle_requests',
                $lines,
                "{$locale} 缺 auth.throttle_requests：使用者會看到裸翻譯鍵"
            );
            $this->assertStringContainsString(
                ':seconds',
                $lines['throttle_requests'],
                "{$locale} 的 auth.throttle_requests 少了 :seconds 佔位符：使用者不知道要等多久"
            );
        }
    }

    #[Test]
    public function an_untrusted_proxy_header_is_logged_so_the_misconfiguration_is_visible(): void {
        // 這道閘按 IP 分桶，而專案沒有 TrustProxies。若日後真的前置 CDN／LB 卻忘了設定，
        // $request->ip() 會變成 proxy 的位址（**非空**，所以 fail-open 分支救不到），所有使用者
        // 塌進同一個桶＝全站無法重設密碼，而且從應用層完全看不出異常。這行警告是它唯一的訊號。
        config(['auth.guest_endpoint_throttle.register' => 10]);

        Log::spy();

        $this->call('POST', '/register', ['email' => 'a@example.com'], [], [], [
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
        ]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains((string) $message, 'no trusted proxies'))
            ->once();
    }

    #[Test]
    public function a_normal_request_does_not_log_the_proxy_warning(): void {
        // 反面對照：沒有 X-Forwarded-For 的一般請求不得產生這行警告，否則正常流量會把 log 洗掉。
        config(['auth.guest_endpoint_throttle.register' => 10]);

        Log::spy();

        $this->post('/register', ['email' => 'a@example.com']);

        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function a_contended_bucket_lock_is_treated_as_over_limit(): void {
        // 「檢查 ＋ 計數」包在 cache lock 裡，因為 file store 的 increment 是沒上鎖的
        // read-modify-write：並發請求會讀到同一個計數再各自寫回同一個較小值（lost update），
        // 計數因此可以被持續壓在上限以下＝這道閘被大量繞過。
        //
        // 並發本身無法在 PHPUnit 裡重現，所以這裡直接**先把鎖佔住**，驗證「拿不到鎖時 fail-closed」。
        // 鎖名含 IP 與端點，所以會出現競爭的只有同一個來源的並發突發本身，合法使用者不會和自己搶鎖。
        config(['auth.guest_endpoint_throttle.register' => 10]);

        $key = ThrottleGuestAuthRequests::bucketKey('register', '127.0.0.1');
        $lock = Cache::lock($key . ':lock', 5);
        $this->assertTrue($lock->acquire(), '前提不成立：測試自己應該先拿到鎖');

        try {
            $this->post('/register', ['email' => 'contended@example.com']);
            $this->assertTrue(
                $this->wasThrottled(),
                '拿不到桶的鎖時必須當成超額（fail-closed）；放行會讓並發突發直接繞過限流'
            );

            // 而且不得因此建帳號（＝真的沒有進到 controller）。
            $this->assertNull(User::where('email', 'contended@example.com')->first());
        } finally {
            $lock->release();
        }

        // 鎖釋放後恢復正常。
        $this->post('/register', ['email' => 'contended@example.com']);
        $this->assertFalse($this->wasThrottled(), '鎖釋放後應恢復正常');
    }
}
