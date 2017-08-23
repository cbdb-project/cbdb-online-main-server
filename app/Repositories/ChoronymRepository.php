<?php
/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/8/20
 * Time: 16:14
 */

namespace App\Repositories;


use App\ChoronymCode;

class ChoronymRepository
{
    public function choronyms()
    {
        return ChoronymCode::select(['c_choronym_code', 'c_choronym_chn', 'c_choronym_desc'])->get();;
    }
}