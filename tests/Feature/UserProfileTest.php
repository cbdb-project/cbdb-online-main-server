<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ── 2026-09-15（Blade 下架環節 4b-3）─────────────────────────────
 * 本檔全部改打 React 端（`/app/profile`）。`appUpdate()` 與 legacy `update()` 是兩個薄殼、
 * 共用同一份驗證與寫入，所以 16 條 PATCH 只是換 URI、斷言一字未改；顯示頁改斷言 props。
 */
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
        $response = $this->get('/app/profile');
        $response->assertRedirect('/login');
    }

    #[Test]
    public function testAuthenticatedUserCanAccessProfile() {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        // 原本 assertSee 頁面標題（Blade 直出中文）＋三個欄位值。React 版標題走翻譯鍵；
        // 三個值在 `profile` prop 裡。
        // 🔴 `assertSee($user->email)` 很弱：登入者自己的 email 出現在 navbar 也會綠。
        // 這裡指名是 `profile` 這個 prop 的欄位。順帶釘住 `update_url`——少了它，
        // 整頁不可儲存，而原本的斷言完全看不出來。
        $this->actingAs($user)
            ->get('/app/profile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Profile/Edit')
                ->where('profile.name', 'Test User')
                ->where('profile.email', 'test@example.com')
                ->where('profile.institution', 'Test Institute')
                ->where('update_url', route('app.profile.update', [], false))
                ->has('avatars'));
    }

    #[Test]
    public function testUserCanUpdateBasicProfile() {
        $user = User::forceCreate([
            'name' => 'Old Name',
            'email' => 'old@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Old Institute',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->patch('/app/profile', [
            'name' => 'New Name',
            'email' => 'new@example.com',
            'institution' => 'New Institute',
            'avatar' => 'avatar0.png',
        ]);

        $response->assertRedirect('/app/profile');
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertEquals('New Name', $user->name);
        $this->assertEquals('new@example.com', $user->email);
        $this->assertEquals('New Institute', $user->institution);
    }

    #[Test]
    public function testUserCanChangePassword() {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('oldpassword'),
            'institution' => 'Test Institute',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->patch('/app/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'institution' => 'Test Institute',
            'avatar' => 'avatar0.png',
            'current_password' => 'oldpassword',
            'new_password' => 'newpassword',
            'new_password_confirmation' => 'newpassword',
        ]);

        $response->assertRedirect('/app/profile');
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertTrue(Hash::check('newpassword', $user->password));
    }

    #[Test]
    public function testPasswordChangeRequiresCurrentPassword() {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('oldpassword'),
            'institution' => 'Test Institute',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->patch('/app/profile', [
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
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('oldpassword'),
            'institution' => 'Test Institute',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->patch('/app/profile', [
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
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('oldpassword'),
            'institution' => 'Test Institute',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->patch('/app/profile', [
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
        $existingUser = User::forceCreate([
            'name' => 'Existing User',
            'email' => 'existing@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'confirmation_token' => 'test-token-1',
            'is_active' => 1,
        ]);

        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'confirmation_token' => 'test-token-2',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->patch('/app/profile', [
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
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $originalPasswordHash = $user->password;

        $response = $this->actingAs($user)->patch('/app/profile', [
            'name' => 'Updated Name',
            'email' => 'test@example.com',
            'institution' => 'Updated Institute',
            'avatar' => 'avatar0.png',
        ]);

        $response->assertRedirect('/app/profile');
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertEquals('Updated Name', $user->name);
        $this->assertEquals('Updated Institute', $user->institution);
        $this->assertEquals($originalPasswordHash, $user->password);
    }

    #[Test]
    public function testNameIsRequired() {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->patch('/app/profile', [
            'name' => '',
            'email' => 'test@example.com',
            'institution' => 'Test Institute',
            'avatar' => 'avatar0.png',
        ]);

        $response->assertSessionHasErrors('name');
    }

    #[Test]
    public function testEmailIsRequired() {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->patch('/app/profile', [
            'name' => 'Test User',
            'email' => '',
            'institution' => 'Test Institute',
            'avatar' => 'avatar0.png',
        ]);

        $response->assertSessionHasErrors('email');
    }

    #[Test]
    public function testInstitutionIsOptional() {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->patch('/app/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'institution' => '',
            'avatar' => 'avatar0.png',
        ]);

        $response->assertRedirect('/app/profile');
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertNull($user->institution);
    }

    #[Test]
    public function testUserCanUpdateAvatar() {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'avatar' => 'avatar0.png',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->patch('/app/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'institution' => 'Test Institute',
            'avatar' => 'avatar10.png',
        ]);

        $response->assertRedirect('/app/profile');
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertEquals('avatar10.png', $user->avatar);
    }

    #[Test]
    public function testAvatarIsRequired() {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'avatar' => 'avatar0.png',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->patch('/app/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'institution' => 'Test Institute',
            // avatar 字段缺失
        ]);

        $response->assertSessionHasErrors('avatar');
    }

    #[Test]
    public function testInvalidAvatarIsRejected() {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'avatar' => 'avatar0.png',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        $response = $this->actingAs($user)->patch('/app/profile', [
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
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'avatar' => 'avatar0.png',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        // 測試 avatar19.png（超出範圍）
        $response = $this->actingAs($user)->patch('/app/profile', [
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
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'institution' => 'Test Institute',
            'avatar' => 'avatar1.png',
            'confirmation_token' => 'test-token',
            'is_active' => 1,
        ]);

        // 測試 CBDB 默認頭像（avatar0.png）
        $response = $this->actingAs($user)->patch('/app/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'institution' => 'Test Institute',
            'avatar' => 'avatar0.png',
        ]);

        $response->assertRedirect('/app/profile');
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertEquals('avatar0.png', $user->avatar, 'Failed to update to avatar0.png');

        // 測試所有 18 個有效頭像
        for ($i = 1; $i <= 18; $i++) {
            $avatarName = "avatar{$i}.png";

            $response = $this->actingAs($user)->patch('/app/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'institution' => 'Test Institute',
                'avatar' => $avatarName,
            ]);

            $response->assertRedirect('/app/profile');
            $response->assertSessionHas('success');

            $user->refresh();
            $this->assertEquals($avatarName, $user->avatar, "Failed to update to {$avatarName}");
        }
    }
}
