<?php

namespace App\Http\Controllers;

use App\Models\AddrBelong;
use App\Models\AddrCode;
use App\Models\AddressCode;
use App\Models\TextCode;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BasicInformationAddressesController extends Controller {
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
            flash('请登入后编辑 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        $data = $request->all();
        $data = Arr::except($data, ['_token']);
        $data['c_personid'] = $id;
        $data['c_fy_intercalary'] = (int)($data['c_fy_intercalary']);
        $data['c_ly_intercalary'] = (int)($data['c_ly_intercalary']);

        $temp = DB::table('BIOG_ADDR_DATA')->where([
            ['c_personid', '=', $data['c_personid']],
            ['c_addr_id', '=', $data['c_addr_id']],
            ['c_addr_type', '=', $data['c_addr_type']],
            ['c_sequence', '=', $data['c_sequence']],
        ])->first();
        if (!blank($temp)) {
            flash('重复数据，保存失败 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        $data = $this->toolsRepository->timestamp($data, true);
        DB::table('BIOG_ADDR_DATA')->insert($data);
        $this->operationRepository->store(Auth::id(), $id, 1, 'BIOG_ADDR_DATA', $data['c_personid']."-".$data['c_addr_id']."-".$data['c_addr_type']."-".$data['c_sequence'], $data);
        flash('Store success @ '.Carbon::now(), 'success');

        return redirect()->route('basicinformation.addresses.edit', [
            'basicinformation' => $id,
            'address' => $data['c_personid'].'-'.$data['c_addr_id'].'-'.$data['c_addr_type'].'-'.$data['c_sequence'],
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id) {

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
    public function update(Request $request, $id, $addr) {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        $data = $request->all();

        $data['c_fy_intercalary'] = (int)($data['c_fy_intercalary']);
        $data['c_ly_intercalary'] = (int)($data['c_ly_intercalary']);

        $data = Arr::except($data, ['_method', '_token']);
        $data = $this->toolsRepository->timestamp($data);
        $addr = str_replace("--", "-minus", $addr);
        $addr_l = explode("-", $addr);
        foreach ($addr_l as $key => $value) {
            $addr_l[$key] = str_replace("minus", "-", $value);
        }

        //20251213新增差異比對紀錄
        $ori = DB::table('BIOG_ADDR_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_addr_id', '=', $addr_l[1]],
            ['c_addr_type', '=', $addr_l[2]],
            ['c_sequence', '=', $addr_l[3]],
        ])->first();

        DB::table('BIOG_ADDR_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_addr_id', '=', $addr_l[1]],
            ['c_addr_type', '=', $addr_l[2]],
            ['c_sequence', '=', $addr_l[3]],
        ])->update($data);
        $data['c_personid'] = $addr_l[0];
        $new_addr = $data['c_personid']."-".$data['c_addr_id']."-".$data['c_addr_type']."-".$data['c_sequence'];
        $this->operationRepository->store(Auth::id(), $id, 3, 'BIOG_ADDR_DATA', $new_addr, $data, $ori);
        flash('Update success @ '.Carbon::now(), 'success');

        return redirect()->route('basicinformation.addresses.edit', [
            'basicinformation' => $id,
            'address' => $new_addr,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id, $addr) {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        $addr = str_replace("--", "-minus", $addr);
        $addr_l = explode("-", $addr);
        foreach ($addr_l as $key => $value) {
            $addr_l[$key] = str_replace("minus", "-", $value);
        }
        $row = DB::table('BIOG_ADDR_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_addr_id', '=', $addr_l[1]],
            ['c_addr_type', '=', $addr_l[2]],
            ['c_sequence', '=', $addr_l[3]],
        ])->first();

        DB::table('BIOG_ADDR_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_addr_id', '=', $addr_l[1]],
            ['c_addr_type', '=', $addr_l[2]],
            ['c_sequence', '=', $addr_l[3]],
        ])->delete();

        $this->operationRepository->store(Auth::id(), $id, 4, 'BIOG_ADDR_DATA', $addr, $row);
        flash('Delete success @ '.Carbon::now(), 'success');

        return redirect()->route('basicinformation.addresses.index', ['basicinformation' => $id]);
    }

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
                ['label' => '编辑', 'url' => '#'],
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
            flash('请登入后编辑 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        if (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 從查詢參數提取複合主鍵
        $schema = CompositePrimaryKey::SCHEMAS['BIOG_ADDR_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 準備更新資料
        $data = $request->all();
        $data['c_fy_intercalary'] = (int)($data['c_fy_intercalary'] ?? 0);
        $data['c_ly_intercalary'] = (int)($data['c_ly_intercalary'] ?? 0);
        $data = Arr::except($data, ['_method', '_token', 'c_personid', 'c_addr_id', 'c_addr_type', 'c_sequence']);
        $data = $this->toolsRepository->timestamp($data);

        // 構建查詢條件
        $conditions = [
            ['c_personid', '=', $pk['c_personid']],
            ['c_addr_id', '=', $pk['c_addr_id']],
            ['c_addr_type', '=', $pk['c_addr_type']],
            ['c_sequence', '=', $pk['c_sequence']],
        ];

        // 取得原始資料
        $ori = DB::table('BIOG_ADDR_DATA')->where($conditions)->first();
        if (!$ori) {
            abort(404, 'BIOG_ADDR_DATA 記錄不存在');
        }

        // 更新資料
        DB::table('BIOG_ADDR_DATA')->where($conditions)->update($data);

        // 記錄操作
        $newPk = [
            'c_personid' => $pk['c_personid'],
            'c_addr_id' => $data['c_addr_id'] ?? $pk['c_addr_id'],
            'c_addr_type' => $data['c_addr_type'] ?? $pk['c_addr_type'],
            'c_sequence' => $data['c_sequence'] ?? $pk['c_sequence'],
        ];
        $resourceId = $newPk['c_personid'].'-'.$newPk['c_addr_id'].'-'.$newPk['c_addr_type'].'-'.$newPk['c_sequence'];
        $this->operationRepository->store(Auth::id(), $id, 3, 'BIOG_ADDR_DATA', $resourceId, $data, $ori);

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
            flash('请登入后编辑 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        if (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        // 從查詢參數提取複合主鍵
        $schema = CompositePrimaryKey::SCHEMAS['BIOG_ADDR_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 構建查詢條件
        $conditions = [
            ['c_personid', '=', $pk['c_personid']],
            ['c_addr_id', '=', $pk['c_addr_id']],
            ['c_addr_type', '=', $pk['c_addr_type']],
            ['c_sequence', '=', $pk['c_sequence']],
        ];

        $row = DB::table('BIOG_ADDR_DATA')->where($conditions)->first();
        if (!$row) {
            abort(404, 'BIOG_ADDR_DATA 記錄不存在');
        }

        // 刪除資料
        DB::table('BIOG_ADDR_DATA')->where($conditions)->delete();

        // 記錄操作
        $resourceId = $pk['c_personid'].'-'.$pk['c_addr_id'].'-'.$pk['c_addr_type'].'-'.$pk['c_sequence'];
        $this->operationRepository->store(Auth::id(), $id, 4, 'BIOG_ADDR_DATA', $resourceId, $row);

        flash('Delete success @ '.Carbon::now(), 'success');

        return redirect()->route('basicinformation.addresses.index', ['basicinformation' => $id]);
    }
}
