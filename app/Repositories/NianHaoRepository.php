<?php
/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/8/20
 * Time: 11:51
 */

namespace App\Repositories;


use App\NianHao;

class NianHaoRepository
{
    public function nianhaos()
    {
        return NianHao::select(['c_nianhao_id', 'c_nianhao_chn'])->get();
    }
}