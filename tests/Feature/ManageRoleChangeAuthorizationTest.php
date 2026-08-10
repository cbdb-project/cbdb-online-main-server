<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * performUserUpdate 角色變更授權收斂（見 docs/USER_PRIVILEGES.md 三之一）：
 *  - 僅系統管理員可變更 is_admin（專家不得授予/調整角色）；
 *  - 不得變更自己的角色；
 *  - 專家仍可調整帳號啟用（is_active）。
 */
class ManageRoleChangeAuthorizationTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-manage-role';
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
            'name' => $name,
            'email' => strtolower($name) . '@example.com',
            'password' => bcrypt('secret'),
            'confirmation_token' => 'token',
            'is_active' => $active,
            'is_admin' => $role,
            'institution' => 'Inst',
        ]);
    }

    #[Test]
    public function expert_cannot_elevate_user_to_super_admin(): void {
        $expert = $this->makeUser('Expert', User::ROLE_EXPERT);
        $target = $this->makeUser('Victim', User::ROLE_REGULAR);

        $this->actingAs($expert)
            ->from(route('app.manage.index'))
            ->patch(route('app.manage.update', ['manage' => $target->id]), [
                'is_active' => 1,
                'is_admin' => User::ROLE_SUPER_ADMIN,
            ])
            ->assertRedirect(route('app.manage.index'));

        $target->refresh();
        $this->assertSame(User::ROLE_REGULAR, (int) $target->is_admin, '專家不應能把他人提為系統管理員');
    }

    #[Test]
    public function expert_can_still_activate_without_changing_role(): void {
        $expert = $this->makeUser('Expert2', User::ROLE_EXPERT);
        $target = $this->makeUser('Pending', User::ROLE_REGULAR, 0);

        $this->actingAs($expert)
            ->patch(route('app.manage.update', ['manage' => $target->id]), [
                'is_active' => 1,
                'is_admin' => User::ROLE_REGULAR,
            ])
            ->assertRedirect(route('app.manage.index'));

        $target->refresh();
        $this->assertSame(1, (int) $target->is_active, '專家應仍可啟用帳號');
        $this->assertSame(User::ROLE_REGULAR, (int) $target->is_admin);
    }

    #[Test]
    public function super_admin_cannot_change_their_own_role(): void {
        $admin = $this->makeUser('Root', User::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)
            ->from(route('app.manage.index'))
            ->patch(route('app.manage.update', ['manage' => $admin->id]), [
                'is_active' => 1,
                'is_admin' => User::ROLE_REGULAR,
            ])
            ->assertRedirect(route('app.manage.index'));

        $admin->refresh();
        $this->assertSame(User::ROLE_SUPER_ADMIN, (int) $admin->is_admin, '不應能變更自己的角色');
    }

    #[Test]
    public function super_admin_can_change_another_users_role(): void {
        $admin = $this->makeUser('Root2', User::ROLE_SUPER_ADMIN);
        $target = $this->makeUser('Promotee', User::ROLE_REGULAR);

        $this->actingAs($admin)
            ->patch(route('app.manage.update', ['manage' => $target->id]), [
                'is_active' => 1,
                'is_admin' => User::ROLE_SUPER_ADMIN,
            ])
            ->assertRedirect(route('app.manage.index'));

        $target->refresh();
        $this->assertSame(User::ROLE_SUPER_ADMIN, (int) $target->is_admin, '系統管理員應可變更他人角色');
    }
}
