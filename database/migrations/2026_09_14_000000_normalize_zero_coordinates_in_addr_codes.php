<?php

use App\Services\CoordinateZeroCleanupService;
use App\Support\CoordinatePairNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * 一次性資料清理：把 `ADDR_CODES` 裡殘留的零／半截經緯度掃成 `NULL`。
 *
 * ## 為什麼是 migration 而不只是一支指令
 *
 * 寫入端守衛（{@see CoordinatePairNormalizer} 及其掛鉤）只管**新的寫入**。真正的地板要靠
 * 事後清掃，而清掃不能靠「有人記得去跑」：
 *
 *  - 生產環境的那 316 列 `0,0` 已於 2026-09-14 經 v2 API 清理／回填（其中 12 列從 CHGIS
 *    回復出真實座標），但那是一次**手動操作**，版本控制裡沒有任何痕跡。
 *  - 任何 migrate-from-scratch 的環境、任何匯入舊 dump 的新部署，都會再帶一批零值進來。
 *
 * 所以：邏輯放在 {@see CoordinateZeroCleanupService}，這支 migration 與
 * `php artisan cbdb:normalize-addr-coords` 共用它。migration 保證每個部署**自動**到達地板
 * 一次；指令供上游每次重灌之後重跑。兩者都是幂等的。
 *
 * ## 刻意不寫 operations／audit_log、不蓋 c_modified_*
 *
 * 這是資料清理，不是 316 次編輯行為——逐列寫稽核會用一批「0 → NULL」的無資訊列淹掉
 * operations 頁，而把 316 列的最後修改者改成執行 migration 的人，會抹掉「這列上次真的被
 * 誰改過」這個更有價值的事實（AGENTS.md §1.2 的語義是「最後一次**實際的**寫入」）。
 * 痕跡留在 `CHANGELOG.md` 與指令輸出。使用者送出的每一次歸零仍然走 handler、仍然寫稽核。
 *
 * ## 不可逆（`down()` 是 no-op），而這是對的
 *
 * 把 `NULL` 還原成 `0` 會重新造出這整套機制要防的那一列，而且**無法區分**「本來就是 NULL
 * 的 14,297 列」與「被這支 migration 清成 NULL 的列」——沒有記錄哪些列被改過（見上），
 * 所以 `down()` 只能是「什麼都不做」而不是「假裝能還原」。真的需要回溯某一列的歷史值時，
 * 來源是資料庫備份，不是這支 migration。
 */
return new class () extends Migration {
    public function up(): void {
        // 測試環境（SQLite）只建立各測試自己需要的表；表不存在就沒什麼可清。
        if (!Schema::hasTable('ADDR_CODES')) {
            return;
        }
        // 欄位不存在的環境（極舊 schema）同樣直接跳過，不要炸掉整條 migration 鏈。
        foreach (CoordinatePairNormalizer::pairsFor('ADDR_CODES') as $pair) {
            foreach ($pair as $column) {
                if (!Schema::hasColumn('ADDR_CODES', $column)) {
                    return;
                }
            }
        }

        $result = app(CoordinateZeroCleanupService::class)->cleanTable('ADDR_CODES');

        // 讓 `php artisan migrate` 的輸出說出實際發生了什麼——尤其是「跳過了哪幾列」，
        // 那是需要人看的資訊，不該只留在回傳值裡。
        if ($result['cleared'] > 0) {
            echo sprintf(
                '  ADDR_CODES：已把 %d 列的零值／半截經緯度清為 NULL。'.PHP_EOL,
                $result['cleared']
            );
            echo '  提醒：ADDRESSES 是派生快取，請在合適時機執行 '
                .'`php artisan cbdb:regenerate-addresses-table`。'.PHP_EOL;
        }
        foreach ($result['skipped_non_numeric'] as $row) {
            $key = array_key_first($row);
            echo sprintf(
                '  ADDR_CODES：%s=%s 的座標欄不是數值（%s），已跳過，請人工確認。'.PHP_EOL,
                $key,
                (string) $row[$key],
                implode('、', $row['columns'])
            );
        }
    }

    public function down(): void {
        // 刻意不做任何事，理由見類註：把 NULL 還原成 0 會重新造出這套機制要防的那一列，
        // 而且沒有記錄哪些列被改過（刻意不寫稽核），所以無法只還原那些列。
    }
};
