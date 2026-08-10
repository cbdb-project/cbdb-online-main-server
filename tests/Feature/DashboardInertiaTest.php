<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P1-4 dashboard Inertia 變體（app.dashboard）測試。
 */
class DashboardInertiaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-dashboard-inertia';
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

        foreach (['BIOG_MAIN', 'ALTNAME_DATA', 'POSTED_TO_OFFICE_DATA', 'BIOG_TEXT_DATA'] as $t) {
            Schema::create($t, function ($table) {
                $table->increments('id');
            });
        }

        Schema::create('operations', function ($table) {
            $table->increments('id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('op_type', 32)->nullable();
            $table->timestamps();
        });
    }

    #[Test]
    public function it_renders_dashboard_component_with_stats(): void {
        $user = User::forceCreate([
            'name' => 'Alice', 'email' => 'a@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_REGULAR,
        ]);

        DB::table('BIOG_MAIN')->insert([['id' => 1], ['id' => 2]]);
        DB::table('operations')->insert([
            ['user_id' => $user->id, 'op_type' => 'create', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $user->id, 'op_type' => 'create', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $response = $this->actingAs($user)->get(route('app.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->where('totalPersons', 2)
            ->where('totalOperations', 2)
            ->has('totalUsers')
            ->has('dailyStats')
            ->has('weeklyStats')
            ->has('monthlyStats')
            ->has('operationTypeStats'));
    }

    #[Test]
    public function guest_is_redirected_to_login(): void {
        $this->get(route('app.dashboard'))->assertRedirect();
    }
}
