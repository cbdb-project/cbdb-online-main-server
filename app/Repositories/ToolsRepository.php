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
            // Behavior:
            // 1. Carbon::now() returns current time in server timezone (e.g., Asia/Shanghai GMT+8)
            // 2. Laravel Query Builder converts to string: '2025-12-22 20:08:00'
            // 3. MySQL TIMESTAMP stores this value directly
            // 4. Display uses same timezone (config('app.timezone')) for consistency
            // Result: All users see unified server time, not their browser's local time
            $data['c_created_date'] = Carbon::now();
        } else {
            $data['c_modified_by'] = Auth::user()->name;
            $data['c_modified_date'] = Carbon::now();
        }

        return $data;
    }
}
