<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * #1264：`GET|POST /api/operations/token`（眾包舊通道的憑證簽發端點）補上限流與正確的狀態碼。
 *
 * 這條端點拿 email＋密碼換**長期有效**的 `confirmation_token`，原本：
 *  - 只有 `api` 群組共用的 600／分鐘＝每分鐘 600 次密碼嘗試，每次一發 bcrypt；
 *  - 三條失敗路徑全部回 **200** ＋一段中文錯誤字串，於是「把 200 的 body 當 token 用」的既有客戶端
 *    會拿著錯誤訊息當憑證，監控也看不出這裡正在被暴力破解。
 *
 * 另外一併釘住 `POST /api/v1/user/login` 已改為 410（它從來不可能成功，卻是一條無節流的密碼端點）。
 */
class CrowdsourcingTokenEndpointTest extends TestCase {
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

        // 欄位要與真實 schema 一致（比照 SecurityAuditLogTest）：SecurityAuditLogger 會吞掉
        // 寫入例外（「審計絕不讓業務操作失敗」），所以表結構少一欄的話審計只是靜默不寫，
        // 而依賴它的斷言會變成「證明了 0 筆」這種假通過。
        Schema::dropIfExists('audit_log');
        Schema::create('audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');
            $table->string('table_name', 64);
            $table->string('operation', 16);
            $table->string('actor_type', 32);
            $table->string('actor_id', 128);
            $table->char('operation_id', 26);
            $table->json('row_pk');
            $table->string('row_pk_text', 512);
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
        });
    }

    private function makeUser(int $isActive, int $role, string $password = 'secret-password'): User {
        return User::unguarded(fn () => User::create([
            'name' => '眾包使用者',
            'email' => 'crowd@example.com',
            'password' => bcrypt($password),
            'confirmation_token' => 'LONG-LIVED-TOKEN',
            'is_active' => $isActive,
            'is_admin' => $role,
        ]));
    }

    #[Test]
    public function the_route_uses_the_named_limiter(): void {
        // 必須是具名 limiter：數值型 throttle 的 key 是 sha1(domain|ip)、不含路由與上限值，
        // 會與全站其他數值型 throttle 共用計數器（#1249 實測過的坑）。
        // 逐條累積而不是覆寫：原本寫成 `$found = $route->gatherMiddleware();`，只會斷言**最後一條**
        // 匹配的路由——把限流只掛在 POST（GET 完全沒有閘）就會全綠，而 GET 正是既有客戶端的用法
        //（SecurityAuditLogTest／InactiveAccountAccessTest 都用 GET 打這條）。
        $byMethod = [];
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() !== 'api/operations/token') {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $byMethod[$method] = in_array('throttle:crowdsourcing-token', $route->gatherMiddleware(), true);
            }
        }

        $this->assertSame(
            ['GET' => true, 'POST' => true],
            $byMethod,
            'GET 與 POST 都必須掛上具名 limiter：這條路由是 Route::match([get, post])，'
                . '只掛一邊等於攻擊者換個方法就繞過整道閘'
        );
    }

    #[Test]
    public function the_throttle_also_applies_to_get_requests(): void {
        // 既有客戶端用的是 GET（密碼在 query string）。功能性斷言全用 post() 的話，
        // 「GET 豁免限流」這種改動會完全隱形。
        config(['auth.api_endpoint_throttle.crowdsourcing_token.per_email' => 2]);
        config(['auth.api_endpoint_throttle.crowdsourcing_token.per_ip' => 600]);
        $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING);

        $this->get('/api/operations/token?q=crowd@example.com&p=wrong')->assertStatus(401);
        $this->get('/api/operations/token?q=crowd@example.com&p=wrong')->assertStatus(401);

        $this->get('/api/operations/token?q=crowd@example.com&p=wrong')->assertStatus(429);
    }

    #[Test]
    public function an_array_email_parameter_does_not_blow_up(): void {
        // limiter 的 closure 跑在 hit() 之前，所以它一拋例外就是「無限量產 500 且不計額度」。
        // `(string) []` 在 PHP 8 是 E_WARNING → Laravel 轉成 ErrorException → 500。
        // controller 自己有 is_string 守衛，這道閘不能比它保護的程式碼還脆弱。
        config(['auth.api_endpoint_throttle.crowdsourcing_token.per_email' => 2]);
        config(['auth.api_endpoint_throttle.crowdsourcing_token.per_ip' => 600]);

        $this->post('/api/operations/token', ['q' => ['a@example.com'], 'p' => 'x'])
            ->assertStatus(401);
        $this->get('/api/operations/token?q[]=a@example.com&p=x')
            ->assertStatus(401);

        // 而且這兩次要真的被計數（否則就是「白吃的請求」）。
        $this->post('/api/operations/token', ['q' => ['a@example.com'], 'p' => 'x'])
            ->assertStatus(429);
    }

    #[Test]
    public function bad_credentials_are_now_401_not_200(): void {
        $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING);

        $response = $this->post('/api/operations/token', ['q' => 'crowd@example.com', 'p' => 'wrong']);

        $response->assertStatus(401);
        // body 文字刻意不變（既有客戶端可能在比對字串）。
        $this->assertStringContainsString('您的帳號與密碼輸入錯誤', $response->getContent());
        // 而且不得洩漏 token。
        $this->assertStringNotContainsString('LONG-LIVED-TOKEN', $response->getContent());
    }

    #[Test]
    public function an_inactive_or_non_crowdsourcing_account_is_403(): void {
        $inactive = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_CROWDSOURCING);
        $response = $this->post('/api/operations/token', ['q' => $inactive->email, 'p' => 'secret-password']);
        $response->assertStatus(403);
        // 兩條 403 的 body 各自斷言：否則把兩段訊息互換（使用者被告知錯誤的原因）不會被抓到。
        $this->assertSame(__('auth.account_inactive'), $response->getContent());
        $this->assertStringNotContainsString('LONG-LIVED-TOKEN', $response->getContent());
        // 拒發也要留審計（程式碼自己的承諾是「成功與被拒都要留紀錄」）。
        $this->assertSame(1, DB::table('audit_log')->where('new_data', 'like', '%account_inactive%')->count());

        User::query()->delete();

        $wrongRole = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_REGULAR);
        $response = $this->post('/api/operations/token', ['q' => $wrongRole->email, 'p' => 'secret-password']);
        $response->assertStatus(403);
        $this->assertStringContainsString('眾包身分', $response->getContent());
        $this->assertStringNotContainsString('LONG-LIVED-TOKEN', $response->getContent());
        $this->assertSame(1, DB::table('audit_log')->where('new_data', 'like', '%not_crowdsourcing_role%')->count());
    }

    #[Test]
    public function a_valid_crowdsourcing_account_still_gets_the_raw_token_with_200(): void {
        // 成功路徑的契約不能被這次改動動到：既有客戶端把 200 的 body 直接當 token 用。
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING);

        $response = $this->post('/api/operations/token', ['q' => $user->email, 'p' => 'secret-password']);

        $response->assertStatus(200);
        $this->assertSame('LONG-LIVED-TOKEN', $response->getContent());
    }

    #[Test]
    public function repeated_password_guesses_against_one_account_are_throttled(): void {
        // per_email 維度：擋「針對某個帳號猜密碼」。
        config(['auth.api_endpoint_throttle.crowdsourcing_token.per_email' => 3]);
        config(['auth.api_endpoint_throttle.crowdsourcing_token.per_ip' => 600]);
        $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING);

        for ($i = 1; $i <= 3; $i++) {
            $this->post('/api/operations/token', ['q' => 'crowd@example.com', 'p' => 'wrong'])
                ->assertStatus(401);
        }

        $blocked = $this->post('/api/operations/token', ['q' => 'crowd@example.com', 'p' => 'wrong']);
        $blocked->assertStatus(429);
        $blocked->assertHeader('Retry-After');
    }

    #[Test]
    public function rotating_the_email_is_caught_by_the_per_ip_dimension(): void {
        // 只有 per_email 的話，換一個 email 就重獲額度＝可以無限次猜「哪些帳號存在／密碼是什麼」。
        config(['auth.api_endpoint_throttle.crowdsourcing_token.per_email' => 600]);
        config(['auth.api_endpoint_throttle.crowdsourcing_token.per_ip' => 3]);

        for ($i = 1; $i <= 3; $i++) {
            $this->post('/api/operations/token', ['q' => "guess{$i}@example.com", 'p' => 'wrong'])
                ->assertStatus(401);
        }

        $this->post('/api/operations/token', ['q' => 'guess4@example.com', 'p' => 'wrong'])
            ->assertStatus(429);
    }

    #[Test]
    public function each_source_ip_keeps_its_own_quota(): void {
        // per-IP 的 key 若塌成常數（'cst-ip'），就變成「全世界每分鐘 20 次」——任何一個客戶端
        // 都能讓所有人拿不到 token。全部請求都來自 127.0.0.1 的測試分辨不出這件事。
        config(['auth.api_endpoint_throttle.crowdsourcing_token.per_email' => 600]);
        config(['auth.api_endpoint_throttle.crowdsourcing_token.per_ip' => 2]);

        for ($i = 1; $i <= 2; $i++) {
            $this->post('/api/operations/token', ['q' => "a{$i}@example.com", 'p' => 'wrong'])
                ->assertStatus(401);
        }
        $this->post('/api/operations/token', ['q' => 'a3@example.com', 'p' => 'wrong'])
            ->assertStatus(429);

        $other = $this->call('POST', '/api/operations/token', ['q' => 'a4@example.com', 'p' => 'wrong'], [], [], [
            'REMOTE_ADDR' => '203.0.113.9',
        ]);
        $this->assertSame(401, $other->getStatusCode(), '另一個來源 IP 必須有自己的額度');
    }

    #[Test]
    public function the_per_email_quota_is_scoped_to_both_the_email_and_the_ip(): void {
        // 這道維度必須同時取決於 email 與 IP：
        //  - key 不看 email → 退化成第二個 per-IP 桶，機構 NAT 後面所有人共用 5 次／分鐘；
        //  - key 不看 IP   → 任何人都能燒掉某個眾包帳號的額度＝帳號級 DoS。
        config(['auth.api_endpoint_throttle.crowdsourcing_token.per_email' => 2]);
        config(['auth.api_endpoint_throttle.crowdsourcing_token.per_ip' => 600]);

        for ($i = 1; $i <= 2; $i++) {
            $this->post('/api/operations/token', ['q' => 'victim@example.com', 'p' => 'wrong'])
                ->assertStatus(401);
        }
        $this->post('/api/operations/token', ['q' => 'victim@example.com', 'p' => 'wrong'])
            ->assertStatus(429);

        // 同一個 IP、換一個 email → 另一份額度（證明 key 看 email）。
        $this->post('/api/operations/token', ['q' => 'someone-else@example.com', 'p' => 'wrong'])
            ->assertStatus(401);

        // 同一個 email、換一個 IP → 另一份額度（證明 key 看 IP）。
        $fromOtherIp = $this->call('POST', '/api/operations/token', ['q' => 'victim@example.com', 'p' => 'wrong'], [], [], [
            'REMOTE_ADDR' => '203.0.113.9',
        ]);
        $this->assertSame(
            401,
            $fromOtherIp->getStatusCode(),
            '同一個 email 從別的 IP 應該有自己的額度，否則任何人都能把某個帳號鎖到拿不到 token'
        );
    }

    #[Test]
    public function the_email_bucket_ignores_case(): void {
        // 正規化：否則 'A@x' 與 'a@x' 是兩個桶，per_email 那道形同虛設。
        // 注意只測得到「小寫」這半：limiter 裡的 trim() 到不了——全域 TrimStrings middleware
        // 早就把 q 去過空白（review 實測拿掉 trim() 仍全綠），它留著只是縱深防禦。
        config(['auth.api_endpoint_throttle.crowdsourcing_token.per_email' => 2]);
        config(['auth.api_endpoint_throttle.crowdsourcing_token.per_ip' => 600]);

        $this->post('/api/operations/token', ['q' => 'Crowd@Example.com', 'p' => 'wrong'])->assertStatus(401);
        $this->post('/api/operations/token', ['q' => ' crowd@example.com ', 'p' => 'wrong'])->assertStatus(401);

        $this->post('/api/operations/token', ['q' => 'CROWD@EXAMPLE.COM', 'p' => 'wrong'])
            ->assertStatus(429);
    }

    #[Test]
    public function throttled_attempts_do_not_reach_the_controller(): void {
        // 封頂的價值在於不再付成本（bcrypt）也不再寫 audit_log。
        config(['auth.api_endpoint_throttle.crowdsourcing_token.per_email' => 1]);
        config(['auth.api_endpoint_throttle.crowdsourcing_token.per_ip' => 600]);
        $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING);

        // 事件名記在 new_data 的 __security 裡（見 SecurityAuditLogger::record）。
        $denied = fn () => DB::table('audit_log')
            ->where('new_data', 'like', '%crowdsourcing_token_denied%')
            ->count();

        $this->post('/api/operations/token', ['q' => 'crowd@example.com', 'p' => 'wrong'])->assertStatus(401);
        $this->assertSame(1, $denied(), '前提不成立：第一次失敗應該留下一筆審計');

        $this->post('/api/operations/token', ['q' => 'crowd@example.com', 'p' => 'wrong'])->assertStatus(429);
        $this->assertSame(
            1,
            $denied(),
            '被限流的請求仍進到 controller：bcrypt 與 audit_log 寫入都還在付'
        );
    }

    #[Test]
    public function the_limits_are_clamped_to_a_sane_range(): void {
        foreach (RouteServiceProvider::CROWDSOURCING_TOKEN_LIMIT_DEFAULTS as $dimension => $default) {
            config(["auth.api_endpoint_throttle.crowdsourcing_token.{$dimension}" => 7]);
            $this->assertSame(7, RouteServiceProvider::crowdsourcingTokenLimit($dimension));

            foreach ([0, -3, null, 'abc'] as $dirty) {
                config(["auth.api_endpoint_throttle.crowdsourcing_token.{$dimension}" => $dirty]);
                $this->assertSame($default, RouteServiceProvider::crowdsourcingTokenLimit($dimension));
            }

            config(["auth.api_endpoint_throttle.crowdsourcing_token.{$dimension}" => 99999]);
            $this->assertSame(600, RouteServiceProvider::crowdsourcingTokenLimit($dimension));
        }

        // 出貨設定值（其餘測試都覆寫 config，所以 config/auth.php 的接線本來沒被測到）。
        $this->assertSame(
            ['per_email' => 5, 'per_ip' => 20],
            RouteServiceProvider::CROWDSOURCING_TOKEN_LIMIT_DEFAULTS
        );
        // 連數字一起釘：只斷言 env 鍵名存在的話，把出貨值改成 99999（夾回 600＝正好是這次要取代的
        // 舊上限）或 500（低於夾限、直接生效）都會全綠。
        $source = file_get_contents(config_path('auth.php'));
        $this->assertStringContainsString("env('AUTH_THROTTLE_CROWDSOURCING_TOKEN_PER_EMAIL', 5)", $source);
        $this->assertStringContainsString("env('AUTH_THROTTLE_CROWDSOURCING_TOKEN_PER_IP', 20)", $source);
    }

    #[Test]
    public function the_retired_v1_login_endpoint_returns_410_and_verifies_no_password(): void {
        // 它從來不可能成功（轉發到不存在的 oauth/token → 404，且驗證成功還會留下 session cookie），
        // 失敗路徑更是 500（呼叫不存在的 failed()）。改回 410 之後不再驗證任何密碼，
        // 暴力破解與使用者列舉的表面直接消失。
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING);

        foreach ([
            ['email' => $user->email, 'password' => 'secret-password'],
            ['email' => $user->email, 'password' => 'wrong-password'],
            ['email' => 'nobody@example.com', 'password' => 'whatever'],
            [],
        ] as $payload) {
            $response = $this->postJson('/api/v1/user/login', $payload);
            $response->assertStatus(410);
            $this->assertStringContainsString('personal access token', (string) $response->json('message'));
        }

        // 不得留下已登入的 session（舊實作驗證成功時會留一個）。
        $this->assertGuest();

        // 帶著 session 的請求也要拿到 410：這條路由原本掛 guest（且 ApiController 建構子又掛一次），
        // 於是已登入者會被 RedirectIfAuthenticated 導向 /home、看不到下架訊息（review 實測 302）。
        $this->actingAs($user)->postJson('/api/v1/user/login', [])->assertStatus(410);
    }
}
