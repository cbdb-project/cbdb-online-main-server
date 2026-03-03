<?php

namespace App\Console\Commands;

use App\Services\IndexAddressRebuildService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class RebuildIndexAddress extends Command {
    protected $signature = 'cbdb:rebuild-index-address {--show-sql : 輸出每一步實際執行的 SQL}';

    protected $description = '全量重建 BIOG_MAIN 的 index address 欄位（MySQL/MariaDB）';

    public function handle(IndexAddressRebuildService $service): int {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $this->error('cbdb:rebuild-index-address 不支援 SQLite，請在 MariaDB/MySQL 環境執行。');

            return 1;
        }

        if ($driver !== 'mysql') {
            $this->error(sprintf('cbdb:rebuild-index-address 目前僅支援 MariaDB/MySQL，當前 driver: %s', $driver));

            return 1;
        }

        foreach (['BIOG_MAIN', 'BIOG_ADDR_DATA', 'BIOG_ADDR_CODES', 'ADDR_CODES'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->error(sprintf('缺少必要資料表：%s', $table));

                return 1;
            }
        }

        $lockName = 'cbdb:rebuild-index-address';
        if (!$this->acquireDbLock($lockName)) {
            $this->error('已有另一個 cbdb:rebuild-index-address 正在執行，已拒絕重複啟動。');

            return 1;
        }

        $startedAt = microtime(true);
        $showSql = (bool) $this->option('show-sql');
        $this->info('開始全量重建 BIOG_MAIN index address...');
        $this->line('策略：逐條規則各自提交（避免單一大 transaction 鎖表）、按 c_index_addr_rank(<100) 循環、Max(c_sequence) 子查詢聚合、首命中優先');
        if ($showSql) {
            $this->line('模式：已開啟 SQL 輸出（每條規則執行前顯示 SQL）');
        }

        try {
            $stats = $service
                ->setLogger(function (string $message): void {
                    $this->line($message);
                })
                ->setShowSql($showSql)
                ->rebuild();

            $elapsed = microtime(true) - $startedAt;
            $this->newLine();
            $this->info('重建完成');
            $this->renderStats($stats, $elapsed);

            return 0;
        } catch (Throwable $e) {
            $this->error('重建失敗。');
            $this->error($e->getMessage());

            return 1;
        } finally {
            $this->releaseDbLock($lockName);
        }
    }

    protected function acquireDbLock(string $lockName): bool {
        $row = DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired_lock', [$lockName]);
        $value = is_object($row) ? ($row->acquired_lock ?? null) : null;

        return (int) $value === 1;
    }

    protected function releaseDbLock(string $lockName): void {
        try {
            DB::selectOne('SELECT RELEASE_LOCK(?)', [$lockName]);
        } catch (Throwable $e) {
            // 忽略釋放鎖失敗，避免覆蓋原始錯誤。
        }
    }

    /**
     * @param array<string, mixed> $stats
     */
    protected function renderStats(array $stats, float $elapsed): void {
        $this->line(sprintf('地址類型數（rank<100）：%d', (int) ($stats['addr_type_count'] ?? 0)));
        $this->line(sprintf('RESET 更新筆數：%d', (int) ($stats['reset'] ?? 0)));

        $this->line('每種地址類型更新統計：');
        foreach (($stats['per_type_updates'] ?? []) as $row) {
            $this->line(sprintf(
                '  c_addr_type=%d (rank=%d): %d',
                (int) ($row['c_addr_type'] ?? 0),
                (int) ($row['rank'] ?? 0),
                (int) ($row['updated'] ?? 0)
            ));
        }

        $this->line(sprintf('最終 c_index_addr_id 非空筆數：%d', (int) ($stats['filled_count'] ?? 0)));
        $this->line(sprintf('總更新筆數（含 reset 與各類型更新次數累計）：%d', (int) ($stats['total_updates'] ?? 0)));
        $this->line(sprintf('耗時：%.2f 秒', $elapsed));
    }
}
