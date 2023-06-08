<?php
/**
 * User: ja
 * Date: 2021/06/16
 * Time: 09:20
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
use App\Dynasty;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

ini_set('memory_limit','1024M');
ini_set('max_execution_time', 600);

class ApiController3 extends Controller
{
    use AuthenticatesUsers;

    public function __construct()
    {
        $this->middleware('guest');
    }

    //20200522依據指定規格製作query_entry_postings API
    protected function query_entry_postings(Request $request) {
        $json = $request['RequestPayload'];
        $arr = json_decode($json, true);
        $entry = $peoplePlace = $data = $useXyArr = array();
        $usePeoplePlace = $locationType = $useDate = $dateType = $dateStartTime = $dateEndTime = $useXy = $start = $list = 0;
        
        $entry = $arr['entry'];
        $usePeoplePlace = $arr['usePeoplePlace']; 
        $peoplePlace = $arr['peoplePlace'];
        $locationType = $arr['locationType']; 
        $useDate = $arr['useDate'];
        $dateType = $arr['dateType']; 
        $dateStartTime = $arr['dateStartTime']; 
        $dateEndTime = $arr['dateEndTime'];
        $dynStart = $arr['dynStart'];
        $dynEnd = $arr['dynEnd']; 
        $useXy = $arr['useXy']; 
        if($arr['start'] <= 0) { $start = 0; } // 避免start為負數
        else { $start = $arr['start'] - 1; } // return輸出由1開始, 程式需由0開始.
        $list = $arr['list'];

        //資料庫邏輯
        $row = DB::table('ENTRY_DATA')->whereIn('ENTRY_DATA.c_entry_code', $entry);
        $row->join('BIOG_MAIN', 'ENTRY_DATA.c_personid', '=', 'BIOG_MAIN.c_personid');

        if($usePeoplePlace) {
            //人物地點BIOG_ADDR_DATA，地名資料ADDR_CODES
            if($locationType == 'pAddr') {
                $row->join('BIOG_ADDR_DATA', 'ENTRY_DATA.c_personid', '=', 'BIOG_ADDR_DATA.c_personid');
                $row->whereIn('BIOG_ADDR_DATA.c_addr_id', $peoplePlace);
            }
            elseif($locationType == 'eAddr') {
                $row->whereIn('ENTRY_DATA.c_entry_addr_id', $peoplePlace);
            }
            elseif($locationType == 'peAddr') {
                $row->join('BIOG_ADDR_DATA', 'ENTRY_DATA.c_personid', '=', 'BIOG_ADDR_DATA.c_personid');
                $row->where(function ($query) use ($peoplePlace) {
                    $query->whereIn('ENTRY_DATA.c_entry_addr_id', $peoplePlace)->orWhereIn('BIOG_ADDR_DATA.c_addr_id', $peoplePlace);
                });
            }
            else {}
        }
        if($useDate) {
            if($dateType == 'entry') {
                if(empty($dateStartTime) || empty($dateEndTime)) { return 'Plaese check dateStartTime and dateEndTime have value.'; }
                $row->where('ENTRY_DATA.c_year', '>=', $dateStartTime);
                $row->where('ENTRY_DATA.c_year', '<=', $dateEndTime);
            }
            elseif($dateType == 'index') {
                if(empty($dateStartTime) || empty($dateEndTime)) { return 'Plaese check dateStartTime and dateEndTime have value.'; }
                $row->where('BIOG_MAIN.c_index_year', '>=', $dateStartTime);
                $row->where('BIOG_MAIN.c_index_year', '<=', $dateEndTime);
            }
            elseif($dateType == 'dynasty') {
                if(empty($dynStart) || empty($dynEnd)) { return 'Plaese check dynStart and dynEnd have value.'; }
                $row->join('DYNASTIES', 'BIOG_MAIN.c_dy', '=', 'DYNASTIES.c_dy');
                $row->where('DYNASTIES.c_dy', '>=', $dynStart);
                $row->where('DYNASTIES.c_dy', '<=', $dynEnd);
            }
            else {}
        }
        if($useXy) {
            $rowOut = $row->get();
            foreach ($rowOut as $val) {
                if($val->c_entry_addr_id != null) {
                    array_push($useXyArr, $val->c_entry_addr_id);
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
WHERE (((ADDR_CODES.x_coord)>=(ADDR_CODES_1.x_coord-0.03) And (ADDR_CODES.x_coord)<=(ADDR_CODES_1.x_coord+0.03)) AND ((ADDR_CODES.y_coord)>=(ADDR_CODES_1.y_coord-0.03) And (ADDR_CODES.y_coord)<=(ADDR_CODES_1.y_coord+0.03)))
');
                $useXyRes = DB::select($sqlTmp);
                $useXyResArr = array();
                foreach ($useXyRes as $val) {
                    array_push($useXyResArr, $val->c_addr_id);
                }
                //從這邊對原本的row進行過濾
                $row->whereIn('ENTRY_DATA.c_entry_addr_id', $useXyResArr); 
            }
        }
        //資料庫邏輯結束

        $row = $row->get();
        //return $row;
        $total = count($row);

        if($list) {
            $row = $row->slice($start, $list);
        }
        //dd($row);
        //組合輸出資料
        foreach ($row as $val) {
            $BiogMain = BiogMain::where('c_personid', '=', $val->c_personid)->first();
            $data_val['PersonID'] = $val->c_personid;
            $data_val['Name'] = $BiogMain->c_name;
            $data_val['NameChn'] = $BiogMain->c_name_chn;
            $data_val['Sex'] = $BiogMain->c_female ? '1-女' : '0-男';
            $data_val['IndexYear'] = $BiogMain->c_index_year;
            //入仕途徑
            $EntryCode = EntryCode::where('c_entry_code', '=', $val->c_entry_code)->first();
            $data_val['EntryDesc'] = $EntryCode->c_entry_desc;
            $data_val['EntryChn'] = $EntryCode->c_entry_desc_chn;
            $data_val['EntryYear'] = $val->c_year;
            $data_val['EntryRank'] = $val->c_exam_rank;
            //親屬關係
            $KinshipCode = KinshipCode::where('c_kincode', '=', $val->c_kin_code)->first();
            $data_val['KinType'] = $KinshipCode->c_kinrel_chn;
            $BiogMainKin = BiogMain::where('c_personid', '=', $val->c_kin_id)->first();
            if(!$BiogMainKin) {
                $BiogMainKin = BiogMain::where('c_personid', '=', 0)->first();
            }
            $data_val['KinName'] = $BiogMainKin->c_name;
            $data_val['KinChn'] = $BiogMainKin->c_name_chn;
            $AssocCode = AssocCode::where('c_assoc_code', '=', $val->c_assoc_code)->first();
            $data_val['Association'] = $AssocCode->c_assoc_desc_chn;
            $BiogMainAssoc = BiogMain::where('c_personid', '=', $val->c_assoc_id)->first();
            $data_val['AssocName'] = $BiogMainAssoc->c_name;
            $data_val['AssocChn'] = $BiogMainAssoc->c_name_chn;

            //這裡是查詢人物的[地址]BIOG_ADDR_DATA
            //20200522修改人物的[地址]查詢依據
            $c_addr_type = $c_addr_id = 0;
            $BiogAddr = BiogAddr::where('c_personid', '=', $val->c_personid)->whereIn('c_addr_type', [1, 16, 6, 4, 2, 13, 14, 17])->first(); 
            if(!$BiogAddr) {
                $BiogAddr = BiogAddr::where('c_personid', '=', $val->c_personid)->first();
            }
            //20200522修改結束
            if($BiogAddr) { 
                $c_addr_type = $BiogAddr->c_addr_type;
                $c_addr_id = $BiogAddr->c_addr_id;
            }
            $data_val['AddrID'] = $c_addr_id;
            $AddrCode = AddrCode::where('c_addr_id', '=', $c_addr_id)->first();
            $data_val['AddrName'] = $AddrCode->c_name;
            $data_val['AddrChn'] = $AddrCode->c_name_chn;
            $data_val['X'] = $AddrCode->x_coord;
            $data_val['Y'] = $AddrCode->y_coord;
            $BIOG_ADDR_DATA = DB::table('BIOG_ADDR_DATA')->where('c_addr_id', '=', $c_addr_id)->count('c_personid');
            $data_val['xy_count'] = $BIOG_ADDR_DATA; // 同一人物地點的人物數
            if($val->c_parental_status != null) {
                $PARENTAL_STATUS_CODES = DB::table('PARENTAL_STATUS_CODES')->where('c_parental_status_code', '=', $val->c_parental_status)->first();
                $data_val['ParentState'] = $PARENTAL_STATUS_CODES->c_parental_status_desc;
                $data_val['ParentStateChn'] = $PARENTAL_STATUS_CODES->c_parental_status_desc_chn;
            }
            else {
                $data_val['ParentState'] = '';
                $data_val['ParentStateChn'] = '';
            } 
            if($val->c_entry_addr_id != null) {
                $EntryAddrCode = AddrCode::where('c_addr_id', '=', $val->c_entry_addr_id)->first();
                $data_val['EntryPlace'] = $EntryAddrCode->c_name;
                $data_val['EntryPlaceChn'] = $EntryAddrCode->c_name_chn;
                $data_val['EntryX'] = $EntryAddrCode->x_coord;
                $data_val['EntryY'] = $EntryAddrCode->y_coord;
                $entry_xy_count = DB::table('ENTRY_DATA')->where('c_entry_addr_id', '=', $val->c_entry_addr_id)->selectRaw('c_personid, count(c_personid)')->groupBy('c_personid')->count('c_personid');
                $data_val['entry_xy_count'] = $entry_xy_count;
            }
            else { 
                $data_val['EntryPlace'] = '';
                $data_val['EntryPlaceChn'] = ''; 
                $data_val['EntryX'] = '';
                $data_val['EntryY'] = '';
                $data_val['entry_xy_count'] = '';
            }
            if($val->c_dy != null) {
                $c_dy = Dynasty::where('c_dy', '=', $val->c_dy)->first();
                $data_val['dynasty'] = $c_dy->c_dynasty;
                $data_val['dynastyChn'] = $c_dy->c_dynasty_chn;
            }
            else {
                $data_val['dynasty'] = '';
                $data_val['dynastyChn'] = '';
            }

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
