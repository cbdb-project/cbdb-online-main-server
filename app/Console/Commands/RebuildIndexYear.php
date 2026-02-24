<?php

namespace App\Console\Commands;

use App\Services\IndexYearRebuildService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class RebuildIndexYear extends Command {
    protected $signature = 'cbdb:rebuild-index-year';

    protected $description = '全量重建 BIOG_MAIN 的 index year 欄位（MySQL/MariaDB）';

    public function handle(IndexYearRebuildService $service): int {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $this->error('cbdb:rebuild-index-year 不支援 SQLite，請在 MariaDB/MySQL 環境執行。');

            return 1;
        }

        if ($driver !== 'mysql') {
            $this->error(sprintf('cbdb:rebuild-index-year 目前僅支援 MariaDB/MySQL，當前 driver: %s', $driver));

            return 1;
        }

        foreach (['BIOG_MAIN', 'KIN_DATA', 'ENTRY_DATA', 'ENTRY_CODE_TYPE_REL'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->error(sprintf('缺少必要資料表：%s', $table));

                return 1;
            }
        }

        $lockName = 'cbdb:rebuild-index-year';
        if (!$this->acquireDbLock($lockName)) {
            $this->error('已有另一個 cbdb:rebuild-index-year 正在執行，已拒絕重複啟動。');

            return 1;
        }

        $startedAt = microtime(true);
        $this->info('開始全量重建 BIOG_MAIN index year...');
        $this->line('策略：單一 transaction、詳細規則統計、重建前清空 c_index_year / type_code / source_id');

        try {
            DB::beginTransaction();

            $stats = $service
                ->setLogger(function (string $message): void {
                    $this->line($message);
                })
                ->rebuild();

            DB::commit();

            $elapsed = microtime(true) - $startedAt;
            $this->newLine();
            $this->info('重建完成');
            $this->renderStats($stats, $elapsed);

            return 0;
        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->error('重建失敗，已回滾 transaction。');
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
        $this->line(sprintf('RESET 更新筆數：%d', (int) ($stats['reset'] ?? 0)));

        $this->line('Phase A 統計：');
        foreach (($stats['phase_a'] ?? []) as $rule => $count) {
            $this->line(sprintf('  Rule %s: %d', $rule, (int) $count));
        }

        $this->line('Phase B 統計：');
        foreach (($stats['phase_b'] ?? []) as $rule => $count) {
            $this->line(sprintf('  Rule %s: %d', $rule, (int) $count));
        }

        $this->line('Phase C（迭代）統計：');
        foreach (($stats['loops'] ?? []) as $loopNo => $loopStats) {
            $this->line(sprintf('  Loop %s:', $loopNo));
            foreach ($loopStats as $rule => $count) {
                if ($rule === '_total_new') {
                    continue;
                }
                $this->line(sprintf('    Rule %s: %d', $rule, (int) $count));
            }
            $this->line(sprintf('    Loop 新增總計: %d', (int) ($loopStats['_total_new'] ?? 0)));
        }

        $this->line(sprintf('總更新筆數（含 reset 與各規則更新次數累計）：%d', (int) ($stats['total_updates'] ?? 0)));
        $this->line(sprintf('耗時：%.2f 秒', $elapsed));
    }
}
