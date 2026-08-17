<?php

namespace Tests\Feature;

use App\Http\Kernel;
use App\Http\Middleware\ThrottleFailedAuthentication;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * #1254：未認證請求原本完全繞過限流（框架把 auth 排在 throttle 之前），改由全域 middleware
 * `ThrottleFailedAuthentication` 把「**帶 Bearer token 卻認證失敗**」的次數按 IP 封頂。
 *
 * 這個測試檔分兩半，兩半一樣重要：
 *
 *  **一、閘門真的會關**（封頂 token 暴力破解與它的 `personal_access_tokens` 查詢）
 *  **二、閘門不會誤傷**（被擋期間瀏覽、登入、session 使用者、公開端點必須照常可用）
 *
 * 第二半是 review 實測後補的：第一版無條件短路，額度用完後該 IP 的**所有**請求都 429，包含
 * `GET /login` 與 `POST /login`——機構 NAT 後面一個 token 過期的腳本就能讓整個機構斷線且無法自救。
 */
class FailedAuthenticationThrottleTest extends TestCase {
    /** 帶一個壞 token 的請求標頭（形狀刻意不含 `|`，會走到 Sanctum 真的查一次 DB 的那條路徑）。 */
    private const BAD_TOKEN = ['Authorization' => 'Bearer bogus-token-value'];

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

        // 計數器存在 cache store；顯式清一次，避免同 process 內其他測試留下的計數影響上限判斷。
        Cache::store(config('cache.default'))->clear();

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

        Schema::dropIfExists('personal_access_tokens');
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * 帶壞 token 的 JSON 請求。
     *
     * 刻意走 `call()` 而不是 `withHeaders(...)->json(...)`：`withHeaders()` 會把標頭留在
     * **後續每一個**請求上（MakesHttpRequests 的 defaultHeaders），於是同一個測試方法裡接著發的
     * 「未帶憑證」請求其實仍帶著 Authorization——那會讓「沒帶 token 不受影響」這類斷言變成假紅。
     */
    private function badTokenJson(string $method, string $uri): \Illuminate\Testing\TestResponse {
        return $this->rawRequest($method, $uri, ['HTTP_AUTHORIZATION' => self::BAD_TOKEN['Authorization']]);
    }

    /** 用 server 變數發請求（可指定來源 IP／任意標頭），避免 withHeaders() 殘留到後續請求。 */
    private function rawRequest(string $method, string $uri, array $server = []): \Illuminate\Testing\TestResponse {
        return $this->call($method, $uri, [], [], [], array_merge([
            'HTTP_ACCEPT' => 'application/json',
        ], $server));
    }

    /** 把某個 IP 的失敗額度用完。 */
    private function exhaustBudget(int $limit, string $ip = '127.0.0.1'): void {
        for ($i = 1; $i <= $limit; $i++) {
            $this->rawRequest('GET', '/api/user', [
                'REMOTE_ADDR' => $ip,
                'HTTP_AUTHORIZATION' => 'Bearer bogus-token-value',
            ])->assertStatus(401);
        }
    }

    #[Test]
    public function the_middleware_is_registered_globally_after_cors(): void {
        // 位置就是保護本身：全域 middleware 不受 $middlewarePriority 排序影響，所以只有掛在
        // 全域 stack 才保證跑在路由的 auth 之前（也才不會被路由的 withoutMiddleware() 排除）。
        // 若有人把它移進 api 群組或某條路由，框架會把 auth 排到它前面，這道閘就完全失效——
        // 而單一路由的狀態碼測試仍可能是綠的。
        $global = (new ReflectionClass(Kernel::class))->getDefaultProperties()['middleware'];

        $this->assertContains(
            ThrottleFailedAuthentication::class,
            $global,
            'ThrottleFailedAuthentication 必須註冊在 Kernel::$middleware（全域），否則會被排到 auth 之後'
        );

        // 必須排在 Cors 之後，429 才會帶上 CORS 標頭（跨來源客戶端才讀得到）。
        // 刻意不要求「最後一個」：那會讓日後新增任何全域 middleware 都無故變紅。
        $this->assertGreaterThan(
            array_search(\App\Http\Middleware\Cors::class, $global, true),
            array_search(ThrottleFailedAuthentication::class, $global, true),
            '必須排在 Cors 之後，否則 429 不會帶 CORS 標頭'
        );
    }

