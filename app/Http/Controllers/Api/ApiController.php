<?php
/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2018/6/1
 * Time: 15:40
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\BiogMain;
use App\OfficeCode;
use App\OfficeCodeTypeRel;
use App\OfficeTypeTree;
use App\Operation;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

ini_set('memory_limit','512M');
ini_set('max_execution_time', 300);

class ApiController extends Controller
{
    use AuthenticatesUsers;

    public function __construct()
    {
        $this->middleware('guest');
    }

    //20200324依據指定規格製作API
    protected function post_list(Request $request){
        $ans = $data = $data_val = array();
        // 變數接值
        $id = $request['id'];
        if($request['start'] <= 0) { $start = 0; } // 避免start為負數
        else { $start = $request['start'] - 1; } // return輸出由1開始, 程式需由0開始.
        $list = $request['list'];

        if($list) {
            $biogAll = OfficeCodeTypeRel::where('c_office_tree_id', 'like', $id.'%')->get();
            $total = count($biogAll);
            $biog = $biogAll->slice($start, $list);
        }
        elseif($id) {
            $biog = OfficeCodeTypeRel::where('c_office_tree_id', 'like', $id.'%')->get();
            $total = count($biog);
        }
        else {
            $biog = OfficeCodeTypeRel::all();
            $total = count($biog);
        }

        foreach ($biog as $val) {
            $c_office_id = $val->c_office_id;
            $biog2 = OfficeCode::where('c_office_id', '=', $c_office_id)->get();
            foreach ($biog2 as $val2) {
                $data_val['pId'] = $c_office_id;
                $data_val['pName'] = $val2->c_office_pinyin;
                $data_val['pNameChn'] = $val2->c_office_chn;
                array_push($data, $data_val);
            }
        }

        $ans['total'] = $total;
        if(isset($start)) { $ans['start'] = (int)$start + 1; } // return輸出由1開始, 程式需由0開始, 這裡把1加回.
        if(isset($list) && $list >= 0) {
            $ans['end'] = (int)$list + (int)$start;
            if($ans['end'] > $ans['total']) { $ans['end'] = $ans['total']; }
        }
        $ans['data'] = $data;

        return $ans;
    }

    protected function OFFICE_CODES(Request $request){
        if($end = $request['end']) {
            if($start = $request['start']) {
                $diffNum = $end - $start + 1;
                $biog = OfficeCode::all()->slice($start, $diffNum);
            }
            else {
                $biog = OfficeCode::all()->take($end);
            }
        }
        elseif($start = $request['start'] && $list = $request['list']) {
            $biog = OfficeCode::all()->slice($start, $list);
        }
        elseif($list = $request['list']) {
            $biog = OfficeCode::all()->take($list); 
        }
        elseif($pId = $request['pId']) {
            $biog = OfficeCode::where('c_office_id', $pId)->get();
        }
        else {
            $biog = OfficeCode::all();
        }
        return $biog;
    }

    protected function OFFICE_CODE_TYPE_REL(Request $request){
        if($end = $request['end']) {
            if($start = $request['start']) {
                $diffNum = $end - $start + 1;
                $biog = OfficeCodeTypeRel::all()->slice($start, $diffNum);
            }
            else {
                $biog = OfficeCodeTypeRel::all()->take($end);
            }
        }
        elseif($start = $request['start'] && $list = $request['list']) {
            $biog = OfficeCodeTypeRel::all()->slice($start, $list);
        }
        elseif($list = $request['list']) {
            $biog = OfficeCodeTypeRel::all()->take($list);
        }
        elseif($pId = $request['pId']) {
            $temp_l = explode("-", $pId);
            $biog = OfficeCodeTypeRel::where('c_office_id', $temp_l[0])->where('c_office_tree_id', $temp_l[1])->first();
        }
        else {
            $biog = OfficeCodeTypeRel::all();
        }
        return $biog;
    }

    protected function OFFICE_TYPE_TREE(Request $request){
        if($end = $request['end']) {
            if($start = $request['start']) {
                $diffNum = $end - $start + 1;
                $biog = OfficeTypeTree::all()->slice($start, $diffNum);
            }
            else {
                $biog = OfficeTypeTree::all()->take($end);
            }
        }
        elseif($start = $request['start'] && $list = $request['list']) {
            $biog = OfficeTypeTree::all()->slice($start, $list);
        }
        elseif($list = $request['list']) {
            $biog = OfficeTypeTree::all()->take($list);
        }
        elseif($pId = $request['pId']) {
            $biog = OfficeTypeTree::where('c_office_type_node_id', $pId)->get();
        }
        else {
            $biog = OfficeTypeTree::all();
        }
        return $biog;
    }

    protected function authenticateClient(Request $request){
        $credentials = $this->credentials($request);

        $request->request->add([
            'grant_type' => $request->grant_type,
            'client_id' => $request->client_id,
            'client_secret' => $request->client_secret,
            'username' => $credentials['email'],
            'password' => $credentials['password'],
        ]);

        $proxy = Request::create('oauth/token', 'POST');

        $reponse = \Route::dispatch($proxy);
        return $reponse;
    }

    protected function authenticated(Request $request)
    {
        return $this->authenticateClient($request);
    }

    protected function sendLoginResponse(Request $request)
    {
        $this->clearLoginAttempts($request);
        return $this->authenticated($request);
    }

    protected function sendFailedLoginResponse(Request $request)
    {
        $msg = $request['errors'];
        $code = $request['code'];
        return $this->failed($msg, $code);
    }
}
