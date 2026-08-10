<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P5-5 crowdsourcing（最近眾包錄入記錄）Inertia 變體測試。
 */
class CrowdsourcingInertiaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-crowdsourcing';
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

        Schema::create('operations', function ($table) {
            $table->increments('id');
            $table->integer('user_id')->default(0);
            $table->integer('c_personid')->default(0);
            $table->integer('op_type')->default(0);
            $table->string('resource')->nullable();
            $table->string('resource_id')->nullable();
            $table->text('resource_data')->nullable();
            $table->text('resource_original')->nullable();
            $table->integer('crowdsourcing_status')->default(0);
            $table->integer('rate')->default(0);
            $table->timestamps();
        });
    }

    private function superAdmin(): User {
        return User::forceCreate([
            'name' => 'Admin', 'email' => 'a@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    #[Test]
    public function index_renders_component_with_lists_and_pagination(): void {
        $this->actingAs($this->superAdmin())
            ->get(route('app.crowdsourcing.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Crowdsourcing/Index')
                ->has('lists')
                ->has('pagination.total')
                ->has('page_translations.operations'));
    }

    #[Test]
    public function index_serializes_row_with_diff_and_review_flag(): void {
        $admin = $this->superAdmin();

        DB::table('operations')->insert([
            'user_id' => $admin->id,
            'c_personid' => 0,
            'op_type' => 2,
            'resource' => 'BIOG_MAIN',
            'resource_id' => '0',
            'resource_data' => json_encode(['c_name_chn' => '新名']),
            'resource_original' => json_encode(['c_name_chn' => '舊名']),
            'crowdsourcing_status' => 2,
            'rate' => 0,
            'created_at' => '2026-06-18 00:00:00',
            'updated_at' => '2026-06-18 00:00:00',
        ]);

        $this->actingAs($admin)
            ->get(route('app.crowdsourcing.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Crowdsourcing/Index')
                ->where('lists.0.resource', 'BIOG_MAIN')
                ->where('lists.0.can_review', true)
                ->where('pagination.total', 1)
                ->has('lists.0.resource_diff'));
    }

    #[Test]
    public function non_super_admin_forbidden(): void {
        $regular = User::forceCreate([
            'name' => 'R', 'email' => 'r@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_REGULAR,
        ]);

        $this->actingAs($regular)->get(route('app.crowdsourcing.index'))->assertForbidden();
    }
}
