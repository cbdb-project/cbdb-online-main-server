<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * 未認證的表單端點限流：`POST /register`、`POST /password/email`、`POST /password/reset`（#1264）。
 *
 * 這三條原本**完全沒有任何限流**（`web` 群組沒有 throttle，`Auth::routes()` 也沒包在任何群組裡），
 * 而每一次請求都有實際成本：
 *  - `/register`：validator 的 `unique:users` 至少一次 `SELECT users`，成功還會建一列待管理員啟用的帳號；
 *  - `/password/email`：`SELECT users` ＋ 刪舊 token ＋ 寫 `password_resets` ＋ **同步連 SMTP 寄一封信**
 *   （`QUEUE_CONNECTION=sync`），受害者是那個信箱的擁有者，也會佔住 worker 與我們的寄信信譽；
 *  - `/password/reset`：token 查詢 ＋ 密碼雜湊比對（bcrypt）。
 * 對照組：`POST /login` 早就有 `ThrottlesLogins` 的 5 次／分鐘，所以缺的不是「登入相關端點的防護」，
 * 而是**只有登入那條有**。
 *
 * ## 回應形狀：與登入被鎖定時一致
 *
 * 這三條是表單端點（React/Inertia 為上線路徑，Blade 為 flag 回退）。框架的 `throttle` middleware
 * 超額時丟 `ThrottleRequestsException` → 一頁 HTML 429（專案沒有 `errors/429.blade.php`），而 Inertia
 * 收到非 Inertia 回應會走 `handleNonInertiaResponse()` 彈全螢幕 iframe modal——使用者看不到任何有意義
 * 的訊息，表單也不會停在正常狀態。所以這裡改丟 `ValidationException`（`ThrottlesLogins` 的原作法）：
 * 瀏覽器得到 302 + `email` 欄位錯誤，Inertia 直接就渲染得出來。
 *
 * 用 ValidationException 而不是自己 `back()->withErrors()` 有兩個實際好處：
 *  1. 回填表單時排除敏感欄位的清單來自 `App\Exceptions\Handler::$dontFlash`（單一真源），
 *     不必在這裡另外維護一份會漏的名單——review 實測過，自己維護時漏了 `token`，
 *     於是被限流的 `POST /password/reset` 會把重設 token 明文寫進 session 檔。
 *  2. `redirectTo()` 讓我們指定「導回哪個表單頁」。這點是必要的：`back()` 依賴 Referer，
 *     實測沒有 Referer 時會導到**首頁**，於是 flash 的欄位錯誤永遠不會被渲染（使用者只看到
 *     莫名回到首頁）。JSON 客戶端不走這條，維持標準的 429 + `Retry-After`。
 *
 * ## 計數語義
 *
 * 按來源 IP、**每一次請求都計數**（不像 #1254 的 `ThrottleFailedAuthentication` 只計失敗）：
 * 這裡要封頂的是「請求本身的成本」——寄信與寫庫在成功時才發生，只計失敗等於不設限。
 * 這也表示**被 validator 打回的請求同樣算一次**（密碼太短、confirmed 不符、email 已存在），
 * 所以上限要留出真人重試的餘裕，見 `DEFAULT_MAX_ATTEMPTS` 的說明。
 *
 * IP 的前提同 #1254：專案沒有 TrustProxies，`$request->ip()` 是直連 peer（Caddy 與 PHP 同容器）。
 * **日後若前置 CDN／LB，設定 TrustProxies 是部署的必要條件**：否則 `$request->ip()` 會變成 proxy 的
 * 位址（一個**非空**的值），所有使用者塌進同一個桶——`/password/email` 只有 5／分鐘，等於全站
 * 無法重設密碼。下面的 fail-open 分支救不了這種情形（它只處理「完全取不到 IP」），所以另外在偵測到
 * `X-Forwarded-For` 而框架沒有任何受信任代理時記一行警告，讓這個設定漏洞不要靜默存在。
 *
 * ## 「檢查 ＋ 計數」用 cache lock 串列化
 *
 * `tooManyAttempts()` 與 `hit()` 分開就是 TOCTOU：同時抵達的請求會都看到「還沒超額」。在目前的
 * `file` cache store 上這比「略微超出上限」更糟——`FileStore::increment()` 是**沒有上鎖的**
 * read-modify-write，並發請求會讀到同一個計數再各自寫回同一個較小值（lost update），於是計數可以
 * 被持續壓在上限以下，等於這道閘被大量繞過（codex 覆核指出，原本的「上限仍然生效」說法不成立）。
 *
 * 所以整段臨界區包在 `Cache::lock()` 裡。這在 file store 上是真的 OS 檔案鎖：`FileLock::acquire()`
 * 走 `FileStore::add()`，而後者用 `LockableFile::getExclusiveLock()`（flock LOCK_EX）。
 *
 * **拿不到鎖時一律視為超額（fail-closed）**，理由是鎖的名字含 IP 與端點：合法使用者不會和自己
 * 搶鎖，會出現競爭的就是同一個來源的並發突發本身。
 *
 * 殘留的限制要說清楚：`file` store 是**每個節點一份**目錄，多節點部署時每個節點各有自己的桶
 *（要跨節點共享得換 redis store，`config/cache.php` 已備好但未啟用）；另外
 * `passwords.users.throttle` 的 per-email 節流在框架內部仍是「先查 recentlyCreatedToken 再 create」，
 * 本身不是原子的——不過它上游已經有這道 per-IP 閘把並發量壓住了。
 */
