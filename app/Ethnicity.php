<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * Class Ethnicity
 * @package App
 */
class Ethnicity extends Model
{
    /**
     * 与模型关联的数据表
     *
     * @var string
     */
    protected $table = 'ETHNICITY_TRIBE_CODES';
    /**
     * @var string
     */
    protected $primaryKey = '﻿c_ethnicity_code';
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
     *
     */
    public function biographies()
    {
        $this->hasMany('\App\BiogMain', '﻿c_ethnicity_code', '﻿c_ethnicity_code');
    }
}
