<?php
namespace App;
use Illuminate\Database\Eloquent\Model;
class AddrCode extends Model
{
    /**
     * 与模型关联的数据表
     *
     * @var string
     */
    protected $table = 'ADDR_CODES';
    protected $primaryKey = 'c_addr_id';
    public $incrementing = false;
    /**
     * 该模型是否被自动维护时间戳
     *
     * @var bool
     */
    public $timestamps = false;
    protected $guarded = [];
    public function belong()
    {
        return $this->hasMany('App\AddrBelong', 'c_addr_id', 'c_addr_id');
    }
}
