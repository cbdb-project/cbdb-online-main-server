<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 將 ASSOC_DATA 中 c_text_title 為空字串的資料正規化為 '[n/a]'。
 *
 * 背景：
 * - ASSOC_DATA.c_text_title 是 9-key 複合主鍵的一部分，'[n/a]' 為現行主流慣例（68203+ 筆）
 * - 歷史上有 294 筆空字串紀錄（2018 年 peter bol 早期建立），語意等同「未知出處」
 * - 手動編輯表單現已統一 fallback 為 '[n/a]'，本 migration 補齊歷史資料
 *
 * 本 migration 是一次性資料清理，不寫 operations 審計紀錄。
 * 若遇到 8-key 前綴相同但 c_text_title 分別為 '' 與 '[n/a]' 的衝突組，
 * 直接刪除 '[n/a]' 版、保留較早的 '' 版，讓後續 UPDATE 不會撞 9-key 主鍵。
 */
class NormalizeAssocDataEmptyTextTitle extends Migration {
    public function up(): void {
        if (!Schema::hasTable('ASSOC_DATA')) {
            return;
        }

        DB::transaction(function () {
            $this->resolveDuplicatePkConflicts();
            $normalized = DB::table('ASSOC_DATA')
                ->where('c_text_title', '')
                ->update(['c_text_title' => '[n/a]']);

            if ($normalized > 0) {
                echo "ASSOC_DATA: normalized {$normalized} rows (c_text_title '' -> '[n/a]')\n";
            }
        });
    }

    public function down(): void {
        // 不還原：'[n/a]' 是 CBDB 的既有慣例，migration 前的空字串紀錄
        // 無法和其他歷史上就寫入 '[n/a]' 的紀錄區分，down 會造成資料污染。
    }

    /**
     * 找出 8-key 前綴相同、c_text_title 同時存在 '' 與 '[n/a]' 版的衝突組，
     * 刪除 '[n/a]' 版以讓後續 UPDATE 不會違反 9-key 主鍵。
     */
    protected function resolveDuplicatePkConflicts(): void {
        $prefixCols = ['c_personid', 'c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id', 'c_assoc_kin_code', 'c_assoc_kin_id', 'c_assoc_first_year'];
        $groupBy = implode(',', $prefixCols);

        $conflicts = DB::select("
            SELECT {$groupBy}
            FROM ASSOC_DATA
            WHERE c_text_title IN ('', '[n/a]')
            GROUP BY {$groupBy}
            HAVING COUNT(DISTINCT c_text_title) > 1
        ");

        foreach ($conflicts as $c) {
            $conditions = [];
            foreach ($prefixCols as $col) {
                $conditions[] = [$col, '=', $c->$col];
            }
            DB::table('ASSOC_DATA')
                ->where($conditions)
                ->where('c_text_title', '[n/a]')
                ->delete();
        }
    }
}
