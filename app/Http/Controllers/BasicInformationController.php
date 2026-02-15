<?php

namespace App\Http\Controllers;

use App\Http\Requests\BasicInformationRequest;
use App\Models\BiogMain;
use App\Repositories\BiogMainRepository;
use App\Repositories\ChoronymRepository;
use App\Repositories\DynastyRepository;
use App\Repositories\EthnicityRepository;
use App\Repositories\NianHaoRepository;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\Repositories\YearRangeRepository;
use App\Services\AuditLogService;
use App\Services\NameSearchIndexService;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Class BiogBasicInformationController
 * @package App\Http\Controllers
 *
 * 人物基本資料主要包括如下几个Model的内容
 * BiogMain Dynasty NianHao YearRangeCode ChoronymCode TextCode Text
 */
class BasicInformationController extends Controller {
    protected $biogMainRepository;
    protected $ethnicityRepository;
    protected $dynastyRepository;
    protected $nianhaoRepository;
    protected $choronymRepository;
    protected $yearRangeRepository;
    protected $operationRepository;
    protected $toolRepository;
    protected $nameSearchIndexService;

    /**
     * Create a new controller instance.
     *
     * @param BiogMainRepository $biogMainRepository
     */
    public function __construct(BiogMainRepository $biogMainRepository, EthnicityRepository $ethnicityRepository, DynastyRepository $dynastyRepository, NianHaoRepository $nianHaoRepository, ChoronymRepository $choronymRepository, YearRangeRepository $yearRangeRepository, ToolsRepository $toolsRepository, OperationRepository $operationRepository, NameSearchIndexService $nameSearchIndexService) {
        $this->biogMainRepository = $biogMainRepository;
        $this->ethnicityRepository = $ethnicityRepository;
        $this->dynastyRepository = $dynastyRepository;
        $this->nianhaoRepository = $nianHaoRepository;
        $this->choronymRepository = $choronymRepository;
        $this->yearRangeRepository = $yearRangeRepository;
        $this->operationRepository = $operationRepository;
        $this->toolRepository = $toolsRepository;
        $this->nameSearchIndexService = $nameSearchIndexService;
        $this->middleware('auth')->except(['index', 'show', 'edit']);
    }

