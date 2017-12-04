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
        $nianhao = NianHao::select(['c_nianhao_id', 'c_dynasty_chn', 'c_nianhao_chn', 'c_firstyear', 'c_lastyear'])->get();
        return $nianhao->map(function ($item, $key){
            return $item->c_nianhao_id." ".$item->c_nianhao_chn." [".$item->c_firstyear."]~[".$item->c_lastyear."]";
        });
    }
}