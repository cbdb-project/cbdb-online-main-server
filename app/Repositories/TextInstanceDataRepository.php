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
        //return TextInstanceData::find($id);
        $id_l = explode("-", $id);
        $row = TextInstanceData::where([
            ['c_textid', '=', $id_l[0]],
            ['c_text_edition_id', '=', $id_l[1]],
            ['c_text_instance_id', '=', $id_l[2]]
        ])->first();
        return $row;
    }

    public function updateById($request, $id)
    {
        $data = $request->all();
        $data = array_except($data, ['_method', '_token']);
        //20211117增加用戶名和保存時間自動填寫
        $data = (new ToolsRepository)->timestamp($data); //更新
        //$altcode = TextInstanceData::find($id);
        $id_l = explode("-", $id);
        $altcode = TextInstanceData::where([
            ['c_textid', '=', $id_l[0]],
            ['c_text_edition_id', '=', $id_l[1]],
            ['c_text_instance_id', '=', $id_l[2]]
        ])->update($data);
    }
}
