<?php

namespace App\Console\Commands;

use App\Services\CoordinateZeroCleanupService;
use Illuminate\Console\Command;

/**
 * 把 `ADDR_CODES` 裡殘留的零／半截座標掃成 `NULL`。
 *
 * **每次上游資料重灌之後都該跑一次。** 寫入端守衛只管新的寫入，而 `ADDR_CODES` 的 316 列
 * `0,0` 幾乎確定是匯入帶進來的（散佈整個 id 區間、涵蓋一整批同類地名）。下一次重灌會再
 * 帶一批。同理，任何匯入舊 dump 的新部署也需要這道地板。
 *
 * 幂等：`NULL` 既非空白亦非零，第二次跑什麼都不做。所以放進部署腳本或 cron 都安全。
 *
 * 邏輯在 {@see CoordinateZeroCleanupService}，與一次性的 data migration 共用同一份實作。
 */
class NormalizeAddrCoords extends Command {
    /** @var string */
    protected $signature = 'cbdb:normalize-addr-coords
                            {--table=ADDR_CODES : 目標資料表（須登記在 CoordinatePairNormalizer::PAIRS）}
                            {--dry-run : 只統計要改幾列，不實際寫入}';

    /** @var string */
    protected $description = '把 ADDR_CODES 的零值／半截經緯度掃成 NULL（幂等，建議每次上游匯入後執行）';

    public function handle(CoordinateZeroCleanupService $service): int {
        $table = (string) $this->option('table');
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('--dry-run：只統計，不會寫入任何資料。');
        }

        try {
            $result = $service->cleanTable($table, $dryRun);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s：掃出 %d 列可能有問題，%s %d 列。',
            $result['table'],
            $result['scanned'],
            $dryRun ? '將會清理' : '已清理',
            $result['cleared']
        ));

        if ($result['skipped_non_numeric'] !== []) {
            // 資料庫裡存著一個不是數的座標。不猜、不清——列出來讓人看。
            $this->newLine();
            $this->warn('以下列的座標欄不是數值，已跳過（請人工確認後處理）：');
            foreach ($result['skipped_non_numeric'] as $row) {
                $key = array_key_first($row);
                $this->line(sprintf('  %s=%s：%s', $key, (string) $row[$key], implode('、', $row['columns'])));
            }
        }

        // 座標變了，派生快取就過時了。不自動跑——那是一支會 truncate 整張表的重量級指令，
        // 不該當成別人的副作用。
        if ($result['cleared'] > 0 && !$dryRun) {
            $this->newLine();
            $this->comment('提醒：ADDRESSES 是由 ADDR_CODES 重建的派生快取。座標已變更，');
            $this->comment('      請在合適的時機執行 `php artisan cbdb:regenerate-addresses-table`。');
            // 刻意不點名「posting 自動填充」與「朝代同名消歧」——查過了：
            // `PostingAutofillService` 對 x_coord／y_coord **零引用**，它查 ADDRESSES 只取
            // 名稱與年份欄，其餘位址查詢打的是已清乾淨的 ADDR_CODES。會看到舊座標的是
            // /app/codes/ADDRESSES 瀏覽頁、Query Playground 的原始 SQL，以及下游匯出。
            $this->comment('      在那之前，/app/codes/ADDRESSES、Query Playground 與下游匯出仍會看到舊座標。');
        }

        return self::SUCCESS;
    }
}
