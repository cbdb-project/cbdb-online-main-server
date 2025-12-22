<?php

/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2018/5/25
 * Time: 15:31
 */

namespace App\Repositories;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class ToolsRepository {
    /**
     * @param array $data
     */
    public function timestamp(array $data, $isCreat = false) {
        if ($isCreat) {
            $data['c_created_by'] = Auth::user()->name;
            // Store as Carbon object in server's configured timezone (config('app.timezone'))
            //
            // CRITICAL: DB_TIMEZONE (.env) MUST match APP_TIMEZONE (config/app.php)!
            //
            // Behavior with correct config (DB_TIMEZONE=+08:00, APP_TIMEZONE=Asia/Shanghai):
            // 1. Carbon::now() returns current time in Asia/Shanghai (GMT+8): '2025-12-22 20:08:00'
            // 2. Laravel Query Builder converts Carbon to string: '2025-12-22 20:08:00'
            // 3. MySQL interprets this string in session timezone (+08:00), converts to UTC for storage
            // 4. On read, MySQL converts UTC back to +08:00, returns: '2025-12-22 20:08:00'
            // 5. Carbon::parse() interprets in Asia/Shanghai timezone
            // 6. Result: UNIX timestamps match perfectly, no 8-hour drift
            //
            // Without DB_TIMEZONE (or if mismatched):
            // - MySQL uses SYSTEM timezone (often UTC)
            // - Causes 8-hour drift in stored UNIX timestamp (string looks same, but timestamp differs)
            // - Queries still work but stored values are semantically wrong
            //
            // Result: All users see unified server time (not browser's local time)
            $data['c_created_date'] = Carbon::now();
        } else {
            $data['c_modified_by'] = Auth::user()->name;
            $data['c_modified_date'] = Carbon::now();
        }

        return $data;
    }
}
