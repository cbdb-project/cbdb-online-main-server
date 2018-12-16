<?php
/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/8/20
 * Time: 23:06
 */

namespace App\Repositories;


use App\YearRangeCode;

class YearRangeRepository
{
    public function yearRange()
    {
        return YearRangeCode::select(['c_range_code', 'c_range', 'c_range_chn'])->get();
    }
}