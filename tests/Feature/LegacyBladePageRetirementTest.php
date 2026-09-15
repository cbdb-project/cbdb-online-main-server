<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 環節 3「先封路、不刪碼」的行為契約。
 *
 * 對應 docs/BLADE_RETIREMENT_STAGE3_ROUTE_MANIFEST.md：A-1…A-17 的 legacy 顯示頁 302
 * 導向 `/app` 對應頁、legacy 寫入端 410 Gone，而 **11 條「不動」的路由完好無損**
 * （環節 3 當時是 19 條，環節 4b-1 收斂後把其中 8 條也封掉了）。
 *
 * 這一檔的重點不是「導向有沒有成功」，而是**三件容易做錯的事**：
 *
 *  1. **不能按 URI prefix 套規則**。同一個 URI 的不同 method 處置不同——`admin/explainsql`
 *     的 GET 要導向、POST 要 410；`codes/{table_name}/export` 是 React 自己在呼叫的端點、
 *     完全不能動。下面的「不動」清單就是這件事的護欄。
 *  2. **GET 不等於唯讀**。`crowdsourcing/{id}/confirm|reject` 是 **GET 動詞的寫入端**，
 *     而且 React 正以 `<a href>` 呼叫它們；按「GET 一律導向」會直接把審核功能導掉。
 *  3. **觀察期必須是 302**。301 會被瀏覽器與 CDN 長期快取，`git revert` 只還原伺服器——
 *     已收到 301 的 client 未必會再請求舊 URL，「可逆」在 301 之下不成立。
 */
class LegacyBladePageRetirementTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('confirmation_token')->nullable();
            $table->integer('is_active')->default(0);
            $table->integer('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    private function superAdmin(): User {
        return User::forceCreate([
            'name' => 'tester',
            'email' => 'legacy-page-retirement@example.com',
            'confirmation_token' => 'token-123',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    // ── 顯示頁 → 302 導向 /app ─────────────────────────────────

    /**
     * ── 2026-09-15（環節 4b-4b）：這個 provider 的名稱與「為什麼要分兩批」都已過時 ──
     *
     * 它原本叫 `gatedDisplayPageProvider`，意思是「**仍由 `legacy.page` 封路**、視圖還在、
     * kill switch 可叫回」，與 `deletedLegacyPageProvider()`（已實體刪除、改 closure）
     * 分開放的理由是**回退能力完全不同**、而且只有前者進得了身分清單。
     *
     * 🔴 **環節 4b-4b 之後兩個理由都不成立了**：所有 legacy 頁面都已實體刪除、
     * 沒有任何路由掛 `legacy.page`、身分清單那條測試也換成了「必須是空的」。
     * 兩個 provider 現在性質完全一樣，**只剩「哪個環節刪的」這個歷史差別**。
     *
     * 刻意**不合併**：它們是各環節的刪除清單，分開放才看得出「哪一批是什麼時候沒的」，
     * 而下面那條 302 測試本來就同時吃兩個 provider，覆蓋沒有缺口。
     * 名稱已改成不再宣稱 gated（原 `deletedReadonlyPageProvider` 也改過——codes 的
     * create／edit 不是唯讀頁）。
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function stage4bDeletedDisplayPageProvider(): array {
        return [
            // codes 的 5 條顯示頁已於環節 4b-4a **實體刪除**，移到 deletedLegacyPageProvider()。
            'profile' => ['/profile', '/app/profile'],
            'manage index' => ['/manage', '/app/manage'],
            'manage edit' => ['/manage/1/edit', '/app/manage/1/edit'],
            'explainsql' => ['/admin/explainsql', '/app/admin/explainsql'],
            'batch books' => ['/admin/batch-load-book-titles', '/app/admin/batch-load-book-titles'],
            'batch offices' => ['/admin/batch-load-offices', '/app/admin/batch-load-offices'],
            'batch social' => ['/admin/batch-load-social-institutes', '/app/admin/batch-load-social-institutes'],
            'table maintenance' => ['/admin/cbdb-table-maintenance', '/app/admin/cbdb-table-maintenance'],
            'unidirectional repair' => ['/admin/unidirectional-relationship-repair', '/app/admin/unidirectional-relationship-repair'],
        ];
    }

    #[Test]
    #[DataProvider('stage4bDeletedDisplayPageProvider')]
    #[DataProvider('deletedLegacyPageProvider')]
    public function legacy_display_pages_redirect_to_the_react_equivalent(string $from, string $to): void {
        $this->actingAs($this->superAdmin())
            ->get($from)
            ->assertStatus(302)
            ->assertRedirect($to);
    }

    /** 觀察期必須是暫時導向：301 會被快取，讓 revert 救不回來。 */
    #[Test]
    public function redirects_are_temporary_not_permanent(): void {
        $user = $this->superAdmin();

        foreach (self::stage4bDeletedDisplayPageProvider() as $label => [$from]) {
            $status = $this->actingAs($user)->get($from)->status();
            $this->assertSame(302, $status, "{$label} 必須是 302，不得是 301");
        }
    }

    /** 導向必須保留 query string，否則使用者的搜尋條件書籤等於失效。 */
    #[Test]
    public function redirects_preserve_the_query_string(): void {
        $response = $this->actingAs($this->superAdmin())
            ->get('/codes/ADDR_CODES?sort_by=c_addr_id&page=3')
            ->assertStatus(302);

        $target = (string) $response->headers->get('Location');
        // 期望值由 route() 產生，不寫死主機名：`http://localhost` 只是 `APP_URL` 沒設時的
        // 預設值，任何把它設成別的值的環境（例如 `http://localhost:8000`）都會讓這條斷言
        // 在一個與被測行為無關的地方紅。用 route() 還順帶鎖住「導向的是那個具名路由」。
        $this->assertStringStartsWith(
            route('app.codes.show', ['table_name' => 'ADDR_CODES']).'?',
            $target
        );

        parse_str((string) parse_url($target, PHP_URL_QUERY), $params);
        $this->assertSame(['page' => '3', 'sort_by' => 'c_addr_id'], $params);
    }

    /**
     * ── 2026-09-15（環節 4b-4a 的 review 抓到兩個 bug，這是它們的回歸測試）─────
     *
     * 第一版把 302 目標**手拼成字串**（`'/app/codes/'.rawurlencode($t).'/'.$id.'/edit'`），
     * 產生兩個對舊 `RetireLegacyBladePage` 的退化：
     *  1. 五條裡有一條（`proposals/{operation}/edit`）**漏拼 query string**；
     *  2. `$id` **完全沒編碼**（`$table_name` 有，不一致）——代碼表已支援文本主鍵，
     *     `$id = '慎'` 會讓 `Location` 吐裸 UTF-8，`a%2Fb` 更會被解成真的路徑分隔。
     *
     * 現在一律走 `route($target, $request->route()->parameters(), false)`。
     * `redirects_preserve_the_query_string()` 只驗 `codes.show`，抓不到第 1 點，故另立本條。
     */
    #[Test]
    public function codes_redirects_preserve_the_query_string_and_encode_the_id(): void {
        $user = $this->superAdmin();

        // ① 五條顯示頁**全部**要保留 query string（第一版只有四條）。
        $withQs = [
            '/codes?a=1',
            '/codes/ADDR_CODES?a=1',
            '/codes/ADDR_CODES/create?a=1',
            '/codes/ADDR_CODES/1/edit?a=1',
            '/codes/ADDR_CODES/proposals/1/edit?a=1',
        ];
        foreach ($withQs as $uri) {
            $location = (string) $this->actingAs($user)->get($uri)->headers->get('Location');
            $this->assertStringContainsString('a=1', $location, "{$uri} 的導向丟掉了 query string");
        }

        // ② 文本主鍵要被 rawurlencode，不可原樣吐進 Location。
        $location = (string) $this->actingAs($user)->get('/codes/ALTNAME_DATA/'.rawurlencode('慎').'/edit')
            ->headers->get('Location');
        $this->assertStringContainsString(rawurlencode('慎'), $location);
        $this->assertStringNotContainsString('慎', $location, 'Location 必須是 ASCII URI-reference');

        // ③ 🔴 **`/`、`=`、`&` 刻意原樣通過，不要「順手修掉」**（codex 提出後實測確認的取捨）。
        //
        // `route($target, $params, false)` 會 rawurlencode 但**只放行** `/ ? & # %`——
        // 而這正是被移除的 `RetireLegacyBladePage`（`app/Http/Middleware/…:80-81`）做的事，
        // 一字不差。所以這不是本環節引入的退化，是**刻意的 parity**。
        //
        // 而且不能改：codes 的 `{id}` 是 `where('id', '.*')`，`operations.resource_id` 對複合主鍵
        // 存的就是 `c_personid=108625&c_merged_from_personid=404794` 這種**帶 `=` 與 `&`** 的格式
        // （見 OperationsIndexLinksTest::test_codes_edit_page_resolves_the_right_composite_row）。
        // 先 rawurlencode 再交給 route() 會把那個格式改寫成 `%3D`／`%26`，等於單方面改掉一個
        // 出現在 operations payload 裡的 URL 形狀。
        //
        //（`?` 與 `#` 在真實請求裡永遠到不了 `$id`——它們在 HTTP 層就已經是 query／fragment 的
        // 起點，所以那兩個字元只在「用 route() 產連結」時才可能出現，不在本 closure 的輸入域。）
        $composite = 'c_personid=108625&c_merged_from_personid=404794';
        $location = (string) $this->actingAs($user)->get('/codes/MERGED_PERSON_DATA/'.$composite.'/edit')
            ->headers->get('Location');
        $this->assertStringContainsString($composite, $location, '複合主鍵的 =／& 必須原樣通過');

        $location = (string) $this->actingAs($user)->get('/codes/T/a/b/edit')->headers->get('Location');
        $this->assertStringEndsWith('/app/codes/T/a/b/edit', $location);
    }

    /**
     * ── 2026-09-15（環節 4b-4b，codex 查出）─────────────────────────────
     *
     * 🔴 **把 controller 換成 closure 會弄丟「建構式 middleware」。**
     *
     * `ManagementController` 的 `auth` 是寫在**建構式**裡的（`$this->middleware('auth')`），
     * 不在路由上。方法刪掉、路由改成 closure 之後那道 `auth` 就跟著消失了——實測 HEAD vs
     * 改動後的訪客行為：7 條 `/manage*` 原本**全部 302 → /login**，變成 302 → `/app/manage`
     * 或 **410**。
     *
     * ⚠️ **我與 review agent 都曾憑 middleware 清單的順序推論「訪客本來就拿 302／410」，
     * 是量測推翻了那個推論。** 這條測試把實際行為釘死，免得日後再靠讀順序猜。
     *
     *（`/profile` 那兩條不受影響：它們在 `Route::middleware('auth')->group` 裡，
     * auth 掛在路由上而非建構式。）
     */
    #[Test]
    public function legacy_manage_routes_still_bounce_guests_to_login(): void {
        foreach ([
            ['get', '/manage'],
            ['get', '/manage/create'],
            ['get', '/manage/1'],
            ['get', '/manage/1/edit'],
            ['post', '/manage'],
            ['put', '/manage/1'],
            ['delete', '/manage/1'],
        ] as [$method, $uri]) {
            $this->{$method}($uri, [])
                ->assertRedirect(route('login'), "訪客打 {$method} {$uri} 應導向登入頁，而不是直接拿到 302／410");
        }

        // 對照：`/profile` 的 auth 掛在路由群組上，本來就不受 controller 刪除影響。
        $this->get('/profile')->assertRedirect(route('login'));
        $this->patch('/profile', [])->assertRedirect(route('login'));
    }

    // ── legacy 寫入端 → 410 ──────────────────────────────────

    /**
     * legacy 寫入端 → 410。
     *
     * ── 2026-09-15（環節 4b-4b）：原名 `gatedWriteEndpointProvider` ────────────
     * 這些端點現在全部是 `abort(410)` 的 closure，**不再由 `legacy.page:gone` 封路**
     *（狀態碼一樣，但 kill switch 對它們已無作用）。名稱與敘述同步改掉，免得下一個人
     * 以為還能靠翻開關讓它們復活。
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function retiredWriteEndpointProvider(): array {
        return [
            // 環節 4b-1 收斂後才封得起來（manifest 的 B 類）：3 個 batch-load controller 的
            // listRouteName() 原本依 $request->is('app/*') 回傳 redirect 目標，legacy POST 走
            // legacy 分支會多一跳 302 而讓匯入結果的 flash 被 session 老化掉。已收斂成一律 app.*。
            'batch books store' => ['post', '/admin/batch-load-book-titles'],
            'batch books undo' => ['post', '/admin/batch-load-book-titles/undo'],
            'batch books pinyin' => ['post', '/admin/batch-load-book-titles/update-pinyin'],
            'batch offices store' => ['post', '/admin/batch-load-offices'],
            'batch social store' => ['post', '/admin/batch-load-social-institutes'],
            // codes 這 7 條自環節 4b-4a 起是 `abort(410)` 的 closure，不再是 middleware
            // ——**狀態碼一樣，但 kill switch 對它們已無作用**（同 4a-3 的 merge-preview POST）。
            // 專屬測試見 legacy_codes_endpoints_stay_retired()。
            'codes proposal update' => ['patch', '/codes/ADDR_CODES/proposals/1'],
            'codes proposal cancel' => ['delete', '/codes/ADDR_CODES/proposals/1'],
            'codes store' => ['post', '/codes/ADDR_CODES'],
            'codes update' => ['put', '/codes/ADDR_CODES/1'],
            'codes destroy' => ['delete', '/codes/ADDR_CODES/1'],
            'codes propose store' => ['post', '/codes/ADDR_CODES/proposal'],
            'codes propose update' => ['post', '/codes/ADDR_CODES/1/proposal'],
            'manage store' => ['post', '/manage'],
            'manage update' => ['put', '/manage/1'],
            'manage destroy' => ['delete', '/manage/1'],
            'manage create page' => ['get', '/manage/create'],
            'manage show page' => ['get', '/manage/1'],
            'profile update' => ['patch', '/profile'],
            'explainsql run' => ['post', '/admin/explainsql'],
            'merge-preview submit' => ['post', '/merge-preview'],
        ];
    }

    #[Test]
    #[DataProvider('retiredWriteEndpointProvider')]
    public function legacy_write_endpoints_are_gone(string $method, string $uri): void {
        $this->actingAs($this->superAdmin())
            ->{$method}($uri, [])
            ->assertStatus(410);
    }

    // ── 「不動」的 11 條：必須完好無損 ──────────────────────────
    //
    // 原本是 19 條。環節 4b-1 把其中 8 條封掉了——它們是 manifest 記錄的
    // 「封了不會壞，但收斂還沒做」那兩組（A 類 1 條 + B 類 6 條，加上與 A 同 URI 的
    // GET proposal-edit）。收斂做完之後它們移到上面的封路 provider。

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function untouchedRouteProvider(): array {
        return [
            // React 正在呼叫的 action endpoint
            'codes export' => ['GET|HEAD', 'codes/{table_name}/export'],
            'crowdsourcing confirm' => ['GET|HEAD', 'crowdsourcing/{id}/confirm'],
            'crowdsourcing reject' => ['GET|HEAD', 'crowdsourcing/{id}/reject'],
            'operations approve' => ['POST', 'operations/{operation}/approve'],
            'operations reject' => ['POST', 'operations/{operation}/reject'],
            'operations cancel' => ['DELETE', 'operations/{operation}/cancel'],
            'operations restore' => ['POST', 'operations/{operation}/restore'],
            'table maintenance rebuild' => ['POST', 'admin/cbdb-table-maintenance/rebuild'],
            'table maintenance progress' => ['GET|HEAD', 'admin/cbdb-table-maintenance/progress/{taskId}'],
            'repair assoc' => ['POST', 'admin/unidirectional-relationship-repair/assoc'],
            'repair kinship' => ['POST', 'admin/unidirectional-relationship-repair/kinship'],
        ];
    }

    /**
     * 這是本檔最重要的斷言：**封路 middleware 不得掛到這 11 條上**。
     *
     * 它們分成兩類：新舊共用同一個 controller method（動了 `/app` 那邊會一起壞），
     * 以及 React 正在呼叫的 action endpoint（PHP 端把 URL 組進 Inertia payload，
     * grep `resources/js` 抓不到）。其中 `crowdsourcing/{id}/confirm|reject` 還是
     * **GET 動詞的寫入端**——按「GET 一律導向」的規則會直接命中。
     */
    #[Test]
    #[DataProvider('untouchedRouteProvider')]
    public function routes_react_still_uses_are_not_gated(string $method, string $uri): void {
        $wanted = str_replace('|HEAD', '', $method);
        $match = null;
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() !== $uri) {
                continue;
            }
            $methods = implode('|', array_values(array_diff($route->methods(), ['HEAD'])));
            if ($methods === $wanted) {
                $match = $route;

                break;
            }
        }

        $this->assertNotNull($match, "路由 {$method} {$uri} 不存在——它不該被下架");

        // gatherMiddleware() 可能夾帶 Closure（行內定義的 route middleware），先濾掉再比對。
        $names = array_map(
            fn ($m) => explode(':', $m)[0],
            array_values(array_filter($match->gatherMiddleware(), 'is_string'))
        );
        $this->assertNotContains(
            'legacy.page',
            $names,
            "路由 {$method} {$uri} 被誤掛封路 middleware——見 route manifest 的「不動」清單"
        );
    }

    /**
     * ── 2026-09-15（Blade 下架環節 4b-4c）─────────────────────────────
     *
     * 🔴 **封路機制本身已經移除**：`App\Http\Middleware\RetireLegacyBladePage`、
     * `config/legacy_page_retirement.php`、Kernel 的 `legacy.page` 別名、
     * `TestCase::useLegacyBladePages()` 與 `.env` 的 `LEGACY_PAGE_RETIREMENT` 全部不存在了。
     *
     * 為什麼值得一條測試：這是一個**不可逆的能力移除**。如果日後有人「為了暫時封一下某頁」
     * 把 middleware 加回來，這條會紅並把理由攤在訊息裡——那個 middleware 有兩條 fail-open
     * 路徑（導向目標不存在時放行、開關關閉時放行），而 Blade 視圖**全部都已經刪了**，
     * 落下去只會得到 500，不會得到「看到舊頁」。要封路請直接寫 closure。
     *
     * ⚠️ **這一條不足以取代下面那條全路由掃描**（我一度以為可以，被 review 實測推翻）：
     * 我原本的理由是「機制刪掉之後，沒有那個別名、誰也掛不上，所以掃描那條變恆真」——
     * **不成立**。Laravel 在路由註冊期**不驗證 alias 是否存在**：把
     * `->middleware('legacy.page:app.codes.index')` 寫回某條路由，`gatherMiddleware()`
     * 照樣回傳那個字串、`route:cache` 照樣成功，**只有請求期才炸**
     *（`BindingResolutionException: Target class [legacy.page] does not exist` ⇒ 500）。
     * 也就是說「有人把 legacy.page 寫回路由」這個**最現實的重犯路徑**，只有掃描那條抓得到。
     */
    #[Test]
    public function the_retirement_middleware_and_its_kill_switch_no_longer_exist(): void {
        $this->assertFalse(
            class_exists('App\Http\Middleware\RetireLegacyBladePage'),
            '封路 middleware 已於環節 4b-4c 移除；要封路請直接寫 closure（理由見本測試 docblock）'
        );
        // ⚠️ `config()` 這條排在 `file_exists` **之前**：在 `config:cache` 過的環境裡，
        // 真正會出問題的是「快取檔裡還留著那個鍵」，而不是原始檔在不在（review 指出，
        // 原本的順序會讓 file_exists 先紅、看不到這條的訊息）。
        $this->assertNull(
            config('legacy_page_retirement.enabled'),
            'kill switch 的 config 鍵不該再存在（含 bootstrap/cache/config.php）'
        );
        $this->assertFalse(
            file_exists(config_path('legacy_page_retirement.php')),
            'kill switch 的 config 已移除；它沒有作用對象了'
        );
        $this->assertFalse(
            method_exists($this, 'useLegacyBladePages'),
            'opt-out helper 已移除；沒有 legacy 頁面可以 opt-out 回去了'
        );
    }

    /**
     * 🔴 **沒有任何路由可以宣告已移除的 `legacy.page` middleware。**
     *
     * 這條與上面那條是**兩件事、缺一不可**（review 實測證明）：
     *  - 上面守「機制的檔案不存在」——有人把 middleware 檔還原時會紅；
     *  - 這條守「沒有路由宣告它」——有人**只在路由上寫回字串**時會紅，而那才是最現實的
     *    重犯路徑（「我只是想暫時封一下某頁」）。Laravel 註冊期不驗證 alias，所以那種寫法
     *    在 `route:list`／`route:cache` 都看不出問題，直到有人打那條 URL 才 500。
     *
     * 訊息刻意寫明替代做法，因為紅的時候人多半正在做那件事。
     */
    #[Test]
    public function no_route_declares_the_removed_legacy_page_middleware(): void {
        $declared = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $m) {
                if (is_string($m) && str_starts_with($m, 'legacy.page')) {
                    $methods = implode('|', array_values(array_diff($route->methods(), ['HEAD'])));
                    $declared[] = $methods.' '.$route->uri();

                    break;
                }
            }
        }

        sort($declared);

        $this->assertSame(
            [],
            $declared,
            '`legacy.page` middleware 已於環節 4b-4c 移除，掛上它的路由在請求期會 500'
            .'（BindingResolutionException）。要封路請直接寫 closure——'
            .'那個 middleware 原本的兩條 fail-open 路徑在 Blade 視圖已全刪的情況下只會產生 500。'
        );
    }

    /**
     * 🔴 **兩把舊鑰匙都不存在了**（kill switch 於環節 4b-4c 移除、migration flag 於 4d 移除）。
     *
     * 這是安全／維運相關的陳述：`AGENTS.md`、`README.md`、`.env.example`、部署 runbook
     * 長期告訴維運者「設 `LEGACY_PAGE_RETIREMENT=false` 就能叫回 Blade 頁」，而更早的版本
     * 則說「翻 `MIGRATION_FLAG_*=old`」。**兩者現在都連機制本身都沒有了。**
     *
     * 測法：先斷言機制不存在（函式、config 檔、config 鍵），再打 7 條顯示頁與 5 條寫入端
     * ——全部必須維持 302／410。
     */
    #[Test]
    public function the_migration_flag_mechanism_no_longer_exists(): void {
        $user = $this->superAdmin();

        // ⚠️ **環節 4d 之後不再嘗試「翻 flag」**：`migration_flag()` 與
        // `config/migration_flags.php` 都已刪除。原本這裡會遞迴把整份 `pages` 翻成 'old'、
        // 並把 `default` 釘成相反的 'new' 以排除 fallback 冒充成功——那整套現在沒有對象。
        // 取而代之：直接斷言**機制不存在**，再驗頁面行為。
        $this->assertFalse(
            function_exists('migration_flag'),
            'migration flag 機制已於環節 4d 移除；要回到 Blade 只能 git revert'
        );
        $this->assertFalse(function_exists('migration_flag_is_new'));
        $this->assertFalse(file_exists(config_path('migration_flags.php')));
        $this->assertNull(config('migration_flags.pages'), 'config 鍵不該再存在（含 config:cache）');

        foreach ([
            '/codes' => '/app/codes',
            '/manage' => '/app/manage',
            '/profile' => '/app/profile',
            '/admin/explainsql' => '/app/admin/explainsql',
            '/admin/batch-load-book-titles' => '/app/admin/batch-load-book-titles',
            '/admin/cbdb-table-maintenance' => '/app/admin/cbdb-table-maintenance',
            '/admin/unidirectional-relationship-repair' => '/app/admin/unidirectional-relationship-repair',
        ] as $from => $to) {
            $this->actingAs($user)->get($from)
                ->assertStatus(302, "{$from} 不該因為翻 flag 而回到 Blade")
                ->assertRedirect($to);
        }

        foreach ([
            ['post', '/codes/ADDR_CODES'],
            ['put', '/manage/1'],
            ['patch', '/profile'],
            ['post', '/admin/explainsql'],
            ['post', '/admin/batch-load-book-titles'],
        ] as [$method, $uri]) {
            $this->actingAs($user)->{$method}($uri, [])
                ->assertStatus(410, "{$method} {$uri} 不該因為翻 flag 而復活");
        }
    }

    /**
     * 🔴 環節 4b-4a：codes 全套 Blade（5 個視圖 + 10 個 controller 方法）**已實體刪除**，
     * 那 12 條 legacy 路由只剩 closure。這條把「12 條的狀態碼」逐條寫死。
     *
     * ⚠️ **這條測試的鑑別力邊界**：把 `abort(410)` 改成 `abort(404)` 會紅；但**把
     * `legacy.page` 寫回路由抓不到**（環節 4b-4c 之後那會在請求期 500，不是狀態碼差異）。
     * 抓得到那件事的是 `no_route_declares_the_removed_legacy_page_middleware()`。
     * **兩條缺一不可。**
     *
     * 📌 原本這裡還會先 `config(['legacy_page_retirement.enabled' => false])` 再驗一次
     *「關掉 kill switch 也叫不回來」。環節 4b-4c 把那個 config 整個刪掉之後，那一段變成
     * **對一個沒有讀取者的幻影 key 賦值**——與前半段完全等價的重複斷言（review 指出），
     * 已移除。
     */
    #[Test]
    public function legacy_codes_endpoints_stay_retired(): void {
        $user = $this->superAdmin();

        // 顯示頁：仍 302（不會回到 Blade）。
        foreach (['/codes', '/codes/ADDR_CODES', '/codes/ADDR_CODES/create',
            '/codes/ADDR_CODES/1/edit', '/codes/ADDR_CODES/proposals/1/edit'] as $uri) {
            $this->actingAs($user)->get($uri)
                ->assertStatus(302, "{$uri} 的 Blade 頁已刪除，不該回到 Blade");
        }

        // 寫入端：仍 410。
        foreach ([['post', '/codes/ADDR_CODES'], ['put', '/codes/ADDR_CODES/1'],
            ['delete', '/codes/ADDR_CODES/1'], ['post', '/codes/ADDR_CODES/proposal'],
            ['post', '/codes/ADDR_CODES/1/proposal'], ['patch', '/codes/ADDR_CODES/proposals/1'],
            ['delete', '/codes/ADDR_CODES/proposals/1']] as [$method, $uri]) {
            $this->actingAs($user)->{$method}($uri, [])
                ->assertStatus(410, "{$method} {$uri} 已下架，不該復活");
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function deletedLegacyPageProvider(): array {
        return [
            // ── 環節 4b-4a 刪除的 codes 顯示頁（5 條）────────────────────
            // 與下面 4a-3 那 9 條同一個處置：視圖與 controller 方法都已不存在，
            // 舊 URI 只剩 redirect closure ⇒ **沒有 kill switch 級回退**。
            'codes index' => ['/codes', '/app/codes'],
            'codes show' => ['/codes/ADDR_CODES', '/app/codes/ADDR_CODES'],
            'codes create' => ['/codes/ADDR_CODES/create', '/app/codes/ADDR_CODES/create'],
            'codes edit' => ['/codes/ADDR_CODES/1/edit', '/app/codes/ADDR_CODES/1/edit'],
            'codes proposal edit' => ['/codes/ADDR_CODES/proposals/1/edit', '/app/codes/ADDR_CODES/proposals/1/edit'],
            // ── 環節 4a-3 刪除的唯讀頁（9 條）─────────────────────────
            'dashboard' => ['/dashboard', '/app/dashboard'],
            'operations' => ['/operations', '/app/operations'],
            'view index' => ['/view', '/app/view'],
            'view show' => ['/view/kinship', '/app/view/kinship'],
            'merge-preview' => ['/merge-preview', '/app/merge-preview'],
            'crowdsourcing' => ['/crowdsourcing', '/app/crowdsourcing'],
            'nl query logs' => ['/query-playground/nl-query-logs', '/app/query-playground/nl-query-logs'],
            'audit-logs' => ['/admin/audit-logs', '/app/admin/audit-logs'],
            'ai-fill-logs' => ['/admin/ai-fill-logs', '/app/admin/ai-fill-logs'],
        ];
    }

    /**
     * 🔴 這 14 條顯示頁的 Blade 視圖與 controller 方法**已實體刪除**
     *（環節 4a-3 的 9 條唯讀頁 + 4b-4a 的 5 條 codes 頁），只剩 redirect closure。
     *
     * 它同時接手了被刪掉的 `InertiaViewTableTest::test_kill_switch_restores_the_legacy_view_page`
     * ——那條原本證明「關掉封路後 Blade 頁真的渲染」，而該能力現在確實不存在。
     *
     * 📌 原本這條會先驗一次預設行為、再 `config(['legacy_page_retirement.enabled' => false])`
     * 驗一次「關掉 kill switch 也叫不回來」。環節 4b-4c 把那個 config 整個刪掉之後，
     * 第二段變成**對一個沒有讀取者的幻影 key 賦值**——與第一段完全等價（review 指出），
     * 已移除。「叫不回來」現在由
     * `the_retirement_middleware_and_its_kill_switch_no_longer_exist()` 從機制面守住。
     */
    #[Test]
    #[DataProvider('deletedLegacyPageProvider')]
    public function deleted_legacy_pages_redirect_to_the_react_equivalent(string $from, string $to): void {
        $this->actingAs($this->superAdmin())
            ->get($from)
            ->assertStatus(302, "{$from} 的 Blade 頁已實體刪除，只該 302 到 React 版")
            ->assertRedirect($to);
    }
}
