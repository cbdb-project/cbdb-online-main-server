<?php

namespace App\Http\Controllers;

use App\Repositories\BiogMainRepository;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BasicInformationTextsController extends Controller {
    /**
     * @var BiogMainRepository
     */
    protected $biogMainRepository;
    protected $table_name;

    /**
     * TextsController constructor.
     * @param BiogMainRepository $biogMainRepository
     */
    public function __construct(BiogMainRepository $biogMainRepository) {
        $this->biogMainRepository = $biogMainRepository;
        $this->table_name = 'BIOG_TEXT_DATA';
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
            flash('請登入後編輯 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        if (!Auth::user()->isActive()) {
            flash('該使用者沒有權限，請聯繫管理員 @ '.Carbon::now(), 'error');

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
            flash('該使用者沒有權限，請聯繫管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 使用 Repository 進行儲存（內含事務與審計）
        $data = $this->biogMainRepository->textStoreById($request, $id);
        if ($data === false) {
            flash('重複資料，儲存失敗 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

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
                ['label' => '編輯', 'url' => '#'],
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
            flash('請登入後編輯 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        if (!Auth::user()->isActive()) {
            flash('該使用者沒有權限，請聯繫管理員 @ '.Carbon::now(), 'error');

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

        if (!Auth::user()->canWriteDirectly()) {
            flash('該使用者沒有權限，請聯繫管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 使用 Repository 進行更新（內含事務與審計）
        try {
            $newPk = $this->biogMainRepository->textUpdateById($request, $id, $id_);
        } catch (\InvalidArgumentException $e) {
            flash($e->getMessage(), 'error');

            return redirect()->back()->withInput();
        }
        if (!$newPk) {
            abort(404, 'BIOG_TEXT_DATA 記錄不存在');
        }

        flash('Update success @ '.Carbon::now(), 'success');

        // 使用新的查詢參數模式重定向
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
                ['label' => '編輯', 'url' => '#'],
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
            flash('請登入後編輯 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        if (!Auth::user()->isActive()) {
            flash('該使用者沒有權限，請聯繫管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 檢查動作類型
        $action = $request->input('action', 'save');

        // 從 URL 查詢字串取得原始 PK（而非表單提交的新值）
        // 注意：不能用 fromRequest()，因為它會合併查詢參數和表單 body
        $schema = CompositePrimaryKey::SCHEMAS['TEXT_DATA'];
        $originalPk = [];
        foreach ($schema as $field) {
            $value = $request->query($field);
            if ($value !== null) {
                $originalPk[$field] = $value;
            }
        }

        if ($action === 'proposal') {
            return app(\App\Http\Controllers\BasicInformationProposalController::class)
                ->proposalUpdateWithPk($request, $id, 'texts', $originalPk);
        }

        if (!Auth::user()->canWriteDirectly()) {
            flash('該使用者沒有權限，請聯繫管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 驗證必填欄位（c_role_id 為可選，預設為 0）
        CompositePrimaryKey::validateOrFail($originalPk, 'TEXT_DATA', ['c_role_id']);

        if ((string) ($originalPk['c_personid'] ?? '') !== (string) $id) {
            abort(400, '路徑人物 ID 與查詢主鍵不一致');
        }

        $c_role_id = $originalPk['c_role_id'] ?? 0;
        $legacyId = $originalPk['c_personid']."-".$originalPk['c_textid']."-".$c_role_id;
        try {
            $newPk = $this->biogMainRepository->textUpdateById($request, $id, $legacyId);
        } catch (\InvalidArgumentException $e) {
            flash($e->getMessage(), 'error');

            return redirect()->back()->withInput();
        }
        if ($newPk === null) {
            abort(404, 'BIOG_TEXT_DATA 記錄不存在');
        }

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
            flash('請登入後編輯 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        if (!Auth::user()->canWriteDirectly()) {
            flash('該使用者沒有權限，請聯繫管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 從查詢參數提取複合主鍵
        $schema = CompositePrimaryKey::SCHEMAS['TEXT_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 驗證必填欄位（c_role_id 為可選，預設為 0）
        CompositePrimaryKey::validateOrFail($pk, 'TEXT_DATA', ['c_role_id']);

        // 構建舊格式 ID 用於 Repository
        $id_ = $pk['c_personid']."-".$pk['c_textid']."-".($pk['c_role_id'] ?? 0);

        // 使用 Repository 進行刪除（內含事務與審計）
        $deleted = $this->biogMainRepository->textDeleteById($id_, $id);
        if (!$deleted) {
            abort(404, 'BIOG_TEXT_DATA 記錄不存在');
        }

        flash('Delete success @ '.Carbon::now(), 'success');

        return redirect()->route('basicinformation.texts.index', ['basicinformation' => $id]);
    }
}
