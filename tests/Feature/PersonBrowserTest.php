<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PersonBrowserTest extends TestCase {
    protected User $user;

    protected function setUp(): void {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\PrometheusMetrics::class);

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
            CREATE TABLE IF NOT EXISTS BIOG_MAIN (
                c_personid INTEGER PRIMARY KEY,
                c_name VARCHAR(255),
                c_name_chn VARCHAR(255),
                c_name_proper VARCHAR(255),
                c_name_rm VARCHAR(255),
                c_surname VARCHAR(255),
                c_surname_chn VARCHAR(255),
                c_mingzi VARCHAR(255),
                c_mingzi_chn VARCHAR(255),
                c_female SMALLINT,
                c_birthyear SMALLINT,
                c_deathyear SMALLINT,
                c_index_year INT,
                c_index_year_type_code VARCHAR(255),
                c_index_addr_id INT,
                c_index_addr_type_code INT,
                c_dy SMALLINT,
                c_ethnicity_code SMALLINT,
                c_household_status_code SMALLINT,
                c_choronym_code SMALLINT,
                c_notes TEXT,
                c_self_bio SMALLINT,
                c_by_nh_code SMALLINT,
                c_by_nh_year SMALLINT,
                c_by_range SMALLINT,
                c_by_intercalary SMALLINT,
                c_by_month SMALLINT,
                c_by_day SMALLINT,
                c_by_day_gz SMALLINT,
                c_dy_nh_code SMALLINT,
                c_dy_nh_year SMALLINT,
                c_dy_range SMALLINT,
                c_dy_intercalary SMALLINT,
                c_dy_month SMALLINT,
                c_dy_day SMALLINT,
                c_dy_day_gz SMALLINT,
                c_death_age SMALLINT,
                c_death_age_range SMALLINT,
                c_fl_earliest_year SMALLINT,
                c_fl_ey_nh_code SMALLINT,
                c_fl_ey_nh_year SMALLINT,
                c_fl_ey_notes TEXT,
                c_fl_latest_year SMALLINT,
                c_fl_ly_nh_code SMALLINT,
                c_fl_ly_nh_year SMALLINT,
                c_fl_ly_notes TEXT,
                c_index_year_source_id INT,
                c_surname_proper VARCHAR(255),
                c_mingzi_proper VARCHAR(255),
                c_surname_rm VARCHAR(255),
                c_mingzi_rm VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS DYNASTIES (
                c_dy INTEGER PRIMARY KEY,
                c_dynasty VARCHAR(255),
                c_dynasty_chn VARCHAR(255)
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
                c_personid INT,
                c_sequence INT,
                c_alt_name_chn VARCHAR(255),
                c_alt_name VARCHAR(255),
                c_alt_name_type_code INT,
                c_source INT,
                c_pages VARCHAR(255),
                c_notes TEXT
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS ALTNAME_CODES (
                c_name_type_code INTEGER PRIMARY KEY,
                c_name_type_desc VARCHAR(255),
                c_name_type_desc_chn VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS BIOG_ADDR_DATA (
                c_personid INT,
                c_addr_id INT,
                c_addr_type INT,
                c_firstyear SMALLINT,
                c_lastyear SMALLINT,
                c_sequence INT,
                c_notes TEXT
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS BIOG_ADDR_CODES (
                c_addr_type INTEGER PRIMARY KEY,
                c_addr_desc VARCHAR(255),
                c_addr_desc_chn VARCHAR(255),
                c_index_addr_default_rank INT
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS BIOG_TEXT_DATA (
                c_personid INT,
                c_textid INT,
                c_role_id INT
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS TEXT_CODES (
                c_textid INTEGER PRIMARY KEY,
                c_title VARCHAR(255),
                c_title_chn VARCHAR(255),
                c_text_year INT
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS TEXT_ROLE_CODES (
                c_role_id INTEGER PRIMARY KEY,
                c_role_desc VARCHAR(255),
                c_role_desc_chn VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS BIOG_SOURCE_DATA (
                c_personid INT,
                c_textid INT,
                c_pages VARCHAR(255),
                c_notes TEXT,
                c_main_source SMALLINT,
                c_self_bio SMALLINT
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS ENTRY_DATA (
                c_personid INT,
                c_entry_code INT,
                c_sequence INT,
                c_year INT,
                c_kin_code INT,
                c_kin_id INT,
                c_assoc_code INT,
                c_assoc_id INT,
                c_inst_code INT,
                c_inst_name_code INT
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
            CREATE TABLE IF NOT EXISTS EVENTS_DATA (
                c_personid INT,
                c_event_code INT,
                c_sequence INT,
                c_year INT,
                c_month INT,
                c_day INT,
                c_source INT,
                c_pages VARCHAR(255),
                c_notes TEXT
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS EVENT_CODES (
                c_event_code INTEGER PRIMARY KEY,
                c_event_name VARCHAR(255),
                c_event_name_chn VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS STATUS_DATA (
                c_personid INT,
                c_status_code INT,
                c_sequence INT,
                c_firstyear SMALLINT,
                c_lastyear SMALLINT,
                c_source INT,
                c_pages VARCHAR(255),
                c_notes TEXT
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS STATUS_CODES (
                c_status_code INTEGER PRIMARY KEY,
                c_status_desc VARCHAR(255),
                c_status_desc_chn VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS ASSOC_DATA (
                c_personid INT,
                c_assoc_code INT,
                c_assoc_id INT,
                c_kin_code INT,
                c_kin_id INT,
                c_assoc_kin_code INT,
                c_assoc_kin_id INT,
                c_text_title VARCHAR(255),
                c_sequence INT,
                c_assoc_first_year INT,
                c_assoc_last_year INT,
                c_source INT,
                c_pages VARCHAR(255),
                c_notes TEXT
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS ASSOC_CODES (
                c_assoc_code INTEGER PRIMARY KEY,
                c_assoc_desc VARCHAR(255),
                c_assoc_desc_chn VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS KIN_DATA (
                c_personid INT,
                c_kin_id INT,
                c_kin_code INT,
                c_source INT,
                c_pages VARCHAR(255),
                c_notes TEXT
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS KINSHIP_CODES (
                c_kincode INTEGER PRIMARY KEY,
                c_kinrel VARCHAR(255),
                c_kinrel_chn VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS POSSESSION_DATA (
                c_personid INT,
                c_possession_record_id INT,
                c_sequence INT,
                c_possession_act_code INT,
                c_possession_desc VARCHAR(255),
                c_possession_desc_chn VARCHAR(255),
                c_quantity VARCHAR(255),
                c_possession_yr SMALLINT,
                c_source INT,
                c_pages VARCHAR(255),
                c_notes TEXT
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS POSSESSION_ACT_CODES (
                c_possession_act_code INTEGER PRIMARY KEY,
                c_possession_act_desc VARCHAR(255),
                c_possession_act_desc_chn VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS BIOG_INST_DATA (
                c_personid INT,
                c_inst_name_code INT,
                c_bi_role_code INT,
                c_inst_code INT,
                c_source INT,
                c_pages VARCHAR(255),
                c_notes TEXT
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS BIOG_INST_CODES (
                c_bi_role_code INTEGER PRIMARY KEY,
                c_bi_role_desc VARCHAR(255),
                c_bi_role_chn VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS SOCIAL_INSTITUTION_NAME_CODES (
                c_inst_name_code INTEGER PRIMARY KEY,
                c_inst_name_hz VARCHAR(255),
                c_inst_name_py VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS POSTED_TO_OFFICE_DATA (
                c_personid INT,
                c_office_id INT,
                c_posting_id INT,
                c_sequence INT,
                c_firstyear SMALLINT,
                c_lastyear SMALLINT,
                c_source INT,
                c_pages VARCHAR(255),
                c_notes TEXT
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS OFFICE_CODES (
                c_office_id INTEGER PRIMARY KEY,
                c_office_chn VARCHAR(255),
                c_office_trans VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS POSTED_TO_ADDR_DATA (
                c_personid INT,
                c_addr_id INT,
                c_posting_id INT,
                c_office_id INT
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS CBDB__NAME_FTS (
                search_term VARCHAR(255),
                c_personid INT
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS INDEXYEAR_TYPE_CODES (
                c_index_year_type_code VARCHAR(10) PRIMARY KEY,
                c_index_year_type_desc VARCHAR(255),
                c_index_year_type_hz VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS YEAR_RANGE_CODES (
                c_range_code INTEGER PRIMARY KEY,
                c_approx VARCHAR(255),
                c_approx_chn VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS GANZHI_CODES (
                c_ganzhi_code INTEGER PRIMARY KEY,
                c_ganzhi_chn VARCHAR(255),
                c_ganzhi_py VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS HOUSEHOLD_STATUS_CODES (
                c_household_status_code INTEGER PRIMARY KEY,
                c_household_status_desc VARCHAR(255),
                c_household_status_desc_chn VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS NIAN_HAO (
                c_nianhao_id INTEGER PRIMARY KEY,
                c_nianhao VARCHAR(255),
                c_nianhao_chn VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS ETHNICITY_TRIBE_CODES (
                c_ethnicity_code INTEGER PRIMARY KEY,
                c_name VARCHAR(255),
                c_name_chn VARCHAR(255)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS CHORONYM_CODES (
                c_choronym_code INTEGER PRIMARY KEY,
                c_choronym_desc VARCHAR(255),
                c_choronym_chn VARCHAR(255)
            )
        ');
    }

    protected function seedTestData(): void {
        DB::table('DYNASTIES')->insert([
            ['c_dy' => 1, 'c_dynasty' => 'Tang', 'c_dynasty_chn' => '唐'],
            ['c_dy' => 2, 'c_dynasty' => 'Song', 'c_dynasty_chn' => '宋'],
        ]);

        DB::table('ADDR_CODES')->insert([
            ['c_addr_id' => 100, 'c_name' => 'Chang An', 'c_name_chn' => '長安'],
            ['c_addr_id' => 101, 'c_name' => 'Luoyang', 'c_name_chn' => '洛陽'],
        ]);

        DB::table('ETHNICITY_TRIBE_CODES')->insert([
            ['c_ethnicity_code' => 10, 'c_name' => 'Han', 'c_name_chn' => '漢'],
        ]);

        DB::table('CHORONYM_CODES')->insert([
            ['c_choronym_code' => 20, 'c_choronym_desc' => 'Longxi', 'c_choronym_chn' => '隴西'],
        ]);

        DB::table('OFFICE_CODES')->insert([
            ['c_office_id' => 30, 'c_office_chn' => '翰林學士', 'c_office_trans' => 'Hanlin Academician'],
        ]);

        DB::table('INDEXYEAR_TYPE_CODES')->insert([
            ['c_index_year_type_code' => '01', 'c_index_year_type_desc' => 'Approximate year', 'c_index_year_type_hz' => '約年'],
        ]);

        DB::table('YEAR_RANGE_CODES')->insert([
            ['c_range_code' => 1, 'c_approx' => 'circa', 'c_approx_chn' => '約'],
            ['c_range_code' => 2, 'c_approx' => 'estimated', 'c_approx_chn' => '估'],
        ]);

        DB::table('GANZHI_CODES')->insert([
            ['c_ganzhi_code' => 1, 'c_ganzhi_chn' => '甲子', 'c_ganzhi_py' => 'jia zi'],
            ['c_ganzhi_code' => 2, 'c_ganzhi_chn' => '乙丑', 'c_ganzhi_py' => 'yi chou'],
        ]);

        DB::table('HOUSEHOLD_STATUS_CODES')->insert([
            ['c_household_status_code' => 1, 'c_household_status_desc' => 'Registered', 'c_household_status_desc_chn' => '編戶'],
        ]);

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 1,
            'c_name' => 'Li Bai',
            'c_name_chn' => '李白',
            'c_name_proper' => 'Li Bai',
            'c_name_rm' => 'Li Bai',
            'c_surname' => 'Li',
            'c_surname_chn' => '李',
            'c_mingzi' => 'Bai',
            'c_mingzi_chn' => '白',
            'c_female' => 0,
            'c_birthyear' => 701,
            'c_by_nh_code' => 1,
            'c_by_nh_year' => 2,
            'c_by_range' => 1,
            'c_by_intercalary' => 0,
            'c_by_month' => 3,
            'c_by_day' => 15,
            'c_by_day_gz' => 1,
            'c_deathyear' => 762,
            'c_dy_nh_code' => 2,
            'c_dy_nh_year' => 1,
            'c_dy_range' => 2,
            'c_dy_intercalary' => 1,
            'c_dy_month' => 8,
            'c_dy_day' => 9,
            'c_dy_day_gz' => 2,
            'c_death_age' => 61,
            'c_death_age_range' => 1,
            'c_fl_earliest_year' => 725,
            'c_fl_ey_nh_code' => 1,
            'c_fl_ey_nh_year' => 5,
            'c_fl_ey_notes' => '初見活動年份',
            'c_fl_latest_year' => 762,
            'c_fl_ly_nh_code' => 2,
            'c_fl_ly_nh_year' => 1,
            'c_fl_ly_notes' => '最後活動年份',
            'c_index_year' => 742,
            'c_index_year_type_code' => '01',
            'c_index_year_source_id' => 2,
            'c_dy' => 1,
            'c_index_addr_id' => 100,
            'c_ethnicity_code' => 10,
            'c_household_status_code' => 1,
            'c_choronym_code' => 20,
            'c_notes' => '人物總註',
        ]);

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 2,
            'c_name' => 'Du Fu',
            'c_name_chn' => '杜甫',
            'c_name_proper' => 'Du Fu',
            'c_name_rm' => 'Du Fu',
            'c_surname' => 'Du',
            'c_surname_chn' => '杜',
            'c_mingzi' => 'Fu',
            'c_mingzi_chn' => '甫',
            'c_female' => 0,
            'c_birthyear' => 712,
            'c_deathyear' => 770,
            'c_index_year' => 755,
            'c_dy' => 1,
            'c_index_addr_id' => null,
            'c_ethnicity_code' => null,
            'c_household_status_code' => null,
            'c_choronym_code' => null,
        ]);

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 3,
            'c_name' => 'Su Shi',
            'c_name_chn' => '蘇軾',
            'c_name_proper' => 'Su Shi',
            'c_name_rm' => 'Su Shi',
            'c_surname' => 'Su',
            'c_surname_chn' => '蘇',
            'c_mingzi' => 'Shi',
            'c_mingzi_chn' => '軾',
            'c_female' => 0,
            'c_birthyear' => 1037,
            'c_deathyear' => 1101,
            'c_index_year' => 1057,
            'c_dy' => 2,
            'c_index_addr_id' => null,
            'c_ethnicity_code' => null,
            'c_household_status_code' => null,
            'c_choronym_code' => null,
        ]);

        DB::table('ALTNAME_DATA')->insert([
            ['c_personid' => 1, 'c_sequence' => 1, 'c_alt_name_chn' => '太白', 'c_alt_name' => 'Taibai', 'c_alt_name_type_code' => 4, 'c_source' => null, 'c_pages' => null, 'c_notes' => null],
            ['c_personid' => 1, 'c_sequence' => 2, 'c_alt_name_chn' => '青蓮居士', 'c_alt_name' => 'Qinglian Jushi', 'c_alt_name_type_code' => 5, 'c_source' => null, 'c_pages' => null, 'c_notes' => null],
            ['c_personid' => 3, 'c_sequence' => 1, 'c_alt_name_chn' => '子瞻', 'c_alt_name' => 'Zizhan', 'c_alt_name_type_code' => 4, 'c_source' => null, 'c_pages' => null, 'c_notes' => null],
            ['c_personid' => 3, 'c_sequence' => 2, 'c_alt_name_chn' => '東坡居士', 'c_alt_name' => 'Dongpo Jushi', 'c_alt_name_type_code' => 5, 'c_source' => null, 'c_pages' => null, 'c_notes' => null],
        ]);

        DB::table('ALTNAME_CODES')->insert([
            ['c_name_type_code' => 4, 'c_name_type_desc' => 'Zi', 'c_name_type_desc_chn' => '字'],
            ['c_name_type_code' => 5, 'c_name_type_desc' => 'Hao', 'c_name_type_desc_chn' => '號'],
        ]);

        DB::table('CBDB__NAME_FTS')->insert([
            ['search_term' => '李白', 'c_personid' => 1],
            ['search_term' => '杜甫', 'c_personid' => 2],
            ['search_term' => '蘇軾', 'c_personid' => 3],
            ['search_term' => '太白', 'c_personid' => 1],
        ]);

        DB::table('BIOG_SOURCE_DATA')->insert([
            ['c_personid' => 1, 'c_textid' => 1, 'c_pages' => '10-20', 'c_notes' => 'main source', 'c_main_source' => 1, 'c_self_bio' => 0],
        ]);

        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            [
                'c_personid' => 1,
                'c_office_id' => 30,
                'c_posting_id' => 1,
                'c_sequence' => 1,
                'c_firstyear' => 744,
                'c_lastyear' => 745,
                'c_source' => null,
                'c_pages' => '12a',
                'c_notes' => 'served briefly',
            ],
        ]);

        DB::table('POSTED_TO_ADDR_DATA')->insert([
            ['c_personid' => 1, 'c_addr_id' => 101, 'c_posting_id' => 1, 'c_office_id' => 30],
        ]);

        DB::table('TEXT_CODES')->insert([
            ['c_textid' => 1, 'c_title' => 'New Tang Book', 'c_title_chn' => '新唐書', 'c_text_year' => 1060],
        ]);

        DB::table('TEXT_ROLE_CODES')->insert([
            ['c_role_id' => 1, 'c_role_desc' => 'Author', 'c_role_desc_chn' => '作者'],
        ]);

        DB::table('BIOG_TEXT_DATA')->insert([
            ['c_personid' => 1, 'c_textid' => 1, 'c_role_id' => 1],
        ]);

        DB::table('ENTRY_DATA')->insert([
            [
                'c_personid' => 1,
                'c_entry_code' => 1,
                'c_sequence' => 1,
                'c_year' => 742,
                'c_kin_code' => null,
                'c_kin_id' => null,
                'c_assoc_code' => null,
                'c_assoc_id' => null,
                'c_inst_code' => null,
                'c_inst_name_code' => null,
            ],
        ]);

        DB::table('ENTRY_CODES')->insert([
            ['c_entry_code' => 1, 'c_entry_desc' => 'Imperial Decree', 'c_entry_desc_chn' => '詔除'],
        ]);

        DB::table('EVENT_CODES')->insert([
            ['c_event_code' => 1, 'c_event_name' => 'Banquet', 'c_event_name_chn' => '宴集'],
        ]);

        DB::table('EVENTS_DATA')->insert([
            [
                'c_personid' => 1,
                'c_event_code' => 1,
                'c_sequence' => 1,
                'c_year' => 744,
                'c_month' => 3,
                'c_day' => 15,
                'c_source' => 1,
                'c_pages' => '22b',
                'c_notes' => 'court banquet',
            ],
        ]);

        DB::table('POSSESSION_ACT_CODES')->insert([
            ['c_possession_act_code' => 1, 'c_possession_act_desc' => 'Owned', 'c_possession_act_desc_chn' => '擁有'],
        ]);

        DB::table('POSSESSION_DATA')->insert([
            [
                'c_personid' => 1,
                'c_possession_record_id' => 1,
                'c_sequence' => 1,
                'c_possession_act_code' => 1,
                'c_possession_desc' => 'Books',
                'c_possession_desc_chn' => '書籍',
                'c_quantity' => '20',
                'c_possession_yr' => 744,
                'c_source' => 1,
                'c_pages' => '31a',
                'c_notes' => 'personal library',
            ],
        ]);

        DB::table('BIOG_INST_CODES')->insert([
            ['c_bi_role_code' => 1, 'c_bi_role_desc' => 'Member', 'c_bi_role_chn' => '成員'],
        ]);

        DB::table('SOCIAL_INSTITUTION_NAME_CODES')->insert([
            ['c_inst_name_code' => 1, 'c_inst_name_hz' => '青蓮詩社', 'c_inst_name_py' => 'Qinglian Poetry Society'],
        ]);

        DB::table('BIOG_INST_DATA')->insert([
            [
                'c_personid' => 1,
                'c_inst_name_code' => 1,
                'c_inst_code' => 1,
                'c_bi_role_code' => 1,
                'c_source' => 1,
                'c_pages' => '41b',
                'c_notes' => 'local circle',
            ],
        ]);

        DB::table('KIN_DATA')->insert([
            ['c_personid' => 1, 'c_kin_id' => 2, 'c_kin_code' => 1, 'c_source' => null, 'c_pages' => null, 'c_notes' => 'friend'],
        ]);

        DB::table('KINSHIP_CODES')->insert([
            ['c_kincode' => 1, 'c_kinrel' => 'Friend', 'c_kinrel_chn' => '友'],
        ]);
    }

    // ───────────────────────────────────
    // Inertia page
    // ───────────────────────────────────

    #[Test]
    public function test_person_browser_requires_authentication(): void {
        $response = $this->get(route('app.person-browser.index'));
        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function test_person_browser_returns_inertia_page(): void {
        $response = $this->actingAs($this->user)->get(route('app.person-browser.index'));

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('PersonBrowser/Index')
                ->has('tabKeys')
                ->has('searchEndpoint')
                ->has('summaryEndpoint')
                ->has('tabEndpoint')
                ->where('mutateEndpoint', route('api.v2.mutate.web', [], false))
                ->where('pinyinEndpoint', '/api/select/search/pinyin')
                ->where('canEditBasicInfo', true)
        );
    }

    #[Test]
    public function test_person_browser_initial_props_structure(): void {
        $response = $this->actingAs($this->user)->get(route('app.person-browser.index'));

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('PersonBrowser/Index')
                ->where('canEditBasicInfo', true)
                ->where('initialPersonId', null)
                ->where('initialKeyword', '')
                ->where('initialTab', 'basic_info')
                ->where('initialPage', 1)
                ->has('tabKeys', 13)
        );
    }

    #[Test]
    public function test_person_browser_accepts_initial_query_params(): void {
        $response = $this->actingAs($this->user)->get(
            route('app.person-browser.index') . '?person_id=1&keyword=李白&tab=alt_names&page=3'
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('PersonBrowser/Index')
                ->where('initialPersonId', 1)
                ->where('initialKeyword', '李白')
                ->where('initialTab', 'alt_names')
                ->where('initialPage', 3)
        );
    }

    // ───────────────────────────────────
    // Search
    // ───────────────────────────────────

    #[Test]
    public function test_search_requires_authentication(): void {
        $response = $this->getJson(route('app.person-browser.search'));
        $response->assertStatus(401);
    }

    #[Test]
    public function test_search_returns_results(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.search'));

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [['c_personid', 'c_name_chn', 'c_name']],
            'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
        $response->assertJsonPath('pagination.total', 3);
        $response->assertJsonPath('data.0.c_personid', 3);
        $response->assertJsonPath('data.1.c_personid', 2);
        $response->assertJsonPath('data.2.c_personid', 1);
    }

    #[Test]
    public function test_search_by_person_id(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.search') . '?q=1');

        $response->assertOk();
        $response->assertJsonPath('pagination.total', 1);
        $response->assertJsonPath('data.0.c_personid', 1);
        $response->assertJsonPath('data.0.c_name_chn', '李白');
    }

    #[Test]
    public function test_search_by_chinese_name(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.search') . '?q=李白');

        $response->assertOk();
        $response->assertJsonPath('data.0.c_personid', 1);
    }

    #[Test]
    public function test_search_by_alt_name_via_fts(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.search') . '?q=太白');

        $response->assertOk();
        $response->assertJsonPath('data.0.c_personid', 1);
    }

    // ───────────────────────────────────
    // Summary
    // ───────────────────────────────────

    #[Test]
    public function test_summary_requires_authentication(): void {
        $response = $this->getJson(route('app.person-browser.summary', ['personId' => 1]));
        $response->assertStatus(401);
    }

    #[Test]
    public function test_summary_returns_person_data(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.summary', ['personId' => 1]));

        $response->assertOk();
        $response->assertJsonStructure([
            'c_personid',
            'c_name_chn',
            'c_name',
            'gender',
            'c_birthyear',
            'c_deathyear',
            'dynasty_chn',
            'tab_counts',
        ]);
        $response->assertJsonPath('c_personid', 1);
        $response->assertJsonPath('c_name_chn', '李白');
        $response->assertJsonPath('gender', '男');
        $response->assertJsonPath('dynasty_chn', '唐');
        $response->assertJsonPath('alt_name_zi', '太白');
        $response->assertJsonPath('alt_name_hao', '青蓮居士');
    }

    #[Test]
    public function test_summary_invalid_person_returns_404(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.summary', ['personId' => 99999]));

        $response->assertStatus(404);
    }

    // ───────────────────────────────────
    // Tabs
    // ───────────────────────────────────

    #[Test]
    public function test_tab_requires_authentication(): void {
        $response = $this->getJson(route('app.person-browser.tab', ['personId' => 1, 'tabKey' => 'basic_info']));
        $response->assertStatus(401);
    }

    #[Test]
    public function test_tab_basic_info_returns_sections(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.tab', ['personId' => 1, 'tabKey' => 'basic_info']));

        $response->assertOk();
        $response->assertJsonStructure([
            'sections' => [['title', 'fields']],
            'form' => [
                'person_id',
                'fields' => [
                    'c_surname_chn',
                    'c_female',
                    'c_by_nh_code',
                    'c_index_year',
                    'c_notes',
                ],
            ],
        ]);
        $sections = collect($response->json('sections'))->keyBy('title');

        $this->assertSame('text', $response->json('form.fields.c_surname_chn.input'));
        $this->assertTrue($response->json('form.fields.c_female.editable'));
        $this->assertSame('enum', $response->json('form.fields.c_by_nh_code.input'));
        $this->assertFalse($response->json('form.fields.c_index_year.editable'));
        $this->assertFalse($response->json('form.fields.c_index_year.send_on_save'));

        $this->assertSame('漢', $this->basicInfoFieldValue($sections->get('基本屬性'), '族裔（中文）'));
        $this->assertSame('Han', $this->basicInfoFieldValue($sections->get('基本屬性'), '族裔（英文）'));
        $this->assertSame('隴西', $this->basicInfoFieldValue($sections->get('基本屬性'), '郡望（中文）'));
        $this->assertSame('Longxi', $this->basicInfoFieldValue($sections->get('基本屬性'), '郡望（英文）'));
        $this->assertSame('編戶', $this->basicInfoFieldValue($sections->get('基本屬性'), '戶籍（中文）'));

        $this->assertSame('約 / circa', $this->basicInfoFieldValue($sections->get('生卒年'), '出生年範圍'));
        $this->assertSame('甲子 / jia zi', $this->basicInfoFieldValue($sections->get('生卒年'), '出生日時干支'));
        $this->assertSame('約 / circa', $this->basicInfoFieldValue($sections->get('生卒年'), '享年範圍'));

        $this->assertSame('01', $this->basicInfoFieldValue($sections->get('指數資料'), 'Index Year Type'));
        $this->assertSame('約年', $this->basicInfoFieldValue($sections->get('指數資料'), 'Index Year Type（中文）'));
        $this->assertSame('Approximate year', $this->basicInfoFieldValue($sections->get('指數資料'), 'Index Year Type（英文）'));
        $this->assertSame('2 杜甫', $this->basicInfoFieldValue($sections->get('指數資料'), 'Index Year Source'));

        $this->assertSame(725, $this->basicInfoFieldValue($sections->get('活動年份'), '在世始年'));
        $this->assertSame('初見活動年份', $this->basicInfoFieldValue($sections->get('活動年份'), '在世始年註'));
        $this->assertSame('最後活動年份', $this->basicInfoFieldValue($sections->get('活動年份'), '在世終年註'));
        $this->assertSame('人物總註', $this->basicInfoFieldValue($sections->get('備註'), '備註'));
    }

    #[Test]
    public function test_tab_postings_returns_office_names_and_addresses(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.tab', ['personId' => 1, 'tabKey' => 'postings']));

        $response->assertOk();
        $response->assertJsonPath('tab', 'postings');
        $response->assertJsonPath('items.0.office_chn', '翰林學士');
        $response->assertJsonPath('items.0.office', 'Hanlin Academician');
        $response->assertJsonPath('items.0.address_summary', '洛陽 / Luoyang');
    }

    #[Test]
    public function test_tab_alt_names_returns_typed_items(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.tab', ['personId' => 1, 'tabKey' => 'alt_names']));

        $response->assertOk();
        $response->assertJsonPath('tab', 'alt_names');
        $response->assertJsonStructure([
            'tab',
            'items' => [['name_chn', 'name', 'type_code', 'type_label_chn', 'type_label']],
        ]);
        $this->assertCount(2, $response->json('items'));
        $response->assertJsonPath('items.0.type_label_chn', '字');
        $response->assertJsonPath('items.0.type_label', 'Zi');
    }

    #[Test]
    public function test_tab_texts_returns_role_labels(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.tab', ['personId' => 1, 'tabKey' => 'texts']));

        $response->assertOk();
        $response->assertJsonPath('tab', 'texts');
        $response->assertJsonPath('items.0.title_chn', '新唐書');
        $response->assertJsonPath('items.0.role_chn', '作者');
        $response->assertJsonPath('items.0.role', 'Author');
    }

    #[Test]
    public function test_tab_events_returns_event_labels(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.tab', ['personId' => 1, 'tabKey' => 'events']));

        $response->assertOk();
        $response->assertJsonPath('tab', 'events');
        $response->assertJsonPath('items.0.event_chn', '宴集');
        $response->assertJsonPath('items.0.event', 'Banquet');
        $response->assertJsonPath('items.0.date_summary', '744年3月15日');
    }

    #[Test]
    public function test_tab_possessions_returns_typed_items(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.tab', ['personId' => 1, 'tabKey' => 'possessions']));

        $response->assertOk();
        $response->assertJsonPath('tab', 'possessions');
        $response->assertJsonPath('items.0.act_chn', '擁有');
        $response->assertJsonPath('items.0.desc_chn', '書籍');
        $response->assertJsonPath('items.0.quantity', '20');
        $response->assertJsonPath('items.0.year', 744);
    }

    #[Test]
    public function test_tab_social_institutions_returns_typed_items(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.tab', ['personId' => 1, 'tabKey' => 'social_institutions']));

        $response->assertOk();
        $response->assertJsonPath('tab', 'social_institutions');
        $response->assertJsonPath('items.0.role_chn', '成員');
        $response->assertJsonPath('items.0.role', 'Member');
        $response->assertJsonPath('items.0.inst_name_chn', '青蓮詩社');
        $response->assertJsonPath('items.0.inst_name', 'Qinglian Poetry Society');
    }

    #[Test]
    public function test_tab_sources_returns_typed_items(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.tab', ['personId' => 1, 'tabKey' => 'sources']));

        $response->assertOk();
        $response->assertJsonPath('tab', 'sources');
        $this->assertCount(1, $response->json('items'));
        $response->assertJsonPath('items.0.is_main_source', true);
    }

    #[Test]
    public function test_tab_entries_returns_typed_items(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.tab', ['personId' => 1, 'tabKey' => 'entries']));

        $response->assertOk();
        $response->assertJsonPath('tab', 'entries');
        $this->assertCount(1, $response->json('items'));
        $response->assertJsonPath('items.0.entry_desc_chn', '詔除');
        $response->assertJsonPath('items.0.entry_desc', 'Imperial Decree');
        $response->assertJsonPath('items.0.year', 742);
    }

    #[Test]
    public function test_tab_kinship_returns_typed_items(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.tab', ['personId' => 1, 'tabKey' => 'kinship']));

        $response->assertOk();
        $response->assertJsonPath('tab', 'kinship');
        $this->assertCount(1, $response->json('items'));
        $response->assertJsonPath('items.0.relation_chn', '友');
        $response->assertJsonPath('items.0.relation', 'Friend');
        $response->assertJsonPath('items.0.kin_person_id', 2);
        $response->assertJsonPath('items.0.kin_person_name_chn', '杜甫');
    }

    #[Test]
    public function test_tab_invalid_key_returns_404(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.tab', ['personId' => 1, 'tabKey' => 'nonexistent']));

        $response->assertStatus(404);
    }

    #[Test]
    public function test_tab_empty_data_returns_empty_items(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.tab', ['personId' => 2, 'tabKey' => 'alt_names']));

        $response->assertOk();
        $response->assertJsonPath('tab', 'alt_names');
        $this->assertCount(0, $response->json('items'));
    }

    #[Test]
    public function test_tab_addresses_returns_typed_items(): void {
        DB::table('BIOG_ADDR_DATA')->insert([
            'c_personid' => 1,
            'c_addr_id' => 100,
            'c_addr_type' => 1,
            'c_firstyear' => 730,
            'c_lastyear' => 762,
            'c_sequence' => 1,
            'c_notes' => '居住地',
        ]);
        DB::table('BIOG_ADDR_CODES')->insert([
            'c_addr_type' => 1,
            'c_addr_desc' => 'Residence',
            'c_addr_desc_chn' => '居住地',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.tab', ['personId' => 1, 'tabKey' => 'addresses']));

        $response->assertOk();
        $response->assertJsonPath('tab', 'addresses');
        $response->assertJsonStructure([
            'tab',
            'items' => [['pk', 'sequence', 'addr_id', 'addr_chn', 'addr', 'type_label_chn', 'type_label', 'first_year', 'last_year', 'notes']],
        ]);
        $response->assertJsonPath('items.0.pk.c_personid', 1);
        $response->assertJsonPath('items.0.pk.c_addr_id', 100);
        $response->assertJsonPath('items.0.pk.c_addr_type', 1);
        $response->assertJsonPath('items.0.pk.c_sequence', 1);
        $response->assertJsonPath('items.0.addr_chn', '長安');
        $response->assertJsonPath('items.0.type_label_chn', '居住地');
        $response->assertJsonPath('items.0.first_year', 730);
    }

    #[Test]
    public function test_tab_statuses_returns_typed_items(): void {
        DB::table('STATUS_DATA')->insert([
            'c_personid' => 1,
            'c_status_code' => 10,
            'c_sequence' => 1,
            'c_firstyear' => 742,
            'c_lastyear' => 762,
            'c_source' => 1,
            'c_pages' => '5a',
            'c_notes' => '翰林身分',
        ]);
        DB::table('STATUS_CODES')->insert([
            'c_status_code' => 10,
            'c_status_desc' => 'Poet',
            'c_status_desc_chn' => '詩人',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.tab', ['personId' => 1, 'tabKey' => 'statuses']));

        $response->assertOk();
        $response->assertJsonPath('tab', 'statuses');
        $response->assertJsonStructure([
            'tab',
            'items' => [['pk', 'sequence', 'status_chn', 'status', 'first_year', 'last_year', 'pages', 'notes']],
        ]);
        $response->assertJsonPath('items.0.pk.c_personid', 1);
        $response->assertJsonPath('items.0.pk.c_sequence', 1);
        $response->assertJsonPath('items.0.pk.c_status_code', 10);
        $response->assertJsonPath('items.0.status_chn', '詩人');
        $response->assertJsonPath('items.0.status', 'Poet');
    }

    #[Test]
    public function test_tab_associations_returns_typed_items(): void {
        DB::table('ASSOC_DATA')->insert([
            'c_personid' => 1,
            'c_assoc_code' => 1,
            'c_assoc_id' => 2,
            'c_kin_code' => null,
            'c_kin_id' => null,
            'c_assoc_kin_code' => null,
            'c_assoc_kin_id' => null,
            'c_text_title' => null,
            'c_sequence' => 1,
            'c_assoc_first_year' => 744,
            'c_assoc_last_year' => 762,
            'c_source' => 1,
            'c_pages' => '8a',
            'c_notes' => '詩友',
        ]);
        DB::table('ASSOC_CODES')->insert([
            'c_assoc_code' => 1,
            'c_assoc_desc' => 'Friend',
            'c_assoc_desc_chn' => '友',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.tab', ['personId' => 1, 'tabKey' => 'associations']));

        $response->assertOk();
        $response->assertJsonPath('tab', 'associations');
        $response->assertJsonStructure([
            'tab',
            'items' => [['pk', 'assoc_desc_chn', 'assoc_desc', 'assoc_person_id', 'assoc_person_name_chn', 'first_year', 'last_year']],
        ]);
        $response->assertJsonPath('items.0.pk.c_personid', 1);
        $response->assertJsonPath('items.0.pk.c_assoc_code', 1);
        $response->assertJsonPath('items.0.pk.c_assoc_id', 2);
        $response->assertJsonPath('items.0.pk.c_assoc_first_year', 744);
        $response->assertJsonPath('items.0.assoc_desc_chn', '友');
        $response->assertJsonPath('items.0.assoc_person_name_chn', '杜甫');
    }

    #[Test]
    public function test_tab_entries_pk_matches_composite_primary_key_schema(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.tab', ['personId' => 1, 'tabKey' => 'entries']));

        $response->assertOk();
        $pk = $response->json('items.0.pk');
        $this->assertIsArray($pk);

        // ENTRY_DATA schema: c_personid, c_entry_code, c_sequence, c_kin_code,
        //   c_assoc_code, c_kin_id, c_year, c_assoc_id, c_inst_code, c_inst_name_code
        $expectedKeys = [
            'c_personid', 'c_entry_code', 'c_sequence', 'c_kin_code',
            'c_assoc_code', 'c_kin_id', 'c_year', 'c_assoc_id',
            'c_inst_code', 'c_inst_name_code',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $pk, "entries pk missing '{$key}'");
        }
        $this->assertSame(1, $pk['c_personid']);
        $this->assertSame(1, $pk['c_entry_code']);
    }

    #[Test]
    public function test_tab_associations_pk_matches_composite_primary_key_schema(): void {
        DB::table('ASSOC_DATA')->insert([
            'c_personid' => 1,
            'c_assoc_code' => 2,
            'c_assoc_id' => 3,
            'c_kin_code' => 5,
            'c_kin_id' => 2,
            'c_assoc_kin_code' => null,
            'c_assoc_kin_id' => null,
            'c_text_title' => '唐詩',
            'c_sequence' => 2,
            'c_assoc_first_year' => 750,
            'c_assoc_last_year' => 760,
            'c_source' => null,
            'c_pages' => null,
            'c_notes' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.tab', ['personId' => 1, 'tabKey' => 'associations']));

        $response->assertOk();

        // Find the item with assoc_code=2 that has more populated pk fields
        $items = $response->json('items');
        $item = collect($items)->firstWhere('pk.c_assoc_code', 2);
        $this->assertNotNull($item, 'Association with c_assoc_code=2 not found');
        $pk = $item['pk'];

        // ASSOC_DATA schema: c_personid, c_assoc_code, c_assoc_id, c_kin_code,
        //   c_kin_id, c_assoc_kin_code, c_assoc_kin_id, c_text_title, c_assoc_first_year
        $expectedKeys = [
            'c_personid', 'c_assoc_code', 'c_assoc_id', 'c_kin_code',
            'c_kin_id', 'c_assoc_kin_code', 'c_assoc_kin_id',
            'c_text_title', 'c_assoc_first_year',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $pk, "associations pk missing '{$key}'");
        }
        $this->assertSame(1, $pk['c_personid']);
        $this->assertSame(5, $pk['c_kin_code']);
        $this->assertSame('唐詩', $pk['c_text_title']);
    }

    #[Test]
    public function test_tab_possessions_pk_has_record_id(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.tab', ['personId' => 1, 'tabKey' => 'possessions']));

        $response->assertOk();
        $pk = $response->json('items.0.pk');
        $this->assertIsArray($pk);
        $this->assertArrayHasKey('c_possession_record_id', $pk);
        $this->assertSame(1, $pk['c_possession_record_id']);
    }

    #[Test]
    public function test_tab_social_institutions_pk_has_inst_code(): void {
        $response = $this->actingAs($this->user)
            ->getJson(route('app.person-browser.tab', ['personId' => 1, 'tabKey' => 'social_institutions']));

        $response->assertOk();
        $pk = $response->json('items.0.pk');
        $this->assertIsArray($pk);

        // BIOG_INST_DATA schema: c_personid, c_inst_code, c_inst_name_code, c_bi_role_code
        $expectedKeys = ['c_personid', 'c_inst_code', 'c_inst_name_code', 'c_bi_role_code'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $pk, "social_institutions pk missing '{$key}'");
        }
        $this->assertSame(1, $pk['c_personid']);
        $this->assertSame(1, $pk['c_inst_code']);
    }

    #[Test]
    public function test_all_non_basic_tabs_items_have_pk(): void {
        $tabsWithData = [
            'alt_names', 'texts', 'sources', 'entries',
            'events', 'possessions', 'social_institutions',
            'postings', 'kinship',
        ];

        foreach ($tabsWithData as $tabKey) {
            $response = $this->actingAs($this->user)
                ->getJson(route('app.person-browser.tab', ['personId' => 1, 'tabKey' => $tabKey]));

            $response->assertOk();
            $items = $response->json('items');
            if (!empty($items)) {
                $this->assertArrayHasKey('pk', $items[0], "Tab '{$tabKey}' items[0] missing 'pk'");
                $this->assertIsArray($items[0]['pk'], "Tab '{$tabKey}' items[0].pk should be an array");
            }
        }
    }

    #[Test]
    public function test_all_non_basic_tabs_use_items_not_rows(): void {
        $listTabs = [
            'alt_names', 'addresses', 'texts', 'sources', 'entries',
            'events', 'statuses', 'associations', 'kinship',
            'possessions', 'social_institutions', 'postings',
        ];

        foreach ($listTabs as $tabKey) {
            $response = $this->actingAs($this->user)
                ->getJson(route('app.person-browser.tab', ['personId' => 1, 'tabKey' => $tabKey]));

            $response->assertOk();
            $this->assertArrayHasKey('tab', $response->json(), "Tab '{$tabKey}' missing 'tab' key");
            $this->assertArrayHasKey('items', $response->json(), "Tab '{$tabKey}' missing 'items' key");
            $this->assertArrayNotHasKey('columns', $response->json(), "Tab '{$tabKey}' still has 'columns' key");
            $this->assertArrayNotHasKey('rows', $response->json(), "Tab '{$tabKey}' still has 'rows' key");
            $this->assertSame($tabKey, $response->json('tab'), "Tab '{$tabKey}' has wrong tab identifier");
        }
    }

    #[Test]
    public function test_all_tab_keys_are_valid(): void {
        $validKeys = [
            'basic_info', 'alt_names', 'addresses', 'texts', 'sources',
            'entries', 'events', 'statuses', 'associations', 'kinship',
            'possessions', 'social_institutions', 'postings',
        ];

        foreach ($validKeys as $key) {
            $response = $this->actingAs($this->user)
                ->getJson(route('app.person-browser.tab', ['personId' => 1, 'tabKey' => $key]));

            $response->assertOk();
        }
    }

    private function basicInfoFieldValue(?array $section, string $label): mixed {
        $this->assertIsArray($section, 'Missing basic info section: ' . $label);

        foreach (($section['fields'] ?? []) as $field) {
            if (($field['label'] ?? null) === $label) {
                return $field['value'] ?? null;
            }
        }

        $this->fail('Missing basic info field: ' . $label);
    }
}
