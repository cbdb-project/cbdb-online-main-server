<?php

namespace App\Services\Mutations;

use RuntimeException;

/**
 * 雙向鏡像「刪除多筆」中止確認（#81 §6 關聯C）。
 *
 * 當 direct 刪除一筆 kinship 正向列、觸發後台反向鏡像同步刪除，且以 legitReverses（指向正向碼的合法反向碼集，
 * 與 update/偵測定位器同套）定位到「對面有多筆」對應反向列時拋出。為避免一次靜默刪除多筆（可能含他段關係的
 * 反向列），改為偵測即停：在 handleDirect 的 DB::transaction 內拋出 → 整筆回滾（正向列亦未刪），回 409 + 候選明細，
 * 供前端列出將被一併刪除的反向列並要求使用者確認；確認後帶 meta.force 重送，才一併刪除全部候選列。
 *
 * 與 MirrorSuspectedException（update 路徑、碼漂移疑似）區分：本例是 delete 路徑、合法反向碼集命中多筆的「人工裁決」。
 * 僅 v2 direct 非強制路徑啟用；legacy／proposal／強制（meta.force）不拋。
 */
class MirrorDeleteMultipleException extends RuntimeException {
    /**
     * @param string $mirrorTable 對面鏡像列所在表（KIN_DATA）
     * @param array<int,array<string,mixed>> $candidates 將被一併刪除的反向列清單（供前端列出 + 組連結）
     */
    public function __construct(
        public readonly string $mirrorTable,
        public readonly array $candidates,
        string $message = ''
    ) {
        parent::__construct($message !== '' ? $message : '對面有多筆對應的反向關係列，為避免一次刪除多筆已中止，請確認後再刪除。');
    }

    /** 候選列筆數。 */
    public function count(): int {
        return count($this->candidates);
    }
}
