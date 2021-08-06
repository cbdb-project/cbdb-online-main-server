<?php
/**
 * User: ja
 * Date: 2020/5/6
 * Time: 09:20
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\BiogMain;
use App\BiogAddr;
use App\BiogAddrCode;
use App\AddrCode;
use App\OfficeCode;
use App\Operation;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

ini_set('memory_limit','512M');
ini_set('max_execution_time', 300);

class ApiController2 extends Controller
{
    use AuthenticatesUsers;

    public function __construct()
    {
        $this->middleware('guest');
    }

    //20200506依據指定規格製作query_office_postings API
    protected function query_office_postings(Request $request) {
        $json = $request['RequestPlayload'];
        $arr = json_decode($json, true);
        $office = $officePlace = $peoplePlace = $data = $useXyArr = array();
        $useOfficePlace = $usePeoplePlace = $indexYear = $useDate = $indexStartTime = $indexEndTime = $dateType = $dynStart = $dynEnd = $useXy = $start = $list = 0;
        
        $office = $arr['office'];
        $officePlace = $arr['officePlace'];
        $peoplePlace = $arr['peoplePlace'];
        $useOfficePlace = $arr['useOfficePlace'];
        $usePeoplePlace = $arr['usePeoplePlace']; 
        //$indexYear = $arr['indexYear']; 
        $useDate = $arr['useDate'];
        $dateType = $arr['dateType'];
        $dynStart = $arr['dynStart'];
        $dynEnd = $arr['dynEnd'];
        $indexStartTime = $arr['indexStartTime']; 
        $indexEndTime = $arr['indexEndTime']; 
        $useXy = $arr['useXy']; 
        if($arr['start'] <= 0) { $start = 0; } // 避免start為負數
        else { $start = $arr['start'] - 1; } // return輸出由1開始, 程式需由0開始.
        $list = $arr['list'];
        
        $row = DB::table('POSTED_TO_OFFICE_DATA')->whereIn('POSTED_TO_OFFICE_DATA.c_office_id', $office);
        $row->join('POSTED_TO_ADDR_DATA', 'POSTED_TO_OFFICE_DATA.c_posting_id', '=', 'POSTED_TO_ADDR_DATA.c_posting_id');
        $row->join('BIOG_MAIN', 'POSTED_TO_OFFICE_DATA.c_personid', '=', 'BIOG_MAIN.c_personid');

        if($useOfficePlace) {
            $row->whereIn('c_addr_id', $officePlace);
        }
        if($usePeoplePlace) {
            //人物地點BIOG_ADDR_DATA，地名資料ADDR_CODES
            $row->join('BIOG_ADDR_DATA', 'POSTED_TO_ADDR_DATA.c_personid', '=', 'BIOG_ADDR_DATA.c_personid');
            $row->whereIn('BIOG_ADDR_DATA.c_addr_id', $peoplePlace);
        }
        /*
        if($indexYear) {
            $row->join('BIOG_MAIN', 'POSTED_TO_OFFICE_DATA.c_personid', '=', 'BIOG_MAIN.c_personid');
            $row->whereBetween('BIOG_MAIN.c_index_year', array($indexStartTime, $indexEndTime));
        }
        */
        if($useDate) {
            if($dateType == 'index') {
                $row->where('BIOG_MAIN.c_index_year', '>=', $dateStartTime);
                $row->where('BIOG_MAIN.c_index_year', '<=', $dateEndTime);
            }
            elseif($dateType == 'dynasty') {
                $row->join('DYNASTIES', 'BIOG_MAIN.c_dy', '=', 'DYNASTIES.c_dy');
                $row->where('DYNASTIES.c_dy', '>=', $dynStart);
                $row->where('DYNASTIES.c_dy', '<=', $dynEnd);
            }
            else {}
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
WHERE (((ADDR_CODES.x_coord)>=(ADDR_CODES_1.x_coord-0.03) And (ADDR_CODES.x_coord)<=(ADDR_CODES_1.x_coord+0.03)) AND ((ADDR_CODES.y_coord)>=(ADDR_CODES_1.y_coord-0.03) And (ADDR_CODES.y_coord)<=(ADDR_CODES_1.y_coord+0.03)))
');
                $useXyRes = DB::select($sqlTmp);
                $useXyResArr = array();
                foreach ($useXyRes as $val) {
                    array_push($useXyResArr, $val->c_addr_id);
                }
                //從這邊對原本的row進行過濾
                $row->whereIn('POSTED_TO_ADDR_DATA.c_addr_id', $useXyResArr);
            }
        }

        $row = $row->get();
        $total = count($row);

        if($list) {
            $row = $row->slice($start, $list);
        }
        //return $row;

        foreach ($row as $val) {
            $BiogMain = $BiogAddr = $BiogAddrCode = $AddrCode = $AddrCode_office = $office = $POSTED_TO_ADDR_DATA = $c_addr_type = $c_addr_id = 0;
            $c_appt_type_code = 0;
            $BiogMain = BiogMain::where('c_personid', '=', $val->c_personid)->first();
            $data_val['PersonID'] = $val->c_personid;
            $data_val['Name'] = $BiogMain->c_name;
            $data_val['NameChn'] = $BiogMain->c_name_chn;
            $data_val['Sex'] = $BiogMain->c_female ? '1-女' : '0-男';
            $data_val['IndexYear'] = $BiogMain->c_index_year;
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
            //這裡是將查詢到的人物[地址]，取得[狀態]中英文名稱。
            $BiogAddrCode = BiogAddrCode::where('c_addr_type', '=', $c_addr_type)->first();
            $data_val['AddrType'] = $BiogAddrCode->c_addr_desc;
            $data_val['AddrTypeChn'] = $BiogAddrCode->c_addr_desc_chn;
            $AddrCode = AddrCode::where('c_addr_id', '=', $c_addr_id)->first();
            $data_val['AddrName'] = $AddrCode->c_name;
            $data_val['AddrChn'] = $AddrCode->c_name_chn;
            $data_val['X'] = $AddrCode->x_coord;
            $data_val['Y'] = $AddrCode->y_coord;

            //這裡是將查詢到的[官職][官職地址]，取得相關中英文名稱。
            $office = OfficeCode::where('c_office_id', '=', $val->c_office_id)->first();
            $AddrCode_office = AddrCode::where('c_addr_id', '=', $val->c_addr_id)->first();
            $data_val['OfficeCode'] = $val->c_office_id; //數字 官職ID
            $data_val['OfficeName'] = $office->c_office_pinyin; // 字符串 官職名，英文
            $data_val['OfficeNameChn'] = $office->c_office_chn; // 字符串 官職名，中文
            $data_val['FirstYear'] = $val->c_firstyear; // 數字 任官開始年
            $data_val['LastYear'] = $val->c_lastyear; // 數字 任官結束年
            //這裡取得朝代的中文名稱
            $dy = DB::table('DYNASTIES')->where('c_dy', '=', $val->c_dy)->first();
            $data_val['Dynasty'] = $dy->c_dynasty_chn; // 字符串 朝代
            $data_val['OfficeAddrID'] = $val->c_addr_id; // 數字 官職地點ID
            $data_val['OfficeAddrName']	= $AddrCode_office->c_name; // 字符串 官職地點名，英文
            $data_val['OfficeAddrChn'] = $AddrCode_office->c_name_chn; // 字符串 官職地點名，中文
            $data_val['OfficeX'] = $AddrCode_office->x_coord; // 數字 官職地點經度座標
            $data_val['OfficeY'] = $AddrCode_office->y_coord; // 數字 官職地點緯度座標
            if($val->c_addr_id != 0) {
                $POSTED_TO_ADDR_DATA = DB::table('POSTED_TO_ADDR_DATA')->where('c_addr_id', '=', $c_addr_id)->count('c_personid');
            }
            $data_val['office_xy_count'] = $POSTED_TO_ADDR_DATA; // 數字 職官地址數
            $data_val['PostingID'] = $val->c_appt_type_code; // 數字 除授記錄
            if($val->c_appt_type_code != null) {
                $c_appt_type_code = DB::table('APPOINTMENT_TYPE_CODES')->where('c_appt_type_code', '=', $val->c_appt_type_code)->first();
                $c_appt_type_desc = $c_appt_type_code->c_appt_type_desc;
                $c_appt_type_desc_chn = $c_appt_type_code->c_appt_type_desc_chn;
            }
            else { $c_appt_type_desc = $c_appt_type_desc_chn = ''; }
            $data_val['ApptType'] = $c_appt_type_desc; // 字符串 除授類型，英文
            $data_val['ApptTypeChn'] = $c_appt_type_desc_chn; // 字符串 除授類型，中文
            if($val->c_assume_office_code != null && $val->c_assume_office_code != 0) {
                $c_assume_office_code = DB::table('ASSUME_OFFICE_CODES')->where('c_assume_office_code', '=', $val->c_assume_office_code)->first();
                $c_assume_office_desc = $c_assume_office_code->c_assume_office_desc;
                $c_assume_office_desc_chn = $c_assume_office_code->c_assume_office_desc_chn;
            }
            else { $c_assume_office_desc = $c_assume_office_desc_chn = ''; }
            $data_val['AssumptionOffice'] = $c_assume_office_desc; // 字符串 赴任情況，英文
            $data_val['AssumptionOfficeChn'] = $c_assume_office_desc_chn; //字符串 赴任情況，中文
            $data_val['Notes'] = $val->c_notes; //字符串 備註

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
