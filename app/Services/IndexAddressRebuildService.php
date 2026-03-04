<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class IndexAddressRebuildService {
    /**
     * @var callable|null
     */
    protected $logger;
    protected bool $showSql = false;
    protected int $batchSize = 500;

    public function setLogger(?callable $logger): self {
        $this->logger = $logger;

        return $this;
    }

    public function setShowSql(bool $showSql): self {
        $this->showSql = $showSql;

        return $this;
    }

    public function setBatchSize(int $batchSize): self {
        $this->batchSize = max(1, $batchSize);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function rebuild(): array {
        DB::connection()->disableQueryLog();

        $addrTypes = DB::table('BIOG_ADDR_CODES')
            ->select('c_addr_type', 'c_index_addr_rank')
            ->whereNotNull('c_index_addr_rank')
            ->where('c_index_addr_rank', '<', 100)
            ->orderBy('c_index_addr_rank')
            ->orderBy('c_addr_type')
            ->get();

        $stats = [
            'addr_type_count' => $addrTypes->count(),
            'addr_types' => [],
            'reset' => 0,
            'per_type_updates' => [],
            'total_updates' => 0,
            'staged_count' => 0,
            'swap_updated' => 0,
            'clear_count' => 0,
            'batch_size' => $this->batchSize,
            'batch_count' => 0,
        ];

        foreach ($addrTypes as $row) {
            $stats['addr_types'][] = [
                'c_addr_type' => (int) $row->c_addr_type,
                'rank' => (int) $row->c_index_addr_rank,
            ];
        }

        $this->log(sprintf('載入地址類型優先級：%d 個（c_index_addr_rank < 100）', $stats['addr_type_count']));
        $batchRanges = $this->buildBatchRanges($this->batchSize);
        $stats['batch_count'] = count($batchRanges);
        if ($stats['batch_count'] > 0) {
            $this->log(sprintf('分批設定：batch_size=%d，批次數=%d（依 c_personid 區間）', $this->batchSize, $stats['batch_count']));
        } else {
            $this->log('BIOG_MAIN 無資料，略過重建。');
            $stats['filled_count'] = 0;

            return $stats;
        }

        $this->dropTempTables();

        try {
            $this->runStatement(
                '建立暫存表（每人每地址類型最新記錄）',
                "CREATE TEMPORARY TABLE tmp_biog_addr_latest (
                    c_personid INT NOT NULL,
                    c_addr_type SMALLINT NOT NULL,
                    c_addr_id INT NOT NULL,
                    PRIMARY KEY (c_personid, c_addr_type)
                ) ENGINE=InnoDB"
            );

            $latestTotal = 0;
            foreach ($batchRanges as $index => [$startId, $endId]) {
                $count = $this->applyRuleWithBindings(
                    sprintf('填充 tmp_biog_addr_latest（batch %d/%d: %d-%d）', $index + 1, $stats['batch_count'], $startId, $endId),
                    "INSERT INTO tmp_biog_addr_latest (c_personid, c_addr_type, c_addr_id)
                     SELECT picked.c_personid,
                            picked.c_addr_type,
                            MAX(picked.c_addr_id) AS c_addr_id
                     FROM BIOG_ADDR_DATA picked
                     JOIN (
                         SELECT c_personid,
                                c_addr_type,
                                MAX(c_sequence) AS c_sequence
                         FROM BIOG_ADDR_DATA
                         WHERE c_personid BETWEEN ? AND ?
                         GROUP BY c_personid, c_addr_type
                     ) latest
                       ON latest.c_personid = picked.c_personid
                      AND latest.c_addr_type = picked.c_addr_type
                      AND latest.c_sequence = picked.c_sequence
                     WHERE picked.c_personid BETWEEN ? AND ?
                     GROUP BY picked.c_personid, picked.c_addr_type",
                    [$startId, $endId, $startId, $endId]
                );
                $latestTotal += $count;
            }
            $this->log(sprintf('填充 tmp_biog_addr_latest（累計） -> %d', $latestTotal));

            $this->runStatement(
                '建立暫存表（每人最終 index address）',
                "CREATE TEMPORARY TABLE tmp_biog_index_addr_stage (
                    c_personid INT NOT NULL,
                    c_index_addr_id INT NULL,
                    c_index_addr_type_code SMALLINT NULL,
                    PRIMARY KEY (c_personid)
                ) ENGINE=InnoDB"
            );

            // MySQL/MariaDB 對同一 TEMPORARY TABLE 在單一查詢中多次引用有限制，
            // 透過建立鏡像表避免「Can't reopen table」。
            $this->runStatement(
                '建立鏡像暫存表（避免 latest 多重引用限制）',
                "CREATE TEMPORARY TABLE tmp_biog_addr_latest_ref (
                    c_personid INT NOT NULL,
                    c_addr_type SMALLINT NOT NULL,
                    c_addr_id INT NOT NULL,
                    PRIMARY KEY (c_personid, c_addr_type)
                ) ENGINE=InnoDB"
            );

            $this->applyRule(
                '填充 tmp_biog_addr_latest_ref',
                "INSERT INTO tmp_biog_addr_latest_ref (c_personid, c_addr_type, c_addr_id)
                 SELECT c_personid, c_addr_type, c_addr_id
                 FROM tmp_biog_addr_latest"
            );

            $this->runStatement(
                '建立批次暫存表（best_rank）',
                "CREATE TEMPORARY TABLE tmp_biog_best_rank_batch (
                    c_personid INT NOT NULL,
                    best_rank INT NOT NULL,
                    PRIMARY KEY (c_personid)
                ) ENGINE=InnoDB"
            );

            $this->runStatement(
                '建立批次暫存表（best_type）',
                "CREATE TEMPORARY TABLE tmp_biog_best_type_batch (
                    c_personid INT NOT NULL,
                    c_index_addr_rank INT NOT NULL,
                    best_addr_type SMALLINT NOT NULL,
                    PRIMARY KEY (c_personid, c_index_addr_rank)
                ) ENGINE=InnoDB"
            );

            $stagedTotal = 0;
            foreach ($batchRanges as $index => [$startId, $endId]) {
                $this->runStatement(
                    sprintf('清空 tmp_biog_best_rank_batch（batch %d/%d）', $index + 1, $stats['batch_count']),
                    'TRUNCATE TABLE tmp_biog_best_rank_batch'
                );
                $this->applyRuleWithBindings(
                    sprintf('填充 tmp_biog_best_rank_batch（batch %d/%d: %d-%d）', $index + 1, $stats['batch_count'], $startId, $endId),
                    "INSERT INTO tmp_biog_best_rank_batch (c_personid, best_rank)
                     SELECT latest_ref.c_personid AS c_personid,
                            MIN(code_ref.c_index_addr_rank) AS best_rank
                     FROM tmp_biog_addr_latest_ref latest_ref
                     JOIN BIOG_ADDR_CODES code_ref
                       ON code_ref.c_addr_type = latest_ref.c_addr_type
                     WHERE latest_ref.c_personid BETWEEN ? AND ?
                       AND code_ref.c_index_addr_rank IS NOT NULL
                       AND code_ref.c_index_addr_rank < 100
                     GROUP BY latest_ref.c_personid",
                    [$startId, $endId]
                );

                $this->runStatement(
                    sprintf('清空 tmp_biog_best_type_batch（batch %d/%d）', $index + 1, $stats['batch_count']),
                    'TRUNCATE TABLE tmp_biog_best_type_batch'
                );
                $this->applyRuleWithBindings(
                    sprintf('填充 tmp_biog_best_type_batch（batch %d/%d: %d-%d）', $index + 1, $stats['batch_count'], $startId, $endId),
                    "INSERT INTO tmp_biog_best_type_batch (c_personid, c_index_addr_rank, best_addr_type)
                     SELECT latest_ref.c_personid AS c_personid,
                            code_ref.c_index_addr_rank AS c_index_addr_rank,
                            MIN(latest_ref.c_addr_type) AS best_addr_type
                     FROM tmp_biog_addr_latest_ref latest_ref
                     JOIN BIOG_ADDR_CODES code_ref
                       ON code_ref.c_addr_type = latest_ref.c_addr_type
                     WHERE latest_ref.c_personid BETWEEN ? AND ?
                       AND code_ref.c_index_addr_rank IS NOT NULL
                       AND code_ref.c_index_addr_rank < 100
                     GROUP BY latest_ref.c_personid, code_ref.c_index_addr_rank",
                    [$startId, $endId]
                );

                $count = $this->applyRuleWithBindings(
                    sprintf('填充 tmp_biog_index_addr_stage（batch %d/%d: %d-%d）', $index + 1, $stats['batch_count'], $startId, $endId),
                    "INSERT INTO tmp_biog_index_addr_stage (c_personid, c_index_addr_id, c_index_addr_type_code)
                     SELECT cand.c_personid,
                            cand.c_addr_id,
                            cand.c_addr_type
                     FROM (
                         SELECT latest.c_personid AS c_personid,
                                latest.c_addr_id AS c_addr_id,
                                latest.c_addr_type AS c_addr_type,
                                current_code.c_index_addr_rank AS c_index_addr_rank
                         FROM tmp_biog_addr_latest latest
                         JOIN BIOG_ADDR_CODES current_code
                           ON current_code.c_addr_type = latest.c_addr_type
                         WHERE latest.c_personid BETWEEN ? AND ?
                           AND current_code.c_index_addr_rank IS NOT NULL
                           AND current_code.c_index_addr_rank < 100
                     ) cand
                     JOIN tmp_biog_best_rank_batch best_rank_pick
                       ON best_rank_pick.c_personid = cand.c_personid
                      AND best_rank_pick.best_rank = cand.c_index_addr_rank
                     JOIN tmp_biog_best_type_batch best_type_pick
                       ON best_type_pick.c_personid = cand.c_personid
                      AND best_type_pick.c_index_addr_rank = cand.c_index_addr_rank
                      AND best_type_pick.best_addr_type = cand.c_addr_type",
                    [$startId, $endId]
                );
                $stagedTotal += $count;
            }
            $stats['staged_count'] = $stagedTotal;
            $this->log(sprintf('填充 tmp_biog_index_addr_stage（累計） -> %d', $stagedTotal));

            foreach ($stats['addr_types'] as $item) {
                $addrType = (int) $item['c_addr_type'];
                $rank = (int) $item['rank'];
                $selected = (int) DB::table('tmp_biog_index_addr_stage')
                    ->where('c_index_addr_type_code', $addrType)
                    ->count();

                $stats['per_type_updates'][] = [
                    'c_addr_type' => $addrType,
                    'rank' => $rank,
                    'updated' => $selected,
                ];
            }

            $stats['clear_count'] = (int) DB::table('BIOG_MAIN as bm')
                ->leftJoin('tmp_biog_index_addr_stage as stage', 'stage.c_personid', '=', 'bm.c_personid')
                ->whereNull('stage.c_personid')
                ->where(function ($query) {
                    $query->whereNotNull('bm.c_index_addr_id')
                        ->orWhereNotNull('bm.c_index_addr_type_code');
                })
                ->count();
            $stats['reset'] = $stats['clear_count'];

            $swapTotal = 0;
            foreach ($batchRanges as $index => [$startId, $endId]) {
                $count = $this->applyRuleWithBindings(
                    sprintf('SWAP（batch %d/%d: %d-%d）', $index + 1, $stats['batch_count'], $startId, $endId),
                    "UPDATE BIOG_MAIN bm
                     LEFT JOIN tmp_biog_index_addr_stage stage
                       ON stage.c_personid = bm.c_personid
                     SET bm.c_index_addr_id = stage.c_index_addr_id,
                         bm.c_index_addr_type_code = stage.c_index_addr_type_code
                     WHERE bm.c_personid BETWEEN ? AND ?
                       AND (
                            COALESCE(bm.c_index_addr_id, -1) <> COALESCE(stage.c_index_addr_id, -1)
                            OR COALESCE(bm.c_index_addr_type_code, -1) <> COALESCE(stage.c_index_addr_type_code, -1)
                       )",
                    [$startId, $endId]
                );
                $swapTotal += $count;
            }
            $stats['swap_updated'] = $swapTotal;
            $this->log(sprintf('SWAP（累計） -> %d', $swapTotal));
            $stats['total_updates'] = $stats['swap_updated'];

            $filledCount = DB::table('BIOG_MAIN')->whereNotNull('c_index_addr_id')->count();
            $stats['filled_count'] = $filledCount;
            $this->log(sprintf('重建後 c_index_addr_id 非空筆數：%d', $filledCount));
        } finally {
            $this->dropTempTables();
        }

        return $stats;
    }

    protected function log(string $message): void {
        if (is_callable($this->logger)) {
            call_user_func($this->logger, $message);
        }
    }

    protected function applyRule(string $label, string $sql): int {
        return $this->applyRuleWithBindings($label, $sql, []);
    }

    /**
     * @param array<int, mixed> $bindings
     */
    protected function applyRuleWithBindings(string $label, string $sql, array $bindings): int {
        if ($this->showSql) {
            $this->log(sprintf('SQL [%s]:', $label));
            $this->log($sql);
        }

        $count = DB::affectingStatement($sql, $bindings);
        $this->log(sprintf('%s -> %d', $label, $count));

        return $count;
    }

    protected function runStatement(string $label, string $sql): void {
        if ($this->showSql) {
            $this->log(sprintf('SQL [%s]:', $label));
            $this->log($sql);
        }

        DB::statement($sql);
        $this->log($label.' -> OK');
    }

    protected function dropTempTables(): void {
        DB::statement('DROP TEMPORARY TABLE IF EXISTS tmp_biog_best_type_batch');
        DB::statement('DROP TEMPORARY TABLE IF EXISTS tmp_biog_best_rank_batch');
        DB::statement('DROP TEMPORARY TABLE IF EXISTS tmp_biog_index_addr_stage');
        DB::statement('DROP TEMPORARY TABLE IF EXISTS tmp_biog_addr_latest_ref');
        DB::statement('DROP TEMPORARY TABLE IF EXISTS tmp_biog_addr_latest');
    }

    /**
     * @return array<int, array{0:int,1:int}>
     */
    protected function buildBatchRanges(int $batchSize): array {
        $row = DB::table('BIOG_MAIN')
            ->selectRaw('MIN(c_personid) AS min_id, MAX(c_personid) AS max_id')
            ->first();

        if (!$row || $row->min_id === null || $row->max_id === null) {
            return [];
        }

        $minId = (int) $row->min_id;
        $maxId = (int) $row->max_id;
        $ranges = [];

        for ($start = $minId; $start <= $maxId; $start += $batchSize) {
            $end = min($start + $batchSize - 1, $maxId);
            $ranges[] = [$start, $end];
        }

        return $ranges;
    }
}
