<?php

namespace App\Http\Controllers;

use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BasicInformationAssocController extends Controller {
    /**
     * @var BiogMainRepository
     */
    protected $biogMainRepository;

    /**
     * TextsController constructor.
     * @param BiogMainRepository $biogMainRepository
     */
    public function __construct(BiogMainRepository $biogMainRepository, OperationRepository $operationRepository) {
        $this->biogMainRepository = $biogMainRepository;
        $this->middleware('auth')->except(['index', 'show', 'edit', 'editQuery']);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index($id) {
        $biogbasicinformation = $this->biogMainRepository->byIdWithAssoc($id);
        //dd($biogbasicinformation);
        $assoc_name_id = [];
        $assoc_name_sequence = [];
        $assoc_name_name = [];
        $assoc_name_title = [];
        foreach ($biogbasicinformation->assoc_name as $key => $value) {
            $assoc_name_id[$key] = $value->pivot->c_assoc_id;
            $assoc_name_sequence[$key] = $value->c_sequence;
            $assoc_name_name[$key] = $value->c_name.' '.$value->c_name_chn;
            $title = '';
            $title = DB::table('ASSOC_DATA')->where([
                ['c_personid', '=', $value->pivot->c_personid],
                ['c_assoc_id', '=', $value->pivot->c_assoc_id],
                ['c_sequence', '=', $value->c_sequence],
            ])->first();
            $assoc_name_title[$key] = $title->c_text_title;
        }
        $assoc_name = $biogbasicinformation->assoc->map(function ($item, $key) use ($assoc_name_id, $assoc_name_sequence, $assoc_name_name, $assoc_name_title) {
            if (in_array($item->pivot->c_assoc_id, $assoc_name_id)) {
                return ['c_personid' => $item->pivot->c_assoc_id, 'c_sequence' => $assoc_name_sequence[$key], 'assoc_name' => $assoc_name_name[array_search($item->pivot->c_assoc_id, $assoc_name_id)], 'c_text_title' => $assoc_name_title[$key]];
            }

            return ['c_personid' => 0, 'c_sequence' => 0, 'assoc_name' => '', 'c_text_title' => ''];
        });

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

        //dd($biogbasicinformation);
        //dd($assoc_name);
        return view('biogmains.assoc.index', ['basicinformation' => $biogbasicinformation,
            'assoc_name' => $assoc_name, 'page_title' => '社會關係', 'page_description' => '基本信息表 社會關係', 'breadcrumb_home' => '人物基本資料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '社會關係', 'url' => '#'],
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

        return view('biogmains.assoc.create', [
            'id' => $id,
            'page_title' => '社會關係', 'page_description' => '基本信息表 社會關係', 'page_url' => '/basicinformation/'.$id.'/assoc', 'breadcrumb_home' => '人物基本資料', 'archer' => '<li>新增</li>',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '社會關係', 'url' => route('basicinformation.assoc.index', $id)],
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

        // 數據預處理：分割 c_inst_code
        // 這些預處理必須在提案 (proposal) 和直接儲存 (save) 之前完成
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
            // 處理年份缺省值，確保符合複合主鍵要求
            // 注意：c_text_title 允許為空字串，不需要強制填充 [n/a]，以免破壞現有空值資料的匹配
            if ($request->input('c_assoc_first_year') === null || $request->input('c_assoc_first_year') === '') {
                $request->merge(['c_assoc_first_year' => '-9999']);
            }

            // 轉發到提案控制器
            return app(\App\Http\Controllers\BasicInformationProposalController::class)
                ->proposalStore($request, $id, 'assoc');
        }

        if (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        //return $request;
        //修改結束
        $data = $this->biogMainRepository->assocStoreById($request, $id);
        flash('Store success @ '.Carbon::now(), 'success');

        // 使用新的查詢參數模式重定向
        $newPk = [
            'c_personid' => $data['c_personid'],
            'c_assoc_code' => $data['c_assoc_code'],
            'c_assoc_id' => $data['c_assoc_id'],
            'c_kin_code' => $data['c_kin_code'] ?? '0',
            'c_kin_id' => $data['c_kin_id'] ?? '0',
            'c_assoc_kin_code' => $data['c_assoc_kin_code'] ?? '0',
            'c_assoc_kin_id' => $data['c_assoc_kin_id'] ?? '0',
            'c_text_title' => $data['c_text_title'] ?? '',
            'c_assoc_first_year' => $data['c_assoc_first_year'] ?? '',
        ];

        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.assoc.edit.query',
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
        $res = $this->biogMainRepository->assocById($id_);

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

        return view('biogmains.assoc.edit', ['id' => $id, 'row' => $res['row'], 'res' => $res,
            'page_title' => '社會關係', 'page_description' => '基本信息表 社會關係',
            'page_url' => '/basicinformation/'.$id.'/assoc',
            'archer' => "<li>編輯</li>",
            'breadcrumb_home' => '人物基本資料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '社會關係', 'url' => route('basicinformation.assoc.index', $id)],
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

        //return $request;
        //修改結束
        $data = $this->biogMainRepository->assocUpdateById($request, $id_, $id);
        flash('Update success @ '.Carbon::now(), 'success');

        // 使用新的查詢參數模式重定向
        $newPk = [
            'c_personid' => $id,
            'c_assoc_code' => $data['c_assoc_code'],
            'c_assoc_id' => $data['c_assoc_id'],
            'c_kin_code' => $data['c_kin_code'] ?? '0',
            'c_kin_id' => $data['c_kin_id'] ?? '0',
            'c_assoc_kin_code' => $data['c_assoc_kin_code'] ?? '0',
            'c_assoc_kin_id' => $data['c_assoc_kin_id'] ?? '0',
            'c_text_title' => $data['c_text_title'] ?? '',
            'c_assoc_first_year' => $data['c_assoc_first_year'] ?? '',
        ];

        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.assoc.edit.query',
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
     * 查詢參數模式：編輯社會關係記錄
     *
     * URL 格式：/basicinformation/{id}/assoc/edit?c_personid=...&c_assoc_code=...&...
     *
     * ASSOC_DATA 有 9 個複合主鍵欄位，使用查詢參數模式可以完全避免編碼問題
     *
     * @param Request $request
     * @param int $id 人物 ID
     * @return \Illuminate\Http\Response
     */
    public function editQuery(Request $request, $id) {
        // 從查詢參數提取複合主鍵
        $schema = CompositePrimaryKey::SCHEMAS['ASSOC_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 驗證必填欄位
        $required = ['c_personid', 'c_assoc_code', 'c_assoc_id'];
        foreach ($required as $field) {
            if (!isset($pk[$field]) || $pk[$field] === '') {
                abort(400, "缺少必要參數：{$field}");
            }
        }

        // 構建舊格式 ID（用於 Repository）
        // 格式：c_personid-c_assoc_code-c_assoc_id-c_kin_code-c_kin_id-c_assoc_kin_code-c_assoc_kin_id-c_text_title-c_assoc_first_year
        // 注意：c_assoc_first_year 可能包含負號（如 -9999），需要編碼為 (minus) 避免解析錯誤
        $assocFirstYear = $pk['c_assoc_first_year'] ?? '-9999';
        $assocFirstYearEncoded = str_replace('-', '(minus)', $assocFirstYear);
        $id_ = $pk['c_personid']."-".
               ($pk['c_assoc_code'] ?? '0')."-".
               ($pk['c_assoc_id'] ?? '0')."-".
               ($pk['c_kin_code'] ?? '0')."-".
               ($pk['c_kin_id'] ?? '0')."-".
               ($pk['c_assoc_kin_code'] ?? '0')."-".
               ($pk['c_assoc_kin_id'] ?? '0')."-".
               ($pk['c_text_title'] ?? '')."-".
               $assocFirstYearEncoded;

        $res = $this->biogMainRepository->assocById($id_);

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

        return view('biogmains.assoc.edit', [
            'id' => $id,
            'row' => $res['row'],
            'res' => $res,
            'pk' => $pk,
            'page_title' => '社會關係',
            'page_description' => '基本信息表 社會關係',
            'page_url' => '/basicinformation/'.$id.'/assoc',
            'archer' => '<li>編輯</li>',
            'breadcrumb_home' => '人物基本資料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '社會關係', 'url' => route('basicinformation.assoc.index', $id)],
                ['label' => '编辑', 'url' => '#'],
            ],
        ]);
    }

    /**
     * 查詢參數模式：更新社會關係記錄
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
            // 處理年份缺省值
            if ($request->input('c_assoc_first_year') === null || $request->input('c_assoc_first_year') === '') {
                $request->merge(['c_assoc_first_year' => '-9999']);
            }

            // 提案模式需要從 URL 查詢字串取得原始 PK（而非表單提交的新值）
            $schema = CompositePrimaryKey::SCHEMAS['ASSOC_DATA'];
            $originalPk = [];
            foreach ($schema as $field) {
                $value = $request->query($field);
                if ($value !== null) {
                    $originalPk[$field] = $value;
                }
            }

            // 使用新的查詢參數模式，直接傳遞主鍵陣列
            return app(\App\Http\Controllers\BasicInformationProposalController::class)
                ->proposalUpdateWithPk($request, $id, 'assoc', $originalPk);
        }

        if (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 從查詢參數提取複合主鍵
        $schema = CompositePrimaryKey::SCHEMAS['ASSOC_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 驗證必填欄位（ASSOC_DATA 有較多可選欄位）
        $optional = ['c_kin_code', 'c_kin_id', 'c_assoc_kin_code', 'c_assoc_kin_id', 'c_text_title', 'c_assoc_first_year'];
        CompositePrimaryKey::validateOrFail($pk, 'ASSOC_DATA', $optional);

        // 構建舊格式 ID
        // 格式：c_personid-c_assoc_code-c_assoc_id-c_kin_code-c_kin_id-c_assoc_kin_code-c_assoc_kin_id-c_text_title-c_assoc_first_year
        // 注意：c_assoc_first_year 可能包含負號（如 -9999），需要編碼為 (minus) 避免解析錯誤
        $assocFirstYear = $pk['c_assoc_first_year'] ?? '-9999';
        $assocFirstYearEncoded = str_replace('-', '(minus)', $assocFirstYear);
        $id_ = $pk['c_personid']."-".
               ($pk['c_assoc_code'] ?? '0')."-".
               ($pk['c_assoc_id'] ?? '0')."-".
               ($pk['c_kin_code'] ?? '0')."-".
               ($pk['c_kin_id'] ?? '0')."-".
               ($pk['c_assoc_kin_code'] ?? '0')."-".
               ($pk['c_assoc_kin_id'] ?? '0')."-".
               ($pk['c_text_title'] ?? '')."-".
               $assocFirstYearEncoded;

        // 使用 Repository 更新
        $data = $this->biogMainRepository->assocUpdateById($request, $id_, $id);

        flash('Update success @ '.Carbon::now(), 'success');

        // 重定向到新的查詢參數格式
        $newPk = [
            'c_personid' => $id,
            'c_assoc_code' => $data['c_assoc_code'] ?? $pk['c_assoc_code'],
            'c_assoc_id' => $data['c_assoc_id'] ?? $pk['c_assoc_id'],
            'c_kin_code' => $data['c_kin_code'] ?? $pk['c_kin_code'] ?? '0',
            'c_kin_id' => $data['c_kin_id'] ?? $pk['c_kin_id'] ?? '0',
            'c_assoc_kin_code' => $data['c_assoc_kin_code'] ?? $pk['c_assoc_kin_code'] ?? '0',
            'c_assoc_kin_id' => $data['c_assoc_kin_id'] ?? $pk['c_assoc_kin_id'] ?? '0',
            'c_text_title' => $data['c_text_title'] ?? $pk['c_text_title'] ?? '',
            'c_assoc_first_year' => $data['c_assoc_first_year'] ?? $pk['c_assoc_first_year'] ?? '',
        ];

        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.assoc.edit.query',
            ['id' => $id],
            $newPk
        ));
    }

    /**
     * 查詢參數模式：刪除社會關係記錄
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
        $schema = CompositePrimaryKey::SCHEMAS['ASSOC_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 驗證必填欄位（ASSOC_DATA 有較多可選欄位）
        $optional = ['c_kin_code', 'c_kin_id', 'c_assoc_kin_code', 'c_assoc_kin_id', 'c_text_title', 'c_assoc_first_year'];
        CompositePrimaryKey::validateOrFail($pk, 'ASSOC_DATA', $optional);

        // 構建舊格式 ID
        // 格式：c_personid-c_assoc_code-c_assoc_id-c_kin_code-c_kin_id-c_assoc_kin_code-c_assoc_kin_id-c_text_title-c_assoc_first_year
        // 注意：c_assoc_first_year 可能包含負號（如 -9999），需要編碼為 (minus) 避免解析錯誤
        $assocFirstYear = $pk['c_assoc_first_year'] ?? '-9999';
        $assocFirstYearEncoded = str_replace('-', '(minus)', $assocFirstYear);
        $id_ = $pk['c_personid']."-".
               ($pk['c_assoc_code'] ?? '0')."-".
               ($pk['c_assoc_id'] ?? '0')."-".
               ($pk['c_kin_code'] ?? '0')."-".
               ($pk['c_kin_id'] ?? '0')."-".
               ($pk['c_assoc_kin_code'] ?? '0')."-".
               ($pk['c_assoc_kin_id'] ?? '0')."-".
               ($pk['c_text_title'] ?? '')."-".
               $assocFirstYearEncoded;

        $this->biogMainRepository->assocDeleteById($id_, $id);

        flash('Delete success @ '.Carbon::now(), 'success');

        return redirect()->route('basicinformation.assoc.index', ['basicinformation' => $id]);
    }
}
