<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class BiogAddr extends Model
{
    /**
     * 与模型关联的数据表
     *
     * @var string
     */
    protected $table = 'BIOG_ADDR_DATA';
    protected $primaryKey = ['c_personid', 'c_addr_id', 'c_addr_type', 'c_sequence'];
    public $incrementing = false;
    /**
     * 该模型是否被自动维护时间戳
     *
     * @var bool
     */
    public $timestamps = false;

    public function addr_type()
    {
        return $this->belongsTo('App\BiogAddrCode', 'c_addr_type', 'c_addr_type');
    }

    public function addr()
    {
        return $this->belongsTo('App\AddrCode', 'c_addr_id', 'c_addr_id');
    }

    public function belong()
    {
        return $this->belongsTo('App\AddrCode', 'c_addr_id', 'c_addr_id');
    }
}
