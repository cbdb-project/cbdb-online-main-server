<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SearchByEntryControllerTest extends TestCase {
    protected User $user;

    protected function setUp(): void {
        parent::setUp();

        $this->createTestTables();
        $this->user = User::factory()->create([
            'is_active' => 1,
            'is_admin' => 3,
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
                c_entry_type VARCHAR(255) PRIMARY KEY,
                c_entry_type_desc VARCHAR(255),
                c_entry_type_desc_chn VARCHAR(255),
                c_entry_type_parent_id VARCHAR(255),
                c_entry_type_level DOUBLE,
                c_entry_type_sortorder DOUBLE
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS ENTRY_CODES (
                c_entry_code INTEGER PRIMARY KEY,
                c_entry_desc VARCHAR(255),
                c_entry_desc_chn VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS ENTRY_CODE_TYPE_REL (
                c_entry_code INTEGER NOT NULL,
                c_entry_type VARCHAR(255) NOT NULL,
                PRIMARY KEY (c_entry_code, c_entry_type)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS BIOG_MAIN (
                c_personid INTEGER PRIMARY KEY,
                c_name VARCHAR(255),
                c_name_chn VARCHAR(255),
                c_dy VARCHAR(255),
                c_index_year INTEGER,
                c_index_addr_id INTEGER,
                c_index_year_type_code VARCHAR(50),
                c_female SMALLINT
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS ENTRY_DATA (
                c_personid INTEGER NOT NULL,
                c_entry_code INTEGER NOT NULL,
                c_sequence INTEGER NOT NULL,
                c_year INTEGER,
                c_entry_addr_id INTEGER,
                c_entry_nh_id INTEGER,
                c_entry_nh_year INTEGER,
                c_entry_range VARCHAR(50),
                c_exam_rank VARCHAR(255),
                c_notes TEXT,
                c_posting_notes TEXT,
                c_parental_status_code VARCHAR(50),
                c_source INTEGER,
                PRIMARY KEY (c_personid, c_entry_code, c_sequence)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS DYNASTIES (
                c_dy VARCHAR(255) PRIMARY KEY,
                c_dynasty VARCHAR(255),
                c_dynasty_chn VARCHAR(255),
                c_start INTEGER,
                c_end INTEGER
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS ADDR_CODES (
                c_addr_id INTEGER PRIMARY KEY,
                c_name VARCHAR(255),
                c_name_chn VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS ALTNAME_DATA (
                c_personid INTEGER NOT NULL,
                c_alt_name VARCHAR(255),
                c_alt_name_chn VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS ADDR_BELONGS_DATA (
                c_addr_id INTEGER NOT NULL,
                c_belongs_to INTEGER NOT NULL
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS INDEXYEAR_TYPE_CODES (
                c_index_year_type_code VARCHAR(50) PRIMARY KEY,
                c_index_year_type_desc VARCHAR(255),
                c_index_year_type_hz VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS NIAN_HAO (
                c_nianhao_id INTEGER PRIMARY KEY,
                c_nianhao_chn VARCHAR(255),
                c_nianhao_pin VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS YEAR_RANGE_CODES (
                c_range_code VARCHAR(50) PRIMARY KEY,
                c_range VARCHAR(255),
                c_range_chn VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS PARENTAL_STATUS_CODES (
                c_parental_status_code VARCHAR(50) PRIMARY KEY,
                c_parental_status_desc VARCHAR(255),
                c_parental_status_desc_chn VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS TEXT_CODES (
                c_textid INTEGER PRIMARY KEY,
                c_title VARCHAR(255),
                c_title_chn VARCHAR(255)
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

        DB::table('DYNASTIES')->insert([
            ['c_dy' => 'DY1', 'c_dynasty' => 'Dynasty 1', 'c_dynasty_chn' => '朝代一', 'c_start' => 900, 'c_end' => 1050],
            ['c_dy' => 'DY2', 'c_dynasty' => 'Dynasty 2', 'c_dynasty_chn' => '朝代二', 'c_start' => 1051, 'c_end' => 1200],
        ]);

        DB::table('ADDR_CODES')->insert([
            ['c_addr_id' => 10, 'c_name' => 'Index Addr 1', 'c_name_chn' => '索引地址一'],
            ['c_addr_id' => 20, 'c_name' => 'Index Addr 2', 'c_name_chn' => '索引地址二'],
            ['c_addr_id' => 100, 'c_name' => 'Entry Addr 1', 'c_name_chn' => '入仕地一'],
            ['c_addr_id' => 101, 'c_name' => 'Entry Sub Addr 1', 'c_name_chn' => '入仕地下屬一'],
            ['c_addr_id' => 200, 'c_name' => 'Entry Addr 2', 'c_name_chn' => '入仕地二'],
        ]);

        DB::table('BIOG_MAIN')->insert([
            [
                'c_personid' => 1,
                'c_name' => 'Test Person 1',
                'c_name_chn' => '測試人物一',
                'c_dy' => 'DY1',
                'c_index_year' => 1000,
                'c_index_addr_id' => 10,
                'c_index_year_type_code' => 'A',
                'c_female' => 0,
            ],
            [
                'c_personid' => 2,
                'c_name' => 'Test Person 2',
                'c_name_chn' => '測試人物二',
                'c_dy' => 'DY2',
                'c_index_year' => 1100,
                'c_index_addr_id' => 20,
                'c_index_year_type_code' => 'B',
                'c_female' => 1,
            ],
        ]);

        DB::table('ALTNAME_DATA')->insert([
            ['c_personid' => 1, 'c_alt_name' => 'Alias A', 'c_alt_name_chn' => '別名甲'],
        ]);

        DB::table('ADDR_BELONGS_DATA')->insert([
            ['c_addr_id' => 101, 'c_belongs_to' => 100],
        ]);

        DB::table('INDEXYEAR_TYPE_CODES')->insert([
            ['c_index_year_type_code' => 'A', 'c_index_year_type_desc' => 'Approximate', 'c_index_year_type_hz' => '約年'],
            ['c_index_year_type_code' => 'B', 'c_index_year_type_desc' => 'Exact', 'c_index_year_type_hz' => '實年'],
        ]);

        DB::table('NIAN_HAO')->insert([
            ['c_nianhao_id' => 1, 'c_nianhao_chn' => '景祐', 'c_nianhao_pin' => 'Jingyou'],
        ]);

        DB::table('YEAR_RANGE_CODES')->insert([
            ['c_range_code' => 'R', 'c_range' => 'Range', 'c_range_chn' => '約'],
        ]);

        DB::table('PARENTAL_STATUS_CODES')->insert([
            ['c_parental_status_code' => 'P1', 'c_parental_status_desc' => 'Parent living', 'c_parental_status_desc_chn' => '父母在'],
        ]);

        DB::table('TEXT_CODES')->insert([
            ['c_textid' => 500, 'c_title' => 'Source A', 'c_title_chn' => '來源甲'],
        ]);

        DB::table('ENTRY_DATA')->insert([
            [
                'c_personid' => 1,
                'c_entry_code' => 1,
                'c_sequence' => 1,
                'c_year' => 1020,
                'c_entry_addr_id' => 100,
                'c_entry_nh_id' => 1,
                'c_entry_nh_year' => 3,
                'c_entry_range' => 'R',
                'c_exam_rank' => '甲',
                'c_notes' => '備註一',
                'c_posting_notes' => '任官備註一',
                'c_parental_status_code' => 'P1',
                'c_source' => 500,
            ],
            [
                'c_personid' => 1,
                'c_entry_code' => 1,
                'c_sequence' => 2,
                'c_year' => 1025,
                'c_entry_addr_id' => 101,
                'c_entry_nh_id' => 1,
                'c_entry_nh_year' => 4,
                'c_entry_range' => 'R',
                'c_exam_rank' => '甲',
                'c_notes' => '備註二',
                'c_posting_notes' => '任官備註二',
                'c_parental_status_code' => 'P1',
                'c_source' => 500,
            ],
            [
                'c_personid' => 2,
                'c_entry_code' => 2,
                'c_sequence' => 1,
                'c_year' => 1120,
                'c_entry_addr_id' => 200,
                'c_entry_nh_id' => null,
                'c_entry_nh_year' => null,
                'c_entry_range' => null,
                'c_exam_rank' => '乙',
                'c_notes' => '備註三',
                'c_posting_notes' => null,
                'c_parental_status_code' => null,
                'c_source' => null,
            ],
        ]);
    }

    #[Test]
    public function test_can_get_entry_types(): void {
        $response = $this->actingAs($this->user)->getJson(route('app.search-by.entry.types'));

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function test_can_get_entry_codes_by_type(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.search-by.entry.codes', ['type_id' => 'TYPE1']));

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonPath('data.0.c_entry_code', 1);
    }

    #[Test]
    public function test_can_search_places(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.search-by.entry.places', ['q' => '入仕地']));

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $this->assertGreaterThanOrEqual(2, count($response->json('data')));
    }

    #[Test]
    public function test_query_requires_at_least_one_condition(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.search-by.entry.query'));

        $response->assertStatus(422)
            ->assertJsonPath('errors.filters.0', '請至少設定一項搜尋條件。');
    }

    #[Test]
    public function test_query_can_match_person_keyword_from_alt_name(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.search-by.entry.query', [
                'person_keyword' => '別名甲',
            ]));

        $response->assertOk()
            ->assertJsonPath('data.summary.record_count', 2)
            ->assertJsonPath('data.summary.person_count', 1)
            ->assertJsonPath('data.records.data.0.c_personid', 1);
    }

    #[Test]
    public function test_query_can_include_subordinate_places(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.search-by.entry.query', [
                'place_ids' => [100],
                'include_sub_units' => true,
            ]));

        $response->assertOk()
            ->assertJsonPath('data.summary.record_count', 2)
            ->assertJsonPath('data.summary.person_count', 1);
    }

    #[Test]
    public function test_query_can_filter_by_single_dynasty_checkbox(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.search-by.entry.query', [
                'dynasty_codes' => ['DY2'],
            ]));

        $response->assertOk()
            ->assertJsonPath('data.summary.record_count', 1)
            ->assertJsonPath('data.people.data.0.c_personid', 2);
    }

    #[Test]
    public function test_query_can_filter_by_multiple_dynasties(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.search-by.entry.query', [
                'dynasty_codes' => ['DY1', 'DY2'],
            ]));

        $response->assertOk()
            ->assertJsonPath('data.summary.record_count', 3)
            ->assertJsonPath('data.summary.person_count', 2);
    }
}
