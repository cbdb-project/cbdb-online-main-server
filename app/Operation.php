<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Operation extends Model
{
    //
    protected $fillable = [
        'op_type', 'resource', 'resource_id', 'resource_data'
    ];
}
