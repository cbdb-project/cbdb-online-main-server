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
use App\Services\BracketNormalizer;
use App\Services\NameSearchIndexService;
use App\Services\PersonBrowserService;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

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
    protected $personBrowserService;

    /**
     * Create a new controller instance.
     *
     * @param BiogMainRepository $biogMainRepository
     */
    public function __construct(BiogMainRepository $biogMainRepository, EthnicityRepository $ethnicityRepository, DynastyRepository $dynastyRepository, NianHaoRepository $nianHaoRepository, ChoronymRepository $choronymRepository, YearRangeRepository $yearRangeRepository, ToolsRepository $toolsRepository, OperationRepository $operationRepository, NameSearchIndexService $nameSearchIndexService, PersonBrowserService $personBrowserService) {
        $this->biogMainRepository = $biogMainRepository;
        $this->ethnicityRepository = $ethnicityRepository;
        $this->dynastyRepository = $dynastyRepository;
        $this->nianhaoRepository = $nianHaoRepository;
        $this->choronymRepository = $choronymRepository;
        $this->yearRangeRepository = $yearRangeRepository;
        $this->operationRepository = $operationRepository;
        $this->toolRepository = $toolsRepository;
        $this->nameSearchIndexService = $nameSearchIndexService;
        $this->personBrowserService = $personBrowserService;
        // 檢視類路由（含 React appShow/appEdit 與其 summary/tab JSON 端點）可公開；
        // 實際寫入由 /api/v2 handler 授權把關。summary/tab 與舊唯讀人物頁同樣公開，
        // 確保訪客也能檢視（編輯能力由 canEditBasicInfo + 後端 v2 授權決定）。
        $this->middleware('auth')->except(['index', 'show', 'edit', 'appIndex', 'appShow', 'appEdit', 'summary', 'tab']);
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
        $q = trim((string) ($request->input('q') ?? ''));
        $num = $request->input('num', 20);
        $cDyInput = $request->input('c_dy');
        $cDy = $cDyInput === null ? '' : trim((string) $cDyInput);

        // 如果有搜尋關鍵字，統計朝代分佈（用於篩選下拉選單）
        $dynastyFacets = [];
        if ($q !== '') {
            $dynastyFacets = BiogMainRepository::dynastyFacetsByQuery($q);
        }

        // 驗證 c_dy 是否在當前查詢的朝代分佈中；若不存在則 redirect 到不帶 c_dy 的乾淨 URL
        if ($cDy !== null && $cDy !== '' && !empty($dynastyFacets)) {
            $validDynasties = collect($dynastyFacets)->pluck('c_dy')->map(fn ($v) => (string) $v)->toArray();
            if (!in_array((string) $cDy, $validDynasties, true)) {
                $params = $request->only(['q', 'num']);

                return redirect()->route('basicinformation.index', array_filter($params, fn ($v) => $v !== null && $v !== ''));
            }
        }

        // 使用 Repository 查询数据
        $names = $this->biogMainRepository->namesByQuery($request, $num);

        return view('biogmains.basicinformation.index', [
            'page_title' => __('person.person_records'),
            'page_description' => __('person.person_records'),
            'names' => $names,
            'q' => $q,
            'c_dy' => $cDy,
            'dynastyFacets' => $dynastyFacets,
        ]);
    }

    /**
     * Inertia + React 版：人物列表（實質首頁）。授權/查詢邏輯與 Blade index 一致。
     */
    public function appIndex(Request $request) {
        $q = trim((string) ($request->input('q') ?? ''));
        $num = $request->input('num', 20);
        $cDyInput = $request->input('c_dy');
        $cDy = $cDyInput === null ? '' : trim((string) $cDyInput);

        $dynastyFacets = $q !== '' ? BiogMainRepository::dynastyFacetsByQuery($q) : [];

        // c_dy 不在當前查詢的朝代分佈中 → 重導乾淨 URL（與 Blade 同邏輯，導向 app 路由）。
        if ($cDy !== '' && !empty($dynastyFacets)) {
            $validDynasties = collect($dynastyFacets)->pluck('c_dy')->map(fn ($v) => (string) $v)->toArray();
            if (!in_array((string) $cDy, $validDynasties, true)) {
                $params = $request->only(['q', 'num']);

                return redirect()->route('app.basicinformation.index', array_filter($params, fn ($v) => $v !== null && $v !== ''));
            }
        }

        $names = $this->biogMainRepository->namesByQuery($request, $num);

        $rows = array_map(fn ($item) => [
            'c_personid' => $item->c_personid,
            'c_name_chn' => $item->c_name_chn,
            'c_name' => $item->c_name,
            'c_dynasty_chn' => $item->c_dynasty_chn ?? '',
            'c_index_year' => $item->c_index_year,
            'addr_name_chn' => $item->ADDR_c_name_chn ?? '',
            'zi' => $item->c_alt_name_chn_zi ?? '',
            'hao' => $item->c_alt_name_chn_hao ?? '',
        ], $names->items());

        $editIsNew = migration_flag_is_new('basicinformation.editor') && Route::has('app.basicinformation.edit');

        return Inertia::render('BasicInformation/Index', [
            'names' => [
                'data' => $rows,
                'meta' => [
                    'current_page' => $names->currentPage(),
                    'last_page' => $names->lastPage(),
                    'per_page' => $names->perPage(),
                    'total' => $names->total(),
                    'from' => $names->firstItem(),
                    'to' => $names->lastItem(),
                ],
            ],
            'q' => $q,
            'c_dy' => $cDy,
            'dynasty_facets' => array_map(fn ($f) => [
                'c_dy' => $f->c_dy,
                'c_dynasty_chn' => $f->c_dynasty_chn,
                'count' => $f->count,
            ], is_array($dynastyFacets) ? $dynastyFacets : $dynastyFacets->all()),
            'can_add' => Auth::check() && Auth::user()->isActive(),
            // 人物編輯器仍為 Blade（Phase 4，受 F7 硬前置）；連結模板 flag-aware。
            'edit_template' => $editIsNew
                ? route('app.basicinformation.edit', ['id' => '__ID__'], false)
                : route('basicinformation.edit', ['basicinformation' => '__ID__'], false),
            'create_url' => route('basicinformation.create', [], false),
            'page_translations' => [
                'biogmains' => is_array($t = trans('biogmains')) ? $t : [],
                'person' => is_array($t = trans('person')) ? $t : [],
            ],
        ]);
    }

    /**
     * 共用：組出 React 編輯/檢視頁所需的 person props（sections + form + 摘要標籤）。
     * sections/form 與 PersonBrowser basic_info 分頁同源（PersonBrowserService::tabData），
     * BasicInfoView 可直接消費；不存在則 404。
     *
     * @return array{0: array, 1: string}  [props, personLabel]
     */
    protected function buildPersonViewProps(int $personId): array {
        $basic = $this->personBrowserService->tabData($personId, 'basic_info');

        if ($basic === null || empty($basic['sections'])) {
            abort(404);
        }

        // 名稱標籤直接從 BIOG_MAIN 取，避免耦合 summary() 的完整欄位需求。
        $nameRow = DB::table('BIOG_MAIN')
            ->where('c_personid', $personId)
            ->first(['c_name_chn', 'c_name']);
        $nameChn = $nameRow->c_name_chn ?? '';
        $name = $nameRow->c_name ?? '';

        $personLabel = (string) $personId;
        if ($nameChn || $name) {
            $personLabel .= ' - ' . $nameChn;
            if ($name) {
                $personLabel .= ' (' . $name . ')';
            }
        }

        return [
            [
                'person_id' => $personId,
                'sections' => $basic['sections'] ?? [],
                'form' => $basic['form'] ?? null,
                'name_chn' => $nameChn,
                'name' => $name,
            ],
            $personLabel,
        ];
    }

    /**
     * 端點與授權旗標（Edit/Show 共用）。實際寫入授權由 /api/v2 handler 把關，
     * 前端 can_* 僅控制 UI 顯示。
     *
     * @return array<string, mixed>
     */
    protected function personEditorMeta(): array {
        $user = Auth::user();

        return [
            'can_edit' => $user ? ($user->isActive() && $user->canWriteDirectly()) : false,
            // 可提案但不可直接寫入者（眾包用戶）亦可送 update 提案；BIOG_MAIN create/delete 提案
            // v2 尚未支援（回 501），故前端對 proposal-only 用戶隱藏新增/刪除入口。
            'can_propose' => $user ? $user->canPropose() : false,
            'mutate_endpoint' => route('api.v2.mutate.web', [], false),
            'delete_endpoint' => route('api.v2.delete.web', [], false),
            'pinyin_endpoint' => '/api/select/search/pinyin',
            'index_url' => migration_flag_is_new('basicinformation.index') && Route::has('app.basicinformation.index')
                ? route('app.basicinformation.index', [], false)
                : route('basicinformation.index', [], false),
        ];
    }

    /**
     * Inertia + React 版：人物編輯主界面（PersonEditor，對齊舊 /basicinformation/{id} 的
     * 13 分頁高效錄入界面）。複用 PersonBrowser 的 BrowserTabs + TabContentLoader，但聚焦
     * 單一人物（無搜尋 sidebar）、basic_info 分頁進場即可錄入、12 子資源分頁各帶 React 編輯器。
     * 頁面本身可公開載入（檢視/可編輯由 can_edit 與 /api/v2 handler 把關）。
     */
    public function appEdit($id) {
        return $this->renderPersonEditor($id);
    }

    /**
     * Task 27 重做：對齊 legacy /basicinformation/{id}/edit 的 React 基本資料編輯器（BasicInfoEditor）。
     * 把 form.fields（富物件）攤平成 BasicInfoEditor 需要的 initial_fields（c_* → 原始值字串）
     * 與 initial_labels（c_* → 顯示標籤）。獨立路由供逐步重做/端到端驗證，不受 flag 影響、不上線。
     */
    public function appEditV2($id) {
        $personId = $this->normalizePersonId($id);
        [$person, $personLabel] = $this->buildPersonViewProps($personId);

        $formFields = $person['form']['fields'] ?? [];
        $initialFields = [];
        $initialLabels = [];
        foreach ($formFields as $key => $f) {
            $initialFields[$key] = (string) ($f['value'] ?? '');
            if (isset($f['display_value']) && $f['display_value'] !== '') {
                $initialLabels[$key] = (string) $f['display_value'];
            }
        }

        $user = Auth::user();

        return Inertia::render('BasicInformation/EditV2', [
            'personId' => $personId,
            'person_label' => $personLabel,
            'initial_fields' => (object) $initialFields,
            'initial_labels' => (object) $initialLabels,
            'can_edit' => $user ? ($user->isActive() && $user->canWriteDirectly()) : false,
            'can_propose' => $user ? $user->canPropose() : false,
            'mutate_endpoint' => route('api.v2.mutate.web', [], false),
            'delete_endpoint' => route('api.v2.delete.web', [], false),
            'pinyin_endpoint' => '/api/select/search/pinyin',
            'index_url' => migration_flag_is_new('basicinformation.index') && Route::has('app.basicinformation.index')
                ? route('app.basicinformation.index', [], false)
                : route('basicinformation.index', [], false),
            'duplicate_collateral_url' => "/basicinformation/{$personId}/Duplicate_Collateral_Info",
            'saveas_url' => "/basicinformation/{$personId}/saveas",
        ]);
    }

    /**
     * Inertia + React 版：地址編輯器（對齊 legacy biogmains/addresses/_form）。獨立測試路由，
     * flag 仍 old、不上線。有 c_addr_id+c_addr_type+c_sequence 即編輯，否則新增。
     */
    public function appAddressEditV2(Request $request, $id) {
        $personId = $this->normalizePersonId($id);
        [, $personLabel] = $this->buildPersonViewProps($personId);

        $person = BiogMain::find($personId);
        $cDy = $person ? (int) $person->c_dy : 0;
        $dynasty = $cDy ? DB::table('DYNASTIES')->where('c_dy', $cDy)->first() : null;

        $hasPk = $request->filled('c_addr_id') && $request->filled('c_addr_type') && $request->filled('c_sequence');
        $mode = $hasPk ? 'edit' : 'create';

        $initialFields = ['c_personid' => (string) $personId];
        $initialLabels = [];
        $otherBelongs = '';
        if ($mode === 'edit') {
            $row = DB::table('BIOG_ADDR_DATA')->where([
                'c_personid' => $personId,
                'c_addr_id' => (int) $request->input('c_addr_id'),
                'c_addr_type' => (int) $request->input('c_addr_type'),
                'c_sequence' => (int) $request->input('c_sequence'),
            ])->first();
            if (!$row) {
                abort(404);
            }
            foreach ((array) $row as $k => $v) {
                $initialFields[$k] = $v === null ? '' : (string) $v;
            }

            // 對齊 legacy：c_addr_id（地名）與 c_source（出處）為非同步搜尋欄位，
            // 無 label 將顯示空白，故於編輯模式補回 addr_str / text_str；並補回 other_belongs 顯示。
            try {
                $addrCode = DB::table('ADDR_CODES')->where('c_addr_id', (int) $row->c_addr_id)->first();
                if ($addrCode) {
                    $belongs = DB::table('ADDR_BELONGS_DATA')->where('c_addr_id', (int) $row->c_addr_id)->get();
                    $belongLabels = [];
                    foreach ($belongs as $b) {
                        $parent = DB::table('ADDR_CODES')->where('c_addr_id', (int) $b->c_belongs_to)->first();
                        if ($parent) {
                            $belongLabels[] = '[['.$parent->c_addr_id.' '.$parent->c_name_chn.' '.$parent->c_firstyear.'~'.$parent->c_lastyear.']]';
                        }
                    }
                    $base = trim($addrCode->c_addr_id.' '.$addrCode->c_name.' '.$addrCode->c_name_chn.' '.$addrCode->c_firstyear.'~'.$addrCode->c_lastyear);
                    $initialLabels['c_addr_id'] = trim($base.' '.($belongLabels[0] ?? ''));
                    if (count($belongLabels) > 1) {
                        $otherBelongs = implode('、', array_slice($belongLabels, 1));
                    }
                }
                if ($row->c_source !== null && $row->c_source !== '') {
                    $textCode = DB::table('TEXT_CODES')->where('c_textid', (int) $row->c_source)->first();
                    if ($textCode) {
                        $initialLabels['c_source'] = trim($textCode->c_textid.' '.$textCode->c_title.' '.$textCode->c_title_chn);
                    }
                }
            } catch (\Throwable $e) {
                // 標籤補水失敗不影響編輯主流程（例如測試環境缺碼表）
            }
        }

        $user = Auth::user();

        return Inertia::render('BasicInformation/AddressEditV2', [
            'person_id' => $personId,
            'person_label' => $personLabel,
            'dynasty_code' => $cDy ?: null,
            'dynasty_start' => (string) ($dynasty->c_start ?? ''),
            'dynasty_end' => (string) ($dynasty->c_end ?? ''),
            'edit_mode' => $mode,
            'initial_fields' => (object) $initialFields,
            'initial_labels' => (object) $initialLabels,
            'other_belongs' => $otherBelongs,
            'can_edit' => $user ? ($user->isActive() && $user->canWriteDirectly()) : false,
            'can_propose' => $user ? $user->canPropose() : false,
            'create_endpoint' => route('api.v2.create.web', [], false),
            'mutate_endpoint' => route('api.v2.mutate.web', [], false),
            'delete_endpoint' => route('api.v2.delete.web', [], false),
            'index_url' => route('basicinformation.addresses.index', ['basicinformation' => $personId], false),
        ]);
    }

    /**
     * Inertia + React 版：著述（texts）編輯器（對齊 legacy biogmains/texts/_form）。獨立測試路由，
     * flag 仍 old、不上線。有 c_textid+c_role_id 即編輯，否則新增。
     */
    public function appTextEditV2(Request $request, $id) {
        $personId = $this->normalizePersonId($id);
        [, $personLabel] = $this->buildPersonViewProps($personId);

        $hasPk = $request->filled('c_textid') && $request->filled('c_role_id');
        $mode = $hasPk ? 'edit' : 'create';

        $initialFields = ['c_personid' => (string) $personId];
        $initialLabels = [];
        if ($mode === 'edit') {
            $textid = (int) $request->input('c_textid');
            $roleId = (int) $request->input('c_role_id');
            $row = DB::table('BIOG_TEXT_DATA')->where([
                'c_personid' => $personId,
                'c_textid' => $textid,
                'c_role_id' => $roleId,
            ])->first();
            if (!$row) {
                abort(404);
            }
            foreach ((array) $row as $k => $v) {
                $initialFields[$k] = $v === null ? '' : (string) $v;
            }

            // 對齊 legacy textById：c_textid（著述）與 c_source（出處）為非同步搜尋欄位，
            // 無 label 會顯示空白，故補回 res['text'] / res['text_str']。
            try {
                $res = $this->biogMainRepository->textById($personId.'-'.$textid.'-'.$roleId);
                if (!empty($res['text'])) {
                    $initialLabels['c_textid'] = trim($res['text']);
                }
                if (!empty($res['text_str'])) {
                    $initialLabels['c_source'] = trim($res['text_str']);
                }
            } catch (\Throwable $e) {
                // label 補水失敗不影響編輯主流程
            }
        }

        $user = Auth::user();

        return Inertia::render('BasicInformation/TextEditV2', [
            'person_id' => $personId,
            'person_label' => $personLabel,
            'edit_mode' => $mode,
            'initial_fields' => (object) $initialFields,
            'initial_labels' => (object) $initialLabels,
            'can_edit' => $user ? ($user->isActive() && $user->canWriteDirectly()) : false,
            'can_propose' => $user ? $user->canPropose() : false,
            'create_endpoint' => route('api.v2.create.web', [], false),
            'mutate_endpoint' => route('api.v2.mutate.web', [], false),
            'delete_endpoint' => route('api.v2.delete.web', [], false),
            'index_url' => route('basicinformation.texts.index', ['basicinformation' => $personId], false),
        ]);
    }

    /**
     * Inertia + React 版：別名（altname）編輯器（對齊 legacy biogmains/altname/_form）。
     * 主鍵 (c_personid, c_alt_name_chn[字串], c_alt_name_type_code)；獨立測試路由、未上線。
     */
    public function appAltnameEditV2(Request $request, $id) {
        $personId = $this->normalizePersonId($id);
        [, $personLabel] = $this->buildPersonViewProps($personId);

        $hasPk = $request->filled('c_alt_name_chn') && $request->filled('c_alt_name_type_code');
        $mode = $hasPk ? 'edit' : 'create';

        $initialFields = ['c_personid' => (string) $personId];
        $initialLabels = [];
        if ($mode === 'edit') {
            $altNameChn = (string) $request->input('c_alt_name_chn');
            $typeCode = (int) $request->input('c_alt_name_type_code');
            $row = DB::table('ALTNAME_DATA')->where([
                'c_personid' => $personId,
                'c_alt_name_chn' => $altNameChn,
                'c_alt_name_type_code' => $typeCode,
            ])->first();
            if (!$row) {
                abort(404);
            }
            foreach ((array) $row as $k => $v) {
                $initialFields[$k] = $v === null ? '' : (string) $v;
            }

            // c_source（出處）為非同步搜尋欄位，補回顯示標籤（失敗不影響編輯）。
            try {
                $sourceId = (int) ($initialFields['c_source'] ?? 0);
                if ($sourceId > 0) {
                    $text = DB::table('TEXT_CODES')->where('c_textid', $sourceId)->first();
                    if ($text) {
                        $initialLabels['c_source'] = trim((string) ($text->c_title_chn ?? $text->c_title ?? $sourceId));
                    }
                }
            } catch (\Throwable $e) {
                // label 補水失敗不影響編輯主流程
            }
        }

        $user = Auth::user();

        return Inertia::render('BasicInformation/AltnameEditV2', [
            'person_id' => $personId,
            'person_label' => $personLabel,
            'edit_mode' => $mode,
            'initial_fields' => (object) $initialFields,
            'initial_labels' => (object) $initialLabels,
            'can_edit' => $user ? ($user->isActive() && $user->canWriteDirectly()) : false,
            'can_propose' => $user ? $user->canPropose() : false,
            'create_endpoint' => route('api.v2.create.web', [], false),
            'mutate_endpoint' => route('api.v2.mutate.web', [], false),
            'delete_endpoint' => route('api.v2.delete.web', [], false),
            'index_url' => route('basicinformation.altnames.index', ['basicinformation' => $personId], false),
        ]);
    }

    /**
     * Inertia + React 版：社會機構（socialinst）編輯器（對齊 legacy biogmains/socialinst/_form）。
     * 主鍵 (c_personid, c_inst_code, c_inst_name_code, c_bi_role_code)；獨立測試路由、flag 仍 old、不上線。
     */
    public function appSocialinstEditV2(Request $request, $id) {
        $personId = $this->normalizePersonId($id);
        [, $personLabel] = $this->buildPersonViewProps($personId);

        $person = BiogMain::find($personId);
        $cDy = $person ? (int) $person->c_dy : 0;

        $hasPk = $request->filled('c_inst_code') && $request->filled('c_inst_name_code') && $request->filled('c_bi_role_code');
        $mode = $hasPk ? 'edit' : 'create';

        $initialFields = ['c_personid' => (string) $personId];
        $initialLabels = [];
        if ($mode === 'edit') {
            $instCode = (int) $request->input('c_inst_code');
            $instNameCode = (int) $request->input('c_inst_name_code');
            $roleCode = (int) $request->input('c_bi_role_code');
            $row = DB::table('BIOG_INST_DATA')->where([
                'c_personid' => $personId,
                'c_inst_code' => $instCode,
                'c_inst_name_code' => $instNameCode,
                'c_bi_role_code' => $roleCode,
            ])->first();
            if (!$row) {
                abort(404);
            }
            foreach ((array) $row as $k => $v) {
                $initialFields[$k] = $v === null ? '' : (string) $v;
            }

            // c_inst_code（合併搜尋）與 c_source（出處）為非同步搜尋欄位，補回顯示標籤；
            // c_bi_role_code 為 list 模式自行解析。補水失敗不影響編輯。
            try {
                $res = $this->biogMainRepository->socialInstById($personId.'-'.$instCode.'-'.$instNameCode.'-'.$roleCode);
                if (!empty($res['inst_code'])) {
                    $initialLabels['c_inst_code'] = trim($res['inst_code']);
                }
                if (!empty($res['text_str'])) {
                    $initialLabels['c_source'] = trim($res['text_str']);
                }
            } catch (\Throwable $e) {
                // label 補水失敗不影響編輯主流程
            }
        }

        $user = Auth::user();

        return Inertia::render('BasicInformation/SocialInstEditV2', [
            'person_id' => $personId,
            'person_label' => $personLabel,
            'dynasty_code' => $cDy ?: null,
            'edit_mode' => $mode,
            'initial_fields' => (object) $initialFields,
            'initial_labels' => (object) $initialLabels,
            'can_edit' => $user ? ($user->isActive() && $user->canWriteDirectly()) : false,
            'can_propose' => $user ? $user->canPropose() : false,
            'create_endpoint' => route('api.v2.create.web', [], false),
            'mutate_endpoint' => route('api.v2.mutate.web', [], false),
            'delete_endpoint' => route('api.v2.delete.web', [], false),
            'index_url' => route('basicinformation.socialinst.index', ['basicinformation' => $personId], false),
        ]);
    }

    /**
     * Inertia + React 版：占有／財產（possession）編輯器（對齊 legacy biogmains/possession/_form）。
     * 主鍵 c_possession_record_id 為伺服器配發 surrogate；新增由 PossessionCreateHandler 配發 + 寫 POSSESSION_ADDR。
     * 地址副表（c_addr_id 多選）僅於新增可編輯；編輯模式為唯讀（v2 update 尚未支援副表，標 TODO）。
     */
    public function appPossessionEditV2(Request $request, $id) {
        $personId = $this->normalizePersonId($id);
        [, $personLabel] = $this->buildPersonViewProps($personId);

        $person = BiogMain::find($personId);
        $cDy = $person ? (int) $person->c_dy : 0;
        $dynasty = $cDy ? DB::table('DYNASTIES')->where('c_dy', $cDy)->first() : null;

        $hasPk = $request->filled('c_possession_record_id');
        $mode = $hasPk ? 'edit' : 'create';

        $initialFields = ['c_personid' => (string) $personId];
        $initialLabels = [];
        $initialAddr = [];
        if ($mode === 'edit') {
            $recordId = (int) $request->input('c_possession_record_id');
            $row = DB::table('POSSESSION_DATA')
                ->where('c_possession_record_id', $recordId)
                ->where('c_personid', $personId)
                ->first();
            if (!$row) {
                abort(404);
            }
            foreach ((array) $row as $k => $v) {
                $initialFields[$k] = $v === null ? '' : (string) $v;
            }

            // c_source（出處）為非同步搜尋欄位，補回顯示標籤；地址副表補回供唯讀顯示。
            // c_measure_code / c_possession_act_code 為 list 模式自行解析。補水失敗不影響編輯。
            try {
                $res = $this->biogMainRepository->possessionById($recordId);
                if (!empty($res['text_str'])) {
                    $initialLabels['c_source'] = trim($res['text_str']);
                }
                foreach (($res['addr_str'] ?? []) as $item) {
                    $initialAddr[] = ['id' => (string) $item[0], 'label' => (string) $item[1]];
                }
            } catch (\Throwable $e) {
                // label 補水失敗不影響編輯主流程
            }
        }

        $user = Auth::user();

        return Inertia::render('BasicInformation/PossessionEditV2', [
            'person_id' => $personId,
            'person_label' => $personLabel,
            'dynasty_code' => $cDy ?: null,
            'dynasty_start' => (string) ($dynasty->c_start ?? ''),
            'dynasty_end' => (string) ($dynasty->c_end ?? ''),
            'edit_mode' => $mode,
            'initial_fields' => (object) $initialFields,
            'initial_labels' => (object) $initialLabels,
            'initial_addr' => $initialAddr,
            'can_edit' => $user ? ($user->isActive() && $user->canWriteDirectly()) : false,
            'can_propose' => $user ? $user->canPropose() : false,
            'create_endpoint' => route('api.v2.create.web', [], false),
            'mutate_endpoint' => route('api.v2.mutate.web', [], false),
            'delete_endpoint' => route('api.v2.delete.web', [], false),
            'index_url' => route('basicinformation.possession.index', ['basicinformation' => $personId], false),
        ]);
    }

    /**
     * Inertia + React 版：事件（events）編輯器（對齊 legacy biogmains/events/_form）。
     * 邏輯主鍵 (c_personid, c_sequence, c_event_code)；含農曆年份（干支日 c_day_ganzhi）。
     * 地址（EVENTS_ADDR 副表）v2 尚未支援，編輯器唯讀顯示並標 TODO。獨立測試路由、flag 仍 old、不上線。
     */
    public function appEventEditV2(Request $request, $id) {
        $personId = $this->normalizePersonId($id);
        [, $personLabel] = $this->buildPersonViewProps($personId);

        $person = BiogMain::find($personId);
        $cDy = $person ? (int) $person->c_dy : 0;

        $hasPk = $request->filled('c_sequence') && $request->filled('c_event_code');
        $mode = $hasPk ? 'edit' : 'create';

        $initialFields = ['c_personid' => (string) $personId];
        $initialLabels = [];
        $initialAddr = [];
        if ($mode === 'edit') {
            $sequence = (int) $request->input('c_sequence');
            $eventCode = (int) $request->input('c_event_code');
            $row = DB::table('EVENTS_DATA')->where([
                'c_personid' => $personId,
                'c_sequence' => $sequence,
                'c_event_code' => $eventCode,
            ])->first();
            if (!$row) {
                abort(404);
            }
            foreach ((array) $row as $k => $v) {
                $initialFields[$k] = $v === null ? '' : (string) $v;
            }

            // c_event_code（事件搜尋）與 c_source（出處）為非同步搜尋欄位，補回顯示標籤；
            // 地址副表補回供唯讀顯示。補水失敗不影響編輯主流程。
            try {
                $res = app(\App\Repositories\EventStatusRepository::class)->eventById($personId.'-'.$sequence.'-'.$eventCode);
                if (!empty($res['event_str'])) {
                    $initialLabels['c_event_code'] = trim($res['event_str']);
                }
                if (!empty($res['text_str'])) {
                    $initialLabels['c_source'] = trim($res['text_str']);
                }
                foreach (($res['addr_str'] ?? []) as $item) {
                    $initialAddr[] = ['id' => (string) $item[0], 'label' => (string) $item[1]];
                }
            } catch (\Throwable $e) {
                // label 補水失敗不影響編輯主流程
            }
        }

        $user = Auth::user();

        return Inertia::render('BasicInformation/EventEditV2', [
            'person_id' => $personId,
            'person_label' => $personLabel,
            'dynasty_code' => $cDy ?: null,
            'edit_mode' => $mode,
            'initial_fields' => (object) $initialFields,
            'initial_labels' => (object) $initialLabels,
            'initial_addr' => $initialAddr,
            'can_edit' => $user ? ($user->isActive() && $user->canWriteDirectly()) : false,
            'can_propose' => $user ? $user->canPropose() : false,
            'create_endpoint' => route('api.v2.create.web', [], false),
            'mutate_endpoint' => route('api.v2.mutate.web', [], false),
            'delete_endpoint' => route('api.v2.delete.web', [], false),
            'index_url' => route('basicinformation.events.index', ['basicinformation' => $personId], false),
        ]);
    }

    /**
     * Inertia + React 版：入仕（entries）編輯器（對齊 legacy biogmains/entries/_form）。獨立測試路由，
     * flag 仍 old、不上線。10 段複合主鍵；有 c_entry_code+c_sequence+c_kin_code+c_assoc_code+
     * c_kin_id+c_year+c_assoc_id+c_inst_code+c_inst_name_code 即編輯，否則新增。
     */
    public function appEntriesEditV2(Request $request, $id) {
        $personId = $this->normalizePersonId($id);
        [, $personLabel] = $this->buildPersonViewProps($personId);

        $person = BiogMain::find($personId);
        $cDy = $person ? (int) $person->c_dy : 0;
        $dynasty = $cDy ? DB::table('DYNASTIES')->where('c_dy', $cDy)->first() : null;

        // 10 段主鍵齊備才視為編輯。
        $pkKeys = ['c_entry_code', 'c_sequence', 'c_kin_code', 'c_assoc_code', 'c_kin_id', 'c_year', 'c_assoc_id', 'c_inst_code', 'c_inst_name_code'];
        $hasPk = collect($pkKeys)->every(fn ($k) => $request->has($k) && $request->input($k) !== '');
        $mode = $hasPk ? 'edit' : 'create';

        $initialFields = ['c_personid' => (string) $personId];
        $initialLabels = [];
        if ($mode === 'edit') {
            $where = ['c_personid' => $personId];
            foreach ($pkKeys as $k) {
                $where[$k] = (int) $request->input($k);
            }
            $row = DB::table('ENTRY_DATA')->where($where)->first();
            if (!$row) {
                abort(404);
            }
            foreach ((array) $row as $k => $v) {
                $initialFields[$k] = $v === null ? '' : (string) $v;
            }

            // 各非同步搜尋欄位補回顯示標籤（補水失敗不影響編輯主流程，僅顯示空白）。
            try {
                $entryCode = (int) $row->c_entry_code;
                if ($entryCode) {
                    $c = DB::table('ENTRY_CODES')->where('c_entry_code', $entryCode)->first();
                    if ($c) {
                        $initialLabels['c_entry_code'] = trim($entryCode.' '.($c->c_entry_desc_chn ?? '').' '.($c->c_entry_desc ?? ''));
                    }
                }
                $kinCode = (int) $row->c_kin_code;
                if ($kinCode) {
                    $c = DB::table('KINSHIP_CODES')->where('c_kincode', $kinCode)->first();
                    if ($c) {
                        $initialLabels['c_kin_code'] = trim($kinCode.' '.($c->c_kinrel_chn ?? '').' '.($c->c_kinrel ?? ''));
                    }
                }
                $assocCode = (int) $row->c_assoc_code;
                if ($assocCode) {
                    $c = DB::table('ASSOC_CODES')->where('c_assoc_code', $assocCode)->first();
                    if ($c) {
                        $initialLabels['c_assoc_code'] = trim($assocCode.' '.($c->c_assoc_desc_chn ?? '').' '.($c->c_assoc_desc ?? ''));
                    }
                }
                foreach (['c_kin_id' => (int) $row->c_kin_id, 'c_assoc_id' => (int) $row->c_assoc_id] as $field => $pid) {
                    if ($pid) {
                        $b = DB::table('BIOG_MAIN')->where('c_personid', $pid)->first();
                        if ($b) {
                            $initialLabels[$field] = trim($pid.' '.($b->c_name_chn ?? '').' '.($b->c_name ?? ''));
                        }
                    }
                }
                $instCode = (int) $row->c_inst_code;
                $instNameCode = (int) $row->c_inst_name_code;
                if ($instCode || $instNameCode) {
                    $n = DB::table('SOCIAL_INSTITUTION_NAME_CODES')->where('c_inst_name_code', $instNameCode)->first();
                    $initialLabels['c_inst_code'] = trim($instCode.'-'.$instNameCode.' '.($n->c_inst_name_hz ?? ''));
                }
                if ((int) ($row->c_entry_addr_id ?? 0)) {
                    $a = DB::table('ADDR_CODES')->where('c_addr_id', (int) $row->c_entry_addr_id)->first();
                    if ($a) {
                        $initialLabels['c_entry_addr_id'] = trim($a->c_addr_id.' '.($a->c_name ?? '').' '.($a->c_name_chn ?? '').' '.($a->c_firstyear ?? '').'~'.($a->c_lastyear ?? ''));
                    }
                }
                if ((int) ($row->c_source ?? 0)) {
                    $tx = DB::table('TEXT_CODES')->where('c_textid', (int) $row->c_source)->first();
                    if ($tx) {
                        $initialLabels['c_source'] = trim($tx->c_textid.' '.($tx->c_title ?? '').' '.($tx->c_title_chn ?? ''));
                    }
                }
            } catch (\Throwable $e) {
                // label 補水失敗不影響編輯主流程（例如測試環境缺碼表）
            }
        }

        $user = Auth::user();

        return Inertia::render('BasicInformation/EntriesEditV2', [
            'person_id' => $personId,
            'person_label' => $personLabel,
            'dynasty_code' => $cDy ?: null,
            'dynasty_start' => (string) ($dynasty->c_start ?? ''),
            'dynasty_end' => (string) ($dynasty->c_end ?? ''),
            'edit_mode' => $mode,
            'initial_fields' => (object) $initialFields,
            'initial_labels' => (object) $initialLabels,
            'can_edit' => $user ? ($user->isActive() && $user->canWriteDirectly()) : false,
            'can_propose' => $user ? $user->canPropose() : false,
            'create_endpoint' => route('api.v2.create.web', [], false),
            'mutate_endpoint' => route('api.v2.mutate.web', [], false),
            'delete_endpoint' => route('api.v2.delete.web', [], false),
            'index_url' => route('basicinformation.entries.index', ['basicinformation' => $personId], false),
        ]);
    }

    /**
     * Inertia + React 版：社會區分（statuses）編輯器 V2。
     * 對齊 legacy biogmains/statuses/_form.blade.php（含 AI 智能識別社會區分類別代碼）。
     * 獨立測試路由，statuses migration flag 維持 old、不上線。
     *
     * 主鍵 3 段（c_personid, c_sequence, c_status_code）。0 為合法主鍵段（c_status_code=0=未詳），
     * 故以 has() && input!=='' 判斷編輯/新增（不可用 (int) 後是否為 0 判斷）。
     */
    public function appStatusEditV2(Request $request, $id) {
        $personId = $this->normalizePersonId($id);
        [, $personLabel] = $this->buildPersonViewProps($personId);

        $person = BiogMain::find($personId);
        $cDy = $person ? (int) $person->c_dy : 0;

        // 主鍵齊備（含 c_sequence、c_status_code，0 為合法值）才視為編輯。
        $pkKeys = ['c_sequence', 'c_status_code'];
        $hasPk = collect($pkKeys)->every(fn ($k) => $request->has($k) && $request->input($k) !== '');
        $mode = $hasPk ? 'edit' : 'create';

        $initialFields = ['c_personid' => (string) $personId];
        $initialLabels = [];
        if ($mode === 'edit') {
            $where = ['c_personid' => $personId];
            foreach ($pkKeys as $k) {
                $where[$k] = (int) $request->input($k);
            }
            $row = DB::table('STATUS_DATA')->where($where)->first();
            if (!$row) {
                abort(404);
            }
            foreach ((array) $row as $k => $v) {
                $initialFields[$k] = $v === null ? '' : (string) $v;
            }

            // 非同步搜尋欄位補回顯示標籤（補水失敗不影響編輯主流程，僅顯示空白）。
            try {
                $statusCode = (int) $row->c_status_code;
                if ($statusCode) {
                    $c = DB::table('STATUS_CODES')->where('c_status_code', $statusCode)->first();
                    if ($c) {
                        $initialLabels['c_status_code'] = trim($statusCode . ' ' . ($c->c_status_desc_chn ?? '') . ' ' . ($c->c_status_desc ?? ''));
                    }
                }
                if ((int) ($row->c_source ?? 0)) {
                    $tx = DB::table('TEXT_CODES')->where('c_textid', (int) $row->c_source)->first();
                    if ($tx) {
                        $initialLabels['c_source'] = trim($tx->c_textid . ' ' . ($tx->c_title ?? '') . ' ' . ($tx->c_title_chn ?? ''));
                    }
                }
                // 年號（起/終）標籤補水。
                foreach (['c_fy_nh_code', 'c_ly_nh_code'] as $nhField) {
                    $nhId = (int) ($row->{$nhField} ?? 0);
                    if ($nhId) {
                        $nh = DB::table('NIAN_HAO')->where('c_nianhao_id', $nhId)->first();
                        if ($nh) {
                            $initialLabels[$nhField] = trim((string) ($nh->c_nianhao_chn ?? ''));
                        }
                    }
                }
                // 時限（起/終）標籤補水。
                foreach (['c_fy_range', 'c_ly_range'] as $rField) {
                    $rCode = (int) ($row->{$rField} ?? 0);
                    if ($rCode) {
                        $rg = DB::table('YEAR_RANGE_CODES')->where('c_range_code', $rCode)->first();
                        if ($rg) {
                            $initialLabels[$rField] = trim((string) ($rg->c_range_chn ?? ''));
                        }
                    }
                }
            } catch (\Throwable $e) {
                // label 補水失敗不影響編輯主流程（例如測試環境缺碼表）
            }
        }

        $user = Auth::user();
        $aiEnabled = (bool) config('services.gemini.api_key') && $user && $user->isActive();

        return Inertia::render('BasicInformation/StatusEditV2', [
            'person_id' => $personId,
            'person_label' => $personLabel,
            'dynasty_code' => $cDy ?: null,
            'edit_mode' => $mode,
            'initial_fields' => (object) $initialFields,
            'initial_labels' => (object) $initialLabels,
            'can_edit' => $user ? ($user->isActive() && $user->canWriteDirectly()) : false,
            'can_propose' => $user ? $user->canPropose() : false,
            'ai_enabled' => $aiEnabled,
            'ai_model' => (string) config('services.gemini.model', ''),
            'ai_suggest_endpoint' => route('ai.code-lookup.suggest', [], false),
            'create_endpoint' => route('api.v2.create.web', [], false),
            'mutate_endpoint' => route('api.v2.mutate.web', [], false),
            'delete_endpoint' => route('api.v2.delete.web', [], false),
            'index_url' => route('basicinformation.statuses.index', ['basicinformation' => $personId], false),
            'route_name' => 'app.basicinformation.statuses.editv2',
        ]);
    }

    /**
     * Inertia + React 版：著述出處（sources）編輯器 V2。
     * 對齊 legacy biogmains/sources/_form.blade.php（含維基資料來源警告）。
     * 獨立測試路由，sources migration flag 維持 old、不上線。
     *
     * 主鍵 3 段（c_personid, c_textid, c_pages）。c_pages 為 varchar 主鍵，哨兵為 ''（空字串）；
     * c_textid=0 為合法值。故以「c_textid 是否存在且非空」判斷編輯/新增（0 仍視為已選編輯目標），
     * c_pages 省略時視為 ''（對齊 BiogSourceRepository::normalizePk canonical 形式）。
     * 後端 SourceMutationHandler 在 update 模式視 PK 不可變（c_textid/c_pages 唯讀）。
     */
    public function appSourceEditV2(Request $request, $id) {
        $personId = $this->normalizePersonId($id);
        [, $personLabel] = $this->buildPersonViewProps($personId);

        // c_textid 存在且非空才視為編輯（0 為合法 textid，故不可用 (int) 是否為 0 判斷）。
        $hasPk = $request->has('c_textid') && $request->input('c_textid') !== '';
        $mode = $hasPk ? 'edit' : 'create';

        $initialFields = ['c_personid' => (string) $personId];
        $initialLabels = [];
        $isWikiSource = false;
        if ($mode === 'edit') {
            $textId = (int) $request->input('c_textid');
            // c_pages 省略時視為 ''（canonical），對齊 normalizePk。
            $pages = (string) $request->input('c_pages', '');
            $row = DB::table('BIOG_SOURCE_DATA')
                ->where('c_personid', $personId)
                ->where('c_textid', $textId)
                ->where('c_pages', $pages)
                ->first();
            if (!$row) {
                abort(404);
            }
            foreach ((array) $row as $k => $v) {
                $initialFields[$k] = $v === null ? '' : (string) $v;
            }

            // 出處（c_textid，search 欄位）補回顯示標籤；補水失敗不影響編輯主流程。
            // 注意：c_textid=0（未詳）為合法代碼，亦須補水（對齊 legacy；不可用 if ($textId) 略過 0）。
            try {
                $tx = DB::table('TEXT_CODES')->where('c_textid', $textId)->first();
                if ($tx) {
                    $initialLabels['c_textid'] = trim($tx->c_textid . ' ' . ($tx->c_title ?? '') . ' ' . ($tx->c_title_chn ?? ''));
                }
            } catch (\Throwable $e) {
                // label 補水失敗不影響編輯主流程（例如測試環境缺碼表）
            }

            // 維基資料來源警告（對齊 legacy $wikiSourceIds）。
            $isWikiSource = in_array($textId, [60795, 68942, 68943], true);
        }

        $user = Auth::user();

        return Inertia::render('BasicInformation/SourceEditV2', [
            'person_id' => $personId,
            'person_label' => $personLabel,
            'edit_mode' => $mode,
            'initial_fields' => (object) $initialFields,
            'initial_labels' => (object) $initialLabels,
            'can_edit' => $user ? ($user->isActive() && $user->canWriteDirectly()) : false,
            'can_propose' => $user ? $user->canPropose() : false,
            'create_endpoint' => route('api.v2.create.web', [], false),
            'mutate_endpoint' => route('api.v2.mutate.web', [], false),
            'delete_endpoint' => route('api.v2.delete.web', [], false),
            'index_url' => route('basicinformation.sources.index', ['basicinformation' => $personId], false),
            'is_wiki_source' => $isWikiSource,
        ]);
    }

    /**
     * Inertia + React 版：人物詳情頁。與 appEdit 同為 PersonEditor 編輯中樞
     * （舊頁 /basicinformation/{id} 即載入可錄入的 basic_info 分頁，故詳情=編輯中樞）。
     */
    public function appShow($id) {
        return $this->renderPersonEditor($id);
    }

    /**
     * 渲染 PersonEditor 編輯中樞。先以 buildPersonViewProps 確認人物存在（404）並取標籤。
     */
    protected function renderPersonEditor($id) {
        $personId = $this->normalizePersonId($id);
        // 確認人物存在並取得顯示標籤（不存在則 404）。
        [, $personLabel] = $this->buildPersonViewProps($personId);

        // 支援 ?tab= 深連結（對齊舊頁各子資源獨立 URL 的可深連結性）；非法值回退 basic_info。
        $validTabs = array_merge(['basic_info'], \App\Services\PersonBrowserService::validTabKeys());
        $requestedTab = (string) request('tab', '');
        $initialTab = in_array($requestedTab, $validTabs, true) ? $requestedTab : 'basic_info';

        return Inertia::render('BasicInformation/PersonEditor', array_merge([
            'personId' => $personId,
            'person_label' => $personLabel,
            'initialTab' => $initialTab,
            'index_url' => migration_flag_is_new('basicinformation.index') && Route::has('app.basicinformation.index')
                ? route('app.basicinformation.index', [], false)
                : route('basicinformation.index', [], false),
            'page_translations' => [
                'person' => is_array($t = trans('person')) ? $t : [],
            ],
        ], $this->personEditorTabProps()));
    }

    /**
     * PersonEditor 所需的端點 + 旗標（與 PersonBrowserController@index 對齊），
     * 但 summary/tab 端點改指向「編輯者/訪客可用」的 app.basicinformation.summary/.tab
     * （非 superadmin-only）。__PERSON_ID__ / __TAB_KEY__ 由前端替換。
     *
     * @return array<string, mixed>
     */
    protected function personEditorTabProps(): array {
        $user = Auth::user();

        return [
            'tabKeys' => PersonBrowserService::validTabKeys(),
            'summaryEndpoint' => route('app.basicinformation.summary', ['id' => '__PERSON_ID__'], false),
            'tabEndpoint' => route('app.basicinformation.tab', ['id' => '__PERSON_ID__', 'tabKey' => '__TAB_KEY__'], false),
            'mutateEndpoint' => route('api.v2.mutate.web', [], false),
            'createEndpoint' => route('api.v2.create.web', [], false),
            'deleteEndpoint' => route('api.v2.delete.web', [], false),
            'pinyinEndpoint' => '/api/select/search/pinyin',
            'canEditBasicInfo' => $user ? ($user->isActive() && $user->canWriteDirectly()) : false,
            'canProposeEdits' => $user ? $user->canPropose() : false,
            'altnameEditorIsNew' => migration_flag_is_new('basicinformation.altname'),
            'addressesEditorIsNew' => migration_flag_is_new('basicinformation.addresses'),
            'textsEditorIsNew' => migration_flag_is_new('basicinformation.texts'),
            'sourcesEditorIsNew' => migration_flag_is_new('basicinformation.sources'),
            'officesEditorIsNew' => migration_flag_is_new('basicinformation.offices'),
            'assocEditorIsNew' => migration_flag_is_new('basicinformation.assoc'),
            'kinshipEditorIsNew' => migration_flag_is_new('basicinformation.kinship'),
            'eventsEditorIsNew' => migration_flag_is_new('basicinformation.events'),
            'entriesEditorIsNew' => migration_flag_is_new('basicinformation.entries'),
            'statusesEditorIsNew' => migration_flag_is_new('basicinformation.statuses'),
            'possessionEditorIsNew' => migration_flag_is_new('basicinformation.possession'),
            'socialInstEditorIsNew' => migration_flag_is_new('basicinformation.socialinst'),
        ];
    }

    /**
     * PersonEditor 用：人物摘要（JSON，header + tab_counts）。委託 PersonBrowserService::summary。
     * 與 person-browser summary 同資料，但路由不在 superadmin 組，供編輯主界面取用。
     */
    public function summary($id) {
        $personId = $this->normalizePersonId($id);
        $data = $this->personBrowserService->summary($personId);

        if ($data === null) {
            return response()->json(['error' => 'Person not found'], 404);
        }

        return response()->json($data);
    }

    /**
     * PersonEditor 用：分頁資料（JSON）。先校驗 tabKey 合法，委託 PersonBrowserService::tabData。
     */
    public function tab($id, $tabKey) {
        $personId = $this->normalizePersonId($id);

        if (!in_array($tabKey, PersonBrowserService::validTabKeys(), true)) {
            return response()->json(['error' => 'Invalid tab key'], 404);
        }

        $data = $this->personBrowserService->tabData($personId, $tabKey);

        if ($data === null) {
            return response()->json(['error' => 'Tab data not available'], 404);
        }

        return response()->json($data);
    }

    /**
     * Inertia + React 版：新增人物表單頁（c_personid + 核心姓名欄位）→ /api/v2/create。
     * 須登入（middleware auth 已涵蓋本方法）；實際寫入授權由 v2 handler 把關。
     */
    public function appCreate() {
        $user = Auth::user();

        // BIOG_MAIN create 提案 v2 尚未支援（回 501）：可提案但不可直接寫入者導回舊版新增流程。
        if ($user && !$user->canWriteDirectly() && $user->canPropose()) {
            flash('人物新增提案請使用舊版眾包流程 @ '.Carbon::now(), 'info');

            return redirect()->route('basicinformation.create');
        }

        $tempId = (int) BiogMain::max('c_personid') + 1;

        return Inertia::render('BasicInformation/Create', [
            'temp_id' => $tempId,
            'can_create' => $user ? ($user->isActive() && $user->canWriteDirectly()) : false,
            'create_endpoint' => route('api.v2.create.web', [], false),
            'edit_template' => migration_flag_is_new('basicinformation.editor') && Route::has('app.basicinformation.edit')
                ? route('app.basicinformation.edit', ['id' => '__ID__'], false)
                : route('basicinformation.edit', ['basicinformation' => '__ID__'], false),
            'index_url' => migration_flag_is_new('basicinformation.index') && Route::has('app.basicinformation.index')
                ? route('app.basicinformation.index', [], false)
                : route('basicinformation.index', [], false),
            'page_translations' => [
                'person' => is_array($t = trans('person')) ? $t : [],
            ],
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
            'page_title' => __('person.person_records'),
            'page_description' => __('person.person_records') . ' – ' . __('common.add'),
            'temp_id' => $temp_id,
            'breadcrumbs' => [
                ['label' => __('person.person_records'), 'url' => route('basicinformation.index')],
                ['label' => __('common.add'), 'url' => '#'],
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
            flash('請登入後編輯 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->isActive()) {
            flash('該用戶沒有權限，請聯絡管理員 @ '.Carbon::now(), 'error');

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

        //20190531判別是否為眾包用戶
        if (Auth::user()->isCrowdsourcingUser()) {
            $data = $this->toolRepository->timestamp($data, true);
            $this->operationRepository->store(Auth::id(), $data['c_personid'], 1, 'BIOG_MAIN', $data['c_personid'], $data, '', 2);
            flash('眾包紀錄 Create success @ '.Carbon::now(), 'success');

            return redirect()->route('basicinformation.index');
        } else {
            // 使用 Repository 進行儲存（內含事務與審計）
            $flight = $this->biogMainRepository->store($request);

            if (Schema::hasTable('CBDB__NAME_FTS')) {
                $this->nameSearchIndexService->reindexPerson($flight);
            }

            flash('Create success @ '.Carbon::now(), 'success');

            return redirect()->route('basicinformation.edit', $flight->c_personid);
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
            'page_title' => __('person.person_records'),
            'page_description' => __('person.person_records') . ' – ' . __('common.view'),
            'readonly' => true,
            'breadcrumbs' => [
                ['label' => __('person.person_records'), 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.show', $personId)],
                ['label' => __('common.view'), 'url' => '#'],
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
            'page_title' => __('person.person_records'),
            'page_description' => __('person.person_records'),
            'breadcrumbs' => [
                ['label' => __('person.person_records'), 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $personId)],
                ['label' => __('common.edit'), 'url' => '#'],
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
            flash('請登入後編輯 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->isActive()) {
            flash('該用戶沒有權限，請聯絡管理員 @ '.Carbon::now(), 'error');

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

            // 括號正規化：全角轉半角、括號前後補空格
            $data = BracketNormalizer::normalizeBiogMain($data);

            // 數據類型轉換
            $female = $data['c_female'] ?? null;
            $data['c_female'] = ($female === null || $female === '' || $female === 'NULL')
                ? null
                : (int) $female;
            $data['c_by_intercalary'] = (int)($data['c_by_intercalary'] ?? 0);
            $data['c_dy_intercalary'] = (int)($data['c_dy_intercalary'] ?? 0);

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
            flash('請登入後編輯 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->canWriteDirectly()) {
            flash('該用戶沒有權限，請聯絡管理員 @ '.Carbon::now(), 'error');

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
            flash('請登入後編輯 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->canWriteDirectly()) {
            flash('該用戶沒有權限，請聯絡管理員 @ '.Carbon::now(), 'error');

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
            flash('請登入後編輯 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->isActive()) {
            flash('該用戶沒有權限，請聯絡管理員 @ '.Carbon::now(), 'error');

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
