<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserFactoryTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        // 配置 SQLite 内存数据库
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // 配置缓存和会话为数组驱动
        config(['cache.default' => 'array']);
        config(['session.driver' => 'array']);

        // 创建 users 表
        $this->createTestTables();
    }

    protected function createTestTables() {
        Schema::dropIfExists('users');

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('institution')->nullable();
            $table->text('settings')->nullable();
            $table->string('avatar')->nullable();
            $table->string('confirmation_token')->nullable();
            $table->rememberToken();
            $table->tinyInteger('is_active')->default(0);
            $table->tinyInteger('is_admin')->default(0);
            $table->timestamps();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    #[Test]
    public function it_can_create_user_with_new_factory_syntax() {
        $user = User::factory()->create();

        $this->assertInstanceOf(User::class, $user);
        $this->assertNotNull($user->id);
        $this->assertNotNull($user->name);
        $this->assertNotNull($user->email);
        $this->assertNotNull($user->password);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => $user->email,
        ]);
    }

    #[Test]
    public function it_can_create_user_with_custom_attributes() {
        $user = User::factory()->create([
            'name' => '测试用户',
            'email' => 'test@example.com',
        ]);

        $this->assertEquals('测试用户', $user->name);
        $this->assertEquals('test@example.com', $user->email);
    }

    #[Test]
    public function it_can_create_multiple_users() {
        $users = User::factory()->count(3)->create();

        $this->assertCount(3, $users);
        $this->assertEquals(3, User::count());
    }

    #[Test]
    public function it_can_create_active_user() {
        $user = User::factory()->active()->create();

        $this->assertEquals(User::STATUS_ACTIVE, $user->is_active);
        $this->assertTrue($user->isActive());
    }

    #[Test]
    public function it_can_create_inactive_user() {
        $user = User::factory()->inactive()->create();

        $this->assertEquals(User::STATUS_INACTIVE, $user->is_active);
        $this->assertFalse($user->isActive());
    }

    #[Test]
    public function it_can_create_super_admin_user() {
        $user = User::factory()->superAdmin()->create();

        $this->assertEquals(User::ROLE_SUPER_ADMIN, $user->is_admin);
        $this->assertTrue($user->isSuperAdmin());
        $this->assertTrue($user->isAdmin());
    }

    #[Test]
    public function it_can_create_expert_user() {
        $user = User::factory()->expert()->create();

        $this->assertEquals(User::ROLE_EXPERT, $user->is_admin);
        $this->assertTrue($user->isExpert());
        $this->assertTrue($user->isAdmin());
    }

    #[Test]
    public function it_can_create_crowdsourcing_user() {
        $user = User::factory()->crowdsourcing()->create();

        $this->assertEquals(User::ROLE_CROWDSOURCING, $user->is_admin);
        $this->assertTrue($user->isCrowdsourcingUser());
        $this->assertFalse($user->isAdmin());
    }

    #[Test]
    public function it_can_create_regular_user() {
        $user = User::factory()->regular()->create();

        $this->assertEquals(User::ROLE_REGULAR, $user->is_admin);
        $this->assertTrue($user->isRegularUser());
        $this->assertFalse($user->isAdmin());
    }

    #[Test]
    public function it_sets_default_confirmation_token() {
        $user = User::factory()->create();

        $this->assertNotNull($user->confirmation_token);
        $this->assertEquals(32, strlen($user->confirmation_token));
    }

    #[Test]
    public function it_can_make_user_without_persisting() {
        $user = User::factory()->make();

        $this->assertInstanceOf(User::class, $user);
        $this->assertNull($user->id);
        $this->assertEquals(0, User::count());
    }

    #[Test]
    public function it_can_combine_multiple_states() {
        $user = User::factory()->active()->superAdmin()->create();

        $this->assertEquals(User::STATUS_ACTIVE, $user->is_active);
        $this->assertEquals(User::ROLE_SUPER_ADMIN, $user->is_admin);
        $this->assertTrue($user->isActive());
        $this->assertTrue($user->isSuperAdmin());
    }

    #[Test]
    public function old_factory_syntax_still_works() {
        // 验证旧的 factory() 语法仍然可用
        $user = factory(User::class)->create();

        $this->assertInstanceOf(User::class, $user);
        $this->assertNotNull($user->id);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
        ]);
    }
}
