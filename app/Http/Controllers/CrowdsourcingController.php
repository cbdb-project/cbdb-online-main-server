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

class CrowdsourcingController extends Controller
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
        $lists = Operation::where('crowdsourcing_status', '!=' , 0)->orderBy('created_at', 'desc')->limit(100)->paginate(20);
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
        $data = $data[0]['resource_data'];
        $data = json_decode($data,true);
        $updated_at = Carbon::now()->format('YmdHis');

        //資料對應處理
        switch ($resource) {
            case "BIOG_MAIN":
                $new_id = BiogMain::max('c_personid') + 1;
                $new_ttsid = BiogMain::max('tts_sysno') + 1;
                $data['c_personid'] = $new_id;
                $data['tts_sysno'] = $new_ttsid;
                $data = $this->toolRepository->timestamp($data); //建檔資訊

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
