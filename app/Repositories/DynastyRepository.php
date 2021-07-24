<?php
/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/8/19
 * Time: 17:56
 */

namespace App\Repositories;


use App\Dynasty;

class DynastyRepository
{
    public function dynasties()
    {
        return Dynasty::select(['c_dy', 'c_dynasty_chn', 'c_dynasty', 'c_start', 'c_end'])->get();
    }
}
