<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class IndexAddressRebuildService {
    /**
     * @var callable|null
     */
    protected $logger;

    public function setLogger(?callable $logger): self {
        $this->logger = $logger;

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
        ];

        foreach ($addrTypes as $row) {
            $stats['addr_types'][] = [
                'c_addr_type' => (int) $row->c_addr_type,
                'rank' => (int) $row->c_index_addr_rank,
            ];
        }

        $this->log(sprintf('載入地址類型優先級：%d 個（c_index_addr_rank < 100）', $stats['addr_type_count']));

        $stats['reset'] = $this->applyRule(
            'RESET',
            "UPDATE BIOG_MAIN
             SET c_index_addr_id = NULL,
                 c_index_addr_type_code = NULL"
        );
        $stats['total_updates'] += $stats['reset'];

        foreach ($stats['addr_types'] as $item) {
            $addrType = (int) $item['c_addr_type'];
            $rank = (int) $item['rank'];

            $count = $this->applyRule(
                sprintf('AddrType %d (rank %d)', $addrType, $rank),
                $this->sqlFillByAddrType($addrType)
            );

            $stats['per_type_updates'][] = [
                'c_addr_type' => $addrType,
                'rank' => $rank,
                'updated' => $count,
            ];
            $stats['total_updates'] += $count;
        }

        $filledCount = DB::table('BIOG_MAIN')->whereNotNull('c_index_addr_id')->count();
        $stats['filled_count'] = $filledCount;
        $this->log(sprintf('重建後 c_index_addr_id 非空筆數：%d', $filledCount));

        return $stats;
    }

    protected function log(string $message): void {
        if (is_callable($this->logger)) {
            call_user_func($this->logger, $message);
        }
    }

    protected function applyRule(string $label, string $sql): int {
        $count = DB::affectingStatement($sql);
        $this->log(sprintf('%s -> %d', $label, $count));

        return $count;
    }

    protected function sqlFillByAddrType(int $addrType): string {
        return "UPDATE BIOG_MAIN bm
                JOIN (
                    SELECT picked.c_personid,
                           picked.c_addr_type,
                           MAX(picked.c_addr_id) AS c_addr_id
                    FROM BIOG_ADDR_DATA picked
                    JOIN (
                        SELECT c_personid,
                               c_addr_type,
                               MAX(c_sequence) AS c_sequence
                        FROM BIOG_ADDR_DATA
                        GROUP BY c_personid, c_addr_type
                    ) latest
                      ON latest.c_personid = picked.c_personid
                     AND latest.c_addr_type = picked.c_addr_type
                     AND latest.c_sequence = picked.c_sequence
                    WHERE picked.c_addr_type = $addrType
                    GROUP BY picked.c_personid, picked.c_addr_type
                ) addr_pick
                  ON addr_pick.c_personid = bm.c_personid
                SET bm.c_index_addr_id = addr_pick.c_addr_id,
                    bm.c_index_addr_type_code = addr_pick.c_addr_type
                WHERE bm.c_index_addr_id IS NULL";
    }
}
