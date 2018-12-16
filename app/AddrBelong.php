<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class AddrBelong extends Model
{
    /**
     * 与模型关联的数据表
     *
     * @var string
     */
    protected $table = 'ADDR_BELONGS_DATA';
    protected $primaryKey = ['c_addr_id', 'c_belongs_to'];
    public $incrementing = false;
    /**
     * 该模型是否被自动维护时间戳
     *
     * @var bool
     */
    public $timestamps = false;

    public function addr()
    {
        return $this->belongsTo('App\AddrCode', 'c_belongs_to', 'c_addr_id');
    }
}
