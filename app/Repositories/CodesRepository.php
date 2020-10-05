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
            if(str_contains($table->Tables_in_cbdb_data,'_CODES') or str_contains($table->Tables_in_cbdb_data,'_codes')) {
                array_push($res, $table->Tables_in_cbdb_data);
            }

        }
        return $res;
    }
}
