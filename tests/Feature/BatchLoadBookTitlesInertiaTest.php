<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P5-6 admin/batch-load-book-titles Inertia 變體
 * （app.admin.batch-load-book-titles[.store/.undo]）測試。
 */
class BatchLoadBookTitlesInertiaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-batch-books';
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

        Schema::create('TEXT_CODES', function ($table) {
            $table->integer('c_textid')->primary();
            $table->string('c_notes')->nullable();
        });
        Schema::create('operations', function ($table) {
            $table->increments('id');
            $table->string('resource')->nullable();
            $table->text('resource_id')->nullable();
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
            ->get(route('app.admin.batch-load-book-titles'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/BatchLoadBookTitles/Index')
                ->has('input')
                ->has('results')
                ->has('urls.store')
                ->has('urls.undo'));
    }

    #[Test]
    public function non_admin_forbidden(): void {
        $regular = User::forceCreate([
            'name' => 'R', 'email' => 'r@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_REGULAR,
        ]);

        $this->actingAs($regular)->get(route('app.admin.batch-load-book-titles'))->assertForbidden();
    }

    #[Test]
    public function store_validation_error_redirects_to_app_route(): void {
        // 空 entries → required 驗證失敗 → 重導回 app 列表（依請求路徑）。
        $this->actingAs($this->admin())
            ->from(route('app.admin.batch-load-book-titles'))
            ->post(route('app.admin.batch-load-book-titles.store'), ['entries' => ''])
            ->assertRedirect(route('app.admin.batch-load-book-titles'))
            ->assertSessionHasErrors('entries');
    }

    #[Test]
    public function undo_redirects_to_app_route(): void {
        // 有效格式但無對應批次 → 刪 0 筆 → 重導 app 列表（依請求路徑）。
        $this->actingAs($this->admin())
            ->post(route('app.admin.batch-load-book-titles.undo'), ['batch_id' => '20260101000000-ABCDEF'])
            ->assertRedirect(route('app.admin.batch-load-book-titles'));
    }
}
