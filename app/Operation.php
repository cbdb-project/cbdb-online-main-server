<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Operation extends Model
{
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
