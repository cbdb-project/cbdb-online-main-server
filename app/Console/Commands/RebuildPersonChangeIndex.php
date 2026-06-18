<?php

namespace App\Console\Commands;

use App\Services\PersonChangeIndexService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 重建 / 刷新 person_change_index（人物層級建檔／最後修改水位線）。
 *
 * 設計見 docs/PERSON_CHANGE_INDEX_DESIGN.md。重點：
 * - 日常增量由 AuditLogService 即時維護；此命令供初始化全量回填、定期校正、手動刷新
 *   （有些歷史記錄不在 audit_log 裡，必須從來源表完整重算）。
 * - 無鎖刷新：分批 NULL-safe GREATEST upsert，水位線單調不回退，可與線上流量並行；
 *   不使用 LOCK TABLES、不開單一巨大交易。
 * - 低配伺服器省資源：c_personid 範圍分段（不用 OFFSET、不對複合主鍵亂用 chunkById）、
 *   分段提交、disableQueryLog、gc、named lock 防併發。
 */
class RebuildPersonChangeIndex extends Command {
    protected $signature = 'cbdb:rebuild-person-change-index
                            {--chunk=2000 : 每段處理的 c_personid 區間寬度（低配機建議偏小）}
                            {--commit-interval=5000 : 每處理多少筆 c_personid 提交一次並開新交易}
                            {--since= : 只重算此時間（YYYY-MM-DD HH:MM:SS）後有變更的人物}
                            {--id-from= : 只重算 c_personid >= 此值}
                            {--id-to= : 只重算 c_personid <= 此值}
                            {--person= : 只重算單一 c_personid（除錯）}
                            {--prune : 額外清除孤兒列（BIOG_MAIN 已不存在的 c_personid）}
                            {--sleep=0 : 每段提交後 usleep 毫秒，降載讓出 CPU}';

    protected $description = '重建／刷新 person_change_index 人物層級修改水位線';

    /**
     * 14 張人物相關來源表，全部具 datetime 的 c_created_date / c_modified_date。
     * 其中 c_personid 可能為 nullable（POSTED_TO_ADDR_DATA / POSTED_TO_OFFICE_DATA /
     * POSSESSION_DATA），聚合時一律 WHERE c_personid IS NOT NULL。
     */
    protected const SOURCE_TABLES = [
        'ALTNAME_DATA',
        'ASSOC_DATA',
        'BIOG_ADDR_DATA',
        'BIOG_INST_DATA',
        'BIOG_MAIN',
        'BIOG_SOURCE_DATA',
        'BIOG_TEXT_DATA',
        'ENTRY_DATA',
        'EVENTS_DATA',
        'KIN_DATA',
        'POSSESSION_DATA',
        'POSTED_TO_ADDR_DATA',
        'POSTED_TO_OFFICE_DATA',
        'STATUS_DATA',
    ];

    /**
     * audit_log.table_name 可能出現的別名（BIOG_TEXT_DATA 在部分路徑記為 TEXT_DATA）。
     */
    protected const AUDIT_SCOPE_TABLES = [
        'ALTNAME_DATA',
        'ASSOC_DATA',
        'BIOG_ADDR_DATA',
        'BIOG_INST_DATA',
        'BIOG_MAIN',
        'BIOG_SOURCE_DATA',
        'BIOG_TEXT_DATA',
        'TEXT_DATA',
        'ENTRY_DATA',
        'EVENTS_DATA',
        'KIN_DATA',
        'POSSESSION_DATA',
        'POSTED_TO_ADDR_DATA',
        'POSTED_TO_OFFICE_DATA',
        'STATUS_DATA',
    ];

    protected const LOCK_NAME = 'cbdb:rebuild-person-change-index';

    protected PersonChangeIndexService $pci;

