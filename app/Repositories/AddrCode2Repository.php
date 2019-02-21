<?php
/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/8/30
 * Time: 17:10
 */

namespace App\Repositories;


use App\AddrCode;
use Illuminate\Http\Request;

class AddrCode2Repository{
    public function addrByQuery(Request $request, $num=20)
    {
        if ($temp = $request->num){
            $num = $temp;
        }
        if (!$request->q){
            return AddrCode::paginate($num);
        }
        $names = AddrCode::where('c_name_chn', 'like', '%'.$request->q.'%')->orWhere('c_name', 'like', '%'.$request->q.'%')->orWhere('c_addr_id', $request->q)->paginate($num);
        $names->appends(['q' => $request->q])->links();
        return $names;
    }

    public function byId($id)
    {
        return AddrCode::find($id);
    }

    public function updateById($request, $id)
    {
        $data = $request->all();
        $altcode = AddrCode::find($id);
        $altcode->update($data);
    }
}
