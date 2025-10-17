<?php
/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/9/8
 * Time: 15:52
 */

namespace App\Repositories;


use Illuminate\Support\Facades\DB;

class CodesRepository
{
    public function codes()
    {
        $res = array();
        $tables = DB::select('SHOW TABLES');
        foreach($tables as $table)
        {
            $tableName = array_values((array)$table)[0];
	    // Only include application code tables that follow the uppercase '_CODES' convention;
	    // exclude system tables such as 'oauth_auth_codes'.
            if(str_contains($tableName,'_CODES')) {
                array_push($res, $tableName);
            }

        }
        return $res;
    }
}
