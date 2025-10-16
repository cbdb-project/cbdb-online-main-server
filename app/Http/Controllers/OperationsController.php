<?php

namespace App\Http\Controllers;

use App\Operation;
use App\BiogMain;
use App\OfficeCode;
use App\OfficeCodeTypeRel;
use App\OfficeTypeTree;
use App\Repositories\OperationRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OperationsController extends Controller
{
    protected $operationRepository;

    public function __construct(OperationRepository $operationRepository)
    {
        $this->operationRepository = $operationRepository;
    }

    public function store()
    {
//        Operation::all();
    }

    public function index()
    {
        $lists = Operation::where('crowdsourcing_status', 0)->orderBy('updated_at', 'desc')->limit(100)->paginate(20);
        //將物件轉為陣列進行陣列比對
        $listsArr = $this->operationRepository->objectToArray($lists);
        $all = count($listsArr['data']);
        for($x=0;$x<$all;$x++) {
            $c_personid = '';
            $arr3 = array();
            $arr1 = $listsArr['data'][$x]['resource_data'];
            $arr2 = $listsArr['data'][$x]['resource_original'];
            //20191225實時比對的程式判斷
            if(!empty($c_personid = $listsArr['data'][$x]['c_personid']) && $listsArr['data'][$x]['resource'] == "BIOG_MAIN") { $arr3 = BiogMain::find($c_personid)->toArray(); }
            elseif(!empty($resource_id = $listsArr['data'][$x]['resource_id']) && !empty($resource = $listsArr['data'][$x]['resource'])) {
                switch ($resource) {
                    case "OFFICE_CODES":
                        if(count((Array)OfficeCode::find($resource_id))) {
                            $arr3 = OfficeCode::find($resource_id)->toArray();
                        }
                        break;
                    case "OFFICE_CODE_TYPE_REL":
                        $temp_l = explode("-", $resource_id);
                        if(count(OfficeCodeTypeRel::where('c_office_id', $temp_l[0])->where('c_office_tree_id', $temp_l[1])->first())) {
                            $arr3 = OfficeCodeTypeRel::where('c_office_id', $temp_l[0])->where('c_office_tree_id', $temp_l[1])->first()->toArray();
                        }
                        break;
                    case "OFFICE_TYPE_TREE":
                        if(count(OfficeTypeTree::find($resource_id))) {
                            $arr3 = OfficeTypeTree::find($resource_id)->toArray();
                        }
                        break;
                    //20251213新增差異比對紀錄
                    case "BIOG_ADDR_DATA":
                        $addr_l = explode("-", $resource_id);
                        $arr3 = DB::table('BIOG_ADDR_DATA')->where([
                            ['c_personid', '=', $addr_l[0]],
                            ['c_addr_id', '=', $addr_l[1]],
                            ['c_addr_type', '=', $addr_l[2]],
                            ['c_sequence', '=', $addr_l[3]]
                        ])->first();
                        $arr3 = json_encode($arr3);
                        $arr3 = json_decode($arr3, true);
                        break;
                    case "ALTNAME_DATA":
                        $addr_l = explode("-", $resource_id);
                        $arr3 = DB::table('ALTNAME_DATA')->where([
                            ['c_personid', '=', $addr_l[0]],
                            ['c_sequence', '=', $addr_l[1]],
                            ['c_alt_name_chn', 'like', '%'.$addr_l[2].'%'],
                            ['c_alt_name_type_code', '=', $addr_l[3]],
                        ])->first();
                        $arr3 = json_encode($arr3);
                        $arr3 = json_decode($arr3, true);
                        break;
                    //20251214新增差異比對紀錄
                    case "BIOG_TEXT_DATA":
                        $temp_l = explode("-", $resource_id);
                        $arr3 = DB::table('BIOG_TEXT_DATA')->where([
                            ['c_personid', '=', $temp_l[0]],
                            ['c_textid', '=', $temp_l[1]],
                            ['c_role_id', '=', $temp_l[2]],
                        ])->first();
                        $arr3 = json_encode($arr3);
                        $arr3 = json_decode($arr3, true);
                        break;
                    case "POSTED_TO_OFFICE_DATA":
                        $temp_l = explode("-", $resource_id);
                        $_officeid = $temp_l[0];
                        $_postingid = $temp_l[1];
                        $arr3 = DB::table('POSTED_TO_OFFICE_DATA')->where([['c_office_id' , '=', $_officeid], ['c_posting_id' , '=', $_postingid]])->first();
                        $arr3 = json_encode($arr3);
                        $arr3 = json_decode($arr3, true);
                        break;
                    default:
                        $arr3 = array();
                        break;
                }
            }
            else { $arr3 = array(); }

            if(!empty($arr2)) {
                //將json轉換為陣列進行比對
                $arr1 = json_decode($arr1, true);
                $arr2 = json_decode($arr2, true);
                $ans = $this->operationRepository->getArrDiff($arr1, $arr2, $arr3);
                //將比對後的結果存回至resource_original欄位
                $lists[$x]['resource_original'] = $ans;
            }
        }
        //echo "<pre><code>";
        //print_r($lists[0]['resource_original']); //成功
        //echo "</code></pre>";
        return view('operations.index', ['lists' => $lists,
            'page_title' => 'NewUpdate', 'page_description' => '最近編輯列表',
            'page_url' => '/operations'
        ]);
    }
}
