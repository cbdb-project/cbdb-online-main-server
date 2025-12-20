<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
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
            $table->string('avatar')->default('avatar5.png');
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

    public function testGuestCannotAccessProfile() {
        $response = $this->get('/profile');
        $response->assertRedirect('/login');
    }

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
        ]);

        $response->assertRedirect('/profile');
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertEquals('New Name', $user->name);
        $this->assertEquals('new@example.com', $user->email);
        $this->assertEquals('New Institute', $user->institution);
    }

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
            'current_password' => 'oldpassword',
            'new_password' => 'newpassword',
            'new_password_confirmation' => 'newpassword',
        ]);

        $response->assertRedirect('/profile');
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertTrue(Hash::check('newpassword', $user->password));
    }

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
            'new_password' => 'newpassword',
            'new_password_confirmation' => 'newpassword',
        ]);

        $response->assertSessionHasErrors('current_password');
    }

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
            'current_password' => 'wrongpassword',
            'new_password' => 'newpassword',
            'new_password_confirmation' => 'newpassword',
        ]);

        $response->assertSessionHasErrors('current_password');

        $user->refresh();
        $this->assertTrue(Hash::check('oldpassword', $user->password));
    }

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
            'current_password' => 'oldpassword',
            'new_password' => 'newpassword',
            'new_password_confirmation' => 'differentpassword',
        ]);

        $response->assertSessionHasErrors('new_password');

        $user->refresh();
        $this->assertTrue(Hash::check('oldpassword', $user->password));
    }

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
        ]);

        $response->assertSessionHasErrors('email');

        $user->refresh();
        $this->assertEquals('test@example.com', $user->email);
    }

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
        ]);

        $response->assertRedirect('/profile');
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertEquals('Updated Name', $user->name);
        $this->assertEquals('Updated Institute', $user->institution);
        $this->assertEquals($originalPasswordHash, $user->password);
    }

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
        ]);

        $response->assertSessionHasErrors('name');
    }

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
        ]);

        $response->assertSessionHasErrors('email');
    }

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
        ]);

        $response->assertRedirect('/profile');
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertNull($user->institution);
    }
}
