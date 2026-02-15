<?php

namespace App\Http\Controllers;

use App\Models\TextCode;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\Services\AuditLogService;
use App\Services\NameSearchIndexService;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BasicInformationAltnamesController extends Controller {
    /**
     * @var BiogMainRepository
     */
    protected $biogMainRepository;
    protected $operationRepository;
    protected $toolsRepository;
    protected $nameSearchIndexService;

    /**
     * TextsController constructor.
     * @param BiogMainRepository $biogMainRepository
     */
    public function __construct(BiogMainRepository $biogMainRepository, OperationRepository $operationRepository, ToolsRepository $toolsRepository, NameSearchIndexService $nameSearchIndexService) {
        $this->biogMainRepository = $biogMainRepository;
        $this->operationRepository = $operationRepository;
        $this->toolsRepository = $toolsRepository;
        $this->nameSearchIndexService = $nameSearchIndexService;
        $this->middleware('auth')->except(['index', 'show', 'edit', 'editQuery']);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index($id) {
        $biogbasicinformation = $this->biogMainRepository->byIdWithAlt($id);
        $personLabel = $id . ' - ' . $biogbasicinformation->c_name_chn . ' (' . $biogbasicinformation->c_name . ')';

        return view('biogmains.altname.index', ['basicinformation' => $biogbasicinformation,
            'page_title' => '别名', 'page_description' => '基本信息表 别名', 'breadcrumb_home' => '人物基本資料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '别名', 'url' => '#'],
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create($id) {
        $basicinformation = $this->biogMainRepository->byPersonId($id);
        $personLabel = $id . ' - ' . $basicinformation->c_name_chn . ' (' . $basicinformation->c_name . ')';

        return view('biogmains.altname.create', [
            'id' => $id,
            'page_title' => '别名', 'page_description' => '基本信息表 别名', 'page_url' => '/basicinformation/'.$id.'/altnames', 'breadcrumb_home' => '人物基本資料', 'archer' => '<li>新增</li>',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '别名', 'url' => route('basicinformation.altnames.index', $id)],
                ['label' => '新增', 'url' => '#'],
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
        // 權限檢查
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        if (!Auth::user()->isActive()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 數據預處理
        $request->merge([
            'c_alt_name_type_code' => ($request->input('c_alt_name_type_code') == -999) ? '0' : ($request->input('c_alt_name_type_code') ?? '0'),
            'c_source' => ($request->input('c_source') == -999) ? '0' : ($request->input('c_source') ?? '0'),
        ]);

        // 檢查動作類型
        $action = $request->input('action', 'save');

        if ($action === 'proposal') {
            // 轉發到提案控制器
            return app(\App\Http\Controllers\BasicInformationProposalController::class)
                ->proposalStore($request, $id, 'altnames');
        }

        // 直接儲存需要額外權限檢查
        if (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 原有的直接儲存邏輯
        $data = $request->all();
        $data = Arr::except($data, ['_token', 'action', '__proposal_comment']);
        $data['c_personid'] = $id;
        $data = $this->toolsRepository->timestamp($data, true);

        // 檢查重複資料
        $temp = DB::table('ALTNAME_DATA')->where([
            ['c_personid', '=', $data['c_personid']],
            ['c_sequence', '=', $data['c_sequence']],
            ['c_alt_name_chn', '=', $data['c_alt_name_chn']],
            ['c_alt_name_type_code', '=', $data['c_alt_name_type_code']],
        ])->first();
        if (!blank($temp)) {
            flash('重复数据，保存失败 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 使用 Query Builder 插入資料
        DB::table('ALTNAME_DATA')->insert($data);
        $operation = $this->operationRepository->store(Auth::id(), $id, 1, 'ALTNAME_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $data['c_personid'],
            'c_sequence' => $data['c_sequence'],
            'c_alt_name_chn' => $data['c_alt_name_chn'],
            'c_alt_name_type_code' => $data['c_alt_name_type_code'],
        ]), $data);
        (new AuditLogService())->write(
            'ALTNAME_DATA',
            'INSERT',
            [
                'c_personid' => $data['c_personid'],
                'c_sequence' => $data['c_sequence'],
                'c_alt_name_chn' => $data['c_alt_name_chn'],
                'c_alt_name_type_code' => $data['c_alt_name_type_code'],
            ],
            null,
            $data,
            'user',
            (string) Auth::id(),
            $operation ? (string) $operation->id : null
        );

        // 手動調用索引服務
        if (Schema::hasTable('CBDB__NAME_FTS') && !empty($data['c_alt_name_chn'])) {
            $this->nameSearchIndexService->indexAltname(
                $data['c_personid'],
                $data['c_alt_name_type_code'],
                $data['c_alt_name_chn']
            );
        }

        flash('Store success @ '.Carbon::now(), 'success');

        // 使用新的查詢參數模式重定向
        $newPk = [
            'c_personid' => $data['c_personid'],
            'c_sequence' => $data['c_sequence'],
            'c_alt_name_chn' => $data['c_alt_name_chn'],
            'c_alt_name_type_code' => $data['c_alt_name_type_code'],
        ];

        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.altnames.edit.query',
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
    public function edit($id, $alt) {
        $addr_l = $this->parseAltnameId($alt);

        $row = DB::table('ALTNAME_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_sequence', '=', $addr_l[1]],
            ['c_alt_name_chn', 'like', '%'.$addr_l[2].'%'],
            ['c_alt_name_type_code', '=', $addr_l[3]],
        ])->first();

        if (!$row) {
            abort(404, 'ALTNAME_DATA record not found');
        }

        // 填補在測試或精簡 schema 中可能缺少的欄位，避免 Blade 存取未定義屬性
        foreach (['c_alt_name', 'c_notes', 'c_pages', 'c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date'] as $column) {
            if (!property_exists($row, $column)) {
                $row->{$column} = null;
            }
        }

        $text_str = null;
        if ($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            if ($text_) {
                $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;
            }
        }

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

        return view('biogmains.altname.edit', ['id' => $id, 'row' => $row, 'alt' => $alt, 'text_str' => $text_str,
            'page_title' => '别名', 'page_description' => '基本信息表 别名',
            'page_url' => '/basicinformation/'.$id.'/altnames',
            'archer' => "<li>編輯</li>",
            'breadcrumb_home' => '人物基本資料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '别名', 'url' => route('basicinformation.altnames.index', $id)],
                ['label' => '编辑', 'url' => '#'],
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
    public function update(Request $request, $id, $alt) {
        // 權限檢查
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        if (!Auth::user()->isActive()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 數據預處理
        $request->merge([
            'c_alt_name_type_code' => ($request->input('c_alt_name_type_code') == -999) ? '0' : ($request->input('c_alt_name_type_code') ?? '0'),
            'c_source' => ($request->input('c_source') == -999) ? '0' : ($request->input('c_source') ?? '0'),
        ]);

        // 檢查動作類型
        $action = $request->input('action', 'save');

        if ($action === 'proposal') {
            // 解析舊格式的複合主鍵，轉換為陣列
            $addr_l = $this->parseAltnameId($alt);
            $originalPk = [
                'c_personid' => $addr_l[0],
                'c_sequence' => $addr_l[1],
                'c_alt_name_chn' => $addr_l[2],
                'c_alt_name_type_code' => $addr_l[3],
            ];

            // 使用新的查詢參數模式，直接傳遞主鍵陣列
            return app(\App\Http\Controllers\BasicInformationProposalController::class)
                ->proposalUpdateWithPk($request, $id, 'altnames', $originalPk);
        }

        // 直接儲存需要額外權限檢查
        if (!Auth::user()->canWriteDirectly()) {
            flash('该用户沒有權限，請聯繫管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 原有的直接儲存邏輯
        $data = $request->all();
        $data = Arr::except($data, ['_method', '_token', 'action', '__proposal_comment']);
        $data = $this->toolsRepository->timestamp($data);
        $addr_l = $this->parseAltnameId($alt);

        //20251213新增差異比對紀錄
        $ori = DB::table('ALTNAME_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_sequence', '=', $addr_l[1]],
            ['c_alt_name_chn', 'like', '%'.$addr_l[2].'%'],
            ['c_alt_name_type_code', '=', $addr_l[3]],
        ])->first();

        // 使用 Query Builder 更新資料
        DB::table('ALTNAME_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_sequence', '=', $addr_l[1]],
            ['c_alt_name_chn', 'like', '%'.$addr_l[2].'%'],
            ['c_alt_name_type_code', '=', $addr_l[3]],
        ])->update($data);

        $operation = $this->operationRepository->store(Auth::id(), $id, 3, 'ALTNAME_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $id,
            'c_sequence' => $data['c_sequence'],
            'c_alt_name_chn' => $data['c_alt_name_chn'],
            'c_alt_name_type_code' => $data['c_alt_name_type_code'],
        ]), $data, $ori);
        (new AuditLogService())->write(
            'ALTNAME_DATA',
            'UPDATE',
            [
                'c_personid' => $id,
                'c_sequence' => $data['c_sequence'],
                'c_alt_name_chn' => $data['c_alt_name_chn'],
                'c_alt_name_type_code' => $data['c_alt_name_type_code'],
            ],
            (new AuditLogService())->normalizeRow($ori),
            array_merge((new AuditLogService())->normalizeRow($ori), $data),
            'user',
            (string) Auth::id(),
            $operation ? (string) $operation->id : null
        );

        // 手動調用索引服務
        if ($ori && Schema::hasTable('CBDB__NAME_FTS')) {
            $nameChanged = $ori->c_alt_name_chn !== $data['c_alt_name_chn'];
            $typeChanged = $ori->c_alt_name_type_code !== $data['c_alt_name_type_code'];

            if ($nameChanged || $typeChanged) {
                // 刪除舊索引
                if ($ori->c_alt_name_chn) {
                    $this->nameSearchIndexService->removeAltname(
                        $ori->c_personid,
                        $ori->c_alt_name_type_code,
                        $ori->c_alt_name_chn
                    );
                }

                // 創建新索引
                if (!empty($data['c_alt_name_chn'])) {
                    $this->nameSearchIndexService->indexAltname(
                        $addr_l[0],
                        $data['c_alt_name_type_code'],
                        $data['c_alt_name_chn']
                    );
                }
            }
        }

        flash('Update success @ '.Carbon::now(), 'success');

        // 使用新的查詢參數模式重定向
        $newPk = [
            'c_personid' => $id,
            'c_sequence' => $data['c_sequence'],
            'c_alt_name_chn' => $data['c_alt_name_chn'],
            'c_alt_name_type_code' => $data['c_alt_name_type_code'],
        ];

        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.altnames.edit.query',
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

    /**
     * 解析別名複合主鍵 ID
     * 支持兩種分隔符格式：'_._' (新格式) 和 '-' (舊格式)
     *
     * @param string $alt 複合主鍵 ID
     * @return array 包含 4 個元素的陣列 [c_personid, c_sequence, c_alt_name_chn, c_alt_name_type_code]
     */
    protected function parseAltnameId($alt) {
        // 檢測分隔符類型：支持兩種格式 '_._' 或 '-'
        if (strpos($alt, '_._') !== false) {
            // 使用 _._格式
            $addr_l = explode('_._', $alt);
        } else {
            // 使用 - 格式
            // 先用 - 分割，然後對每個部分調用 unionPKDef_decode
            // （因為 - 被編碼為 (minus) 所以可以安全地用 - 分割）
            $addr_l = explode("-", $alt);
            foreach ($addr_l as $key => $value) {
                $addr_l[$key] = $this->biogMainRepository->unionPKDef_decode($value);
            }
        }

        // 檢查陣列長度是否足夠（ALTNAME_DATA 需要 4 個欄位）
        if (count($addr_l) < 4) {
            \Log::error("ALTNAME_DATA ID 格式不正確: {$alt}", [
                'parsed' => $addr_l,
                'expected_count' => 4,
                'actual_count' => count($addr_l),
            ]);
            abort(400, 'ALTNAME_DATA ID 格式不正確');
        }

        // 處理 NULL 值
        if (isset($addr_l[1]) && $addr_l[1] == 'NULL') {
            $addr_l[1] = null;
        }

        return $addr_l;
    }

    // ===================================================================
    // 查詢參數模式方法（推薦使用）
    // 使用 HTTP 查詢參數傳遞複合主鍵，避免自定義編碼邏輯
    // 參考：docs/COMPOSITE_PRIMARY_KEY_URL_DESIGN.md
    // ===================================================================

    /**
     * 查詢參數模式：編輯別名記錄
     *
     * URL 格式：/basicinformation/{id}/altnames/edit?c_personid=...&c_sequence=...&c_alt_name_chn=...&c_alt_name_type_code=...
     *
     * @param Request $request
     * @param int $id 人物 ID
     * @return \Illuminate\Http\Response
     */
    public function editQuery(Request $request, $id) {
        // 從查詢參數提取複合主鍵
        $schema = CompositePrimaryKey::SCHEMAS['ALTNAME_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 驗證必填欄位
        $required = ['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'];
        foreach ($required as $field) {
            if (!isset($pk[$field]) || $pk[$field] === '') {
                abort(400, "缺少必要參數：{$field}");
            }
        }

        // 處理 c_sequence 可能為 'NULL' 或 null 的情況
        if (isset($pk['c_sequence']) && $pk['c_sequence'] === 'NULL') {
            $pk['c_sequence'] = null;
        }

        // 構建查詢條件
        $conditions = [
            ['c_personid', '=', $pk['c_personid']],
            ['c_alt_name_chn', '=', $pk['c_alt_name_chn']],
            ['c_alt_name_type_code', '=', $pk['c_alt_name_type_code']],
        ];

        // c_sequence 可能為 null，需要使用 whereNull
        $query = DB::table('ALTNAME_DATA')->where($conditions);
        if (isset($pk['c_sequence']) && $pk['c_sequence'] !== null) {
            $query->where('c_sequence', '=', $pk['c_sequence']);
        } else {
            $query->whereNull('c_sequence');
        }

        $row = $query->first();

        if (!$row) {
            abort(404, 'ALTNAME_DATA 記錄不存在');
        }

        // 填補在測試或精簡 schema 中可能缺少的欄位
        foreach (['c_alt_name', 'c_notes', 'c_pages', 'c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date'] as $column) {
            if (!property_exists($row, $column)) {
                $row->{$column} = null;
            }
        }

        $text_str = null;
        if (property_exists($row, 'c_source') && ($row->c_source || $row->c_source === 0)) {
            $text_ = TextCode::find($row->c_source);
            if ($text_) {
                $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;
            }
        }

        // 處理 basicinformation
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
            // 如果 byPersonId 失敗，只使用 ID
        }

        return view('biogmains.altname.edit', [
            'id' => $id,
            'row' => $row,
            'alt' => null, // 查詢參數模式不需要編碼的主鍵字串
            'text_str' => $text_str,
            'pk' => $pk, // 傳遞複合主鍵供表單使用
            'page_title' => '别名',
            'page_description' => '基本信息表 别名',
            'page_url' => '/basicinformation/'.$id.'/altnames',
            'archer' => '<li>編輯</li>',
            'breadcrumb_home' => '人物基本資料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '别名', 'url' => route('basicinformation.altnames.index', $id)],
                ['label' => '编辑', 'url' => '#'],
            ],
        ]);
    }

    /**
     * 查詢參數模式：更新別名記錄
     *
     * @param Request $request
     * @param int $id 人物 ID
     * @return \Illuminate\Http\Response
     */
    public function updateQuery(Request $request, $id) {
        // 權限檢查
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        if (!Auth::user()->isActive()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 檢查動作類型
        $action = $request->input('action', 'save');

        if ($action === 'proposal') {
            // 提案模式需要從 URL 查詢字串取得原始 PK（而非表單提交的新值）
            $schema = CompositePrimaryKey::SCHEMAS['ALTNAME_DATA'];
            $originalPk = [];
            foreach ($schema as $field) {
                $value = $request->query($field);
                if ($value !== null) {
                    $originalPk[$field] = $value;
                }
            }

            // 處理 c_sequence 可能為 'NULL' 的情況
            if (isset($originalPk['c_sequence']) && $originalPk['c_sequence'] === 'NULL') {
                $originalPk['c_sequence'] = null;
            }

            // 使用新的查詢參數模式，直接傳遞主鍵陣列
            return app(\App\Http\Controllers\BasicInformationProposalController::class)
                ->proposalUpdateWithPk($request, $id, 'altnames', $originalPk);
        }

        // 直接儲存需要額外權限檢查
        if (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 從 URL 查詢字串提取原始 PK（用於定位記錄）
        // 注意：不能用 fromRequest()，因為它會合併查詢參數和表單 body
        $schema = CompositePrimaryKey::SCHEMAS['ALTNAME_DATA'];
        $originalPk = [];
        foreach ($schema as $field) {
            $value = $request->query($field);
            if ($value !== null) {
                $originalPk[$field] = $value;
            }
        }

        // 驗證必填欄位（c_sequence 為可選）
        CompositePrimaryKey::validateOrFail($originalPk, 'ALTNAME_DATA', ['c_sequence']);

        // 處理 c_sequence
        if (isset($originalPk['c_sequence']) && $originalPk['c_sequence'] === 'NULL') {
            $originalPk['c_sequence'] = null;
        }

        // 構建查詢條件（使用原始 PK）
        $conditions = [
            ['c_personid', '=', $originalPk['c_personid']],
            ['c_alt_name_chn', '=', $originalPk['c_alt_name_chn']],
            ['c_alt_name_type_code', '=', $originalPk['c_alt_name_type_code']],
        ];

        // c_sequence 可能為 null，需要使用 whereNull
        $queryBuilder = DB::table('ALTNAME_DATA')->where($conditions);
        if (isset($originalPk['c_sequence']) && $originalPk['c_sequence'] !== null) {
            $queryBuilder->where('c_sequence', '=', $originalPk['c_sequence']);
        } else {
            $queryBuilder->whereNull('c_sequence');
        }

        // 取得原始資料
        $ori = $queryBuilder->first();
        if (!$ori) {
            abort(404, 'ALTNAME_DATA 記錄不存在');
        }

        // 準備更新資料（保留 PK 欄位，允許修改）
        $data = $request->all();
        $comment = $data['__proposal_comment'] ?? null;
        $data = Arr::except($data, ['_method', '_token', 'action', '__proposal_comment']);
        $data = $this->toolsRepository->timestamp($data);

        // 更新資料（需要重新構建 query builder，因為 $queryBuilder 已經被 first() 消耗）
        $updateQuery = DB::table('ALTNAME_DATA')->where($conditions);
        if (isset($originalPk['c_sequence']) && $originalPk['c_sequence'] !== null) {
            $updateQuery->where('c_sequence', '=', $originalPk['c_sequence']);
        } else {
            $updateQuery->whereNull('c_sequence');
        }
        $updateQuery->update($data);

        // 記錄操作（使用新的複合主鍵）
        $newPk = [
            'c_personid' => $data['c_personid'] ?? $originalPk['c_personid'],
            'c_sequence' => $data['c_sequence'] ?? $originalPk['c_sequence'],
            'c_alt_name_chn' => $data['c_alt_name_chn'] ?? $originalPk['c_alt_name_chn'],
            'c_alt_name_type_code' => $data['c_alt_name_type_code'] ?? $originalPk['c_alt_name_type_code'],
        ];

        // 準備存入 operations 的數據，加入註解
        $operationData = $data;
        if ($comment) {
            $operationData['__note'] = $comment;
        }

        $operation = $this->operationRepository->store(Auth::id(), $id, 3, 'ALTNAME_DATA', CompositePrimaryKey::buildStoredResourceId($newPk), $operationData, $ori);
        (new AuditLogService())->write(
            'ALTNAME_DATA',
            'UPDATE',
            $newPk,
            (new AuditLogService())->normalizeRow($ori),
            array_merge((new AuditLogService())->normalizeRow($ori), $data),
            'user',
            (string) Auth::id(),
            $operation ? (string) $operation->id : null
        );

        // 更新索引
        if ($ori && Schema::hasTable('CBDB__NAME_FTS')) {
            $nameChanged = ($ori->c_alt_name_chn ?? null) !== ($data['c_alt_name_chn'] ?? $originalPk['c_alt_name_chn']);
            $typeChanged = ($ori->c_alt_name_type_code ?? null) !== ($data['c_alt_name_type_code'] ?? $originalPk['c_alt_name_type_code']);

            if ($nameChanged || $typeChanged) {
                if ($ori->c_alt_name_chn) {
                    $this->nameSearchIndexService->removeAltname(
                        $ori->c_personid,
                        $ori->c_alt_name_type_code,
                        $ori->c_alt_name_chn
                    );
                }

                $newAltNameChn = $data['c_alt_name_chn'] ?? $originalPk['c_alt_name_chn'];
                if (!empty($newAltNameChn)) {
                    $this->nameSearchIndexService->indexAltname(
                        $originalPk['c_personid'],
                        $data['c_alt_name_type_code'] ?? $originalPk['c_alt_name_type_code'],
                        $newAltNameChn
                    );
                }
            }
        }

        flash('Update success @ '.Carbon::now(), 'success');

        // 重定向到新的查詢參數格式
        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.altnames.edit.query',
            ['id' => $id],
            $newPk
        ));
    }

    /**
     * 查詢參數模式：刪除別名記錄
     *
     * @param Request $request
     * @param int $id 人物 ID
     * @return \Illuminate\Http\Response
     */
    public function destroyQuery(Request $request, $id) {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        if (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 從查詢參數提取複合主鍵
        $schema = CompositePrimaryKey::SCHEMAS['ALTNAME_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 驗證必填欄位（c_sequence 為可選）
        CompositePrimaryKey::validateOrFail($pk, 'ALTNAME_DATA', ['c_sequence']);

        // 處理 c_sequence
        if (isset($pk['c_sequence']) && $pk['c_sequence'] === 'NULL') {
            $pk['c_sequence'] = null;
        }

        // 構建查詢條件
        $conditions = [
            ['c_personid', '=', $pk['c_personid']],
            ['c_alt_name_chn', '=', $pk['c_alt_name_chn']],
            ['c_alt_name_type_code', '=', $pk['c_alt_name_type_code']],
        ];

        // c_sequence 可能為 null，需要使用 whereNull
        $query = DB::table('ALTNAME_DATA')->where($conditions);
        if (isset($pk['c_sequence']) && $pk['c_sequence'] !== null) {
            $query->where('c_sequence', '=', $pk['c_sequence']);
        } else {
            $query->whereNull('c_sequence');
        }

        $row = $query->first();
        if (!$row) {
            abort(404, 'ALTNAME_DATA 記錄不存在');
        }

        // 記錄操作
        $operation = $this->operationRepository->store(Auth::id(), $id, 4, 'ALTNAME_DATA', CompositePrimaryKey::buildStoredResourceId($pk), $row);

        // 刪除資料（需要重新構建 query builder）
        $deleteQuery = DB::table('ALTNAME_DATA')->where($conditions);
        if (isset($pk['c_sequence']) && $pk['c_sequence'] !== null) {
            $deleteQuery->where('c_sequence', '=', $pk['c_sequence']);
        } else {
            $deleteQuery->whereNull('c_sequence');
        }
        $deleteQuery->delete();
        (new AuditLogService())->write(
            'ALTNAME_DATA',
            'DELETE',
            $pk,
            (new AuditLogService())->normalizeRow($row),
            null,
            'user',
            (string) Auth::id(),
            $operation ? (string) $operation->id : null
        );

        // 清理索引
        if ($row && Schema::hasTable('CBDB__NAME_FTS') && $row->c_alt_name_chn) {
            $this->nameSearchIndexService->removeAltname(
                $row->c_personid,
                $row->c_alt_name_type_code,
                $row->c_alt_name_chn
            );
        }

        flash('Delete success @ '.Carbon::now(), 'success');

        return redirect()->route('basicinformation.altnames.index', ['basicinformation' => $id]);
    }
}
