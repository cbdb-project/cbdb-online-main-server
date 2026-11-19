<?php

namespace App\Services\Mutations;

/**
 * 代碼表新增時 `max(key)+1` 自動配發的主鍵超出該欄實際整數型別的值域。
 *
 * 為什麼要擋：呼叫端明確給的主鍵早就有值域檢查，但自動配發的值是交易內才算出來的、
 * 不經那一道。非 strict sql_mode 的 MariaDB 會把 smallint 的 32768 靜默截斷成 32767，
 * 於是要嘛撞上既有的 32767 回「並發衝突，請重試」（重試永遠一樣），要嘛建在一個
 * 呼叫端沒拿到的鍵上。以專屬例外讓交易回滾、由 handler 轉成 422。
 */
class AutoAssignedKeyOutOfRangeException extends \RuntimeException {
    /** @param array{0:int,1:int} $range */
    public function __construct(public readonly string $column, public readonly array $range) {
        parent::__construct($column . ' 自動配發的主鍵已超出欄位值域');
    }
}
