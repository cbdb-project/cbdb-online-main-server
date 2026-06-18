<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P5-1 operations（最近操作 / 提案）Inertia 變體測試。
 */
class OperationsInertiaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-operations';
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
            $table->timestamps();
        });

        Schema::create('BIOG_MAIN', function ($table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name')->nullable();
            $table->string('c_name_chn')->nullable();
        });
    }

    private function admin(): User {
        return User::create([
            'name' => 'Admin', 'email' => 'a@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    #[Test]
    public function index_renders_component(): void {
        $this->actingAs($this->admin())
            ->get(route('app.operations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Operations/Index')
                ->has('lists')
                ->has('pagination.total')
                ->where('proposals_only', false)
                ->has('page_translations.operations'));
    }

    #[Test]
    public function index_serializes_operation_row_with_diff(): void {
        $admin = $this->admin();

        DB::table('operations')->insert([
            'user_id' => $admin->id,
            'c_personid' => 0,
            'op_type' => 3,
            'resource' => 'TEST_RES',
            'resource_id' => '',
            'resource_data' => json_encode(['c_name_chn' => '新名']),
            'resource_original' => json_encode(['c_name_chn' => '舊名']),
            'crowdsourcing_status' => 0,
            'created_at' => '2026-06-18 00:00:00',
            'updated_at' => '2026-06-18 00:00:00',
        ]);

        $this->actingAs($admin)
            ->get(route('app.operations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Operations/Index')
                ->where('lists.0.op_type', 3)
                ->where('lists.0.can_compare', true)
                ->where('pagination.total', 1)
                ->has('lists.0.diff_source'));
    }

    #[Test]
    public function proposals_mode_sets_flag(): void {
        $this->actingAs($this->admin())
            ->get(route('app.operations.index', ['proposals_only' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Operations/Index')
                ->where('proposals_only', true)
                ->has('status_filters'));
    }
}
