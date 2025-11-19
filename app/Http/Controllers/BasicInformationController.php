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

use App\Pinyin;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
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
        return view('biogmains.basicinformation.index', ['page_title' => 'Basicinformation', 'page_description' => '編輯人物基本信息']);
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
        #20240328移除tts_sysno
        #$data['tts_sysno'] = BiogMain::max('tts_sysno') + 1;
        $data = $this->toolRepository->timestamp($data, True);
        //20190531判別是否為眾包用戶
        if (Auth::user()->is_admin == 2) {
            $this->operationRepository->store(Auth::id(), $data['c_personid'], 1, 'BIOG_MAIN', $data['c_personid'], $data, '', 2);
            flash('眾包紀錄 Create success @ '.Carbon::now(), 'success');
            return redirect()->route('basicinformation.index');
        }
        else {
            //20230628觸發「自動生成」功能
            $data = $this->biogMainRepository->auto_pinyin($data);
            //增加完成
            $flight = BiogMain::create($data);
            $this->operationRepository->store(Auth::id(), $data['c_personid'], 1, 'BIOG_MAIN', $data['c_personid'], $data);
            flash('Create success @ '.Carbon::now(), 'success');
            return redirect()->route('basicinformation.edit', $data['c_personid']);
        }
        //20190531修改結束
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
        //20190531判別是否為眾包用戶
        if (Auth::user()->is_admin == 2) {
            flash('眾包紀錄 Update success @ '.Carbon::now(), 'success');
            return redirect()->route('basicinformation.index');
        }
        else {
            flash('Update success @ '.Carbon::now(), 'success');
            return redirect()->route('basicinformation.edit', $id);
        }
        //20190531修改結束
    }

    //20190223新增另存功能
    public function saveas($id)
    {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1 || Auth::user()->is_admin == 2){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        //如果沒有使用toArray(), 需搭配save()儲存, 則會儲存物件本身, 就無法另存.
        $data = BiogMain::find($id)->toArray();
        $new_id = BiogMain::max('c_personid') + 1;
        #20240328移除tts_sysno
        #$new_ttsid = BiogMain::max('tts_sysno') + 1;
        $data['c_personid'] = $new_id;
        #20240328移除tts_sysno
        #$data['tts_sysno'] = $new_ttsid;
        $data = $this->toolRepository->timestamp($data, True); //建檔資訊
        $data['c_modified_by'] = $data['c_modified_date'] = '';
        $flight = BiogMain::create($data);
        $this->operationRepository->store(Auth::id(), $new_id, 1, 'BIOG_MAIN', $new_id, $data);
        flash('Create success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.edit', $new_id); 
    }

    //20240701新增Duplicate Collateral Info功能
    public function Duplicate_Collateral_Info($id)
    {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1 || Auth::user()->is_admin == 2){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $data = BiogMain::find($id)->toArray();
        $new_id = BiogMain::max('c_personid') + 1;
        $data['c_personid'] = $new_id;
        $data = $this->toolRepository->timestamp($data, True); //建檔資訊
        $data['c_modified_by'] = $data['c_modified_date'] = '';
        $flight = BiogMain::create($data);
        $this->operationRepository->store(Auth::id(), $new_id, 1, 'BIOG_MAIN', $new_id, $data);
        //擴充複製訊息：地址，出處，親屬，社會關係，社會機構，社會區分
        //地址
        $addr = DB::table('BIOG_ADDR_DATA')->where([
            ['c_personid', '=', $id],
        ])->get();
        foreach ($addr as $addr_data) {
            $addr_data = (array)$addr_data;
            $addr_data['c_personid'] = $new_id;
            $addr_data = array_except($addr_data, ['_token']);
            $addr_data = $this->toolRepository->timestamp($addr_data, True); //建檔資訊
            DB::table('BIOG_ADDR_DATA')->insert($addr_data);
            $this->operationRepository->store(Auth::id(), $new_id, 1, 'BIOG_ADDR_DATA', $addr_data['c_personid']."-".$addr_data['c_addr_id']."-".$addr_data['c_addr_type']."-".$addr_data['c_sequence'], $addr_data);
        }

        //出處
        $source = DB::table('BIOG_SOURCE_DATA')->where([
            ['c_personid', '=', $id],
        ])->get();
        foreach ($source as $source_data) {
            $source_data = (array)$source_data;
            $source_data['c_personid'] = $new_id;
            $source_data = array_except($source_data, ['_token']);
            DB::table('BIOG_SOURCE_DATA')->insert($source_data);
            $this->operationRepository->store(Auth::id(), $new_id, 1, 'BIOG_SOURCE_DATA', $source_data['c_personid']."-".$source_data['c_textid']."-".$source_data['c_pages'], $source_data);
        }

        //親屬
        $kin = DB::table('KIN_DATA')->where([
            ['c_personid', '=', $id],
        ])->get();
        foreach ($kin as $kin_data) {
            $kin_data = (array)$kin_data;
            $kin_data['c_personid'] = $new_id;
            $kin_data = $this->toolRepository->timestamp($kin_data, True); //建檔資訊
            DB::table('KIN_DATA')->insert($kin_data);
            $this->operationRepository->store(Auth::id(), $new_id, 1, 'KIN_DATA', $kin_data['c_personid']."-".$kin_data['c_kin_id']."-".$kin_data['c_kin_code'], $kin_data);
        }

        $kin_pair = DB::table('KIN_DATA')->where([
            ['c_kin_id', '=', $id],
        ])->get();
        foreach ($kin_pair as $kin_data) {
            $kin_data = (array)$kin_data;
            $kin_pair_id = $kin_data['c_personid'];
            $kin_data['c_kin_id'] = $new_id;
            $kin_data = $this->toolRepository->timestamp($kin_data, True); //建檔資訊
            DB::table('KIN_DATA')->insert($kin_data);
            $this->operationRepository->store(Auth::id(), $kin_pair_id, 1, 'KIN_DATA', $kin_data['c_personid']."-".$kin_data['c_kin_id']."-".$kin_data['c_kin_code'], $kin_data);
        }

        //社會關係
        $assoc = DB::table('ASSOC_DATA')->where([
            ['c_personid', '=', $id],
        ])->get();
        foreach ($assoc as $assoc_data) {
            $assoc_data = (array)$assoc_data;
            $assoc_data['c_personid'] = $new_id;
            $assoc_data['c_kin_id'] = 0;
            $assoc_data['c_kin_code'] = 0;
            $assoc_data['c_assoc_kin_id'] = 0;
            $assoc_data['c_assoc_kin_code'] = 0;
            $assoc_data['c_tertiary_personid'] = 0;
            $assoc_data['c_tertiary_type_notes'] = null;
            $assoc_data = $this->toolRepository->timestamp($assoc_data, True); //建檔資訊
            DB::table('ASSOC_DATA')->insert($assoc_data);
            $this->operationRepository->store(Auth::id(), $new_id, 1, 'ASSOC_DATA', $assoc_data['c_personid']."-".$assoc_data['c_assoc_code']."-".$assoc_data['c_assoc_id']."-".$assoc_data['c_kin_code']."-".$assoc_data['c_kin_id']."-".$assoc_data['c_assoc_kin_code']."-".$assoc_data['c_assoc_kin_id']."-".$assoc_data['c_text_title'], $assoc_data);
        }

        $assoc_pair = DB::table('ASSOC_DATA')->where([
            ['c_assoc_id', '=', $id],
        ])->get();
        foreach ($assoc_pair as $assoc_data) {
            $assoc_data = (array)$assoc_data;
            $assoc_pair_id = $assoc_data['c_personid'];
            $assoc_data['c_assoc_id'] = $new_id;
            $assoc_data['c_kin_id'] = 0;
            $assoc_data['c_kin_code'] = 0;
            $assoc_data['c_assoc_kin_id'] = 0;
            $assoc_data['c_assoc_kin_code'] = 0;
            $assoc_data['c_tertiary_personid'] = 0;
            $assoc_data['c_tertiary_type_notes'] = null;
            $assoc_data = $this->toolRepository->timestamp($assoc_data, True); //建檔資訊
            DB::table('ASSOC_DATA')->insert($assoc_data);
            $this->operationRepository->store(Auth::id(), $assoc_pair_id, 1, 'ASSOC_DATA', $assoc_data['c_personid']."-".$assoc_data['c_assoc_code']."-".$assoc_data['c_assoc_id']."-".$assoc_data['c_kin_code']."-".$assoc_data['c_kin_id']."-".$assoc_data['c_assoc_kin_code']."-".$assoc_data['c_assoc_kin_id']."-".$assoc_data['c_text_title'], $assoc_data);
        }

        //社交機構
        $inst = DB::table('BIOG_INST_DATA')->where([
            ['c_personid', '=', $id],
        ])->get();
        foreach ($inst as $inst_data) {
            $inst_data = (array)$inst_data;
            $inst_data['c_personid'] = $new_id;
            $inst_data = array_except($inst_data, ['_token']);
            $inst_data = $this->toolRepository->timestamp($inst_data, True); //建檔資訊
            DB::table('BIOG_INST_DATA')->insert($inst_data);
            $this->operationRepository->store(Auth::id(), $new_id, 1, 'BIOG_INST_DATA', $inst_data['c_personid']."-".$inst_data['c_inst_code']."-".$inst_data['c_inst_name_code']."-".$inst_data['c_bi_role_code'], $inst_data);
        } 

        //社會區分
        $status = DB::table('STATUS_DATA')->where([
            ['c_personid', '=', $id],
        ])->get();
        foreach ($status as $status_data) {
            $status_data = (array)$status_data;
            $status_data['c_personid'] = $new_id;
            $status_data = array_except($status_data, ['_token']);
            $status_data = $this->toolRepository->timestamp($status_data, True); //建檔資訊
            DB::table('STATUS_DATA')->insert($status_data);
            $this->operationRepository->store(Auth::id(), $new_id, 1, 'STATUS_DATA', $status_data['c_personid'].'-'.$status_data['c_sequence'].'-'.$status_data['c_status_code'], $status_data);
        }

        //擴充結束
        flash('Create success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.edit', $new_id);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $ori = $this->biogMainRepository->byPersonId($id);
        $biog = BiogMain::find($id);
        $biog->c_name_chn = '<待删除>';
        //20190605判別是否為眾包用戶
        if (Auth::user()->is_admin == 2) {
            $this->operationRepository->store(Auth::id(), $id, 4, 'BIOG_MAIN', $id, $biog, $ori, 2);
            flash('眾包紀錄 Delete success @ '.Carbon::now(), 'success');
            return redirect()->route('basicinformation.index');
        }
        else {
            $biog->save();
            $this->operationRepository->store(Auth::id(), $id, 4, 'BIOG_MAIN', $id, $biog, $ori);
            flash('Delete success @ '.Carbon::now(), 'success');
            return redirect()->route('basicinformation.index');
        }
    }
}
