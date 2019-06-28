<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BiogMain;
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
            $arr1 = $listsArr['data'][$x]['resource_data'];
            $arr2 = $listsArr['data'][$x]['biog'];
            if(!empty($arr2)) {
                //將json轉換為陣列進行比對
                $arr1 = json_decode($arr1, true);
                $arr2 = json_decode($arr2, true);
                $ans = $this->operationRepository->getArrDiff($arr1, $arr2);
                //將比對後的結果存回至biog欄位
                $lists[$x]['biog'] = $ans;
            }
        }
        return view('modified.index', ['lists' => $lists,
            'page_title' => 'Modified', 'page_description' => '最近修改紀錄',
            'page_url' => '/modified'
        ]);
    }
}
