<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InertiaSearchByEntryTest extends TestCase {
    protected User $user;

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
            ['c_addr_id' => 100, 'c_name' => 'Entry Addr 1', 'c_name_chn' => '入仕地一'],
        ]);
    }

    #[Test]
    public function test_index_requires_authentication(): void {
        $response = $this->get(route('app.search-by.entry.index'));
        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function test_index_returns_inertia_page(): void {
        $response = $this->actingAs($this->user)->get(route('app.search-by.entry.index'));

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('SearchByEntry/Index')
                ->has('entryTypes', 2)
                ->has('dynasties', 2)
                ->where('initialFilters.person_keyword', null)
                ->where('initialFilters.entry_codes', [])
                ->where('initialFilters.dynasty_codes', [])
                ->where('initialFilters.place_ids', [])
                ->where('pageUrl', route('app.search-by.entry.index', [], false))
                ->where('queryEndpoint', route('app.search-by.entry.query', [], false))
        );
    }

    #[Test]
    public function test_index_can_preload_codes_and_places_from_query_string(): void {
        $response = $this->actingAs($this->user)->get(route('app.search-by.entry.index', [
            'type_id' => 'TYPE1',
            'entry_codes' => [1],
            'place_ids' => [100],
        ]));

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('SearchByEntry/Index')
                ->has('preloadedCodes', 1)
                ->where('preloadedCodes.0.c_entry_code', 1)
                ->has('preloadedPlaces', 1)
                ->where('preloadedPlaces.0.c_addr_id', 100)
                ->where('initialFilters.type_id', 'TYPE1')
                ->where('initialFilters.entry_codes', [1])
                ->where('initialFilters.place_ids', [100])
        );
    }

    #[Test]
    public function test_index_uses_app_route_as_canonical_page_url(): void {
        $response = $this->actingAs($this->user)->get(route('app.search-by.entry.index'));

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('SearchByEntry/Index')
                ->where('pageUrl', route('app.search-by.entry.index', [], false))
        );
    }
}
