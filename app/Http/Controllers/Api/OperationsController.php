<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\BiogMain;
use App\Operation;
use App\Repositories\BiogMainRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Auth;
use App\v1;

class OperationsController extends Controller
{
    public function hello(Request $request)
    {
        return $request;
    }

    public function add(Request $request)
    {

        //用來將json存入operations
        $x = $this->add_operations($request);
        return $x;

/*
        //用來將json存入資料表
        $x = $this->storeProcess(27);
        return $x;
*/
        //$this->operationRepository->store(Auth::id(), $new_id, 1, 'BIOG_MAIN', $new_id, $data);
        //$data = new v1();
        //return $data->addC($request);
    }

    public function add_operations($keyword)
    {
        $z = $keyword['token'];
        $token = DB::table('users')->where('confirmation_token', $z)->get();
        $token = json_decode($token,true);
        $token = $token[0]['id'];
        if(empty($token)) { return '500'; }
        $x = $keyword['json'];
        $y = $keyword['resource'];
        $operation = new Operation();
        $operation->resource = $y;
        $operation->resource_data = $x;
        $operation->user_id = $token; //這邊要規劃由token取值.
        $operation->crowdsourcing_status = 2;
        //crowdsourcing_status欄位值說明
        //0.專業用戶修改紀錄
        //1.crowdsourcing記錄並已插入數據庫 
        //2.crowdsourcing記錄還沒有被處理 
        //3.crowdsourcing記錄reject
        $message = $operation->save();
        $message ? $message='200' : $message='500';
        return $message;
    }

    public function storeProcess(Request $request)
    {
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

    public function store($user_id, $c_personid, $op_type, $resource, $resource_id, $resource_data)
    {
        $operation = new Operation();
        $operation->user_id = $user_id;
        $operation->c_personid = $c_personid;
        $operation->op_type = $op_type;
        $operation->resource = $resource;
        $operation->resource_id = $resource_id;
        $operation->resource_data = json_encode($resource_data);
        $operation->save();
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
