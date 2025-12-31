<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SearchByEntryControllerTest extends TestCase {
    use RefreshDatabase;

    protected $user;

    protected function setUp(): void {
        parent::setUp();

        // 創建測試用戶
        $this->user = User::factory()->create([
            'is_active' => 1,
        ]);

        // 創建測試數據表
        $this->createTestTables();
        $this->seedTestData();
    }

    /**
     * 創建測試所需的數據表
     */
    protected function createTestTables(): void {
        // 禁用外鍵約束檢查
        DB::statement('PRAGMA foreign_keys = OFF');

        // ENTRY_TYPES 表
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

        // ENTRY_CODES 表
        DB::statement('
            CREATE TABLE IF NOT EXISTS ENTRY_CODES (
                c_entry_code SMALLINT NOT NULL PRIMARY KEY,
                c_entry_desc VARCHAR(255),
                c_entry_desc_chn VARCHAR(255)
            )
        ');

        // ENTRY_CODE_TYPE_REL 表
        DB::statement('
            CREATE TABLE IF NOT EXISTS ENTRY_CODE_TYPE_REL (
                c_entry_code SMALLINT NOT NULL,
                c_entry_type VARCHAR(255) NOT NULL,
                PRIMARY KEY (c_entry_code, c_entry_type)
            )
        ');

        // ENTRY_DATA 表
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

        // BIOG_MAIN 表
        DB::statement('
            CREATE TABLE IF NOT EXISTS BIOG_MAIN (
                c_personid INT NOT NULL PRIMARY KEY,
                c_name VARCHAR(255),
                c_name_chn VARCHAR(255),
                c_index_year INT
            )
        ');
    }

    /**
     * 填充測試數據
     */
    protected function seedTestData(): void {
        // 插入入仕類型
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

        // 插入入仕代碼
        DB::table('ENTRY_CODES')->insert([
            [
                'c_entry_code' => 1,
                'c_entry_desc' => 'Entry Code 1',
                'c_entry_desc_chn' => '入仕代碼一',
            ],
            [
                'c_entry_code' => 2,
                'c_entry_desc' => 'Entry Code 2',
                'c_entry_desc_chn' => '入仕代碼二',
            ],
        ]);

        // 插入代碼類型關聯
        DB::table('ENTRY_CODE_TYPE_REL')->insert([
            ['c_entry_code' => 1, 'c_entry_type' => 'TYPE1'],
            ['c_entry_code' => 2, 'c_entry_type' => 'TYPE2'],
        ]);

        // 插入人物數據
        DB::table('BIOG_MAIN')->insert([
            [
                'c_personid' => 1,
                'c_name' => 'Test Person 1',
                'c_name_chn' => '測試人物一',
                'c_index_year' => 1000,
            ],
            [
                'c_personid' => 2,
                'c_name' => 'Test Person 2',
                'c_name_chn' => '測試人物二',
                'c_index_year' => 1100,
            ],
        ]);

        // 插入入仕數據
        DB::table('ENTRY_DATA')->insert([
            [
                'c_personid' => 1,
                'c_entry_code' => 1,
                'c_sequence' => 1,
                'c_year' => 1020,
                'c_entry_addr_id' => 100,
                'c_kin_code' => 0,
                'c_kin_id' => 0,
                'c_assoc_code' => 0,
                'c_assoc_id' => 0,
                'c_inst_code' => 0,
                'c_inst_name_code' => 0,
            ],
            [
                'c_personid' => 2,
                'c_entry_code' => 2,
                'c_sequence' => 1,
                'c_year' => 1120,
                'c_entry_addr_id' => 200,
                'c_kin_code' => 0,
                'c_kin_id' => 0,
                'c_assoc_code' => 0,
                'c_assoc_id' => 0,
                'c_inst_code' => 0,
                'c_inst_name_code' => 0,
            ],
        ]);
    }

    /**
     * 測試主頁面是否正常顯示（需要登入）
     */
    #[Test]
    public function test_index_requires_authentication(): void {
        $response = $this->get(route('search-by.entry.index'));
        $response->assertRedirect(route('login'));
    }

    /**
     * 測試登入用戶可以訪問主頁面
     */
    #[Test]
    public function test_authenticated_user_can_access_index(): void {
        $response = $this->actingAs($this->user)->get(route('search-by.entry.index'));
        $response->assertStatus(200);
        $response->assertViewIs('search-by.entry.index');
    }

    /**
     * 測試獲取入仕類型 API
     */
    #[Test]
    public function test_can_get_entry_types(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('search-by.entry.types'));

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'c_entry_type',
                        'c_entry_type_desc',
                        'c_entry_type_desc_chn',
                        'c_entry_type_parent_id',
                        'c_entry_type_level',
                        'c_entry_type_sortorder',
                    ],
                ],
            ]);

        $this->assertCount(2, $response->json('data'));
    }

    /**
     * 測試獲取入仕代碼 API（無類型過濾）
     */
    #[Test]
    public function test_can_get_all_entry_codes(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('search-by.entry.codes'));

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'c_entry_code',
                        'c_entry_desc',
                        'c_entry_desc_chn',
                    ],
                ],
            ]);

        $this->assertCount(2, $response->json('data'));
    }

    /**
     * 測試根據類型獲取入仕代碼 API
     */
    #[Test]
    public function test_can_get_entry_codes_by_type(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('search-by.entry.codes', ['type_id' => 'TYPE1']));

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals(1, $data[0]['c_entry_code']);
    }

    /**
     * 測試搜索功能（基本搜索）
     */
    #[Test]
    public function test_can_search_by_entry_codes(): void {
        $response = $this->actingAs($this->user)
            ->get(route('search-by.entry.search', [
                'entry_codes' => [1],
            ]));

        $response->assertStatus(200);
        $response->assertViewIs('search-by.entry.results');
        $response->assertViewHas('results');

        $results = $response->viewData('results');
        $this->assertCount(1, $results);
        $this->assertEquals(1, $results[0]->c_personid);
    }

    /**
     * 測試搜索功能（年份範圍過濾）
     */
    #[Test]
    public function test_can_search_with_year_range(): void {
        $response = $this->actingAs($this->user)
            ->get(route('search-by.entry.search', [
                'entry_codes' => [1, 2],
                'year_from' => 1100,
                'year_to' => 1200,
            ]));

        $response->assertStatus(200);
        $results = $response->viewData('results');
        $this->assertCount(1, $results);
        $this->assertEquals(2, $results[0]->c_personid);
        $this->assertEquals(1120, $results[0]->c_year);
    }

    /**
     * 測試搜索功能（地址過濾）
     */
    #[Test]
    public function test_can_search_with_address_filter(): void {
        $response = $this->actingAs($this->user)
            ->get(route('search-by.entry.search', [
                'entry_codes' => [1, 2],
                'addr_id' => 100,
            ]));

        $response->assertStatus(200);
        $results = $response->viewData('results');
        $this->assertCount(1, $results);
        $this->assertEquals(1, $results[0]->c_personid);
    }

    /**
     * 測試搜索驗證（entry_codes 必須為陣列）
     */
    #[Test]
    public function test_search_validation_requires_entry_codes_as_array(): void {
        $response = $this->actingAs($this->user)
            ->get(route('search-by.entry.search', [
                'entry_codes' => 'invalid',
            ]));

        // GET 請求驗證失敗會返回 302 重定向
        $response->assertStatus(302);
    }

    /**
     * 測試搜索驗證（年份必須為整數）
     */
    #[Test]
    public function test_search_validation_requires_integer_years(): void {
        $response = $this->actingAs($this->user)
            ->get(route('search-by.entry.search', [
                'entry_codes' => [1],
                'year_from' => 'invalid',
            ]));

        // GET 請求驗證失敗會返回 302 重定向
        $response->assertStatus(302);
    }
}
