<?php
/**
 * Created by PhpStorm.
 * User: Ja
 * Date: 2020/11/11
 * Time: 11:00
 */

namespace App\Repositories;


use App\TextInstanceData;
use Illuminate\Http\Request;

class TextInstanceDataRepository{
    public function textByQuery(Request $request, $num=20)
    {
        if ($temp = $request->num){
            $num = $temp;
        }
        if (!$request->q){
            return TextInstanceData::paginate($num);
        }
        $names = TextInstanceData::where('c_instance_title_chn', 'like', '%'.$request->q.'%')->orWhere('c_instance_title', 'like', '%'.$request->q.'%')->orWhere('c_textid', $request->q)->paginate($num);
        $names->appends(['q' => $request->q])->links();
        return $names;
    }

    public function byId($id)
    {
        return TextInstanceData::find($id);
    }

    public function updateById($request, $id)
    {
        $data = $request->all();
        $altcode = TextInstanceData::find($id);
        $altcode->update($data);
    }
}
