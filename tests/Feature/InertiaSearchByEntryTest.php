<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InertiaSearchByEntryTest extends TestCase {
    protected $user;

    protected function setUp(): void {
        parent::setUp();

        $this->createTestTables();

        $this->user = User::factory()->create([
            'is_active' => 1,
        ]);

        $this->seedTestData();
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
            CREATE TABLE IF NOT EXISTS ENTRY_TYPES (
                c_entry_type VARCHAR(255) NOT NULL PRIMARY KEY,
                c_entry_type_desc VARCHAR(255),
                c_entry_type_desc_chn VARCHAR(255),
                c_entry_type_parent_id VARCHAR(255),
                c_entry_type_level DOUBLE,
                c_entry_type_sortorder DOUBLE
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS ENTRY_CODES (
                c_entry_code SMALLINT NOT NULL PRIMARY KEY,
                c_entry_desc VARCHAR(255),
                c_entry_desc_chn VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS ENTRY_CODE_TYPE_REL (
                c_entry_code SMALLINT NOT NULL,
                c_entry_type VARCHAR(255) NOT NULL,
                PRIMARY KEY (c_entry_code, c_entry_type)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS ENTRY_DATA (
                c_personid INT NOT NULL,
                c_entry_code SMALLINT NOT NULL,
                c_sequence SMALLINT NOT NULL,
                c_year INT,
                c_entry_addr_id INT,
                c_exam_rank VARCHAR(255),
                c_kin_code SMALLINT NOT NULL DEFAULT 0,
                c_kin_id INT NOT NULL DEFAULT 0,
                c_assoc_code SMALLINT NOT NULL DEFAULT 0,
                c_assoc_id INT NOT NULL DEFAULT 0,
                c_inst_code INT NOT NULL DEFAULT 0,
                c_inst_name_code INT NOT NULL DEFAULT 0,
                c_notes TEXT,
                PRIMARY KEY (c_personid, c_entry_code, c_sequence, c_kin_code, c_assoc_code, c_kin_id, c_year, c_assoc_id, c_inst_code, c_inst_name_code)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS BIOG_MAIN (
                c_personid INT NOT NULL PRIMARY KEY,
                c_name VARCHAR(255),
                c_name_chn VARCHAR(255),
                c_dy VARCHAR(255),
                c_index_year INT,
                c_index_addr_id INT
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS DYNASTIES (
                c_dy VARCHAR(255) NOT NULL PRIMARY KEY,
                c_dynasty VARCHAR(255),
                c_dynasty_chn VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS ADDR_CODES (
                c_addr_id INT NOT NULL PRIMARY KEY,
                c_name VARCHAR(255),
                c_name_chn VARCHAR(255)
            )
        ');
    }

    protected function seedTestData(): void {
        DB::table('ENTRY_TYPES')->insert([
            [
                'c_entry_type' => 'TYPE1',
                'c_entry_type_desc' => 'Type 1',
                'c_entry_type_desc_chn' => '類型一',
                'c_entry_type_parent_id' => null,
                'c_entry_type_level' => 1,
                'c_entry_type_sortorder' => 1,
            ],
            [
                'c_entry_type' => 'TYPE2',
                'c_entry_type_desc' => 'Type 2',
                'c_entry_type_desc_chn' => '類型二',
                'c_entry_type_parent_id' => 'TYPE1',
                'c_entry_type_level' => 2,
                'c_entry_type_sortorder' => 2,
            ],
        ]);

        DB::table('ENTRY_CODES')->insert([
            ['c_entry_code' => 1, 'c_entry_desc' => 'Entry Code 1', 'c_entry_desc_chn' => '入仕代碼一'],
            ['c_entry_code' => 2, 'c_entry_desc' => 'Entry Code 2', 'c_entry_desc_chn' => '入仕代碼二'],
        ]);

        DB::table('ENTRY_CODE_TYPE_REL')->insert([
            ['c_entry_code' => 1, 'c_entry_type' => 'TYPE1'],
            ['c_entry_code' => 2, 'c_entry_type' => 'TYPE2'],
        ]);

        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 1, 'c_name' => 'Test Person 1', 'c_name_chn' => '測試人物一', 'c_dy' => 'DY1', 'c_index_year' => 1000, 'c_index_addr_id' => 10],
            ['c_personid' => 2, 'c_name' => 'Test Person 2', 'c_name_chn' => '測試人物二', 'c_dy' => 'DY2', 'c_index_year' => 1100, 'c_index_addr_id' => 20],
        ]);

        DB::table('DYNASTIES')->insert([
            ['c_dy' => 'DY1', 'c_dynasty' => 'Dynasty 1', 'c_dynasty_chn' => '朝代一'],
            ['c_dy' => 'DY2', 'c_dynasty' => 'Dynasty 2', 'c_dynasty_chn' => '朝代二'],
        ]);

        DB::table('ADDR_CODES')->insert([
            ['c_addr_id' => 10, 'c_name' => 'Address 1', 'c_name_chn' => '地址一'],
            ['c_addr_id' => 20, 'c_name' => 'Address 2', 'c_name_chn' => '地址二'],
        ]);

        DB::table('ENTRY_DATA')->insert([
            [
                'c_personid' => 1, 'c_entry_code' => 1, 'c_sequence' => 1, 'c_year' => 1020,
                'c_entry_addr_id' => 100, 'c_kin_code' => 0, 'c_kin_id' => 0,
                'c_assoc_code' => 0, 'c_assoc_id' => 0, 'c_inst_code' => 0, 'c_inst_name_code' => 0,
            ],
            [
                'c_personid' => 2, 'c_entry_code' => 2, 'c_sequence' => 1, 'c_year' => 1120,
                'c_entry_addr_id' => 200, 'c_kin_code' => 0, 'c_kin_id' => 0,
                'c_assoc_code' => 0, 'c_assoc_id' => 0, 'c_inst_code' => 0, 'c_inst_name_code' => 0,
            ],
        ]);
    }

    /**
     * 新入口需要登入
     */
    #[Test]
    public function test_app_index_requires_authentication(): void {
        $response = $this->get(route('app.search-by.entry.index'));
        $response->assertRedirect(route('login'));
    }

    /**
     * 無條件時可渲染 Inertia page
     */
    #[Test]
    public function test_app_index_returns_inertia_page(): void {
        $response = $this->actingAs($this->user)->get(route('app.search-by.entry.index'));
        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
            ->component('SearchByEntry/Index')
            ->has('entryTypes', 2)
            ->where('results', null)
        );
    }

    /**
     * Inertia 回應包含正確的 props 結構
     */
    #[Test]
    public function test_app_index_has_correct_props_structure(): void {
        $response = $this->actingAs($this->user)->get(route('app.search-by.entry.index'));
        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
            ->component('SearchByEntry/Index')
            ->has('entryTypes')
            ->has('preloadedCodes')
            ->has('filters')
            ->has('typesEndpoint')
            ->has('codesEndpoint')
            ->where('filters.entry_codes', [])
            ->where('filters.year_from', null)
            ->where('filters.year_to', null)
            ->where('filters.addr_id', null)
            ->where('filters.type_id', null)
        );
    }

    /**
     * 帶搜尋條件時返回結果
     */
    #[Test]
    public function test_app_index_with_search_conditions_returns_results(): void {
        $response = $this->actingAs($this->user)
            ->get(route('app.search-by.entry.index', [
                'entry_codes' => [1],
            ]));

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
            ->component('SearchByEntry/Index')
            ->has('results')
            ->where('results.total', 1)
            ->has('results.data', 1)
            ->where('results.data.0.c_personid', 1)
        );
    }

    /**
     * 分頁可保留條件
     */
    #[Test]
    public function test_app_index_pagination_preserves_conditions(): void {
        $response = $this->actingAs($this->user)
            ->get(route('app.search-by.entry.index', [
                'entry_codes' => [1, 2],
                'page' => 1,
            ]));

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
            ->component('SearchByEntry/Index')
            ->has('results')
            ->where('results.total', 2)
            ->where('filters.entry_codes', [1, 2])
        );
    }

    /**
     * year_from > year_to 有驗證錯誤
     */
    #[Test]
    public function test_app_index_validates_year_range(): void {
        $response = $this->actingAs($this->user)
            ->get(route('app.search-by.entry.index', [
                'year_from' => 1200,
                'year_to' => 1000,
            ]));

        // GET 驗證失敗會重定向
        $response->assertStatus(302);
        $response->assertSessionHasErrors('year_from');
    }

    /**
     * 舊路由仍正常
     */
    #[Test]
    public function test_old_blade_routes_still_work(): void {
        // 舊首頁
        $response = $this->actingAs($this->user)->get(route('search-by.entry.index'));
        $response->assertStatus(200);
        $response->assertViewIs('search-by.entry.index');

        // 舊搜尋
        $response = $this->actingAs($this->user)->get(route('search-by.entry.search', [
            'entry_codes' => [1],
        ]));
        $response->assertStatus(200);
        $response->assertViewIs('search-by.entry.results');

        // 舊 API
        $response = $this->actingAs($this->user)->getJson(route('search-by.entry.types'));
        $response->assertStatus(200)->assertJson(['success' => true]);
    }

    /**
     * type_id 可預載 entry codes
     */
    #[Test]
    public function test_app_index_preloads_codes_for_type(): void {
        $response = $this->actingAs($this->user)
            ->get(route('app.search-by.entry.index', [
                'type_id' => 'TYPE1',
            ]));

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
            ->has('preloadedCodes', 1)
            ->where('preloadedCodes.0.c_entry_code', 1)
        );
    }

    /**
     * 無搜尋條件（僅 type_id）不執行查詢
     */
    #[Test]
    public function test_app_index_no_results_without_search_conditions(): void {
        $response = $this->actingAs($this->user)
            ->get(route('app.search-by.entry.index', [
                'type_id' => 'TYPE1',
            ]));

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
            ->where('results', null)
        );
    }

    /**
     * 地址過濾可正常運作
     */
    #[Test]
    public function test_app_index_search_with_address_filter(): void {
        $response = $this->actingAs($this->user)
            ->get(route('app.search-by.entry.index', [
                'entry_codes' => [1, 2],
                'addr_id' => 100,
            ]));

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
            ->has('results')
            ->where('results.total', 1)
            ->where('results.data.0.c_personid', 1)
        );
    }

    /**
     * 年份範圍過濾可正常運作
     */
    #[Test]
    public function test_app_index_search_with_year_range(): void {
        $response = $this->actingAs($this->user)
            ->get(route('app.search-by.entry.index', [
                'entry_codes' => [1, 2],
                'year_from' => 1100,
                'year_to' => 1200,
            ]));

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
            ->has('results')
            ->where('results.total', 1)
            ->where('results.data.0.c_personid', 2)
        );
    }
}
