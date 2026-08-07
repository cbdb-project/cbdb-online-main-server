<?php

namespace App\Services;

use Illuminate\Database\Events\QueryExecuted;

class QueryProfile {
    /**
     * 最多保留幾筆明細。這是除錯輔助，不該把一個跑上萬筆查詢的頁面（批次匯入、
     * 維護工具）的每一句 SQL 與 bindings 全留在記憶體裡。前端／舊版 Blade modal
     * 都只顯示前 100 筆，留 200 筆已足夠；**筆數與總耗時另行累計，永遠精確**，
     * 不會因為停止保留明細而變小。
     */
    public const MAX_STORED = 200;

    /**
     * @var array<int, array<string, mixed>>
     */
    protected $queries = [];

    /** 全部查詢筆數（含已不再保留明細的部分）。 */
    protected int $count = 0;

    /** 全部查詢累計耗時（含已不再保留明細的部分）。 */
    protected float $totalTime = 0.0;

    /**
     * @param bool $retainDetails 是否保留這一筆的 SQL 與 bindings。
     *                            false 時仍計入筆數與耗時——舊版 Blade 的「本次查詢共 N 筆」
     *                            摘要行對所有人顯示（含訪客，該行沒有權限閘），只有明細
     *                            modal 才限管理員。昂貴的是保留每筆 SQL／bindings，不是計數。
     */
    public function add(QueryExecuted $event, bool $retainDetails = true): void {
        $time = is_numeric($event->time) ? (float) $event->time : 0.0;

        $this->count++;
        $this->totalTime += $time;

        if (!$retainDetails || count($this->queries) >= self::MAX_STORED) {
            return;
        }

        $this->queries[] = [
            'sql' => $event->sql,
            'bindings' => $event->bindings,
            'time' => $time,
        ];
    }

    public function count(): int {
        return $this->count;
    }

    public function totalTime(): float {
        return $this->totalTime;
    }

    /**
     * @param int|null $limit 只取前 N 筆明細。**先切再編碼**——bindings 的 json_encode
     *                        只對真的會被用到的那幾筆做，不要編碼 200 筆再丟掉一半。
     */
    public function summary(?int $limit = null): array {
        $rows = $limit === null ? $this->queries : array_slice($this->queries, 0, $limit);

        $queries = array_map(function ($query) {
            return $query + [
                'bindings_json' => json_encode(
                    $query['bindings'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            ];
        }, $rows);

        return [
            'count' => $this->count(),
            'time_ms' => $this->totalTime(),
            'queries' => $queries,
        ];
    }
}
