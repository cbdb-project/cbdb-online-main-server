<?php
/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/8/30
 * Time: 16:11
 */

namespace App\Repositories;


use App\AltnameCode;
use Illuminate\Http\Request;

class AltCodeRepository
{
    public function altByQuery(Request $request, $num=20)
    {
        if ($temp = $request->num){
            $num = $temp;
        }
        if (!$request->q){
            return AltnameCode::paginate($num);
        }
        $names = AltnameCode::where('c_name_type_desc_chn', 'like', '%'.$request->q.'%')->orWhere('c_name_type_desc', 'like', '%'.$request->q.'%')->orWhere('c_name_type_code', $request->q)->paginate($num);
        $names->appends(['q' => $request->q])->links();
        return $names;
    }

    public function byId($id)
    {
        return AltnameCode::find($id);
    }

    public function updateById($request, $id)
    {
        $data = $request->all();
        $altcode = AltnameCode::find($id);
        $altcode->update($data);
    }

    public function altcode()
    {
        return AltnameCode::select(['c_name_type_code', 'c_name_type_desc', 'c_name_type_desc_chn'])->get();
    }
}