    // ------------------------------------------------------------------
    // 一、閘門真的會關
    // ------------------------------------------------------------------

    #[Test]
    public function failed_bearer_authentication_gets_429_after_the_limit(): void {
        config(['auth.failed_attempt_throttle.per_minute' => 3]);

        for ($i = 1; $i <= 3; $i++) {
            $this->badTokenJson('GET', '/api/user')->assertStatus(401);
        }

        $response = $this->badTokenJson('GET', '/api/user');
        $response->assertStatus(429);

        // 斷言 Retry-After 的**值**而不只是存在：窗口長度是這道閘的語義（每分鐘），
        // 把 DECAY_SECONDS 改成 1 或 86400 都不會被「有這個標頭」抓到——前者讓上限變成
        // 每秒 60 次，後者把「每分鐘 60 次」變成「失敗 60 次鎖一天」。
        $retryAfter = (int) $response->headers->get('Retry-After');
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(
            ThrottleFailedAuthentication::DECAY_SECONDS,
            $retryAfter,
            'Retry-After 超過計數窗口：窗口長度與文件宣稱的「每分鐘」不再一致'
        );

        // 429 的形狀（訊息 + X-RateLimit-*）也要釘住：改成裸 response('', 429) 會讓客戶端
        // 失去 JSON 訊息與額度資訊，瀏覽器也拿不到例外處理器 render 的頁面。
        $response->assertJsonPath('message', 'Too Many Attempts.');
        $response->assertHeader('X-RateLimit-Limit', 3);
        $response->assertHeader('X-RateLimit-Remaining', 0);
    }

    #[Test]
    public function the_window_expires(): void {
        // 沒有這條，把 DECAY_SECONDS 從 60 改成 1（上限實際變成 60 次／秒，封頂形同無效）
        // 或改成 86400（失敗 60 次鎖一天）都會存活——所有請求都在同一秒內打完。
        config(['auth.failed_attempt_throttle.per_minute' => 2]);

        $this->exhaustBudget(2);
        $this->badTokenJson('GET', '/api/user')->assertStatus(429);

        $this->travel(ThrottleFailedAuthentication::DECAY_SECONDS + 1)->seconds();

        $this->badTokenJson('GET', '/api/user')->assertStatus(401);
    }

