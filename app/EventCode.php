<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class EventCode extends Model
{
    /**
     * 与模型关联的数据表
     *
     * @var string
     */
    protected $table = 'EVENT_CODES';
    /**
     * @var string
     */
    protected $primaryKey = 'c_event_code';
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
