<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\BiogMain;
use App\OfficeCode;
use App\OfficeCodeTypeRel;
use App\OfficeTypeTree;
use App\Operation;
use App\Repositories\BiogMainRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Auth;
use App\v1;

class OperationsController extends Controller
{

    public function add(Request $request)
    {
        //用來將json存入operations
        $x = $this->add_operations($request);
        return $x;
    }

    public function update(Request $request)
    {
        //要製作update_operations
        $x = $this->update_operations($request);
        return $x;
    }

    public function del(Request $request)
    {
        //要製作destroy_operations
        $x = $this->destroy_operations($request);
        return $x;
    }

    public function add_operations($keyword)
    {
        $z = $keyword['token'];
        $token = DB::table('users')->where('confirmation_token', $z)->get();
        $token = json_decode($token,true);
        $token = $token[0]['id'];
        if(empty($token)) { return '500'; }
        $x = $keyword['json'];
        if(empty($x)) { return '500'; }
        $y = $keyword['resource'];
        if(empty($y)) { return '500'; }

        $operation = new Operation();
        $operation->resource = $y;
        $operation->resource_data = $x;
        $operation->user_id = $token; //這邊要規劃由token取值.
        $operation->crowdsourcing_status = 2;
        $operation->op_type = 1;
        //crowdsourcing_status欄位值說明
        //0.專業用戶修改紀錄
        //1.crowdsourcing記錄並已插入數據庫 
        //2.crowdsourcing記錄還沒有被處理 
        //3.crowdsourcing記錄reject
        $message = $operation->save();
        $message ? $message='200' : $message='500';
        return $message;
    }

    public function update_operations($keyword)
    {
        $z = $keyword['token'];
        $token = DB::table('users')->where('confirmation_token', $z)->get();
        $token = json_decode($token,true);
        $token = $token[0]['id'];
        if(empty($token)) { return '500'; }
        $x = $keyword['json'];
        if(empty($x)) { return '500'; }
        $y = $keyword['resource'];
        if(empty($y)) { return '500'; }
        //20191224增加判斷式，開放其他API進入。
        if($y == "BIOG_MAIN") {
            $c_personid = $keyword['c_personid'];
            $resource_id = $c_personid;
            if(empty($c_personid)) { return '500'; }
            $BiogMainRepository = new BiogMainRepository();
            $ori = $BiogMainRepository->byPersonId($c_personid); 
        }
        else {
            $pId = $keyword['pId'];
            $c_personid = "";
            $resource_id = $pId;
            switch ($y) {
                case "OFFICE_CODES": 
                    $ori = OfficeCode::find($pId);
                    break;
                case "OFFICE_CODE_TYPE_REL":
                    $temp_l = explode("-", $pId);
                    $ori = OfficeCodeTypeRel::where('c_office_id', $temp_l[0])->where('c_office_tree_id', $temp_l[1])->first();
                    break;
                case "OFFICE_TYPE_TREE":
                    $ori = OfficeTypeTree::find($pId);
                    break;
                default:
                    $ori = null;
                    break;
            }
        }
        $operation = new Operation();
        $operation->resource = $y;
        $operation->c_personid = $c_personid;
        $operation->resource_id = $resource_id;
        $operation->resource_data = $x;
        $operation->resource_original = $ori;
        $operation->user_id = $token; //這邊要規劃由token取值.
        $operation->crowdsourcing_status = 2;
        $operation->op_type = 3;
        $message = $operation->save();
        $message ? $message='200' : $message='500';
        return $message;
    }

    public function destroy_operations($keyword)
    {
        $z = $keyword['token'];
        $token = DB::table('users')->where('confirmation_token', $z)->get();
        $token = json_decode($token,true);
        $token = $token[0]['id'];
        if(empty($token)) { return '500'; }
        $y = $keyword['resource'];
        if(empty($y)) { return '500'; }
        //20191224增加判斷式，開放其他API進入。
        if($y == "BIOG_MAIN") {
            $c_personid = $keyword['c_personid'];
            $resource_id = $c_personid;
            if(empty($c_personid)) { return '500'; }
            $BiogMainRepository = new BiogMainRepository();
            $ori = $BiogMainRepository->byPersonId($c_personid);
            $biog = BiogMain::find($c_personid);
            $biog->c_name_chn = '<待删除>';
        }
        else {
            $pId = $keyword['pId'];
            $c_personid = "";
            $resource_id = $pId;
            $ori = null;
            switch ($y) {
                case "OFFICE_CODES":
                    $biog = OfficeCode::find($pId);
                    break;
                case "OFFICE_CODE_TYPE_REL":
                    $temp_l = explode("-", $pId);
                    $biog = OfficeCodeTypeRel::where('c_office_id', $temp_l[0])->where('c_office_tree_id', $temp_l[1])->first();
                    break;
                case "OFFICE_TYPE_TREE":
                    $biog = OfficeTypeTree::find($pId);
                    break;
                default:
                    $biog = null;
                    break;
            }
        }
        $operation = new Operation();
        $operation->resource = $y;
        $operation->c_personid = $c_personid;
        $operation->resource_id = $resource_id;
        $operation->resource_data = $biog;
        $operation->resource_original = $ori;
        $operation->user_id = $token; //這邊要規劃由token取值.
        $operation->crowdsourcing_status = 2;
        $operation->op_type = 4;
        $message = $operation->save();
        $message ? $message='200' : $message='500';
        return $message;
    }

    public function storeProcess(Request $request)
    {
        //20190531這邊要取得table名稱, 規劃建置switch case來處理各種儲存
        $id = $request['id'];
        DB::table('operations')->where('id', $id)->update(array('crowdsourcing_status' => 1));
        $data = DB::table('operations')->where('id', $id)->get();
        $data = json_decode($data,true);
        $data = $data[0]['resource_data'];
        $data = json_decode($data,true);
        $new_id = BiogMain::max('c_personid') + 1;
        $new_ttsid = BiogMain::max('tts_sysno') + 1;
        $data['c_personid'] = $new_id;
        $data['tts_sysno'] = $new_ttsid;
        $message = BiogMain::create($data);
        $message ? $message='200' : $message='500';
        return $message;
    }

    public function token(Request $request) {
        $user_id = $request->q;
        $user_password = $request->p;
        //呼叫這行就可以進行帳號與密碼的認證了
        if (Auth::attempt(['email' => $user_id, 'password' => $user_password])) {
            $data = DB::table('users')->where('email','=',$user_id)->get();
            foreach($data as $item){
                if($item->is_admin != 2) { return "帳號須為眾包身分，才可以取得token。"; }
                $data = $item->confirmation_token;
            }
            return $data;
        }
        else { return "您的帳號與密碼輸入錯誤"; }
    }
}
