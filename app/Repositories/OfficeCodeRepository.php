<?php
/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/8/30
 * Time: 17:10
 */

namespace App\Repositories;


use App\OfficeCode;
use Illuminate\Http\Request;

class OfficeCodeRepository {

    public function officeByQuery(Request $request, $num=20)
    {
        if ($temp = $request->num){
            $num = $temp;
        }
        if (!$request->q){
            return OfficeCode::paginate($num);
        }
        $names = OfficeCode::where('c_office_chn', 'like', '%'.$request->q.'%')->orWhere('c_office_pinyin', 'like', '%'.$request->q.'%')->orWhere('c_office_id', $request->q)->paginate($num);
        $names->appends(['q' => $request->q])->links();
        return $names;
    }

    public function byId($id)
    {
        return OfficeCode::find($id);
    }

    public function updateById($request, $id)
    {
        $data = $request->all();
        $altcode = OfficeCode::find($id);
        $altcode->update($data);
    }
}
