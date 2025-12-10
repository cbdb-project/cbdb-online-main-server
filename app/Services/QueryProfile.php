<?php

namespace App\Services;

use Illuminate\Database\Events\QueryExecuted;

class QueryProfile {
    /**
     * @var array<int, array<string, mixed>>
     */
    protected $queries = [];

    public function add(QueryExecuted $event): void {
        $time = is_numeric($event->time) ? (float) $event->time : 0.0;

        $this->queries[] = [
            'sql' => $event->sql,
            'bindings' => $event->bindings,
            'time' => $time,
        ];
    }

    public function count(): int {
        return count($this->queries);
    }

    public function totalTime(): float {
        return array_sum(array_column($this->queries, 'time'));
    }

    public function summary(): array {
        $queries = array_map(function ($query) {
            return $query + [
                'bindings_json' => json_encode(
                    $query['bindings'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            ];
        }, $this->queries);

        return [
            'count' => $this->count(),
            'time_ms' => $this->totalTime(),
            'queries' => $queries,
        ];
    }
}
