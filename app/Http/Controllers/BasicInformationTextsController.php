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

class BasicInformationTextsController extends Controller {
    /**
     * @var BiogMainRepository
     */
    protected $biogMainRepository;
    protected $table_name;
    protected $operationRepository;
    protected $toolsRepository;

    /**
     * TextsController constructor.
     * @param BiogMainRepository $biogMainRepository
     */
    public function __construct(BiogMainRepository $biogMainRepository, OperationRepository $operationRepository, ToolsRepository $toolsRepository) {
        $this->biogMainRepository = $biogMainRepository;
        $this->table_name = 'BIOG_TEXT_DATA';
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
        $biogbasicinformation = $this->biogMainRepository->byIdWithText($id);

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

        return view('biogmains.texts.index', ['basicinformation' => $biogbasicinformation,
            'page_title' => '著述', 'page_description' => '基本信息表 著述', 'breadcrumb_home' => '人物基本資料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '著述', 'url' => '#'],
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

        return view('biogmains.texts.create', [
            'id' => $id,
            'page_title' => '著述', 'page_description' => '基本信息表 著述', 'page_url' => '/basicinformation/'.$id.'/texts', 'breadcrumb_home' => '人物基本資料', 'archer' => '<li>新增</li>',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '著述', 'url' => route('basicinformation.texts.index', $id)],
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
            'c_textid' => ($request->input('c_textid') == -999) ? '0' : ($request->input('c_textid') ?? '0'),
            'c_source' => ($request->input('c_source') == -999) ? '0' : ($request->input('c_source') ?? '0'),
        ]);

        // 檢查動作類型
        $action = $request->input('action', 'save');

        if ($action === 'proposal') {
            // 轉發到提案控制器
            return app(\App\Http\Controllers\BasicInformationProposalController::class)
                ->proposalStore($request, $id, 'texts');
        }

        if (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        $data = $request->all();
        $data = Arr::except($data, ['_token']);
        $data['c_personid'] = $id;
        $data = $this->toolsRepository->timestamp($data, true);
        $temp = DB::table($this->table_name)->where([
            ['c_personid', '=', $data['c_personid']],
            ['c_textid', '=', $data['c_textid']],
            ['c_role_id', '=', $data['c_role_id']],
        ])->first();
        if (!blank($temp)) {
            flash('重复数据，保存失败 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        DB::table($this->table_name)->insert($data);
        $operation = $this->operationRepository->store(Auth::id(), $id, 1, $this->table_name, CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $data['c_personid'],
            'c_textid' => $data['c_textid'],
            'c_role_id' => $data['c_role_id'],
        ]), $data);
        (new AuditLogService())->write(
            $this->table_name,
            'INSERT',
            [
                'c_personid' => $data['c_personid'],
                'c_textid' => $data['c_textid'],
                'c_role_id' => $data['c_role_id'],
            ],
            null,
            $data,
            'user',
            (string) Auth::id(),
            $operation ? (string) $operation->id : null
        );
        flash('Store success @ '.Carbon::now(), 'success');

        // 使用新的查詢參數模式重定向
        $newPk = [
            'c_personid' => $data['c_personid'],
            'c_textid' => $data['c_textid'],
            'c_role_id' => $data['c_role_id'],
        ];

        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.texts.edit.query',
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
        $res = $this->biogMainRepository->textById($id_);

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

        return view('biogmains.texts.edit', ['id' => $id, 'row' => $res['row'], 'res' => $res,
            'page_title' => '著述', 'page_description' => '基本信息表 著述',
            'page_url' => '/basicinformation/'.$id.'/texts',
            'archer' => "<li>編輯</li>",
            'breadcrumb_home' => '人物基本資料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '著述', 'url' => route('basicinformation.texts.index', $id)],
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
    public function update(Request $request, $id, $id_) {
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
            'c_textid' => ($request->input('c_textid') == -999) ? '0' : ($request->input('c_textid') ?? '0'),
            'c_source' => ($request->input('c_source') == -999) ? '0' : ($request->input('c_source') ?? '0'),
        ]);

        // 檢查動作類型
        $action = $request->input('action', 'save');

        if ($action === 'proposal') {
            // 解析舊格式的複合主鍵
            $temp_l = explode("-", $id_);
            $originalPk = [
                'c_personid' => $temp_l[0],
                'c_textid' => $temp_l[1],
                'c_role_id' => $temp_l[2],
            ];

            return app(\App\Http\Controllers\BasicInformationProposalController::class)
                ->proposalUpdateWithPk($request, $id, 'texts', $originalPk);
        }

        // 直接儲存需要額外權限檢查
        if (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        $data = $request->all();
        $comment = $data['__proposal_comment'] ?? null;
        $data = Arr::except($data, ['_method', '_token', 'action', '__proposal_comment']);
        $data = $this->toolsRepository->timestamp($data);
        $temp_l = explode("-", $id_);

        //20251214新增差異比對紀錄
        $ori = DB::table($this->table_name)->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_textid', '=', $temp_l[1]],
            ['c_role_id', '=', $temp_l[2]],
        ])->first();

        DB::table($this->table_name)->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_textid', '=', $temp_l[1]],
            ['c_role_id', '=', $temp_l[2]],
        ])->update($data);
        $data['c_personid'] = $temp_l[0];

        // 準備要存入 operations 表的數據，加入註解
        $operationData = $data;
        if ($comment) {
            $operationData['__note'] = $comment;
        }

        $operation = $this->operationRepository->store(Auth::id(), $id, 3, $this->table_name, CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $data['c_personid'],
            'c_textid' => $data['c_textid'],
            'c_role_id' => $data['c_role_id'],
        ]), $operationData, $ori);
        (new AuditLogService())->write(
            $this->table_name,
            'UPDATE',
            [
                'c_personid' => $data['c_personid'],
                'c_textid' => $data['c_textid'],
                'c_role_id' => $data['c_role_id'],
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
            'c_personid' => $data['c_personid'],
            'c_textid' => $data['c_textid'],
            'c_role_id' => $data['c_role_id'],
        ];

        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.texts.edit.query',
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
     * 查詢參數模式：編輯著述記錄
     *
     * URL 格式：/basicinformation/{id}/texts/edit?c_personid=...&c_text_id=...
     *
     * @param Request $request
     * @param int $id 人物 ID
     * @return \Illuminate\Http\Response
     */
    public function editQuery(Request $request, $id) {
        // 從查詢參數提取複合主鍵
        $schema = CompositePrimaryKey::SCHEMAS['TEXT_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 驗證必填欄位
        if (!isset($pk['c_personid']) || !isset($pk['c_textid'])) {
            abort(400, '缺少必要參數：c_personid 或 c_textid');
        }

        // 構建舊格式 ID（用於 Repository）- 格式：c_personid-c_textid-c_role_id
        $c_role_id = $pk['c_role_id'] ?? 0;
        $id_ = $pk['c_personid'].'-'.$pk['c_textid'].'-'.$c_role_id;
        $res = $this->biogMainRepository->textById($id_);

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

        return view('biogmains.texts.edit', [
            'id' => $id,
            'row' => $res['row'],
            'res' => $res,
            'pk' => $pk,
            'page_title' => '著述',
            'page_description' => '基本信息表 著述',
            'page_url' => '/basicinformation/'.$id.'/texts',
            'archer' => '<li>編輯</li>',
            'breadcrumb_home' => '人物基本資料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '著述', 'url' => route('basicinformation.texts.index', $id)],
                ['label' => '编辑', 'url' => '#'],
            ],
        ]);
    }

    /**
     * 查詢參數模式：更新著述記錄
     *
     * @param Request $request
     * @param int $id 人物 ID
     * @return \Illuminate\Http\Response
     */
    public function updateQuery(Request $request, $id) {
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

        // 從查詢參數提取複合主鍵
        $schema = CompositePrimaryKey::SCHEMAS['TEXT_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        if ($action === 'proposal') {
            return app(\App\Http\Controllers\BasicInformationProposalController::class)
                ->proposalUpdateWithPk($request, $id, 'texts', $pk);
        }

        if (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 驗證必填欄位（c_role_id 為可選，預設為 0）
        CompositePrimaryKey::validateOrFail($pk, 'TEXT_DATA', ['c_role_id']);

        // 準備更新資料
        $data = $request->all();
        $comment = $data['__proposal_comment'] ?? null;
        $data = Arr::except($data, ['_method', '_token', 'action', '__proposal_comment', 'c_personid', 'c_textid', 'c_role_id']);
        $data = $this->toolsRepository->timestamp($data);

        // 構建查詢條件（使用 BIOG_TEXT_DATA 表）
        $c_role_id = $pk['c_role_id'] ?? 0;
        $conditions = [
            ['c_personid', '=', $pk['c_personid']],
            ['c_textid', '=', $pk['c_textid']],
            ['c_role_id', '=', $c_role_id],
        ];

        // 取得原始資料
        $ori = DB::table($this->table_name)->where($conditions)->first();
        if (!$ori) {
            abort(404, 'BIOG_TEXT_DATA 記錄不存在');
        }

        // 更新資料
        DB::table($this->table_name)->where($conditions)->update($data);

        // 記錄操作
        $newPk = [
            'c_personid' => $pk['c_personid'],
            'c_textid' => $data['c_textid'] ?? $pk['c_textid'],
            'c_role_id' => $data['c_role_id'] ?? $c_role_id,
        ];

        // 準備要存入 operations 表的數據，加入註解
        $operationData = $data;
        if ($comment) {
            $operationData['__note'] = $comment;
        }

        $operation = $this->operationRepository->store(Auth::id(), $id, 3, $this->table_name, CompositePrimaryKey::buildStoredResourceId($newPk), $operationData, $ori);
        (new AuditLogService())->write(
            $this->table_name,
            'UPDATE',
            $newPk,
            (new AuditLogService())->normalizeRow($ori),
            array_merge((new AuditLogService())->normalizeRow($ori), $data),
            'user',
            (string) Auth::id(),
            $operation ? (string) $operation->id : null
        );

        flash('Update success @ '.Carbon::now(), 'success');

        // 重定向到新的查詢參數格式
        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.texts.edit.query',
            ['id' => $id],
            $newPk
        ));
    }

    /**
     * 查詢參數模式：刪除著述記錄
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
        $schema = CompositePrimaryKey::SCHEMAS['TEXT_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 驗證必填欄位（c_role_id 為可選，預設為 0）
        CompositePrimaryKey::validateOrFail($pk, 'TEXT_DATA', ['c_role_id']);

        // 構建查詢條件
        $c_role_id = $pk['c_role_id'] ?? 0;
        $conditions = [
            ['c_personid', '=', $pk['c_personid']],
            ['c_textid', '=', $pk['c_textid']],
            ['c_role_id', '=', $c_role_id],
        ];

        $row = DB::table($this->table_name)->where($conditions)->first();
        if (!$row) {
            abort(404, 'BIOG_TEXT_DATA 記錄不存在');
        }

        // 刪除資料
        DB::table($this->table_name)->where($conditions)->delete();

        // 記錄操作
        $operation = $this->operationRepository->store(Auth::id(), $id, 4, $this->table_name, CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $pk['c_personid'],
            'c_textid' => $pk['c_textid'],
            'c_role_id' => $c_role_id,
        ]), $row);
        (new AuditLogService())->write(
            $this->table_name,
            'DELETE',
            [
                'c_personid' => $pk['c_personid'],
                'c_textid' => $pk['c_textid'],
                'c_role_id' => $c_role_id,
            ],
            (new AuditLogService())->normalizeRow($row),
            null,
            'user',
            (string) Auth::id(),
            $operation ? (string) $operation->id : null
        );

        flash('Delete success @ '.Carbon::now(), 'success');

        return redirect()->route('basicinformation.texts.index', ['basicinformation' => $id]);
    }
}
