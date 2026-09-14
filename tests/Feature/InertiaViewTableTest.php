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

    #[Test]
    public function test_app_view_index_description_follows_locale(): void {
        // Register localized _desc translations for the test view (langKey: views.view_test_items).
        app('translator')->addLines(['views.view_test_items_desc' => 'Aggregated English description.'], 'en');
        app('translator')->addLines(['views.view_test_items_desc' => '中文說明覆寫。'], 'zh-TW');

        // English locale → English translation is used (not the raw Chinese config description).
        $this->actingAs($this->user)
            ->withSession(['locale' => 'en'])
            ->get(route('app.view.index'))
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where('views.1.key', 'test-items')
                    ->where('views.1.description', 'Aggregated English description.')
            );

        // Chinese locale → Chinese translation is used.
        $this->actingAs($this->user)
            ->withSession(['locale' => 'zh-TW'])
            ->get(route('app.view.index'))
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where('views.1.key', 'test-items')
                    ->where('views.1.description', '中文說明覆寫。')
            );
    }

    #[Test]
    public function test_app_view_show_description_follows_locale(): void {
        app('translator')->addLines(['views.view_test_items_desc' => 'Aggregated English description.'], 'en');

        $this->actingAs($this->user)
            ->withSession(['locale' => 'en'])
            ->get(route('app.view.show', 'test-items'))
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where('description', 'Aggregated English description.')
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
    // Old /view routes（環節 4a-3 已實體刪除 Blade 視圖）
    // -------------------------------------------------------
    //
    // 這裡原本有三條 legacy 測試，隨 Blade 下架環節 4a-3 移除：
    //
    //  - test_legacy_view_index_still_works ／ test_legacy_view_show_still_works
    //    只斷言 `assertViewIs('view.list')`／`assertViewIs('view.index')`；那兩個視圖已刪除。
    //  - test_kill_switch_restores_the_legacy_view_page 驗的是「關掉封路後 Blade 頁真的
    //    渲染」——那個能力現在**確實不存在**了（視圖已刪，而且 `/view` 改成純 redirect
    //    closure、不再掛 `legacy.page`，所以 kill switch 對它完全無作用）。
    //    它前半段的「302 → /app/view」已由
    //    LegacyBladePageRetirementTest::legacy_readonly_pages_redirect_without_the_kill_switch
    //    接手（那條同時驗「kill switch 關閉也照樣 302」）。
    //
    // 🔴 **連帶效應**：`/view` 這批頁面自此**沒有任何 kill switch 級回退**，
    //    要回到 Blade 只能 git revert 並重新部署。

}
