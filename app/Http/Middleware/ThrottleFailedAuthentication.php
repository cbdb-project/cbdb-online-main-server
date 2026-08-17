<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * 把「**帶著 Bearer token 卻認證失敗**」的次數按來源 IP 封頂（#1254）。
 *
 * 為什麼需要這個：框架的 `$middlewarePriority` 把 `AuthenticatesRequests` 排在 `ThrottleRequests`
 * **之前**（`Illuminate\Foundation\Http\Kernel`），所以掛了 `auth`／`auth:sanctum` 的路由上，
 * 未認證請求會在認證階段就返回，路由自己的限流（`throttle:mcp`、`throttle:qa-answer`、`api` 群組
 * 的 600/分）一條都不會執行。實測：把 MCP 上限調成 3、連打 8 次 → 8 個失敗、0 個 429。
 * 每一次帶 token 的失敗都會付一次 `personal_access_tokens` 查詢
 *（`vendor/laravel/sanctum/src/PersonalAccessToken.php` 的 `findToken()`），所以那是一條
 * 「無限次、每次一個索引查詢」的路徑。
 *
 * 為什麼不直接覆寫 `$middlewarePriority` 把 throttle 移到 auth 前面（原 issue 的提案）：
 * 限流若在 auth 之前跑，limiter callback 裡的 `$request->user()` 走的是**預設 guard**（web／session）
 * ——把預設 guard 切成 sanctum 的正是 `Authenticate` middleware——於是 bearer token 請求恆為 null，
 * `qa-answer`／`mcp` 的 `by($request->user()?->id ?: $request->ip())` 全部退成 IP 計數。
 * `tests/Feature/HistoricalQaTest` 現有斷言「同 IP 換使用者額度必須獨立」會被打破，MCP 也會讓
 * 同一個 NAT 後面的機構共用額度（那正是 #1249 改用具名 limiter 要避免的）。而且那個排序是全站
 * 生效的，blast radius 遠大於它解決的問題。
 *
 * ## 範圍：只管「帶 Bearer token 的認證嘗試」
 *
 * 計數與短路**都**要求請求帶了 Bearer token。這個收窄是 review 實測後改的，兩個方向都很重要：
 *
 *  - **短路若不限定，會變成自傷工具。** 第一版無條件短路，實測後果是額度用完後該 IP 的**所有**
 *    請求都 429——包含 `GET /`、`GET /login`、`POST /login`、以及帶著**有效** token 的請求。
 *    CBDB 的主要使用型態是機構 NAT：出口 IP 後面一個 token 過期的整合腳本，就會讓整個機構在該
 *    窗口內連登入頁都打不開、無法自救。現在沒帶 Bearer token 的請求一律不短路，瀏覽、登入、
 *    session 使用者、公開端點在被擋期間完全不受影響。
 *  - **計數若不限定，會被無關的 401 灌滿。** `/metrics` 的 Basic Auth challenge、MCP 客戶端規範
 *    要求的「先發一個未帶憑證的請求換 `WWW-Authenticate`」握手、以及 session 過期的站內 XHR
 *   （`MutationController` 自己回 401，那些路由沒有 auth middleware）都會回 401，但都不是 token
 *    暴力破解，也都不會付 `personal_access_tokens` 查詢。把它們算進來只會誤傷。
 *
 * 留下的代價要說清楚：**同一個 IP 的其他 Bearer 客戶端會被連帶擋住**（額度是 per-IP，不是
 * per-token——要知道 token 有效與否就得先查一次 DB，而那正是要封頂的成本）。回應帶 `Retry-After`。
 *
 * ## 判定「認證失敗」不能只看 401
 *
 * 未認證請求只有在 `expectsJson()` 為真時才回 401（`app/Exceptions/Handler.php` 的
 * `unauthenticated()`），否則 `Authenticate` 會 302 到 `/login`。第一版只認 401，review 實測出
 * 攻擊者**只要不帶 `Accept: application/json`** 就能無限次試 token（每次仍付一次 DB 查詢），
 * 而且方向是反的：守規矩送 `Accept: application/json` 的客戶端才會被計數。所以「帶了 Bearer token
 * 卻被導向登入頁」也算一次失敗。
 *
 * ## 註冊位置
 *
 * 註冊在 `Kernel::$middleware`（全域）：全域 middleware 不受 `$middlewarePriority` 排序影響，
 * 就是字面順序，因此一定在任何路由 middleware（含 auth）之前執行；也無法被路由的
 * `withoutMiddleware()` 排除。位置排在 `Cors` 之後，429 才會帶上 CORS 標頭。
 *
 * **IP 的前提**：專案目前沒有 TrustProxies（全庫 0 命中），`$request->ip()` 取的是直連 peer，
 * 而 Caddy 與 PHP 同容器，所以那是真的 client IP（實測輪替 `X-Forwarded-For` 無法換到新額度）。
 * 日後若前置 CDN／LB，**必須同時設定 TrustProxies**，否則所有請求的 IP 會塌成一個值、這個桶會
 * 變成全站共用一個。反過來說，在沒有代理的現況下加 TrustProxies 才是危險的（client 可自行偽造
 * `X-Forwarded-For`）。
 */
