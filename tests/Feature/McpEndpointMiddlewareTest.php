<?php

namespace Tests\Feature;

use App\Providers\RouteServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * #1249：`GET /api/mcp` 必須與 POST 一樣受 MCP 專屬限流保護。
 *
 * 背景：`Mcp::web()` 註冊兩條路由（GET 是 MCP 規範要求的 SSE 佔位，恆回 405；POST 是真正的
 * 伺服器），但它只回傳 POST，所以 `routes/ai.php` 原本的 `->middleware([...])` 只套到 POST。
 * GET 因此沒有任何路由層 middleware，僅靠 `api` 群組寬鬆的 600/分把關——同一個 URI 一半有閘
 * 一半沒有。修法是改用群組套限流，並改用具名 limiter（數值型 throttle 的 key 只由 IP 決定，
 * 會與 api 群組的 600 桶相撞，使上限腰斬並與其他 /api 流量互相排擠）。
 *
 * 這裡釘住四件事：
 *  1. GET 與 POST 都掛上具名 limiter `mcp`；
 *  2. 限流真的會擋（不只是「有掛」），且上限取自 config；
 *  3. GET 仍回 405（規範行為不能因為補閘而改變）；
 *  4. POST 的完整閘門（auth:sanctum + ability）沒有被這次改動弄掉。
 */
class McpEndpointMiddlewareTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        // 具名 limiter 的計數器存在 cache store。phpunit.xml 已把 CACHE_DRIVER 設為 array
        // （每個測試 process 一份、互不殘留），這裡再顯式清一次 store，讓「上限調小後打過頭」
        // 那條測試不受同 process 內其他測試留下的計數影響。
        Cache::store(config('cache.default'))->clear();
    }

    /** @return array<int,\Illuminate\Routing\Route> */
    private function mcpRoutes(string $method): array {
        $found = [];

        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === 'api/mcp' && in_array($method, $route->methods(), true)) {
                $found[] = $route;
            }
        }

        return $found;
    }

    private function requireMcpRoute(string $method): \Illuminate\Routing\Route {
        $routes = $this->mcpRoutes($method);

        // 不用 markTestSkipped：那會讓「有人誤把 MCP_ENABLED 關掉」變成全綠。
        // 端點理應存在（config/mcp.php 預設 enabled=true、laravel/mcp 在 require 而非 require-dev）。
        $this->assertCount(
            1,
            $routes,
            "應該恰好有一條 {$method} api/mcp 路由。0 條代表 MCP 端點沒註冊（檢查 mcp.cbdb.enabled）；"
                .'2 條代表有人用「同 URI 後註冊覆蓋」的寫法而 method set 不一致，留下了殘影路由。'
        );

        return $routes[0];
    }

    #[Test]
    public function both_verbs_use_the_named_mcp_rate_limiter(): void {
        foreach (['GET', 'POST'] as $method) {
            $this->assertContains(
                'throttle:mcp',
                $this->requireMcpRoute($method)->gatherMiddleware(),
                "{$method} api/mcp 必須使用具名 limiter `mcp`。若這裡失敗，通常是有人把 routes/ai.php 的"
                    .' throttle 群組拆掉，或改回了數值型 throttle:120,1（那會與 api 群組的 600 桶共用計數器）。'
            );
        }
    }

    #[Test]
    public function the_mcp_budget_is_isolated_from_the_shared_api_throttle(): void {
        // 只換成具名 limiter 還不夠：api 群組繼承來的 throttle:600,1 若仍在，同 IP 的其他
        // /api 流量把 600 桶打滿時，MCP 自己的 120 還有額度卻照樣 429。這裡直接檢查
        // **解析後**的 middleware 清單，確保那條 600 真的被 withoutMiddleware() 排除掉。
        //
        // 這條斷言同時守住 routes/ai.php 與 app/Http/Kernel.php 之間的字串耦合：
        // 若哪天 api 群組的 throttle 參數改了，排除字串會失配，這裡就會紅。
        foreach (['GET', 'POST'] as $method) {
            $resolved = app('router')->gatherRouteMiddleware($this->requireMcpRoute($method));

            $throttles = array_values(array_filter(
                $resolved,
                fn ($name) => is_string($name) && str_contains($name, 'ThrottleRequests')
            ));

            $this->assertSame(
                ['Illuminate\Routing\Middleware\ThrottleRequests:mcp'],
                $throttles,
                "{$method} api/mcp 應該只剩具名 limiter 一條 throttle。若這裡出現 ThrottleRequests:600,1，"
                    .'代表 routes/ai.php 的 withoutMiddleware() 字串與 app/Http/Kernel.php 的 api 群組不再一致。'
            );
        }
    }

    #[Test]
    public function the_limiter_actually_blocks_and_honours_the_configured_limit(): void {
        // gatherMiddleware() 只證明「有掛」。這裡把上限調小並真的打過頭，確認會 429，
        // 同時證明 limiter 是在請求時才讀 config（而不是註冊路由當下就把數字定死）。
        $this->requireMcpRoute('GET');
        config(['mcp.cbdb.rate_limit_per_minute' => 3]);

        for ($i = 1; $i <= 3; $i++) {
            $this->get('/api/mcp')->assertStatus(405);
        }

        $this->get('/api/mcp')->assertStatus(429);
    }

    #[Test]
    public function the_configured_limit_is_clamped_to_a_sane_range(): void {
        // 直接取 limiter callback 算出的 Limit，不必真的打上百次請求。
        $limitFor = function (mixed $configured): int {
            config(['mcp.cbdb.rate_limit_per_minute' => $configured]);

            return RateLimiter::limiter('mcp')(Request::create('/api/mcp'))->maxAttempts;
        };

        // 正常值照用。
        $this->assertSame(50, $limitFor(50));

        // 0／負數不是「零額度」——Limit::perMinute(0) 實測會放行第一個請求，等於幾乎不設限，
        // 只可能是設定錯誤，退回預設。
        $this->assertSame(RouteServiceProvider::MCP_RATE_LIMIT_DEFAULT, $limitFor(0));
        $this->assertSame(RouteServiceProvider::MCP_RATE_LIMIT_DEFAULT, $limitFor(-5));

        // 不得放寬超過 api 群組原本的 600/分（MCP 群組已把那條排除掉，這裡自己守住承諾）。
        $this->assertSame(600, $limitFor(5000));

        // 髒設定值（null／非數字字串）經 (int) 轉型為 0，同樣退回預設而不是變成幾乎不設限。
        $this->assertSame(RouteServiceProvider::MCP_RATE_LIMIT_DEFAULT, $limitFor(null));
        $this->assertSame(RouteServiceProvider::MCP_RATE_LIMIT_DEFAULT, $limitFor('abc'));
    }

    #[Test]
    public function get_still_answers_405_as_the_protocol_expects(): void {
        // 補閘不得改變協定層語義：MCP 客戶端探測 GET 時應得到「不支援 SSE」的 405，
        // 而不是 401——這是刻意不在 GET 上加 auth:sanctum 的原因。
        $this->requireMcpRoute('GET');

        $this->get('/api/mcp')->assertStatus(405);
    }

    #[Test]
    public function post_keeps_its_full_authorization_gate(): void {
        $middleware = $this->requireMcpRoute('POST')->gatherMiddleware();

        $this->assertContains('auth:sanctum', $middleware);
        $this->assertContains('mcp.ability:'.config('mcp.cbdb.required_ability', 'mcp:read'), $middleware);
    }

    #[Test]
    public function post_without_a_token_is_rejected(): void {
        // 端到端確認 POST 的閘門真的生效（middleware 清單斷言只證明有掛，不證明會擋）。
        $this->requireMcpRoute('POST');

        $this->postJson('/api/mcp', [])->assertUnauthorized();
    }
}
