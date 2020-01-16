<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BiogMain;
use App\OfficeCode;
use App\OfficeCodeTypeRel;
use App\OfficeTypeTree;
use App\Operation;
use App\Repositories\ToolsRepository;
use App\Repositories\OperationRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class ModifiedController extends Controller
{
    protected $operationRepository;
    protected $toolRepository;

    public function __construct(ToolsRepository $toolsRepository,OperationRepository $operationRepository)
    {
        $this->toolRepository  = $toolsRepository;
        $this->operationRepository = $operationRepository;
    }

    public function store()
    {
//        Operation::all();
    }

    public function index()
    {
        $lists = Operation::whereIn('crowdsourcing_status', array(0,1))->orderBy('updated_at', 'desc')->limit(100)->paginate(20);
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
                        if(count(OfficeCode::find($resource_id))) {
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
        return view('modified.index', ['lists' => $lists,
            'page_title' => 'Modified', 'page_description' => '最近修改紀錄',
            'page_url' => '/modified'
        ]);
    }
}