    public function handle(PersonChangeIndexService $pci): int {
        $this->pci = $pci;

        if (!Schema::hasTable('person_change_index')) {
            $this->error('資料表 person_change_index 不存在，請先執行 migrations。');

            return 1;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $commitInterval = max(1, (int) $this->option('commit-interval'));
        $since = $this->normalizeSince($this->option('since'));
        $sleepMs = max(0, (int) $this->option('sleep'));
        [$idFrom, $idTo] = $this->resolveIdRange();

        if ($idFrom !== null && $idTo !== null && $idFrom > $idTo) {
            $this->error(sprintf('id 範圍無效：from(%d) > to(%d)', $idFrom, $idTo));

            return 1;
        }

        // named lock 防併發（advisory，不鎖資料表）；SQLite 測試環境略過。
        if (is_mysql() && !$this->acquireDbLock()) {
            $this->error('已有另一個 cbdb:rebuild-person-change-index 正在執行，已拒絕重複啟動。');

            return 1;
        }

        // 禁用查詢日誌避免記憶體累積。
        DB::connection()->disableQueryLog();

        $startedAt = microtime(true);
        $this->info('開始重建 person_change_index...');
        $this->line(sprintf(
            '參數：chunk=%d, commit-interval=%d, since=%s, id-from=%s, id-to=%s, prune=%s',
            $chunk,
            $commitInterval,
            $since ?? '（無）',
            $idFrom ?? '（無）',
            $idTo ?? '（無）',
            $this->option('prune') ? 'yes' : 'no'
        ));

        try {
            [$rangeLo, $rangeHi] = $this->resolveScanRange($idFrom, $idTo);
            if ($rangeLo === null || $rangeHi === null) {
                $this->warn('BIOG_MAIN 沒有可處理的 c_personid，跳過來源表掃描。');
            } else {
                foreach (self::SOURCE_TABLES as $table) {
                    $this->processSourceTable($table, $rangeLo, $rangeHi, $chunk, $commitInterval, $since, $sleepMs);
                }
            }

            $this->processAuditLog($since, $idFrom, $idTo, $commitInterval, $sleepMs);

            if ($this->option('prune')) {
                $this->pruneOrphans($chunk, $idFrom, $idTo);
            }
        } catch (Throwable $e) {
            $this->error('重建失敗：' . $e->getMessage());

            return 1;
        } finally {
            if (is_mysql()) {
                $this->releaseDbLock();
            }
        }

        $this->info(sprintf('person_change_index 重建完成，耗時 %.2f 秒。', microtime(true) - $startedAt));
        $this->line(sprintf('目前列數：%s', number_format((int) DB::table('person_change_index')->count())));

        return 0;
    }

    /**
     * 逐來源表：以 c_personid 範圍分段聚合 MAX 日期，NULL-safe GREATEST upsert。
     * BIOG_MAIN 額外權威覆寫 c_created_date（人物建檔時間）。
     */
    protected function processSourceTable(
        string $table,
        int $rangeLo,
        int $rangeHi,
        int $chunk,
        int $commitInterval,
        ?string $since,
        int $sleepMs
    ): void {
        if (!Schema::hasTable($table)
            || !Schema::hasColumn($table, 'c_personid')
            || !Schema::hasColumn($table, 'c_modified_date')
            || !Schema::hasColumn($table, 'c_created_date')) {
            $this->line(sprintf('  - 跳過 %s（表或日期欄不存在）', $table));

            return;
        }

        $writeCreated = $table === 'BIOG_MAIN';
        $processed = 0;
        $buffer = [];

        // BIOG_MAIN 是 c_created_date 的權威來源（一人一列），即使在 --since 模式也必須全範圍掃描，
        // 否則「只在子資源/audit 於 since 後變更」的人物，其 c_created_date 不會被權威回填而留下 NULL/舊值。
        // --since 的省資源優化只套用在量大的子資源表與 audit_log。
        $applySince = $since !== null && $table !== 'BIOG_MAIN';

        for ($lo = $rangeLo; $lo <= $rangeHi; $lo += $chunk) {
            $hi = min($lo + $chunk - 1, $rangeHi);

            $query = DB::table($table)
                ->selectRaw('c_personid, MAX(c_modified_date) AS m, MAX(c_created_date) AS cr')
                ->whereNotNull('c_personid')
                ->whereBetween('c_personid', [$lo, $hi]);

            if ($applySince) {
                $query->where(function ($w) use ($since) {
                    $w->where('c_modified_date', '>=', $since)
                        ->orWhere('c_created_date', '>=', $since);
                });
            }

            $rows = $query->groupBy('c_personid')->get();

            foreach ($rows as $row) {
                $buffer[] = [
                    'c_personid' => (int) $row->c_personid,
                    'last' => $row->m,
                    'created' => $writeCreated ? $row->cr : null,
                ];
                $processed++;

                if (count($buffer) >= $commitInterval) {
                    $this->flushBuffer($buffer, $writeCreated, $sleepMs);
                    $buffer = [];
                }
            }
        }

        if (!empty($buffer)) {
            $this->flushBuffer($buffer, $writeCreated, $sleepMs);
        }

        $this->line(sprintf('  - %s：聚合 %s 個 c_personid（記憶體 %s MB）', $table, number_format($processed), $this->memUsageMb()));
    }

    /**
     * audit_log 一輪：補「子資源 update 未回寫自身 c_modified_date」缺口及任何不在來源表自身日期欄的變更。
     * audit_log 無 c_personid 欄（在 row_pk/new_data/old_data 的 JSON 內），須掃描 + 解析。
     *
     * 逐 table_name 處理，並以 (occurred_at, id) keyset 分頁——使查詢能完全吃到
     * (table_name, occurred_at, id) 複合索引（table_name 等值 + occurred_at 範圍 + ORDER BY occurred_at, id），
     * 避免 filesort/temp；而非用 chunkById('id') 強制按 PK 排序導致索引失效、退化成全表掃描。
     * occurred_at 為 NOT NULL，搭配唯一遞增的 id 當 tie-breaker，keyset 穩定不漏不重。
     */
    protected function processAuditLog(?string $since, ?int $idFrom, ?int $idTo, int $commitInterval, int $sleepMs): void {
        if (!Schema::hasTable('audit_log')) {
            $this->line('  - 跳過 audit_log（表不存在）');

            return;
        }

        $pageSize = 2000;
        $buffer = [];
        $processed = 0;

        foreach (self::AUDIT_SCOPE_TABLES as $tableName) {
            $cursorOccurred = null;
            $cursorId = null;

            while (true) {
                $query = DB::table('audit_log')
                    ->select(['id', 'occurred_at', 'row_pk', 'new_data', 'old_data'])
                    ->where('table_name', $tableName);

                if ($since !== null) {
                    $query->where('occurred_at', '>=', $since);
                }

                if ($cursorOccurred !== null) {
                    $query->where(function ($w) use ($cursorOccurred, $cursorId) {
                        $w->where('occurred_at', '>', $cursorOccurred)
                            ->orWhere(function ($w2) use ($cursorOccurred, $cursorId) {
                                $w2->where('occurred_at', '=', $cursorOccurred)
                                    ->where('id', '>', $cursorId);
                            });
                    });
                }

                $logs = $query->orderBy('occurred_at')->orderBy('id')->limit($pageSize)->get();
                if ($logs->isEmpty()) {
                    break;
                }

                foreach ($logs as $log) {
                    $cursorOccurred = $log->occurred_at;
                    $cursorId = $log->id;

                    $personId = $this->resolvePersonId($log);
                    if ($personId === null) {
                        continue;
                    }
                    if ($idFrom !== null && $personId < $idFrom) {
                        continue;
                    }
                    if ($idTo !== null && $personId > $idTo) {
                        continue;
                    }

                    // 同一 buffer 內保留較大的 occurred_at；GREATEST upsert 仍會與表內現值合併。
                    if (!isset($buffer[$personId]) || $log->occurred_at > $buffer[$personId]) {
                        $buffer[$personId] = $log->occurred_at;
                    }
                    $processed++;

                    if (count($buffer) >= $commitInterval) {
                        $this->flushAuditBuffer($buffer, $sleepMs);
                        $buffer = [];
                    }
                }

                if ($logs->count() < $pageSize) {
                    break;
                }
            }
        }

        if (!empty($buffer)) {
            $this->flushAuditBuffer($buffer, $sleepMs);
        }

        $this->line(sprintf('  - audit_log：掃描 %s 筆相關記錄（記憶體 %s MB）', number_format($processed), $this->memUsageMb()));
    }

    /**
     * 從 audit_log 一列解析 c_personid：解碼 JSON 欄位後委派共用服務（row_pk → new_data → old_data）。
     */
    protected function resolvePersonId(object $log): ?int {
        return $this->pci->resolvePersonId(
            $this->decodeJsonColumn($log->row_pk ?? null),
            $this->decodeJsonColumn($log->new_data ?? null),
            $this->decodeJsonColumn($log->old_data ?? null)
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decodeJsonColumn($raw): ?array {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<int, array{c_personid:int,last:mixed,created:mixed}> $buffer
     */
    protected function flushBuffer(array $buffer, bool $writeCreated, int $sleepMs): void {
        if (empty($buffer)) {
            return;
        }

        $now = now()->format('Y-m-d H:i:s');
        $rows = [];
        foreach ($buffer as $item) {
            $rows[] = [
                'c_personid' => $item['c_personid'],
                'c_last_modified_date' => $item['last'],
                'c_created_date' => $writeCreated ? $item['created'] : null,
                'updated_at' => $now,
            ];
        }

        $this->upsertWatermark($rows, $writeCreated);
        $this->throttle($sleepMs);
    }

    /**
     * @param array<int,string> $buffer  c_personid => occurred_at
     */
    protected function flushAuditBuffer(array $buffer, int $sleepMs): void {
        if (empty($buffer)) {
            return;
        }

        $now = now()->format('Y-m-d H:i:s');
        $rows = [];
        foreach ($buffer as $personId => $occurredAt) {
            $rows[] = [
                'c_personid' => (int) $personId,
                'c_last_modified_date' => $occurredAt,
                'c_created_date' => null,
                'updated_at' => $now,
            ];
        }

        $this->upsertWatermark($rows, false);
        $this->throttle($sleepMs);
    }

    /**
     * NULL-safe GREATEST upsert，分子批寫入，包在具死鎖重試的短交易內。
     *
     * @param array<int, array<string, mixed>> $rows
     */
    protected function upsertWatermark(array $rows, bool $writeCreated): void {
        // 共用 NULL-safe GREATEST upsert（與即時路徑 AuditLogService 同一份邏輯，集中於 PersonChangeIndexService）。
        // 每子批包在具死鎖重試的短交易內；不開單一巨大交易、不鎖表。
        foreach (array_chunk($rows, 500) as $batch) {
            DB::transaction(function () use ($batch, $writeCreated) {
                $this->pci->upsert($batch, $writeCreated);
            }, 3);
        }

        // 每次 flush 後主動回收，避免低配機上逐段聚合大表時記憶體單調累積。
        gc_collect_cycles();
    }

    /**
     * 清除孤兒列（person_change_index 有、但 BIOG_MAIN 已無的 c_personid），分批避免大刪除。
     * 套用 --id-from/--id-to 夾擠：分段續跑時只清該段孤兒，避免每段全表掃描。
     */
    protected function pruneOrphans(int $chunk, ?int $idFrom, ?int $idTo): void {
        $deleted = 0;
        do {
            $query = DB::table('person_change_index as p')
                ->leftJoin('BIOG_MAIN as b', 'p.c_personid', '=', 'b.c_personid')
                ->whereNull('b.c_personid');

            if ($idFrom !== null) {
                $query->where('p.c_personid', '>=', $idFrom);
            }
            if ($idTo !== null) {
                $query->where('p.c_personid', '<=', $idTo);
            }

            $ids = $query->limit($chunk)->pluck('p.c_personid')->all();

            if (!empty($ids)) {
                $deleted += DB::table('person_change_index')->whereIn('c_personid', $ids)->delete();
            }
        } while (!empty($ids));

        $this->line(sprintf('  - prune：清除 %s 個孤兒 c_personid', number_format($deleted)));
    }

    protected function throttle(int $sleepMs): void {
        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    }

    protected function memUsageMb(): string {
        return number_format(memory_get_usage(true) / 1024 / 1024, 2);
    }

    protected function normalizeSince(?string $since): ?string {
        $since = $since ? trim($since) : '';
        if ($since === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($since)->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            $this->warn(sprintf('--since 無法解析（%s），忽略此參數。', $since));

            return null;
        }
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    protected function resolveIdRange(): array {
        $person = $this->option('person');
        if ($person !== null && $person !== '') {
            $pid = (int) $person;

            return [$pid, $pid];
        }

        $from = $this->option('id-from');
        $to = $this->option('id-to');

        return [
            ($from !== null && $from !== '') ? (int) $from : null,
            ($to !== null && $to !== '') ? (int) $to : null,
        ];
    }

    /**
     * 以 BIOG_MAIN 的 c_personid 範圍為掃描基準，套用 id-from/id-to 夾擠。
     *
     * @return array{0: ?int, 1: ?int}
     */
    protected function resolveScanRange(?int $idFrom, ?int $idTo): array {
        $min = DB::table('BIOG_MAIN')->min('c_personid');
        $max = DB::table('BIOG_MAIN')->max('c_personid');

        if ($min === null || $max === null) {
            return [null, null];
        }

        $lo = max((int) $min, $idFrom ?? (int) $min);
        $hi = min((int) $max, $idTo ?? (int) $max);

        if ($lo > $hi) {
            return [null, null];
        }

        return [$lo, $hi];
    }

    protected function acquireDbLock(): bool {
        $row = DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired_lock', [self::LOCK_NAME]);
        $value = is_object($row) ? ($row->acquired_lock ?? null) : null;

        return (int) $value === 1;
    }

    protected function releaseDbLock(): void {
        try {
            DB::selectOne('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        } catch (Throwable $e) {
            // 忽略釋放鎖失敗，避免覆蓋原始錯誤。
        }
    }
}
