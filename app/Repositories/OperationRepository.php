<?php
/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/9/17
 * Time: 16:52
 */

namespace App\Repositories;


use App\Operation;

class OperationRepository
{
    /**
     * @param int $user_id 用户ID
     * @param int $c_personid 人物ID
     * @param int $op_type 操作类型
     * @param string $resource 修改表明
     * @param int $resource_id 修改数据ID
     * @param array $resource_data 数据
     * @param array $ori 数据
     * @param int $crowdsourcing_status 0.專業用戶修改紀錄 
     *                                  1.crowdsourcing記錄並已插入數據庫 
     *                                  2.crowdsourcing記錄還沒有被處理 
     *                                  3.crowdsourcing記錄reject 
     *                                  4.crowdsourcing處理失敗
     * @return mixed
     */

    public function store($user_id, $c_personid, $op_type, $resource, $resource_id, $resource_data, $ori='', $crowdsourcing_status=0)
    {
        $operation = new Operation();
        $operation->user_id = $user_id;
        $operation->c_personid = $c_personid;
        $operation->op_type = $op_type;
        $operation->resource = $resource;
        $operation->resource_id = $resource_id;
        $operation->resource_data = json_encode($resource_data);
        if(!empty($ori)) $operation->resource_original = json_encode($ori);
        if($crowdsourcing_status != 0) $operation->crowdsourcing_status = $crowdsourcing_status;
        $operation->save();
    }

    public function objectToArray($object)
    {
        //先編碼成json字串，再解碼成陣列
        return json_decode(json_encode($object), true);
    }

    public function getArrDiff($arr1, $arr2, $arr3)
    {
        //進行陣列雜訊的濾除
        if(!is_array($arr1) || !is_array($arr2)) { return ""; }
        $NewArr1ture = array();
        $NewArr2ture = array();
        $NewArr3ture = array();
        $NewArr1 = array_diff_assoc($arr1, $arr2);
        $NewArr2 = array_diff_assoc($arr2, $arr1);
        $NewArr3 = array_diff_assoc($arr1, $arr3);

        $data = "<br/><p>[修改後]</p>";
        foreach($NewArr1 as $key => $value){
            if($key == "_method" || $key == "_token") {
                continue;
            }
            else {
                $NewArr1ture[$key] = $value;
                $data .= "欄位：".$key."  內容：".$value."<br/>";
            }
        }
        $data .= "<br/><p>[原本的]</p>";
        foreach($NewArr1ture as $key => $value){
            $NewArr2ture[$key] = $value;
            $data .= "欄位：".$key."  內容：".$NewArr2[$key]."<br/>";
        }

        $data .= "<br/><p>[實時比對]</p>";
        foreach($NewArr3 as $key => $value){
            if($key == "_method" || $key == "_token") {
                continue;
            }
            else {
                if(!empty($arr3[$key])) {
                    $NewArr3ture[$key] = $value;
                    $data .= "欄位：".$key."  內容：".$arr3[$key]."<br/>";
                }
            }
        }
        //雜訊濾除結束
        return $data;
    }
}
