<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Operation extends Model {
    public const TYPE_CREATE = 1;
    public const TYPE_UPDATE_FULL = 2; // Put(Update 全部信息) - 修改
    public const TYPE_UPDATE = 3; // Patch(Update 部分属性) - 修改
    public const TYPE_DELETE = 4;
    public const TYPE_PROPOSAL_CREATE = 8;
    public const TYPE_PROPOSAL_UPDATE = 9;

    //
    protected $fillable = [
        'user_id',
        'c_personid',
        'op_type',
        'resource',
        'resource_id',
        'resource_data',
        'resource_original',
        'crowdsourcing_status',
        'biog',
    ];

    public function user() {
        return $this->belongsTo('App\Models\User');
    }

    public function biogmain() {
        return $this->belongsTo('App\Models\BiogMain', 'c_personid', 'c_personid');
    }
}
