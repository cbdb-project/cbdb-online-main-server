<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * 稽核欄（c_created_by／c_modified_by）署名的請求級覆寫。
 *
 * 語義（2026-08-05 定案）：稽核欄記錄「最後一次實際寫入」的時刻與操作者——核准提案、
 * 還原記錄都是寫入，一律蓋當下，不從提案 payload 或歷史快照沿用舊值。
 *
 * 核准提案時，實際寫入者是審核人、內容來自提案人，署名採雙人名格式：
 * 「審核人 (Proposed by: 提案人)」。由 OperationsProposalController 在套用提案前
 * override()、finally 中 clear()；其間所有經 ToolsRepository::timestamp() 的寫入
 * （含 handler 重放、鏡像同步）都採此署名。平時（無 override）行為不變＝當前登入者。
 */
class AuditActor {
    private static ?string $override = null;

    /** 設定本請求後續寫入使用的署名（呼叫端負責在 finally 中 clear()）。 */
    public static function override(string $name): void {
        self::$override = $name;
    }

    public static function clear(): void {
        self::$override = null;
    }

    /** 目前應寫入稽核欄的署名：override 優先，否則當前登入者。截斷至 255 防溢位。 */
    public static function currentName(): string {
        $name = self::$override ?? (Auth::user()?->name ?? (string) Auth::id());

        return mb_substr($name, 0, 255);
    }

    /**
     * 組出核准提案時的雙人名署名：「審核人 (Proposed by: 提案人)」。
     * 提案人缺失或與審核人相同時，只回審核人單名。
     */
    public static function approvalName(?string $proposer): string {
        $reviewer = Auth::user()?->name ?? (string) Auth::id();
        $proposer = trim((string) $proposer);
        if ($proposer === '' || $proposer === $reviewer) {
            return $reviewer;
        }

        return "{$reviewer} (Proposed by: {$proposer})";
    }
}