class ThrottleGuestAuthRequests {
    /**
     * 各端點的預設上限（每分鐘、每 IP）。
     *
     * 數字是按「每次請求的成本」與「真人會重試幾次」定的，不是統一值：
     *  - `register` 30：成本只有一次 SELECT ＋ 一次 INSERT，**沒有** SMTP。而且被 validator 打回也算
     *    一次（密碼太短、confirmed 不符），真人常要 2～3 次才過；機構 NAT 後面一整班同時註冊是
     *    CBDB 的真實情境，所以這裡刻意留寬。
     *  - `password-email` 5：唯一會同步寄信的一條，成本最高、真人一分鐘內不需要第二次。
     *    它另有一道按 email 的節流（`config/auth.php` 的 `passwords.users.throttle`）。
     *  - `password-reset` 10：一次 token 查詢 ＋ 一次 bcrypt；使用者可能改幾次密碼才符合規則。
     *
     * @var array<string,int>
     */
    public const DEFAULT_MAX_ATTEMPTS = [
        'register' => 30,
        'password-email' => 5,
        'password-reset' => 10,
    ];

    /** 計數窗口（秒）。 */
    public const DECAY_SECONDS = 60;

    /**
     * 超額時導回的表單頁（`ValidationException::redirectTo()`）。
     *
     * `password-reset` 刻意導回「索取重設連結」那一頁而不是帶 token 的重設表單：我們不把一次性
     * token 放進導向 URL 或 session（見 App\Exceptions\Handler::$dontFlash）。代價是被限流的合法
     * 使用者要重新點一次信裡的連結（原信裡的 token 在有效期內仍可用）。
     */
    private const FORM_ROUTES = [
        'register' => 'register',
        'password-email' => 'password.request',
        'password-reset' => 'password.request',
    ];

    public function handle(Request $request, Closure $next, string $name): Response {
        if (!array_key_exists($name, self::DEFAULT_MAX_ATTEMPTS)) {
            // fail-closed：middleware 參數打錯字時不可以靜默變成「沒有限流」。
            throw new \InvalidArgumentException("未知的 guest 限流名稱: {$name}");
        }

        $ip = (string) $request->ip();

        // 完全取不到 REMOTE_ADDR 時 fail-open，與 #1254 的 ThrottleFailedAuthentication 一致：
        // 這裡的上限只有 5～30，若讓這種請求共用 sha1('') 一個桶，一旦某個環境真的取不到 IP
        // 就會變成全站註冊與重設密碼一起被封——靜默的全站封鎖比放過幾個請求糟得多。
        //
        // 注意這**不是**在處理「前置代理卻忘了設 TrustProxies」：那種情形 $request->ip() 會是
        // proxy 的位址（非空），走不到這個分支。那個設定漏洞只能由部署端修，這裡只負責讓它可見
        //（見 warnIfUntrustedProxy()）。
        if ($ip === '') {
            return $next($request);
        }

        $this->warnIfUntrustedProxy($request);

        $maxAttempts = self::maxAttempts($name);
        $key = self::bucketKey($name, $ip);

        if (!$this->claimAttempt($key, $maxAttempts)) {
            $this->logOncePerWindow($name, $ip, $key, $maxAttempts);

            return $this->tooManyAttempts($request, $name, $key, $maxAttempts);
        }

        return $next($request);
    }

    /**
     * 計數器的 key。
     *
     * 帶端點名：三條端點各有自己的桶。（數值型 `throttle:N,1` 的 key 是 `sha1(domain|ip)`，
     * 不含路由也不含上限值，會與全站其他數值型 throttle 共用計數器——見 RouteServiceProvider
     * 對 #1249 的註解。）
     */
    public static function bucketKey(string $name, string $ip): string {
        return 'guest-auth:' . $name . ':' . sha1($ip);
    }

