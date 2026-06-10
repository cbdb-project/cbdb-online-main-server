<?php

namespace Tests\Unit;

use App\Support\CoordinateValidator;
use Tests\TestCase;

/**
 * 座標有效性判定測試
 *
 * 對應 docs/CHGIS_MAP_PLACE_LINK.md §3 的判定規則。
 */
class CoordinateValidatorTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        // 固定測試用的範圍設定，避免受 .env 影響
        config([
            'chgis_map.epsilon' => 1e-7,
            'chgis_map.mercator_lat_limit' => 85.0511,
            'chgis_map.bounds' => ['west' => 58.5372, 'south' => -62.6348, 'east' => 152.24, 'north' => 82.7288],
            'chgis_map.sane_bounds' => ['enabled' => true, 'west' => 70.0, 'south' => 15.0, 'east' => 140.0, 'north' => 55.0],
        ]);
    }

    public function testValidEastAsiaPoint(): void {
        // 開封一帶（lon ~114.3, lat ~34.8）
        $this->assertTrue(CoordinateValidator::isValid(114.3, 34.8));
    }

    public function testNullCoordsAreInvalid(): void {
        $this->assertFalse(CoordinateValidator::isValid(null, null));
        $this->assertFalse(CoordinateValidator::isValid(114.3, null));
        $this->assertFalse(CoordinateValidator::isValid(null, 34.8));
        $this->assertSame('non_numeric', CoordinateValidator::reason(null, 34.8));
    }

    public function testEmptyStringIsInvalid(): void {
        $this->assertFalse(CoordinateValidator::isValid('', ''));
        $this->assertFalse(CoordinateValidator::isValid('abc', '34.8'));
    }

    public function testZeroZeroIsInvalid(): void {
        $this->assertFalse(CoordinateValidator::isValid(0, 0));
        $this->assertSame('zero_axis', CoordinateValidator::reason(0, 0));
    }

    public function testSingleAxisZeroIsInvalid(): void {
        // 經度為 0
        $this->assertFalse(CoordinateValidator::isValid(0, 34.8));
        // 緯度為 0
        $this->assertFalse(CoordinateValidator::isValid(114.3, 0));
    }

    public function testNearZeroIsInvalid(): void {
        $this->assertFalse(CoordinateValidator::isValid(1e-9, 1e-9));
        $this->assertFalse(CoordinateValidator::isValid(114.3, 1e-9));
    }

    public function testReversedCoordsFallOutOfBounds(): void {
        // 反掉：把緯度 34.8 放進經度、經度 114.3 放進緯度
        // 114.3 > north(82.7288) → 超界
        $this->assertFalse(CoordinateValidator::isValid(34.8, 114.3));
        $this->assertSame('out_of_bounds', CoordinateValidator::reason(34.8, 114.3));
    }

    public function testOutOfMbtilesBoundsIsInvalid(): void {
        // 倫敦（lon ~-0.1, lat ~51.5）：經度 < west
        $this->assertFalse(CoordinateValidator::isValid(-0.1276, 51.5072));
        // 經度超過 east
        $this->assertFalse(CoordinateValidator::isValid(160.0, 40.0));
    }

    public function testSaneBoundsFiltersWideMbtilesArea(): void {
        // 南半球某點：在 mbtiles bounds（south=-62.6348）內，但被 sane_bounds（south=15）濾掉
        $this->assertFalse(CoordinateValidator::isValid(100.0, -30.0));
        $this->assertSame('out_of_sane_bounds', CoordinateValidator::reason(100.0, -30.0));
    }

    public function testSaneBoundsCanBeDisabled(): void {
        config(['chgis_map.sane_bounds.enabled' => false]);
        // 關閉 sane 後，南半球點只要落在 mbtiles bounds 即有效
        $this->assertTrue(CoordinateValidator::isValid(100.0, -30.0));
    }

    public function testBoundaryValuesAreInclusive(): void {
        config(['chgis_map.sane_bounds.enabled' => false]);
        // 恰好等於 bounds 邊界
        $this->assertTrue(CoordinateValidator::isValid(58.5372, 82.7288));
        $this->assertTrue(CoordinateValidator::isValid(152.24, -62.6348));
    }

    public function testNumericStringsAreAccepted(): void {
        // 資料庫 double 欄位有時以字串形式傳入
        $this->assertTrue(CoordinateValidator::isValid('114.3', '34.8'));
    }

    public function testNanAndInfAreInvalid(): void {
        // 直接傳入浮點 NAN/INF（is_numeric 會回 true，需靠 is_finite 攔截）
        $this->assertFalse(CoordinateValidator::isValid(NAN, NAN));
        $this->assertFalse(CoordinateValidator::isValid(INF, 34.8));
        $this->assertFalse(CoordinateValidator::isValid(114.3, -INF));
        $this->assertSame('non_numeric', CoordinateValidator::reason(NAN, 34.8));
        // 溢位的科學記號字串 → (float) 後為 INF
        $this->assertFalse(CoordinateValidator::isValid('1e400', '1e400'));
    }

    public function testBooleanIsInvalid(): void {
        $this->assertFalse(CoordinateValidator::isValid(true, true));
        $this->assertFalse(CoordinateValidator::isValid(114.3, false));
    }

    public function testScientificNotationStringParsesToNumber(): void {
        // '1e2' = 100，為合法數值；落在 bounds/sane 內即有效（釘死行為）
        $this->assertTrue(CoordinateValidator::isValid('1.143e2', '3.48e1'));
    }

    public function testWhitespacePaddedStringIsAccepted(): void {
        // PHP is_numeric 接受前後空白，(float) 可正確轉換；釘死此行為
        $this->assertTrue(CoordinateValidator::isValid(' 114.3 ', "34.8\t"));
    }

    public function testMercatorLatLimitTriggersWhenBoundsWidened(): void {
        // 預設 bounds（north=82.7288）下第 4 關為死碼；放寬 north 後才可觸發
        config([
            'chgis_map.sane_bounds.enabled' => false,
            'chgis_map.bounds' => ['west' => 58.5372, 'south' => -88.0, 'east' => 152.24, 'north' => 88.0],
        ]);
        // 緯度落在放寬後的 bounds 內，但超過 Web Mercator 上限 85.0511
        $this->assertFalse(CoordinateValidator::isValid(100.0, 86.0));
        $this->assertSame('mercator_unprojectable', CoordinateValidator::reason(100.0, 86.0));
        // 恰好等於上限應通過（含界）
        $this->assertTrue(CoordinateValidator::isValid(100.0, 85.0511));
    }

    public function testReversedCoordsStillInsideBoundsIsKnownLimitation(): void {
        // 已知限制（§3.1）：兩值都落在彼此合法範圍時無法辨識反掉，保守接受。
        // 注意：啟用 sane_bounds（lon 70-140, lat 15-55）時 lon/lat 範圍無交集，
        // 反掉座標必被攔截；此限制僅在較寬的 mbtiles bounds 下才會出現。
        config(['chgis_map.sane_bounds.enabled' => false]);
        // mbtiles bounds 下 lon[58.5,152.24] 與 lat[-62.6,82.7] 交集為 [58.5,82.7]，
        // 70 與 80 互換後仍都落在各自範圍 → 兩者皆有效（無法辨識反掉）。
        $this->assertTrue(CoordinateValidator::isValid(70.0, 80.0));
        $this->assertTrue(CoordinateValidator::isValid(80.0, 70.0));
    }

    public function testMissingBoundsKeysFailClosed(): void {
        // config 缺 bounds 鍵 → 全部判無效（fail-closed）
        config(['chgis_map.bounds' => []]);
        $this->assertFalse(CoordinateValidator::isValid(114.3, 34.8));
        $this->assertSame('out_of_bounds', CoordinateValidator::reason(114.3, 34.8));
    }

    public function testSaneBoundsEnabledButMissingKeysFailClosed(): void {
        // sane_bounds 啟用但缺座標鍵 → 全部判無效（刻意 fail-closed，非疏漏）
        config(['chgis_map.sane_bounds' => ['enabled' => true]]);
        $this->assertFalse(CoordinateValidator::isValid(114.3, 34.8));
        $this->assertSame('out_of_sane_bounds', CoordinateValidator::reason(114.3, 34.8));
    }
}
