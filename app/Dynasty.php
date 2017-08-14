<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Dynasty extends Model
{
    /**
     * 与模型关联的数据表
     *
     * @var string
     */
    protected $primaryKey = 'c_dy';
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

    /**
     * 获取这个朝代所有人物
     */
    public function biographies()
    {
        return $this->hasMany('App\BiogMain', 'c_dy', 'c_dy');
    }

    public function nianhaos()
    {
        return $this->hasMany('App\Nianhao', 'c_dy', 'c_dy');
    }
}
