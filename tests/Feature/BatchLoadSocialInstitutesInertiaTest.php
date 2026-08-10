<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P5-8 admin/batch-load-social-institutes Inertia 變體測試。
 */
class BatchLoadSocialInstitutesInertiaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-batch-social';
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
            $table->string('confirmation_token')->nullable();
            $table->tinyInteger('is_active')->default(0);
            $table->tinyInteger('is_admin')->default(0);
            $table->timestamps();
        });
    }

    private function admin(): User {
        return User::forceCreate([
            'name' => 'Admin', 'email' => 'a@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    #[Test]
    public function show_form_renders_component(): void {
        $this->actingAs($this->admin())
            ->get(route('app.admin.batch-load-social-institutes'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/BatchLoadSocialInstitutes/Index')
                ->has('input')
                ->has('results')
                ->has('urls.store'));
    }

    #[Test]
    public function non_admin_forbidden(): void {
        $regular = User::forceCreate([
            'name' => 'R', 'email' => 'r@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_REGULAR,
        ]);

        $this->actingAs($regular)->get(route('app.admin.batch-load-social-institutes'))->assertForbidden();
    }

    #[Test]
    public function store_validation_error_redirects_to_app_route(): void {
        $this->actingAs($this->admin())
            ->from(route('app.admin.batch-load-social-institutes'))
            ->post(route('app.admin.batch-load-social-institutes.store'), ['entries' => ''])
            ->assertRedirect(route('app.admin.batch-load-social-institutes'))
            ->assertSessionHasErrors('entries');
    }
}
