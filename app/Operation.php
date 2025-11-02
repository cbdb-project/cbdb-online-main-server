<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Operation extends Model
{
    const TYPE_CREATE = 1;
    const TYPE_UPDATE = 2;
    const TYPE_RESTORE = 3;
    const TYPE_DELETE = 4;
    const TYPE_PROPOSAL_CREATE = 8;
    const TYPE_PROPOSAL_UPDATE = 9;

    //
    protected $fillable = [
        'user_id', 'c_personid', 'op_type', 'resource', 'resource_id', 'resource_data', 'biog',
    ];

    public function user()
    {
        return $this->belongsTo('App\User');
    }

    public function biogmain()
    {
        return $this->belongsTo('App\BiogMain', 'c_personid', 'c_personid');
    }
}
