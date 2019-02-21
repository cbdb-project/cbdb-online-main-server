<?php
/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/8/30
 * Time: 17:10
 */

namespace App\Repositories;


use App\SocialInstitutionCode;
use Illuminate\Http\Request;

class SocialInstitutionCodeRepository {

    public function socialinstitutionByQuery(Request $request, $num=20)
    {
        if ($temp = $request->num){
            $num = $temp;
        }
        if (!$request->q){
            return SocialInstitutionCode::paginate($num);
        }
        $names = SocialInstitutionCode::where('c_inst_name_code', 'like', '%'.$request->q.'%')->orWhere('c_inst_code', 'like', '%'.$request->q.'%')->orWhere('c_inst_type_code', $request->q)->paginate($num);
        $names->appends(['q' => $request->q])->links();
        return $names;
    }

    public function byId($id)
    {
        return SocialInstitutionCode::find($id);
    }

    public function byUnionId($id)
    {
        $temp_l = explode("-", $id);
        $row = SocialInstitutionCode::where([
            ['c_inst_name_code', '=', $temp_l[0]],
            ['c_inst_code', '=', $temp_l[1]],
            ['c_inst_type_code', '=', $temp_l[2]],
        ])->first();
        return $row;
    }

    public function updateById($request, $id)
    {
        $data = $request->all();
        $altcode = SocialInstitutionCode::find($id);
        $altcode->update($data);
    }

    public function updateByUnionId($request, $id)
    {
        $data = $request->all();
        $data = array_except($data, ['_token', '_method']);
        $temp_l = explode("-", $id);
        $row = SocialInstitutionCode::where([
            ['c_inst_name_code', '=', $temp_l[0]],
            ['c_inst_code', '=', $temp_l[1]],
            ['c_inst_type_code', '=', $temp_l[2]]
        ])->update($data);
        return $data;
    }
}
