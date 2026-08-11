<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P2-9：關閉註冊不得讓頁面 500。
 *
 * `Auth::routes(['register' => false])` 會讓 `register` 這條命名路由消失，此時任何
 * 無保護的 `route('register')` 都會拋 RouteNotFoundException。先前關註冊就是因此讓
 * `/login` 變成 500——使用者連登入都做不到。
 *
 * 受影響的引用點比原始清單列的兩處多得多：4 個 Blade（其中兩個是**版面**，會讓每個用到
 * 它的頁面一起 500）、`HandleInertiaRequests` 的 `shell.register_url`（**每一頁**都求值的
 * 共享 prop）、以及 `WelcomeController`（**站台首頁**）。
 *
 * 本測試刻意用 **runtime** 檢查而不是掃原始碼字串。第一版用 `str_contains` 掃 Blade，
 * 有三種各自獨立的假綠：完全不掃 PHP（於是漏掉讓首頁 500 的 WelcomeController）、
 * 保護判定是整檔一次而非逐處、以及只認字面 `route('register')` 而漏掉
 * `route('register', [], false)`。那一版 11 個測試全綠，而首頁其實是 500。
 */
class RegistrationDisabledDoesNotBreakPagesTest extends TestCase {
    /**
     * 在 runtime 讓 `register` 這條命名路由消失。
     *
     * 改掉 route 的 `as` 再 refreshNameLookups()：UrlGenerator 與測試共用同一個
     * RouteCollection 物件，所以之後任何 `route('register')` 都會真的拋——不管呼叫點是
     * Blade、controller、共享 prop，還是帶了額外參數的變體。
     */
    private function disableRegisterRoute(): void {
        $routes = Route::getRoutes();

        foreach ($routes as $route) {
            if ($route->getName() === 'register') {
                $route->action['as'] = '__register_disabled__';
            }
        }

        $routes->refreshNameLookups();

        $this->assertFalse(Route::has('register'), '前置條件：register 路由必須已被移除');
    }

    #[Test]
    public function the_home_page_does_not_break_when_registration_is_disabled(): void {
        // welcome flag 預設 new，所以 GET / 走 WelcomeController 的 Inertia 分支。
        $this->disableRegisterRoute();

        $this->assertLessThan(500, $this->get('/')->getStatusCode(), '首頁不得因關閉註冊而 500');
    }

    #[Test]
    public function the_login_page_does_not_break_when_registration_is_disabled(): void {
        // 這正是先前的事故：關了註冊，使用者連登入都做不到。
        $this->disableRegisterRoute();

        $this->assertLessThan(500, $this->get('/login')->getStatusCode(), '登入頁不得因關閉註冊而 500');
    }

    #[Test]
    public function the_shared_inertia_prop_becomes_null_instead_of_throwing(): void {
        // shell.register_url 是每一頁都求值的共享 prop；無保護會讓整個 React 站台每頁 500。
        // 直接對 prop 斷言而不是隨便找一個 React 頁面打：auth.login flag 預設 new，所以
        // /login 就是 Inertia 頁，這條同時覆蓋「頁面不 500」與「prop 為 null」兩件事，
        // 且不依賴任何資料表（挑 /app/basicinformation 會因為本測試沒建表而 500，
        // 那個紅燈與註冊毫無關係——第一版就踩了這個坑）。
        $this->disableRegisterRoute();

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('shell.register_url', null));
    }

    #[Test]
    public function the_register_route_currently_exists_so_the_links_still_render(): void {
        // 反向確認：目前註冊是開放的，所以上面的保護不能把入口整個藏掉。
        $this->assertTrue(Route::has('register'));

        $this->get('/login')->assertOk()->assertSee(route('register', [], false), false);
    }

    #[Test]
    public function no_source_file_calls_the_register_route_without_a_guard(): void {
        // runtime 檢查是主防線（涵蓋所有呼叫形式），這條是第二道：掃 Blade 與 PHP，
        // 逐「出現位置」判定附近有沒有 Route::has 保護，避免整檔一次判定造成的假綠。
        $offenders = [];

        foreach ($this->sourceFiles() as $path) {
            $lines = explode("\n", $this->stripComments((string) file_get_contents($path)));

            foreach ($lines as $i => $line) {
                // 涵蓋 route('register')、route("register")、route('register', [], false) 等變體。
                if (!preg_match('/\broute\(\s*[\'"]register[\'"]/', $line)) {
                    continue;
                }

                // 保護可能寫在同一行（三元）或前面幾行（@if / ? :）。
                $window = implode("\n", array_slice($lines, max(0, $i - 6), 7));
                if (!str_contains($window, "Route::has('register')")) {
                    $offenders[] = $path.':'.($i + 1).' → '.trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "以下位置無保護地呼叫 route('register')，關閉註冊時會 500：\n".implode("\n", $offenders)
        );
    }

    /**
     * 把註解內容抹成空白但保留行數，讓掃描不會把「說明文字裡提到 route('register')」
     * 當成真的呼叫（本檔與 login.blade.php／HandleInertiaRequests 的註解都會提到它，
     * 其中還有跨行的 {{-- --}} 區塊，只看行首字元擋不掉）。
     */
    private function stripComments(string $contents): string {
        $blank = fn (array $m): string => (string) preg_replace('/[^\n]/', ' ', $m[0]);

        // Blade 註解與 PHP 區塊註解（都可能跨行）。
        $contents = (string) preg_replace_callback('/\{\{--.*?--\}\}/s', $blank, $contents);
        $contents = (string) preg_replace_callback('#/\*.*?\*/#s', $blank, $contents);
        // 單行 // 註解（含縮排）。
        $contents = (string) preg_replace('#^(\s*)//.*$#m', '$1', $contents);

        return $contents;
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array {
        $paths = [];

        foreach ([resource_path('views'), app_path()] as $root) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (str_ends_with($file->getFilename(), '.blade.php') || $file->getExtension() === 'php') {
                    $paths[] = $file->getPathname();
                }
            }
        }

        return $paths;
    }
}
