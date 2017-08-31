<?php
/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/8/30
 * Time: 17:10
 */

namespace App\Repositories;


use App\AppointmentTypeCode;
use Illuminate\Http\Request;

class AppointCodeRepository{
    public function appointByQuery(Request $request, $num=50)
    {
        if ($temp = $request->num){
            $num = $temp;
        }
        if (!$request->q){
            return AppointmentTypeCode::paginate($num);
        }
        $names = AppointmentTypeCode::where('c_appt_type_desc_chn', 'like', '%'.$request->q.'%')->orWhere('c_appt_type_desc', 'like', '%'.$request->q.'%')->orWhere('c_appt_type_code', $request->q)->paginate($num);
        $names->appends(['q' => $request->q])->links();
        return $names;
    }
}