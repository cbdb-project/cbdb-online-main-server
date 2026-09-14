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
 * 導向 `/app` 對應頁、legacy 寫入端 410 Gone，而 **19 條「不動」的路由完好無損**。
 *
 * 這一檔的重點不是「導向有沒有成功」，而是**三件容易做錯的事**：
 *
 *  1. **不能按 URI prefix 套規則**。同一個 URI 的不同 method 處置不同——`admin/explainsql`
 *     的 GET 要導向、POST 要 410；`codes/{t}/proposals/{op}` 的 PATCH/DELETE 是新舊共用、
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
     * @return array<string, array{0: string, 1: string}>
     */
    public static function gatedDisplayPageProvider(): array {
        return [
            'dashboard' => ['/dashboard', '/app/dashboard'],
            'profile' => ['/profile', '/app/profile'],
            'codes index' => ['/codes', '/app/codes'],
            'codes show' => ['/codes/ADDR_CODES', '/app/codes/ADDR_CODES'],
            'codes create' => ['/codes/ADDR_CODES/create', '/app/codes/ADDR_CODES/create'],
            'codes edit' => ['/codes/ADDR_CODES/1/edit', '/app/codes/ADDR_CODES/1/edit'],
            'operations' => ['/operations', '/app/operations'],
            'manage index' => ['/manage', '/app/manage'],
            'manage edit' => ['/manage/1/edit', '/app/manage/1/edit'],
            'merge-preview' => ['/merge-preview', '/app/merge-preview'],
            'crowdsourcing' => ['/crowdsourcing', '/app/crowdsourcing'],
            'view index' => ['/view', '/app/view'],
            'view show' => ['/view/kinship', '/app/view/kinship'],
            'audit-logs' => ['/admin/audit-logs', '/app/admin/audit-logs'],
            'ai-fill-logs' => ['/admin/ai-fill-logs', '/app/admin/ai-fill-logs'],
            'explainsql' => ['/admin/explainsql', '/app/admin/explainsql'],
            'batch books' => ['/admin/batch-load-book-titles', '/app/admin/batch-load-book-titles'],
            'batch offices' => ['/admin/batch-load-offices', '/app/admin/batch-load-offices'],
            'batch social' => ['/admin/batch-load-social-institutes', '/app/admin/batch-load-social-institutes'],
            'table maintenance' => ['/admin/cbdb-table-maintenance', '/app/admin/cbdb-table-maintenance'],
            'unidirectional repair' => ['/admin/unidirectional-relationship-repair', '/app/admin/unidirectional-relationship-repair'],
            'nl query logs' => ['/query-playground/nl-query-logs', '/app/query-playground/nl-query-logs'],
        ];
    }

    #[Test]
    #[DataProvider('gatedDisplayPageProvider')]
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
        $this->assertStringStartsWith('http://localhost/app/codes/ADDR_CODES?', $target);

        parse_str((string) parse_url($target, PHP_URL_QUERY), $params);
        $this->assertSame(['page' => '3', 'sort_by' => 'c_addr_id'], $params);
    }

    // ── legacy 寫入端 → 410 ──────────────────────────────────

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function gatedWriteEndpointProvider(): array {
        return [
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

    // ── 「不動」的 19 條：必須完好無損 ──────────────────────────

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function untouchedRouteProvider(): array {
        return [
            // 新舊共用同一個 controller method
            'batch books store' => ['POST', 'admin/batch-load-book-titles'],
            'batch books undo' => ['POST', 'admin/batch-load-book-titles/undo'],
            'batch books pinyin' => ['POST', 'admin/batch-load-book-titles/update-pinyin'],
            'batch offices store' => ['POST', 'admin/batch-load-offices'],
            'batch social store' => ['POST', 'admin/batch-load-social-institutes'],
            'codes proposal update' => ['PATCH', 'codes/{table_name}/proposals/{operation}'],
            'codes proposal cancel' => ['DELETE', 'codes/{table_name}/proposals/{operation}'],
            // React 正在呼叫的 action endpoint
            'codes export' => ['GET|HEAD', 'codes/{table_name}/export'],
            'codes proposal edit' => ['GET|HEAD', 'codes/{table_name}/proposals/{operation}/edit'],
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
     * 這是本檔最重要的斷言：**封路 middleware 不得掛到這 19 條上**。
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

    /** manifest 說 35 條，這裡鎖住總數，避免日後零散加掛而沒更新文件。 */
    #[Test]
    public function exactly_the_manifested_routes_are_gated(): void {
        $gated = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $m) {
                if (is_string($m) && str_starts_with($m, 'legacy.page')) {
                    $gated[] = implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();
                }
            }
        }

        $this->assertCount(
            35,
            $gated,
            "封路路由數與 manifest 不符（實際 ".count($gated)." 條）。"
            ."加減路由時請同步更新 docs/BLADE_RETIREMENT_STAGE3_ROUTE_MANIFEST.md：\n"
            .implode("\n", $gated)
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
        $this->actingAs($user)->get('/dashboard')->assertStatus(302);

        config(['legacy_page_retirement.enabled' => false]);

        // 關掉之後請求應抵達原 legacy controller（不是 302、也不是 410）
        $status = $this->actingAs($user)->get('/dashboard')->status();
        $this->assertNotSame(302, $status, 'kill switch 關閉後不該再導向');
        $this->assertNotSame(410, $status, 'kill switch 關閉後不該回 410');
    }

    /** 寫入端的 410 同樣受 kill switch 控制。 */
    #[Test]
    public function the_kill_switch_also_restores_legacy_write_endpoints(): void {
        $user = $this->superAdmin();

        $this->actingAs($user)->patch('/profile', [])->assertStatus(410);

        config(['legacy_page_retirement.enabled' => false]);

        $this->assertNotSame(410, $this->actingAs($user)->patch('/profile', [])->status());
    }
}
