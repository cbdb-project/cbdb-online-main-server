<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 测试 Manage 相关页面能正常加载（不出现 500 错误）
 *
 * 使用 in-memory SQLite 数据库，灌入最小化测试数据
 * 只验证 HTTP 状态码，不检查具体内容
 */
/**
 * ── 2026-09-15（Blade 下架環節 4b-3）─────────────────────────────
 * 本檔全部改打 React 端（`/app/manage`）。寫入端（`appUpdate`）與 legacy `update()` 是
 * 兩個薄殼、共用同一份實作，差別只在成功後的重導目標；顯示頁改斷言 Inertia props。
 */
class ManagePagesLoadTest extends TestCase {
    protected $adminUser;
    protected $regularUser;

    protected function setUp(): void {
        parent::setUp();

        // 使用 in-memory SQLite 数据库
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // 設定缓存和 session 为数组驱动
        config(['cache.default' => 'array']);
        config(['session.driver' => 'array']);

        // 创建测试所需的最小化表结构
        $this->createMinimalTables();

        // 创建测试用戶
        $this->createTestUsers();
    }

    /**
     * 创建最小化表结构
     */
    protected function createMinimalTables() {
        // 创建 users 表
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('institution')->nullable();
            $table->json('settings')->nullable();
            $table->string('avatar')->nullable();
            $table->string('confirmation_token')->nullable();
            $table->smallInteger('is_active')->default(0);
            $table->smallInteger('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        // 停用／軟刪除帳號會連帶撤銷 Sanctum token（AccountAccessRevoker），欄位對齊
        // database/migrations/2025_12_17_000001_create_personal_access_tokens_table.php。
        Schema::dropIfExists('personal_access_tokens');
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * 创建测试用戶
     */
    protected function createTestUsers() {
        // 创建管理员用戶（用于认证和访问管理页面）
        $this->adminUser = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'is_active' => 1,
            'is_admin' => User::ROLE_SUPER_ADMIN,  // 系統管理員（變更他人角色需超級管理員）
            'confirmation_token' => 'admin_token_' . time(),
            'remember_token' => 'admin_remember_' . time(),
        ]);

        // 创建普通用戶（用于在列表中显示）
        $this->regularUser = User::factory()->create([
            'name' => 'Regular User',
            'email' => 'regular@example.com',
            'institution' => 'Test Institution',
            'is_active' => 1,
            'is_admin' => 0,  // 普通用戶
            'confirmation_token' => 'regular_token_' . time(),
            'remember_token' => 'regular_remember_' . time(),
        ]);

        // 创建一个专家用戶
        User::factory()->create([
            'name' => 'Expert User',
            'email' => 'expert@example.com',
            'is_active' => 1,
            'is_admin' => 1,  // 专家
            'confirmation_token' => 'expert_token_' . time(),
            'remember_token' => 'expert_remember_' . time(),
        ]);

        // 创建一个眾包用戶
        User::factory()->create([
            'name' => 'Crowdsource User',
            'email' => 'crowd@example.com',
            'is_active' => 0,
            'is_admin' => 2,  // 眾包
            'confirmation_token' => 'crowd_token_' . time(),
            'remember_token' => 'crowd_remember_' . time(),
        ]);

        // 创建一个被删除的用戶（不应该在列表中显示）
        User::factory()->create([
            'name' => 'Deleted User',
            'email' => 'deleted@example.com-2024-01-01',
            'is_active' => 0,
            'is_admin' => 0,
            'password' => '-',
            'confirmation_token' => '-',
            'remember_token' => '-',
        ]);
    }

