<?php

namespace App\Http\Controllers;

use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BasicInformationSourcesController extends Controller {
    /**
     * @var BiogMainRepository
     */
    protected $biogMainRepository;

    /**
     * TextsController constructor.
     * @param BiogMainRepository $biogMainRepository
     */
    public function __construct(BiogMainRepository $biogMainRepository) {
        $this->biogMainRepository = $biogMainRepository;
        $this->middleware('auth')->except(['index', 'show', 'edit', 'editQuery']);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index($id) {
        $basicinformation = $this->biogMainRepository->byIdWithSources($id);

        if (blank($basicinformation)) {
            abort(404);
        }

        // 處理 basicinformation 可能為 null 或缺少字段的情況
        $personLabel = $id;

        try {
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

        return view('biogmains.sources.index', [
            'basicinformation' => $basicinformation,
            'page_title' => '出處',
            'page_description' => '基本信息表 出處',
            'breadcrumb_home' => '人物基本資料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '出处', 'url' => '#'],
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

        return view('biogmains.sources.create', [
            'id' => $id,
            'page_title' => '出處',
            'page_description' => '基本信息表 出處',
            'page_url' => '/basicinformation/'.$id.'/sources',
            'breadcrumb_home' => '人物基本資料',
            'archer' => '<li>新增</li>',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '出处', 'url' => route('basicinformation.sources.index', $id)],
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
        ]);

        // 檢查動作類型
        $action = $request->input('action', 'save');

        if ($action === 'proposal') {
            // 轉發到提案控制器
            return app(\App\Http\Controllers\BasicInformationProposalController::class)
                ->proposalStore($request, $id, 'sources');
        }

        if (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        $data = $this->biogMainRepository->sourceStoreById($request, $id);
        flash('Store success @ '.Carbon::now(), 'success');

        // 使用新的查詢參數模式重定向
        $newPk = [
            'c_personid' => $data['c_personid'],
            'c_textid' => $data['c_textid'],
            'c_pages' => $data['c_pages'] ?? '',
        ];

        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.sources.edit.query',
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
        $res = $this->biogMainRepository->sourceById($id, $id_);

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

        return view('biogmains.sources.edit', [
            'id' => $id,
            'row' => $res['row'],
            'res' => $res,
            'page_title' => '出處',
            'page_description' => '基本信息表 出處',
            'page_url' => '/basicinformation/'.$id.'/sources',
            'archer' => "<li>編輯</li>",
            'breadcrumb_home' => '人物基本資料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '出处', 'url' => route('basicinformation.sources.index', $id)],
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
        } elseif (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        $data = $this->biogMainRepository->sourceUpdateById($request, $id, $id_);
        flash('Update success @ '.Carbon::now(), 'success');

        // 使用新的查詢參數模式重定向
        $newPk = [
            'c_personid' => $id,
            'c_textid' => $data['c_textid'],
            'c_pages' => $data['c_pages'] ?? '',
        ];

        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.sources.edit.query',
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
     * 查詢參數模式：編輯出處記錄
     *
     * URL 格式：/basicinformation/{id}/sources/edit?c_personid=...&c_source_id=...
     *
     * @param Request $request
     * @param int $id 人物 ID
     * @return \Illuminate\Http\Response
     */
    public function editQuery(Request $request, $id) {
        // 從查詢參數提取複合主鍵
        $schema = CompositePrimaryKey::SCHEMAS['BIOG_SOURCE_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 驗證必填欄位（c_pages 可能為空字串）
        if (!isset($pk['c_personid']) || !isset($pk['c_textid'])) {
            abort(400, '缺少必要參數：c_personid 或 c_textid');
        }

        // 使用 Repository 查詢（格式：c_personid-c_textid-c_pages）
        $c_pages = $pk['c_pages'] ?? '';
        $encodedPages = $this->biogMainRepository->unionPKDef($c_pages);
        $id_ = $pk['c_personid'].'-'.$pk['c_textid'].'-'.$encodedPages;
        $res = $this->biogMainRepository->sourceById($id, $id_);

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

        return view('biogmains.sources.edit', [
            'id' => $id,
            'row' => $res['row'],
            'res' => $res,
            'pk' => $pk,
            'page_title' => '出處',
            'page_description' => '基本信息表 出處',
            'page_url' => '/basicinformation/'.$id.'/sources',
            'archer' => '<li>編輯</li>',
            'breadcrumb_home' => '人物基本資料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '出处', 'url' => route('basicinformation.sources.index', $id)],
                ['label' => '编辑', 'url' => '#'],
            ],
        ]);
    }

    /**
     * 查詢參數模式：更新出處記錄
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

        // 數據預處理
        $request->merge([
            'c_textid' => ($request->input('c_textid') == -999) ? '0' : ($request->input('c_textid') ?? '0'),
        ]);

        // 檢查動作類型
        $action = $request->input('action', 'save');

        if ($action === 'proposal') {
            // 提案模式需要從 URL 查詢字串取得原始 PK（而非表單提交的新值）
            $schema = CompositePrimaryKey::SCHEMAS['BIOG_SOURCE_DATA'];
            $originalPk = [];
            foreach ($schema as $field) {
                $value = $request->query($field);
                if ($value !== null) {
                    $originalPk[$field] = $value;
                }
            }

            // 使用新的查詢參數模式，直接傳遞主鍵陣列
            return app(\App\Http\Controllers\BasicInformationProposalController::class)
                ->proposalUpdateWithPk($request, $id, 'sources', $originalPk);
        }

        if (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 從查詢參數提取複合主鍵
        $schema = CompositePrimaryKey::SCHEMAS['BIOG_SOURCE_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 驗證必填欄位（c_pages 為可選）
        CompositePrimaryKey::validateOrFail($pk, 'BIOG_SOURCE_DATA', ['c_pages']);

        // 構建舊格式 ID（格式：c_personid-c_textid-c_pages）
        $c_pages = $pk['c_pages'] ?? '';
        $encodedPages = $this->biogMainRepository->unionPKDef($c_pages);
        $id_ = $pk['c_personid'].'-'.$pk['c_textid'].'-'.$encodedPages;

        // 使用 Repository 更新
        $data = $this->biogMainRepository->sourceUpdateById($request, $id, $id_);

        flash('Update success @ '.Carbon::now(), 'success');

        // 重定向到新的查詢參數格式
        $newPk = [
            'c_personid' => $id,
            'c_textid' => $data['c_textid'],
            'c_pages' => $data['c_pages'] ?? '',
        ];

        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.sources.edit.query',
            ['id' => $id],
            $newPk
        ));
    }

    /**
     * 查詢參數模式：刪除出處記錄
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
        $schema = CompositePrimaryKey::SCHEMAS['BIOG_SOURCE_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 驗證必填欄位（c_pages 為可選）
        CompositePrimaryKey::validateOrFail($pk, 'BIOG_SOURCE_DATA', ['c_pages']);

        $cPages = $pk['c_pages'] ?? '';
        $row = DB::table('BIOG_SOURCE_DATA')->where([
            ['c_personid', '=', $pk['c_personid']],
            ['c_textid', '=', $pk['c_textid']],
            ['c_pages', '=', $cPages],
        ])->first();

        if (!$row) {
            abort(404, 'BIOG_SOURCE_DATA 記錄不存在');
        }

        DB::table('BIOG_SOURCE_DATA')->where([
            ['c_personid', '=', $pk['c_personid']],
            ['c_textid', '=', $pk['c_textid']],
            ['c_pages', '=', $cPages],
        ])->delete();

        $operation = (new OperationRepository())->store(
            Auth::id(),
            $id,
            4,
            'BIOG_SOURCE_DATA',
            CompositePrimaryKey::buildStoredResourceId([
                'c_personid' => $pk['c_personid'],
                'c_textid' => $pk['c_textid'],
                'c_pages' => $cPages,
            ]),
            $row
        );

        (new AuditLogService())->write(
            'BIOG_SOURCE_DATA',
            'DELETE',
            [
                'c_personid' => $pk['c_personid'],
                'c_textid' => $pk['c_textid'],
                'c_pages' => $cPages,
            ],
            (new AuditLogService())->normalizeRow($row),
            null,
            'user',
            (string) Auth::id(),
            $operation ? (string) $operation->id : null
        );

        flash('Delete success @ '.Carbon::now(), 'success');

        return redirect()->route('basicinformation.sources.index', ['basicinformation' => $id]);
    }
}
