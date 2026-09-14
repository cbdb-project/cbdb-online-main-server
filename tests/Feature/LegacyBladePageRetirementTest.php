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
     * 仍由 `legacy.page` middleware 封路的顯示頁（視圖還在，kill switch 可叫回）。
     *
     * ⚠️ **已實體刪除的 9 條唯讀頁不在這裡**——它們改成 redirect closure，見
     * `deletedReadonlyPageProvider()`。兩者對 302 行為而言等價（都是 302 + 同一目標），
     * 所以下面那條 302 測試同時吃兩個 provider；但**回退能力完全不同**，
     * 所以身分清單（`exactly_the_manifested_routes_are_gated()`）只含這一批。
     *
     * 把兩批分開放是刻意的：混在同一個 provider 裡只能靠註解區分，
     * 下一個人很容易把 9 條當成 gated 而去改 manifest。
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function gatedDisplayPageProvider(): array {
        return [
            // 環節 4b-1 收斂後才封得起來：它是 React operations 頁「修改提案」連結的目標，
            // 而 OperationsController 產那個 payload 時**沒有 Route::has() 保護**——
            // 所以得先把那一行改指 app.codes.proposals.edit 才能封這條。
            'codes proposal edit' => ['/codes/ADDR_CODES/proposals/1/edit', '/app/codes/ADDR_CODES/proposals/1/edit'],
            'profile' => ['/profile', '/app/profile'],
            'codes index' => ['/codes', '/app/codes'],
            'codes show' => ['/codes/ADDR_CODES', '/app/codes/ADDR_CODES'],
            'codes create' => ['/codes/ADDR_CODES/create', '/app/codes/ADDR_CODES/create'],
            'codes edit' => ['/codes/ADDR_CODES/1/edit', '/app/codes/ADDR_CODES/1/edit'],
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
    #[DataProvider('gatedDisplayPageProvider')]
    #[DataProvider('deletedReadonlyPageProvider')]
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

        foreach (self::gatedDisplayPageProvider() as $label => [$from]) {
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

    // ── legacy 寫入端 → 410 ──────────────────────────────────

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function gatedWriteEndpointProvider(): array {
        return [
            // 環節 4b-1 收斂後才封得起來（manifest 的 B 類）：3 個 batch-load controller 的
            // listRouteName() 原本依 $request->is('app/*') 回傳 redirect 目標，legacy POST 走
            // legacy 分支會多一跳 302 而讓匯入結果的 flash 被 session 老化掉。已收斂成一律 app.*。
            'batch books store' => ['post', '/admin/batch-load-book-titles'],
            'batch books undo' => ['post', '/admin/batch-load-book-titles/undo'],
            'batch books pinyin' => ['post', '/admin/batch-load-book-titles/update-pinyin'],
            'batch offices store' => ['post', '/admin/batch-load-offices'],
            'batch social store' => ['post', '/admin/batch-load-social-institutes'],
            // 與上面那條 GET proposal-edit 同 URI 家族，一起封（manifest 的 A 類）。
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
    #[DataProvider('gatedWriteEndpointProvider')]
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
     * 封路的**身分**必須與 manifest 逐條吻合——不只是數量。
     *
     * 只鎖數量擋得住「零散加掛」，卻擋不住「換掛」：把某條該封的拿掉、同時誤封另一條，
     * 數字還是 35、測試照綠。所以這裡寫死完整清單，diff 會直接指出該改 manifest 哪一行。
     */
    #[Test]
    public function exactly_the_manifested_routes_are_gated(): void {
        $expected = [
            'DELETE codes/{table_name}/proposals/{operation}',
            'DELETE codes/{table_name}/{id}',
            'DELETE manage/{manage}',
            'GET admin/batch-load-book-titles',
            'GET admin/batch-load-offices',
            'GET admin/batch-load-social-institutes',
            'GET admin/cbdb-table-maintenance',
            'GET admin/explainsql',
            'GET admin/unidirectional-relationship-repair',
            'GET codes',
            'GET codes/{table_name}',
            'GET codes/{table_name}/create',
            'GET codes/{table_name}/proposals/{operation}/edit',
            'GET codes/{table_name}/{id}/edit',
            'GET manage',
            'GET manage/create',
            'GET manage/{manage}',
            'GET manage/{manage}/edit',
            'GET profile',
            'PATCH codes/{table_name}/proposals/{operation}',
            'PATCH profile',
            'POST admin/batch-load-book-titles',
            'POST admin/batch-load-book-titles/undo',
            'POST admin/batch-load-book-titles/update-pinyin',
            'POST admin/batch-load-offices',
            'POST admin/batch-load-social-institutes',
            'POST admin/explainsql',
            'POST codes/{table_name}',
            'POST codes/{table_name}/proposal',
            'POST manage',
            'POST|PATCH codes/{table_name}/{id}/proposal',
            'PUT|PATCH codes/{table_name}/{id}',
            'PUT|PATCH manage/{manage}',
        ];

        $gated = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $m) {
                if (is_string($m) && str_starts_with($m, 'legacy.page')) {
                    $methods = implode('|', array_values(array_diff($route->methods(), ['HEAD'])));
                    $gated[] = $methods.' '.$route->uri();

                    break;
                }
            }
        }

        sort($gated);
        sort($expected);

        $this->assertSame(
            $expected,
            $gated,
            '封路清單與 docs/BLADE_RETIREMENT_STAGE3_ROUTE_MANIFEST.md 不符；'
            .'加減或改動封路路由時請同步更新該文件與本清單。'
        );
    }
    // ── kill switch ──────────────────────────────────────────

    /**
     * 封路可用 config 開關即時關閉——這是環節 3「可逆」的實際兌現方式。
     *
     * 觀察期間若發現某個 React 頁有問題，把 `LEGACY_PAGE_RETIREMENT=false` 一翻、
     * 清 config 快取，legacy 頁立刻復活：**不需重新部署、不需 git revert**。
     * 環節 4 實體刪除之後就再也沒有這個能力，所以它值得有測試守著。
     */
    #[Test]
    public function the_kill_switch_restores_the_legacy_pages(): void {
        $user = $this->superAdmin();

        // 預設：封路生效
        $this->actingAs($user)->get('/admin/explainsql')->assertStatus(302);

        config(['legacy_page_retirement.enabled' => false]);

        // 關掉之後請求應抵達原 legacy controller。
        //
        // 這個測試只證明「middleware 讓開了」——它用 /dashboard，而該頁在本檔的精簡 schema
        // 下渲染會因缺表而 5xx，所以**必須連 5xx 一起排除**，否則 500 也會讓
        // assertNotSame(302)/assertNotSame(410) 通過，變成假綠。
        //
        // 「legacy 頁真的復活並渲染成 **Blade**」的實證在同檔的
        // migration_flags_no_longer_reopen_gated_legacy_pages()——它末尾對 /admin/explainsql
        // 斷言 assertViewIs('admin.explain_sql')。
        //（原本指向 InertiaViewTableTest::test_kill_switch_restores_the_legacy_view_page，
        //  該測試已隨環節 4a-3 刪除——/view 的 Blade 頁不存在了，那個能力也不存在了。）
        // 用 /admin/explainsql：它不查業務表，在本檔的精簡 schema 下也能真的渲染，
        // 所以可以斷言 assertOk()——比「不是 302 也不是 410」有意義得多。
        $this->actingAs($user)->get('/admin/explainsql')->assertOk();
    }

    /** 寫入端的 410 同樣受 kill switch 控制。 */
    #[Test]
    public function the_kill_switch_also_restores_legacy_write_endpoints(): void {
        $user = $this->superAdmin();

        $this->actingAs($user)->patch('/profile', [])->assertStatus(410);

        config(['legacy_page_retirement.enabled' => false]);

        $status = $this->actingAs($user)->patch('/profile', [])->status();
        $this->assertNotSame(410, $status);
        $this->assertLessThan(500, $status, '同理：5xx 會讓上面那個斷言變成假綠');
    }

    /**
     * 🔴 **封路不讀 migration flag**——這是回退鍵改變的核心，值得寫死。
     *
     * 環節 3 之前，`codes` 這批頁面把 `MIGRATION_FLAG_*` 翻回 `old` 就會回到 Blade 版；
     * 環節 3 之後 `legacy.page` middleware 排在 controller 之前、且完全不看 flag，所以翻 flag
     * **沒有任何效果**。這不只是行為差異，而是一個**安全**陳述：
     * `docs/CODES_SORT_FILTER_AUTH_GATE.md` 記載「Blade 版 `codes/{table_name}` 是無門檻的深分頁
     * 排序查詢」，而它重新暴露的條件已經從「翻 flag」變成「`LEGACY_PAGE_RETIREMENT=false`」。
     *
     * 沒有這條測試，那份文檔就只是另一句會再次過時的話。
     */
    #[Test]
    public function migration_flags_no_longer_reopen_gated_legacy_pages(): void {
        $user = $this->superAdmin();

        // 把整批頁面 flag 全翻回 old——封路仍然生效。
        //
        // ⚠️ **必須遞迴**：`pages` 含 `admin`／`auth`／`query-playground` 三個巢狀群組，
        // 非遞迴的 array_map 會把 `pages.admin` 從陣列壓成字串 'old'，於是
        // `migration_flag('admin.explain-sql')` 的 Arr::get 中途撞到字串回 null、
        // 落到 `migration_flags.default`——覆寫變成空轉。
        $flipToOld = static function (array $pages) use (&$flipToOld): array {
            return array_map(
                static fn ($value) => is_array($value) ? $flipToOld($value) : 'old',
                $pages
            );
        };
        // ⚠️ default 刻意釘成 **'new'**（與覆寫值相反）：若釘成 'old'，下面的
        // `assertSame('old', ...)` 就分不出「遞迴覆寫成功」與「解析失敗後 fallback 到 default」
        // ——兩者都會回 'old'，斷言照綠。設成 'new' 之後，只有真的讀到覆寫值才會是 'old'。
        config([
            'migration_flags.default' => 'new',
            'migration_flags.pages' => $flipToOld((array) config('migration_flags.pages', [])),
        ]);

        // 覆寫真的生效了（否則下面整個測試是空轉）。
        $this->assertSame('old', migration_flag('codes'), '扁平 key 的覆寫必須生效');
        $this->assertSame('old', migration_flag('admin.explain-sql'), '巢狀群組的覆寫必須也生效');
        // 反面對照：沒被覆寫的未知 key 才會拿到 default，證明上面兩條不是 fallback。
        $this->assertSame('new', migration_flag('a-key-that-does-not-exist'));

        // 顯示頁：仍然 302。涵蓋兩種 middleware 組合——純 legacy.page（codes／manage／explainsql）
        // 與「`auth` 併掛」（`/profile`，順序敏感：auth 若排在封路之後，未登入請求會先被導到 /login）。
        //
        // ⚠️ 原本這裡還列了 /operations、/dashboard、/view/dynasties，但它們自環節 4a-3 起
        // 是 redirect closure——**不管 flag 怎麼翻都會 302**，放在這個測試裡是空轉斷言，
        // 而且會讓「auth 併掛」那個組合變成完全沒被覆蓋（唯一還是 auth + legacy.page 的
        // 顯示頁就是 /profile）。它們的行為由 legacy_readonly_pages_redirect_without_the_kill_switch() 守。
        foreach (['/codes', '/codes/DYNASTIES', '/admin/explainsql', '/manage', '/profile'] as $uri) {
            $this->actingAs($user)
                ->get($uri)
                ->assertStatus(302, "翻 flag 不應讓 {$uri} 回到 Blade 版（封路 middleware 不讀 flag）");
        }

        // 寫入端（`legacy.page:gone`）同樣不讀 flag：33 條封路裡有 19 條是這型，
        // 只驗導向型會漏掉一半。
        foreach ([['patch', '/profile'], ['post', '/codes/DYNASTIES']] as [$method, $uri]) {
            $this->actingAs($user)
                ->{$method}($uri, [])
                ->assertStatus(410, "翻 flag 不應讓 {$method} {$uri} 復活");
        }

        // 對照：真正的鑰匙是 kill switch，且它與 flag 無關（flag 此刻仍是 old）。
        // 斷言 assertViewIs 而非只看 200——把「回到 **Blade 版**」寫實，
        // 免得日後這條路由被改成回 Inertia 時測試還是綠的。
        config(['legacy_page_retirement.enabled' => false]);
        $this->actingAs($user)
            ->get('/admin/explainsql')
            ->assertOk()
            ->assertViewIs('admin.explain_sql');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function deletedReadonlyPageProvider(): array {
        return [
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
     * 🔴 環節 4a-3 的核心行為變化：這 9 條唯讀頁的 Blade 視圖與 controller 方法**已實體刪除**，
     * 所以它們改成純 redirect closure、**不再受 kill switch 控制**。
     *
     * 為什麼值得一條專屬測試：其餘 33 條封路的賣點是「`LEGACY_PAGE_RETIREMENT=false` 就能
     * 即時叫回 Blade 頁」。這 9 條沒有那個能力了——而**光看 302 的狀態碼分辨不出來**。
     * 若日後有人誤以為 kill switch 能救回它們（例如照著舊 runbook 操作），
     * 這條測試是唯一寫死「不能」的地方。
     *
     * 它同時接手了被刪掉的 `InertiaViewTableTest::test_kill_switch_restores_the_legacy_view_page`
     * ——那條原本證明「關掉封路後 Blade 頁真的渲染」，而該能力現在確實不存在。
     */
    #[Test]
    #[DataProvider('deletedReadonlyPageProvider')]
    public function legacy_readonly_pages_redirect_without_the_kill_switch(string $from, string $to): void {
        $user = $this->superAdmin();

        // 預設（封路開啟）：302
        $this->actingAs($user)->get($from)->assertStatus(302)->assertRedirect($to);

        // 關掉 kill switch：**照樣** 302——頁面已經不存在，叫不回來。
        config(['legacy_page_retirement.enabled' => false]);
        $this->actingAs($user)
            ->get($from)
            ->assertStatus(302, "{$from} 的 Blade 頁已實體刪除，kill switch 不該（也無法）把它叫回來")
            ->assertRedirect($to);
    }
}
