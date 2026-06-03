<?php

namespace App\Http\Controllers;

use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\Services\AuditLogService;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BasicInformationEntriesController extends Controller {
    /**
     * @var BiogMainRepository
     */
    protected $biogMainRepository;
    protected $operationRepository;
    protected $toolsRepository;

    /**
     * TextsController constructor.
     * @param BiogMainRepository $biogMainRepository
     */
    public function __construct(BiogMainRepository $biogMainRepository, OperationRepository $operationRepository, ToolsRepository $toolsRepository) {
        $this->biogMainRepository = $biogMainRepository;
        $this->operationRepository = $operationRepository;
        $this->toolsRepository = $toolsRepository;
        $this->middleware('auth')->except(['index', 'show', 'edit', 'editQuery']);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index($id) {
        $biogbasicinformation = $this->biogMainRepository->byIdWithEntries($id);

        // 處理 basicinformation 可能為 null 或缺少字段的情況
        $personLabel = $id;

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

        // 收集所有年號 ID，批量查詢年號名稱
        $nianhaoMap = collect();

        try {
            if ($biogbasicinformation && $biogbasicinformation->entries->isNotEmpty()) {
                $nianhaoIds = $biogbasicinformation->entries
                    ->pluck('pivot.c_entry_nh_id')
                    ->filter(fn ($v) => $v && $v != 0)
                    ->unique()
                    ->values();
                if ($nianhaoIds->isNotEmpty()) {
                    $nianhaoMap = DB::table('NIAN_HAO')
                        ->whereIn('c_nianhao_id', $nianhaoIds)
                        ->pluck('c_nianhao_chn', 'c_nianhao_id');
                }
            }
        } catch (\Exception $e) {
            // 測試環境或最小化 schema 可能缺少 NIAN_HAO 表，降級為不顯示年號
        }

        return view('biogmains.entries.index', ['basicinformation' => $biogbasicinformation,
            'nianhaoMap' => $nianhaoMap,
            'page_title' => __('person.entry'), 'page_description' => __('person.person_records') . ' – ' . __('person.entry'), 'breadcrumb_home' => __('person.person_records'),
            'breadcrumbs' => [
                ['label' => __('person.person_records'), 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => __('person.entry'), 'url' => '#'],
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create($id) {
        // 處理 basicinformation 可能為 null 或缺少字段的情況
        $personLabel = $id;

        try {
            $basicinformation = $this->biogMainRepository->byPersonId($id);
            if ($basicinformation) {
                $nameChn = $basicinformation->c_name_chn ?? '';
                $name = $basicinformation->c_name ?? '';
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

        return view('biogmains.entries.create', [
            'id' => $id,
            'page_title' => __('person.entry'), 'page_description' => __('person.person_records') . ' – ' . __('person.entry'), 'page_url' => '/basicinformation/'.$id.'/entries', 'breadcrumb_home' => __('person.person_records'), 'archer' => '<li>新增</li>',
            'breadcrumbs' => [
                ['label' => __('person.person_records'), 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => __('person.entry'), 'url' => route('basicinformation.entries.index', $id)],
                ['label' => __('common.add'), 'url' => '#'],
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, $id) {
        if (!Auth::check()) {
            flash('請登入後編輯 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        if (!Auth::user()->isActive()) {
            flash('該使用者沒有權限，請聯繫管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 數據預處理：處理 -999 轉為 0 並分割 c_inst_code
        // 這些預處理必須在提案 (proposal) 和直接儲存 (save) 之前完成
        $data = $request->all();
        $data['c_entry_code'] = CompositePrimaryKey::emptyToSentinel($data['c_entry_code'] ?? null);
        $data['c_entry_addr_id'] = CompositePrimaryKey::emptyToSentinel($data['c_entry_addr_id'] ?? null);
        $data['c_kin_code'] = CompositePrimaryKey::emptyToSentinel($data['c_kin_code'] ?? null);
        $data['c_assoc_code'] = CompositePrimaryKey::emptyToSentinel($data['c_assoc_code'] ?? null);
        $data['c_inst_code'] = CompositePrimaryKey::emptyToSentinel($data['c_inst_code'] ?? null);
        $data['c_source'] = CompositePrimaryKey::emptyToSentinel($data['c_source'] ?? null);
        // 補齊其他 NOT NULL 複合主鍵欄位，避免清空後觸發 "缺少必要的複合主鍵參數" 錯誤
        $data['c_year'] = CompositePrimaryKey::emptyToSentinel($data['c_year'] ?? null);
        $data['c_kin_id'] = CompositePrimaryKey::emptyToSentinel($data['c_kin_id'] ?? null);
        $data['c_assoc_id'] = CompositePrimaryKey::emptyToSentinel($data['c_assoc_id'] ?? null);
        $data['c_sequence'] = CompositePrimaryKey::emptyToSentinel($data['c_sequence'] ?? null);

        $temp = explode("-", $data['c_inst_code']);
        $c_inst_code = $temp[0];
        $c_inst_name_code = $temp[1] ?? '0';

        // 如果沒有分割出 c_inst_name_code，則預設為 0
        if (empty($temp[1])) {
            $c_inst_code = $c_inst_code ?: '0';
            $c_inst_name_code = '0';
        }

        // 將預處理後的數據合併回 Request，以便 proposalStore 正確接收
        $request->merge([
            'c_entry_code' => $data['c_entry_code'],
            'c_entry_addr_id' => $data['c_entry_addr_id'],
            'c_kin_code' => $data['c_kin_code'],
            'c_assoc_code' => $data['c_assoc_code'],
            'c_inst_code' => $c_inst_code,
            'c_inst_name_code' => $c_inst_name_code,
            'c_source' => $data['c_source'],
            'c_year' => $data['c_year'],
            'c_kin_id' => $data['c_kin_id'],
            'c_assoc_id' => $data['c_assoc_id'],
            'c_sequence' => $data['c_sequence'],
        ]);

        // 檢查動作類型
        $action = $request->input('action', 'save');

        if ($action === 'proposal') {
            // 轉發到提案控制器
            return app(\App\Http\Controllers\BasicInformationProposalController::class)
                ->proposalStore($request, $id, 'entries');
        }

        if (!Auth::user()->canWriteDirectly()) {
            flash('該使用者沒有權限，請聯繫管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 使用 Repository 進行儲存（內含事務與審計）
        $data = $this->biogMainRepository->entryStoreById($request, $id);
        if ($data === false) {
            flash('重複資料，儲存失敗 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        flash('Store success @ '.Carbon::now(), 'success');

        // 使用新的查詢參數模式重定向
        $newPk = [
            'c_personid' => $data['c_personid'],
            'c_entry_code' => $data['c_entry_code'],
            'c_sequence' => $data['c_sequence'],
            'c_kin_code' => $data['c_kin_code'],
            'c_assoc_code' => $data['c_assoc_code'],
            'c_kin_id' => $data['c_kin_id'],
            'c_year' => $data['c_year'],
            'c_assoc_id' => $data['c_assoc_id'],
            'c_inst_code' => $data['c_inst_code'],
            'c_inst_name_code' => $data['c_inst_name_code'],
        ];

        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.entries.edit.query',
            ['id' => $id],
            $newPk
        ));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id, $id_) {
        //聯合主鍵的樣式
        //"c_personid":34445,"c_entry_code":39,"c_sequence":1,"c_kin_code":0,"c_kin_id":0,"c_assoc_code":0,"c_assoc_id":0,"c_year":1351,"c_inst_code":0,"c_inst_name_code":0
        $res = $this->biogMainRepository->entryById($id_);

        // 處理 basicinformation 可能為 null 或缺少字段的情況
        $personLabel = $id;

        try {
            $basicinformation = $this->biogMainRepository->byPersonId($id);
            if ($basicinformation) {
                $nameChn = $basicinformation->c_name_chn ?? '';
                $name = $basicinformation->c_name ?? '';
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

        return view('biogmains.entries.edit', ['id' => $id, 'row' => $res['row'], 'res' => $res,
            'page_title' => __('person.entry'), 'page_description' => __('person.person_records') . ' – ' . __('person.entry'),
            'page_url' => '/basicinformation/'.$id.'/entries',
            'archer' => "<li>編輯</li>",
            'breadcrumb_home' => __('person.person_records'),
            'breadcrumbs' => [
                ['label' => __('person.person_records'), 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => __('person.entry'), 'url' => route('basicinformation.entries.index', $id)],
                ['label' => __('common.edit'), 'url' => '#'],
            ],
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id, $id_) {
        //建安修改20181109
        if (!Auth::check()) {
            flash('請登入後編輯 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->canWriteDirectly()) {
            flash('該使用者沒有權限，請聯繫管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        /*
        //原本的寫法，保留做為參考。
        $this->biogMainRepository->entryUpdateById($request, $id_, $id);
        flash('Update success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.entries.edit', [
            'basicinformation' => $id,
            'entry' => $id_,
        ]);
        */
        // 數據預處理：處理 -999 轉為 0 並分割 c_inst_code
        $data = $request->all();
        $data['c_entry_code'] = CompositePrimaryKey::emptyToSentinel($data['c_entry_code'] ?? null);
        $data['c_entry_addr_id'] = CompositePrimaryKey::emptyToSentinel($data['c_entry_addr_id'] ?? null);
        $data['c_kin_code'] = CompositePrimaryKey::emptyToSentinel($data['c_kin_code'] ?? null);
        $data['c_assoc_code'] = CompositePrimaryKey::emptyToSentinel($data['c_assoc_code'] ?? null);
        $data['c_inst_code'] = CompositePrimaryKey::emptyToSentinel($data['c_inst_code'] ?? null);
        $data['c_source'] = CompositePrimaryKey::emptyToSentinel($data['c_source'] ?? null);
        // 補齊其他 NOT NULL 複合主鍵欄位，避免清空後觸發 "缺少必要的複合主鍵參數" 錯誤
        $data['c_year'] = CompositePrimaryKey::emptyToSentinel($data['c_year'] ?? null);
        $data['c_kin_id'] = CompositePrimaryKey::emptyToSentinel($data['c_kin_id'] ?? null);
        $data['c_assoc_id'] = CompositePrimaryKey::emptyToSentinel($data['c_assoc_id'] ?? null);
        $data['c_sequence'] = CompositePrimaryKey::emptyToSentinel($data['c_sequence'] ?? null);

        $temp = explode("-", $data['c_inst_code']);
        $c_inst_code = $temp[0];
        $c_inst_name_code = $temp[1] ?? '0';

        if (empty($temp[1])) {
            $c_inst_code = $c_inst_code ?: '0';
            $c_inst_name_code = '0';
        }

        $request->merge([
            'c_entry_code' => $data['c_entry_code'],
            'c_entry_addr_id' => $data['c_entry_addr_id'],
            'c_kin_code' => $data['c_kin_code'],
            'c_assoc_code' => $data['c_assoc_code'],
            'c_inst_code' => $c_inst_code,
            'c_inst_name_code' => $c_inst_name_code,
            'c_source' => $data['c_source'],
            'c_year' => $data['c_year'],
            'c_kin_id' => $data['c_kin_id'],
            'c_assoc_id' => $data['c_assoc_id'],
            'c_sequence' => $data['c_sequence'],
        ]);

        $data = $request->all();
        $comment = $data['__proposal_comment'] ?? null;
        $data = Arr::except($data, ['_method', '_token', 'action', '__proposal_comment']);
        $data = $this->toolsRepository->timestamp($data);
        //return $request;
        //修改結束
        $id_ = str_replace("--", "-minus", $id_);
        $addr_a = explode("-", $id_);
        foreach ($addr_a as $key => $value) {
            $addr_a[$key] = str_replace("minus", "-", $value);
        }

        //20251208新增差異比對紀錄
        $ori = DB::table('ENTRY_DATA')->where([
            ['c_personid', '=', $addr_a[0]],
            ['c_entry_code', '=', $addr_a[1]],
            ['c_sequence', '=', $addr_a[2]],
            ['c_kin_code', '=', $addr_a[3]],
            ['c_assoc_code', '=', $addr_a[4]],
            ['c_kin_id', '=', $addr_a[5]],
            ['c_year', '=', $addr_a[6]],
            ['c_assoc_id', '=', $addr_a[7]],
            ['c_inst_code', '=', $addr_a[8]],
            ['c_inst_name_code', '=', $addr_a[9]],
    ])->first();

        DB::table('ENTRY_DATA')->where([
            ['c_personid', '=', $addr_a[0]],
            ['c_entry_code', '=', $addr_a[1]],
            ['c_sequence', '=', $addr_a[2]],
            ['c_kin_code', '=', $addr_a[3]],
            ['c_assoc_code', '=', $addr_a[4]],
            ['c_kin_id', '=', $addr_a[5]],
            ['c_year', '=', $addr_a[6]],
            ['c_assoc_id', '=', $addr_a[7]],
            ['c_inst_code', '=', $addr_a[8]],
            ['c_inst_name_code', '=', $addr_a[9]],
        ])->update($data);
        $operationData = $data;
        if ($comment) {
            $operationData['__note'] = $comment;
        }
        $operation = $this->operationRepository->store(Auth::id(), $id, 3, 'ENTRY_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $id,
            'c_entry_code' => $data['c_entry_code'],
            'c_sequence' => $data['c_sequence'],
            'c_kin_code' => $data['c_kin_code'],
            'c_assoc_code' => $data['c_assoc_code'],
            'c_kin_id' => $data['c_kin_id'],
            'c_year' => $data['c_year'],
            'c_assoc_id' => $data['c_assoc_id'],
            'c_inst_code' => $data['c_inst_code'],
            'c_inst_name_code' => $data['c_inst_name_code'],
        ]), $operationData, $ori);
        (new AuditLogService())->write(
            'ENTRY_DATA',
            'UPDATE',
            [
                'c_personid' => $id,
                'c_entry_code' => $data['c_entry_code'],
                'c_sequence' => $data['c_sequence'],
                'c_kin_code' => $data['c_kin_code'],
                'c_assoc_code' => $data['c_assoc_code'],
                'c_kin_id' => $data['c_kin_id'],
                'c_year' => $data['c_year'],
                'c_assoc_id' => $data['c_assoc_id'],
                'c_inst_code' => $data['c_inst_code'],
                'c_inst_name_code' => $data['c_inst_name_code'],
            ],
            (new AuditLogService())->normalizeRow($ori),
            array_merge((new AuditLogService())->normalizeRow($ori), $data),
            'user',
            (string) Auth::id(),
            $operation ? (string) $operation->id : null
        );
        flash('Update success @ '.Carbon::now(), 'success');

        // 使用新的查詢參數模式重定向
        $newPk = [
            'c_personid' => $id,
            'c_entry_code' => $data['c_entry_code'],
            'c_sequence' => $data['c_sequence'],
            'c_kin_code' => $data['c_kin_code'],
            'c_assoc_code' => $data['c_assoc_code'],
            'c_kin_id' => $data['c_kin_id'],
            'c_year' => $data['c_year'],
            'c_assoc_id' => $data['c_assoc_id'],
            'c_inst_code' => $data['c_inst_code'],
            'c_inst_name_code' => $data['c_inst_name_code'],
        ];

        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.entries.edit.query',
            ['id' => $id],
            $newPk
        ));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */

    // ===================================================================
    // 查詢參數模式方法（推薦使用）
    // 使用 HTTP 查詢參數傳遞複合主鍵，避免自定義編碼邏輯
    // 參考：docs/COMPOSITE_PRIMARY_KEY_URL_DESIGN.md
    // ===================================================================

    /**
     * 查詢參數模式：編輯入仕記錄
     *
     * URL 格式：/basicinformation/{id}/entries/edit?c_personid=...&c_entry_code=...&...
     *
     * @param Request $request
     * @param int $id 人物 ID
     * @return \Illuminate\Http\Response
     */
    public function editQuery(Request $request, $id) {
        // 從查詢參數提取複合主鍵
        $schema = CompositePrimaryKey::SCHEMAS['ENTRY_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 驗證必填欄位
        CompositePrimaryKey::validateOrFail($pk, 'ENTRY_DATA');

        // 構建舊格式 ID（格式：c_personid-c_entry_code-c_sequence-...）
        $id_ = implode('-', [
            $pk['c_personid'],
            $pk['c_entry_code'],
            $pk['c_sequence'],
            $pk['c_kin_code'],
            $pk['c_assoc_code'],
            $pk['c_kin_id'],
            $pk['c_year'],
            $pk['c_assoc_id'],
            $pk['c_inst_code'],
            $pk['c_inst_name_code'],
        ]);

        $res = $this->biogMainRepository->entryById($id_);

        // 處理 personLabel
        $personLabel = $id;

        try {
            $basicinformation = $this->biogMainRepository->byPersonId($id);
            if ($basicinformation) {
                $nameChn = $basicinformation->c_name_chn ?? '';
                $name = $basicinformation->c_name ?? '';
                if ($nameChn || $name) {
                    $personLabel .= ' - ' . $nameChn;
                    if ($name) {
                        $personLabel .= ' (' . $name . ')';
                    }
                }
            }
        } catch (\Exception $e) {
            // 忽略錯誤
        }

        return view('biogmains.entries.edit', [
            'id' => $id,
            'row' => $res['row'],
            'res' => $res,
            'pk' => $pk,
            'page_title' => __('person.entry'),
            'page_description' => __('person.person_records') . ' – ' . __('person.entry'),
            'page_url' => '/basicinformation/'.$id.'/entries',
            'archer' => '<li>編輯</li>',
            'breadcrumb_home' => __('person.person_records'),
            'breadcrumbs' => [
                ['label' => __('person.person_records'), 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => __('person.entry'), 'url' => route('basicinformation.entries.index', $id)],
                ['label' => __('common.edit'), 'url' => '#'],
            ],
        ]);
    }

    /**
     * 查詢參數模式：更新入仕記錄
     *
     * @param Request $request
     * @param int $id 人物 ID
     * @return \Illuminate\Http\Response
     */
    public function updateQuery(Request $request, $id) {
        if (!Auth::check()) {
            flash('請登入後編輯 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        if (!Auth::user()->isActive()) {
            flash('該使用者沒有權限，請聯繫管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 數據預處理：處理 -999 轉為 0 並分割 c_inst_code
        // 這些預處理必須在提案 (proposal) 和直接儲存 (save) 之前完成
        $request->merge([
            'c_entry_code' => CompositePrimaryKey::emptyToSentinel($request->input('c_entry_code')),
            'c_entry_addr_id' => CompositePrimaryKey::emptyToSentinel($request->input('c_entry_addr_id')),
            'c_kin_code' => CompositePrimaryKey::emptyToSentinel($request->input('c_kin_code')),
            'c_assoc_code' => CompositePrimaryKey::emptyToSentinel($request->input('c_assoc_code')),
            'c_source' => CompositePrimaryKey::emptyToSentinel($request->input('c_source')),
            // 補齊其他 NOT NULL 複合主鍵欄位，避免清空後觸發 "缺少必要的複合主鍵參數" 錯誤
            'c_year' => CompositePrimaryKey::emptyToSentinel($request->input('c_year')),
            'c_kin_id' => CompositePrimaryKey::emptyToSentinel($request->input('c_kin_id')),
            'c_assoc_id' => CompositePrimaryKey::emptyToSentinel($request->input('c_assoc_id')),
            'c_sequence' => CompositePrimaryKey::emptyToSentinel($request->input('c_sequence')),
        ]);

        $temp = explode("-", $request->input('c_inst_code', ''));
        $c_inst_code = $temp[0] ?: '0';
        $c_inst_name_code = $temp[1] ?? '0';

        if (empty($temp[1])) {
            $c_inst_code = $c_inst_code ?: '0';
            $c_inst_name_code = '0';
        }

        $request->merge([
            'c_inst_code' => $c_inst_code,
            'c_inst_name_code' => $c_inst_name_code,
        ]);

        // 檢查動作類型
        $action = $request->input('action', 'save');

        if ($action === 'proposal') {
            // 提案模式需要從 URL 查詢字串取得原始 PK（而非表單提交的新值）
            $schema = CompositePrimaryKey::SCHEMAS['ENTRY_DATA'];
            $originalPk = [];
            foreach ($schema as $field) {
                $value = $request->query($field);
                if ($value !== null) {
                    $originalPk[$field] = $value;
                }
            }

            // 使用新的查詢參數模式，直接傳遞主鍵陣列
            return app(\App\Http\Controllers\BasicInformationProposalController::class)
                ->proposalUpdateWithPk($request, $id, 'entries', $originalPk);
        }

        if (!Auth::user()->canWriteDirectly()) {
            flash('該使用者沒有權限，請聯繫管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 從 URL 查詢字串提取原始 PK（用於定位記錄）
        // 注意：不能用 fromRequest()，因為它會合併查詢參數和表單 body
        $schema = CompositePrimaryKey::SCHEMAS['ENTRY_DATA'];
        $originalPk = [];
        foreach ($schema as $field) {
            $value = $request->query($field);
            if ($value !== null) {
                $originalPk[$field] = $value;
            }
        }

        // 驗證必填欄位
        CompositePrimaryKey::validateOrFail($originalPk, 'ENTRY_DATA');

        $data = $request->all();
        $comment = $data['__proposal_comment'] ?? null;
        $data = Arr::except($data, ['_method', '_token', 'action', '__proposal_comment']);
        $data = $this->toolsRepository->timestamp($data);


        // 取得原始資料（使用原始 PK）
        $ori = DB::table('ENTRY_DATA')->where([
            ['c_personid', '=', $originalPk['c_personid']],
            ['c_entry_code', '=', $originalPk['c_entry_code']],
            ['c_sequence', '=', $originalPk['c_sequence']],
            ['c_kin_code', '=', $originalPk['c_kin_code']],
            ['c_assoc_code', '=', $originalPk['c_assoc_code']],
            ['c_kin_id', '=', $originalPk['c_kin_id']],
            ['c_year', '=', $originalPk['c_year']],
            ['c_assoc_id', '=', $originalPk['c_assoc_id']],
            ['c_inst_code', '=', $originalPk['c_inst_code']],
            ['c_inst_name_code', '=', $originalPk['c_inst_name_code']],
        ])->first();

        if (!$ori) {
            abort(404, 'ENTRY_DATA 記錄不存在');
        }

        // 更新（使用原始 PK 定位）
        DB::table('ENTRY_DATA')->where([
            ['c_personid', '=', $originalPk['c_personid']],
            ['c_entry_code', '=', $originalPk['c_entry_code']],
            ['c_sequence', '=', $originalPk['c_sequence']],
            ['c_kin_code', '=', $originalPk['c_kin_code']],
            ['c_assoc_code', '=', $originalPk['c_assoc_code']],
            ['c_kin_id', '=', $originalPk['c_kin_id']],
            ['c_year', '=', $originalPk['c_year']],
            ['c_assoc_id', '=', $originalPk['c_assoc_id']],
            ['c_inst_code', '=', $originalPk['c_inst_code']],
            ['c_inst_name_code', '=', $originalPk['c_inst_name_code']],
        ])->update($data);

        $operationData = $data;
        if ($comment) {
            $operationData['__note'] = $comment;
        }
        $operation = $this->operationRepository->store(Auth::id(), $id, 3, 'ENTRY_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $id,
            'c_entry_code' => $data['c_entry_code'],
            'c_sequence' => $data['c_sequence'],
            'c_kin_code' => $data['c_kin_code'],
            'c_assoc_code' => $data['c_assoc_code'],
            'c_kin_id' => $data['c_kin_id'],
            'c_year' => $data['c_year'],
            'c_assoc_id' => $data['c_assoc_id'],
            'c_inst_code' => $data['c_inst_code'],
            'c_inst_name_code' => $data['c_inst_name_code'],
        ]), $operationData, $ori);
        (new AuditLogService())->write(
            'ENTRY_DATA',
            'UPDATE',
            [
                'c_personid' => $id,
                'c_entry_code' => $data['c_entry_code'],
                'c_sequence' => $data['c_sequence'],
                'c_kin_code' => $data['c_kin_code'],
                'c_assoc_code' => $data['c_assoc_code'],
                'c_kin_id' => $data['c_kin_id'],
                'c_year' => $data['c_year'],
                'c_assoc_id' => $data['c_assoc_id'],
                'c_inst_code' => $data['c_inst_code'],
                'c_inst_name_code' => $data['c_inst_name_code'],
            ],
            (new AuditLogService())->normalizeRow($ori),
            array_merge((new AuditLogService())->normalizeRow($ori), $data),
            'user',
            (string) Auth::id(),
            $operation ? (string) $operation->id : null
        );
        flash('Update success @ '.Carbon::now(), 'success');

        // 重定向到新的查詢參數格式
        $newPk = [
            'c_personid' => $id,
            'c_entry_code' => $data['c_entry_code'],
            'c_sequence' => $data['c_sequence'],
            'c_kin_code' => $data['c_kin_code'],
            'c_assoc_code' => $data['c_assoc_code'],
            'c_kin_id' => $data['c_kin_id'],
            'c_year' => $data['c_year'],
            'c_assoc_id' => $data['c_assoc_id'],
            'c_inst_code' => $data['c_inst_code'],
            'c_inst_name_code' => $data['c_inst_name_code'],
        ];

        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.entries.edit.query',
            ['id' => $id],
            $newPk
        ));
    }

    /**
     * 查詢參數模式：刪除入仕記錄
     *
     * @param Request $request
     * @param int $id 人物 ID
     * @return \Illuminate\Http\Response
     */
    public function destroyQuery(Request $request, $id) {
        if (!Auth::check()) {
            flash('請登入後編輯 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        if (!Auth::user()->canWriteDirectly()) {
            flash('該使用者沒有權限，請聯繫管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 從查詢參數提取複合主鍵
        $schema = CompositePrimaryKey::SCHEMAS['ENTRY_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 驗證必填欄位
        CompositePrimaryKey::validateOrFail($pk, 'ENTRY_DATA');

        // 構建舊格式 ID 用於 Repository
        $id_ = implode('-', [
            $pk['c_personid'],
            $pk['c_entry_code'],
            $pk['c_sequence'],
            $pk['c_kin_code'],
            $pk['c_assoc_code'],
            $pk['c_kin_id'],
            $pk['c_year'],
            $pk['c_assoc_id'],
            $pk['c_inst_code'],
            $pk['c_inst_name_code'],
        ]);

        // 使用 Repository 進行刪除（內含事務與審計）
        $deleted = $this->biogMainRepository->entryDeleteById($id_, $id);
        if (!$deleted) {
            abort(404, 'ENTRY_DATA 記錄不存在');
        }

        flash('Delete success @ '.Carbon::now(), 'success');

        return redirect()->route('basicinformation.entries.index', ['basicinformation' => $id]);
    }
}
