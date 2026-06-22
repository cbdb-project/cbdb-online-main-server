<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 6 — 認證頁與入口頁 React/Inertia 變體測試。
 *
 * flag=new 時各頁 render 對應 Inertia component + props；
 * flag=old（預設）時維持原 Blade（HTML 回應，無 Inertia component）。
 *
 * 不觸及 POST 流程（沿用既有 laravel/ui 測試），純驗證 show* 的條件 render。
 */
class AuthPagesInertiaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-auth-inertia';
        if (!is_dir($compiledViewPath)) {
            mkdir($compiledViewPath, 0777, true);
        }
        config(['view.compiled' => $compiledViewPath]);
    }

    private function flagNew(array $flags): void {
        foreach ($flags as $key) {
            config(["migration_flags.pages.$key" => 'new']);
        }
    }

    // ---- flag=new：render 對應 Inertia component ----

    #[Test]
    public function login_renders_inertia_when_flag_new(): void {
        $this->flagNew(['auth.login']);

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->has('intended'));
    }

    #[Test]
    public function register_renders_inertia_when_flag_new(): void {
        $this->flagNew(['auth.register']);

        $this->get('/register')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Register'));
    }

    #[Test]
    public function forgot_password_renders_inertia_when_flag_new(): void {
        $this->flagNew(['auth.passwords']);

        $this->get('/password/reset')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/ForgotPassword'));
    }

    #[Test]
    public function reset_password_renders_inertia_with_token_email_when_flag_new(): void {
        $this->flagNew(['auth.passwords']);

        $this->get('/password/reset/the-token?email=user%40example.com')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/ResetPassword')
                ->where('token', 'the-token')
                ->where('email', 'user@example.com'));
    }

    #[Test]
    public function welcome_renders_inertia_when_flag_new(): void {
        $this->flagNew(['welcome']);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->where('is_authenticated', false)
                ->has('urls.login')
                ->has('urls.name_api'));
    }

    // ---- flag=old（預設）：維持 Blade，無 Inertia component ----

    #[Test]
    public function login_renders_blade_when_flag_old(): void {
        config(['migration_flags.pages.auth.login' => 'old']);

        $response = $this->get('/login')->assertOk();
        $this->assertStringNotContainsString('data-page', $response->getContent());
        $response->assertSee(__('common.welcome_back'));
    }

    #[Test]
    public function register_renders_blade_when_flag_old(): void {
        config(['migration_flags.pages.auth.register' => 'old']);

        $response = $this->get('/register')->assertOk();
        $this->assertStringNotContainsString('data-page', $response->getContent());
        $response->assertSee(__('common.join_us'));
    }

    #[Test]
    public function forgot_password_renders_blade_when_flag_old(): void {
        config(['migration_flags.pages.auth.passwords' => 'old']);

        $response = $this->get('/password/reset')->assertOk();
        $this->assertStringNotContainsString('data-page', $response->getContent());
        $response->assertSee(__('common.send_reset_link_title'));
    }

    #[Test]
    public function reset_password_renders_blade_when_flag_old(): void {
        config(['migration_flags.pages.auth.passwords' => 'old']);

        $response = $this->get('/password/reset/the-token')->assertOk();
        $this->assertStringNotContainsString('data-page', $response->getContent());
        $response->assertSee(__('common.update_password_title'));
    }

    #[Test]
    public function welcome_renders_blade_when_flag_old(): void {
        config(['migration_flags.pages.welcome' => 'old']);

        $response = $this->get('/')->assertOk();
        $this->assertStringNotContainsString('data-page', $response->getContent());
        $response->assertSee(__('nav.welcome_system_title'));
    }
}
