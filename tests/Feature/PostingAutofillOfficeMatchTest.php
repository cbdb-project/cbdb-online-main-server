<?php

namespace Tests\Feature;

use App\Services\PostingAutofillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * 回歸測試：fuzzyMatchOffice 對泛稱官名「同知」的消歧順序。
 *
 * 修正前的 bug：「同知」會被 Step 2 的 c_office_chn_alt 匹配誤命中「同知樞密院事」。
 * 修正方式：在 Step 1（朝代專屬精確匹配）後、Step 2（alt 匹配）前加入
 * admin_type 消歧，只在朝代專屬精確匹配落空時才將泛稱導向抽象官名。
 *
 * 這組測試同時保護兩個不變量：
 * 1. 宋代等找不到朝代專屬「同知」記錄時，會根據地址 admin_type 導向
 *    「同知某府軍府事」/「同知某州軍州事」。
 * 2. 明清等已有朝代專屬「同知」記錄時，消歧不會蓋掉朝代專屬匹配
 *    （避免 Step 1.5 被人提前到 Step 1 之前）。
 */
class PostingAutofillOfficeMatchTest extends TestCase {
    use RefreshDatabase;

    protected function setUp(): void {
        parent::setUp();

        DB::table('DYNASTIES')->insert([
            ['c_dy' => 15, 'c_dynasty_chn' => '宋', 'c_dynasty' => 'Song', 'c_start' => 960, 'c_end' => 1279, 'c_sort' => 15],
            ['c_dy' => 19, 'c_dynasty_chn' => '明', 'c_dynasty' => 'Ming', 'c_start' => 1368, 'c_end' => 1644, 'c_sort' => 19],
            ['c_dy' => 20, 'c_dynasty_chn' => '清', 'c_dynasty' => 'Qing', 'c_start' => 1644, 'c_end' => 1911, 'c_sort' => 20],
        ]);

        DB::table('OFFICE_CODES')->insert([
            // 宋代：泛稱「同知」會出現在 107 的 alt 清單，用以重現舊版誤匹配。
            ['c_office_id' => 107, 'c_dy' => 15, 'c_office_chn' => '同知樞密院事', 'c_office_chn_alt' => '同知院事;同知;同知樞'],
            ['c_office_id' => 3301, 'c_dy' => 15, 'c_office_chn' => '同知某州軍州事', 'c_office_chn_alt' => '同知;郡副'],
            ['c_office_id' => 6974, 'c_dy' => 15, 'c_office_chn' => '同知某府軍府事', 'c_office_chn_alt' => null],
            // 明清：朝代專屬的「同知」精確記錄。
            ['c_office_id' => 70974, 'c_dy' => 19, 'c_office_chn' => '同知', 'c_office_chn_alt' => null],
            ['c_office_id' => 85485, 'c_dy' => 20, 'c_office_chn' => '同知', 'c_office_chn_alt' => '候補同知;候選同知'],
        ]);
    }

    /**
     * 呼叫 protected fuzzyMatchOffice，避免走完整 HTTP 流程。
     */
    protected function matchOffice(string $officeName, ?int $dynastyCode, ?string $adminType): ?array {
        $service = app(PostingAutofillService::class);
        $ref = new ReflectionClass($service);
        $method = $ref->getMethod('fuzzyMatchOffice');
        $method->setAccessible(true);

        return $method->invoke($service, $officeName, $dynastyCode, $adminType);
    }

    public function test_song_tongzhi_with_fu_resolves_to_abstract_fu_office() {
        $result = $this->matchOffice('同知', 15, '府');

        $this->assertNotNull($result);
        $this->assertSame(6974, $result['id']);
        $this->assertSame('同知某府軍府事', $result['text']);
        $this->assertSame('exact', $result['match_type']);
    }

    public function test_song_tongzhi_with_zhou_resolves_to_abstract_zhou_office() {
        $result = $this->matchOffice('同知', 15, '州');

        $this->assertNotNull($result);
        $this->assertSame(3301, $result['id']);
        $this->assertSame('同知某州軍州事', $result['text']);
    }

    public function test_song_tongzhi_with_xian_resolves_to_abstract_zhou_office() {
        $result = $this->matchOffice('同知', 15, '縣');

        $this->assertNotNull($result);
        $this->assertSame(3301, $result['id']);
    }

    public function test_ming_tongzhi_with_fu_prefers_dynasty_specific_record() {
        // 明代有朝代專屬「同知」（70974），Step 1 應優先命中，
        // 不得因 admin_type=府 而退化到宋代 6974。
        $result = $this->matchOffice('同知', 19, '府');

        $this->assertNotNull($result);
        $this->assertSame(70974, $result['id']);
        $this->assertSame('同知', $result['text']);
    }

    public function test_qing_tongzhi_with_fu_prefers_dynasty_specific_record() {
        $result = $this->matchOffice('同知', 20, '府');

        $this->assertNotNull($result);
        $this->assertSame(85485, $result['id']);
    }

    public function test_song_tongzhi_without_admin_type_does_not_hit_disambiguation() {
        // 沒有 admin_type 時，消歧步驟不應觸發；維持既有（非此次修正範圍的）行為。
        // 此處僅驗證不會回傳 6974/3301 抽象官名，不對具體命中做斷言。
        $result = $this->matchOffice('同知', 15, null);

        if ($result !== null) {
            $this->assertNotContains($result['id'], [6974, 3301]);
        }
        $this->assertTrue(true);
    }
}
