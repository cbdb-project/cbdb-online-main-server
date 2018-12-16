<?php
/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/9/18
 * Time: 14:17
 */

namespace App\Repositories;


use App\BiogAddrCode;

class BiogAddrCodeRepository
{
    public function biogaddr()
    {
        return BiogAddrCode::select(['c_addr_type', 'c_addr_desc', 'c_addr_desc_chn'])->get();
    }
}