    private function normalizePersonId($id): int {
        $idString = (string)$id;

        if ($idString === '' || !ctype_digit($idString)) {
            abort(404);
        }

        $personId = (int)$idString;

        if ($personId < 0) {
            abort(404);
        }

        return $personId;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request) {
        // 获取查询参数
        $q = $request->input('q', '');
        $num = $request->input('num', 20);

        // 使用 Repository 查询数据
        $names = $this->biogMainRepository->namesByQuery($request, $num);

        return view('biogmains.basicinformation.index', [
            'page_title' => '人物基本資料',
            'page_description' => '編輯人物基本資料',
            'names' => $names,
            'q' => $q,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create() {
        $temp_id = BiogMain::max('c_personid') + 1;

        return view('biogmains.basicinformation.create', [
            'page_title' => '人物基本資料',
            'page_description' => '新建人物基本資料',
            'temp_id' => $temp_id,
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => '新增', 'url' => '#'],
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request) {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->isActive()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        $data = $request->all();
        //        dd(!BiogMain::where('c_personid', $data['c_personid'])->get()->isEmpty());
        if ($data['c_personid'] == null or $data['c_personid'] == 0 or !BiogMain::where('c_personid', $data['c_personid'])->get()->isEmpty()) {
            flash('person id 未填或已存在 '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif ((int)$data['c_personid'] - (BiogMain::max('c_personid')) > 10000) {
            flash('person id 过大 '.Carbon::now(), 'error');

            return redirect()->back();
        }
        //        $data['c_personid'] = BiogMain::max('c_personid') + 1;
        $data = $this->toolRepository->timestamp($data, true);
        //20190531判別是否為眾包用戶
        if (Auth::user()->isCrowdsourcingUser()) {
            $this->operationRepository->store(Auth::id(), $data['c_personid'], 1, 'BIOG_MAIN', $data['c_personid'], $data, '', 2);
            flash('眾包紀錄 Create success @ '.Carbon::now(), 'success');

            return redirect()->route('basicinformation.index');
        } else {
            //20230628觸發「自動生成」功能
            $data = $this->biogMainRepository->auto_pinyin($data);
            //增加完成
            $flight = null;
            DB::transaction(function () use (&$flight, $data) {
                $flight = BiogMain::create($data);
                $operation = $this->operationRepository->store(Auth::id(), $data['c_personid'], 1, 'BIOG_MAIN', $data['c_personid'], $data);

                (new AuditLogService())->write(
                    'BIOG_MAIN',
                    'INSERT',
                    ['c_personid' => $data['c_personid']],
                    null,
                    $flight->toArray(),
                    'user',
                    (string) Auth::id(),
                    $operation ? (string) $operation->id : null
                );
            });

            if (Schema::hasTable('CBDB__NAME_FTS')) {
                $this->nameSearchIndexService->reindexPerson($flight);
            }

            flash('Create success @ '.Carbon::now(), 'success');

            return redirect()->route('basicinformation.edit', $data['c_personid']);
        }
        //20190531修改結束
    }

    /**
     * Display the specified resource.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function show($id) {
        $personId = $this->normalizePersonId($id);
        $biogbasicinformation = $this->biogMainRepository->byPersonId($personId);

        if (!$biogbasicinformation) {
            abort(404);
        }

        $dynasties = $this->dynastyRepository->dynasties();
        $nianhaos = $this->nianhaoRepository->nianhaos();
        $yearRange = $this->yearRangeRepository->yearRange();

        // 處理 basicinformation 可能為 null 或缺少字段的情況
        $personLabel = $personId;

        try {
            if ($biogbasicinformation) {
                $nameChn = $biogbasicinformation->c_name_chn ?? '';
                $name = $biogbasicinformation->c_name ?? '';
                if ($nameChn || $name) {
                    $personLabel .= ' - ' . $nameChn;
                    if ($name) {
                        $personLabel .= ' (' . $name . ')';
                    }
                }
            }
        } catch (\Exception $e) {
            // 如果 byPersonId 失敗（例如在測試環境中表結構不完整），只使用 ID
        }

        return view('biogmains.basicinformation.edit', [
            'basicinformation' => $biogbasicinformation,
            'dynasties' => $dynasties,
            'nianhaos' => $nianhaos,
            'yearRange' => $yearRange,
            'page_title' => '人物基本資料',
            'page_description' => '基本信息表 基本资料（只读）',
            'readonly' => true,
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.show', $personId)],
                ['label' => '查看', 'url' => '#'],
            ],
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id) {
        $personId = $this->normalizePersonId($id);

        $biogbasicinformation = $this->biogMainRepository->byPersonId($personId);

        if (!$biogbasicinformation) {
            abort(404);
        }

        $dynasties = $this->dynastyRepository->dynasties();
        $nianhaos = $this->nianhaoRepository->nianhaos();
        $yearRange = $this->yearRangeRepository->yearRange();

        // 處理 basicinformation 可能為 null 或缺少字段的情況
        $personLabel = $personId;

        try {
            if ($biogbasicinformation) {
                $nameChn = $biogbasicinformation->c_name_chn ?? '';
                $name = $biogbasicinformation->c_name ?? '';
                if ($nameChn || $name) {
                    $personLabel .= ' - ' . $nameChn;
                    if ($name) {
                        $personLabel .= ' (' . $name . ')';
                    }
                }
            }
        } catch (\Exception $e) {
            // 如果 byPersonId 失敗（例如在測試環境中表結構不完整），只使用 ID
        }

        return view('biogmains.basicinformation.edit', [
            'basicinformation' => $biogbasicinformation,
            'dynasties' => $dynasties,
            'nianhaos' => $nianhaos,
            'yearRange' => $yearRange,
            'page_title' => '人物基本資料',
            'page_description' => '基本信息表 基本资料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $personId)],
                ['label' => '编辑', 'url' => '#'],
            ],
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param BasicInformationRequest|Request $request
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function update(BasicInformationRequest $request, $id) {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->isActive()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 檢查動作類型
        $action = $request->input('action', 'save');

        if ($action === 'proposal') {
            // 基本資料表提案前，需先經過姓名正規化與時間戳邏輯，確保提案內容完整
            $data = $request->all();

            // 姓名合成邏輯
            $data['c_name_chn'] = ($data['c_surname_chn'] ?? '') . ($data['c_mingzi_chn'] ?? '');
            $data['c_name'] = trim(($data['c_surname'] ?? '') . ' ' . ($data['c_mingzi'] ?? ''));
            $data['c_name_proper'] = trim(($data['c_mingzi_proper'] ?? '') . ' ' . ($data['c_surname_proper'] ?? ''));
            $data['c_name_rm'] = trim(($data['c_mingzi_rm'] ?? '') . ' ' . ($data['c_surname_rm'] ?? ''));

            // 數據類型轉換
            $data['c_female'] = (int)($data['c_female'] ?? 0);
            $data['c_by_intercalary'] = (int)($data['c_by_intercalary'] ?? 0);
            $data['c_dy_intercalary'] = (int)($data['c_dy_intercalary'] ?? 0);

            // 拼音自動生成（測試環境可能未建立 pinyin 表）
            if (Schema::hasTable('pinyin')) {
                $data = $this->biogMainRepository->auto_pinyin($data);
            }

            // 時間戳
            $data = $this->toolRepository->timestamp($data);

            // 替換 Request 中的數據（以便 ProposalController 提取）
            $request->replace($data);

            return app(\App\Http\Controllers\BasicInformationProposalController::class)
                ->proposalUpdateWithPk($request, $id, 'biogmain', ['c_personid' => $id]);
        }

        $result = $this->biogMainRepository->updateById($request, $id);

        // 檢查是否有實質變更
        if (isset($result['no_changes']) && $result['no_changes']) {
            flash('無實質更新，資料未變更 @ '.Carbon::now(), 'info');

            return redirect()->route('basicinformation.edit', $id);
        }

        //20190531判別是否為眾包用戶
        if (Auth::user()->isCrowdsourcingUser()) {
            flash('眾包紀錄 Update success @ '.Carbon::now(), 'success');

            return redirect()->route('basicinformation.index');
        } else {
            if (Schema::hasTable('CBDB__NAME_FTS')) {
                $person = BiogMain::find($id);
                if ($person) {
                    $this->nameSearchIndexService->reindexPerson($person);
                }
            }

            flash('Update success @ '.Carbon::now(), 'success');

            return redirect()->route('basicinformation.edit', $id);
        }
        //20190531修改結束
    }

    //20190223新增另存功能
    public function saveas($id) {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        //如果沒有使用toArray(), 需搭配save()儲存, 則會儲存物件本身, 就無法另存.
        $data = BiogMain::find($id)->toArray();
        $new_id = BiogMain::max('c_personid') + 1;
        $data['c_personid'] = $new_id;
        $data = $this->toolRepository->timestamp($data, true); //建檔資訊
        $data['c_modified_by'] = $data['c_modified_date'] = '';
        $flight = null;
        DB::transaction(function () use (&$flight, $data, $new_id) {
            $flight = BiogMain::create($data);
            $operation = $this->operationRepository->store(Auth::id(), $new_id, 1, 'BIOG_MAIN', $new_id, $data);

            (new AuditLogService())->write(
                'BIOG_MAIN',
                'INSERT',
                ['c_personid' => $new_id],
                null,
                $flight->toArray(),
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );
        });

        if (Schema::hasTable('CBDB__NAME_FTS')) {
            $this->nameSearchIndexService->reindexPerson($flight);
        }

        flash('Create success @ '.Carbon::now(), 'success');

        return redirect()->route('basicinformation.edit', $new_id);
    }

    //20240701新增Duplicate Collateral Info功能
    public function Duplicate_Collateral_Info($id) {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        $auditLogService = new AuditLogService();
        $flight = null;
        $new_id = BiogMain::max('c_personid') + 1;

        DB::transaction(function () use (&$flight, $id, $new_id, $auditLogService) {
            $data = BiogMain::find($id)->toArray();
            $data['c_personid'] = $new_id;
            $data = $this->toolRepository->timestamp($data, true); //建檔資訊
            $data['c_modified_by'] = $data['c_modified_date'] = '';

            $flight = BiogMain::create($data);
            $operation = $this->operationRepository->store(Auth::id(), $new_id, 1, 'BIOG_MAIN', $new_id, $data);

            $auditLogService->write(
                'BIOG_MAIN',
                'INSERT',
                ['c_personid' => $new_id],
                null,
                $flight->toArray(),
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );

            if (Schema::hasTable('CBDB__NAME_FTS')) {
                $this->nameSearchIndexService->reindexPerson($flight);
            }

            //擴充複製訊息：地址，出處，親屬，社會關係，社會機構，社會區分
            //地址
            $addr = DB::table('BIOG_ADDR_DATA')->where([
                ['c_personid', '=', $id],
            ])->get();
            foreach ($addr as $addr_data) {
                $addr_data = (array)$addr_data;
                $addr_data['c_personid'] = $new_id;
                $addr_data = Arr::except($addr_data, ['_token']);
                $addr_data = $this->toolRepository->timestamp($addr_data, true); //建檔資訊
                DB::table('BIOG_ADDR_DATA')->insert($addr_data);
                $operation = $this->operationRepository->store(Auth::id(), $new_id, 1, 'BIOG_ADDR_DATA', CompositePrimaryKey::buildStoredResourceId([
                    'c_personid' => $addr_data['c_personid'],
                    'c_addr_id' => $addr_data['c_addr_id'],
                    'c_addr_type' => $addr_data['c_addr_type'],
                    'c_sequence' => $addr_data['c_sequence'],
                ]), $addr_data);

                $auditLogService->write(
                    'BIOG_ADDR_DATA',
                    'INSERT',
                    [
                        'c_personid' => $addr_data['c_personid'],
                        'c_addr_id' => $addr_data['c_addr_id'],
                        'c_addr_type' => $addr_data['c_addr_type'],
                        'c_sequence' => $addr_data['c_sequence'],
                    ],
                    null,
                    $addr_data,
                    'user',
                    (string) Auth::id(),
                    $operation ? (string) $operation->id : null
                );
            }

            //出處
            $source = DB::table('BIOG_SOURCE_DATA')->where([
                ['c_personid', '=', $id],
            ])->get();
            foreach ($source as $source_data) {
                $source_data = (array)$source_data;
                $source_data['c_personid'] = $new_id;
                $source_data = Arr::except($source_data, ['_token']);
                DB::table('BIOG_SOURCE_DATA')->insert($source_data);
                $operation = $this->operationRepository->store(Auth::id(), $new_id, 1, 'BIOG_SOURCE_DATA', CompositePrimaryKey::buildStoredResourceId([
                    'c_personid' => $source_data['c_personid'],
                    'c_textid' => $source_data['c_textid'],
                    'c_pages' => $source_data['c_pages'],
                ]), $source_data);

                $auditLogService->write(
                    'BIOG_SOURCE_DATA',
                    'INSERT',
                    [
                        'c_personid' => $source_data['c_personid'],
                        'c_textid' => $source_data['c_textid'],
                        'c_pages' => $source_data['c_pages'],
                    ],
                    null,
                    $source_data,
                    'user',
                    (string) Auth::id(),
                    $operation ? (string) $operation->id : null
                );
            }

            //親屬
            $kin = DB::table('KIN_DATA')->where([
                ['c_personid', '=', $id],
            ])->get();
            foreach ($kin as $kin_data) {
                $kin_data = (array)$kin_data;
                $kin_data['c_personid'] = $new_id;
                $kin_data = $this->toolRepository->timestamp($kin_data, true); //建檔資訊
                DB::table('KIN_DATA')->insert($kin_data);
                $operation = $this->operationRepository->store(Auth::id(), $new_id, 1, 'KIN_DATA', CompositePrimaryKey::buildStoredResourceId([
                    'c_personid' => $kin_data['c_personid'],
                    'c_kin_id' => $kin_data['c_kin_id'],
                    'c_kin_code' => $kin_data['c_kin_code'],
                ]), $kin_data);

                $auditLogService->write(
                    'KIN_DATA',
                    'INSERT',
                    [
                        'c_personid' => $kin_data['c_personid'],
                        'c_kin_id' => $kin_data['c_kin_id'],
                        'c_kin_code' => $kin_data['c_kin_code'],
                    ],
                    null,
                    $kin_data,
                    'user',
                    (string) Auth::id(),
                    $operation ? (string) $operation->id : null
                );
            }

            $kin_pair = DB::table('KIN_DATA')->where([
                ['c_kin_id', '=', $id],
            ])->get();
            foreach ($kin_pair as $kin_data) {
                $kin_data = (array)$kin_data;
                $kin_pair_id = $kin_data['c_personid'];
                $kin_data['c_kin_id'] = $new_id;
                $kin_data = $this->toolRepository->timestamp($kin_data, true); //建檔資訊
                DB::table('KIN_DATA')->insert($kin_data);
                $operation = $this->operationRepository->store(Auth::id(), $kin_pair_id, 1, 'KIN_DATA', CompositePrimaryKey::buildStoredResourceId([
                    'c_personid' => $kin_data['c_personid'],
                    'c_kin_id' => $kin_data['c_kin_id'],
                    'c_kin_code' => $kin_data['c_kin_code'],
                ]), $kin_data);

                $auditLogService->write(
                    'KIN_DATA',
                    'INSERT',
                    [
                        'c_personid' => $kin_data['c_personid'],
                        'c_kin_id' => $kin_data['c_kin_id'],
                        'c_kin_code' => $kin_data['c_kin_code'],
                    ],
                    null,
                    $kin_data,
                    'user',
                    (string) Auth::id(),
                    $operation ? (string) $operation->id : null
                );
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
                $assoc_data = $this->toolRepository->timestamp($assoc_data, true); //建檔資訊
                DB::table('ASSOC_DATA')->insert($assoc_data);
                $operation = $this->operationRepository->store(Auth::id(), $new_id, 1, 'ASSOC_DATA', CompositePrimaryKey::buildStoredResourceId([
                    'c_personid' => $assoc_data['c_personid'],
                    'c_assoc_code' => $assoc_data['c_assoc_code'],
                    'c_assoc_id' => $assoc_data['c_assoc_id'],
                    'c_kin_code' => $assoc_data['c_kin_code'],
                    'c_kin_id' => $assoc_data['c_kin_id'],
                    'c_assoc_kin_code' => $assoc_data['c_assoc_kin_code'],
                    'c_assoc_kin_id' => $assoc_data['c_assoc_kin_id'],
                    'c_text_title' => $assoc_data['c_text_title'] ?? '',
                    'c_assoc_first_year' => $assoc_data['c_assoc_first_year'] ?? '-9999',
                ]), $assoc_data);

                $auditLogService->write(
                    'ASSOC_DATA',
                    'INSERT',
                    [
                        'c_personid' => $assoc_data['c_personid'],
                        'c_assoc_code' => $assoc_data['c_assoc_code'],
                        'c_assoc_id' => $assoc_data['c_assoc_id'],
                        'c_kin_code' => $assoc_data['c_kin_code'],
                        'c_kin_id' => $assoc_data['c_kin_id'],
                        'c_assoc_kin_code' => $assoc_data['c_assoc_kin_code'],
                        'c_assoc_kin_id' => $assoc_data['c_assoc_kin_id'],
                        'c_text_title' => $assoc_data['c_text_title'] ?? '',
                        'c_assoc_first_year' => $assoc_data['c_assoc_first_year'] ?? '-9999',
                    ],
                    null,
                    $assoc_data,
                    'user',
                    (string) Auth::id(),
                    $operation ? (string) $operation->id : null
                );
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
                $assoc_data = $this->toolRepository->timestamp($assoc_data, true); //建檔資訊
                DB::table('ASSOC_DATA')->insert($assoc_data);
                $operation = $this->operationRepository->store(Auth::id(), $assoc_pair_id, 1, 'ASSOC_DATA', CompositePrimaryKey::buildStoredResourceId([
                    'c_personid' => $assoc_data['c_personid'],
                    'c_assoc_code' => $assoc_data['c_assoc_code'],
                    'c_assoc_id' => $assoc_data['c_assoc_id'],
                    'c_kin_code' => $assoc_data['c_kin_code'],
                    'c_kin_id' => $assoc_data['c_kin_id'],
                    'c_assoc_kin_code' => $assoc_data['c_assoc_kin_code'],
                    'c_assoc_kin_id' => $assoc_data['c_assoc_kin_id'],
                    'c_text_title' => $assoc_data['c_text_title'] ?? '',
                    'c_assoc_first_year' => $assoc_data['c_assoc_first_year'] ?? '-9999',
                ]), $assoc_data);

                $auditLogService->write(
                    'ASSOC_DATA',
                    'INSERT',
                    [
                        'c_personid' => $assoc_data['c_personid'],
                        'c_assoc_code' => $assoc_data['c_assoc_code'],
                        'c_assoc_id' => $assoc_data['c_assoc_id'],
                        'c_kin_code' => $assoc_data['c_kin_code'],
                        'c_kin_id' => $assoc_data['c_kin_id'],
                        'c_assoc_kin_code' => $assoc_data['c_assoc_kin_code'],
                        'c_assoc_kin_id' => $assoc_data['c_assoc_kin_id'],
                        'c_text_title' => $assoc_data['c_text_title'] ?? '',
                        'c_assoc_first_year' => $assoc_data['c_assoc_first_year'] ?? '-9999',
                    ],
                    null,
                    $assoc_data,
                    'user',
                    (string) Auth::id(),
                    $operation ? (string) $operation->id : null
                );
            }

            //社交機構
            $inst = DB::table('BIOG_INST_DATA')->where([
                ['c_personid', '=', $id],
            ])->get();
            foreach ($inst as $inst_data) {
                $inst_data = (array)$inst_data;
                $inst_data['c_personid'] = $new_id;
                $inst_data = Arr::except($inst_data, ['_token']);
                $inst_data = $this->toolRepository->timestamp($inst_data, true); //建檔資訊
                DB::table('BIOG_INST_DATA')->insert($inst_data);
                $operation = $this->operationRepository->store(Auth::id(), $new_id, 1, 'BIOG_INST_DATA', CompositePrimaryKey::buildStoredResourceId([
                    'c_personid' => $inst_data['c_personid'],
                    'c_inst_code' => $inst_data['c_inst_code'],
                    'c_inst_name_code' => $inst_data['c_inst_name_code'],
                    'c_bi_role_code' => $inst_data['c_bi_role_code'],
                ]), $inst_data);

                $auditLogService->write(
                    'BIOG_INST_DATA',
                    'INSERT',
                    [
                        'c_personid' => $inst_data['c_personid'],
                        'c_inst_code' => $inst_data['c_inst_code'],
                        'c_inst_name_code' => $inst_data['c_inst_name_code'],
                        'c_bi_role_code' => $inst_data['c_bi_role_code'],
                    ],
                    null,
                    $inst_data,
                    'user',
                    (string) Auth::id(),
                    $operation ? (string) $operation->id : null
                );
            }

            //社會區分
            $status = DB::table('STATUS_DATA')->where([
                ['c_personid', '=', $id],
            ])->get();
            foreach ($status as $status_data) {
                $status_data = (array)$status_data;
                $status_data['c_personid'] = $new_id;
                $status_data = Arr::except($status_data, ['_token']);
                $status_data = $this->toolRepository->timestamp($status_data, true); //建檔資訊
                DB::table('STATUS_DATA')->insert($status_data);
                $operation = $this->operationRepository->store(Auth::id(), $new_id, 1, 'STATUS_DATA', CompositePrimaryKey::buildStoredResourceId([
                    'c_personid' => $status_data['c_personid'],
                    'c_sequence' => $status_data['c_sequence'],
                    'c_status_code' => $status_data['c_status_code'],
                ]), $status_data);

                $auditLogService->write(
                    'STATUS_DATA',
                    'INSERT',
                    [
                        'c_personid' => $status_data['c_personid'],
                        'c_sequence' => $status_data['c_sequence'],
                        'c_status_code' => $status_data['c_status_code'],
                    ],
                    null,
                    $status_data,
                    'user',
                    (string) Auth::id(),
                    $operation ? (string) $operation->id : null
                );
            }
        });

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
    public function destroy($id) {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->isActive()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        $ori = $this->biogMainRepository->byPersonId($id);
        if (!$ori) {
            abort(404);
        }
        $biog = BiogMain::find($id);
        $biog->c_name_chn = '<待删除>';

        //20190605判別是否為眾包用戶
        if (Auth::user()->isCrowdsourcingUser()) {
            $this->operationRepository->store(Auth::id(), $id, 4, 'BIOG_MAIN', $id, $biog, $ori, 2);
            flash('眾包紀錄 Delete success @ '.Carbon::now(), 'success');

            return redirect()->route('basicinformation.index');
        } else {
            DB::transaction(function () use ($biog, $id, $ori) {
                $biog->save();
                $operation = $this->operationRepository->store(Auth::id(), $id, 4, 'BIOG_MAIN', $id, $biog, $ori);

                (new AuditLogService())->write(
                    'BIOG_MAIN',
                    'UPDATE',
                    ['c_personid' => $id],
                    $ori->toArray(),
                    $biog->toArray(),
                    'user',
                    (string) Auth::id(),
                    $operation ? (string) $operation->id : null
                );

                if (Schema::hasTable('CBDB__NAME_FTS')) {
                    $this->nameSearchIndexService->reindexPerson($biog);
                }
            });

            flash('Delete success @ '.Carbon::now(), 'success');

            return redirect()->route('basicinformation.index');
        }
    }
}
