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
            // Store as Carbon object in local timezone (Asia/Shanghai)
            // Laravel will convert to MySQL TIMESTAMP which stores UTC internally
            // but displays in system timezone, giving us the correct local time
            $data['c_created_date'] = Carbon::now();
        } else {
            $data['c_modified_by'] = Auth::user()->name;
            $data['c_modified_date'] = Carbon::now();
        }

        return $data;
    }
}
