<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class BiogMain extends Model
{
    /**
     * 与模型关联的数据表
     *
     * @var string
     */
    protected $table = 'BIOG_MAIN';
    protected $primaryKey = 'c_personid';
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
     * 获取该人物所属的朝代
     */
    public function dynasty()
    {
        return $this->belongsTo('App\Dynasty', 'c_dy', 'c_dy');
    }

    public function birthYearNH()
    {
        return $this->belongsTo('App\NianHao', 'c_by_nh_code', 'c_nianhao_id');
    }

    public function deathYearNH()
    {
        return $this->belongsTo('App\NianHao', 'c_dy_nh_code', 'c_nianhao_id');
    }

    public function choronym()
    {
        return $this->belongsTo('App\ChoronymCode', 'c_choronym_code', 'c_choronym_code');
    }

    public function ethnicity()
    {
        return $this->belongsTo('App\Ethnicity', 'c_dy', '﻿c_ethnicity_code');
    }
}
