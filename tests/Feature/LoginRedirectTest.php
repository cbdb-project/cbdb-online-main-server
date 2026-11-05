<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginRedirectTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\PrometheusMetrics::class);
    }

    /**
     * 測試：redirectPath() 方法應該使用 intended URL
     */
    #[Test]
    public function test_redirect_path_returns_intended_url() {
        $controller = new LoginController();

        // 設置 intended URL 到 session
        session()->put('url.intended', '/dashboard');

        // 調用 redirectPath() 方法
        $redirectPath = $controller->redirectPath();

        // 應該返回 intended URL
        $this->assertEquals(url('/dashboard'), $redirectPath);
    }

    /**
     * 測試：redirectPath() 方法在沒有 intended URL 時應返回默認值
     */
    #[Test]
    public function test_redirect_path_returns_default_when_no_intended() {
        $controller = new LoginController();

        // 確保 session 中沒有 intended URL
        session()->forget('url.intended');

        // 調用 redirectPath() 方法
        $redirectPath = $controller->redirectPath();

        // 應該返回默認的 /home
        $this->assertEquals(url('/home'), $redirectPath);
    }

    /**
     * 測試：驗證 LoginController 有 redirectPath 方法
     */
    #[Test]
    public function test_login_controller_has_redirect_path_method() {
        $controller = new LoginController();

        // 確認 redirectPath 方法存在
        $this->assertTrue(
            method_exists($controller, 'redirectPath'),
            'LoginController 應該有 redirectPath 方法'
        );
    }

    /**
     * 測試：訪問受保護頁面時未登錄應重定向到登錄頁面
     */
    #[Test]
    public function test_unauthenticated_access_redirects_to_login() {
        // 確保未登錄
        auth()->logout();

        // 訪問需要認證的頁面。
        // `/dashboard` 自 Blade 下架環節 4a-3 起是純 redirect closure（Blade 頁已刪、不掛 auth），
        // 所以改打 `/app/dashboard`——那才是實際受 `auth` 保護的路由。
        // 順帶說明使用者實際體驗的變化：打舊 URL 現在是 302 → `/app/dashboard` → 302 → `/login`
        // （多一跳），而 Laravel 記下的 intended URL 變成 `/app/dashboard`，登入後直接落在 React 頁。
        $response = $this->get('/app/dashboard');

        // 應該重定向到登錄頁面
        $response->assertRedirect('/login');
    }

    /**
     * 測試：手動打開登入頁時可保存公開頁面的回跳 URL
     */
    #[Test]
    public function test_show_login_form_stores_safe_redirect_in_session() {
        $controller = new LoginController();
        $request = Request::create('/login', 'GET', [
            'redirect' => '/basicinformation/4/edit?foo=bar',
        ]);
        $request->setLaravelSession(app('session.store'));

        $controller->showLoginForm($request);

        $this->assertSame('/basicinformation/4/edit?foo=bar', session('url.intended'));
    }

    /**
     * 測試：登入頁不接受外部或危險的回跳 URL
     */
    #[Test]
    public function test_show_login_form_ignores_unsafe_redirects() {
        $controller = new LoginController();
        session()->put('url.intended', '/dashboard');

        $request = Request::create('/login', 'GET', [
            'redirect' => 'https://example.com/phishing',
        ]);
        $request->setLaravelSession(app('session.store'));

        $controller->showLoginForm($request);

        $this->assertSame('/dashboard', session('url.intended'));
    }

    /**
     * 測試：反斜線、控制字元與其編碼版本會被瀏覽器解讀成協定相對 URL，必須拒絕
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeRedirectProvider')]
    public function test_show_login_form_rejects_browser_normalised_open_redirects(string $redirect) {
        $controller = new LoginController();
        session()->put('url.intended', '/dashboard');

        $request = Request::create('/login', 'GET', ['redirect' => $redirect]);
        $request->setLaravelSession(app('session.store'));

        $controller->showLoginForm($request);

        $this->assertSame('/dashboard', session('url.intended'));
    }

    public static function unsafeRedirectProvider(): array {
        return [
            'backslash host' => ['/\evil.com'],
            'backslash after slash' => ['/\/evil.com'],
            'encoded backslash' => ['/%5cevil.com'],
            'encoded backslash upper' => ['/%5Cevil.com'],
            'raw tab' => ["/\t/evil.com"],
            'raw newline' => ["/\n/evil.com"],
            'raw carriage return' => ["/\r/evil.com"],
            'raw space' => ['/ /evil.com'],
            'encoded tab' => ['/%09/evil.com'],
            'encoded newline' => ['/%0a/evil.com'],
            'encoded carriage return' => ['/%0D/evil.com'],
            'encoded nul' => ['/%00/evil.com'],
            'encoded delete' => ['/%7f/evil.com'],
            'backslash in query' => ['/ok?next=\evil.com'],
            'protocol relative' => ['//evil.com'],
            'absolute url' => ['https://evil.com/x'],
            'login loop' => ['/login'],
        ];
    }

    /**
     * 測試：帶百分號編碼查詢串的授權請求型 URL 必須原樣保留（state／nonce／PKCE 不能被誤殺或改寫）
     */
    #[Test]
    public function test_show_login_form_keeps_query_with_encoded_values_intact() {
        $controller = new LoginController();
        $redirect = '/oauth/authorize?client_id=abc&redirect_uri=https%3A%2F%2Frp.example%2Fcb&state=a%20b&nonce=n1&code_challenge=xyz&code_challenge_method=S256';

        $request = Request::create('/login', 'GET', ['redirect' => $redirect]);
        $request->setLaravelSession(app('session.store'));

        $controller->showLoginForm($request);

        $this->assertSame($redirect, session('url.intended'));
    }
}
