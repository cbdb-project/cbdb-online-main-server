<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ChoronymCode extends Model
{
    /**
     * 与模型关联的数据表
     *
     * @var string
     */
    protected $table = 'CHORONYM_CODES';
    protected $primaryKey = 'c_choronym_code';
    /**
     * 该模型是否被自动维护时间戳
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * 可被批量赋值的属性。
     *
     * @var array
     */
    protected $fillable = ['c_choronym_desc', 'c_choronym_chn'];
}
