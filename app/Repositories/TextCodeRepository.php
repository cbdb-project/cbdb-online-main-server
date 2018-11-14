<?php
/**
 * Created by PhpStorm.
 * User: Ja
 * Date: 2018/10/29
 * Time: 15:30
 */

namespace App\Repositories;


use App\TextTypeCode;
use Illuminate\Http\Request;

class TextCodeRepository{
    public function textByQuery(Request $request, $num=20)
    {
        if ($temp = $request->num){
            $num = $temp;
        }
        if (!$request->q){
            return TextTypeCode::paginate($num);
        }
        $names = TextTypeCode::where('c_title_chn', 'like', '%'.$request->q.'%')->orWhere('c_title_chn', 'like', '%'.$request->q.'%')->orWhere('c_textid', $request->q)->paginate($num);
        $names->appends(['q' => $request->q])->links();
        return $names;
    }

    public function byId($id)
    {
        return TextTypeCode::find($id);
    }

    public function updateById($request, $id)
    {
        $data = $request->all();
        $altcode = TextTypeCode::find($id);
        $altcode->update($data);
    }
}
