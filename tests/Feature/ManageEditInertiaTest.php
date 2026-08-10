<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P5-3 manage/edit Inertia 變體（app.manage.edit/update）測試。
 */
class ManageEditInertiaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-manage-edit';
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
            $table->string('institution')->nullable();
            $table->string('password');
            $table->string('confirmation_token')->nullable();
            $table->string('remember_token')->nullable();
            $table->tinyInteger('is_active')->default(0);
            $table->tinyInteger('is_admin')->default(0);
            $table->timestamps();
        });
    }

    private function makeUser(string $name, int $role, int $active = 1): User {
        return User::forceCreate([
            'name' => $name, 'email' => strtolower($name) . '@example.com', 'password' => bcrypt('secret'),
            'confirmation_token' => 'token', 'is_active' => $active, 'is_admin' => $role, 'institution' => 'Inst',
        ]);
    }

    #[Test]
    public function edit_renders_user_form(): void {
        $admin = $this->makeUser('Admin', User::ROLE_SUPER_ADMIN);
        $target = $this->makeUser('Bob', User::ROLE_REGULAR);

        $this->actingAs($admin)->get(route('app.manage.edit', ['manage' => $target->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Manage/Edit')
                ->where('user.id', $target->id)
                ->where('user.is_admin', 0)
                ->where('user.created_at', $target->created_at?->format('Y-m-d H:i:s'))
                ->where('user.updated_at', $target->updated_at?->format('Y-m-d H:i:s'))
                ->has('urls.update'));
    }

    #[Test]
    public function update_changes_role_and_activation(): void {
        $admin = $this->makeUser('Admin', User::ROLE_SUPER_ADMIN);
        $target = $this->makeUser('Bob', User::ROLE_REGULAR, 0);

        $this->actingAs($admin)
            ->patch(route('app.manage.update', ['manage' => $target->id]), ['is_active' => 1, 'is_admin' => 1])
            ->assertRedirect(route('app.manage.index'));

        $target->refresh();
        $this->assertSame(1, (int) $target->is_active);
        $this->assertSame(1, (int) $target->is_admin);
    }

    #[Test]
    public function update_soft_deletes_user(): void {
        $admin = $this->makeUser('Admin', User::ROLE_SUPER_ADMIN);
        $target = $this->makeUser('Bob', User::ROLE_REGULAR);

        $this->actingAs($admin)
            ->patch(route('app.manage.update', ['manage' => $target->id]), ['delete_user' => 1])
            ->assertRedirect(route('app.manage.index'));

        $target->refresh();
        $this->assertSame('-', $target->password);
    }

    #[Test]
    public function non_manager_cannot_update(): void {
        $regular = $this->makeUser('Reg', User::ROLE_REGULAR);
        $target = $this->makeUser('Bob', User::ROLE_REGULAR);

        $this->actingAs($regular)
            ->from(route('app.manage.index'))
            ->patch(route('app.manage.update', ['manage' => $target->id]), ['is_active' => 0, 'is_admin' => 3]);

        $target->refresh();
        $this->assertSame(0, (int) $target->is_admin);
    }
}
