<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class NianHao extends Model
{
    /**
     * 与模型关联的数据表
     *
     * @var string
     */
    protected $table = 'NIAN_HAO';
    protected $primaryKey = 'c_nianhao_id';
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

    public function birthYearNHs()
    {
        return $this->hasMany('App\BiogMain', 'c_nianhao_id', 'c_by_nh_code');
    }

    public function deathYearNHs()
    {
        return $this->hasMany('App\BiogMain', 'c_nianhao_id', 'c_dy_nh_code');
    }

    public function dynasty()
    {
        return $this->belongsTo('App\Dynasty', 'c_dy', 'c_dy');
    }
}
