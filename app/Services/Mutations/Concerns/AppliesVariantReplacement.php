<?php

namespace App\Services\Mutations\Concerns;

use App\Services\CharVariantMapService;
use Illuminate\Http\JsonResponse;

/**
 * v2 mutation handler 的異體字落地替換掛鉤（見 docs/CHAR_VARIANT_MAP_TEXT_COLUMN_ROLLOUT_PLAN.md S3）。
 *
 * 掛鉤點一律在 `preprocess*Data()` 之前、也就是 PK 計算（`extractPkFromRow()`／
 * `buildNewPk()`）與查重（`findExistingRow()`／`hasEffectiveChanges()`）之前，
 * 這樣文本型 PK 成員（`ALTNAME_DATA.c_alt_name_chn`、`ASSOC_DATA.c_text_title`、
 * `BIOG_SOURCE_DATA.c_pages`）被替換後的值會自然成為新 PK，查重也看到替換後的值。
 *
 * 替換範圍由 `VariantReplaceScope::modeFor()` 按「表.欄位型別」決定：人名／別名欄
 * strict、其餘文本欄 lenient、未知表與排除欄不替換（fail-closed）。所以子類不需要、
 * 也**不應該**自己再呼叫 `CharVariantMapService::replace*()`。
 */
trait AppliesVariantReplacement {
    /**
     * 本次請求已發生的替換（變體字 → 參考字）。
     *
     * @var array<string,string|array<int,string>>
     */
    protected array $variantReplaced = [];

    /**
     * 對整列套用落地替換，並把結果**併入**（而非覆寫）`$this->variantReplaced`。
     *
     * 為什麼一定要 merge：子類若在自己的 `preprocess*` 或 `afterDirect*` 再補一次
     * 替換並 assign，會把通用掛鉤收集到的通知靜默吃掉——這正是 S3 修掉的失效模式
     * （`AltnameCreateHandler` 舊碼 assign 後，通用掛鉤先跑、它的 `replaced` 恆為 []，
     * 別名替換通知就消失了）。
     *
     * @param array<string,mixed> $data
     * @param string|null $table 目標資料表；省略時取 `$this->tableName()`（基底體系內的
     *                           子資源都有這個方法，體系外的三個例外 handler 沒有，需明給）
     * @return array<string,mixed>
     */
    protected function applyVariantReplacement(array $data, ?string $table = null): array {
        $result = CharVariantMapService::replaceRow($data, $table ?? $this->tableName());
        $this->variantReplaced = CharVariantMapService::mergeReplaced(
            $this->variantReplaced,
            $result['replaced']
        );

        return $result['data'];
    }

    /**
     * 重置累積的替換紀錄。
     *
     * handler 由容器解析，同一個 process／請求內可能被重複使用（例如批次多筆 mutate），
     * 不重置會把上一筆的通知帶到下一筆的回應上。
     */
    protected function resetVariantReplaced(): void {
        $this->variantReplaced = [];
    }

    /** 把 `notices` 掛到回應上（沒有替換時原樣回傳，含 422／409 等錯誤回應）。 */
    protected function withVariantNotices(JsonResponse $response): JsonResponse {
        return CharVariantMapService::withNotices($response, $this->variantReplaced);
    }
}
