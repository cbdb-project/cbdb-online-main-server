<?php

namespace App\Services\Mutations\EntityAggregate;

/**
 * 聚合寫入在交易內發現「資料狀態不容許安全寫入」時拋出（例如無主鍵的
 * SOCIAL_INSTITUTION_ALTNAME_DATA 出現 where 分不開的重複列、或 update／delete 影響列數
 * 不符預期）。交易因而整筆回滾，三個聚合 handler 把它轉成 **409**，而不是裸 500——
 * 對 API 呼叫端來說，寫入請求回 500 等於「不確定寫進去沒有」，409 才說得清楚「沒寫、
 * 因為資料衝突」。
 */
class AggregateWriteConflictException extends \RuntimeException {
    /**
     * @param array<string, array<int, string>> $errors 欄位級錯誤（回應的 errors）
     */
    public function __construct(string $message, protected array $errors = []) {
        parent::__construct($message);
    }

    /** @return array<string, array<int, string>> */
    public function errors(): array {
        return $this->errors;
    }
}
