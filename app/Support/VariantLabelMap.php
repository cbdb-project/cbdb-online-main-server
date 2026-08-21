<?php

namespace App\Support;

use App\Services\CharVariantMapService;
use Illuminate\Support\Facades\Log;

/**
 * 「標籤 → 代碼」對照表的異體字歸一（見 docs/CHAR_VARIANT_MAP_TEXT_COLUMN_ROLLOUT_PLAN.md S4）。
 *
 * 為什麼**兩側都要**歸一：
 * - 只歸一傳入標籤不夠。D6 不做回溯校正，既有 `DYNASTIES` 列的字面若是「淸」就永遠是
 *   「淸」；而使用者輸入「清」本來就是參考字、替換後不變 ⇒ 照樣查不到。
 * - 只歸一代碼表那側也不夠：使用者輸入「淸」而代碼表寫「清」時同樣落空。
 * 所以 map 的鍵在記憶體內歸一（不改資料庫）、查表前也把傳入標籤歸一，兩個方向才都命中。
 *
 * **鍵碰撞的處理是這個類別存在的主要理由**：這些 map 的值同時被當成「合法代碼白名單」
 * （`in_array((int) $code, $map, true)`）。歸一後若兩列的鍵塌成一個，被丟掉那個
 * `c_dy`／`c_inst_type_code` 就會從白名單消失，**一個完全合法的代碼開始被判 invalid**。
 * 所以 `build()` 回傳兩份東西：
 *   1. `label => code` 的純量 map（碰撞時**確定性取最小碼**並記 warning）——呼叫端形狀不變；
 *   2. **扁平的合法代碼集合**——白名單檢查改讀它，不再因為鍵碰撞而漏掉合法碼。
 *
 * 注意：不能沿用「`mapWithKeys` + `orderBy` asc 的 last-wins」，那是取**最大**者，與這裡
 * 明訂的「取最小」相反。
 */
class VariantLabelMap {
    /**
     * 從 (標籤, 代碼) 序對建出歸一後的 map 與合法代碼集合。
     *
     * 刻意接受 (標籤, 代碼) 序對而不是既有的 keyed map：keyed map 早在 `pluck()` 那一步
     * 就把「字面完全相同的標籤」塌掉了，用它建 `allCodes` 會連那種情況都漏掉代碼。
     *
     * @param iterable<int,array{0: mixed, 1: mixed}> $pairs [標籤, 代碼]
     * @param string $table 標籤欄所屬的表（決定替換模式）
     * @param string $column 標籤欄
     * @return array{0: array<string,int>, 1: array<int,int>} [歸一標籤 => 最小代碼, 全部合法代碼]
     */
    public static function build(iterable $pairs, string $table, string $column): array {
        $map = [];
        $allCodes = [];

        $rawLabels = [];

        foreach ($pairs as $pair) {
            $label = trim((string) ($pair[0] ?? ''));
            $code = (int) ($pair[1] ?? 0);

            // 標籤為空的列**不進白名單**：白名單的用途只是「不要因為歸一造成的鍵碰撞而
            // 漏掉合法代碼」，而碰撞的雙方都必然有標籤。把無標籤列（含 c_dy 為 NULL 被折成
            // 0 的佔位列）也算進去，等於放寬了原本「map 的值」那組白名單，會讓
            // dynasty_code=0／佔位碼從被擋變成通過。
            if ($label === '') {
                continue;
            }

            $key = self::normalizeLabel($label, $table, $column);
            if ($key === '') {
                continue;
            }

            $allCodes[$code] = true;

            if (!array_key_exists($key, $map)) {
                $map[$key] = $code;
                $rawLabels[$key] = $label;

                continue;
            }

            if ($map[$key] === $code) {
                continue;
            }

            // 只有「原始標籤互不相同」才是歸一造成的碰撞，值得 warning。字面完全相同的
            // 重複標籤是既有資料問題、舊碼一直靜默 last-wins，不該因為這次改動開始刷 log。
            if (($rawLabels[$key] ?? null) !== $label) {
                Log::warning('標籤歸一後碰撞，取最小代碼', [
                    'table' => $table,
                    'column' => $column,
                    'normalized_label' => $key,
                    'codes' => [$map[$key], $code],
                ]);
            }

            $map[$key] = min($map[$key], $code);
            if ($map[$key] === $code) {
                $rawLabels[$key] = $label;
            }
        }

        return [$map, array_values(array_map('intval', array_keys($allCodes)))];
    }

    /** 查表前把傳入標籤做同樣的歸一（`trim` 後替換，與 `build()` 的鍵一致）。 */
    public static function normalizeLabel(string $label, string $table, string $column): string {
        return trim(CharVariantMapService::replaceFor($table, $column, trim($label))['text']);
    }

    /**
     * 以歸一後的標籤查 map。
     *
     * @param array<string,int> $map 由 `build()` 產生（鍵已歸一）
     */
    public static function lookup(array $map, string $label, string $table, string $column): ?int {
        $key = self::normalizeLabel($label, $table, $column);

        return $map[$key] ?? null;
    }
}