    /**
     * 在鎖裡完成「檢查上限 ＋ 計數」，回傳這個請求是否放行。
     *
     * 計數在進 controller 之前、且**不論結果都算一次**：這裡封頂的是請求成本，不是失敗次數
     *（寄信與寫庫在成功時才發生，只計失敗等於不設限）。
     *
     * 拿不到鎖＝同一個 (IP, 端點) 正在並發，一律當成超額處理（見類別註解的 fail-closed 理由）。
     */
    private function claimAttempt(string $key, int $maxAttempts): bool {
        $lock = Cache::lock($key . ':lock', 5);

        return $lock->get(function () use ($key, $maxAttempts) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                return false;
            }

            RateLimiter::hit($key, self::DECAY_SECONDS);

            return true;
        }) === true;
    }

    /** 某個端點每分鐘每 IP 的上限。 */
    public static function maxAttempts(string $name): int {
        $default = self::DEFAULT_MAX_ATTEMPTS[$name] ?? 1;
        $configured = (int) config("auth.guest_endpoint_throttle.{$name}", $default);

        // 0／負數／髒值（`(int) 'abc'` = 0）不是「零額度」而幾乎一定是設定錯誤：若照字面解讀，
        // 一個錯字就會讓所有人都註冊不了、也拿不到重設信。夾上限 600 則是「這道閘只用來封頂」。
        if ($configured < 1) {
            $configured = $default;
        }

        return min($configured, 600);
    }

    /**
     * 每個窗口只記一行 log。
     *
     * 沒有任何訊號的話，攻擊進行中在 ops 眼裡只是一堆普通的 302，與驗證失敗分不出來。
     * 但每個被擋的請求都記一行等於把攻擊流量放大進 log，所以用一個 60 秒的 cache 旗標去重
     *（`Cache::add()` 只有在鍵不存在時才回 true，本身是原子的）。
     *
     * 這個 60 秒是「從第一次被擋起算」的滾動去重，**不**與限流窗口對齊——目的只是「持續被擋時
     * 每分鐘至少留一行訊號」，不是精確地一個窗口一行。
     */
    private function logOncePerWindow(string $name, string $ip, string $key, int $maxAttempts): void {
        if (!Cache::add($key . ':logged', 1, self::DECAY_SECONDS)) {
            return;
        }

        Log::warning('guest auth endpoint throttled', [
            'endpoint' => $name,
            'ip' => $ip,
            'limit_per_minute' => $maxAttempts,
        ]);
    }

    /**
     * 偵測「請求帶了 X-Forwarded-For，但框架沒有任何受信任代理」並記一行警告（同樣每窗口一次）。
     *
     * 這是一個**設定漏洞的可見性**機制，不是防護：真的前置了 CDN／LB 卻沒設定 TrustProxies 時，
     * `$request->ip()` 會是 proxy 的位址，於是所有使用者共用一個桶（`/password/email` 只有 5／分鐘
     * ＝全站無法重設密碼），而且從應用層看不出任何異常。反過來，在沒有代理的現況下貿然設定
     * TrustProxies 才是危險的（client 可自行偽造 X-Forwarded-For 換桶），所以這裡只警告、不自動信任。
     */
    private function warnIfUntrustedProxy(Request $request): void {
        if (!$request->headers->has('X-Forwarded-For') || Request::getTrustedProxies() !== []) {
            return;
        }

        if (!Cache::add('guest-auth:untrusted-proxy-warned', 1, self::DECAY_SECONDS)) {
            return;
        }

        Log::warning('guest auth throttle sees X-Forwarded-For but no trusted proxies are configured', [
            'resolved_ip' => $request->ip(),
            'hint' => '若已前置 CDN／LB，請設定 TrustProxies；否則所有使用者會共用同一個限流桶',
        ]);
    }

    /**
     * 超額回應。
     *
     * JSON 客戶端拿到標準的 429 + `Retry-After`；瀏覽器／Inertia 表單走 ValidationException，
     * 得到 302 + `email` 欄位錯誤（`email` 是這三個表單都有渲染的欄位），與登入被鎖定時同一形狀。
     */
    private function tooManyAttempts(Request $request, string $name, string $key, int $maxAttempts): Response {
        $seconds = RateLimiter::availableIn($key);
        $message = __('auth.throttle_requests', ['seconds' => $seconds]);

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 429, [
                'Retry-After' => $seconds,
                'X-RateLimit-Limit' => $maxAttempts,
                'X-RateLimit-Remaining' => 0,
                'X-RateLimit-Reset' => time() + $seconds,
            ]);
        }

        throw ValidationException::withMessages(['email' => $message])
            ->redirectTo($this->formUrl($name));
    }

    /** 表單頁的絕對 URL；路由名不存在時退回站根（不要讓限流本身變成 RouteNotFoundException）。 */
    private function formUrl(string $name): string {
        $routeName = self::FORM_ROUTES[$name] ?? null;

        return $routeName !== null && Route::has($routeName) ? route($routeName) : url('/');
    }
}
