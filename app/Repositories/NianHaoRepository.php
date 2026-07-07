<?php

/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/8/20
 * Time: 11:51
 */

namespace App\Repositories;

use App\Models\NianHao;
use App\Support\HistoricalYearRangeFormatter;

class NianHaoRepository {
    public function nianhaos() {
        $nianhao = NianHao::select(['c_nianhao_id', 'c_dynasty_chn', 'c_nianhao_chn', 'c_firstyear', 'c_lastyear'])->get();

        return $nianhao->map(function ($item, $key) {
            return [
                'c_nianhao_id' => $item->c_nianhao_id,
                'c_nianhao_chn' => $item->c_nianhao_chn,
                'c_str' => "[".$item->c_firstyear."]~[".$item->c_lastyear."]",
                'c_firstyear' => $item->c_firstyear,
                'c_lastyear' => $item->c_lastyear,
                // 供下拉選單標示起止年以區分同朝代重複年號（如元朝兩筆「至元」）；c_str 保留給既有解析邏輯用。
                'c_year_range' => HistoricalYearRangeFormatter::format($item->c_firstyear, $item->c_lastyear),
            ];
        });
    }
}
