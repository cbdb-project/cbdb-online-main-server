<?php
/**
 * User: ja
 * Date: 2020/6/10
 * Time: 14:00
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\BiogMain;
use App\BiogAddr;
use App\BiogAddrCode;
use App\AddrCode;
use App\OfficeCode;
use App\EntryCode;
use App\KinshipCode;
use App\AssocCode;
use App\Operation;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

ini_set('memory_limit','512M');
ini_set('max_execution_time', 300);

class ApiController5 extends Controller
{
    use AuthenticatesUsers;

    public function __construct()
    {
        $this->middleware('guest');
    }

    //20200610製作[查詢人物社會關係]query_associates API
    protected function query_associates(Request $request) {
        $json = $request['RequestPlayload'];
        $arr = json_decode($json, true);
        $aName = $start = $list = $total = 0;
        $data = $useXyArr = $c_addr_id_Arr = $a_addr_id_Arr = array();

        $association = $arr['association'];
        $place = $arr['place'];
        $usePeoplePlace = $arr['usePeoplePlace'];
        $useXy = $arr['useXy'];
        $broad = $arr['broad'];
        if($broad == 1) { $XY = '0.06'; }
        if($broad == 0) { $XY = '0.03'; }
        
        if(!empty($arr['start'])) { 
            if($arr['start'] <= 0) { $start = 0; } // 避免start為負數
            else { $start = $arr['start'] - 1; } // return輸出由1開始, 程式需由0開始.
        }
        else { $start = 0; }

        if(!empty($arr['list'])) {
            $list = $arr['list'];
        }
        else { $list = 10000; }

        //資料庫邏輯
        $row = DB::table('ASSOC_DATA')->whereIn('ASSOC_DATA.c_assoc_code', $association);
        $row->join('BIOG_ADDR_DATA', 'BIOG_ADDR_DATA.c_personid', '=', 'ASSOC_DATA.c_personid'); 
        if($usePeoplePlace) {
            $row->whereIn('BIOG_ADDR_DATA.c_addr_id', $place);
        }

        if($useXy) {
            $rowOut = $row->get();
            foreach ($rowOut as $val) {
                if($val->c_addr_id != null) {
                    array_push($useXyArr, $val->c_addr_id);
                }
            }
            //判斷是否為空陣列
            if(!empty($useXyArr)) {
                $useXyVar = '';
                foreach ($useXyArr as $val) {
                    if($useXyVar)  $useXyVar .= ',';
                    $useXyVar .= $val;
                }
                $sqlTmp = sprintf('
SELECT DISTINCT ADDR_CODES.c_addr_id FROM ADDR_CODES
INNER JOIN ADDR_CODES AS ADDR_CODES_1
ON ADDR_CODES_1.c_addr_id in ('. $useXyVar .')
WHERE (((ADDR_CODES.x_coord)>=(ADDR_CODES_1.x_coord-'.$XY.') And (ADDR_CODES.x_coord)<=(ADDR_CODES_1.x_coord+'.$XY.')) AND ((ADDR_CODES.y_coord)>=(ADDR_CODES_1.y_coord-'.$XY.') And (ADDR_CODES.y_coord)<=(ADDR_CODES_1.y_coord+'.$XY.')))
');
                $useXyRes = DB::select($sqlTmp);
                $useXyResArr = array();
                foreach ($useXyRes as $val) {
                    array_push($useXyResArr, $val->c_addr_id);
                }
                //從這邊對原本的row進行過濾
                $row->whereIn('BIOG_ADDR_DATA.c_addr_id', $useXyResArr);
            }
        }
        $row = $row->get();
        //資料庫邏輯結束

        $total = count($row);

        //預先組合計算用的資料
        foreach ($row as $val) {
            //這裡是查詢人物的[地址]BIOG_ADDR_DATA
            $c_addr_type = $c_addr_id = 0;
            $BiogAddr = BiogAddr::where('c_personid', '=', $val->c_personid)->whereIn('c_addr_type', [1, 16, 6, 4, 2, 13, 14, 17])->first();
            if(!$BiogAddr) {
                $BiogAddr = BiogAddr::where('c_personid', '=', $val->c_personid)->first();
            }
            if($BiogAddr) {
                $c_addr_type = $BiogAddr->c_addr_type;
                $c_addr_id = $BiogAddr->c_addr_id;
            }
            $AddrCode = AddrCode::where('c_addr_id', '=', $c_addr_id)->first();

            array_push($c_addr_id_Arr, $c_addr_id.'-'.$AddrCode->c_name_chn);

            //這裡是查詢社會關係人的[地址]BIOG_ADDR_DATA
            $a_addr_type = $a_addr_id = 0;
            $ABiogAddr = BiogAddr::where('c_personid', '=', $val->c_assoc_id)->whereIn('c_addr_type', [1, 16, 6, 4, 2, 13, 14, 17])->first();
            if(!$ABiogAddr) {
                $ABiogAddr = BiogAddr::where('c_personid', '=', $val->c_assoc_id)->first();
            }
            if($ABiogAddr) {
                $a_addr_type = $ABiogAddr->c_addr_type;
                $a_addr_id = $ABiogAddr->c_addr_id;
            }
            $AAddrCode = AddrCode::where('c_addr_id', '=', $a_addr_id)->first();

            array_push($a_addr_id_Arr, $a_addr_id.'-'.$AAddrCode->c_name_chn);

        }
        $answer = array_count_values($c_addr_id_Arr);
        $answer2 = array_count_values($a_addr_id_Arr);

        //組合輸出資料
        foreach ($row as $val) {
            $BiogMain = BiogMain::where('c_personid', '=', $val->c_personid)->first();
            $data_val['pId'] = $val->c_personid;
            $data_val['pName'] = $BiogMain->c_name;
            $data_val['pNameChn'] = $BiogMain->c_name_chn;
            $data_val['pSex'] = $BiogMain->c_female ? '1-女' : '0-男';
            $data_val['pIndexYear'] = $BiogMain->c_index_year;
            //這裡是查詢人物的[地址]BIOG_ADDR_DATA
            $c_addr_type = $c_addr_id = 0;
            $BiogAddr = BiogAddr::where('c_personid', '=', $val->c_personid)->whereIn('c_addr_type', [1, 16, 6, 4, 2, 13, 14, 17])->first();
            if(!$BiogAddr) {
                $BiogAddr = BiogAddr::where('c_personid', '=', $val->c_personid)->first();
            }
            if($BiogAddr) {
                $c_addr_type = $BiogAddr->c_addr_type;
                $c_addr_id = $BiogAddr->c_addr_id;
            }
            $AddrCode = AddrCode::where('c_addr_id', '=', $c_addr_id)->first();
            //查詢結束
            $data_val['pAddrID'] = $c_addr_id;
            $data_val['pAddrName'] = $AddrCode->c_name;
            $data_val['pAddrNameChn'] = $AddrCode->c_name_chn;
            $data_val['pX'] = $AddrCode->x_coord;
            $data_val['pY'] = $AddrCode->y_coord;
            $data_val['p_xy_count'] = $answer[$c_addr_id.'-'.$AddrCode->c_name_chn];

            $KinshipCode = KinshipCode::where('c_kincode', '=', $val->c_kin_code)->first();
            $PBiogMain = BiogMain::where('c_personid', '=', $val->c_kin_id)->first();
            if(!$PBiogMain) { $PBiogMain = BiogMain::where('c_personid', '=', 0)->first(); }
            $data_val['pKinshipRelation'] = $KinshipCode->c_kinrel;
            $data_val['pKinshipRelationChn'] = $KinshipCode->c_kinrel_chn;
            $data_val['pKinName'] = $PBiogMain->c_name;
            $data_val['pKinNameChn'] = $PBiogMain->c_name_chn;
            $ABiogMain = BiogMain::where('c_personid', '=', $val->c_assoc_id)->first();
            if(!$ABiogMain) { $ABiogMain = BiogMain::where('c_personid', '=', 0)->first(); }
            $data_val['aId'] = $val->c_assoc_id;
            $data_val['aName'] = $ABiogMain->c_name;
            $data_val['aNameChn'] = $ABiogMain->c_name_chn;
            $data_val['aSex'] = $ABiogMain->c_female ? '1-女' : '0-男';
            $data_val['aIndexYear'] = $ABiogMain->c_index_year;
            //這裡是查詢社會關係人的[地址]BIOG_ADDR_DATA
            $a_addr_type = $a_addr_id = 0;
            $ABiogAddr = BiogAddr::where('c_personid', '=', $val->c_assoc_id)->whereIn('c_addr_type', [1, 16, 6, 4, 2, 13, 14, 17])->first();
            if(!$ABiogAddr) {
                $ABiogAddr = BiogAddr::where('c_personid', '=', $val->c_assoc_id)->first();
            }
            if($ABiogAddr) {
                $a_addr_type = $ABiogAddr->c_addr_type;
                $a_addr_id = $ABiogAddr->c_addr_id;
            }
            $AAddrCode = AddrCode::where('c_addr_id', '=', $a_addr_id)->first();
            //查詢結束
            $data_val['aAddrID'] = $a_addr_id;
            $data_val['aAddrName'] = $AAddrCode->c_name;
            $data_val['aAddrNameChn'] = $AAddrCode->c_name_chn;
            $data_val['aX'] = $AAddrCode->x_coord;
            $data_val['aY'] = $AAddrCode->y_coord;
            $data_val['a_xy_count'] = $answer2[$a_addr_id.'-'.$AAddrCode->c_name_chn];

            $AKKinshipCode = KinshipCode::where('c_kincode', '=', $val->c_assoc_kin_code)->first();
            $AKBiogMain = BiogMain::where('c_personid', '=', $val->c_assoc_kin_id)->first();
            if(!$AKBiogMain) { $AKBiogMain = BiogMain::where('c_personid', '=', 0)->first(); }
            $data_val['aKinshipRelation'] = $AKKinshipCode->c_kinrel;
            $data_val['aKinshipRelationChn'] = $AKKinshipCode->c_kinrel_chn;
            $data_val['aKinName'] = $AKBiogMain->c_name;
            $data_val['aKinNameChn'] = $AKBiogMain->c_name_chn;
            $data_val['distance'] = $val->c_assoc_count;
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
        //return $row;
    }

    //20200610製作[查找社會關係]find_assoc API
    protected function find_assoc(Request $request) {
        $aName = $start = $list = $total = 0;
        $data = array();
        $aName = $request['aName'];
        
        if(!empty($arr['start'])) { 
            if($arr['start'] <= 0) { $start = 0; } // 避免start為負數
            else { $start = $arr['start'] - 1; } // return輸出由1開始, 程式需由0開始.
        }
        else { $start = 0; }

        if(!empty($arr['list'])) {
            $list = $arr['list'];
        }
        else { $list = 10000; }

        //資料庫邏輯
        $row = AssocCode::where('c_assoc_desc_chn', 'like', '%'.$aName.'%')->orWhere('c_assoc_desc', 'like', '%'.$aName.'%');
        $row = $row->get();
        //資料庫邏輯結束

        $total = count($row);

        //組合輸出資料
        foreach ($row as $val) {
            $data_val['aId'] = $val->c_assoc_code;
            $data_val['aName'] = $val->c_assoc_desc;
            $data_val['aNameChn'] = $val->c_assoc_desc_chn;
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
        //return $row;
    }

    //20200610製作[根據社會關係類型代碼獲取社會關係]get_assoc API
    protected function get_assoc(Request $request) {
        $aType = $start = $list = $total = 0;
        $data = array();
        $aType = $request['aType'];
        
        if(!empty($arr['start'])) { 
            if($arr['start'] <= 0) { $start = 0; } // 避免start為負數
            else { $start = $arr['start'] - 1; } // return輸出由1開始, 程式需由0開始.
        }
        else { $start = 0; }

        if(!empty($arr['list'])) {
            $list = $arr['list'];
        }
        else { $list = 10000; }

        //資料庫邏輯
        $row = DB::table('ASSOC_CODE_TYPE_REL')->where('ASSOC_CODE_TYPE_REL.c_assoc_type_id', 'like', $aType.'%');
        $row->join('ASSOC_CODES', 'ASSOC_CODES.c_assoc_code', '=', 'ASSOC_CODE_TYPE_REL.c_assoc_code');
        $row = $row->get();
        //資料庫邏輯結束

        $total = count($row);

        //組合輸出資料
        foreach ($row as $val) {
            $data_val['aId'] = $val->c_assoc_code;
            $data_val['aName'] = $val->c_assoc_desc;
            $data_val['aNameChn'] = $val->c_assoc_desc_chn;
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
        //return $row;
    }

}
