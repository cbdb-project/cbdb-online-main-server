<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P5-9 外部資料庫引用瀏覽器（/app/external-db-link）Inertia 變體測試。
 */
class WikiMaintenanceInertiaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-wiki';
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

        Schema::create('BIOG_SOURCE_DATA', function ($table) {
            $table->integer('c_personid');
            $table->integer('c_textid');
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
        });

        Schema::create('BIOG_MAIN', function ($table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
            $table->integer('c_dy')->nullable();
            $table->integer('c_index_year')->nullable();
            $table->integer('c_index_addr_id')->nullable();
        });

        Schema::create('TEXT_CODES', function ($table) {
            $table->integer('c_textid')->primary();
            $table->string('c_url_api')->nullable();
            $table->string('c_url_api_coda')->nullable();
        });

        Schema::create('DYNASTIES', function ($table) {
            $table->integer('c_dy')->primary();
            $table->string('c_dynasty_chn')->nullable();
        });

        Schema::create('ADDR_CODES', function ($table) {
            $table->integer('c_addr_id')->primary();
            $table->string('c_name_chn')->nullable();
        });
    }

    private function admin(): User {
        return User::forceCreate([
            'name' => 'Admin', 'email' => 'a@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    /** 建立共用測試資料：兩位人物（含朝代／指數年／指數地址）與中文維基引用。 */
    private function seedRecords(): void {
        DB::table('DYNASTIES')->insert(['c_dy' => 15, 'c_dynasty_chn' => '宋']);
        DB::table('ADDR_CODES')->insert(['c_addr_id' => 100, 'c_name_chn' => '開封']);
        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 1, 'c_name_chn' => '某人', 'c_dy' => 15, 'c_index_year' => 1050, 'c_index_addr_id' => 100],
            ['c_personid' => 2, 'c_name_chn' => '另一人', 'c_dy' => null, 'c_index_year' => 980, 'c_index_addr_id' => null],
        ]);
        DB::table('TEXT_CODES')->insert(['c_textid' => 60795, 'c_url_api' => 'https://zh.wikipedia.org/wiki/', 'c_url_api_coda' => '']);
        DB::table('BIOG_SOURCE_DATA')->insert([
            ['c_personid' => 1, 'c_textid' => 60795, 'c_pages' => '李白', 'c_notes' => null],
            ['c_personid' => 2, 'c_textid' => 60795, 'c_pages' => '杜甫', 'c_notes' => null],
        ]);
    }

    #[Test]
    public function renders_component_with_sources_and_records(): void {
        $this->seedRecords();

        $this->actingAs($this->admin())
            ->get(route('app.external-db-link'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/WikiMaintenance/Index')
                ->where('current_source_id', 60795)
                ->has('sources', 8)
                ->where('sources.0.name', '中文維基百科 (Wikipedia)')
                ->where('sources.0.icon', 'fab fa-wikipedia-w')
                ->where('sources.0.color', 'blue')
                ->where('records.0.c_personid', 1)
                ->where('records.0.c_dynasty_chn', '宋')
                ->where('records.0.c_index_year', 1050)
                ->where('records.0.c_index_addr_chn', '開封')
                ->where('records.0.link', 'https://zh.wikipedia.org/wiki/%E6%9D%8E%E7%99%BD')
                ->where('records.1.c_dynasty_chn', null)
                ->where('pagination.current_page', 1)
                ->where('pagination.last_page', 1)
                ->where('pagination.per_page', 20)
                ->where('pagination.total', 2)
                ->where('pagination.from', 1)
                ->where('pagination.to', 2)
                ->where('filters.search', '')
                ->where('sort', '')
                ->where('direction', 'asc')
                ->has('urls.index')
                ->has('page_translations.admin'));
    }

    #[Test]
    public function search_filters_by_name_pages_or_person_id(): void {
        $this->seedRecords();
        $admin = $this->admin();

        // 依人名模糊比對
        $this->actingAs($admin)
            ->get(route('app.external-db-link', ['search' => '另一']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('records', 1)
                ->where('records.0.c_personid', 2)
                ->where('filters.search', '另一')
                ->where('pagination.total', 1));

        // 依頁碼標題模糊比對
        $this->actingAs($admin)
            ->get(route('app.external-db-link', ['search' => '李白']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('records', 1)
                ->where('records.0.c_personid', 1));

        // 純數字視為人物 ID 精確比對
        $this->actingAs($admin)
            ->get(route('app.external-db-link', ['search' => '2']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('records', 1)
                ->where('records.0.c_personid', 2));
    }

    #[Test]
    public function sorts_by_whitelisted_column_and_ignores_unknown(): void {
        $this->seedRecords();
        $admin = $this->admin();

        // 依指數年遞增：980（personid 2）在前
        $this->actingAs($admin)
            ->get(route('app.external-db-link', ['sort' => 'c_index_year', 'direction' => 'asc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('records.0.c_personid', 2)
                ->where('sort', 'c_index_year')
                ->where('direction', 'asc'));

        // 白名單外欄位一律忽略，回退預設排序（personid 遞增）
        $this->actingAs($admin)
            ->get(route('app.external-db-link', ['sort' => 'c_notes; DROP TABLE users', 'direction' => 'desc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('records.0.c_personid', 1)
                ->where('sort', ''));
    }

    #[Test]
    public function active_regular_user_can_access(): void {
        $regular = User::forceCreate([
            'name' => 'R', 'email' => 'r@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_REGULAR,
        ]);

        $this->actingAs($regular)
            ->get(route('app.external-db-link'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/WikiMaintenance/Index'));
    }

    #[Test]
    public function guest_redirected_to_login(): void {
        $this->get(route('app.external-db-link'))->assertRedirect('/login');
    }

    #[Test]
    public function legacy_path_redirects_to_react_version_preserving_query(): void {
        // Blade 版已下架：/external-db-link 硬導向 /app/external-db-link（同 Query Playground 模式）。
        $this->actingAs($this->admin())
            ->get('/external-db-link?source_id=68942&search=Q1')
            ->assertRedirect(route('app.external-db-link', ['source_id' => 68942, 'search' => 'Q1']));
    }

    #[Test]
    public function inactive_user_forbidden(): void {
        $inactive = User::forceCreate([
            'name' => 'I', 'email' => 'i@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 0, 'is_admin' => User::ROLE_REGULAR,
        ]);

        $this->actingAs($inactive)
            ->get(route('app.external-db-link'))
            ->assertForbidden();
    }
}
