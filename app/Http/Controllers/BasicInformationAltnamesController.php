<?php

namespace App\Http\Controllers;

use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\Services\NameSearchIndexService;
use App\TextCode;
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
        $this->operationRepository->store(Auth::id(), $id, 1, 'ALTNAME_DATA', $data['c_personid']."-".$data['c_sequence']."-".$data['c_alt_name_chn']."-".$data['c_alt_name_type_code'], $data);

        // 手動調用索引服務
        if (Schema::hasTable('CBDB__NAME_FTS') && !empty($data['c_alt_name_chn'])) {
            $this->nameSearchIndexService->indexAltname(
                $data['c_personid'],
                $data['c_alt_name_type_code'],
                $data['c_alt_name_chn']
            );
        }

        flash('Store success @ '.Carbon::now(), 'success');
        //20200709引用聯合主鍵保留字弱點防禦函式
        $data['c_alt_name_chn'] = $this->biogMainRepository->unionPKDef($data['c_alt_name_chn']);

        return redirect()->route('basicinformation.altnames.edit', [
            'basicinformation' => $id,
            'altname' => $data['c_personid'].'-'.$data['c_sequence'].'-'.$data['c_alt_name_chn'].'-'.$data['c_alt_name_type_code'],
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

        // 檢查動作類型
        $action = $request->input('action', 'save');

        if ($action === 'proposal') {
            // 轉發到提案控制器
            return app(\App\Http\Controllers\BasicInformationProposalController::class)
                ->proposalUpdate($request, $id, 'altnames', $alt);
        }

        // 直接儲存需要額外權限檢查
        if (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

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

        if ($data['c_sequence'] == null) {
            $data['c_sequence'] = 'NULL';
        }
        $new_alt = $id.'-'.$data['c_sequence'].'-'.$data['c_alt_name_chn'].'-'.$data['c_alt_name_type_code'];
        $this->operationRepository->store(Auth::id(), $id, 3, 'ALTNAME_DATA', $new_alt, $data, $ori);

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
        //20200709引用聯合主鍵保留字弱點防禦函式
        $new_alt = $this->biogMainRepository->unionPKDef($new_alt);
        //20210715新增錯別字過濾
        $errWord = ['?', '', '�'];
        $new_alt = str_replace($errWord, '', $new_alt);

        return redirect()->route('basicinformation.altnames.edit', [
            'basicinformation' => $id,
            'altname' => $new_alt,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id, $alt) {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        $addr_l = $this->parseAltnameId($alt);

        $row = DB::table('ALTNAME_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_sequence', '=', $addr_l[1]],
            ['c_alt_name_chn', 'like', '%'.$addr_l[2].'%'],
            ['c_alt_name_type_code', '=', $addr_l[3]],
        ])->first();

        $this->operationRepository->store(Auth::id(), $id, 4, 'ALTNAME_DATA', $alt, $row);

        // 使用 Query Builder 刪除資料
        DB::table('ALTNAME_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_sequence', '=', $addr_l[1]],
            ['c_alt_name_chn', 'like', '%'.$addr_l[2].'%'],
            ['c_alt_name_type_code', '=', $addr_l[3]],
        ])->delete();

        // 手動調用索引服務清理索引
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
            $alt = str_replace("--", "-minus", $alt);
            //聯合主鍵保留字弱點防禦函式，解析保留字。
            $alt = $this->biogMainRepository->unionPKDef_decode($alt);
            $addr_l = explode("-", $alt);
            foreach ($addr_l as $key => $value) {
                $addr_l[$key] = str_replace("minus", "-", $value);
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
}