    /**
     * 額度是「這個來源的失敗認證次數」，與路由無關：任何一條路由把額度用完，其餘都必須 429。
     *
     * 刻意枚舉多種形狀而不只 /api/user 與 /api/mcp——review 實測過，只加一句
     * `&& !$request->is('api/v2/*')` 或 `&& $request->is('api/*')` 就能把最值得暴力破解的
     * 那幾條（寫入端點、非 api 前綴的頁面）排除在計數之外，而只枚舉兩條的測試完全看不到。
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function authGatedEndpoints(): array {
        return [
            'sanctum api' => ['GET', '/api/user'],
            'mcp' => ['POST', '/api/mcp'],
            'v2 寫入（web 群組、auth.optional）' => ['POST', '/api/v2/mutate'],
            'web 頁面（JSON 請求）' => ['GET', '/app/manage'],
        ];
    }

    #[Test]
    #[DataProvider('authGatedEndpoints')]
    public function the_budget_is_shared_across_every_auth_gated_endpoint(string $method, string $uri): void {
        config(['auth.failed_attempt_throttle.per_minute' => 2]);

        for ($i = 1; $i <= 2; $i++) {
            $this->badTokenJson($method, $uri)->assertStatus(401);
        }

        foreach (self::authGatedEndpoints() as $label => [$otherMethod, $otherUri]) {
            $this->badTokenJson($otherMethod, $otherUri)
                ->assertStatus(429, "額度用完後 {$label} 仍未被擋下：計數器不該有路徑豁免");
        }
    }

    #[Test]
    public function the_429_short_circuits_before_authentication(): void {
        // 這條才是重點：封頂的價值在於「不再進入 auth」，於是 Sanctum 對不含 `|` 的 token
        // 會做的那次 personal_access_tokens 查詢也被擋在外面。只斷言狀態碼不足以證明這件事
        //（429 也可能發生在 auth 之後）。
        config(['auth.failed_attempt_throttle.per_minute' => 1]);

        $tokenLookups = 0;
        DB::listen(function ($query) use (&$tokenLookups) {
            if (str_contains($query->sql, 'personal_access_tokens')) {
                $tokenLookups++;
            }
        });

        // 第一次：會走完 auth，因此會查一次 token 表（證明計數器與這條查詢的關係成立）。
        $this->badTokenJson('GET', '/api/user')->assertStatus(401);
        $this->assertSame(1, $tokenLookups, '前提不成立：第一次失敗認證應該查一次 personal_access_tokens');

        // 額度用完後：不得再查。
        $this->badTokenJson('GET', '/api/user')->assertStatus(429);
        $this->assertSame(
            1,
            $tokenLookups,
            '額度用完後仍查了 personal_access_tokens，代表 429 發生在 auth 之後，這道閘沒有擋住 DB 成本'
        );
    }

    #[Test]
    public function a_bearer_token_redirected_to_login_also_counts(): void {
        // review 實測出的繞道，也是這版修法補的洞：未認證請求只有 expectsJson() 為真才回 401，
        // 否則 Authenticate 會 302 到 /login。若只認 401，攻擊者不帶 Accept: application/json
        // 就能無限次試 token（每次仍付一次 personal_access_tokens 查詢），而且方向是反的——
        // 守規矩送 Accept: application/json 的客戶端才會被計數。
        config(['auth.failed_attempt_throttle.per_minute' => 2]);

        $html = [
            'HTTP_AUTHORIZATION' => 'Bearer bogus-token-value',
            'HTTP_ACCEPT' => 'text/html',
        ];

        for ($i = 1; $i <= 2; $i++) {
            $this->rawRequest('GET', '/api/user', $html)->assertRedirect(route('login'));
        }

        $this->assertSame(
            429,
            $this->rawRequest('GET', '/api/user', $html)->getStatusCode(),
            '帶 bearer token 而被導向登入頁的請求沒有被計數：不帶 Accept: application/json 就能無限試 token'
        );
    }

    #[Test]
    public function a_redirect_that_is_not_the_login_page_does_not_count(): void {
        // 判定必須精準到「導向登入頁」，不是任何 302：站內有幾條與認證無關的硬導向
        //（/query-playground → /app/query-playground 就是一條），把它們算進來會讓帶 token 的
        // 正常客戶端無故燒額度。
        config(['auth.failed_attempt_throttle.per_minute' => 2]);

        Route::middleware('api')->get('/__test/redirect', fn () => redirect('/app/query-playground'));

        for ($i = 1; $i <= 5; $i++) {
            $redirect = $this->rawRequest('GET', '/__test/redirect', [
                'HTTP_AUTHORIZATION' => self::BAD_TOKEN['Authorization'],
                'HTTP_ACCEPT' => 'text/html',
            ]);
            $this->assertSame(302, $redirect->getStatusCode());
            $this->assertNotSame(route('login'), $redirect->headers->get('Location'), 'fixture 前提不成立');
        }

        $this->badTokenJson('GET', '/api/user')->assertStatus(401);
        $this->badTokenJson('GET', '/api/user')->assertStatus(401);
        $this->badTokenJson('GET', '/api/user')->assertStatus(429);
    }

    #[Test]
    public function each_source_ip_has_its_own_budget(): void {
        // 計數維度必須是 IP：把 key 改成常數會讓一個攻擊者 429 掉所有人，
        // 而全部用同一個 IP 的測試完全看不到這件事。
        config(['auth.failed_attempt_throttle.per_minute' => 2]);

        $this->exhaustBudget(2);
        $this->badTokenJson('GET', '/api/user')->assertStatus(429);

        $other = $this->rawRequest('GET', '/api/user', [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_AUTHORIZATION' => 'Bearer bogus-token-value',
        ]);
        $this->assertSame(401, $other->getStatusCode(), '另一個來源 IP 必須有自己的額度');
    }

    #[Test]
    public function client_controlled_headers_do_not_grant_a_fresh_budget(): void {
        // 專案沒有 TrustProxies，所以計數只能用 $request->ip()（直連 peer）。
        // 若有人把 key 改成讀 X-Forwarded-For 或 User-Agent，攻擊者換一個標頭就能重置額度
        //（兩者都實測過會存活）。這條同時把「沒有 TrustProxies」這個前提釘住。
        config(['auth.failed_attempt_throttle.per_minute' => 2]);

        $this->exhaustBudget(2);

        foreach ([
            ['HTTP_X_FORWARDED_FOR' => '198.51.100.7'],
            ['HTTP_USER_AGENT' => 'rotated-agent/1.0'],
        ] as $spoofed) {
            $response = $this->rawRequest('GET', '/api/user', array_merge(
                ['HTTP_AUTHORIZATION' => 'Bearer bogus-token-value'],
                $spoofed
            ));
            $this->assertSame(
                429,
                $response->getStatusCode(),
                '換一個由客戶端自行決定的標頭就拿到新額度，等於這道閘可以無限繞過：' . json_encode($spoofed)
            );
        }
    }

    #[Test]
    public function a_successful_response_does_not_reset_the_counter(): void {
        // 實測過的繞道：在計數之後加一句「成功就 clear()」，看起來像是「好人不該被記帳」，
        // 實際上讓攻擊者每猜一次 token 就打一個公開頁面，額度永遠回滿、無限次嘗試。
        config(['auth.failed_attempt_throttle.per_minute' => 2]);

        $seq = [];
        $seq[] = $this->badTokenJson('GET', '/api/user')->getStatusCode();
        $seq[] = $this->get('/')->getStatusCode();
        $seq[] = $this->badTokenJson('GET', '/api/user')->getStatusCode();
        $seq[] = $this->get('/')->getStatusCode();
        $seq[] = $this->badTokenJson('GET', '/api/user')->getStatusCode();

        $this->assertSame(
            [401, 200, 401, 200, 429],
            $seq,
            '成功的請求把失敗計數歸零了：攻擊者只要在每次嘗試之間打一個公開頁面就能無限重試'
        );
    }

    // ------------------------------------------------------------------
    // 二、閘門不會誤傷
    // ------------------------------------------------------------------

    #[Test]
    public function requests_without_a_bearer_token_are_never_blocked(): void {
        // 這條是這版修法的核心取捨。第一版無條件短路，額度用完後該 IP 的**所有**請求都 429
        //（實測含 GET /、GET /login、POST /login、以及帶有效 token 的請求）——機構 NAT 後面
        // 一個 token 過期的腳本就能讓整個機構斷線，而且**連登入頁都打不開、無法自救**。
        config(['auth.failed_attempt_throttle.per_minute' => 2]);

        $this->exhaustBudget(2);
        $this->badTokenJson('GET', '/api/user')->assertStatus(429);

        // 同一個 IP 上，沒帶 Bearer token 的請求必須完全不受影響。
        $this->get('/')->assertSuccessful();
        $this->get('/login')->assertSuccessful();
        $this->getJson('/api/user')->assertStatus(401);      // 未帶憑證 → 照常 401，不是 429
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertStatus(302);                              // 登入流程仍可用（被擋住的人要能自救）
    }

    #[Test]
    public function a_401_without_credentials_is_not_counted(): void {
        // 未帶憑證的 401 不是 token 暴力破解，也不會付 personal_access_tokens 查詢：
        //  - MCP 規範要求客戶端先發一個未帶憑證的請求換 WWW-Authenticate（正常握手）；
        //  - /metrics 的 Basic Auth challenge、session 過期的站內 XHR 也都會回 401。
        // 把它們算進來只會誤傷。
        config(['auth.failed_attempt_throttle.per_minute' => 2]);

        for ($i = 1; $i <= 5; $i++) {
            $this->getJson('/api/user')->assertStatus(401);
            $this->postJson('/api/mcp', [])->assertStatus(401);
        }

        // 帶 token 的額度仍然完整。
        $this->badTokenJson('GET', '/api/user')->assertStatus(401);
        $this->badTokenJson('GET', '/api/user')->assertStatus(401);
        $this->badTokenJson('GET', '/api/user')->assertStatus(429);
    }

    #[Test]
    public function non_401_responses_do_not_accumulate(): void {
        // 403（已通過認證、能力或啟用狀態不足）、404、422（表單驗證失敗）都不是「認證失敗」。
        // 422 這條特別重要：把它算進來的話，一般使用者連續填錯幾次表單就會被鎖在門外
        //（實測過那個 mutation：limit=2 時第三次驗證錯誤就變 429）。
        // 419（CSRF）同理不計，但測試環境的 VerifyCsrfToken 在 runningUnitTests() 下會短路，
        // 這裡無法製造出 419，只能靠實作只認 401／導向登入頁這件事保證。
        config(['auth.failed_attempt_throttle.per_minute' => 2]);

        Route::middleware('api')->get('/__test/forbidden', fn () => abort(403));
        Route::middleware('api')->post('/__test/validate', function (Request $request) {
            $request->validate(['required_field' => 'required']);

            return response()->json(['ok' => true]);
        });

        for ($i = 1; $i <= 5; $i++) {
            $this->badTokenJson('GET', '/__test/forbidden')->assertStatus(403);
            $this->badTokenJson('GET', '/__test/no-such-route-here')->assertStatus(404);
            $this->badTokenJson('POST', '/__test/validate')->assertStatus(422);
        }

        // 額度仍然完整。
        $this->badTokenJson('GET', '/api/user')->assertStatus(401);
        $this->badTokenJson('GET', '/api/user')->assertStatus(401);
        $this->badTokenJson('GET', '/api/user')->assertStatus(429);
    }

    #[Test]
    public function unauthenticated_browsers_are_not_counted(): void {
        // **沒帶任何憑證**的瀏覽器逛站內頁面：未登入是 302 到登入頁，這是正常行為，不該吃額度。
        // 與 a_bearer_token_redirected_to_login_also_counts() 是一對——差別只在有沒有帶
        // Authorization 標頭：帶了就是認證失敗，沒帶就是還沒登入的瀏覽器。
        config(['auth.failed_attempt_throttle.per_minute' => 2]);

        for ($i = 1; $i <= 5; $i++) {
            $this->get('/app/manage')->assertRedirect();
        }

        $this->badTokenJson('GET', '/api/user')->assertStatus(401);
        $this->badTokenJson('GET', '/api/user')->assertStatus(401);
        $this->badTokenJson('GET', '/api/user')->assertStatus(429);
    }

    #[Test]
    public function successful_requests_never_accumulate(): void {
        // 只計失敗是本修法的全部價值：認證成功的流量不論多少次都不該吃到這個額度，
        // 否則就退化成「auth 之前的全域 IP 限流」，會誤傷合法使用者（同 NAT 的機構共用一個桶）。
        config(['auth.failed_attempt_throttle.per_minute' => 3]);

        // is_active 不在 $fillable，要 unguarded 才寫得進去（否則帳號是未啟用的，
        // Authenticate 會回 403 而不是 200，測試會誤以為是限流問題）。
        $user = User::unguarded(fn () => User::create([
            'name' => 'Active User',
            'email' => 'active@example.com',
            'password' => bcrypt('secret'),
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_REGULAR,
        ]));
        $token = $user->createToken('cli')->plainTextToken;

        for ($i = 1; $i <= 10; $i++) {
            $this->withHeaders(['Authorization' => 'Bearer ' . $token])
                ->getJson('/api/user')
                ->assertStatus(200);
        }

        // 兩個測試環境的殘留要清掉，否則下面那幾個「壞 token」請求其實仍是已認證的：
        //  - withHeaders() 會留在後續請求上（MakesHttpRequests 的 defaultHeaders）；
        //  - 同一個測試方法內共用一個 application 實例，RequestGuard 會把解析過的 user 快取住。
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        // 成功了 10 次之後，失敗額度應該還是完整的 3 次。
        $this->badTokenJson('GET', '/api/user')->assertStatus(401);
        $this->badTokenJson('GET', '/api/user')->assertStatus(401);
        $this->badTokenJson('GET', '/api/user')->assertStatus(401);
        $this->badTokenJson('GET', '/api/user')->assertStatus(429);
    }

    // ------------------------------------------------------------------
    // 三、設定
    // ------------------------------------------------------------------

    #[Test]
    public function the_configured_limit_is_clamped_to_a_sane_range(): void {
        $maxFor = function (mixed $configured): int {
            config(['auth.failed_attempt_throttle.per_minute' => $configured]);

            return ThrottleFailedAuthentication::maxAttempts();
        };

        $this->assertSame(30, $maxFor(30));

        // 0／負數不是「零額度」：若照字面解讀，該 IP 的 Bearer 客戶端在第一次失敗後就再也發不出
        // 任何請求，一個設定錯字等於對該來源關閉 API。這種值只可能是設定錯誤，退回預設。
        $this->assertSame(ThrottleFailedAuthentication::DEFAULT_MAX_ATTEMPTS, $maxFor(0));
        $this->assertSame(ThrottleFailedAuthentication::DEFAULT_MAX_ATTEMPTS, $maxFor(-5));
        $this->assertSame(ThrottleFailedAuthentication::DEFAULT_MAX_ATTEMPTS, $maxFor(null));
        $this->assertSame(ThrottleFailedAuthentication::DEFAULT_MAX_ATTEMPTS, $maxFor('abc'));

        // 這道閘的用途是封頂失敗，不是放寬任何東西：不得超過 api 群組原本的 600/分。
        $this->assertSame(600, $maxFor(5000));
    }

    #[Test]
    public function the_shipped_configuration_is_sixty_per_minute(): void {
        // 上面那些測試都直接 config([...]) 覆寫上限，所以 config/auth.php 與 .env 的接線
        // 完全沒被測到：把預設值改成 99999（會被夾成 600）、或把鍵名打錯，全部都會存活。
        $this->assertSame(60, (int) config('auth.failed_attempt_throttle.per_minute'));
        $this->assertSame(60, ThrottleFailedAuthentication::maxAttempts());

        $this->assertStringContainsString(
            "env('FAILED_AUTH_THROTTLE_PER_MINUTE', 60)",
            file_get_contents(config_path('auth.php')),
            'config/auth.php 必須用 FAILED_AUTH_THROTTLE_PER_MINUTE 這個 env 鍵名並預設 60'
                . '（.env.example 與 docs/API_AUTHENTICATION.md 都寫著這個名字與數字）'
        );
    }
}
