<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserIpLoggingTest extends TestCase {
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

    #[Test]
    public function testRegistrationPersistsIpAddresses() {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '123.45.67.89'])
            ->post('/register', [
                'name' => 'Register Tester',
                'email' => 'register@example.com',
                'institution' => 'Test Institute',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
                // 模擬有人在註冊表單夾帶提權欄位，必須被忽略。
                'is_active' => 1,
                'is_admin' => 3,
            ]);

        $response->assertRedirect('/home');
        // 註冊不再留下登入 session（RegisterController::registered 會登出）：新帳號
        // is_active=0，帶著 session 就能觸及 auth-only 端點（例如 POST /api-tokens）。
        $this->assertGuest();

        $user = User::where('email', 'register@example.com')->first();
        $this->assertNotNull($user);

        $settings = $user->settings;
        $this->assertSame('123.45.67.89', $settings['registration_ip'] ?? null);
        $this->assertSame('123.45.67.89', $settings['last_login_ip'] ?? null);
        $this->assertArrayNotHasKey('registration_at', $settings);
        $this->assertArrayNotHasKey('last_login_at', $settings);

        // 註冊絕不可自帶啟用狀態或角色：兩者都不在 User::$fillable，一律落 DB default 0。
        $this->assertSame(User::STATUS_INACTIVE, $user->is_active);
        $this->assertSame(User::ROLE_REGULAR, $user->is_admin);
    }

    #[Test]
    public function testLoginUpdatesLastLoginIp() {
        $user = User::forceCreate([
            'name' => 'Login Tester',
            'email' => 'login@example.com',
            'institution' => 'Test Institute',
            'settings' => [
                'registration_ip' => '10.0.0.1',
                'last_login_ip' => '10.0.0.1',
            ],
            'password' => Hash::make('secret123'),
            'is_active' => 1,
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->post('/login', [
                'email' => 'login@example.com',
                'password' => 'secret123',
            ]);

        $response->assertRedirect('/home');

        $user->refresh();
        $settings = $user->settings;
        $this->assertSame('203.0.113.5', $settings['last_login_ip'] ?? null);
        $this->assertSame('10.0.0.1', $settings['registration_ip'] ?? null);
        $this->assertArrayNotHasKey('last_login_at', $settings);
        $this->assertArrayNotHasKey('registration_at', $settings);
    }

    #[Test]
    public function testLoginDoesNotBackfillRegistrationFields() {
        $user = User::forceCreate([
            'name' => 'No Registration Info',
            'email' => 'noreg@example.com',
            'institution' => null,
            'settings' => [
                // intentionally leave registration_* empty
                'last_login_ip' => '198.51.100.10',
            ],
            'password' => Hash::make('secret123'),
            'is_active' => 1,
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post('/login', [
                'email' => 'noreg@example.com',
                'password' => 'secret123',
            ]);

        $response->assertRedirect('/home');

        $user->refresh();
        $settings = $user->settings;
        $this->assertSame('198.51.100.20', $settings['last_login_ip'] ?? null);
        $this->assertArrayNotHasKey('registration_ip', $settings);
        $this->assertArrayNotHasKey('registration_at', $settings);
        $this->assertArrayNotHasKey('last_login_at', $settings);
    }
}
