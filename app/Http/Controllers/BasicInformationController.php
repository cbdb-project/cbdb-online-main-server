<?php

namespace App\Http\Controllers;

use App\BiogMain;
use App\Http\Requests\BasicInformationRequest;
use App\Repositories\BiogMainRepository;
use App\Repositories\ChoronymRepository;
use App\Repositories\DynastyRepository;
use App\Repositories\EthnicityRepository;
use App\Repositories\NianHaoRepository;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\Repositories\YearRangeRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Class BiogBasicInformationController
 * @package App\Http\Controllers
 *
 * 人物基本信息主要包括如下几个Model的内容
 * BiogMain Dynasty NianHao YearRangeCode ChoronymCode TextCode Text
 */
class BasicInformationController extends Controller
{
    protected $biogMainRepository;
    protected $ethnicityRepository;
    protected $dynastyRepository;
    protected $nianhaoRepository;
    protected $choronymRepository;
    protected $yearRangeRepository;
    protected $operationRepository;
    protected $toolRepository;

    /**
     * Create a new controller instance.
     *
     * @param BiogMainRepository $biogMainRepository
     */
    public function __construct(BiogMainRepository $biogMainRepository, EthnicityRepository $ethnicityRepository, DynastyRepository $dynastyRepository, NianHaoRepository $nianHaoRepository, ChoronymRepository $choronymRepository, YearRangeRepository $yearRangeRepository, ToolsRepository $toolsRepository, OperationRepository $operationRepository)
    {
        $this->biogMainRepository = $biogMainRepository;
        $this->ethnicityRepository = $ethnicityRepository;
        $this->dynastyRepository = $dynastyRepository;
        $this->nianhaoRepository = $nianHaoRepository;
        $this->choronymRepository = $choronymRepository;
        $this->yearRangeRepository = $yearRangeRepository;
        $this->operationRepository = $operationRepository;
        $this->toolRepository  = $toolsRepository;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('biogmains.basicinformation.index', ['page_title' => 'Basicinformation', 'page_description' => '编辑人物基本信息']);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $temp_id = BiogMain::max('c_personid') + 1;
        return view('biogmains.basicinformation.create', ['page_title' => 'Basicinformation', 'page_description' => '新建人物基本信息', 'temp_id' => $temp_id]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $data = $request->all();
//        dd(!BiogMain::where('c_personid', $data['c_personid'])->get()->isEmpty());
        if ($data['c_personid'] == null or $data['c_personid'] == 0 or !BiogMain::where('c_personid', $data['c_personid'])->get()->isEmpty()){
            flash('person id 未填或已存在 '.Carbon::now(), 'error');
            return redirect()->back();
        }elseif ((int)$data['c_personid']-(BiogMain::max('c_personid')) > 10000) {
            flash('person id 过大 '.Carbon::now(), 'error');
            return redirect()->back();
        }
//        $data['c_personid'] = BiogMain::max('c_personid') + 1;
        $data['tts_sysno'] = BiogMain::max('tts_sysno') + 1;
        $data = $this->toolRepository->timestamp($data, True);
        $flight = BiogMain::create($data);
        $this->operationRepository->store(Auth::id(), $data['c_personid'], 1, 'BIOG_MAIN', $data['tts_sysno'], $data);
        flash('Create success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.edit', $data['c_personid']);
    }

    /**
     * Display the specified resource.
     *
     * @param  int $id
     * @return \App\BiogMain|BiogMainRepository|BiogMainRepository[]|\Illuminate\Database\Eloquent\Collection|\Illuminate\Database\Eloquent\Model|\Illuminate\Http\Response
     */
    public function show($id)
    {
        $biogbasicinformation = $this->biogMainRepository->byPersonId($id);
        $biogbasicinformation->kinship;
        $biogbasicinformation->office;
        return $biogbasicinformation;
//        return view('biogmains.show', $result);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $biogbasicinformation = $this->biogMainRepository->byPersonId($id);
        $dynasties = $this->dynastyRepository->dynasties();
        $nianhaos = $this->nianhaoRepository->nianhaos();
        $yearRange = $this->yearRangeRepository->yearRange();
        return view('biogmains.basicinformation.edit', ['basicinformation' => $biogbasicinformation, 'dynasties' => $dynasties, 'nianhaos' => $nianhaos, 'yearRange' => $yearRange,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 基本资料']);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param BasicInformationRequest|Request $request
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function update(BasicInformationRequest $request, $id)
    {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $this->biogMainRepository->updateById($request, $id);
        flash('Update success @ '.Carbon::now(), 'success');

        return redirect()->route('basicinformation.edit', $id);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $biog = BiogMain::find($id);
        $biog->c_name_chn = '<待删除>';
        $biog->save();
        $this->operationRepository->store(Auth::id(), $id, 4, 'BIOG_MAIN', $id, []);
    }


}
