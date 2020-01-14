<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class OfficeCodeTypeRel extends Model
{
    /**
     * 与模型关联的数据表
     *
     * @var string
     */
    protected $table = 'OFFICE_CODE_TYPE_REL';
    protected $primaryKey = 'c_office_id';
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
