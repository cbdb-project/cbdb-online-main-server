<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 認證頁與入口頁的 Inertia 渲染測試。
 *
 * ── 2026-09-15（Blade 下架環節 4c）─────────────────────────────
 * 原本的敘述是「flag=new 時 render Inertia，flag=old（預設）時維持原 Blade」——**兩個部分
 * 現在都不對**：Blade 版已實體刪除、flag 分支已移除，而且 `old` 從來就不是預設
 *（`config/migration_flags.php` 裡這幾個 key 的預設值一直是 `new`）。
 *
 * 📌 底下 6 條 `*_when_flag_new()` 與 `flagNew()` helper 自此**名實不符**（設不設 flag 結果
 * 一樣），刻意不改名：環節 4d 會把整個 flag 機制拆掉，屆時它們會一起收斂，現在改名只是
 * 製造一次無謂的 diff。它們仍有價值——驗的是各頁的 component 與 props。
 *
 * 不觸及 POST 流程（沿用既有 laravel/ui 測試），純驗證 show* 的 render 結果。
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
                ->has('urls.name_api')
                // 人物頁 base 已無 flag 可切（legacy 頁於 Blade 下架計畫環節 2 刪除），一律 /app。
                ->where('urls.person_show', '/app/basicinformation')
                ->where('urls.person_index', '/app/basicinformation'));
    }

    /**
     * 護欄：即使有人把 `basicinformation.*` flag 塞回 config，入口頁的人物連結也不得
     * 指回已刪除的 legacy 頁。原測試是「show／index 兩個 flag 可獨立切換」，那個能力
     * 隨環節 2 一併消失。
     */
    #[Test]
    public function welcome_person_bases_ignore_reintroduced_basicinformation_flags(): void {
        $this->flagNew(['welcome']);
        config([
            'migration_flags.pages.basicinformation.show' => 'old',
            'migration_flags.pages.basicinformation.index' => 'old',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->where('urls.person_show', '/app/basicinformation')
                ->where('urls.person_index', '/app/basicinformation'));
    }

    // ── 2026-09-15（Blade 下架環節 4c）─────────────────────────────
    //
    // 這裡原本有 5 條 `*_renders_blade_when_flag_old()`：auth 四頁與 welcome 是全站**最後**
    // 一批「翻 migration flag 真的會渲染 Blade」的頁面（flag 分支寫在 controller 內部、
    // 路由未封路）。環節 6a 特地**沒有**宣稱「flag 已全面失效」，就是因為這 5 條釘著反例。
    //
    // 環節 4c 把那 5 個 Blade 視圖實體刪除、flag 分支一併移除 ⇒ 那 5 條測試的前提消失。
    // 取而代之的是下面這一條：**把「翻 flag 不再改變任何渲染」寫死**。
    //
    // 🔴 它同時是一句**站台級**的陳述：自此全站沒有任何頁面的渲染受 migration flag 影響，
    // flag 只剩「連結指向」一個作用（側邊欄、payload 裡的 URL）。那正是環節 4d 要拆掉
    // 整個 flag 機制的前提——在它成立之前，4d 做不得。

    #[Test]
    public function flipping_the_flags_no_longer_changes_what_gets_rendered(): void {
        // 五頁的 flag 全部翻成 old——它們在環節 4c 之前會因此渲染 Blade。
        config([
            'migration_flags.pages.auth.login' => 'old',
            'migration_flags.pages.auth.register' => 'old',
            'migration_flags.pages.auth.passwords' => 'old',
            'migration_flags.pages.welcome' => 'old',
        ]);

        // ⚠️ **環節 4d 之後不能再斷言「覆寫生效」**：`migration_flag()` 與
        // `config/migration_flags.php` 都已刪除，那些 config key 沒有任何讀取者。
        // 這條測試的意義因此從「翻 flag 也叫不回 Blade」變成「**連 flag 這個東西都不存在了，
        // 上面那幾行 config() 是對幽靈 key 賦值，五頁照樣渲染 React**」。
        // 機制不存在本身由 LegacyBladePageRetirementTest::the_migration_flag_mechanism_no_longer_exists()
        // 守；這裡留著那幾行 config() 是刻意的——它們示範了「就算有人把 key 塞回來也沒用」。

        foreach ([
            '/login' => 'Auth/Login',
            '/register' => 'Auth/Register',
            '/password/reset' => 'Auth/ForgotPassword',
            '/password/reset/the-token' => 'Auth/ResetPassword',
            '/' => 'Welcome',
        ] as $uri => $component) {
            $this->get($uri)
                ->assertOk("{$uri} 應該仍然 200（Blade 版已刪，翻 flag 不該改變任何事）")
                ->assertInertia(fn (Assert $page) => $page->component($component));
        }
    }
}
