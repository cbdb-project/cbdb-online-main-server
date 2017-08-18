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
    public function ethnicity()
    {
        return Ethnicity::all()->pluck('c_ethnicity_code', 'c_name_chn');
    }
}