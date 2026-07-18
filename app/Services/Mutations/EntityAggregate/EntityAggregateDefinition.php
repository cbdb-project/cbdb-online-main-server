<?php

namespace App\Services\Mutations\EntityAggregate;

use App\Services\Import\EntityAggregateService;

/**
 * 「複合實體」寫入的定義契約（docs/ENTITY_AGGREGATE_ARCHITECTURE.md §6.5）。
 *
 * 通用 handler（EntityAggregateCreate/Update/DeleteHandler）承擔所有實體共通的骨架——
 * 授權、resource 分派、target.pk 解析、404、DB::transaction 包裝、回應信封——每個實體只需
 * 實作此契約，提供三處真正會變的部分：輸入校驗（validate）、寫入前護欄（guardWrite）、
 * 回應 result 區塊成形（result）。分派由 EntityAggregateDefinitionRegistry 依
 * config/entity_aggregates.php 完成。
 *
 * 目的：把 office／social institution 兩輪各自的 3 個 handler 收斂為「3 通用 handler ＋
 * 每實體 1 個 definition」，並讓後續 code+type-rel 家族可用一個 config 驅動的共用 definition
 * 服務多個實體（§6.5 第二梯隊）。
 */
interface EntityAggregateDefinition {
    /** 本 definition 服務的 resource 名（含別名）。 */
    public function resources(): array;

    /** 支援的操作子集（'create'／'update'／'delete'）。 */
    public function operations(): array;

    /** 識別鍵欄名（target.pk 解析、404／422 錯誤欄名）。 */
    public function pkField(): string;

    /** 回應信封的正規 resource 名。 */
    public function resourceName(): string;

    /** 404 訊息（找不到實體時）。 */
    public function notFoundMessage(): string;

    /** 聚合根 Service。 */
    public function service(): EntityAggregateService;

    /**
     * 校驗並解析 changes 為 Service 輸入。create／update 可有不同輸入形狀（$operation 區分）。
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [errors, input]；errors 非空時 handler 回 422
     */
    public function validate(string $operation, array $changes): array;

    /**
     * 寫入前護欄（如刪除／改名時的引用檢查）。回傳 [message, status, errors] 由 handler 直接回應；
     * null 表示放行。create 時 $id／$existing 為 null。
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing 已載入的實體聚合（update／delete 時提供）
     * @return array{0: string, 1: int, 2: array<string, mixed>}|null
     */
    public function guardWrite(string $operation, ?int $id, array $input, ?array $existing): ?array;

    /**
     * 由 Service 回傳值成形回應的 result 區塊（含 pk／status／operation_id／row 等）。
     * create 時 $id 為 null（識別鍵在 $serviceResult 內）。
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $serviceResult
     * @return array<string, mixed>
     */
    public function result(string $operation, ?int $id, array $input, array $serviceResult): array;
}
