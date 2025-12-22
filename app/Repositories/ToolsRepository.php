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
            // Store as Carbon object (UTC), which Laravel will:
            // 1. Convert to TIMESTAMP in database (UTC stored, no timezone info in column)
            // 2. Serialize to ISO-8601 in JSON (e.g., "2024-12-22T12:00:00.000000Z")
            // Display logic (CodesController) will convert to Asia/Taipei when showing to users
            $data['c_created_date'] = Carbon::now();
        } else {
            $data['c_modified_by'] = Auth::user()->name;
            $data['c_modified_date'] = Carbon::now();
        }

        return $data;
    }
}
