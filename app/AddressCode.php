<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class AddressCode extends Model
{
    /**
     * 与模型关联的数据表
     *
     * @var string
     */
    protected $table = 'ADDRESSES';
    protected $primaryKey = 'c_addr_id';

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

    public function type() {
        $this->hasOne('App\BiogAddrCode');
    }
}
