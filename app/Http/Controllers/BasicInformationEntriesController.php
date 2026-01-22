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

        return view('biogmains.entries.index', ['basicinformation' => $biogbasicinformation,
            'page_title' => '入仕', 'page_description' => '基本信息表 入仕', 'breadcrumb_home' => '人物基本資料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '入仕', 'url' => '#'],
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
            'page_title' => '入仕', 'page_description' => '基本信息表 入仕', 'page_url' => '/basicinformation/'.$id.'/entries', 'breadcrumb_home' => '人物基本資料', 'archer' => '<li>新增</li>',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '入仕', 'url' => route('basicinformation.entries.index', $id)],
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
        } elseif (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        //20181217建安遮除原本儲存方式，改以[别名]方式儲存。
        /*
        $_id = $this->biogMainRepository->entryStoreById($request, $id);
        flash('Store success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.entries.edit', [
            'basicinformation' => $id,
            'entry' => $_id,
        ]);
        */
        $data = $request->all();
        $data = Arr::except($data, ['_token']);
        $data['c_personid'] = $id;
        $data = $this->toolsRepository->timestamp($data, true);
        //20181217新增片段，api回傳值有-999需要轉為0
        $data['c_entry_code'] = $data['c_entry_code'] == -999 ? '0' : $data['c_entry_code'];
        $data['c_entry_addr_id'] = $data['c_entry_addr_id'] == -999 ? '0' : $data['c_entry_addr_id'];
        $data['c_kin_code'] = $data['c_kin_code'] == -999 ? '0' : $data['c_kin_code'];
        $data['c_assoc_code'] = $data['c_assoc_code'] == -999 ? '0' : $data['c_assoc_code'];
        $data['c_inst_code'] = $data['c_inst_code'] == -999 ? '0' : $data['c_inst_code'];
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        //新增結束
        //20210804在這裡處理c_inst_code傳遞過來的值，分別儲存至c_inst_code與c_inst_name_code欄位，$c_inst_name_code預設為0
        $temp = explode("-", $data['c_inst_code']);
        $c_inst_code = $temp[0];
        if (!empty($temp[1])) {
            $c_inst_name_code = $temp[1];
        } else {
            $c_inst_code = '0';
            $c_inst_name_code = '0';
        }

        if ($c_inst_name_code != '') {
            $data['c_inst_code'] = $c_inst_code;
            $data['c_inst_name_code'] = $c_inst_name_code;
        }
        //return $request;
        //修改結束
        $temp = DB::table('ENTRY_DATA')->where([
            ['c_personid', '=', $data['c_personid']],
            ['c_entry_code', '=', $data['c_entry_code']],
            ['c_sequence', '=', $data['c_sequence']],
            ['c_kin_code', '=', $data['c_kin_code']],
            ['c_assoc_code', '=', $data['c_assoc_code']],
            ['c_kin_id', '=', $data['c_kin_id']],
            ['c_year', '=', $data['c_year']],
            ['c_assoc_id', '=', $data['c_assoc_id']],
            ['c_inst_code', '=', $data['c_inst_code']],
            ['c_inst_name_code', '=', $data['c_inst_name_code']],
        ])->first();
        if (!blank($temp)) {
            flash('重复数据，保存失败 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        DB::table('ENTRY_DATA')->insert($data);
        $newKey = $data['c_personid'].'-'.$data['c_entry_code'].'-'.$data['c_sequence'].'-'.$data['c_kin_code'].'-'.$data['c_assoc_code'].'-'.$data['c_kin_id'].'-'.$data['c_year'].'-'.$data['c_assoc_id'].'-'.$data['c_inst_code'].'-'.$data['c_inst_name_code'];
        $this->operationRepository->store(Auth::id(), $id, 1, 'ENTRY_DATA', $newKey, $data);
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
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id) {
        //
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
            'page_title' => '入仕', 'page_description' => '基本信息表 入仕',
            'page_url' => '/basicinformation/'.$id.'/entries',
            'archer' => "<li>編輯</li>",
            'breadcrumb_home' => '人物基本資料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '入仕', 'url' => route('basicinformation.entries.index', $id)],
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
        //建安修改20181109
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

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
        $data = $request->all();
        $data = Arr::except($data, ['_method', '_token']);
        $data = $this->toolsRepository->timestamp($data);
        //20181217新增片段，api回傳值有-999需要轉為0
        $data['c_entry_code'] = $data['c_entry_code'] == -999 ? '0' : $data['c_entry_code'];
        $data['c_entry_addr_id'] = $data['c_entry_addr_id'] == -999 ? '0' : $data['c_entry_addr_id'];
        $data['c_kin_code'] = $data['c_kin_code'] == -999 ? '0' : $data['c_kin_code'];
        $data['c_assoc_code'] = $data['c_assoc_code'] == -999 ? '0' : $data['c_assoc_code'];
        $data['c_inst_code'] = $data['c_inst_code'] == -999 ? '0' : $data['c_inst_code'];
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        //新增結束
        //20210804在這裡處理c_inst_code傳遞過來的值，分別儲存至c_inst_code與c_inst_name_code欄位，$c_inst_name_code預設為0
        $temp = explode("-", $data['c_inst_code']);
        $c_inst_code = $temp[0];
        if (!empty($temp[1])) {
            $c_inst_name_code = $temp[1];
        } else {
            $c_inst_code = '0';
            $c_inst_name_code = '0';
        }

        if ($c_inst_name_code != '') {
            $data['c_inst_code'] = $c_inst_code;
            $data['c_inst_name_code'] = $c_inst_name_code;
        }
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
        $newid = $id.'-'.$data['c_entry_code'].'-'.$data['c_sequence'].'-'.$data['c_kin_code'].'-'.$data['c_assoc_code'].'-'.$data['c_kin_id'].'-'.$data['c_year'].'-'.$data['c_assoc_id'].'-'.$data['c_inst_code'].'-'.$data['c_inst_name_code'];
        $this->operationRepository->store(Auth::id(), $id, 3, 'ENTRY_DATA', $newid, $data, $ori);
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
    public function destroy($id, $id_) {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        //建安修改20191112
        //$this->biogMainRepository->entryDeleteById($id_, $id);
        $id_ = str_replace("--", "-minus", $id_);
        $addr_a = explode("-", $id_);
        foreach ($addr_a as $key => $value) {
            $addr_a[$key] = str_replace("minus", "-", $value);
        }
        $row = DB::table('ENTRY_DATA')->where([
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

        $this->operationRepository->store(Auth::id(), $id, 4, 'ENTRY_DATA', $id_, $row);
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
        ])->delete();
        flash('Delete success @ '.Carbon::now(), 'success');

        return redirect()->route('basicinformation.entries.index', ['basicinformation' => $id]);
    }

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
            'page_title' => '入仕',
            'page_description' => '基本信息表 入仕',
            'page_url' => '/basicinformation/'.$id.'/entries',
            'archer' => '<li>編輯</li>',
            'breadcrumb_home' => '人物基本資料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '入仕', 'url' => route('basicinformation.entries.index', $id)],
                ['label' => '编辑', 'url' => '#'],
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
            flash('请登入后编辑 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        if (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 從 URL 查詢字串提取原始 PK（用於定位記錄）
        // 注意：不能用 fromRequest()，因為它會合併查詢參數和表單 body
        $schema = CompositePrimaryKey::SCHEMAS['ENTRY_DATA'];
        $originalPk = [];
        foreach ($schema as $field) {
            $value = $request->query($field);
            if ($value !== null && $value !== '') {
                $originalPk[$field] = $value;
            }
        }

        // 驗證必填欄位
        CompositePrimaryKey::validateOrFail($originalPk, 'ENTRY_DATA');

        $data = $request->all();
        $data = Arr::except($data, ['_method', '_token']);
        $data = $this->toolsRepository->timestamp($data);

        // 處理 -999 轉為 0
        $data['c_entry_code'] = $data['c_entry_code'] == -999 ? '0' : $data['c_entry_code'];
        $data['c_entry_addr_id'] = $data['c_entry_addr_id'] == -999 ? '0' : $data['c_entry_addr_id'];
        $data['c_kin_code'] = $data['c_kin_code'] == -999 ? '0' : $data['c_kin_code'];
        $data['c_assoc_code'] = $data['c_assoc_code'] == -999 ? '0' : $data['c_assoc_code'];
        $data['c_inst_code'] = $data['c_inst_code'] == -999 ? '0' : $data['c_inst_code'];
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];

        // 處理 c_inst_code 分割
        $temp = explode("-", $data['c_inst_code']);
        $c_inst_code = $temp[0];
        if (!empty($temp[1])) {
            $c_inst_name_code = $temp[1];
        } else {
            $c_inst_code = '0';
            $c_inst_name_code = '0';
        }

        if ($c_inst_name_code != '') {
            $data['c_inst_code'] = $c_inst_code;
            $data['c_inst_name_code'] = $c_inst_name_code;
        }

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

        $newid = $id.'-'.$data['c_entry_code'].'-'.$data['c_sequence'].'-'.$data['c_kin_code'].'-'.$data['c_assoc_code'].'-'.$data['c_kin_id'].'-'.$data['c_year'].'-'.$data['c_assoc_id'].'-'.$data['c_inst_code'].'-'.$data['c_inst_name_code'];
        $this->operationRepository->store(Auth::id(), $id, 3, 'ENTRY_DATA', $newid, $data, $ori);
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
            flash('请登入后编辑 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        if (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 從查詢參數提取複合主鍵
        $schema = CompositePrimaryKey::SCHEMAS['ENTRY_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 驗證必填欄位
        CompositePrimaryKey::validateOrFail($pk, 'ENTRY_DATA');

        // 取得原始資料
        $row = DB::table('ENTRY_DATA')->where([
            ['c_personid', '=', $pk['c_personid']],
            ['c_entry_code', '=', $pk['c_entry_code']],
            ['c_sequence', '=', $pk['c_sequence']],
            ['c_kin_code', '=', $pk['c_kin_code']],
            ['c_assoc_code', '=', $pk['c_assoc_code']],
            ['c_kin_id', '=', $pk['c_kin_id']],
            ['c_year', '=', $pk['c_year']],
            ['c_assoc_id', '=', $pk['c_assoc_id']],
            ['c_inst_code', '=', $pk['c_inst_code']],
            ['c_inst_name_code', '=', $pk['c_inst_name_code']],
        ])->first();

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

        $this->operationRepository->store(Auth::id(), $id, 4, 'ENTRY_DATA', $id_, $row);

        DB::table('ENTRY_DATA')->where([
            ['c_personid', '=', $pk['c_personid']],
            ['c_entry_code', '=', $pk['c_entry_code']],
            ['c_sequence', '=', $pk['c_sequence']],
            ['c_kin_code', '=', $pk['c_kin_code']],
            ['c_assoc_code', '=', $pk['c_assoc_code']],
            ['c_kin_id', '=', $pk['c_kin_id']],
            ['c_year', '=', $pk['c_year']],
            ['c_assoc_id', '=', $pk['c_assoc_id']],
            ['c_inst_code', '=', $pk['c_inst_code']],
            ['c_inst_name_code', '=', $pk['c_inst_name_code']],
        ])->delete();

        flash('Delete success @ '.Carbon::now(), 'success');

        return redirect()->route('basicinformation.entries.index', ['basicinformation' => $id]);
    }
}
