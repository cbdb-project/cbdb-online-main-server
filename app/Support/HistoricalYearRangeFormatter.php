<?php

namespace App\Support;

/**
 * 統一年號/朝代等代碼表的起止年顯示格式，供下拉選單標示以區分同名重複代碼
 * （例如元朝有兩筆「至元」年號，分屬 1264–1294 與 1335–1340）。
 */
class HistoricalYearRangeFormatter {
    /** CBDB 慣用「未詳」哨兵值：0 與 -9999。 */
    private const SENTINELS = [0, -9999];

    /**
     * @return string|null 例如 "(1264–1294)"；起訖任一為 null 或哨兵值時回傳 null（不顯示區間）。
     */
    public static function format(?int $first, ?int $last): ?string {
        if ($first === null || $last === null) {
            return null;
        }
        if (in_array($first, self::SENTINELS, true) || in_array($last, self::SENTINELS, true)) {
            return null;
        }

        return "({$first}–{$last})";
    }
}
