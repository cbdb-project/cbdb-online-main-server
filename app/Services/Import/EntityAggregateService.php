<?php

namespace App\Services\Import;

/**
 * 複合實體聚合根 Service 的共同形狀（docs/ENTITY_AGGREGATE_ARCHITECTURE.md §6.5）。
 *
 * 每個複合實體（office／social-institution／後續 text、place 與 code+type-rel 家族）
 * 的聚合根實作此介面：識別＝單一 int 鍵；寫入方法不自開交易（由呼叫端包
 * DB::transaction）；所有下層寫入逐筆記 operations + audit_log。
 *
 * 引用護欄約定：referenceCount() > 0 時呼叫端必須擋 delete（及改動識別性屬性，
 * 如機構改名）——下層表被人物資料以 ON DELETE CASCADE 引用，違反護欄會靜默損毀人物資料。
 *
 * 領域校驗（validate → [errors, input]）刻意不在此介面：create 與 update 的輸入形狀
 * 可能不同（如 social-institution 的 create 相容批量匯入語義），由各 handler 的
 * Resolves*AggregateInput concern 承擔；§4.5 實體級提案落地時再統一收斂。
 */
interface EntityAggregateService {
    /** 載入單一實體聚合；不存在回 null。 */
    public function load(int $id): ?array;

    /** 新增實體（於呼叫端交易內）；輸入須已驗證。回傳含新識別鍵與 operation id。 */
    public function create(array $input, int $actorPersonId = 0): array;

    /** 更新實體聚合（於呼叫端交易內）；輸入須已驗證，實體須存在。 */
    public function update(int $id, array $input, int $actorPersonId = 0): array;

    /** 刪除實體聚合（於呼叫端交易內）；呼叫端須先過 referenceCount() 護欄。 */
    public function delete(int $id, int $actorPersonId = 0): array;

    /** 實體被人物資料引用的筆數；>0 不可刪除（及不可改識別性屬性）。 */
    public function referenceCount(int $id): int;
}
