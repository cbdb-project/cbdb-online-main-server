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
use App\EntryCode;
use App\EntryCodeTypeRel;
use App\AddrCode;
use App\AddrBelongsData;
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

    //20200427依據指定規格製作entry_list_by_name API
    protected function entry_list_by_name(Request $request){
        $ans = $data = $data_val = array();
        // 變數接值
        $eName = $request['eName'];
        $accurate = $request['accurate'];
        if($request['start'] <= 0) { $start = 0; } // 避免start為負數
        else { $start = $request['start'] - 1; } // return輸出由1開始, 程式需由0開始.
        $list = $request['list'];

        if($list) {
            if($accurate == 1) {
                $biogAll = EntryCode::where('c_entry_desc', '=', $eName)
                    ->orWhere('c_entry_desc_chn', '=', $eName)->get();
            }
            else {
                $biogAll = EntryCode::where('c_entry_desc', 'like', '%'.$eName.'%')
                    ->orWhere('c_entry_desc_chn', 'like', '%'.$eName.'%')->get();
            }
            $total = count($biogAll);
            $biog = $biogAll->slice($start, $list);
        }
        elseif($pName) {
            if($accurate == 1) {
                $biog = EntryCode::where('c_entry_desc', '=', $eName)
                    ->orWhere('c_entry_desc_chn', '=', $eName)->get();
            }
            else {
                $biog = EntryCode::where('c_entry_desc', 'like', '%'.$eName.'%')
                    ->orWhere('c_entry_desc_chn', 'like', '%'.$eName.'%')->get();
            }
            $total = count($biog);
        }
        else {
            $biog = EntryCode::all();
            $total = count($biog);
        }

        foreach ($biog as $val) {
            $data_val['eId'] = $val->c_entry_code;
            $data_val['eName'] = $val->c_entry_desc;
            $data_val['eNameChn'] = $val->c_entry_desc_chn;
            array_push($data, $data_val);
        }

        $ans['total'] = $total;
        if(isset($start)) { $ans['start'] = (int)$start + 1; } // return輸出由1開始, 程式需由0開始, 這裡把1加回.
        if(isset($list) && $list >= 0) {
            $ans['end'] = (int)$list + (int)$start;
            if($ans['end'] > $ans['total']) { $ans['end'] = $ans['total']; }
        }
        else {
            $ans['end'] = (int)$total;
        }
        $ans['data'] = $data;

        return $ans;
    }

    //20200427依據指定規格製作office_list_by_name API
    //20210304增列data[i].pNameChnAlt的屬性，官職的別名漢字。
    protected function office_list_by_name(Request $request){
        $ans = $data = $data_val = array();
        // 變數接值
        $pName = $request['pName'];
        $accurate = $request['accurate'];
        if($request['start'] <= 0) { $start = 0; } // 避免start為負數
        else { $start = $request['start'] - 1; } // return輸出由1開始, 程式需由0開始.
        $list = $request['list'];

        if($list) {
            if($accurate == 1) {
                $biogAll = OfficeCode::where('c_office_chn', '=', $pName)
                    ->orWhere('c_office_pinyin', '=', $pName)->get();
            }
            else {
                $biogAll = OfficeCode::where('c_office_chn', 'like', '%'.$pName.'%')
                    ->orWhere('c_office_pinyin', 'like', '%'.$pName.'%')->get();
            }
            $total = count($biogAll);
            $biog = $biogAll->slice($start, $list);
        }
        elseif($pName) {
            if($accurate == 1) {
                $biog = OfficeCode::where('c_office_chn', '=', $pName)
                    ->orWhere('c_office_pinyin', '=', $pName)->get();
            }
            else {
                $biog = OfficeCode::where('c_office_chn', 'like', '%'.$pName.'%')
                    ->orWhere('c_office_pinyin', 'like', '%'.$pName.'%')->get();
            }
            $total = count($biog);
        }
        else {
            $biog = OfficeCode::all();
            $total = count($biog);
        }

        foreach ($biog as $val) {
            $data_val['pId'] = $val->c_office_id;
            $data_val['pName'] = $val->c_office_pinyin;
            $data_val['pNameChn'] = $val->c_office_chn;
            $data_val['pNameChnAlt'] = $val->c_office_chn_alt;
            array_push($data, $data_val);
        }

        $ans['total'] = $total;
        if(isset($start)) { $ans['start'] = (int)$start + 1; } // return輸出由1開始, 程式需由0開始, 這裡把1加回.
        if(isset($list) && $list >= 0) {
            $ans['end'] = (int)$list + (int)$start;
            if($ans['end'] > $ans['total']) { $ans['end'] = $ans['total']; }
        }
        else {
            $ans['end'] = (int)$total;
        }
        $ans['data'] = $data;

        return $ans;
    }

    //20200409依據指定規格製作place_belongs_to API
    public function add_place_belongs_to($id, $data_all, $once){
        $c_addr_id = $c_belongs_to = '';
        $newIdArr = array();
        if($once == 1) { // 取得AddrCode第一筆資料
            $biogOnce = AddrBelongsData::whereIn('c_addr_id', $id)->get();
            foreach ($biogOnce as $val1) {
                $c_addr_id = $val1->c_addr_id;
                $c_belongs_to = $val1->c_belongs_to;

                $biog2Once = AddrCode::where('c_addr_id', '=', $c_addr_id)->get();
                foreach ($biog2Once as $val2) {
                    $data_val['pId'] = $c_addr_id;
                    $data_val['pName'] = $val2->c_name;
                    $data_val['pNameChn'] = $val2->c_name_chn;
                    $data_val['pStartTime'] = $val2->c_firstyear;
                    $data_val['pEndTime'] = $val2->c_lastyear;
                }
                $biog3Once = AddrCode::where('c_addr_id', '=', $c_belongs_to)->get();
                foreach ($biog3Once as $val3) {
                    $data_val['pBId'] = $c_belongs_to;
                    $data_val['pBName'] = $val3->c_name;
                    $data_val['pBNameChn'] = $val3->c_name_chn;
                }
                array_push($data_all, $data_val);
            }
        }
        $biog = AddrBelongsData::whereIn('c_belongs_to', $id)->get();
        if($biog) { //AddrBelongsData有值，才執行。
            foreach ($biog as $val1) {
                $c_addr_id = $val1->c_addr_id;
                $c_belongs_to = $val1->c_belongs_to;
                $biog2 = AddrCode::where('c_addr_id', '=', $c_addr_id)->get();
                foreach ($biog2 as $val2) {
                    $data_val['pId'] = $c_addr_id;
                    $data_val['pName'] = $val2->c_name;
                    $data_val['pNameChn'] = $val2->c_name_chn;
                    $data_val['pStartTime'] = $val2->c_firstyear;
                    $data_val['pEndTime'] = $val2->c_lastyear;
                }
                $biog3 = AddrCode::where('c_addr_id', '=', $c_belongs_to)->get();
                foreach ($biog3 as $val3) {
                    $data_val['pBId'] = $c_belongs_to;
                    $data_val['pBName'] = $val3->c_name;
                    $data_val['pBNameChn'] = $val3->c_name_chn;
                }
                array_push($data_all, $data_val);
                array_push($newIdArr, $c_addr_id);
            }

            if($c_belongs_to == '0' || $c_belongs_to == '' || $c_addr_id == '') {
                // AddrBelongsData底下沒有資料了，回傳陣列。 
                return $data_all;
            }
            else {
                // AddrBelongsData底下還有資料，再次執行遞迴，同時也把目前組合的陣列傳遞給後續的遞迴。
                $data_all = $this->add_place_belongs_to($newIdArr, $data_all ,0);
                return $data_all;
            }
        }
        else { //如果AddrBelongsData沒有值，直接回傳陣列。
          return $data_all;
        }
    }

    protected function place_belongs_to(Request $request){
        $ans = $data = $data_val = $data_all = $id = array();
        // 變數接值
        array_push($id, $request['id']);
        if($request['start'] <= 0) { $start = 0; } // 避免start為負數
        else { $start = $request['start'] - 1; } // return輸出由1開始, 程式需由0開始.
        $list = $request['list'];

        if($list) {
            $biogAll = $this->add_place_belongs_to($id, $data_all, 1);
            $total = count($biogAll);
            //陣列排序依據pId的順序
            $biogAll = array_values(array_sort($biogAll, function($value) {
                return $value['pId'];
            }));
            $data = array_slice($biogAll, $start, $list);
        }
        elseif($id) {
            $data = $this->add_place_belongs_to($id, $data_all, 1);
            $total = count($data);
            //陣列排序依據pId的順序
            $data = array_values(array_sort($data, function($value) {
                return $value['pId'];
            }));
        }
        else {
            return 500;
        }

        $ans['total'] = $total;
        if(isset($start)) { $ans['start'] = (int)$start + 1; } // return輸出由1開始, 程式需由0開始, 這裡把1加回.
        if(isset($list) && $list >= 0) {
            $ans['end'] = (int)$list + (int)$start;
            if($ans['end'] > $ans['total']) { $ans['end'] = $ans['total']; }
        }
        else {
            $ans['end'] = (int)$total;
        }
        $ans['data'] = $data;

        return $ans;
    }

    //20200408依據指定規格製作place_list API
    protected function place_list(Request $request){
        $ans = $data = $data_val = array();
        // 變數接值
        $name = $request['name'];
        $startTime = $request['startTime'];
        $endTime = $request['endTime'];
        $accurate = $request['accurate'];
        if($request['start'] <= 0) { $start = 0; } // 避免start為負數
        else { $start = $request['start'] - 1; } // return輸出由1開始, 程式需由0開始.
        $list = $request['list'];

        if($list) {
            if($accurate == 1) {
                if($startTime && $endTime) {
                    $biogAll = AddrCode::where(function ($query) use ($name, $startTime ,$endTime) {
                        $query->where('c_name', 'like', '%'.$name.'%')
                              ->where('c_lastyear', '>=', $startTime)
                              ->where('c_firstyear', '<=', $endTime);
                        })->orWhere(function ($query) use ($name, $startTime ,$endTime) {
                        $query->where('c_name_chn', 'like', '%'.$name.'%')
                              ->where('c_lastyear', '>=', $startTime)
                              ->where('c_firstyear', '<=', $endTime);
                        })->get();
                }
                else {
                    $biogAll = AddrCode::where('c_name', 'like', '%'.$name.'%')->orWhere('c_name_chn', 'like', '%'.$name.'%')->get();
                }
            }
            else {
                if($startTime && $endTime) {
                    $biogAll = AddrCode::where(function ($query) use ($name, $startTime ,$endTime) {
                        $query->where('c_name', '=', $name)
                              ->where('c_lastyear', '>=', $startTime)
                              ->where('c_firstyear', '<=', $endTime);
                        })->orWhere(function ($query) use ($name, $startTime ,$endTime) {
                        $query->where('c_name_chn', '=', $name)
                              ->where('c_lastyear', '>=', $startTime)
                              ->where('c_firstyear', '<=', $endTime);
                        })->get();
                }
                else {
                    $biogAll = AddrCode::where('c_name', '=', $name)->orWhere('c_name_chn', '=', $name)->get();
                }
            }
            $total = count($biogAll);
            $biog = $biogAll->slice($start, $list);
        }
        elseif($name) {
            if($accurate == 1) {
                if($startTime && $endTime) {
                    $biog = AddrCode::where(function ($query) use ($name, $startTime ,$endTime) {
                        $query->where('c_name', 'like', '%'.$name.'%')
                              ->where('c_lastyear', '>=', $startTime)
                              ->where('c_firstyear', '<=', $endTime);
                        })->orWhere(function ($query) use ($name, $startTime ,$endTime) {
                        $query->where('c_name_chn', 'like', '%'.$name.'%')
                              ->where('c_lastyear', '>=', $startTime)
                              ->where('c_firstyear', '<=', $endTime);
                        })->get();
                }
                else {
                    $biog = AddrCode::where('c_name', 'like', '%'.$name.'%')->orWhere('c_name_chn', 'like', '%'.$name.'%')->get();
                }
            }
            else {
                if($startTime && $endTime) {
                    $biog = AddrCode::where(function ($query) use ($name, $startTime ,$endTime) {
                        $query->where('c_name', '=', $name)
                              ->where('c_lastyear', '>=', $startTime)
                              ->where('c_firstyear', '<=', $endTime);
                        })->orWhere(function ($query) use ($name, $startTime ,$endTime) {
                        $query->where('c_name_chn', '=', $name)
                              ->where('c_lastyear', '>=', $startTime)
                              ->where('c_firstyear', '<=', $endTime);
                        })->get();
                }
                else {
                    $biog = AddrCode::where('c_name', '=', $name)->orWhere('c_name_chn', '=', $name)->get();
                }
            }
            $total = count($biog);
        }
        else {
            $biog = AddrCode::all();
            $total = count($biog);
        }

        foreach ($biog as $val) {
            $c_addr_id = $val->c_addr_id;
            $data_val['pId'] = $c_addr_id;
            $data_val['pName'] = $val->c_name;
            $data_val['pNameChn'] = $val->c_name_chn;
            $data_val['pStartTime'] = $val->c_firstyear;
            $data_val['pEndTime'] = $val->c_lastyear;
            $pBName = $pBNameChn = "";
            $biog2 = AddrBelongsData::where('c_addr_id', '=', $c_addr_id)->get();
            foreach ($biog2 as $val2) {
                $biog3 = AddrCode::where('c_addr_id', '=', $val2->c_belongs_to)->get();
                foreach ($biog3 as $val3) {
                    $pBName = $val3->c_name;
                    $pBNameChn = $val3->c_name_chn;
                }
            }
            $data_val['pBName'] = $pBName;
            $data_val['pBNameChn'] = $pBNameChn;
            array_push($data, $data_val);
        }

        $ans['total'] = $total;
        if(isset($start)) { $ans['start'] = (int)$start + 1; } // return輸出由1開始, 程式需由0開始, 這裡把1加回.
        if(isset($list) && $list >= 0) {
            $ans['end'] = (int)$list + (int)$start;
            if($ans['end'] > $ans['total']) { $ans['end'] = $ans['total']; }
        }
        else {
            $ans['end'] = (int)$total;
        }
        $ans['data'] = $data;

        return $ans;
    }

    //20200408依據指定規格製作entry_list API
    protected function entry_list(Request $request){
        $ans = $data = $data_val = array();
        // 變數接值
        $id = $request['id'];
        if($request['start'] <= 0) { $start = 0; } // 避免start為負數
        else { $start = $request['start'] - 1; } // return輸出由1開始, 程式需由0開始.
        $list = $request['list'];

        if($list) {
            $biogAll = EntryCodeTypeRel::where('c_entry_type', 'like', $id.'%')->get();
            $total = count($biogAll);
            $biog = $biogAll->slice($start, $list);
        }
        elseif($id) {
            $biog = EntryCodeTypeRel::where('c_entry_type', 'like', $id.'%')->get();
            $total = count($biog);
        }
        else {
            $biog = EntryCodeTypeRel::all();
            $total = count($biog);
        }

        foreach ($biog as $val) {
            $c_entry_code = $val->c_entry_code;
            $biog2 = EntryCode::where('c_entry_code', '=', $c_entry_code)->get();
            foreach ($biog2 as $val2) {
                $data_val['eId'] = $c_entry_code;
                $data_val['eName'] = $val2->c_entry_desc;
                $data_val['eNameChn'] = $val2->c_entry_desc_chn;
                array_push($data, $data_val);
            }
        }

        $ans['total'] = $total;
        if(isset($start)) { $ans['start'] = (int)$start + 1; } // return輸出由1開始, 程式需由0開始, 這裡把1加回.
        if(isset($list) && $list >= 0) {
            $ans['end'] = (int)$list + (int)$start;
            if($ans['end'] > $ans['total']) { $ans['end'] = $ans['total']; }
        }
        else {
            $ans['end'] = (int)$total;
        }
        $ans['data'] = $data;

        return $ans;
    }

    //20200324依據指定規格製作API
    //20210305增列data[i].pNameChnAlt的屬性，官職的別名漢字。
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
                $data_val['pNameChnAlt'] = $val2->c_office_chn_alt;
                array_push($data, $data_val);
            }
        }

        $ans['total'] = $total;
        if(isset($start)) { $ans['start'] = (int)$start + 1; } // return輸出由1開始, 程式需由0開始, 這裡把1加回.
        if(isset($list) && $list >= 0) {
            $ans['end'] = (int)$list + (int)$start;
            if($ans['end'] > $ans['total']) { $ans['end'] = $ans['total']; }
        }

        else {
            $ans['end'] = (int)$total;
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
