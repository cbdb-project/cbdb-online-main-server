<?php

namespace App\Services\Mutations;

use RuntimeException;

/**
 * 雙向鏡像同步「疑似匹配」中止（#70 新特性）。
 *
 * 當 direct 更新觸發後台雙向鏡像同步、嚴格定位（對方/本人/書名/首年 + 關係碼 ∈ 正向舊碼的合法反向集）
 * 落空，但「放寬查詢」（去掉關係碼約束、其餘相同）在對面查到 N 條疑似同一關係的列（其碼已漂移出合法反向集）時拋出。
 *
 * 與 MirrorConflictException（對面內容真分歧、鏡像列已找到）區分：本例是「按反向碼找不到鏡像、但對面有疑似列」，
 * 若逕自 backfill 會補出重複鏡像列。故在 handleDirect 的 DB::transaction 內拋出 → 整筆回滾，回 409 + 疑似明細，
 * 供前端彈「對面無匹配反向碼／有 N 條疑似」警告 + 可點連結跳對面 + 強制收斂。
 *
 * 放寬查詢僅作「UI 疑似提示」之用，不入冪等／確定性匹配規則。
 * 僅 v2 direct 非強制路徑啟用（detectConflict=true）；legacy／proposal／強制不啟用。
 */
class MirrorSuspectedException extends RuntimeException {
    /**
     * @param string $mirrorTable 對面鏡像列所在表（ASSOC_DATA / KIN_DATA）
     * @param array<int,array<string,mixed>> $candidates 疑似列主鍵清單（供前端組 edit-v2 連結；可能多筆）
     * @param int $authoritativeCode 本側欲寫入對面的權威反向碼（強制收斂目標）
     */
    public function __construct(
        public readonly string $mirrorTable,
        public readonly array $candidates,
        public readonly int $authoritativeCode,
        string $message = ''
    ) {
        parent::__construct($message !== '' ? $message : '對面找不到與本關係匹配的反向碼，但偵測到疑似同一關係的記錄，為避免補出重複鏡像已中止同步');
    }

    /** 疑似列筆數。 */
    public function count(): int {
        return count($this->candidates);
    }
}
