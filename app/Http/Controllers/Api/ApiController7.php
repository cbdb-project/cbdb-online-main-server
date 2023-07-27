<?php
/**
 * User: ja
 * Date: 2023/7/25
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

class ApiController7 extends Controller
{
    use AuthenticatesUsers;

    public function __construct()
    {
        $this->middleware('guest');
    }

    //20230725製作[查詢社會關係網路]query_assoc_network API
    protected function query_assoc_network(Request $request) {
        $json = $request['RequestPayload'];
        $arr = json_decode($json, true);
        //dd($arr); //驗證資料傳遞的正確性
        $start = $list = $total = 0;
        $data = $useXyArr = array();

        $people = $arr['people'];  //陣列
        $assocCode = $arr['assocCode'];  //陣列
        $assocType = $arr['assocType'];  //陣列
        $maxNodeDist = $arr['maxNodeDist'] ?? 1;  //數字默認為1
        $place = $arr['place'];  //陣列
        $usePeoplePlace = $arr['usePeoplePlace'] ?? 0;  //數字
        $useXy = $arr['useXy'] ?? 0;  //數字
        $broad = $arr['broad'] ?? 0;  //數字
        $indexYear = $arr['indexYear'] ?? 0;  //數字
        $indexStartTime = $arr['indexStartTime'] ?? 0;  //數字
        $indexEndTime = $arr['indexEndTime'] ?? 0;  //數字
        $useDy = $arr['useDy'] ?? 0;  //數字
        $dynStart = $arr['dynStart'] ?? 0;  //數字
        $dynEnd = $arr['dynEnd'] ?? 0;  //數字
        $includeMale = $arr['includeMale'] ?? 1;  //數字默認為1
        $includeFemale = $arr['includeFemale'] ?? 1;  //數字默認為1
        if($broad == 1) {
            $XY = '0.03';
        }
        else {
            $XY = '0.06';
        }
        //dd($includeMale);

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

        $first_row = DB::table('ASSOC_TYPES')->whereIn('ASSOC_TYPES.c_assoc_type_parent_id', $assocType)->get();
        foreach ($first_row as $val) {
            $assocType_row[] = $val->c_assoc_type_id;
        }
        //dd($assocType_row);

        $second_row = DB::table('ASSOC_CODE_TYPE_REL')->whereIn('ASSOC_CODE_TYPE_REL.c_assoc_type_id', $assocType_row)->get();
        foreach ($second_row as $val) {
            $c_assoc_code_row[] = $val->c_assoc_code;
        }
        //dd($c_assoc_code_row);

        if($maxNodeDist == 0) {
            $row_b = DB::table('ASSOC_DATA')->whereIn('ASSOC_DATA.c_personid', $people);
            $row_b->join('BIOG_MAIN', 'ASSOC_DATA.c_personid', '=', 'BIOG_MAIN.c_personid');
            $row_b = $row_b->get();
            foreach($row_b as $v) {
               $people[] = $v->c_assoc_id;
            }
            $row = DB::table('ASSOC_DATA')->whereIn('ASSOC_DATA.c_personid', $people);
            $row->join('BIOG_MAIN', 'ASSOC_DATA.c_personid', '=', 'BIOG_MAIN.c_personid');
        }
        elseif($maxNodeDist == 1) {
            foreach($c_assoc_code_row as $v) {
                $assocCode[] = $v;
            }
            $row = DB::table('ASSOC_DATA')->whereIn('ASSOC_DATA.c_personid', $people);
            $row->join('BIOG_MAIN', 'ASSOC_DATA.c_personid', '=', 'BIOG_MAIN.c_personid');
            $row->whereIn('ASSOC_DATA.c_assoc_code', $assocCode);
        }
        elseif($maxNodeDist == 2) {
            foreach($c_assoc_code_row as $v) {
                $assocCode[] = $v;
            }
            $row_b = DB::table('ASSOC_DATA')->whereIn('ASSOC_DATA.c_personid', $people);
            $row_b->join('BIOG_MAIN', 'ASSOC_DATA.c_personid', '=', 'BIOG_MAIN.c_personid');
            $row_b->whereIn('ASSOC_DATA.c_assoc_code', $assocCode);
            $row_b = $row_b->get();
            foreach($row_b as $v) {
               $people[] = $v->c_assoc_id; 
            }
            //dd($people);
            //累加第一輪的people
            //將 ASSOC_DATA.c_assoc_id 作為 ASSOC_DATA.personid, 再進行一次 maxNodeDist = 1 查詢。
            $row = DB::table('ASSOC_DATA')->whereIn('ASSOC_DATA.c_personid', $people);
            $row->join('BIOG_MAIN', 'ASSOC_DATA.c_personid', '=', 'BIOG_MAIN.c_personid');
            $row->whereIn('ASSOC_DATA.c_assoc_code', $assocCode);
        }
        else {
            return 'API 暫不支援 maxNodeDist 大於 2 之查詢';
        }

        $row->join('BIOG_ADDR_DATA', 'BIOG_ADDR_DATA.c_personid', '=', 'ASSOC_DATA.c_personid');
        if($usePeoplePlace) {
            $row->whereIn('BIOG_ADDR_DATA.c_addr_id', $place);
        }

        $row = $this->useXy($row, $useXy, $XY);
        $row = $this->useDate($row, $indexYear, $indexStartTime, $indexEndTime, $useDy, $dynStart, $dynEnd);
        
        if($includeMale == 0) {
            $row->where('BIOG_MAIN.c_female', '!=', 0);
        }

        if($includeFemale == 0) {
            $row->where('BIOG_MAIN.c_female', '!=', 1);
        }

        $row = $row->get();

        //資料庫邏輯結束
        if(!empty($arr['DEBUG']) && $arr['DEBUG'] == 1) { return $row; }

        $total = count($row);

        if($list) {
            $row = $row->slice($start, $list);
        }

        //return $row;

        //組合輸出資料
        $record_list = 0;
        foreach ($row as $val) {

            $record_list++;
            if($record_list < $start + 1) { continue; }
            if($record_list > $list) { break; } 

            $BiogMain = BiogMain::where('c_personid', '=', $val->c_personid)->first();
            $data_val['pId'] = $val->c_personid;
            $data_val['pName'] = $BiogMain->c_name;
            $data_val['pNameChn'] = $BiogMain->c_name_chn;
            $AssocBiogMain = BiogMain::where('c_personid', '=', $val->c_assoc_id)->first();
            $data_val['aId'] = $val->c_assoc_id;
            $data_val['aName'] = $AssocBiogMain->c_name;
            $data_val['aNameChn'] = $AssocBiogMain->c_name_chn;
            $data_val['pIndexYear'] = $BiogMain->c_index_year;
            $data_val['pSex'] = $BiogMain->c_female ? 'F' : 'M';
            $data_val['aIndexYear'] = $AssocBiogMain->c_index_year;
            $data_val['aSex'] = $AssocBiogMain->c_female ? 'F' : 'M';
            //用c_index_addr_id查ADDR_CODES的c_addr_id
            if(!empty($val->c_index_addr_id)) {
                $AddrCode = AddrCode::where('c_addr_id', '=', $val->c_index_addr_id)->first();
            }
            if(!empty($AddrCode)) {
                $data_val['pAddrID'] = $val->c_index_addr_id;
                $data_val['pAddrName'] = $AddrCode->c_name;
                $data_val['pAddrNameChn'] = $AddrCode->c_name_chn;
                $data_val['pX'] = $AddrCode->x_coord;
                $data_val['pY'] = $AddrCode->y_coord;
            }
            else {
                $data_val['pAddrID'] = '';
                $data_val['pAddrName'] = '';
                $data_val['pAddrNameChn'] = '';
                $data_val['pX'] = '';
                $data_val['pY'] = '';
            }
            //用$AssocBiogMain的c_index_addr_id查ADDR_CODES的c_addr_id
            if(!empty($AssocBiogMain->c_index_addr_id)) {
                $AddrCode2 = AddrCode::where('c_addr_id', '=', $AssocBiogMain->c_index_addr_id)->first();
            }
            if(!empty($AddrCode2)) {
                $data_val['aAddrID'] = $AssocBiogMain->c_index_addr_id;
                $data_val['aAddrName'] = $AddrCode2->c_name;
                $data_val['aAddrNameChn'] = $AddrCode2->c_name_chn;
                $data_val['aX'] = $AddrCode2->x_coord;
                $data_val['ay'] = $AddrCode2->y_coord;
            }
            else {
                $data_val['aAddrID'] = '';
                $data_val['aAddrName'] = '';
                $data_val['aAddrNameChn'] = '';
                $data_val['aX'] = '';
                $data_val['aY'] = '';
            }

            $data_val['pAssocRelationId'] = $val->c_assoc_code;
            if(!empty($val->c_assoc_code)) {
                $AssocCode = AssocCode::where('c_assoc_code', '=', $val->c_assoc_code)->first();
            }
            if(!empty($AssocCode)) {
                $data_val['pAssocRelation'] = $AssocCode->c_assoc_desc;
                $data_val['pAssocRelationChn'] = $AssocCode->c_assoc_desc_chn;
            }
            else {
                $data_val['pAssocRelation'] = '';
                $data_val['pAssocRelationChn'] = '';
            }

            $data_val['distance'] = $this->getdizhi($AddrCode->x_coord, $AddrCode->y_coord, $AddrCode2->x_coord, $AddrCode2->y_coord);
            $data_val['count'] = $val->c_assoc_count;

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


    protected function useDate($row, $indexYear, $indexStartTime, $indexEndTime, $useDy, $dynStart, $dynEnd) {

        if($indexYear) {
            $row->where('BIOG_MAIN.c_index_year', '>=', $indexStartTime);
            $row->where('BIOG_MAIN.c_index_year', '<=', $indexEndTime);
        }

        if($useDy) {
            $row->join('DYNASTIES', 'BIOG_MAIN.c_dy', '=', 'DYNASTIES.c_dy');
            $row->where('DYNASTIES.c_dy', '>=', $dynStart);
            $row->where('DYNASTIES.c_dy', '<=', $dynEnd);
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


    protected function getdizhi($longitude1, $latitude1, $longitude2, $latitude2, $unit=2, $decimal=2){
        /*
        * 計算兩點地理座標之間的距離
        * @param  Decimal $longitude1 起點經度
        * @param  Decimal $latitude1  起點緯度
        * @param  Decimal $longitude2 終點經度
        * @param  Decimal $latitude2  終點緯度
        * @param  Int     $unit       單位 1:米 2:公里
        * @param  Int     $decimal    精度 保留小數位數
        * @return Decimal
        */

        $EARTH_RADIUS = 6370.996; // 地球半徑係數
        $PI = 3.1415926;

        $radLat1 = $latitude1 * $PI / 180.0;
        $radLat2 = $latitude2 * $PI / 180.0;

        $radLng1 = $longitude1 * $PI / 180.0;
        $radLng2 = $longitude2 * $PI /180.0;

        $a = $radLat1 - $radLat2;
        $b = $radLng1 - $radLng2;

        $distance = 2 * asin(sqrt(pow(sin($a/2),2) + cos($radLat1) * cos($radLat2) * pow(sin($b/2),2)));
        $distance = $distance * $EARTH_RADIUS * 1000;

        if($unit==2){
            $distance = $distance / 1000;
        }
        return round($distance, $decimal);
    }


}
