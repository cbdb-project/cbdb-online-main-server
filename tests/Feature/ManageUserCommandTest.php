<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManageUserCommandTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('institution')->nullable();
            $table->json('settings')->nullable();
            $table->string('avatar')->default('avatar0.png');
            $table->string('confirmation_token')->nullable();
            $table->smallInteger('is_active')->default(0);
            $table->smallInteger('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    #[Test]
    public function testInteractiveUserCreationSetsStatusAndRole() {
        $this->artisan('cbdb:manage-user')
            ->expectsQuestion('請輸入用戶 Email', 'interactive@example.com')
            ->expectsQuestion('請輸入用戶名稱', 'Interactive User')
            ->expectsQuestion('請輸入密碼（至少 6 個字符）', 'password123')
            ->expectsChoice('請選擇激活狀態', '預留', ['未激活', '激活', '預留'])
            ->expectsChoice('請選擇用戶角色', '專家', ['一般用戶', '專家', '眾包用戶', '系統管理員'])
            ->assertExitCode(0);

        $user = User::where('email', 'interactive@example.com')->first();

        $this->assertNotNull($user);
        $this->assertEquals('Interactive User', $user->name);
        $this->assertEquals(User::STATUS_RESERVED, $user->is_active);
        $this->assertEquals(User::ROLE_EXPERT, $user->is_admin);
        $this->assertTrue(Hash::check('password123', $user->password));
    }

    #[Test]
    public function testUserCreationWithOptionsDoesNotSkipStatusOrRole() {
        $this->artisan('cbdb:manage-user', [
            '--email' => 'options@example.com',
            '--name' => 'Options User',
            '--password' => 'optionpass',
            '--active' => (string)User::STATUS_ACTIVE,
            '--role' => 'super-admin',
        ])->assertExitCode(0);

        $user = User::where('email', 'options@example.com')->first();

        $this->assertNotNull($user);
        $this->assertEquals(User::STATUS_ACTIVE, $user->is_active);
        $this->assertEquals(User::ROLE_SUPER_ADMIN, $user->is_admin);
        $this->assertTrue(Hash::check('optionpass', $user->password));
    }
}
