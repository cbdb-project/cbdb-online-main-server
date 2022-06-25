<?php
/**
 * User: ja
 * Date: 2022/3/25
 * Time: 09:00
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

class ApiController6 extends Controller
{
    use AuthenticatesUsers;

    public function __construct()
    {
        $this->middleware('guest');
    }

    //20220325製作[通過地區查詢]query_place API
    protected function query_place(Request $request) {
        $json = $request['RequestPlayload'];
        $arr = json_decode($json, true);
        //dd($arr); //驗證資料傳遞的正確性
        $aName = $start = $list = $total = 0;
        $data = $useXyArr = $c_addr_id_Arr = $a_addr_id_Arr = $all_row = array();

        $peoplePlace = $arr['peoplePlace'];
        $placeType = $arr['placeType'];
        $useDate = $arr['useDate'];
        $dateType = $arr['dateType'];
        $dateStartTime = $arr['dateStartTime'];
        $dateEndTime = $arr['dateEndTime'];
        $dynStart = $arr['dynStart'];
        $dynEnd = $arr['dynEnd'];
        $useXy = $arr['useXy'];
        $XY = '0.03';

        if(!empty($arr['start'])) { 
            if($arr['start'] <= 0) { $start = 0; } // 避免start為負數
            else { $start = $arr['start'] - 1; } // return輸出由1開始, 程式需由0開始.
        }
        else { $start = 0; }

        if(!empty($arr['list'])) {
            $list = $arr['list'];
        }
        else { $list = 10000; }

        //主要資料庫邏輯
        
        if(in_array('individual', $placeType)) {
            $row = DB::table('BIOG_MAIN')->whereIn('BIOG_MAIN.c_index_addr_id', $peoplePlace);
            $row->join('ADDR_CODES', 'ADDR_CODES.c_addr_id', '=', 'BIOG_MAIN.c_index_addr_id');
            $row = $this->useDate($row, $useDate, $dateType, $dateStartTime, $dateEndTime, $dynStart, $dynEnd);
            $row = $this->useXy($row, $useXy, $XY);
            $row = $row->get();
            foreach($row as $v) {
                $v->placeType = 'individual';
                array_push($all_row, $v);
            }
        }
        if(in_array('entry', $placeType)) {
            $row = DB::table('ENTRY_DATA')->whereIn('ENTRY_DATA.c_entry_addr_id', $peoplePlace);
            $row->join('BIOG_MAIN', 'BIOG_MAIN.c_personid', '=', 'ENTRY_DATA.c_personid');
            $row->join('ADDR_CODES', 'ADDR_CODES.c_addr_id', '=', 'BIOG_MAIN.c_index_addr_id');
            $row = $this->useDate($row, $useDate, $dateType, $dateStartTime, $dateEndTime, $dynStart, $dynEnd);
            $row = $this->useXy($row, $useXy, $XY);
            $row = $row->get();
            foreach($row as $v) {
                $v->placeType = 'entry';
                array_push($all_row, $v);
            }
        }
        if(in_array('association', $placeType)) {
            $row = DB::table('ASSOC_DATA')->whereIn('ASSOC_DATA.c_addr_id', $peoplePlace);
            $row->join('BIOG_MAIN', 'BIOG_MAIN.c_personid', '=', 'ASSOC_DATA.c_personid');
            $row->join('ADDR_CODES', 'ADDR_CODES.c_addr_id', '=', 'ASSOC_DATA.c_addr_id');
            $row = $this->useDate($row, $useDate, $dateType, $dateStartTime, $dateEndTime, $dynStart, $dynEnd);
            $row = $this->useXy($row, $useXy, $XY);
            $row = $row->get();
            foreach($row as $v) {
                $v->placeType = 'association';
                array_push($all_row, $v);
            }
        }
        if(in_array('officePosting', $placeType)) {
            $row = DB::table('POSTED_TO_ADDR_DATA')->whereIn('POSTED_TO_ADDR_DATA.c_addr_id', $peoplePlace);
            $row->join('BIOG_MAIN', 'BIOG_MAIN.c_personid', '=', 'POSTED_TO_ADDR_DATA.c_personid');
            $row->join('ADDR_CODES', 'ADDR_CODES.c_addr_id', '=', 'POSTED_TO_ADDR_DATA.c_addr_id');
            $row = $this->useDate($row, $useDate, $dateType, $dateStartTime, $dateEndTime, $dynStart, $dynEnd);
            $row = $this->useXy($row, $useXy, $XY);
            $row = $row->get();
            foreach($row as $v) {
                $v->placeType = 'officePosting';
                array_push($all_row, $v);
            }
        }
        if(in_array('institutional', $placeType)) {
            //20220413需要索取SOCIAL_INSTITUTION_ADDR資料表的測試資料
            $row = DB::table('SOCIAL_INSTITUTION_ADDR')->whereIn('SOCIAL_INSTITUTION_ADDR.c_inst_addr_id', $peoplePlace);
            $row->join('BIOG_INST_DATA', 'BIOG_INST_DATA.c_inst_code', '=', 'SOCIAL_INSTITUTION_ADDR.c_inst_code');
            $row->join('BIOG_MAIN', 'BIOG_MAIN.c_personid', '=', 'BIOG_INST_DATA.c_personid');
            $row->join('ADDR_CODES', 'ADDR_CODES.c_addr_id', '=', 'SOCIAL_INSTITUTION_ADDR.c_inst_addr_id');
            $row = $this->useDate($row, $useDate, $dateType, $dateStartTime, $dateEndTime, $dynStart, $dynEnd);
            $row = $this->useXy($row, $useXy, $XY);
            $row = $row->get();
            foreach($row as $v) {
                $v->placeType = 'institutional';
                array_push($all_row, $v);
            }
        }
        if(in_array('kinship', $placeType)) {
            $row = DB::table('BIOG_MAIN')->whereIn('BIOG_MAIN.c_index_addr_id', $peoplePlace);
            $row->join('KIN_DATA', 'KIN_DATA.c_kin_id', '=', 'BIOG_MAIN.c_personid');
            $row->join('KINSHIP_CODES', 'KINSHIP_CODES.c_kincode', '=', 'KIN_DATA.c_kin_code');
            $row->join('ADDR_CODES', 'ADDR_CODES.c_addr_id', '=', 'BIOG_MAIN.c_index_addr_id');
            $row = $this->useDate($row, $useDate, $dateType, $dateStartTime, $dateEndTime, $dynStart, $dynEnd);
            $row = $this->useXy($row, $useXy, $XY);
            $row = $row->get();
            foreach($row as $v) {
                $v->placeType = 'kinship';
                array_push($all_row, $v);
            }
        }
        if(in_array('associate', $placeType)) {
            $row = DB::table('BIOG_ADDR_DATA')->whereIn('BIOG_ADDR_DATA.c_addr_id', $peoplePlace);
            $row->join('BIOG_MAIN', 'BIOG_MAIN.c_personid', '=', 'BIOG_ADDR_DATA.c_personid');
            $row->join('ADDR_CODES', 'ADDR_CODES.c_addr_id', '=', 'BIOG_MAIN.c_index_addr_id');
            $row->join('ASSOC_DATA', 'ASSOC_DATA.c_assoc_id', '=', 'BIOG_ADDR_DATA.c_personid');
            $row->join('ASSOC_CODES', 'ASSOC_CODES.c_assoc_code', '=', 'ASSOC_DATA.c_assoc_code');
            $row = $this->useDate($row, $useDate, $dateType, $dateStartTime, $dateEndTime, $dynStart, $dynEnd);
            $row = $this->useXy($row, $useXy, $XY);
            $row = $row->get();
            foreach($row as $v) {
                $v->placeType = 'associate';
                array_push($all_row, $v);
            }
        }

        //資料庫邏輯結束
        $row = $all_row;
        if(!empty($arr['DEBUG']) && $arr['DEBUG'] == 1) { return $row; }
        $total = count($row);

        //組合輸出資料
        $record_list = 0;
        foreach ($row as $val) {

            $record_list++;
            if($record_list < $start + 1) { continue; }
            if($record_list > $list) { break; } 

            $BiogMain = BiogMain::where('c_personid', '=', $val->c_personid)->first();
            $data_val['PersonID'] = $val->c_personid;
            $data_val['Name'] = $BiogMain->c_name;
            $data_val['NameChn'] = $BiogMain->c_name_chn;
            $data_val['Sex'] = $BiogMain->c_female ? '1-女' : '0-男';
            $data_val['IndexYear'] = $BiogMain->c_index_year;
            //用c_index_year_type_code查INDEXYEAR_TYPE_CODES的c_index_year_type_code
            $IndexYear = DB::table('INDEXYEAR_TYPE_CODES')->where([['c_index_year_type_code', '=', $val->c_index_year_type_code]])->first();
            if(!empty($IndexYear)) {
                $data_val['IndexYearType'] = $IndexYear->c_index_year_type_desc;
                $data_val['IndexYearTypeChn'] = $IndexYear->c_index_year_type_hz;
                $data_val['IndexYearCode'] = $IndexYear->c_index_year_type_code;
            }
            else {
                $data_val['IndexYearType'] = '';
                $data_val['IndexYearTypeChn'] = '';
                $data_val['IndexYearCode'] = '';
            }
            //用c_index_addr_id查ADDR_CODES的c_addr_id
            if(!empty($val->c_index_addr_id)) {
                $AddrCode = AddrCode::where('c_addr_id', '=', $val->c_index_addr_id)->first();
            }
            if(!empty($AddrCode)) {
                $data_val['PlaceName'] = $AddrCode->c_name;
                $data_val['PlaceNameChn'] = $AddrCode->c_name_chn;
            }
            else {
                $data_val['PlaceName'] = '';
                $data_val['PlaceNameChn'] = '';
            }

            if(!empty($val->c_assoc_id)) {
                $c_assoc_name = BiogMain::where('c_personid', '=', $val->c_assoc_id)->first();
            }
            if(!empty($c_assoc_name)) {
                $data_val['PlaceAssocName'] = $c_assoc_name->c_name;
                $data_val['PlaceAssocChn'] =  $c_assoc_name->c_name_chn;
            }
            else {
                $data_val['PlaceAssocName'] = '';
                $data_val['PlaceAssocChn'] = '';
            }
            //BIOG_MAIN 無法提供的資訊，譬如 data[i].AssocName 輸出空值即可
            if(!empty($val->c_year)) {
                $data_val['PlaceAssoStart'] = $val->c_year;
                $data_val['PlaceAssoEnd'] = '';
            }
            //這裡可以視需求擴充
            elseif(!empty($val->c_firstyear) || !empty($val->c_lastyear)) {
                $data_val['PlaceAssoStart'] = $val->c_firstyear;
                $data_val['PlaceAssoEnd'] = $val->c_lastyear;
            }
            else {
                $data_val['PlaceAssoStart'] = '';
                $data_val['PlaceAssoEnd'] = '';
            }
            //c_index_addr_type_code查BIOG_ADDR_CODES的c_addr_type
            if(!empty($val->c_index_addr_type_code)) {
                $BiogAddrCode = BiogAddrCode::where('c_addr_type', '=', $val->c_index_addr_type_code)->first();
            }
            if(!empty($BiogAddrCode)) {
                $data_val['PlaceType'] = $BiogAddrCode->c_addr_type;
                $data_val['PlaceTypeDetail'] = $BiogAddrCode->c_addr_desc;
                $data_val['PlaceTypeDetailChn'] = $BiogAddrCode->c_addr_desc_chn;
            }
            else {
                $data_val['PlaceType'] = '';
                $data_val['PlaceTypeDetail'] = '';
                $data_val['PlaceTypeDetailChn'] = '';
            }

            $data_val['X'] = $val->x_coord;
            $data_val['Y'] = $val->y_coord;

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

    protected function useDate($row, $useDate, $dateType, $dateStartTime, $dateEndTime, $dynStart, $dynEnd) {
        //useDate過濾條件
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
        return $row;
    }


    protected function useXy($row, $useXy, $XY) {
        //useXy過濾條件
        $useXyArr = array();
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

                //return $sqlTmp; //驗證$useXy可以查找到資料並進行過濾
                $useXyRes = DB::select($sqlTmp);
                $useXyResArr = array();
                foreach ($useXyRes as $val) {
                    array_push($useXyResArr, $val->c_addr_id);
                }
                /*20220503修改程式邏輯，
                 *先使用查詢地址的 id 獲得經緯度座標資訊，然後用獲得的經緯度座標正負 0.03 找到更多地址 ID，
                 *之後用這些地址 ID 來作為地址檢索條件（每個地址 ID 之間是 OR 關係）。
                 *useXY 的條件限定會比不使用 useXY 獲得的資訊更多。
                 */
                $row->orWhereIn('BIOG_MAIN.c_index_addr_id', $useXyResArr);
                //dd($useXyResArr); //檢查$useXyResArr驗證有效
            }
        }
        return $row;
    }


}
