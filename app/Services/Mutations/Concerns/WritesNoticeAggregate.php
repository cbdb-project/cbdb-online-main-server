<?php

namespace App\Services\Mutations\Concerns;

use Illuminate\Http\JsonResponse;

/**
 * 把一條寫入路徑上所有「系統改了你的輸入」的通知一次掛齊。
 *
 * 目前有兩種：異體字落地替換（{@see AppliesVariantReplacement}）與經緯度歸零
 * （{@see NormalizesCoordinatePairs}）。一個請求可能同時觸發兩者（改了中文名、又把座標
 * 留空），而兩者都寫進回應的同一個頂層 `notices` 欄位——分成兩個欄位會讓呼叫端得知道
 * 去看幾個地方。
 *
 * **為什麼要有這個 trait 而不是各自呼叫**：`AbstractCodeTableMutationHandler` 與
 * `CodeTableCreateHandler` 各有 7–9 個 return 需要掛通知。新增一種通知時若得逐一補上，
 * 漏掉一半 return 是遲早的事——而漏掉的那半正是 409／422 這些「被擋下來時使用者最需要
 * 知道系統改了什麼」的路徑。集中成一個方法，新增通知種類只要改這裡一處。
 *
 * 使用本 trait 的類別必須同時 use 上面兩個 trait。
 */
trait WritesNoticeAggregate {
    protected function withWriteNotices(JsonResponse $response): JsonResponse {
        return $this->withCoordinateNotices($this->withVariantNotices($response));
    }
}
