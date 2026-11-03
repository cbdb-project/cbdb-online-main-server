<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Inertia\DevTools\DevTools;
use Tests\TestCase;

/**
 * Inertia v3 的 DevTools 在 APP_ENV=local 時預設啟用，而它的 /_inertia/devtools/* 路由在 local 環境
 * 「不要求登入」，記錄裡有每個頁面的 props（含登入者資訊）。docker-compose.yml／.env.docker.example
 * 都寫死 APP_ENV=local，所以必須在程式碼層預設關閉（見 AppServiceProvider::register()）。
 */
class InertiaDevToolsDisabledTest extends TestCase {
    public function test_devtools_is_disabled_by_default_even_in_the_local_environment() {
        // 包預設 enabled=null 代表「看環境」，我們要求未明確設定時一律關閉。
        $this->assertFalse(config('inertia.devtools.enabled'));

        $this->app['env'] = 'local';
        $this->assertTrue($this->app->environment('local'));
        $this->assertFalse(DevTools::enabled(), 'APP_ENV=local 時 DevTools 不可自動啟用。');
    }

    public function test_register_only_fills_in_an_unset_value_and_keeps_an_explicit_choice() {
        // 未設定（null，包預設）→ 固定為關閉
        config(['inertia.devtools.enabled' => null]);
        (new AppServiceProvider($this->app))->register();
        $this->assertFalse(config('inertia.devtools.enabled'));

        // 明確設為 true → 不可被覆蓋（否則沒有辦法在開發環境刻意開啟）
        config(['inertia.devtools.enabled' => true]);
        (new AppServiceProvider($this->app))->register();
        $this->assertTrue(config('inertia.devtools.enabled'));
        $this->assertTrue(DevTools::enabled());

        // 明確設為 false → 維持關閉
        config(['inertia.devtools.enabled' => false]);
        (new AppServiceProvider($this->app))->register();
        $this->assertFalse(config('inertia.devtools.enabled'));
    }

    public function test_package_devtools_defaults_such_as_redaction_are_still_present() {
        // 若有人把整個 devtools 陣列寫進 config/inertia.php（頂層浅合併會整塊蓋掉套件預設），
        // 脫敏清單會變空；同時這也證明套件的 mergeConfigFrom 先於 AppServiceProvider::register() 完成。
        $this->assertNotEmpty(config('inertia.devtools.redact.keys'));
        $this->assertContains('password', config('inertia.devtools.redact.keys'));
    }

    /**
     * 只是冒煙檢查，不是回歸防線：測試環境是 `testing`（不是 `local`），這些路由本來就不會註冊，
     * 撤掉 AppServiceProvider 的修復它仍會通過。真正擋回歸的是上面第一個測試。
     */
    public function test_devtools_routes_are_not_registered() {
        $this->get('/_inertia/devtools/entries')->assertNotFound();
    }
}
