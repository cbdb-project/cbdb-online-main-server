<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * 🔴🔴 **2026-09-15（Blade 下架環節 4b-4b）起：本 middleware 已無任何掛載點，是死碼。**
 *
 * 所有 legacy Blade 頁面都已實體刪除，舊 URI 改由 `routes/web.php` 的 closure 直接
 * redirect／`abort(410)`。護欄：
 * `LegacyBladePageRetirementTest::no_route_is_gated_by_the_retirement_middleware_any_more()`。
 *
 * ⚠️ **不要把 `legacy.page` 掛回任何路由**：本類有兩條 fail-open 路徑（導向目標不存在時
 * 放行 + `Log::warning`、kill switch 關閉時放行），而視圖都已經不存在——落下去只會得到
 * **500**，不會得到「看到舊頁」。需要封某條路由時請直接寫 closure。
 *
 * 本類與 `config/legacy_page_retirement.php` 待專屬環節（4b-4c）一併移除。
 *
 * ── 以下是它服役時的說明（歷史）─────────────────────────────────
 */
/**
 * Legacy Blade 頁面封路閘門（Blade 下架計畫環節 3）。
 *
 * 「先封路、不刪碼」：legacy 路由與 Blade 視圖都留著，但請求不再抵達 controller——
 * 顯示頁 302 導向 `/app` 對應頁、legacy 寫入端回 410 Gone。這一步**完全可逆**
 * （`git revert` 一個 commit 即恢復），目的是讓「舊 URL 一律落到 React」先上線觀察，
 * 把書籤／外部連結的問題提前暴露，再進環節 4 的實體刪除。
 *
 * 用法（逐條掛，見 docs/BLADE_RETIREMENT_STAGE3_ROUTE_MANIFEST.md）：
 *   ->middleware('legacy.page:app.dashboard')   // GET 顯示頁 → 302 導向該路由
 *   ->middleware('legacy.page:gone')            // legacy 寫入端 → 410
 *
 * ⚠️ **刻意不用 `Route::redirect()`**：它底層是 `Route::any()`，會把同一 URI 的所有
 * HTTP method 一起接管——而 manifest 已證明**同一個 URI 的不同 method 處置不同**
 * （例：`admin/explainsql` 的 GET 要導向、POST 要 410；`codes/{table_name}/export` 是
 * React 自己在呼叫的端點、完全不能動，而同前綴的 `codes/{table_name}` GET 要導向）。
 *（原本這裡舉的例子是 `codes/{t}/proposals/{op}` 的 PATCH/DELETE「新舊共用、完全不能動」，
 * 那在環節 4b-1 收斂後已不成立——它們現在封成 410。論證本身不變，只是例子換掉。）
 *
 * ⚠️ **302 不是 301**：觀察期必須用暫時導向。301 會被瀏覽器與 CDN 長期快取，
 * `git revert` 只還原伺服器——已經收到 301 的 client 未必會再請求舊 URL，
 * 「可逆」在 301 之下並不成立。確定永久下架後才在環節 4 升級。
 *
 * ⚠️ **controller middleware 排在 route middleware 之後**，所以本閘門會先跑：未登入的
 * legacy 非 GET 請求現在拿到 410 而不是 302 導向 `/login`（例：`PUT /manage/1`）。
 * 不是安全問題（410 不洩漏任何資訊），但與封路前的行為不同，值得知道。
 *
 * ⚠️ CSRF 順序：`VerifyCsrfToken` 屬 `web` group、跑在本 middleware **之前**，
 * 所以真實世界未帶 token 的 legacy POST 會先拿到 419 而不是 410（測試環境跳過 CSRF
 * 才看得到 410）。這與已移除的 `LegacyBladeFormGate` 行為一致，非退化。
 */
class RetireLegacyBladePage {
    private const GONE_MESSAGE = '舊版頁面已下架，請改用 /app 介面。';

    public function handle(Request $request, Closure $next, string $target) {
        // 封路總開關（見 config/legacy_page_retirement.php）：
        //   - 營運端的 kill switch：觀察期間發現 React 頁有問題，翻掉它就讓 legacy 頁立刻復活，
        //     不需重新部署、不需 git revert——這正是環節 4 之後就再也沒有的能力；
        //   - 測試 opt-out：仍在驗 legacy Blade 行為的測試以 TestCase::useLegacyBladePages()
        //     局部關閉。那些頁面在環節 3 並未刪除、仍可被 kill switch 叫回，覆蓋依然有意義。
        if (!config('legacy_page_retirement.enabled', true)) {
            return $next($request);
        }

        // legacy 寫入端：一律 410，不看 method——掛這個參數就表示該條本身是寫入端。
        if ($target === 'gone') {
            abort(410, self::GONE_MESSAGE);
        }

        // 非 GET 落到導向型閘門，表示 manifest 漏了分類：寧可 410 也不要把寫入請求
        // 302 出去（redirect 會讓瀏覽器把 POST 降級成 GET、body 整包丟掉，是靜默資料遺失）。
        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
            abort(410, self::GONE_MESSAGE);
        }

        // 目標路由不存在時放行原 controller，而不是 500。這是 fail-open 的刻意選擇：
        // 封路只是過渡手段，若因設定錯誤導向不到目標，讓使用者看到（仍在的）舊頁
        // 遠優於整頁 500。
        //
        // 但**不能靜默**：日後誰把某條 app.* 路由改名，production 就會無聲復活一個 Blade
        // 頁——沒有 500、沒有 log、測試也不會紅。記一筆 warning 把「靜默」換成「可觀測」。
        if (!Route::has($target)) {
            Log::warning('legacy.page 導向目標路由不存在，已放行原 legacy 頁面', [
                'target' => $target,
                'uri' => $request->path(),
            ]);

            return $next($request);
        }

        $parameters = $request->route() ? $request->route()->parameters() : [];
        $url = route($target, $parameters, false);

        $query = $request->getQueryString();
        if ($query !== null && $query !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?').$query;
        }

        return redirect()->to($url, 302);
    }
}
