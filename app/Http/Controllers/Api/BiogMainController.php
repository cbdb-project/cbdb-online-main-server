<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\BiogMain;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class BiogMainController extends Controller
{
    protected $biogMainRepository;

    /**
     * TextsController constructor.
     * @param BiogMainRepository $biogMainRepository
     */
    public function __construct(BiogMainRepository $biogMainRepository)
    {
        $this->biogMainRepository = $biogMainRepository;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
        return response()->json([
            "error" => "no_id",
            "message" => "没有输入id",
            "hint" => "/api/v1/biog/{id}"
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $data = $request->all();
        if ($data['c_personid'] == null or $data['c_personid'] == 0 or !BiogMain::where('c_personid', $data['c_personid'])->get()->isEmpty()){
            flash('person id 未填或已存在 '.Carbon::now(), 'error');
            return redirect()->back();
        }elseif ((int)$data['c_personid']-(BiogMain::max('c_personid')) > 10000) {
            flash('person id 过大 '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $data['tts_sysno'] = BiogMain::max('tts_sysno') + 1;
        (new ToolsRepository())->timestamp($data, True);
        $flight = BiogMain::create($data);
        (new OperationRepository())->store(Auth::id(), $data['c_personid'], 1, 'BIOG_MAIN', $data['tts_sysno'], $data);
        return response()->json([
            "error" => 0,
            "message" => "新增成功",
            "hint" => ""
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return BiogMain
     */
    public function show($id)
    {
        $data = $this->biogMainRepository->byPersonId($id);
//        dump($data->toArray());
        return new BiogMain($data);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
        $this->biogMainRepository->updateById($request, $id);
        return response()->json([
            "error" => 0,
            "message" => "创建成功",
            "hint" => ""
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $biog = BiogMain::find($id);
        $biog->c_name_chn = '<待删除>';
        $biog->save();
        (new OperationRepository())->store(Auth::id(), $id, 4, 'BIOG_MAIN', $id, []);
    }
}
