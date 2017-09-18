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
    public function store($op)
    {
        $operation = Operation::create($op);
    }

}