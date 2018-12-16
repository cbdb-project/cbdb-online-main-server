<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use App\BiogMain;
use Auth;
use Carbon\Carbon;

class v1 extends Model {

    public function info() {
        return phpinfo();
    }

    public function token(Request $request) {
        $user_id = $request->q;
        $user_password = $request->p;
        //呼叫這行就可以進行帳號與密碼的認證了
        if (Auth::attempt(['email' => $user_id, 'password' => $user_password])) {
            $data = DB::table('users')->where('email','=',$user_id)->get();
            foreach($data as $item){
                $data = $item->confirmation_token;
            }
            return $data;
        }
        else { return "您的帳號與密碼輸入錯誤"; }
        //return DB::table('users')->get();
    }

    public function search(Request $request) {
        $data = BiogMain::where('c_name_chn', 'like', $request->q)->orWhere('c_name', 'like', $request->q)->orWhere('c_personid', $request->q)->paginate(10);
        $data->appends(['q' => $request->q])->links();
        return $data;
    }

    public function addC(Request $request)
    {
        $c_name_chn = $request->c_name_chn;
        $c_name = $request->c_name;
        $temp_id = BiogMain::max('c_personid') + 1;
        $c_created_date = Carbon::now()->format('Ymd');
        $biog = BiogMain::create(['c_personid'=>$temp_id,'c_name_chn'=>$c_name_chn,'c_name'=>$c_name,'c_created_by'=>'Api','c_created_date'=>$c_created_date]);
        return "已經新增此筆資料 \n $biog";
    }

    public function updateC(Request $request)
    {
        $id = $request->q;
        $c_name_chn = $request->c_name_chn;
        $c_name = $request->c_name;
        $biog = BiogMain::find($id);
        if(empty($biog)) return "沒有此筆資料";
        if(!empty($c_name_chn)){ $biog->c_name_chn = $c_name_chn; }
        if(!empty($c_name)){ $biog->c_name = $c_name; }
        $biog->save();
        return "已經更新此筆資料 \n $biog";
    }

    public function deleteC(Request $request)
    {
        $id = $request->q;
        $biog = BiogMain::find($id);
        $biog->c_name_chn = '<待删除>';
        $biog->save();
        //這行是重要的,要把紀錄寫到operation
        //$this->operationRepository->store(Auth::id(), $id, 4, 'BIOG_MAIN', $id, []);
        return "已經將此筆資料設定為刪除";
    }
}
