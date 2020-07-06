<?php
/**
 * User: ja
 * Date: 2020/6/9
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
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

ini_set('memory_limit','512M');
ini_set('max_execution_time', 300);

class ApiController4 extends Controller
{
    use AuthenticatesUsers;

    public function __construct()
    {
        $this->middleware('guest');
    }

    //20200609製作query_relatives API 遞迴的function
    protected function relativesLoop($people, $MAncGen, $MDecGen, $MColLink, $MMarLink, $MLoop, $rowArr, $firstPeople, $run) {
        $c_kin_id = $data = $firstPeopleArr = array();
        $run = $run + 1;
        //資料庫邏輯
        for($i=0;$i<count($people);$i++) {
          $row = DB::table('KIN_DATA')->where('KIN_DATA.c_personid', '=', $people[$i]);
          $row->join('KINSHIP_CODES', 'KINSHIP_CODES.c_kincode', '=', 'KIN_DATA.c_kin_code');
          $row->where('KINSHIP_CODES.c_upstep', '<=', $MAncGen);
          $row->where('KINSHIP_CODES.c_dwnstep', '<=', $MDecGen);
          $row->where('KINSHIP_CODES.c_marstep', '<=', $MColLink);
          $row->where('KINSHIP_CODES.c_colstep', '<=', $MMarLink);
          $row = $row->get();
          foreach ($row as $val) {
            array_push($c_kin_id, $val->c_kin_id);
            array_push($firstPeopleArr, $firstPeople[$i]);
            $data['firstId'] = $firstPeople[$i];
            $data['run'] = $run;
            $data['c_personid'] = $val->c_personid;
            $data['c_kin_id'] = $val->c_kin_id;
            $data['c_kin_code'] = $val->c_kin_code;
            $data['c_notes'] = $val->c_notes;
            array_push($rowArr, $data);
          }
        }

        $MLoop = $MLoop - 1;

        if($MLoop >= 1) {
            $rowArr = $this->relativesLoop($c_kin_id, $MAncGen, $MDecGen, $MColLink, $MMarLink, $MLoop, $rowArr, $firstPeopleArr, $run);
            return $rowArr;
        }
        else {
            return $rowArr;
        }
    }

    //20200609依據指定規格製作query_relatives API
    protected function query_relatives(Request $request) {
        $json = $request['RequestPlayload'];
        $arr = json_decode($json, true);
        $people = $mCircleArr = $data = $dataV = $rowArr = array();
        $k_addr_id_Arr = array();
        $mCircle = $MAncGen = $MDecGen = $MColLink = $MMarLink = $MLoop = $run = $start = $list = 0;
        
        $people = $arr['people'];
        $mCircle = $arr['mCircle']; 
        $MAncGen = $arr['MAncGen'];
        $MDecGen = $arr['MDecGen']; 
        $MColLink = $arr['MColLink'];
        $MMarLink = $arr['MMarLink']; 
        $MLoop = $arr['MLoop'];

        if(!empty($arr['start'])) { 
            if($arr['start'] <= 0) { $start = 0; } // 避免start為負數
            else { $start = $arr['start'] - 1; } // return輸出由1開始, 程式需由0開始.
        }
        else { $start = 0; }

        if(!empty($arr['list'])) {
            $list = $arr['list'];
        }
        else { $list = 10000; }

        if($mCircle) {
            //資料庫邏輯
            $row = DB::table('KIN_DATA')->whereIn('KIN_DATA.c_personid', $people);
            $row->join('KINSHIP_CODES', 'KINSHIP_CODES.c_kincode', '=', 'KIN_DATA.c_kin_code');
            //五服查詢
            $mCircleRow = DB::table('KIN_MOURNING');
            $mCircleRow->join('KINSHIP_CODES', 'KINSHIP_CODES.c_kinrel', '=', 'KIN_MOURNING.c_kinrel');
            $mCircleRow = $mCircleRow->get();
            foreach ($mCircleRow as $val) {
                array_push($mCircleArr, $val->c_kincode);
            }
            $row->whereIn('KIN_DATA.c_kin_code', $mCircleArr);
            $row = $row->get();
            $total = count($row);
            if($list) {
                $row = $row->slice($start, $list);
            }
            foreach ($row as $val) {
                $dataV['firstId'] = $val->c_personid;
                $dataV['c_personid'] = $val->c_personid;
                $dataV['c_kin_id'] = $val->c_kin_id;
                $dataV['c_kin_code'] = $val->c_kin_code;
                $dataV['c_notes'] = $val->c_notes;
                array_push($rowArr, $dataV);
            }
            $row = $rowArr;
        }
        else {
            $row = $this->relativesLoop($people, $MAncGen, $MDecGen, $MColLink, $MMarLink, $MLoop, $rowArr, $people, $run);
            $total = count($row);
            //return $row;
        }

        //預先組合計算用的資料
        foreach ($row as $val) {
            //這裡是查詢親屬關係目標人物人物的[地址]BIOG_ADDR_DATA
            $k_addr_type = $k_addr_id = 0;
            $KBiogAddr = BiogAddr::where('c_personid', '=', $val['c_kin_code'])->whereIn('c_addr_type', [1, 16, 6, 4, 2, 13, 14, 17])->first();
            if(!$KBiogAddr) {
                $KBiogAddr = BiogAddr::where('c_personid', '=', $val['c_kin_code'])->first();
            }
            if($KBiogAddr) {
                $k_addr_type = $KBiogAddr->c_addr_type;
                $k_addr_id = $KBiogAddr->c_addr_id;
            }
            $KAddrCode = AddrCode::where('c_addr_id', '=', $k_addr_id)->first();
            
            array_push($k_addr_id_Arr, $k_addr_id.'-'.$KAddrCode->c_name_chn);
        }
        $answer = array_count_values($k_addr_id_Arr);
        //return $answer;
        
        //組合輸出資料
        foreach ($row as $val) {
            $FirstBiogMain = BiogMain::where('c_personid', '=', $val['firstId'])->first();
            $data_val['rId'] = $val['firstId'];
            $data_val['rName'] = $FirstBiogMain->c_name;
            $data_val['rNameChn'] = $FirstBiogMain->c_name_chn;
            $BiogMain = BiogMain::where('c_personid', '=', $val['c_personid'])->first();
            $data_val['pId'] = $val['c_personid'];
            $data_val['pName'] = $BiogMain->c_name;
            $data_val['pNameChn'] = $BiogMain->c_name_chn;
            //這裡是查詢人物的[地址]BIOG_ADDR_DATA
            $c_addr_type = $c_addr_id = 0;
            $BiogAddr = BiogAddr::where('c_personid', '=', $val['c_personid'])->whereIn('c_addr_type', [1, 16, 6, 4, 2, 13, 14, 17])->first();
            if(!$BiogAddr) {
                $BiogAddr = BiogAddr::where('c_personid', '=', $val['c_personid'])->first();
            }
            if($BiogAddr) {
                $c_addr_type = $BiogAddr->c_addr_type;
                $c_addr_id = $BiogAddr->c_addr_id;
            }
            $BiogAddrCode = BiogAddrCode::where('c_addr_type', '=', $c_addr_type)->first(); 
            $data_val['pAddrID'] = $c_addr_id;
            $data_val['pAddrType'] = $BiogAddrCode->c_addr_desc;
            $data_val['pAddrTypeChn'] = $BiogAddrCode->c_addr_desc_chn;
            $AddrCode = AddrCode::where('c_addr_id', '=', $c_addr_id)->first();
            $data_val['pAddrName'] = $AddrCode->c_name;
            $data_val['pAddrNameChn'] = $AddrCode->c_name_chn;
            $data_val['pX'] = $AddrCode->x_coord;
            $data_val['pY'] = $AddrCode->y_coord;

            $KinBiogMain = BiogMain::where('c_personid', '=', $val['c_kin_id'])->first();
            $data_val['Id'] = $val['c_kin_id'];
            $data_val['Name'] = $KinBiogMain->c_name;
            $data_val['NameChn'] = $KinBiogMain->c_name_chn;
            $data_val['Sex'] = $KinBiogMain->c_female ? '1-女' : '0-男';
            $data_val['IndexYear'] = $KinBiogMain->c_index_year;
            $KinshipCode = KinshipCode::where('c_kincode', '=', $val['c_kin_code'])->first();
            $data_val['pkinship'] = $KinshipCode->c_kinrel_chn;

            $rKinshipCodeNum = 0;
            $rKinship = DB::table('KIN_DATA')->where('c_personid', '=', $val['firstId'])->where('c_kin_id', '=', $val['c_kin_id'])->get();
            if($rKinship) {
                foreach ($rKinship as $val2) {
                    $rKinshipCodeNum = $val2->c_kin_code;
                }
            }
            $rKinshipCode = KinshipCode::where('c_kincode', '=', $rKinshipCodeNum)->first();
            $data_val['rKinship'] = $rKinshipCode->c_kinrel_chn;
            $data_val['up'] = $KinshipCode->c_upstep;
            $data_val['down'] = $KinshipCode->c_dwnstep;
            $data_val['col'] = $KinshipCode->c_colstep;
            $data_val['mar'] = $KinshipCode->c_marstep;
            //這裡是查詢親屬關係目標人物人物的[地址]BIOG_ADDR_DATA
            $k_addr_type = $k_addr_id = 0;
            $KBiogAddr = BiogAddr::where('c_personid', '=', $val['c_kin_code'])->whereIn('c_addr_type', [1, 16, 6, 4, 2, 13, 14, 17])->first();
            if(!$KBiogAddr) {
                $KBiogAddr = BiogAddr::where('c_personid', '=', $val['c_kin_code'])->first();
            }
            if($KBiogAddr) {
                $k_addr_type = $KBiogAddr->c_addr_type;
                $k_addr_id = $KBiogAddr->c_addr_id;
            }
            $KBiogAddrCode = BiogAddrCode::where('c_addr_type', '=', $k_addr_type)->first();
            $data_val['AddrID'] = $k_addr_id;
            $data_val['AddrType'] = $KBiogAddrCode->c_addr_desc;
            $data_val['AddrTypeChn'] = $KBiogAddrCode->c_addr_desc_chn;
            $KAddrCode = AddrCode::where('c_addr_id', '=', $k_addr_id)->first();
            $data_val['AddrName'] = $KAddrCode->c_name;
            $data_val['AddrNameChn'] = $KAddrCode->c_name_chn;
            $data_val['X'] = $KAddrCode->x_coord;
            $data_val['Y'] = $KAddrCode->y_coord;

            //這裡是查詢中心人物的[地址]BIOG_ADDR_DATA
            $r_addr_type = $r_addr_id = 0;
            $RBiogAddr = BiogAddr::where('c_personid', '=', $val['firstId'])->whereIn('c_addr_type', [1, 16, 6, 4, 2, 13, 14, 17])->first();
            if(!$RBiogAddr) {
                $RBiogAddr = BiogAddr::where('c_personid', '=', $val['firstId'])->first();
            }
            if($RBiogAddr) {
                $r_addr_type = $RBiogAddr->c_addr_type;
                $r_addr_id = $RBiogAddr->c_addr_id;
            }
            $RAddrCode = AddrCode::where('c_addr_id', '=', $r_addr_id)->first();
            $data_val['pDistance'] = $this->getdizhi($KAddrCode->x_coord, $KAddrCode->y_coord, $AddrCode->x_coord, $AddrCode->y_coord);
            $data_val['rDistance'] = $this->getdizhi($KAddrCode->x_coord, $KAddrCode->y_coord, $RAddrCode->x_coord, $RAddrCode->y_coord);

            $data_val['xy_count'] = $answer[$k_addr_id.'-'.$KAddrCode->c_name_chn]; 
            $data_val['Notes'] = $val['c_notes'];

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
