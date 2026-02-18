<?php

namespace App\Http\Controllers;

use App\Models\TextCode;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\Services\NameSearchIndexService;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Http\Request;
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
            flash('請登入後編輯 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        if (!Auth::user()->isActive()) {
            flash('該使用者沒有權限，請聯繫管理員 @ '.Carbon::now(), 'error');

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
            flash('該使用者沒有權限，請聯繫管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 使用 Repository 進行儲存（內含事務與審計）
        $data = $this->biogMainRepository->altnameStoreById($request, $id);
        if ($data === false) {
            flash('重複資料，儲存失敗 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 手動調用索引服務
        if (Schema::hasTable('CBDB__NAME_FTS') && !empty($data['c_alt_name_chn'])) {
            $this->nameSearchIndexService->indexAltname(
                $data['c_personid'],
                $data['c_alt_name_type_code'],
                $data['c_alt_name_chn']
            );
        }

        flash('Store success @ '.Carbon::now(), 'success');

        // (#834 Phase 2): 使用 3-key 重定向，c_sequence 不參與定位
        $newPk = [
            'c_personid' => $data['c_personid'],
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
        $row = $this->biogMainRepository->altnameById($alt);

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
            flash('請登入後編輯 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        if (!Auth::user()->isActive()) {
            flash('該使用者沒有權限，請聯繫管理員 @ '.Carbon::now(), 'error');

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
            // (#834 Phase 2): 使用 3-key 關聯陣列
            $originalPk = $this->parseAltnameId($alt);

            return app(\App\Http\Controllers\BasicInformationProposalController::class)
                ->proposalUpdateWithPk($request, $id, 'altnames', $originalPk);
        }

        // 直接儲存需要額外權限檢查
        if (!Auth::user()->canWriteDirectly()) {
            flash('該使用者沒有權限，請聯繫管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 使用 Repository 進行更新（內含事務與審計，且修復 LIKE 謂詞風險）
        $ori = $this->biogMainRepository->altnameById($alt);
        $newPk = $this->biogMainRepository->altnameUpdateById($request, $id, $alt);
        if (!$newPk) {
            abort(404, 'ALTNAME_DATA 記錄不存在');
        }

        // 手動調用索引服務
        if ($ori && Schema::hasTable('CBDB__NAME_FTS')) {
            $data = $request->all();
            $nameChanged = $ori->c_alt_name_chn !== ($data['c_alt_name_chn'] ?? $ori->c_alt_name_chn);
            $typeChanged = $ori->c_alt_name_type_code !== ($data['c_alt_name_type_code'] ?? $ori->c_alt_name_type_code);

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
                $newAltNameChn = $data['c_alt_name_chn'] ?? $ori->c_alt_name_chn;
                if (!empty($newAltNameChn)) {
                    $this->nameSearchIndexService->indexAltname(
                        $id,
                        $data['c_alt_name_type_code'] ?? $ori->c_alt_name_type_code,
                        $newAltNameChn
                    );
                }
            }
        }

        flash('Update success @ '.Carbon::now(), 'success');

        // 使用新的查詢參數模式重定向
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
     * 解析別名複合主鍵 ID 為 3-key 關聯陣列
     *
     * (#834 Phase 2): 委派給 Repository，支援歷史 4-key 與新 3-key 格式。
     *
     * @param string $alt 複合主鍵 ID
     * @return array 3-key 關聯陣列 ['c_personid' => ..., 'c_alt_name_chn' => ..., 'c_alt_name_type_code' => ...]
     */
    protected function parseAltnameId($alt) {
        return $this->biogMainRepository->parseAltnameId($alt);
    }

    // ===================================================================
    // 查詢參數模式方法（推薦使用）
    // 使用 HTTP 查詢參數傳遞複合主鍵，避免自定義編碼邏輯
    // 參考：docs/COMPOSITE_PRIMARY_KEY_URL_DESIGN.md
    // ===================================================================

    /**
     * 查詢參數模式：編輯別名記錄
     *
     * URL 格式：/basicinformation/{id}/altnames/edit?c_personid=...&c_alt_name_chn=...&c_alt_name_type_code=...
     *
     * @param Request $request
     * @param int $id 人物 ID
     * @return \Illuminate\Http\Response
     */
    public function editQuery(Request $request, $id) {
        // (#834 Phase 2): 從查詢參數提取 3-key
        $schema = CompositePrimaryKey::SCHEMAS['ALTNAME_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 驗證必填欄位
        CompositePrimaryKey::validateOrFail($pk, 'ALTNAME_DATA');

        // 構建 3-key 查詢條件
        $row = DB::table('ALTNAME_DATA')->where([
            ['c_personid', '=', $pk['c_personid']],
            ['c_alt_name_chn', '=', $pk['c_alt_name_chn']],
            ['c_alt_name_type_code', '=', $pk['c_alt_name_type_code']],
        ])->first();

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
            flash('請登入後編輯 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        if (!Auth::user()->isActive()) {
            flash('該使用者沒有權限，請聯繫管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 檢查動作類型
        $action = $request->input('action', 'save');

        if ($action === 'proposal') {
            // (#834 Phase 2): 從 URL 查詢字串取得 3-key 原始 PK
            $schema = CompositePrimaryKey::SCHEMAS['ALTNAME_DATA'];
            $originalPk = [];
            foreach ($schema as $field) {
                $value = $request->query($field);
                if ($value !== null) {
                    $originalPk[$field] = $value;
                }
            }

            return app(\App\Http\Controllers\BasicInformationProposalController::class)
                ->proposalUpdateWithPk($request, $id, 'altnames', $originalPk);
        }

        // 直接儲存需要額外權限檢查
        if (!Auth::user()->canWriteDirectly()) {
            flash('該使用者沒有權限，請聯繫管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // (#834 Phase 2): 從 URL 查詢字串提取 3-key 原始 PK
        $schema = CompositePrimaryKey::SCHEMAS['ALTNAME_DATA'];
        $originalPk = [];
        foreach ($schema as $field) {
            $value = $request->query($field);
            if ($value !== null) {
                $originalPk[$field] = $value;
            }
        }

        CompositePrimaryKey::validateOrFail($originalPk, 'ALTNAME_DATA');

        // 取得原始資料（供索引更新判斷）
        $ori = $this->biogMainRepository->altnameById($originalPk);
        if (!$ori) {
            abort(404, 'ALTNAME_DATA 記錄不存在');
        }

        // 保留原始請求資料供索引差異判斷
        $data = $request->all();
        $newPk = $this->biogMainRepository->altnameUpdateById($request, $id, $originalPk);
        if (!$newPk) {
            abort(404, 'ALTNAME_DATA 記錄不存在');
        }

        // 更新索引
        if ($ori && Schema::hasTable('CBDB__NAME_FTS')) {
            $nameChanged = ($ori->c_alt_name_chn ?? null) !== ($data['c_alt_name_chn'] ?? $originalPk['c_alt_name_chn'] ?? null);
            $typeChanged = ($ori->c_alt_name_type_code ?? null) !== ($data['c_alt_name_type_code'] ?? $originalPk['c_alt_name_type_code'] ?? null);

            if ($nameChanged || $typeChanged) {
                if ($ori->c_alt_name_chn) {
                    $this->nameSearchIndexService->removeAltname(
                        $ori->c_personid,
                        $ori->c_alt_name_type_code,
                        $ori->c_alt_name_chn
                    );
                }

                $newAltNameChn = $data['c_alt_name_chn'] ?? $originalPk['c_alt_name_chn'] ?? null;
                if (!empty($newAltNameChn)) {
                    $this->nameSearchIndexService->indexAltname(
                        $newPk['c_personid'] ?? $originalPk['c_personid'],
                        $data['c_alt_name_type_code'] ?? $originalPk['c_alt_name_type_code'] ?? null,
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
            flash('請登入後編輯 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        if (!Auth::user()->canWriteDirectly()) {
            flash('該使用者沒有權限，請聯繫管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // (#834 Phase 2): 從查詢參數提取 3-key
        $schema = CompositePrimaryKey::SCHEMAS['ALTNAME_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);
        CompositePrimaryKey::validateOrFail($pk, 'ALTNAME_DATA');

        // 取得原始資料用於索引清理
        $row = $this->biogMainRepository->altnameById($pk);
        if (!$row) {
            abort(404, 'ALTNAME_DATA 記錄不存在');
        }

        // 使用 Repository 進行刪除（內含事務與審計）
        $deleted = $this->biogMainRepository->altnameDeleteById($pk, $id);
        if (!$deleted) {
            abort(404, 'ALTNAME_DATA 記錄不存在');
        }

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
