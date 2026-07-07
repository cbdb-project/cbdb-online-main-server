<?php

/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/8/19
 * Time: 17:56
 */

namespace App\Repositories;

use App\Models\Dynasty;
use App\Support\HistoricalYearRangeFormatter;

class DynastyRepository {
    public function dynasties() {
        $dynasties = Dynasty::select(['c_dy', 'c_dynasty_chn', 'c_dynasty', 'c_start', 'c_end'])->get();

        return $dynasties->map(function ($item) {
            return [
                'c_dy' => $item->c_dy,
                'c_dynasty_chn' => $item->c_dynasty_chn,
                'c_dynasty' => $item->c_dynasty,
                'c_start' => $item->c_start,
                'c_end' => $item->c_end,
                // 供下拉選單標示起止年，幫助使用者辨識朝代時間範圍。
                'c_year_range' => HistoricalYearRangeFormatter::format($item->c_start, $item->c_end),
            ];
        });
    }
}