class ThrottleFailedAuthentication {
    /** 設定值不合理時退回的預設上限（每分鐘、每 IP）。 */
    public const DEFAULT_MAX_ATTEMPTS = 60;

    /** 計數器 key 的前綴：與數值型 throttle 的 `sha1(domain|ip)` 命名空間隔離。 */
    private const KEY_PREFIX = 'failed-auth:';

    /** 計數窗口（秒）。 */
    public const DECAY_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response {
        // 不是 Bearer token 認證嘗試就完全不碰：不短路、不計數。
        if ($request->bearerToken() === null) {
            return $next($request);
        }

        $key = $this->resolveKey($request);

        // 取不到來源 IP 時 fail-open。硬要計數就只能讓所有這種請求共用一桶，那會在
        //「日後前置代理卻忘了設 TrustProxies」時把全站塞進同一個 60/分的桶——靜默的全站斷線
        // 比放過幾個請求糟得多。
        if ($key === null) {
            return $next($request);
        }

        $maxAttempts = self::maxAttempts();

        // 先短路：額度用完就不再進入 auth，這樣「無限次失敗」與它帶的那次 DB 查詢都被封頂。
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw $this->buildException($key, $maxAttempts);
        }

        $response = $next($request);

        if ($this->isFailedAuthentication($request, $response)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
        }

        return $response;
    }

    /** 計數器 key；取不到來源 IP 時回 null（呼叫端 fail-open）。 */
    private function resolveKey(Request $request): ?string {
        $ip = $request->ip();

        if ($ip === null || $ip === '') {
            return null;
        }

        return self::KEY_PREFIX . sha1($ip);
    }

    /**
     * 這個回應算不算一次「認證失敗」。（呼叫端已保證請求帶了 Bearer token。）
     *
     * 401 是 JSON 請求的形狀；非 JSON 請求會被導向登入頁，那同樣是認證失敗——帶著 Authorization
     * 標頭卻被丟到 HTML 登入頁，不是瀏覽器在逛頁面。
     *
     * 刻意不計的：403（來自 ability／帳號未啟用的檢查，那些請求**已經**通過認證，攻擊者得先有一個
     * 有效 token）、422（表單驗證，與認證無關）、419（CSRF，過期分頁重送屬常態）。
     */
    private function isFailedAuthentication(Request $request, Response $response): bool {
        if ($response->getStatusCode() === 401) {
            return true;
        }

        if (!$response->isRedirect()) {
            return false;
        }

        // 只認「導向登入頁」這一種轉向，不是任何轉向：站內有幾條與認證無關的硬導向
        //（例如 /query-playground → /app/query-playground），把它們算進來會誤計。
        return Route::has('login')
            && $response->headers->get('Location') === route('login');
    }

    /**
     * 每分鐘每 IP 的失敗認證上限。
     *
     * 夾範圍的理由與 #1249 的 `RouteServiceProvider::mcpRateLimit()` 相同：
     *  - 0／負數／髒值（`(int) 'abc'` = 0）不是「零額度」而幾乎一定是設定錯誤，退回預設比照字面
     *    解讀安全——若真的變成 0，那個 IP 的 Bearer 客戶端在第一次失敗後就再也發不出任何請求。
     *  - 上限夾在 600（`api` 群組的既有上限）：這道閘的用途是封頂失敗，不是放寬任何東西。
     */
    public static function maxAttempts(): int {
        $configured = (int) config('auth.failed_attempt_throttle.per_minute', self::DEFAULT_MAX_ATTEMPTS);

        if ($configured < 1) {
            $configured = self::DEFAULT_MAX_ATTEMPTS;
        }

        return min($configured, 600);
    }

    /**
     * 與 `ThrottleRequests` 一致的 429：交給例外處理器 render，JSON 請求得到 JSON、
     * 頁面請求得到頁面，並帶上 Retry-After／X-RateLimit-* 標頭。
     */
    private function buildException(string $key, int $maxAttempts): ThrottleRequestsException {
        $retryAfter = RateLimiter::availableIn($key);

        return new ThrottleRequestsException('Too Many Attempts.', null, [
            'Retry-After' => $retryAfter,
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => 0,
            'X-RateLimit-Reset' => time() + $retryAfter,
        ]);
    }
}
