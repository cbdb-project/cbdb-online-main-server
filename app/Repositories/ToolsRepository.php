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
            #20251217更新 create 和 modify 欄位時間的寫入方式
            # 同時更新新舊兩個欄位以保持相容性
            $data['c_created_date'] = Carbon::now()->format('Ymd');
            $data['c_created_date_timestamp_temporary'] = Carbon::now();
        } else {
            $data['c_modified_by'] = Auth::user()->name;
            # 同時更新新舊兩個欄位以保持相容性
            $data['c_modified_date'] = Carbon::now()->format('Ymd');
            $data['c_modified_date_timestamp_temporary'] = Carbon::now();
        }

        return $data;
    }
}
