<?php

namespace App\Services\Mutations;

/**
 * 代碼表刪除時，交易內實際刪除的列數不是 1。
 *
 * 0 列：檢查存在之後、刪除之前被同時刪掉了；多列：主鍵條件不唯一（key_columns 與真正的
 * 唯一鍵不一致）。兩者都不該寫出 operations／audit_log——以專屬例外讓交易回滾，
 * 由 CodeTableDeleteHandler 轉成 404／409。
 */
class CodeTableDeleteConflictException extends \RuntimeException {
    public function __construct(public readonly int $deleted) {
        parent::__construct('代碼表刪除實際影響 ' . $deleted . ' 列');
    }
}
