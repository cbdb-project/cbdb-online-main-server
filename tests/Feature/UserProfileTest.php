<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserProfileTest extends TestCase {
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
    public function testGuestCannotAccessProfile() {
        $response = $this->get('/profile');
        $response->assertRedirect('/login');
    }

    #[Test]
    public function testAuthenticatedUserCanAccessProfile() {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->get('/profile');

        $response->assertStatus(200);
        $response->assertSee('個人資料設定');
        $response->assertSee('Test User');
        $response->assertSee('test@example.com');
        $response->assertSee('Test Institute');
    }

    #[Test]
    public function testUserCanUpdateBasicProfile() {
        $user = User::create([
            'name' => 'Old Name',
            'email' => 'old@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Old Institute',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->patch('/profile', [
            'name' => 'New Name',
            'email' => 'new@example.com',
            'institution' => 'New Institute',
            'avatar' => 'avatar0.png',
        ]);

        $response->assertRedirect('/profile');
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertEquals('New Name', $user->name);
        $this->assertEquals('new@example.com', $user->email);
        $this->assertEquals('New Institute', $user->institution);
    }

    #[Test]
    public function testUserCanChangePassword() {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('oldpassword'),
            'institution' => 'Test Institute',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'institution' => 'Test Institute',
            'avatar' => 'avatar0.png',
            'current_password' => 'oldpassword',
            'new_password' => 'newpassword',
            'new_password_confirmation' => 'newpassword',
        ]);

        $response->assertRedirect('/profile');
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertTrue(Hash::check('newpassword', $user->password));
    }

    #[Test]
    public function testPasswordChangeRequiresCurrentPassword() {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('oldpassword'),
            'institution' => 'Test Institute',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'institution' => 'Test Institute',
            'avatar' => 'avatar0.png',
            'new_password' => 'newpassword',
            'new_password_confirmation' => 'newpassword',
        ]);

        $response->assertSessionHasErrors('current_password');
    }

    #[Test]
    public function testPasswordChangeMustVerifyCurrentPassword() {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('oldpassword'),
            'institution' => 'Test Institute',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'institution' => 'Test Institute',
            'avatar' => 'avatar0.png',
            'current_password' => 'wrongpassword',
            'new_password' => 'newpassword',
            'new_password_confirmation' => 'newpassword',
        ]);

        $response->assertSessionHasErrors('current_password');

        $user->refresh();
        $this->assertTrue(Hash::check('oldpassword', $user->password));
    }

    #[Test]
    public function testPasswordChangeMustBeConfirmed() {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('oldpassword'),
            'institution' => 'Test Institute',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'institution' => 'Test Institute',
            'avatar' => 'avatar0.png',
            'current_password' => 'oldpassword',
            'new_password' => 'newpassword',
            'new_password_confirmation' => 'differentpassword',
        ]);

        $response->assertSessionHasErrors('new_password');

        $user->refresh();
        $this->assertTrue(Hash::check('oldpassword', $user->password));
    }

    #[Test]
    public function testEmailMustBeUnique() {
        $existingUser = User::create([
            'name' => 'Existing User',
            'email' => 'existing@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'confirmation_token' => 'test-token-1',
            'is_active' => 1,
        ]);

        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'confirmation_token' => 'test-token-2',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->patch('/profile', [
            'name' => 'Test User',
            'email' => 'existing@example.com',
            'institution' => 'Test Institute',
            'avatar' => 'avatar0.png',
        ]);

        $response->assertSessionHasErrors('email');

        $user->refresh();
        $this->assertEquals('test@example.com', $user->email);
    }

    #[Test]
    public function testUserCanUpdateProfileWithoutChangingPassword() {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $originalPasswordHash = $user->password;

        $response = $this->actingAs($user)->patch('/profile', [
            'name' => 'Updated Name',
            'email' => 'test@example.com',
            'institution' => 'Updated Institute',
            'avatar' => 'avatar0.png',
        ]);

        $response->assertRedirect('/profile');
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertEquals('Updated Name', $user->name);
        $this->assertEquals('Updated Institute', $user->institution);
        $this->assertEquals($originalPasswordHash, $user->password);
    }

    #[Test]
    public function testNameIsRequired() {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->patch('/profile', [
            'name' => '',
            'email' => 'test@example.com',
            'institution' => 'Test Institute',
            'avatar' => 'avatar0.png',
        ]);

        $response->assertSessionHasErrors('name');
    }

    #[Test]
    public function testEmailIsRequired() {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->patch('/profile', [
            'name' => 'Test User',
            'email' => '',
            'institution' => 'Test Institute',
            'avatar' => 'avatar0.png',
        ]);

        $response->assertSessionHasErrors('email');
    }

    #[Test]
    public function testInstitutionIsOptional() {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'institution' => '',
            'avatar' => 'avatar0.png',
        ]);

        $response->assertRedirect('/profile');
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertNull($user->institution);
    }

    #[Test]
    public function testUserCanUpdateAvatar() {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'avatar' => 'avatar0.png',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'institution' => 'Test Institute',
            'avatar' => 'avatar10.png',
        ]);

        $response->assertRedirect('/profile');
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertEquals('avatar10.png', $user->avatar);
    }

    #[Test]
    public function testAvatarIsRequired() {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'avatar' => 'avatar0.png',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'institution' => 'Test Institute',
            // avatar 字段缺失
        ]);

        $response->assertSessionHasErrors('avatar');
    }

    #[Test]
    public function testInvalidAvatarIsRejected() {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'avatar' => 'avatar0.png',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'institution' => 'Test Institute',
            'avatar' => 'invalid-avatar.png',
        ]);

        $response->assertSessionHasErrors('avatar');

        $user->refresh();
        $this->assertEquals('avatar0.png', $user->avatar);
    }

    #[Test]
    public function testAvatarMustBeInValidRange() {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'avatar' => 'avatar0.png',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        // 測試 avatar19.png（超出範圍）
        $response = $this->actingAs($user)->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'institution' => 'Test Institute',
            'avatar' => 'avatar19.png',
        ]);

        $response->assertSessionHasErrors('avatar');

        $user->refresh();
        $this->assertEquals('avatar0.png', $user->avatar);
    }

    #[Test]
    public function testAllValidAvatarsAreAccepted() {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'avatar' => 'avatar1.png',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        // 測試 CBDB 默認頭像（avatar0.png）
        $response = $this->actingAs($user)->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'institution' => 'Test Institute',
            'avatar' => 'avatar0.png',
        ]);

        $response->assertRedirect('/profile');
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertEquals('avatar0.png', $user->avatar, 'Failed to update to avatar0.png');

        // 測試所有 18 個有效頭像
        for ($i = 1; $i <= 18; $i++) {
            $avatarName = "avatar{$i}.png";

            $response = $this->actingAs($user)->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'institution' => 'Test Institute',
                'avatar' => $avatarName,
            ]);

            $response->assertRedirect('/profile');
            $response->assertSessionHas('success');

            $user->refresh();
            $this->assertEquals($avatarName, $user->avatar, "Failed to update to {$avatarName}");
        }
    }
}
