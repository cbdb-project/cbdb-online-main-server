<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class NameFtsProgressService
{
    public static function initialize(string $taskId, array $meta = []): void
    {
        $data = array_merge($meta, [
            'task_id' => $taskId,
            'progress' => 5,
            'message' => '已排程等待執行…',
            'status' => 'queued',
            'started_at' => Carbon::now()->format('Y-m-d H:i:s'),
            'updated_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);

        Cache::put(self::cacheKey($taskId), $data, now()->addHour());
    }

    public static function update(string $taskId, int $progress, string $message, string $status = 'running'): void
    {
        $key = self::cacheKey($taskId);
        $data = Cache::get($key, []);

        $data['task_id'] = $taskId;
        $data['progress'] = max(0, min(100, $progress));
        $data['message'] = $message;
        $data['status'] = $status;
        $data['updated_at'] = Carbon::now()->format('Y-m-d H:i:s');

        if (in_array($status, ['completed', 'error'], true)) {
            $data['completed_at'] = Carbon::now()->format('Y-m-d H:i:s');
        }

        Cache::put($key, $data, now()->addHour());
    }

    public static function get(string $taskId)
    {
        return Cache::get(self::cacheKey($taskId));
    }

    public static function cacheKey(string $taskId): string
    {
        return 'cbdb_name_fts_progress_' . $taskId;
    }
}
