<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use App\User;

/**
 * 测试 Manage 相关页面能正常加载（不出现 500 错误）
 *
 * 使用 in-memory SQLite 数据库，灌入最小化测试数据
 * 只验证 HTTP 状态码，不检查具体内容
 */
class ManagePagesLoadTest extends TestCase
{
    protected $adminUser;
    protected $regularUser;

    protected function setUp(): void
    {
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
    protected function createMinimalTables()
    {
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
    protected function createTestUsers()
    {
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
    public function test_manage_index_page_loads()
    {
        $response = $this->actingAs($this->adminUser)->get('/manage');
        $response->assertStatus(200);
    }

    /**
     * 测试普通用户访问 /manage 会被重定向
     */
    public function test_manage_index_redirects_non_admin()
    {
        $response = $this->actingAs($this->regularUser)->get('/manage');
        $response->assertRedirect('/home');
    }

    /**
     * 测试未认证用户访问 /manage 会被重定向到登录页
     */
    public function test_manage_index_requires_authentication()
    {
        $response = $this->get('/manage');
        $response->assertRedirect('/login');
    }

    /**
     * 测试编辑页面（改变审核状态）：/manage/{id}/edit?type=1
     */
    public function test_manage_edit_change_active_status()
    {
        $originalStatus = $this->regularUser->is_active;

        $response = $this->actingAs($this->adminUser)
            ->get("/manage/{$this->regularUser->id}/edit?type=1");

        $response->assertRedirect(route('manage.index'));

        // 验证状态已改变
        $this->regularUser->refresh();
        $this->assertEquals(1 - $originalStatus, $this->regularUser->is_active);
    }

    /**
     * 测试编辑页面（改变用户状态）：/manage/{id}/edit?type=2
     */
    public function test_manage_edit_change_user_type()
    {
        $originalType = $this->regularUser->is_admin;

        $response = $this->actingAs($this->adminUser)
            ->get("/manage/{$this->regularUser->id}/edit?type=2");

        $response->assertRedirect(route('manage.index'));

        // 验证用户类型已改变（0 -> 1）
        $this->regularUser->refresh();
        $this->assertNotEquals($originalType, $this->regularUser->is_admin);
    }

    /**
     * 测试编辑页面（删除用户）：/manage/{id}/edit?type=3
     */
    public function test_manage_edit_delete_user()
    {
        $response = $this->actingAs($this->adminUser)
            ->get("/manage/{$this->regularUser->id}/edit?type=3");

        $response->assertRedirect(route('manage.index'));

        // 验证用户已被标记为删除
        $this->regularUser->refresh();
        $this->assertEquals('-', $this->regularUser->password);
        $this->assertEquals('-', $this->regularUser->confirmation_token);
        $this->assertEquals('-', $this->regularUser->remember_token);
    }

    /**
     * 测试非管理员无法执行编辑操作
     */
    public function test_manage_edit_requires_admin()
    {
        $response = $this->actingAs($this->regularUser)
            ->get("/manage/{$this->adminUser->id}/edit?type=1");

        $response->assertRedirect();
    }

    /**
     * 测试路由参数正确性（验证修复后的路由参数）
     */
    public function test_manage_edit_route_with_correct_parameters()
    {
        // 测试路由能正确生成 URL
        $url = route('manage.edit', ['manage' => $this->regularUser->id, 'type' => '1']);
        $this->assertStringContainsString("/manage/{$this->regularUser->id}/edit", $url);
        $this->assertStringContainsString("type=1", $url);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }
}
