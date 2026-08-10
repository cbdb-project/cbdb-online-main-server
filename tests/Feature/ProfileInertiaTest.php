<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P1-5 profile/edit Inertia 變體（app.profile.edit / app.profile.update）測試。
 */
class ProfileInertiaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-profile-inertia';
        if (!is_dir($compiledViewPath)) {
            mkdir($compiledViewPath, 0777, true);
        }
        config(['view.compiled' => $compiledViewPath]);

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('institution')->nullable();
            $table->string('avatar')->default('avatar0.png');
            $table->string('confirmation_token')->nullable();
            $table->tinyInteger('is_active')->default(0);
            $table->tinyInteger('is_admin')->default(0);
            $table->timestamps();
        });
    }

    private function makeUser(array $overrides = []): User {
        return User::forceCreate(array_merge([
            'name' => 'Alice', 'email' => 'a@example.com', 'password' => bcrypt('secret123'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_REGULAR,
            'avatar' => 'avatar0.png',
        ], $overrides));
    }

    #[Test]
    public function edit_renders_component_with_profile(): void {
        $user = $this->makeUser(['institution' => 'Harvard']);

        $this->actingAs($user)->get(route('app.profile.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Profile/Edit')
                ->where('profile.name', 'Alice')
                ->where('profile.email', 'a@example.com')
                ->where('profile.institution', 'Harvard')
                ->has('avatars')
                ->has('update_url'));
    }

    #[Test]
    public function guest_is_redirected(): void {
        $this->get(route('app.profile.edit'))->assertRedirect();
    }

    #[Test]
    public function update_saves_basic_info_and_flashes_success(): void {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->from(route('app.profile.edit'))
            ->patch(route('app.profile.update'), [
                'name' => 'Alice Updated',
                'email' => 'a@example.com',
                'institution' => 'MIT',
                'avatar' => 'avatar3.png',
            ])
            ->assertRedirect(route('app.profile.edit'))
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('Alice Updated', $user->name);
        $this->assertSame('MIT', $user->institution);
        $this->assertSame('avatar3.png', $user->avatar);
    }

    #[Test]
    public function update_validates_required_and_avatar_allowlist(): void {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->from(route('app.profile.edit'))
            ->patch(route('app.profile.update'), ['name' => '', 'email' => 'bad', 'avatar' => 'evil.png'])
            ->assertSessionHasErrors(['name', 'email', 'avatar']);
    }

    #[Test]
    public function update_rejects_wrong_current_password(): void {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->from(route('app.profile.edit'))
            ->patch(route('app.profile.update'), [
                'name' => 'Alice', 'email' => 'a@example.com', 'avatar' => 'avatar0.png',
                'current_password' => 'wrongpass', 'new_password' => 'newsecret', 'new_password_confirmation' => 'newsecret',
            ])
            ->assertSessionHasErrors('current_password');
    }

    #[Test]
    public function update_changes_password_with_correct_current(): void {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->from(route('app.profile.edit'))
            ->patch(route('app.profile.update'), [
                'name' => 'Alice', 'email' => 'a@example.com', 'avatar' => 'avatar0.png',
                'current_password' => 'secret123', 'new_password' => 'newsecret', 'new_password_confirmation' => 'newsecret',
            ])
            ->assertRedirect(route('app.profile.edit'));

        $user->refresh();
        $this->assertTrue(Hash::check('newsecret', $user->password));
    }
}
