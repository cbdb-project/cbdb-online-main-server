<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InertiaViewTableTest extends TestCase {
    protected User $user;

    protected function setUp(): void {
        parent::setUp();

        $this->createTestTables();
        $this->user = User::factory()->create([
            'is_active' => 1,
        ]);
        $this->seedTestData();
        $this->setTestConfig();
    }

    protected function createTestTables(): void {
        DB::statement('PRAGMA foreign_keys = OFF');

        DB::statement('
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                password VARCHAR(255) NOT NULL,
                remember_token VARCHAR(100),
                confirmation_token VARCHAR(255) NOT NULL,
                is_active SMALLINT NOT NULL DEFAULT 0,
                is_admin SMALLINT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS test_view_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                c_name VARCHAR(255),
                c_name_chn VARCHAR(255),
                c_value INTEGER
            )
        ');
    }

    protected function seedTestData(): void {
        DB::table('test_view_items')->insert([
            ['c_name' => 'Alpha', 'c_name_chn' => '甲', 'c_value' => 10],
            ['c_name' => 'Beta', 'c_name_chn' => '乙', 'c_value' => 20],
            ['c_name' => 'Gamma', 'c_name_chn' => '丙', 'c_value' => 30],
        ]);
    }

    protected function setTestConfig(): void {
        Config::set('view_tables', [
            'test-items' => [
                'aliases' => ['View_TestItems', 'TestAlias'],
                'title' => '測試項目檢視',
                'description' => '這是測試用的檢視表。',
                'builder' => function () {
                    return DB::table('test_view_items')
                        ->select('id', 'c_name', 'c_name_chn', 'c_value');
                },
                'columns' => [
                    'id' => 'ID',
                    'c_name' => 'Name (ENG)',
                    'c_name_chn' => 'Name (CHN)',
                    'c_value' => 'Value',
                ],
                'page_size' => 2,
            ],
            'another-view' => [
                'aliases' => ['View_AnotherView'],
                'title' => '另一檢視',
                'description' => '另一個測試檢視。',
                'builder' => function () {
                    return DB::table('test_view_items')
                        ->select('id', 'c_name');
                },
                'columns' => [
                    'id' => 'ID',
                    'c_name' => 'Name',
                ],
                'page_size' => 50,
            ],
        ]);

        Config::set('view_table_searchable', [
            'test-items' => [
                'c_name',
                'c_name_chn',
            ],
        ]);
    }

    // -------------------------------------------------------
    // /app/view (list)
    // -------------------------------------------------------

    #[Test]
    public function test_app_view_index_requires_authentication(): void {
        $response = $this->get(route('app.view.index'));
        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function test_app_view_index_returns_inertia_page(): void {
        $response = $this->actingAs($this->user)->get(route('app.view.index'));

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('ViewTables/List')
                ->has('views', 2)
                ->has('listUrl')
        );
    }

    #[Test]
    public function test_app_view_index_list_content_is_correct(): void {
        $response = $this->actingAs($this->user)->get(route('app.view.index'));

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('ViewTables/List')
                ->has('views', 2)
                // Sorted by primary_alias (case-insensitive)
                // View_AnotherView < View_TestItems
                ->where('views.0.key', 'another-view')
                ->where('views.0.primary_alias', 'View_AnotherView')
                ->where('views.0.title', '另一檢視')
                ->where('views.1.key', 'test-items')
                ->where('views.1.primary_alias', 'View_TestItems')
                ->where('views.1.title', '測試項目檢視')
                ->where('views.1.description', '這是測試用的檢視表。')
        );
    }

    // -------------------------------------------------------
    // /app/view/{key} (show)
    // -------------------------------------------------------

    #[Test]
    public function test_app_view_show_requires_authentication(): void {
        $response = $this->get(route('app.view.show', 'test-items'));
        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function test_app_view_show_returns_inertia_page(): void {
        $response = $this->actingAs($this->user)->get(route('app.view.show', 'test-items'));

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('ViewTables/Show')
                ->where('title', '測試項目檢視')
                ->where('description', '這是測試用的檢視表。')
                ->has('columns')
                ->has('rows', 2)
                ->has('pagination')
                ->has('debug')
                ->has('pageUrl')
                ->has('listUrl')
                ->where('key', 'test-items')
        );
    }

    #[Test]
    public function test_app_view_show_alias_resolves_correctly(): void {
        $response = $this->actingAs($this->user)->get(route('app.view.show', 'View_TestItems'));

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('ViewTables/Show')
                ->where('key', 'test-items')
                ->where('title', '測試項目檢視')
        );
    }

    #[Test]
    public function test_app_view_show_alias_is_case_insensitive(): void {
        $response = $this->actingAs($this->user)->get(route('app.view.show', 'view_testitems'));

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('ViewTables/Show')
                ->where('key', 'test-items')
        );
    }

    #[Test]
    public function test_app_view_show_search_applies_correctly(): void {
        $response = $this->actingAs($this->user)->get(route('app.view.show', ['key' => 'test-items', 'search' => 'Alpha']));

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('ViewTables/Show')
                ->has('rows', 1)
                ->where('rows.0.c_name', 'Alpha')
                ->where('filters.search', 'Alpha')
        );
    }

    #[Test]
    public function test_app_view_show_search_chinese(): void {
        $response = $this->actingAs($this->user)->get(route('app.view.show', ['key' => 'test-items', 'search' => '乙']));

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('ViewTables/Show')
                ->has('rows', 1)
                ->where('rows.0.c_name_chn', '乙')
        );
    }

    #[Test]
    public function test_app_view_show_pagination_preserves_search(): void {
        $response = $this->actingAs($this->user)->get(route('app.view.show', [
            'key' => 'test-items',
            'search' => '',
            'page' => 2,
        ]));

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('ViewTables/Show')
                // page_size = 2, total = 3, so page 2 has 1 row
                ->has('rows', 1)
                ->where('pagination.current_page', 2)
                ->where('pagination.last_page', 2)
                ->where('pagination.total', 3)
        );
    }

    #[Test]
    public function test_app_view_show_invalid_key_returns_404(): void {
        $response = $this->actingAs($this->user)->get(route('app.view.show', 'nonexistent-key'));
        $response->assertNotFound();
    }

    #[Test]
    public function test_app_view_show_debug_info_present(): void {
        $response = $this->actingAs($this->user)->get(route('app.view.show', 'test-items'));

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('ViewTables/Show')
                ->has('debug.sql')
                ->has('debug.rendered_sql')
                ->has('debug.bindings')
                ->where('debug.per_page', 2)
                ->where('debug.current_page', 1)
        );
    }

    // -------------------------------------------------------
    // Old /view routes still work
    // -------------------------------------------------------

    #[Test]
    public function test_legacy_view_index_still_works(): void {
        $response = $this->actingAs($this->user)->get(route('view.index'));
        $response->assertOk();
        $response->assertViewIs('view.list');
    }

    #[Test]
    public function test_legacy_view_show_still_works(): void {
        $response = $this->actingAs($this->user)->get(route('view.show', 'test-items'));
        $response->assertOk();
        $response->assertViewIs('view.index');
    }
}
