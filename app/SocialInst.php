<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class SocialInst extends Model
{
    /**
     * 与模型关联的数据表
     *
     * @var string
     */
    protected $table = 'SOCIAL_INSTITUTION_NAME_CODES';
    protected $primaryKey = 'c_inst_name_code';
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
