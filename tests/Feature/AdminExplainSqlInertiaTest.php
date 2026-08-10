<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P1-3 admin/explainsql Inertia 變體（app.admin.explainsql / .explain）測試。
 */
class AdminExplainSqlInertiaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-explainsql-inertia';
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
        config()->set('mcp.cbdb.allowed_tables', ['sample']);
        config()->set('mcp.cbdb.max_limit', 100);

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

    private function makeAdmin(): User {
        return User::forceCreate([
            'name' => 'Admin', 'email' => 'a@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    #[Test]
    public function show_renders_component_with_empty_props(): void {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get(route('app.admin.explainsql'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ExplainSql/Index')
                ->where('sql', '')
                ->where('results', null)
                ->where('error', null)
                ->has('explain_url')
                ->has('page_translations.admin'));
    }

    #[Test]
    public function non_admin_gets_403(): void {
        $regular = User::forceCreate([
            'name' => 'R', 'email' => 'r@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_REGULAR,
        ]);

        $this->actingAs($regular)->get(route('app.admin.explainsql'))->assertForbidden();
    }

    #[Test]
    public function explain_requires_sql(): void {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->from(route('app.admin.explainsql'))
            ->post(route('app.admin.explainsql.explain'), ['sql' => ''])
            ->assertSessionHasErrors('sql');
    }

    #[Test]
    public function explain_rejects_non_readonly_sql_with_error_prop(): void {
        $admin = $this->makeAdmin();

        // 非唯讀 SQL 應由 ReadOnlyTableQueryService 擋下，error prop 非 null、results 為 null，
        // 不觸及實際 DB EXPLAIN。
        $this->actingAs($admin)
            ->post(route('app.admin.explainsql.explain'), ['sql' => 'DELETE FROM users'])
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ExplainSql/Index')
                ->where('results', null)
                ->whereNot('error', null));
    }

    #[Test]
    public function explain_returns_results_props_for_valid_sql(): void {
        $admin = $this->makeAdmin();
        DB::statement('CREATE TABLE sample (id INTEGER)');

        $this->actingAs($admin)
            ->post(route('app.admin.explainsql.explain'), ['sql' => 'SELECT * FROM sample'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ExplainSql/Index')
                ->where('sql', 'SELECT * FROM sample')
                ->where('error', null)
                ->has('columns')
                ->has('results.0'));
    }
}
