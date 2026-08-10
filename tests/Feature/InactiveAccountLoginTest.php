<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 未啟用（is_active != 1）帳號不得取得登入 session（見 docs/USER_PRIVILEGES.md 三之二）。
 */
class InactiveAccountLoginTest extends TestCase {
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
            $table->string('avatar')->nullable();
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

    private function makeUser(int $active): User {
        return User::forceCreate([
            'name' => 'Tester',
            'email' => 'tester@example.com',
            'password' => Hash::make('secret123'),
            'confirmation_token' => 'tok',
            'is_active' => $active,
            'is_admin' => User::ROLE_REGULAR,
        ]);
    }

    #[Test]
    public function inactive_user_cannot_log_in(): void {
        $this->makeUser(User::STATUS_INACTIVE);

        $response = $this->from('/login')->post('/login', [
            'email' => 'tester@example.com',
            'password' => 'secret123',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[Test]
    public function active_user_can_log_in(): void {
        $this->makeUser(User::STATUS_ACTIVE);

        $response = $this->post('/login', [
            'email' => 'tester@example.com',
            'password' => 'secret123',
        ]);

        $response->assertRedirect('/home');
        $this->assertAuthenticated();
    }
}