    /**
     * 测试主页面：/manage（需要管理员权限）
     */
    #[Test]
    public function test_manage_index_page_loads() {
        $rows = [];

        $this->actingAs($this->adminUser)
            ->get('/app/manage')
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$rows) {
                $page->component('Admin/Manage/Index');
                $rows = array_map(fn ($r) => (array) $r, $page->toArray()['props']['data']['rows']);
            });

        // 原本只斷言 200——空清單也會 200。順帶把 setUp 那句註解
        //「被刪除的用戶不应该在列表中显示」真的釘成斷言：fixture 建了 5 個人，
        // 其中 deleted@example.com-2024-01-01 是已刪除的，列表應該只有 4 個。
        $this->assertCount(4, $rows);
        $this->assertNotContains(
            'deleted@example.com-2024-01-01',
            array_column($rows, 'email'),
            '已刪除的用戶不得出現在管理列表'
        );
    }

    /**
     * ── 2026-09-15（Blade 下架環節 4b-3，review 指出）─────────────────
     *
     * 上一條的 fixture 那個「已刪除用戶」**三個謂詞全中**（`confirmation_token`、
     * `remember_token`、`password` 都是 `-`），所以只要 `buildUserListing()` 的三個
     * `where` 還活著任何一個，它就被擋掉 ⇒ **那條測試只偵測得到「三個一起掉」**。
     * review 實測：只留 `remember_token` 那個 closure、把另外兩個拿掉 ⇒ 上一條依然全綠。
     *
     * 這一條逐謂詞各建一個使用者（每人只觸發一個條件），任何**單一**謂詞被拿掉都會紅。
     */
    #[Test]
    public function test_manage_index_soft_delete_filter_covers_every_predicate() {
        // 每人只踩一個 `-`，其餘欄位都是正常值。
        User::factory()->create([
            'name' => 'Token Deleted', 'email' => 'token-deleted@example.com',
            'is_active' => 1, 'is_admin' => 0,
            'confirmation_token' => '-', 'remember_token' => 'ok', 'password' => 'ok',
        ]);
        User::factory()->create([
            'name' => 'Remember Deleted', 'email' => 'remember-deleted@example.com',
            'is_active' => 1, 'is_admin' => 0,
            'confirmation_token' => 'ok', 'remember_token' => '-', 'password' => 'ok',
        ]);
        User::factory()->create([
            'name' => 'Password Deleted', 'email' => 'password-deleted@example.com',
            'is_active' => 1, 'is_admin' => 0,
            'confirmation_token' => 'ok', 'remember_token' => 'ok', 'password' => '-',
        ]);

        $rows = [];

        $this->actingAs($this->adminUser)
            ->get('/app/manage')
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$rows) {
                $page->component('Admin/Manage/Index');
                $rows = array_map(fn ($r) => (array) $r, $page->toArray()['props']['data']['rows']);
            });

        $emails = array_column($rows, 'email');
        foreach (['token-deleted', 'remember-deleted', 'password-deleted'] as $who) {
            $this->assertNotContains(
                $who.'@example.com',
                $emails,
                $who.' 只踩一個軟刪除謂詞，對應的 where 被拿掉就會漏出來'
            );
        }

        // 正向對照：正常使用者仍在（否則「整張表都撈不到」也會讓上面全過）。
        $this->assertContains($this->regularUser->email, $emails);
    }

    /**
     * 测试普通用戶访问 /manage 会被重定向
     */
    #[Test]
    public function test_manage_index_redirects_non_admin() {
        $response = $this->actingAs($this->regularUser)->get('/app/manage');
        $response->assertRedirect('/home');
    }

    /**
     * 测试未认证用戶访问 /manage 会被重定向到登录页
     */
    #[Test]
    public function test_manage_index_requires_authentication() {
        $response = $this->get('/app/manage');
        $response->assertRedirect('/login');
    }

    /**
     * 测试編輯页面加载：/manage/{id}/edit
     */
    #[Test]
    public function test_manage_edit_page_loads() {
        // 原本 assertSee 頁面標題（Blade 直出中文）＋使用者的姓名與 email。
        // React 版標題走翻譯鍵；姓名與 email 在 `user` prop 裡。
        // 🔴 `assertSee($user->email)` 其實很弱：頁面上任何地方出現那串字都會綠
        //（例如登入者自己的 email 出現在 navbar）。這裡指名是 `user` 這個 prop 的欄位——
        // 「編輯頁載入的是**正確那個人**」才是這條測試的主體。
        $this->actingAs($this->adminUser)
            ->get("/app/manage/{$this->regularUser->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Manage/Edit')
                ->where('user.id', $this->regularUser->id)
                ->where('user.name', $this->regularUser->name)
                ->where('user.email', $this->regularUser->email));
    }

    /**
     * 测试更新用戶激活状态：PUT /manage/{id}
     */
    #[Test]
    public function test_manage_update_active_status() {
        $response = $this->actingAs($this->adminUser)
            ->put("/app/manage/{$this->regularUser->id}", [
                'is_active' => 0,
                'is_admin' => $this->regularUser->is_admin,
            ]);

        $response->assertRedirect(route('app.manage.index'));

        // 验证状态已改变
        $this->regularUser->refresh();
        $this->assertEquals(0, $this->regularUser->is_active);
    }

    /**
     * 测试更新用戶角色：PUT /manage/{id}
     */
    #[Test]
    public function test_manage_update_user_role() {
        $response = $this->actingAs($this->adminUser)
            ->put("/app/manage/{$this->regularUser->id}", [
                'is_active' => $this->regularUser->is_active,
                'is_admin' => 1, // 改为专家
            ]);

        $response->assertRedirect(route('app.manage.index'));

        // 验证用戶类型已改变
        $this->regularUser->refresh();
        $this->assertEquals(1, $this->regularUser->is_admin);
    }

    /**
     * 测试删除用戶：PUT /manage/{id} with delete_user
     */
    #[Test]
    public function test_manage_delete_user() {
        $response = $this->actingAs($this->adminUser)
            ->put("/app/manage/{$this->regularUser->id}", [
                'is_active' => $this->regularUser->is_active,
                'is_admin' => $this->regularUser->is_admin,
                'delete_user' => 1,
            ]);

        $response->assertRedirect(route('app.manage.index'));

        // 验证用戶已被标记为删除
        $this->regularUser->refresh();
        $this->assertEquals('-', $this->regularUser->password);
        $this->assertEquals('-', $this->regularUser->confirmation_token);
        $this->assertEquals('-', $this->regularUser->remember_token);
    }

    /**
     * 测试非管理员无法访问編輯页面
     */
    #[Test]
    public function test_manage_edit_requires_admin() {
        $response = $this->actingAs($this->regularUser)
            ->get("/app/manage/{$this->adminUser->id}/edit");

        $response->assertRedirect();
    }

    /**
     * 测试非管理员无法执行更新操作
     */
    #[Test]
    public function test_manage_update_requires_admin() {
        $response = $this->actingAs($this->regularUser)
            ->put("/app/manage/{$this->adminUser->id}", [
                'is_active' => 0,
                'is_admin' => 0,
            ]);

        $response->assertRedirect();
    }

    /**
     * 测试路由参数正确性
     */
    #[Test]
    public function test_manage_edit_route_with_correct_parameters() {
        // 测试路由能正确生成 URL
        $url = route('app.manage.edit', $this->regularUser->id);
        $this->assertStringContainsString("/app/manage/{$this->regularUser->id}/edit", $url);
    }

    /**
     * 测试更新不存在的用戶
     */
    #[Test]
    public function test_manage_update_nonexistent_user() {
        $response = $this->actingAs($this->adminUser)
            ->put("/app/manage/99999", [
                'is_active' => 1,
                'is_admin' => 0,
            ]);

        $response->assertRedirect(route('app.manage.index'));
    }

    /**
     * 测试验证规则
     */
    #[Test]
    public function test_manage_update_validation() {
        // 测试无效的 is_active 值
        $response = $this->actingAs($this->adminUser)
            ->put("/app/manage/{$this->regularUser->id}", [
                'is_active' => 'invalid',
                'is_admin' => 0,
            ]);

        $response->assertSessionHasErrors('is_active');

        // 测试无效的 is_admin 值
        $response = $this->actingAs($this->adminUser)
            ->put("/app/manage/{$this->regularUser->id}", [
                'is_active' => 1,
                'is_admin' => 99,
            ]);

        $response->assertSessionHasErrors('is_admin');
    }

    protected function tearDown(): void {
        parent::tearDown();
    }
}
