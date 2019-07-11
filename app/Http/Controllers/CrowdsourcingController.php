<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BiogMain;
use App\Operation;
use App\Repositories\ToolsRepository;
use App\Repositories\OperationRepository;
use App\Repositories\BiogMainRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class CrowdsourcingController extends Controller
{
    protected $operationRepository;
    protected $toolRepository;
    protected $biogMainRepository;

    public function __construct(ToolsRepository $toolsRepository,OperationRepository $operationRepository,BiogMainRepository $biogMainRepository)
    {
        $this->toolRepository  = $toolsRepository;
        $this->operationRepository = $operationRepository;
        $this->biogMainRepository = $biogMainRepository;
    }

    public function store()
    {
//        Operation::all();
    }

    public function index()
    {
        $lists = Operation::where('crowdsourcing_status', '!=' , 0)->orderBy('created_at', 'desc')->limit(100)->paginate(20);
        //將物件轉為陣列進行陣列比對
        $listsArr = $this->operationRepository->objectToArray($lists);
        $all = count($listsArr['data']);
        for($x=0;$x<$all;$x++) {
            $c_personid = '';
            $arr3 = array();
            $arr1 = $listsArr['data'][$x]['resource_data'];
            $arr2 = $listsArr['data'][$x]['resource_original'];
            if(!empty($c_personid = $listsArr['data'][$x]['c_personid'])) { $arr3 = BiogMain::find($c_personid)->toArray(); }
            if(!empty($arr2)) {
                //將json轉換為陣列進行比對
                $arr1 = json_decode($arr1, true);
                $arr2 = json_decode($arr2, true);
                $ans = $this->operationRepository->getArrDiff($arr1, $arr2, $arr3);
                //將比對後的結果存回至resource_original欄位
                $lists[$x]['resource_original'] = $ans;
            }
        }
        return view('crowdsourcing.index', ['lists' => $lists,
            'page_title' => 'Crowdsourcing', 'page_description' => '最近眾包錄入紀錄',
            'page_url' => '/crowdsourcing'
        ]);
    }

    public function confirm($id)
    {
        //登入判斷
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }

        //operations資料解析
        $data = DB::table('operations')->where('id', $id)->get();
        $data = json_decode($data,true);
        $rate = $data[0]['rate'];
        $rate = $rate+1;
        $resource = $data[0]['resource'];
        $op_type = $data[0]['op_type'];
        $c_personid = $data[0]['c_personid'];
        $data = $data[0]['resource_data'];
        $data = json_decode($data,true);
        $updated_at = Carbon::now()->format('YmdHis');

        //資料對應處理
        if($op_type == 1) { //新增資料
            switch ($resource) {
                case "BIOG_MAIN":
                    $new_id = BiogMain::max('c_personid') + 1;
                    $new_ttsid = BiogMain::max('tts_sysno') + 1;
                    $data['c_personid'] = $new_id;
                    $data['tts_sysno'] = $new_ttsid;
                    $data = $this->toolRepository->timestamp($data); //建檔資訊

                    //$errorMsg = "您提供的JSON格式不符合，請refect這筆紀錄。";
                    //App::abort(403, $errorMsg);

                    $message = BiogMain::create($data);
                    if($message == true) {
                        DB::table('operations')->where('id', $id)->update(array('crowdsourcing_status' => 1, 'rate' => $rate, 'updated_at' => $updated_at));
                        $this->operationRepository->store(Auth::id(), $data['c_personid'], 1, $resource, $data['c_personid'], $data);
                        flash('Create success @ '.Carbon::now(), 'success');
                    }
                    break;
                default:
                    DB::table('operations')->where('id', $id)->update(array('crowdsourcing_status' => 4, 'rate' => $rate, 'updated_at' => $updated_at));
                    flash('Create error @ '.Carbon::now(), 'danger');
                    break;
            }
        }
        elseif($op_type == 3){ //修改資料
            switch ($resource) {
                case "BIOG_MAIN":
                    //眾包用戶經由錄入介面所儲存的json，是經過biogMainRepository->updateById整理過後的完整json，所以可以直接呼叫後儲存即可。
                    $ori = $this->biogMainRepository->byPersonId($c_personid);
                    $biog = BiogMain::find($c_personid);
                    $biog->update($data);
                    DB::table('operations')->where('id', $id)->update(array('crowdsourcing_status' => 1, 'rate' => $rate, 'updated_at' => $updated_at));
                    $this->operationRepository->store(Auth::id(), $c_personid, 3, $resource, $c_personid, $data, $ori);
                    flash('Update success @ '.Carbon::now(), 'success');
                    break;
                default:
                    DB::table('operations')->where('id', $id)->update(array('crowdsourcing_status' => 4, 'rate' => $rate, 'updated_at' => $updated_at));
                    flash('Update error @ '.Carbon::now(), 'danger');
                    break;
            }
        }
        elseif($op_type == 4){ //刪除資料
            switch ($resource) {
                case "BIOG_MAIN":
                    //眾包用戶經由錄入介面所儲存的json，是經過biogMainRepository->updateById整理過後的完整json，所以>可以直接呼叫後儲存即可。
                    $ori = $this->biogMainRepository->byPersonId($c_personid);
                    $biog = BiogMain::find($c_personid);
                    $biog->update($data);
                    DB::table('operations')->where('id', $id)->update(array('crowdsourcing_status' => 1, 'rate' => $rate, 'updated_at' => $updated_at));
                    $this->operationRepository->store(Auth::id(), $c_personid, 4, $resource, $c_personid, $data, $ori);
                    flash('Delete success @ '.Carbon::now(), 'success');
                    break;
                default:
                    DB::table('operations')->where('id', $id)->update(array('crowdsourcing_status' => 4, 'rate' => $rate, 'updated_at' => $updated_at));
                    flash('Update error @ '.Carbon::now(), 'danger');
                    break;
            }
        
        }
        return redirect()->route('crowdsourcing.index');
    }

    public function reject($id)
    {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $updated_at = Carbon::now()->format('YmdHis');
        DB::table('operations')->where('id', $id)->update(array('crowdsourcing_status' => 3, 'updated_at' => $updated_at));
        flash('Reject success @ '.Carbon::now(), 'success');
        return redirect()->route('crowdsourcing.index');
    }
}
