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
     * @return mixed
     */
    public function store($user_id, $c_personid, $op_type, $resource, $resource_id, $resource_data)
    {
        $operation = new Operation();
        $operation->user_id = $user_id;
        $operation->c_personid = $c_personid;
        $operation->op_type = $op_type;
        $operation->resource = $resource;
        $operation->resource_id = $resource_id;
        $operation->resource_data = json_encode($resource_data);
        $operation->save();
    }

}