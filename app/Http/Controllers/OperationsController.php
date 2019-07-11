<?php

namespace App\Http\Controllers;

use App\Operation;
use App\BiogMain;
use App\Repositories\OperationRepository;
use Illuminate\Http\Request;

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
            $arr2 = $listsArr['data'][$x]['biog'];
            if(!empty($c_personid = $listsArr['data'][$x]['c_personid'])) { $arr3 = BiogMain::find($c_personid)->toArray(); }
            if(!empty($arr2)) {
                //將json轉換為陣列進行比對
                $arr1 = json_decode($arr1, true);
                $arr2 = json_decode($arr2, true);
                $ans = $this->operationRepository->getArrDiff($arr1, $arr2, $arr3);
                //將比對後的結果存回至biog欄位
                $lists[$x]['biog'] = $ans;
            }
        }
        //echo "<pre><code>";
        //print_r($lists[0]['biog']); //成功
        //echo "</code></pre>";
        return view('operations.index', ['lists' => $lists,
            'page_title' => 'NewUpdate', 'page_description' => '最近編輯列表',
            'page_url' => '/operations'
        ]);
    }
}
