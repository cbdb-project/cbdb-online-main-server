<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * legacy 人物路由下架後的對外行為契約（Blade 下架計畫環節 2）。
 *
 * 取代已刪除的 LegacyBladeFormGateTest：閘門本身沒了，但它的**語義**保留在路由層——
 * 顯示頁 302 導向 /app 對應頁、legacy 寫入端 410 Gone。這兩件事是對外可見的行為，
 * 使用者書籤與外部連結都會碰到，必須有測試鎖住。
 *
 * 另外鎖住三條**刻意保留**的路由（計畫 D-5）：`saveas` 與 `Duplicate_Collateral_Info`
 * 是 React BasicInfoEditor 正在呼叫的端點，`destroy` 則是未被閘門擋過、無 React 對應者。
 * 它們若被後續清理誤刪，React 的按鈕會直接 404。
 */
class LegacyPersonRouteRetirementTest extends TestCase {
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

    private function activeUser(): User {
        return User::forceCreate([
            'name' => 'tester',
            'email' => 'legacy-route-retirement@example.com',
            'confirmation_token' => 'token-123',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_EXPERT,
        ]);
    }

    // ── 顯示頁：302 導向 /app（觀察期語義，刻意不是 301）─────────

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function displayPageProvider(): array {
        return [
            'index' => ['/basicinformation', '/app/basicinformation'],
            'create' => ['/basicinformation/create', '/app/basicinformation/create'],
            'show' => ['/basicinformation/123', '/app/basicinformation/123'],
            'edit' => ['/basicinformation/123/edit', '/app/basicinformation/123/edit'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('displayPageProvider')]
    public function legacy_display_pages_redirect_to_the_react_equivalent(string $from, string $to): void {
        $response = $this->get($from);

        $response->assertStatus(302)->assertRedirect($to);
    }

    /**
     * 觀察期刻意用 302：301 會被瀏覽器與 CDN 長期快取，`git revert` 只還原伺服器，
     * 已經收到 301 的 client 未必會再請求舊 URL——「可逆」在 301 之下不成立。
     */
    #[Test]
    public function redirects_are_temporary_not_permanent(): void {
        foreach (array_keys(self::displayPageProvider()) as $case) {
            [$from] = self::displayPageProvider()[$case];
            $this->get($from)->assertStatus(302);
        }
    }

    /**
     * 列表頁的搜尋條件不能在導向時掉掉，否則使用者的書籤等於失效。
     *
     * 斷言刻意與**參數順序無關**：Laravel 的 getQueryString() 會正規化排序
     * （`q=..&page=3` 出來是 `page=3&q=..`），鎖死字面順序只會讓測試在無意義的地方紅。
     */
    #[Test]
    public function index_redirect_preserves_the_query_string(): void {
        $response = $this->get('/basicinformation?q=%E8%98%87%E8%BB%BE&page=3')->assertStatus(302);

        $target = $response->headers->get('Location');
        // 期望值由 route() 產生，不寫死主機名：`http://localhost` 只是 `APP_URL` 沒設時的
        // 預設值，任何把它設成別的值的環境（例如 `http://localhost:8000`）都會讓這條斷言
        // 在一個與被測行為無關的地方紅。用 route() 還順帶鎖住「導向的是那個具名路由」。
        $this->assertStringStartsWith(route('app.basicinformation.index').'?', $target);

        parse_str((string) parse_url($target, PHP_URL_QUERY), $params);
        $this->assertSame(['page' => '3', 'q' => '蘇軾'], $params);
    }

    // ── legacy 寫入端：410 Gone ──────────────────────────────

    #[Test]
    public function legacy_person_store_is_gone(): void {
        $this->actingAs($this->activeUser())
            ->post('/basicinformation', ['c_name_chn' => '測試'])
            ->assertStatus(410);
    }

    #[Test]
    public function legacy_person_update_is_gone(): void {
        $user = $this->activeUser();

        $this->actingAs($user)->put('/basicinformation/123', ['c_name_chn' => '測試'])->assertStatus(410);
        $this->actingAs($user)->patch('/basicinformation/123', ['c_name_chn' => '測試'])->assertStatus(410);
    }

    // ── legacy 子資源與提案路由：已全數下架 ────────────────────

    #[Test]
    public function legacy_subresource_and_proposal_routes_are_unroutable(): void {
        $urls = [
            '/basicinformation/123/altnames',
            '/basicinformation/123/altnames/create',
            '/basicinformation/123/altnames/edit',
            '/basicinformation/123/addresses',
            '/basicinformation/123/offices',
            '/basicinformation/123/kinship',
            '/basicinformation/123/assoc/edit',
            '/basicinformation/123/biogmain/proposal',
        ];

        foreach ($urls as $url) {
            $this->get($url)->assertStatus(404, "legacy URL {$url} 應已不可路由");
        }
    }

    // ── 刻意保留的三條路由（計畫 D-5）──────────────────────────

    /**
     * `saveas` 與 `Duplicate_Collateral_Info` 是 React BasicInfoEditor 正在呼叫的端點
     * （TabContentLoader 把它們當成按鈕的 href），`destroy` 則是未被閘門擋過者。
     * 這裡只驗「路由仍可抵達」——不是 404、也不是 410；實際行為由各自的既有測試負責。
     */
    #[Test]
    public function intentionally_kept_legacy_write_routes_are_still_routable(): void {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('basicinformation.destroy'));

        foreach (['/basicinformation/123/saveas', '/basicinformation/123/Duplicate_Collateral_Info'] as $url) {
            $status = $this->get($url)->status();
            $this->assertNotSame(404, $status, "{$url} 不應被下架——React 編輯器正在呼叫它");
            $this->assertNotSame(410, $status, "{$url} 不應回 410——React 編輯器正在呼叫它");
        }
    }

    /** CHGIS 地圖點端點：每個 React 頁的地圖 partial 都依賴它的路由名。 */
    #[Test]
    public function person_map_points_route_survives(): void {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('basicinformation.map-points'));
        $this->assertSame(
            '/basicinformation/123/map-points',
            route('basicinformation.map-points', ['id' => 123], false)
        );
    }
}
