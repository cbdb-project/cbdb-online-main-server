<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P5-2 manage/index Inertia 變體（app.manage.index）測試。
 */
class ManageIndexInertiaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-manage-index';
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
    public function admin_sees_user_listing(): void {
        $admin = $this->makeUser('Admin', User::ROLE_SUPER_ADMIN);
        $this->makeUser('Bob', User::ROLE_REGULAR);

        $this->actingAs($admin)->get(route('app.manage.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Manage/Index')
                ->has('data.rows')
                ->has('data.meta')
                ->has('inactive_users')
                ->has('filters')
                ->has('edit_template'));
    }

    #[Test]
    public function non_admin_redirected_home(): void {
        $regular = $this->makeUser('Reg', User::ROLE_REGULAR);

        $this->actingAs($regular)->get(route('app.manage.index'))->assertRedirect('/home');
    }

    #[Test]
    public function search_filters_users(): void {
        $admin = $this->makeUser('Admin', User::ROLE_SUPER_ADMIN);
        $this->makeUser('Charlie', User::ROLE_REGULAR);

        $this->actingAs($admin)->get(route('app.manage.index', ['search' => 'Charlie']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.rows', 1)
                ->where('filters.search', 'Charlie'));
    }
}
