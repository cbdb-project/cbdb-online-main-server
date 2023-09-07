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
use App\Dynasty;
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
        $user_input_people = $people;//clone the original input people
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
            $row = DB::table('ASSOC_DATA')->whereIn('ASSOC_DATA.c_personid', $user_input_people);
            $row->join('BIOG_MAIN', 'ASSOC_DATA.c_personid', '=', 'BIOG_MAIN.c_personid');
            $row->whereIn('ASSOC_DATA.c_assoc_id', $user_input_people);
           
        }
        elseif($maxNodeDist == 1) {
            foreach($c_assoc_code_row as $v) {
                $assocCode[] = $v;
            }
            //ASSOC_DATA.personid 符合 $user_input_people 的 ASSOC_DATA.c_assoc_id array
            $assoc_people = $this->get_assoc_people($user_input_people, $assocCode);
            //將$user_input_people、第一輪找出的 ASSOC_DATA.c_assoc_id 合併去重
            $sum_people = array_merge($user_input_people, $assoc_people);
            $sum_people = array_unique($sum_people);
            //限定 ASSOC_DATA.personid 和 ASSOC_DATA.c_assoc_id 必需是 $sum_people 中的人物，並與 BIOG_MAIN 做 join
            $row = $this->get_related_edges($sum_people, $assocCode);

        }
        elseif($maxNodeDist == 2) {
            foreach($c_assoc_code_row as $v) {
                $assocCode[] = $v;
            }
            
            //ASSOC_DATA.personid 符合 $user_input_people 的 ASSOC_DATA.c_assoc_id
            $assoc_people = $this->get_assoc_people($user_input_people, $assocCode);
            //將前一輪的 ASSOC_DATA.c_assoc_id 作為 ASSOC_DATA.personid, 再進行一次查詢。
            $assoc_people2 = $this->get_assoc_people($assoc_people, $assocCode);
            //將$user_input_people、第一輪找出的 ASSOC_DATA.c_assoc_id 和 第二輪找出的 ASSOC_DATA.c_assoc_id 合併去重
            $sum_people = array_merge($user_input_people, $assoc_people , $assoc_people2);
            $sum_people = array_unique($sum_people);
            //限定 ASSOC_DATA.personid 和 ASSOC_DATA.c_assoc_id 必需是 $sum_people 中的人物，並與 BIOG_MAIN 做 join
            $row = $this->get_related_edges($sum_people, $assocCode);         
        }
        else {
            return 'API 暫不支援 maxNodeDist 大於 2 之查詢';
        }

        $row = $row->get();
        //資料庫邏輯結束 $row 的型態此時是 collect
        if(!empty($arr['DEBUG']) && $arr['DEBUG'] == 1) { return $row; }

        //得到關係人的資料
        $row = $this->get_assoc_necessary_data($row);  
        
        //如果useXy == 1 將得到擴大的$place清單，注意 $place 是以 by reference 方式傳入
        $this->get_extended_place($row, $useXy, $XY, $place); 
        
        //過濾時間條件，URL上的人物不受過濾時間條件限制
        $row = $this->useDate($row, $indexYear, $indexStartTime, $indexEndTime, $useDy, $dynStart, $dynEnd, $user_input_people);
        
        if($includeMale == 0) {
            $row = $row->filter(function($v){
                return $v->c_female!=0 && $v->assoc_c_female!=0;
            });
        }

        if($includeFemale == 0) {
            $row = $row->filter(function($v){
                return $v->c_female!=1 && $v->assoc_c_female!=1;
            });
        }
        
        //過濾地點條件，如果前面 useXy == 1，這時候的 $place 會是擴展xy軸後的地址id；如果 useXy == 0，則 $place 是 URL上的 $place
        //URL上的人物不受過濾地點條件限制
        if(($usePeoplePlace || $useXy) &&  $maxNodeDist >= 1) {
            $row = $this->filter_place($row, $user_input_people, $place);
        }
        

        //去除重複的資料清洗，$row 的型態在清洗後之後變成 array
        $new_row = [];
        foreach($row as $v) {
            if(empty($new_row)) { $new_row[] = $v; }
            foreach($new_row as $s) {
                if($v->c_personid == $s->c_personid && $v->c_assoc_id == $s->c_assoc_id && $v->c_assoc_code == $s->c_assoc_code && $v->c_text_title == $s->c_text_title) {
                    break; 
                } else {
                    $new_row[] = $v;
                    break;
                }
            }
        }
        $row = $new_row;
        //去除重複的資料清洗結束

        $total = count($row);

        //需要客製lise與start的資料處理
        if($list) {
            //$row = $row->slice($start, $list);
            $new_row = [];
            $i = 0;
            $j = 0;
            foreach($row as $v) {
                $j++;
                if($j > $start) {
                    $new_row[] = $v;
                    $i++;
                }
                if($i >= $list) { break; }
            }
            $row = $new_row;
        }
        //資料處理客製結束


        //return $row;

        //組合輸出資料
        $record_list = 0;
        foreach ($row as $val) {

            //$record_list++;
            //if($record_list < $start + 1) { continue; }
            //if($record_list > $list) { break; } 

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

            $x1 = $AddrCode->x_coord ?? 0;
            $y1 = $AddrCode->y_coord ?? 0;
            $x2 = $AddrCode2->x_coord ?? 0;
            $y2 = $AddrCode2->y_coord ?? 0;

            $data_val['distance'] = $this->getdizhi($x1, $y1, $x2, $y2);
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

    // 從 ASSOC_DATA 裡，找到以 $people 為 c_personid 的所有 c_assoc_id
    protected function get_assoc_people($people, $assocCode){
        $row = DB::table('ASSOC_DATA')->whereIn('ASSOC_DATA.c_personid', $people);
        $row->whereIn('ASSOC_DATA.c_assoc_code', $assocCode);
        
        $row = $row->get();
        $return_people = [];

        foreach($row as $v) {
            if(!in_array($v->c_assoc_id, $return_people)){
                $return_people[] = $v->c_assoc_id;
            }
        }

        // array
        return $return_people;
    }

    // 從 ASSOC_DATA 裡，找到 $people 範圍內的所有關係，並將 ASSOC_DATA.c_personid join 到 BIOG_MAIN.c_personid
    protected function get_related_edges($people, $assocCode){
        $row = DB::table('ASSOC_DATA')->whereIn('ASSOC_DATA.c_personid', $people);
        $row->join('BIOG_MAIN', 'ASSOC_DATA.c_personid', '=', 'BIOG_MAIN.c_personid');
        $row->whereIn('ASSOC_DATA.c_assoc_id', $people);
        $row->whereIn('ASSOC_DATA.c_assoc_code', $assocCode);
        
        return $row;
    }

    // 組成新的row，加入以 c_assoc_id 為 BiogMain.c_personid ，並找出關係人在 BiogMain 的 c_index_addr_id、c_index_year、c_dy、c_female
    protected function get_assoc_necessary_data($row){
        foreach ($row as $v) {
            $assoc_BiogMain = BiogMain::where('c_personid', '=', $v->c_assoc_id)->first();

            if(!empty($assoc_BiogMain)){
                $v->assoc_c_index_addr_id = $assoc_BiogMain->c_index_addr_id;
                $v->assoc_c_index_year = $assoc_BiogMain->c_index_year;
                $v->assoc_c_dy = $assoc_BiogMain->c_dy;
                $v->assoc_c_female = $assoc_BiogMain->c_female;
            }
            else{
                $v->assoc_c_index_addr_id = "";
                $v->assoc_c_index_year = "";
                $v->assoc_c_dy = "";
                $v->assoc_c_female = "";
            }
        }
        
        return $row;
    }


    //過濾地點條件
    protected function filter_place($row, $user_input_people, $place){
        $tmp_row =collect();
        if($row->isEmpty()){
            return $row;
        }
        foreach($row as $v) {
            //$tmp_assoc = BiogMain::where('c_personid', '=', $v->c_assoc_id)->first();

            //只要此人物和關係人都符合 URL 上的 $people ，即放入結果，不論是否符合 $place 
            if(in_array($v->c_personid, $user_input_people) && in_array($v->c_assoc_id, $user_input_people)){
                $tmp_row->push($v);
            }
            //如果此人物是 URL 上的 $people 之一，關係人不是 URL 上的 $people 之一；但關係人在 Biog_Main 上的 c_index_addr_id 符合 $place ，亦放入結果
            else if(in_array($v->c_personid, $user_input_people) && (!in_array($v->c_assoc_id, $user_input_people) && in_array($v->assoc_c_index_addr_id, $place))){
                $tmp_row->push($v);
            }
            //如果關係人是 URL 上的 $people 之一，此人物不是 URL 上的 $people 之一；但此人物在 Biog_Main 上的 c_index_addr_id 符合 $place ，亦放入結果
            else if(in_array($v->c_assoc_id, $user_input_people) && (!in_array($v->c_personid, $user_input_people) && in_array($v->c_index_addr_id, $place))){
                $tmp_row->push($v);
            }
            //如果關係人和關係人都不在 $people 中，若雙方在 Biog_Main 上的 c_index_addr_id 都符合 $place ，亦放入結果
            else if( in_array($v->c_index_addr_id, $place) && in_array($v->assoc_c_index_addr_id, $place)){
                $tmp_row->push($v);
            }
        }
        return $tmp_row;
    }

    protected function useDate($row, $indexYear, $indexStartTime, $indexEndTime, $useDy, $dynStart, $dynEnd, $user_input_people) {
        if($indexYear && $row->isNotEmpty()) {
            // $row->where('BIOG_MAIN.c_index_year', '>=', $indexStartTime);
            // $row->where('BIOG_MAIN.c_index_year', '<=', $indexEndTime);
            $row = $row->filter(function($v) use($indexStartTime, $indexEndTime, $user_input_people){
                if(in_array($v->c_personid, $user_input_people) && in_array($v->c_assoc_id, $user_input_people)){
                    return $v;
                }
                else if(in_array($v->c_personid, $user_input_people) && (!in_array($v->c_assoc_id, $user_input_people) && 
                $v->assoc_c_index_year >= $indexStartTime && $v->assoc_c_index_year <= $indexEndTime)){
                    return $v;
                }
                else if(in_array($v->c_assoc_id, $user_input_people) && (!in_array($v->c_personid, $user_input_people) &&  
                $v->c_index_year >= $indexStartTime && $v->c_index_year <= $indexEndTime)){
                    return $v;
                } 
                else if($v->c_index_year >= $indexStartTime && $v->c_index_year <= $indexEndTime && $v->assoc_c_index_year >= $indexStartTime && $v->assoc_c_index_year <= $indexEndTime){
                    return $v;
                }
            });
        }

        if($useDy && $row->isNotEmpty()) {
            // $row->join('DYNASTIES', 'BIOG_MAIN.c_dy', '=', 'DYNASTIES.c_dy');
            // $row->where('DYNASTIES.c_dy', '>=', $dynStart);
            // $row->where('DYNASTIES.c_dy', '<=', $dynEnd);
            $row = $row->filter(function ($v) use($dynStart, $dynEnd, $user_input_people) {
                //以Dynasty.c_sort做為判斷範圍的依據
                $p_dynasty = Dynasty::where('c_dy', '=', $v->c_dy)->first()->c_sort ?? null;
                $a_dynasty = Dynasty::where('c_dy', '=', $v->assoc_c_dy)->first()->c_sort ?? null;
                $dynStart_sort = Dynasty::where('c_dy', '=', $dynStart)->first()->c_sort ?? null;
                $dynEnd_sort = Dynasty::where('c_dy', '=', $dynEnd)->first()->c_sort ?? null;
                
                if(in_array($v->c_personid, $user_input_people) && in_array($v->c_assoc_id, $user_input_people)){
                    return $v;
                }
                else if(in_array($v->c_personid, $user_input_people) && (!in_array($v->c_assoc_id, $user_input_people) && 
                $a_dynasty >= $dynStart_sort && $a_dynasty <= $dynEnd_sort)){
                    return $v;
                }
                else if(in_array($v->c_assoc_id, $user_input_people) && (!in_array($v->c_personid, $user_input_people) &&  
                $p_dynasty >= $dynStart_sort && $p_dynasty <= $dynEnd_sort)){
                    return $v;
                } 
                else if($p_dynasty >= $dynStart_sort && $p_dynasty <= $dynEnd_sort && 
                $a_dynasty >= $dynStart_sort && $a_dynasty <= $dynEnd_sort){
                    return $v;
                }
            });
            
        }

        return $row;
    }

    
    // $place 在使用XY之後，會更新成擴展後的地址id，因此要用 by reference 的方式傳入
    protected function get_extended_place($row, $useXy, $XY, &$place) {
        if($useXy && !empty($place)) {    
            $useXyVar = '';
            foreach ($place as $val) {
                if($useXyVar)  $useXyVar .= ',';  //string concat
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
                
            $useXyResArr = array(); //放擴展地理座標後的結果
            foreach ($useXyRes as $val) {
                array_push($useXyResArr, $val->c_addr_id);
            }
            /*20220503修改程式邏輯，
             *先使用查詢地址的 id 獲得經緯度座標資訊，然後用獲得的經緯度座標正負 0.03 找到更多地址 ID，
             *之後用這些地址 ID 來作為地址檢索條件（每個地址 ID 之間是 OR 關係）。
             *useXY 的條件限定會比不使用 useXY 獲得的資訊更多。
             */
            //dd($useXyResArr); //檢查$useXyResArr驗證有效
            $place = $useXyResArr; //擴展地理座標後的結果放回$place
        }
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
