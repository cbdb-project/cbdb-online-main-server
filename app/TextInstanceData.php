<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class TextInstanceData extends Model
{
    /**
     * 与模型关联的数据表
     *
     * @var string
     */
    protected $table = 'TEXT_INSTANCE_DATA';
    protected $primaryKey = 'c_textid';
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
