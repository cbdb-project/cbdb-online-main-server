<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\LoginController;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginRedirectTest extends TestCase {
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

        // 訪問需要認證的頁面
        $response = $this->get('/dashboard');

        // 應該重定向到登錄頁面
        $response->assertRedirect('/login');
    }
}
