<?php
/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/8/16
 * Time: 16:23
 */

namespace App\Repositories;


use App\Ethnicity;

class EthnicityRepository
{
    public function ethnicities()
    {
        return Ethnicity::select(['c_ethnicity_code', 'c_name_chn', 'c_name'])->get();
    }
}