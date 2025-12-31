<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 测试 Manage 相关页面能正常加载（不出现 500 错误）
 *
 * 使用 in-memory SQLite 数据库，灌入最小化测试数据
 * 只验证 HTTP 状态码，不检查具体内容
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

        // 设置缓存和 session 为数组驱动
        config(['cache.default' => 'array']);
        config(['session.driver' => 'array']);

        // 创建测试所需的最小化表结构
        $this->createMinimalTables();

        // 创建测试用户
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
    }

    /**
     * 创建测试用户
     */
    protected function createTestUsers() {
        // 创建管理员用户（用于认证和访问管理页面）
        $this->adminUser = factory(User::class)->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'is_active' => 1,
            'is_admin' => 1,  // 管理员权限
            'confirmation_token' => 'admin_token_' . time(),
            'remember_token' => 'admin_remember_' . time(),
        ]);

        // 创建普通用户（用于在列表中显示）
        $this->regularUser = factory(User::class)->create([
            'name' => 'Regular User',
            'email' => 'regular@example.com',
            'institution' => 'Test Institution',
            'is_active' => 1,
            'is_admin' => 0,  // 普通用户
            'confirmation_token' => 'regular_token_' . time(),
            'remember_token' => 'regular_remember_' . time(),
        ]);

        // 创建一个专家用户
        factory(User::class)->create([
            'name' => 'Expert User',
            'email' => 'expert@example.com',
            'is_active' => 1,
            'is_admin' => 1,  // 专家
            'confirmation_token' => 'expert_token_' . time(),
            'remember_token' => 'expert_remember_' . time(),
        ]);

        // 创建一个众包用户
        factory(User::class)->create([
            'name' => 'Crowdsource User',
            'email' => 'crowd@example.com',
            'is_active' => 0,
            'is_admin' => 2,  // 众包
            'confirmation_token' => 'crowd_token_' . time(),
            'remember_token' => 'crowd_remember_' . time(),
        ]);

        // 创建一个被删除的用户（不应该在列表中显示）
        factory(User::class)->create([
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
        $response = $this->actingAs($this->adminUser)->get('/manage');
        $response->assertStatus(200);
    }

    /**
     * 测试普通用户访问 /manage 会被重定向
     */
    #[Test]
    public function test_manage_index_redirects_non_admin() {
        $response = $this->actingAs($this->regularUser)->get('/manage');
        $response->assertRedirect('/home');
    }

    /**
     * 测试未认证用户访问 /manage 会被重定向到登录页
     */
    #[Test]
    public function test_manage_index_requires_authentication() {
        $response = $this->get('/manage');
        $response->assertRedirect('/login');
    }

    /**
     * 测试编辑页面加载：/manage/{id}/edit
     */
    #[Test]
    public function test_manage_edit_page_loads() {
        $response = $this->actingAs($this->adminUser)
            ->get("/manage/{$this->regularUser->id}/edit");

        $response->assertStatus(200);
        $response->assertSee('编辑用户设置');
        $response->assertSee($this->regularUser->name);
        $response->assertSee($this->regularUser->email);
    }

    /**
     * 测试更新用户激活状态：PUT /manage/{id}
     */
    #[Test]
    public function test_manage_update_active_status() {
        $response = $this->actingAs($this->adminUser)
            ->put("/manage/{$this->regularUser->id}", [
                'is_active' => 0,
                'is_admin' => $this->regularUser->is_admin,
            ]);

        $response->assertRedirect(route('manage.index'));

        // 验证状态已改变
        $this->regularUser->refresh();
        $this->assertEquals(0, $this->regularUser->is_active);
    }

    /**
     * 测试更新用户角色：PUT /manage/{id}
     */
    #[Test]
    public function test_manage_update_user_role() {
        $response = $this->actingAs($this->adminUser)
            ->put("/manage/{$this->regularUser->id}", [
                'is_active' => $this->regularUser->is_active,
                'is_admin' => 1, // 改为专家
            ]);

        $response->assertRedirect(route('manage.index'));

        // 验证用户类型已改变
        $this->regularUser->refresh();
        $this->assertEquals(1, $this->regularUser->is_admin);
    }

    /**
     * 测试删除用户：PUT /manage/{id} with delete_user
     */
    #[Test]
    public function test_manage_delete_user() {
        $response = $this->actingAs($this->adminUser)
            ->put("/manage/{$this->regularUser->id}", [
                'is_active' => $this->regularUser->is_active,
                'is_admin' => $this->regularUser->is_admin,
                'delete_user' => 1,
            ]);

        $response->assertRedirect(route('manage.index'));

        // 验证用户已被标记为删除
        $this->regularUser->refresh();
        $this->assertEquals('-', $this->regularUser->password);
        $this->assertEquals('-', $this->regularUser->confirmation_token);
        $this->assertEquals('-', $this->regularUser->remember_token);
    }

    /**
     * 测试非管理员无法访问编辑页面
     */
    #[Test]
    public function test_manage_edit_requires_admin() {
        $response = $this->actingAs($this->regularUser)
            ->get("/manage/{$this->adminUser->id}/edit");

        $response->assertRedirect();
    }

    /**
     * 测试非管理员无法执行更新操作
     */
    #[Test]
    public function test_manage_update_requires_admin() {
        $response = $this->actingAs($this->regularUser)
            ->put("/manage/{$this->adminUser->id}", [
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
        $url = route('manage.edit', $this->regularUser->id);
        $this->assertStringContainsString("/manage/{$this->regularUser->id}/edit", $url);
    }

    /**
     * 测试更新不存在的用户
     */
    #[Test]
    public function test_manage_update_nonexistent_user() {
        $response = $this->actingAs($this->adminUser)
            ->put("/manage/99999", [
                'is_active' => 1,
                'is_admin' => 0,
            ]);

        $response->assertRedirect(route('manage.index'));
    }

    /**
     * 测试验证规则
     */
    #[Test]
    public function test_manage_update_validation() {
        // 测试无效的 is_active 值
        $response = $this->actingAs($this->adminUser)
            ->put("/manage/{$this->regularUser->id}", [
                'is_active' => 'invalid',
                'is_admin' => 0,
            ]);

        $response->assertSessionHasErrors('is_active');

        // 测试无效的 is_admin 值
        $response = $this->actingAs($this->adminUser)
            ->put("/manage/{$this->regularUser->id}", [
                'is_active' => 1,
                'is_admin' => 99,
            ]);

        $response->assertSessionHasErrors('is_admin');
    }

    protected function tearDown(): void {
        parent::tearDown();
    }
}
