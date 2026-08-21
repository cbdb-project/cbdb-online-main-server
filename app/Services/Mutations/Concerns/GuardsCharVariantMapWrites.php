<?php

namespace App\Services\Mutations\Concerns;

use App\Exceptions\VariantMappingException;
use App\Services\CharVariantMapService;
use Illuminate\Http\JsonResponse;

/**
 * `char_variant_map` 經 v2 mutation API 寫入時的結構守衛與快取重置。
 *
 * 為什麼非做不可：`config/code_table_mutations.php` 把 `char_variant_map` 登記成可
 * mutate 的代碼表（`allowed_fields` 含 `c_variant_char`／`c_reference_char`），但
 * 結構驗證與快取重置原本只掛在 `CodesController`（Codes UI）與 `OperationsController`
 * （還原）。也就是說用 API 就能繞過去：
 *
 * - 新增一筆會**成環**的對照（表裡已有 `峯→峰`，再送 `峰→峯`）→ `resolveMap()` 的
 *   `dropCycleEdges()` 會把環上**兩條**邊一起丟掉，只留 Log::error ⇒ 這組字的替換在
 *   全站靜默停止。多字元對照同樣會被靜默丟棄。
 * - 不重置快取 ⇒ 新對照在該 process 的剩餘生命週期內不生效。
 *
 * S3 把落地替換擴到所有人物寫入路徑之後，這個洞的影響面從 Codes UI 擴到全站，所以
 * 守衛下移到落庫層（handler），不再依賴呼叫端記得檢查。
 */
trait GuardsCharVariantMapWrites {
    /**
     * @param array<string,mixed> $row 本次要寫入的欄位
     * @param int|null $excludeId 更新時排除自己那一列（取得要排除的舊邊）
     * @return JsonResponse|null null = 通過；非 null = 422 錯誤回應
     */
    protected function guardCharVariantMapWrite(string $table, array $row, ?int $excludeId = null): ?JsonResponse {
        if (strtolower($table) !== 'char_variant_map') {
            return null;
        }

        try {
            CharVariantMapService::assertWritable($row, $excludeId);
        } catch (VariantMappingException $e) {
            // 只 catch 專屬型別：QueryException 繼承 PDOException 繼承 RuntimeException，
            // 用 \RuntimeException 會把資料庫錯誤誤判成「驗證失敗」並洩漏原始 SQL。
            return $this->errorResponse($e->getMessage(), 422, [
                'changes' => ['invalid_variant_mapping'],
            ]);
        }

        return null;
    }

    /** 落庫成功後重置對照表快取（只有動到 char_variant_map 時）。 */
    protected function resetVariantMapCacheIfNeeded(string $table): void {
        if (strtolower($table) === 'char_variant_map') {
            CharVariantMapService::reset();
        }
    }
}
