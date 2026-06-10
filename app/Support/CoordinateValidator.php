<?php

namespace App\Support;

/**
 * 座標有效性判定工具
 *
 * 判定一組 (x_coord, y_coord) = (經度, 緯度) 是否可在 CHGIS 底圖上連結／顯示。
 * 規則詳見 docs/CHGIS_MAP_PLACE_LINK.md §3。
 *
 * 判定順序（任一不過即無效）：
 *  1. 存在性：經緯度皆非 null、可轉為數值。
 *  2. 非零：|lon| >= epsilon 且 |lat| >= epsilon（排除 0,0 與單軸為 0）。
 *  3. 落在 mbtiles bounds 內，且（若啟用）落在 sane_bounds 內。
 *  4. Web Mercator 可投影：|lat| <= mercator_lat_limit。
 *
 * 註：刻意不自動 swap 反掉的座標；反掉者多半因超出範圍而被判無效。
 */
class CoordinateValidator {
    /**
     * 判定座標是否有效（可連結）。
     *
     * @param float|int|string|null $lon 經度（x_coord）
     * @param float|int|string|null $lat 緯度（y_coord）
     */
    public static function isValid($lon, $lat): bool {
        return self::reason($lon, $lat) === null;
    }

    /**
     * 回傳不通過的原因；通過時回 null（供 debug／測試）。
     *
     * @param float|int|string|null $lon 經度（x_coord）
     * @param float|int|string|null $lat 緯度（y_coord）
     */
    public static function reason($lon, $lat): ?string {
        // 1. 存在性 + 可數值化
        if (!self::isNumeric($lon) || !self::isNumeric($lat)) {
            return 'non_numeric';
        }

        $lon = (float) $lon;
        $lat = (float) $lat;

        // 2. 非零（含單軸為 0）
        $epsilon = (float) config('chgis_map.epsilon', 1e-7);
        if (abs($lon) < $epsilon || abs($lat) < $epsilon) {
            return 'zero_axis';
        }

        // 3a. mbtiles bounds
        $bounds = config('chgis_map.bounds', []);
        if (!self::within($lon, $lat, $bounds)) {
            return 'out_of_bounds';
        }

        // 3b. 合理東亞收斂框（可選）
        $sane = config('chgis_map.sane_bounds', []);
        if (!empty($sane['enabled']) && !self::within($lon, $lat, $sane)) {
            return 'out_of_sane_bounds';
        }

        // 4. Web Mercator 緯度上限
        $latLimit = (float) config('chgis_map.mercator_lat_limit', 85.0511);
        if (abs($lat) > $latLimit) {
            return 'mercator_unprojectable';
        }

        return null;
    }

    /**
     * 判斷值是否為有效且有限的數值。
     *
     * 排除 null、空字串、布林、非數字字串，並排除 NAN/INF（如溢位的 '1e400'
     * 或直接傳入的浮點 NAN/INF），避免後續比較靠副作用僥倖攔截。
     *
     * @param mixed $value
     */
    private static function isNumeric($value): bool {
        if ($value === null || $value === '' || is_bool($value)) {
            return false;
        }

        if (!is_numeric($value)) {
            return false;
        }

        return is_finite((float) $value);
    }

    /**
     * 判斷 (lon, lat) 是否落在指定範圍框內（含邊界）。
     *
     * @param array{west?:float,south?:float,east?:float,north?:float} $box
     */
    private static function within(float $lon, float $lat, array $box): bool {
        if (!isset($box['west'], $box['south'], $box['east'], $box['north'])) {
            return false;
        }

        return $lon >= (float) $box['west']
            && $lon <= (float) $box['east']
            && $lat >= (float) $box['south']
            && $lat <= (float) $box['north'];
    }
}
