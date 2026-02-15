<?php

namespace App\Http\Controllers;

use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BasicInformationSocialInstController extends Controller {
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
        $biogbasicinformation = $this->biogMainRepository->byIdWithSocialInst($id);

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

        return view('biogmains.socialinst.index', ['basicinformation' => $biogbasicinformation,
            'page_title' => '社交機構', 'page_description' => '基本信息表 社交機構', 'breadcrumb_home' => '人物基本資料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '社交機構', 'url' => '#'],
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

        return view('biogmains.socialinst.create', [
            'id' => $id,
            'page_title' => '社交機構', 'page_description' => '基本信息表 社交機構', 'page_url' => '/basicinformation/'.$id.'/socialinst', 'breadcrumb_home' => '人物基本資料', 'archer' => '<li>新增</li>',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '社交機構', 'url' => route('basicinformation.socialinst.index', $id)],
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

        // 數據預處理：處理 -999 轉為 0 並分割 c_inst_code
        // 這些預處理必須在提案 (proposal) 和直接儲存 (save) 之前完成
        $request->merge([
            'c_bi_role_code' => ($request->input('c_bi_role_code') == -999) ? '0' : ($request->input('c_bi_role_code') ?? '0'),
            'c_source' => ($request->input('c_source') == -999) ? '0' : ($request->input('c_source') ?? '0'),
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
            // 轉發到提案控制器
            return app(\App\Http\Controllers\BasicInformationProposalController::class)
                ->proposalStore($request, $id, 'socialinst');
        }

        if (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        //return $request;
        //修改結束
        $_id = $this->biogMainRepository->socialInstStoreById($request, $id);
        flash('Store success @ '.Carbon::now(), 'success');

        // 解析複合主鍵（socialInstStoreById 返回查詢參數格式，如 c_personid=123&c_inst_code=456&...）
        $newPk = CompositePrimaryKey::parseStoredResourceId($_id, 'BIOG_INST_DATA');
        if ($newPk === null) {
            \Log::error('socialInstStoreById 返回的 resource_id 無法解析', ['resource_id' => $_id]);

            return redirect(route('basicinformation.socialinst.index', $id, false));
        }

        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.socialinst.edit.query',
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
        $res = $this->biogMainRepository->socialInstById($id_);

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

        return view('biogmains.socialinst.edit', ['id' => $id, 'row' => $res['row'], 'res' => $res,
            'page_title' => '社交機構', 'page_description' => '基本信息表 社交機構',
            'page_url' => '/basicinformation/'.$id.'/socialinst',
            'archer' => "<li>編輯</li>",
            'breadcrumb_home' => '人物基本資料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '社交機構', 'url' => route('basicinformation.socialinst.index', $id)],
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

        // 數據預處理
        $request->merge([
            'c_bi_role_code' => ($request->input('c_bi_role_code') == -999) ? '0' : ($request->input('c_bi_role_code') ?? '0'),
            'c_source' => ($request->input('c_source') == -999) ? '0' : ($request->input('c_source') ?? '0'),
        ]);

        // 數據預處理：分割 c_inst_code
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

        if (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        $data = $request->all();
        $data = Arr::except($data, ['_method', '_token', 'action']);
        $data = $this->toolsRepository->timestamp($data);
        //return $request;
        //修改結束 //20211020修改增加c_bi_begin_year與c_bi_end_year
        $addr_l = explode("-", $id_);
        if ($addr_l[1] == '') {
            $addr_l[1] = null;
        }
        if ($addr_l[2] == '') {
            $addr_l[2] = null;
        }

        $ori = DB::table('BIOG_INST_DATA')->where([
                ['c_personid', '=', $addr_l[0]],
                ['c_inst_code', '=', $addr_l[1]],
                ['c_inst_name_code', '=', $addr_l[2]],
                ['c_bi_role_code', '=', $addr_l[3]],
        ])->first();

        DB::table('BIOG_INST_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_inst_code', '=', $addr_l[1]],
            ['c_inst_name_code', '=', $addr_l[2]],
            ['c_bi_role_code', '=', $addr_l[3]],
        ])->update($data);
        $this->operationRepository->store(Auth::id(), $id, 3, 'BIOG_INST_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $id,
            'c_inst_code' => $data['c_inst_code'],
            'c_inst_name_code' => $data['c_inst_name_code'],
            'c_bi_role_code' => $data['c_bi_role_code'],
        ]), $data, $ori);
        flash('Update success @ '.Carbon::now(), 'success');

        // 使用新的查詢參數模式重定向
        $newPk = [
            'c_personid' => $id,
            'c_inst_code' => $data['c_inst_code'],
            'c_inst_name_code' => $data['c_inst_name_code'],
            'c_bi_role_code' => $data['c_bi_role_code'],
        ];

        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.socialinst.edit.query',
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
     * 查詢參數模式：編輯社交機構記錄
     *
     * URL 格式：/basicinformation/{id}/socialinst/edit?c_personid=...&c_inst_code=...&c_inst_name_code=...&c_bi_role_code=...
     *
     * @param Request $request
     * @param int $id 人物 ID
     * @return \Illuminate\Http\Response
     */
    public function editQuery(Request $request, $id) {
        // 從查詢參數提取複合主鍵
        $schema = CompositePrimaryKey::SCHEMAS['BIOG_INST_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 驗證必填欄位
        CompositePrimaryKey::validateOrFail($pk, 'BIOG_INST_DATA');

        // 使用 Repository 查詢（格式：c_personid-c_inst_code-c_inst_name_code-c_bi_role_code）
        $id_ = $pk['c_personid'].'-'.$pk['c_inst_code'].'-'.$pk['c_inst_name_code'].'-'.$pk['c_bi_role_code'];
        $res = $this->biogMainRepository->socialInstById($id_);

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

        return view('biogmains.socialinst.edit', [
            'id' => $id,
            'row' => $res['row'],
            'res' => $res,
            'pk' => $pk,
            'page_title' => '社交機構',
            'page_description' => '基本信息表 社交機構',
            'page_url' => '/basicinformation/'.$id.'/socialinst',
            'archer' => '<li>編輯</li>',
            'breadcrumb_home' => '人物基本資料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '社交機構', 'url' => route('basicinformation.socialinst.index', $id)],
                ['label' => '编辑', 'url' => '#'],
            ],
        ]);
    }

    /**
     * 查詢參數模式：更新社交機構記錄
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

        // 數據預處理：分割 c_inst_code
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
            $schema = CompositePrimaryKey::SCHEMAS['BIOG_INST_DATA'];
            $originalPk = [];
            foreach ($schema as $field) {
                $value = $request->query($field);
                if ($value !== null) {
                    $originalPk[$field] = $value;
                }
            }

            // 使用新的查詢參數模式，直接傳遞主鍵陣列
            return app(\App\Http\Controllers\BasicInformationProposalController::class)
                ->proposalUpdateWithPk($request, $id, 'socialinst', $originalPk);
        }

        if (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 從 URL 查詢字串提取原始 PK（用於定位記錄）
        // 注意：不能用 fromRequest()，因為它會合併查詢參數和表單 body
        $schema = CompositePrimaryKey::SCHEMAS['BIOG_INST_DATA'];
        $originalPk = [];
        foreach ($schema as $field) {
            $value = $request->query($field);
            if ($value !== null) {
                $originalPk[$field] = $value;
            }
        }

        // 驗證必填欄位
        CompositePrimaryKey::validateOrFail($originalPk, 'BIOG_INST_DATA');

        $data = $request->all();
        $data = Arr::except($data, ['_method', '_token', 'action']);
        $data = $this->toolsRepository->timestamp($data);

        // 取得原始資料（使用原始 PK）
        $ori = DB::table('BIOG_INST_DATA')->where([
            ['c_personid', '=', $originalPk['c_personid']],
            ['c_inst_code', '=', $originalPk['c_inst_code']],
            ['c_inst_name_code', '=', $originalPk['c_inst_name_code']],
            ['c_bi_role_code', '=', $originalPk['c_bi_role_code']],
        ])->first();

        if (!$ori) {
            abort(404, 'BIOG_INST_DATA 記錄不存在');
        }

        // 更新（使用原始 PK 定位）
        DB::table('BIOG_INST_DATA')->where([
            ['c_personid', '=', $originalPk['c_personid']],
            ['c_inst_code', '=', $originalPk['c_inst_code']],
            ['c_inst_name_code', '=', $originalPk['c_inst_name_code']],
            ['c_bi_role_code', '=', $originalPk['c_bi_role_code']],
        ])->update($data);

        $this->operationRepository->store(Auth::id(), $id, 3, 'BIOG_INST_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $id,
            'c_inst_code' => $data['c_inst_code'],
            'c_inst_name_code' => $data['c_inst_name_code'],
            'c_bi_role_code' => $data['c_bi_role_code'],
        ]), $data, $ori);
        flash('Update success @ '.Carbon::now(), 'success');

        // 重定向到新的查詢參數格式
        $newPk = [
            'c_personid' => $id,
            'c_inst_code' => $data['c_inst_code'],
            'c_inst_name_code' => $data['c_inst_name_code'],
            'c_bi_role_code' => $data['c_bi_role_code'],
        ];

        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.socialinst.edit.query',
            ['id' => $id],
            $newPk
        ));
    }

    /**
     * 查詢參數模式：刪除社交機構記錄
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
        $schema = CompositePrimaryKey::SCHEMAS['BIOG_INST_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 驗證必填欄位
        CompositePrimaryKey::validateOrFail($pk, 'BIOG_INST_DATA');

        // 取得原始資料
        $row = DB::table('BIOG_INST_DATA')->where([
            ['c_personid', '=', $pk['c_personid']],
            ['c_inst_code', '=', $pk['c_inst_code']],
            ['c_inst_name_code', '=', $pk['c_inst_name_code']],
            ['c_bi_role_code', '=', $pk['c_bi_role_code']],
        ])->first();

        $this->operationRepository->store(Auth::id(), $id, 4, 'BIOG_INST_DATA', CompositePrimaryKey::buildStoredResourceId($pk), $row);

        DB::table('BIOG_INST_DATA')->where([
            ['c_personid', '=', $pk['c_personid']],
            ['c_inst_code', '=', $pk['c_inst_code']],
            ['c_inst_name_code', '=', $pk['c_inst_name_code']],
            ['c_bi_role_code', '=', $pk['c_bi_role_code']],
        ])->delete();

        flash('Delete success @ '.Carbon::now(), 'success');

        return redirect()->route('basicinformation.socialinst.index', ['basicinformation' => $id]);
    }
}
