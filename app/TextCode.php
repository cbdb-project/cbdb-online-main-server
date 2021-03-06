<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class TextCode extends Model
{
    /**
     * 与模型关联的数据表
     *
     * @var string
     */
    protected $table = 'TEXT_CODES';
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

    public function type()
    {
        return $this->belongsTo('App\TextType', 'c_text_type_id', 'c_text_type_code');
    }
}
