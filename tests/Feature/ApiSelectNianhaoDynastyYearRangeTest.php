<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * /api/select/nianhao 與 /api/select/dynasty 新增 c_year_range 欄位（如 "(1264–1294)"），
 * 供前端下拉選單標示起止年以區分同朝代重複年號（如元朝兩筆「至元」）。
 */
class ApiSelectNianhaoDynastyYearRangeTest extends TestCase {
    use RefreshDatabase;

    #[Test]
    public function nianhao_endpoint_includes_formatted_year_range(): void {
        DB::table('DYNASTIES')->insert([
            'c_dy' => 18, 'c_dynasty_chn' => '元', 'c_dynasty' => 'Yuan', 'c_start' => 1234, 'c_end' => 1367, 'c_sort' => 18,
        ]);
        DB::table('NIAN_HAO')->insert([
            ['c_nianhao_id' => 623, 'c_dy' => 18, 'c_dynasty_chn' => '元', 'c_nianhao_chn' => '至元', 'c_firstyear' => 1264, 'c_lastyear' => 1294],
            ['c_nianhao_id' => 635, 'c_dy' => 18, 'c_dynasty_chn' => '元', 'c_nianhao_chn' => '至元', 'c_firstyear' => 1335, 'c_lastyear' => 1340],
        ]);

        $response = $this->getJson('/api/select/nianhao');

        $response->assertOk();
        $rows = collect($response->json())->keyBy('c_nianhao_id');

        $this->assertSame('(1264–1294)', $rows[623]['c_year_range']);
        $this->assertSame('(1335–1340)', $rows[635]['c_year_range']);
        // c_str 為既有解析用欄位，格式不受本次改動影響。
        $this->assertSame('[1264]~[1294]', $rows[623]['c_str']);
    }

    #[Test]
    public function nianhao_endpoint_omits_year_range_when_years_are_sentinel_or_missing(): void {
        DB::table('DYNASTIES')->insert([
            'c_dy' => 0, 'c_dynasty_chn' => '未詳', 'c_dynasty' => 'unknown', 'c_start' => 0, 'c_end' => 0, 'c_sort' => 0,
        ]);
        DB::table('NIAN_HAO')->insert([
            ['c_nianhao_id' => 0, 'c_dy' => 0, 'c_dynasty_chn' => '未詳', 'c_nianhao_chn' => '未詳', 'c_firstyear' => null, 'c_lastyear' => null],
        ]);

        $response = $this->getJson('/api/select/nianhao');

        $response->assertOk();
        $rows = collect($response->json())->keyBy('c_nianhao_id');

        $this->assertNull($rows[0]['c_year_range']);
    }

    #[Test]
    public function dynasty_endpoint_includes_formatted_year_range(): void {
        DB::table('DYNASTIES')->insert([
            'c_dy' => 18, 'c_dynasty_chn' => '元', 'c_dynasty' => 'Yuan', 'c_start' => 1234, 'c_end' => 1367, 'c_sort' => 18,
        ]);

        $response = $this->getJson('/api/select/dynasty');

        $response->assertOk();
        $rows = collect($response->json())->keyBy('c_dy');

        $this->assertSame('(1234–1367)', $rows[18]['c_year_range']);
    }

    #[Test]
    public function dynasty_endpoint_omits_year_range_for_sentinel_placeholder(): void {
        DB::table('DYNASTIES')->insert([
            'c_dy' => 999, 'c_dynasty_chn' => '未詳', 'c_dynasty' => 'unknown', 'c_start' => 0, 'c_end' => 0, 'c_sort' => 999,
        ]);

        $response = $this->getJson('/api/select/dynasty');

        $response->assertOk();
        $rows = collect($response->json())->keyBy('c_dy');

        $this->assertNull($rows[999]['c_year_range']);
    }
}
