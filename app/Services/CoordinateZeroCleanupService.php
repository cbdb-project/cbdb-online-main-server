<?php

namespace App\Services;

use App\Support\CoordinatePairNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * 把既有資料裡殘留的零／半截座標掃成 `NULL`。
 *
 * ## 為什麼需要一個「事後清掃」而不只是寫入端守衛
 *
 * 寫入端守衛（{@see CoordinatePairNormalizer} 及其掛鉤）只管**新的寫入**。它管不到：
 *
 *  - **上游資料重灌**。`ADDR_CODES` 的 316 列 `0,0` 就是這樣來的——那些列的
 *    `c_created_by`／`c_modified_by` 全部是 `NULL`，也就是**從來沒有被應用寫過**，
 *    是當年原始 CBDB 匯入帶進來的。下一次上游重灌會再帶一批。
 *  - **從零建起的部署**。跑完所有 migration 的新環境若匯入了舊 dump，同樣帶著零值。
 *
 * 所以這個 service 是那道「地板」：跑完之後全庫沒有零座標，而且**重複跑是幂等的**
 * （`NULL` 既非空白亦非零，第二次什麼都不做）。它由兩個呼叫端共用，邏輯只有一份：
 *  - `php artisan cbdb:normalize-addr-coords`（隨時可跑，尤其是每次上游匯入之後）；
 *  - 一支一次性的 data migration，讓每個部署與每個 migrate-from-scratch 的環境都**自動**
 *    到達這個地板，不必有人記得去跑指令。
 *
 * ## 刻意不寫 operations／audit_log
 *
 * 這是**資料清理**，不是 316 次編輯行為。逐列寫稽核記錄會用一批毫無資訊量的列淹掉
 * operations 頁（每一筆的內容都是「0 → NULL」），而真正該留下痕跡的地方是
 * `CHANGELOG.md` 與這支 service 的輸出。同理不蓋 `c_modified_*`：
 * AGENTS.md §1.2 的語義是「最後一次**實際的**寫入」，把 316 列的最後修改者都改成執行清理
 * 的那個人，會抹掉「這列上次真的被誰改過」這個事實，而那個事實比清理這件事更有價值。
 *
 * **這條與寫入端的規則不衝突**：使用者送出的每一次歸零仍然走 handler、仍然寫稽核、
 * 仍然回通知。豁免的只有這支批次工具。
 *
 * ## 不做的事
 *
 * 不嘗試從 CHGIS 回填座標。2026-09-14 的那次清理裡有 12 列是從 CHGIS 回復出真實座標的
 * （3 列取自共用同一 `CHGIS_PT_ID` 的既有列、9 列取自 CHGIS gazetteer），但那需要外部
 * 服務、需要逐列判斷兩個來源不一致時取哪個，不是一支應該在 migration 裡自動跑的東西。
 * 這支只負責「不要留下謊稱是座標的 0」。要回填請走 v2 API，逐列留下稽核。
 */
class CoordinateZeroCleanupService {
    /**
     * 掃描並清理一張表的零／半截座標。
     *
     * @param string $table 目標資料表（必須登記在 `CoordinatePairNormalizer::PAIRS`）
     * @param bool $dryRun true 時只統計、不寫入
     * @return array{table: string, scanned: int, cleared: int, skipped_non_numeric: array<int, array<string, mixed>>}
     */
    public function cleanTable(string $table, bool $dryRun = false): array {
        $pairs = CoordinatePairNormalizer::pairsFor($table);
        if ($pairs === []) {
            throw new \InvalidArgumentException($table.' 沒有登記座標欄位對，無法清理。');
        }

        $keyColumn = $this->keyColumnFor($table);
        $columns = [];
        foreach ($pairs as $pair) {
            foreach ($pair as $column) {
                $columns[] = $column;
            }
        }

        $scanned = 0;
        $cleared = 0;
        $skipped = [];

        // 只撈「可能需要處理」的列，不是全表：零、或一軸為零、或一軸 NULL 另一軸有值。
        // 逐塊處理避免把整張表讀進記憶體（ADDR_CODES 三萬列，將來可能更多）。
        $query = DB::table($table)->select(array_merge([$keyColumn], $columns));
        $query->where(function ($q) use ($columns) {
            foreach ($columns as $column) {
                $q->orWhere($column, '=', 0);
                // 「一軸 NULL、另一軸有值」的半截列：把有值那一軸也清掉。
                $q->orWhere(function ($inner) use ($column, $columns) {
                    $inner->whereNull($column);
                    foreach ($columns as $other) {
                        if ($other !== $column) {
                            $inner->whereNotNull($other);
                        }
                    }
                });
            }
        });

        foreach ($query->orderBy($keyColumn)->cursor() as $row) {
            ++$scanned;
            $rowArray = (array) $row;
            $key = $rowArray[$keyColumn];

            $invalid = CoordinatePairNormalizer::invalidColumns($rowArray, $table);
            if ($invalid !== []) {
                // 資料庫裡存著一個不是數的座標（理論上進不來，但舊資料什麼都可能有）。
                // 不猜、不清，列出來讓人看。
                $skipped[] = [$keyColumn => $key, 'columns' => array_keys($invalid)];

                continue;
            }

            $result = CoordinatePairNormalizer::normalizeRow($rowArray, $table, true);
            $changes = [];
            foreach ($columns as $column) {
                if (($rowArray[$column] ?? null) !== null && $result['data'][$column] === null) {
                    $changes[$column] = null;
                }
            }
            if ($changes === []) {
                continue;
            }

            ++$cleared;
            if (!$dryRun) {
                DB::table($table)->where($keyColumn, $key)->update($changes);
            }
        }

        return [
            'table' => $table,
            'scanned' => $scanned,
            'cleared' => $cleared,
            'skipped_non_numeric' => $skipped,
        ];
    }

    /**
     * 用來定位單列的欄位。
     *
     * `ADDR_CODES` 有真正的主鍵；`ADDRESSES` 沒有（原始 schema 只有一個非唯一 `KEY`），
     * 所以對它不做逐列更新——它是 `cbdb:regenerate-addresses-table` 由 `ADDR_CODES` 以
     * `INSERT ... SELECT` 重建的派生快取，源頭清乾淨、重建一次就好，在派生物上逐列改只會
     * 與源頭不一致。
     */
    private function keyColumnFor(string $table): string {
        $known = [
            'ADDR_CODES' => 'c_addr_id',
        ];
        $upper = strtoupper($table);
        if (!isset($known[$upper])) {
            throw new \InvalidArgumentException(
                $table.' 沒有可用來逐列定位的主鍵，不支援清理。'
                .'（`ADDRESSES` 是派生快取，請清乾淨 `ADDR_CODES` 後重跑 '
                .'`php artisan cbdb:regenerate-addresses-table`。）'
            );
        }

        return $known[$upper];
    }
}
