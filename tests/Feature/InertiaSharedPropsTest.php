<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Inertia 共用 props 契約測試（守護 Phase 0 F4/F2/F5 的 share() 輸出）。
 *
 * 這是遷移頁面測試範式的一部分：頁面層級用 $response->assertInertia(fn (Assert $page) =>
 * $page->component(...)->has(...)->where(...))（範例見 InertiaSearchByEntryTest、
 * HistoricalQaTest）；此處則直接驗證 middleware 注入的共用 props 形狀，與頁面解耦。
 */
class InertiaSharedPropsTest extends TestCase {
    use RefreshDatabase;

    /** 以指定使用者（或 guest）解析 share() 輸出。 */
    private function shareFor(?User $user): array {
        $request = Request::create('/app/query-playground', 'GET');
        $request->setUserResolver(fn () => $user);

        return (new HandleInertiaRequests())->share($request);
    }

    public function test_guest_share_has_null_user_but_full_contract(): void {
        $props = $this->shareFor(null);

        $this->assertNull($props['auth']['user']);
        $this->assertIsArray($props['flash']);
        $this->assertIsArray($props['nav']);
        $this->assertArrayHasKey('home_url', $props['shell']);
        $this->assertArrayHasKey('logout_url', $props['shell']);
        // 殼所需翻譯群組常駐
        foreach (['common', 'nav', 'auth', 'validation'] as $group) {
            $this->assertArrayHasKey($group, $props['translations']);
        }
    }

    public function test_shell_home_url_respects_basicinformation_index_flag(): void {
        config(['migration_flags.pages.basicinformation.index' => 'old']);
        $this->assertSame('/basicinformation', $this->shareFor(null)['shell']['home_url']);

        config(['migration_flags.pages.basicinformation.index' => 'new']);
        $this->assertSame('/app/basicinformation', $this->shareFor(null)['shell']['home_url']);
    }

    public function test_share_exposes_app_name_and_version(): void {
        config(['app.name' => 'CBDB Online']);

        $props = $this->shareFor(null);

        // 側邊欄品牌名改由 share() 的 app.name 提供（取代前端硬編碼），與舊 Blade 側邊欄
        // config('app.name') 同源；version 仍保留。
        $this->assertSame('CBDB Online', $props['app']['name']);
        $this->assertArrayHasKey('version', $props['app']);
    }

    public function test_authenticated_share_exposes_roles_and_can(): void {
        $user = User::factory()->create(['is_active' => User::STATUS_ACTIVE, 'is_admin' => User::ROLE_SUPER_ADMIN]);
        $props = $this->shareFor($user);

        $this->assertSame($user->id, $props['auth']['user']['id']);

        foreach (['is_active', 'is_admin', 'is_expert', 'is_super_admin', 'is_crowdsourcing', 'is_regular'] as $k) {
            $this->assertArrayHasKey($k, $props['auth']['user']['roles']);
        }
        foreach (['manage_users', 'restore_operations', 'review_proposals', 'view_audit_logs', 'write_directly', 'run_batch_import'] as $k) {
            $this->assertArrayHasKey($k, $props['auth']['user']['can']);
        }

        // superadmin 的角色旗標
        $this->assertTrue($props['auth']['user']['roles']['is_super_admin']);
        $this->assertTrue($props['auth']['user']['can']['manage_users']);

        // nav 已套用角色閘門：superadmin 應看到 admin 區段
        $topKeys = array_column($props['nav'], 'key');
        $this->assertContains('admin', $topKeys);
    }

    public function test_flash_messages_are_normalized_to_array_shape(): void {
        $user = User::factory()->create(['is_active' => User::STATUS_ACTIVE, 'is_admin' => User::ROLE_REGULAR]);

        // 模擬 laracasts/flash 在 session 留下的訊息
        session(['flash_notification' => collect([
            (object) ['level' => 'success', 'message' => '已儲存', 'title' => null, 'important' => false, 'overlay' => false],
        ])]);

        $props = $this->shareFor($user);

        $this->assertCount(1, $props['flash']);
        $this->assertSame('success', $props['flash'][0]['level']);
        $this->assertSame('已儲存', $props['flash'][0]['message']);
        $this->assertArrayHasKey('important', $props['flash'][0]);
        $this->assertArrayHasKey('overlay', $props['flash'][0]);
    }

    public function test_flash_handles_array_shaped_messages_and_defaults(): void {
        $user = User::factory()->create(['is_active' => User::STATUS_ACTIVE, 'is_admin' => User::ROLE_REGULAR]);

        // 陣列形狀訊息（部分欄位缺省）→ flashMessages() 應套用預設值。
        session(['flash_notification' => collect([
            ['message' => '無 level 的訊息'],
        ])]);

        $props = $this->shareFor($user);

        $this->assertCount(1, $props['flash']);
        $this->assertSame('info', $props['flash'][0]['level']);       // 預設 level
        $this->assertSame('無 level 的訊息', $props['flash'][0]['message']);
        $this->assertNull($props['flash'][0]['title']);                // 預設 title
        $this->assertFalse($props['flash'][0]['important']);           // 預設 important
        $this->assertFalse($props['flash'][0]['overlay']);             // 預設 overlay
    }

    public function test_flash_is_empty_array_when_no_messages(): void {
        $props = $this->shareFor(null);

        $this->assertSame([], $props['flash']);
    }

    public function test_flash_normalizes_non_collection_session_value(): void {
        // session 內若不是 Collection（直接存陣列）→ flashMessages() 先 collect() 再正規化。
        session(['flash_notification' => [
            ['message' => '純陣列訊息', 'level' => 'warning'],
        ]]);

        $props = $this->shareFor(null);

        $this->assertCount(1, $props['flash']);
        $this->assertSame('warning', $props['flash'][0]['level']);
        $this->assertSame('純陣列訊息', $props['flash'][0]['message']);
    }
}
