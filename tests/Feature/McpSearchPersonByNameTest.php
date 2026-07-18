<?php

namespace Tests\Feature;

use App\Services\NameSearchService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 別名感知人名搜尋（MCP search_person_by_name 底層 NameSearchService）回歸。
 * 驗證：字／號／別名命中同一 personid、name_type_codes 過濾、dynasty 過濾、numeric personid。
 */
class McpSearchPersonByNameTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $this->createTables();
        $this->seed();
    }

    private function createTables(): void {
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('CREATE TABLE IF NOT EXISTS BIOG_MAIN (
            c_personid INTEGER PRIMARY KEY,
            c_name VARCHAR(255), c_name_chn VARCHAR(255),
            c_name_proper VARCHAR(255), c_name_rm VARCHAR(255),
            c_surname VARCHAR(255), c_mingzi VARCHAR(255),
            c_dy INTEGER, c_index_addr_id INTEGER
        )');
        DB::statement('CREATE TABLE IF NOT EXISTS DYNASTIES (c_dy INTEGER PRIMARY KEY, c_dynasty_chn VARCHAR(255))');
        DB::statement('CREATE TABLE IF NOT EXISTS ADDR_CODES (c_addr_id INTEGER PRIMARY KEY, c_name_chn VARCHAR(255))');
        DB::statement('CREATE TABLE IF NOT EXISTS CBDB__NAME_FTS (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            c_personid INTEGER, name_type_code INTEGER,
            name_type_desc VARCHAR(32), name_type_desc_chn VARCHAR(32),
            search_term VARCHAR(100), full_name VARCHAR(100),
            source VARCHAR(32), is_simplified SMALLINT DEFAULT 0
        )');
    }

    private function seed(): void {
        DB::table('DYNASTIES')->insert([
            ['c_dy' => 15, 'c_dynasty_chn' => '宋'],
            ['c_dy' => 19, 'c_dynasty_chn' => '明'],
        ]);
        DB::table('ADDR_CODES')->insert(['c_addr_id' => 1, 'c_name_chn' => '臨川']);
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 100, 'c_name_chn' => '王安石', 'c_name' => 'Wang Anshi',
            'c_dy' => 15, 'c_index_addr_id' => 1,
        ]);

        $fts = fn ($term, $full, $code, $descChn, $src) => [
            'c_personid' => 100, 'name_type_code' => $code, 'name_type_desc' => '', 'name_type_desc_chn' => $descChn,
            'search_term' => $term, 'full_name' => $full, 'source' => $src, 'is_simplified' => 0,
        ];
        DB::table('CBDB__NAME_FTS')->insert([
            $fts('王安石', '王安石', null, '本名', 'BIOG_MAIN'),
            $fts('安石', '王安石', null, '本名', 'BIOG_MAIN'),
            $fts('介甫', '介甫', 4, '字', 'ALTNAME_DATA'),
            $fts('甫', '介甫', 4, '字', 'ALTNAME_DATA'),
            $fts('半山', '半山', 5, '室名、別號', 'ALTNAME_DATA'),
        ]);
    }

    private function svc(): NameSearchService {
        return app(NameSearchService::class);
    }

    #[Test]
    public function it_finds_person_by_courtesy_name(): void {
        $res = $this->svc()->searchPersons('介甫');
        $this->assertSame(1, $res['total']);
        $this->assertSame(100, $res['rows'][0]['c_personid']);
        $this->assertSame('王安石', $res['rows'][0]['c_name_chn']);
        $terms = collect($res['rows'][0]['matched_terms']);
        $this->assertTrue($terms->contains(fn ($t) => $t['term'] === '介甫' && $t['name_type_code'] === 4));
    }

    #[Test]
    public function it_finds_person_by_style_name(): void {
        $this->assertSame(100, $this->svc()->searchPersons('半山')['rows'][0]['c_personid']);
    }

    #[Test]
    public function it_finds_person_by_main_name(): void {
        $this->assertSame(1, $this->svc()->searchPersons('王安石')['total']);
    }

    #[Test]
    public function name_type_codes_filter_restricts_matches(): void {
        // 半山 是號(5)；限定只搜字(4) 應查不到
        $this->assertSame(0, $this->svc()->searchPersons('半山', null, [4])['total']);
        // 限定號(5) 應命中
        $this->assertSame(1, $this->svc()->searchPersons('半山', null, [5])['total']);
    }

    #[Test]
    public function dynasty_filter_applies(): void {
        $this->assertSame(0, $this->svc()->searchPersons('介甫', 19)['total']);
        $this->assertSame(1, $this->svc()->searchPersons('介甫', 15)['total']);
    }

    #[Test]
    public function numeric_keyword_is_exact_personid(): void {
        $res = $this->svc()->searchPersons('100');
        $this->assertSame(1, $res['total']);
        $this->assertSame(100, $res['rows'][0]['c_personid']);
    }

    #[Test]
    public function unknown_name_returns_empty(): void {
        $this->assertSame(0, $this->svc()->searchPersons('不存在的名字')['total']);
    }
}
