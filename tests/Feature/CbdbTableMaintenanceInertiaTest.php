<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P5-10 admin/cbdb-table-maintenance Inertia 變體測試。
 */
class CbdbTableMaintenanceInertiaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-table-maint';
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
        return User::create([
            'name' => 'Admin', 'email' => 'a@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    #[Test]
    public function renders_component_with_tables(): void {
        $this->actingAs($this->admin())
            ->get(route('app.admin.cbdb-table-maintenance'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/CbdbTableMaintenance/Index')
                ->has('tables', 2)
                ->where('tables.0.table_key', 'CBDB__TRAD_SIMP_MAP')
                ->where('tables.0.exists', false)
                ->where('tables.1.table_key', 'CBDB__NAME_FTS')
                ->has('urls.rebuild')
                ->has('page_translations.admin'));
    }

    #[Test]
    public function counts_existing_table(): void {
        Schema::create('CBDB__TRAD_SIMP_MAP', function ($table) {
            $table->increments('id');
            $table->string('trad')->nullable();
        });
        \Illuminate\Support\Facades\DB::table('CBDB__TRAD_SIMP_MAP')->insert([['trad' => '繁'], ['trad' => '體']]);

        $this->actingAs($this->admin())
            ->get(route('app.admin.cbdb-table-maintenance'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tables.0.exists', true)
                ->where('tables.0.count', 2));
    }

    #[Test]
    public function non_admin_forbidden(): void {
        $regular = User::create([
            'name' => 'R', 'email' => 'r@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_REGULAR,
        ]);

        $this->actingAs($regular)
            ->get(route('app.admin.cbdb-table-maintenance'))
            ->assertForbidden();
    }
}
