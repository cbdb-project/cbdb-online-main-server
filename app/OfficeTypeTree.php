<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class OfficeTypeTree extends Model
{
    /**
     * 与模型关联的数据表
     *
     * @var string
     */
    protected $table = 'OFFICE_TYPE_TREE';
    protected $primaryKey = 'c_office_type_node_id';
    //primary key 型別為字串，則可以透過 $keyType 設定為 'string'
    protected $keyType = 'string';
    /**
     * 该模型是否被自动维护时间戳
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * 不可被批量赋值的属性。
     *
     * @var array
     */
    protected $guarded = [];

}
