<?php

namespace App\Services\Mutations;

use RuntimeException;

/**
 * 雙向鏡像同步衝突（#66 資料安全）。
 *
 * 當 direct 更新觸發後台雙向鏡像同步、而「對面鏡像列的對應欄位已有內容且與本側即將寫入的值不同」時拋出。
 * 在 handleDirect 的 DB::transaction 內拋出 → 整筆（含正向列）回滾，避免單邊覆寫洗掉對方既有資料。
 * 由 handleDirect 捕獲後轉成 409 結構化回應（含衝突欄明細與對面鏡像列 PK，供前端警告 + 可點連結 + 強制覆寫）。
 *
 * 僅 v2 direct 路徑啟用（呼叫端傳 detectConflict=true）；legacy 與 proposal 不啟用，行為不變。
 */
class MirrorConflictException extends RuntimeException {
    /**
     * @param string $mirrorTable 對面鏡像列所在表（ASSOC_DATA / KIN_DATA）
     * @param array<string,mixed> $mirrorPk 對面鏡像列主鍵（供前端組 edit-v2 連結）
     * @param array<int,array{field:string,existing:mixed,incoming:mixed}> $conflicts 衝突欄明細
     */
    public function __construct(
        public readonly string $mirrorTable,
        public readonly array $mirrorPk,
        public readonly array $conflicts,
        string $message = ''
    ) {
        parent::__construct($message !== '' ? $message : '對面對應記錄已有不同內容，為避免覆寫已中止同步');
    }

    /** 衝突欄位名清單（供回應/訊息）。 */
    public function conflictFields(): array {
        return array_values(array_map(static fn ($c) => $c['field'], $this->conflicts));
    }
}
