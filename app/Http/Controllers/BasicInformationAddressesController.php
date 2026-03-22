<?php

namespace App\Http\Controllers;

use App\Models\AddrBelong;
use App\Models\AddrCode;
use App\Models\AddressCode;
use App\Models\TextCode;
use App\Repositories\BiogMainRepository;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BasicInformationAddressesController extends Controller {
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
        $biogbasicinformation = $this->biogMainRepository->byIdWithAddr($id);

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

        return view('biogmains.addresses.index', [
            'basicinformation' => $biogbasicinformation,
            'page_title' => '地址',
            'page_description' => '基本信息表 地址',
            'breadcrumb_home' => '人物基本資料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '地址', 'url' => '#'],
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

        return view('biogmains.addresses.create', [
            'id' => $id,
            'page_title' => '地址',
            'page_description' => '基本信息表 地址',
            'page_url' => '/basicinformation/'.$id.'/addresses',
            'breadcrumb_home' => '人物基本資料',
            'archer' => '<li>新增</li>',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '地址', 'url' => route('basicinformation.addresses.index', $id)],
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
            'c_addr_id' => ($request->input('c_addr_id') == -999) ? '0' : ($request->input('c_addr_id') ?? '0'),
            'c_source' => ($request->input('c_source') == -999) ? '0' : ($request->input('c_source') ?? '0'),
        ]);

        // 檢查動作類型
        $action = $request->input('action', 'save');

        if ($action === 'proposal') {
            // 轉發到提案控制器
            return app(\App\Http\Controllers\BasicInformationProposalController::class)
                ->proposalStore($request, $id, 'addresses');
        }

        if (!Auth::user()->canWriteDirectly()) {
            flash('該使用者沒有權限，請聯繫管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 使用 Repository 進行儲存（內含事務與審計）
        $data = $this->biogMainRepository->addrStoreById($request, $id);
        if ($data === false) {
            flash('重複資料，儲存失敗 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        flash('Store success @ '.Carbon::now(), 'success');

        // 使用新的查詢參數模式重定向
        $newPk = [
            'c_personid' => $data['c_personid'],
            'c_addr_id' => $data['c_addr_id'],
            'c_addr_type' => $data['c_addr_type'],
            'c_sequence' => $data['c_sequence'],
        ];

        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.addresses.edit.query',
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
    public function edit($id, $addr) {
        $addr = str_replace("--", "-minus", $addr);
        $addr_l = explode("-", $addr);
        foreach ($addr_l as $key => $value) {
            $addr_l[$key] = str_replace("minus", "-", $value);
        }
        //        dd($addr_l);
        $row = DB::table('BIOG_ADDR_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_addr_id', '=', $addr_l[1]],
            ['c_addr_type', '=', $addr_l[2]],
            ['c_sequence', '=', $addr_l[3]],
        ])->first();
        $addr_str = null;
        $other_belongs_str = null;
        if ($row->c_addr_id || $row->c_addr_id === 0) {
            //20210805修改「地址」中利用 ADDRESSES 表和 ADDR_CODES 表
            //$addr_ = AddressCode::find($row->c_addr_id);
            //$addr_str = $addr_->c_addr_id." ".$addr_->c_name." ".$addr_->c_name_chn." ".$addr_->c_firstyear."~".$addr_->c_lastyear;
            $item = AddrCode::find($row->c_addr_id);
            $belongs = "";
            $originalText = $item->c_addr_id." ".$item->c_name." ".$item->c_name_chn." ".trim($belongs)." ".$item->c_firstyear."~".$item->c_lastyear;
            $add = "";
            //$dy = AddrBelong::where('c_addr_id', $item->c_addr_id)->value('c_belongs_to');
            //$dy2 = AddrCode::where('c_addr_id', $dy)->value('c_name_chn');
            // if($dy == null) {
            //     $dy = 0; $add = "";
            // }
            // else {
            //     $dy2 = AddrCode::where('c_addr_id', $dy)->value('c_name_chn');
            //     $add = "[[".$dy." ".$dy2."]]";
            // }
            // $addr_str = $originalText." ".$add;
            //修改結束

            //20231002賢瑛修改
            $add = [];
            $dy = AddrBelong::where('c_addr_id', '=', $item->c_addr_id)->get();
            if ($dy->isEmpty()) {
                $add[] = "";
            } else {
                foreach ($dy as $d) {
                    //找出上一層資料
                    $dy2 = AddrCode::where('c_addr_id', '=', $d->c_belongs_to)->first();
                    if (!$dy2->empty) {
                        $add_str = "[[".$dy2->c_addr_id." ".$dy2->c_name_chn." ".$dy2->c_firstyear."~".$dy2->c_lastyear."]]";
                        $add[] = $add_str;
                    } else {
                        $add[] = "";
                    }
                }
            }
            $addr_str = trim($originalText." ".$add[0]);

            if (count($add) > 1) {
                for ($i = 1; $i < count($add); $i++) {
                    if ($i > 1) {
                        $other_belongs_str = $other_belongs_str."、".trim($add[$i]);
                    } else {
                        $other_belongs_str = $other_belongs_str.trim($add[$i]);
                    }
                }
            }
        }
        $text_str = null;
        //      dd($row->c_source);
        if ($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;
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

        return view('biogmains.addresses.edit', [
            'id' => $id,
            'row' => $row,
            'addr_str' => $addr_str,
            'text_str' => $text_str,
            'page_title' => '地址',
            'page_description' => '基本信息表 地址',
            'page_url' => '/basicinformation/'.$id.'/addresses',
            'archer' => "<li>編輯</li>",
            'other_belongs_str' => $other_belongs_str,
            'breadcrumb_home' => '人物基本資料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '地址', 'url' => route('basicinformation.addresses.index', $id)],
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
    public function update(Request $request, $id, $addr) {
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
            'c_addr_id' => ($request->input('c_addr_id') == -999) ? '0' : ($request->input('c_addr_id') ?? '0'),
            'c_source' => ($request->input('c_source') == -999) ? '0' : ($request->input('c_source') ?? '0'),
        ]);

        // 檢查動作類型
        $action = $request->input('action', 'save');

        if ($action === 'proposal') {
            // 解析舊格式的複合主鍵，轉換為陣列
            $addr_l = explode("-", str_replace("--", "-minus", $addr));
            $originalPk = [
                'c_personid' => str_replace("minus", "-", $addr_l[0]),
                'c_addr_id' => str_replace("minus", "-", $addr_l[1]),
                'c_addr_type' => str_replace("minus", "-", $addr_l[2]),
                'c_sequence' => str_replace("minus", "-", $addr_l[3]),
            ];

            // 使用新的查詢參數模式，直接傳遞主鍵陣列
            return app(\App\Http\Controllers\BasicInformationProposalController::class)
                ->proposalUpdateWithPk($request, $id, 'addresses', $originalPk);
        }

        // 直接儲存需要額外權限檢查
        if (!Auth::user()->canWriteDirectly()) {
            flash('該使用者沒有權限，請聯繫管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 使用 Repository 進行更新（內含事務與審計）
        $newPk = $this->biogMainRepository->addrUpdateById($request, $id, $addr);
        if (!$newPk) {
            abort(404, 'BIOG_ADDR_DATA 記錄不存在');
        }

        flash('Update success @ '.Carbon::now(), 'success');

        // 使用新的查詢參數模式重定向
        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.addresses.edit.query',
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
     * 查詢參數模式：編輯地址記錄
     *
     * URL 格式：/basicinformation/{id}/addresses/edit?c_personid=...&c_addr_id=...&c_addr_type=...&c_sequence=...
     *
     * @param Request $request
     * @param int $id 人物 ID
     * @return \Illuminate\Http\Response
     */
    public function editQuery(Request $request, $id) {
        // 從查詢參數提取複合主鍵
        $schema = CompositePrimaryKey::SCHEMAS['BIOG_ADDR_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 驗證必填欄位
        $required = ['c_personid', 'c_addr_id', 'c_addr_type', 'c_sequence'];
        foreach ($required as $field) {
            if (!isset($pk[$field]) || $pk[$field] === '') {
                abort(400, "缺少必要參數：{$field}");
            }
        }

        // 查詢資料
        $row = DB::table('BIOG_ADDR_DATA')->where([
            ['c_personid', '=', $pk['c_personid']],
            ['c_addr_id', '=', $pk['c_addr_id']],
            ['c_addr_type', '=', $pk['c_addr_type']],
            ['c_sequence', '=', $pk['c_sequence']],
        ])->first();

        if (!$row) {
            abort(404, 'BIOG_ADDR_DATA 記錄不存在');
        }

        // 處理地址資訊
        $addr_str = null;
        $other_belongs_str = null;
        if ($row->c_addr_id || $row->c_addr_id === 0) {
            $item = AddrCode::find($row->c_addr_id);
            if ($item) {
                $belongs = "";
                $originalText = $item->c_addr_id." ".$item->c_name." ".$item->c_name_chn." ".trim($belongs)." ".$item->c_firstyear."~".$item->c_lastyear;

                $add = [];
                $dy = AddrBelong::where('c_addr_id', '=', $item->c_addr_id)->get();
                if ($dy->isEmpty()) {
                    $add[] = "";
                } else {
                    foreach ($dy as $d) {
                        $dy2 = AddrCode::where('c_addr_id', '=', $d->c_belongs_to)->first();
                        if ($dy2 && !$dy2->empty) {
                            $add_str = "[[".$dy2->c_addr_id." ".$dy2->c_name_chn." ".$dy2->c_firstyear."~".$dy2->c_lastyear."]]";
                            $add[] = $add_str;
                        } else {
                            $add[] = "";
                        }
                    }
                }
                $addr_str = trim($originalText." ".($add[0] ?? ''));

                if (count($add) > 1) {
                    for ($i = 1; $i < count($add); $i++) {
                        if ($i > 1) {
                            $other_belongs_str = $other_belongs_str."、".trim($add[$i]);
                        } else {
                            $other_belongs_str = $other_belongs_str.trim($add[$i]);
                        }
                    }
                }
            }
        }

        $text_str = null;
        if (property_exists($row, 'c_source') && ($row->c_source || $row->c_source === 0)) {
            $text_ = TextCode::find($row->c_source);
            if ($text_) {
                $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;
            }
        }

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

        return view('biogmains.addresses.edit', [
            'id' => $id,
            'row' => $row,
            'pk' => $pk,
            'addr_str' => $addr_str,
            'text_str' => $text_str,
            'page_title' => '地址',
            'page_description' => '基本信息表 地址',
            'page_url' => '/basicinformation/'.$id.'/addresses',
            'archer' => '<li>編輯</li>',
            'other_belongs_str' => $other_belongs_str,
            'breadcrumb_home' => '人物基本資料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '地址', 'url' => route('basicinformation.addresses.index', $id)],
                ['label' => '編輯', 'url' => '#'],
            ],
        ]);
    }

    /**
     * 查詢參數模式：更新地址記錄
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
        $schema = CompositePrimaryKey::SCHEMAS['BIOG_ADDR_DATA'];
        $originalPk = [];
        foreach ($schema as $field) {
            $value = $request->query($field);
            if ($value !== null) {
                $originalPk[$field] = $value;
            }
        }

        if ($action === 'proposal') {
            // 使用新的查詢參數模式，直接傳遞主鍵陣列
            return app(\App\Http\Controllers\BasicInformationProposalController::class)
                ->proposalUpdateWithPk($request, $id, 'addresses', $originalPk);
        }

        if (!Auth::user()->canWriteDirectly()) {
            flash('該使用者沒有權限，請聯繫管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 驗證必填欄位
        CompositePrimaryKey::validateOrFail($originalPk, 'BIOG_ADDR_DATA');

        if ((string) ($originalPk['c_personid'] ?? '') !== (string) $id) {
            abort(400, '路徑人物 ID 與查詢主鍵不一致');
        }

        $legacyId = $originalPk['c_personid']."-".$originalPk['c_addr_id']."-".$originalPk['c_addr_type']."-".$originalPk['c_sequence'];
        try {
            $newPk = $this->biogMainRepository->addrUpdateById($request, $id, $legacyId);
        } catch (\InvalidArgumentException $e) {
            flash($e->getMessage(), 'error');

            return redirect()->back()->withInput();
        }
        if ($newPk === null) {
            abort(404, 'BIOG_ADDR_DATA 記錄不存在');
        }

        flash('Update success @ '.Carbon::now(), 'success');

        // 重定向到新的查詢參數格式
        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.addresses.edit.query',
            ['id' => $id],
            $newPk
        ));
    }

    /**
     * 查詢參數模式：刪除地址記錄
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
        $schema = CompositePrimaryKey::SCHEMAS['BIOG_ADDR_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 驗證必填欄位
        CompositePrimaryKey::validateOrFail($pk, 'BIOG_ADDR_DATA');

        // 構建舊格式 ID 用於 Repository
        $id_ = $pk['c_personid']."-".$pk['c_addr_id']."-".$pk['c_addr_type']."-".$pk['c_sequence'];

        // 使用 Repository 進行刪除（內含事務與審計）
        $deleted = $this->biogMainRepository->addrDeleteById($id_, $id);
        if (!$deleted) {
            abort(404, 'BIOG_ADDR_DATA 記錄不存在');
        }

        flash('Delete success @ '.Carbon::now(), 'success');

        return redirect()->route('basicinformation.addresses.index', ['basicinformation' => $id]);
    }
}
