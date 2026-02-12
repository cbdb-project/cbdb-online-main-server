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
use Illuminate\Validation\ValidationException;

class BasicInformationOfficesController extends Controller {
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
        $this->table_name = 'POSTED_TO_OFFICE_DATA';
        $this->operationRepository = $operationRepository;
        $this->toolsRepository = $toolsRepository;
        $this->middleware('auth')->except(['index', 'show', 'edit', 'editQuery']);
        $this->middleware(function ($request, $next) {
            if (!Auth::check() || !Auth::user()->isActive()) {
                $personId = $request->route('basicinformation') ?? $request->route('id');

                return redirect()->route('basicinformation.show', $personId);
            }

            return $next($request);
        })->only(['edit', 'editQuery']);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index($id) {
        $biogbasicinformation = $this->biogMainRepository->byIdWithOff($id);
        //        dd($biogbasicinformation->offices_addr->toArray());

        $serialAddr = $this->serialAddr($biogbasicinformation->offices_addr->toArray());

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

        //        dd($serialAddr);
        return view('biogmains.offices.index', ['basicinformation' => $biogbasicinformation, 'post2addr' => $serialAddr,
            'page_title' => '官名', 'page_description' => '基本信息表 官名', 'breadcrumb_home' => '人物基本資料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '官名', 'url' => '#'],
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

        return view('biogmains.offices.create', [
            'id' => $id,
            'page_title' => '官名', 'page_description' => '基本信息表 官名', 'page_url' => '/basicinformation/'.$id.'/offices', 'breadcrumb_home' => '人物基本資料', 'archer' => '<li>新增</li>',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '官名', 'url' => route('basicinformation.offices.index', $id)],
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
        //20211020在這裡處理c_inst_code傳遞過來的值，分別儲存至c_inst_code與c_inst_name_code欄位
        //20211020修正$c_inst_name_code預設為0
        $temp = explode("-", $request->c_inst_code);
        $c_inst_code = $temp[0];
        if (!empty($temp[1])) {
            $c_inst_name_code = $temp[1];
        } else {
            $c_inst_code = '0';
            $c_inst_name_code = '0';
        }

        if ($c_inst_name_code != '') {
            $request->c_inst_code = $c_inst_code;
            $request->c_inst_name_code = $c_inst_name_code;
            $request->merge(['c_inst_code' => $c_inst_code]);
            $request->merge(['c_inst_name_code' => $c_inst_name_code]);
        }
        //return $request;
        //修改結束

        // 提取 AI 填充日誌 ID 並從 request 中移除（避免傳入資料庫操作）
        $aiFillLogId = $request->input('ai_fill_log_id');
        $request->request->remove('ai_fill_log_id');

        $_id = $this->biogMainRepository->officeStoreById($request, $id);
        flash('Store success @ '.Carbon::now(), 'success');

        // 更新 AI 填充日誌（如果本次提交使用了 AI 填充）
        if ($aiFillLogId) {
            $this->updateAiFillLog($aiFillLogId, $request);
        }

        // 解析主鍵（officeStoreById 返回查詢參數格式，如 c_office_id=87473&c_posting_id=2104406）
        $newPk = CompositePrimaryKey::parseStoredResourceId($_id, 'POSTED_TO_OFFICE_DATA');
        if ($newPk === null) {
            \Log::error('officeStoreById 返回的 resource_id 無法解析', ['resource_id' => $_id]);

            return redirect(route('basicinformation.offices.index', $id, false));
        }

        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.offices.edit.query',
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

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id, $office) {
        $res = $this->biogMainRepository->officeById($office);

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

        return view('biogmains.offices.edit', ['id' => $id, 'row' => $res['row'], 'res' => $res,
            'page_title' => '官名', 'page_description' => '基本信息表 官名',
            'page_url' => '/basicinformation/'.$id.'/offices',
            'archer' => "<li>編輯</li>",
            'breadcrumb_home' => '人物基本資料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '官名', 'url' => route('basicinformation.offices.index', $id)],
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
        //20211020在這裡處理c_inst_code傳遞過來的值，分別儲存至c_inst_code與c_inst_name_code欄位
        //20211020修正$c_inst_name_code預設為0
        $temp = explode("-", $request->c_inst_code);
        $c_inst_code = $temp[0];
        if (!empty($temp[1])) {
            $c_inst_name_code = $temp[1];
        } else {
            $c_inst_code = '0';
            $c_inst_name_code = '0';
        }

        if ($c_inst_name_code != '') {
            $request->c_inst_code = $c_inst_code;
            $request->c_inst_name_code = $c_inst_name_code;
            $request->merge(['c_inst_code' => $c_inst_code]);
            $request->merge(['c_inst_name_code' => $c_inst_name_code]);
        }

        //return $request;
        //修改結束
        try {
            $result = $this->biogMainRepository->officeUpdateById($request, $id_, $id);
        } catch (ValidationException $e) {
            // 捕獲地址記錄衝突等驗證錯誤，顯示完整的錯誤消息列表
            $errorMessages = $e->validator->errors()->all();
            foreach ($errorMessages as $message) {
                flash($message, 'error');
            }

            return redirect()->back()->withInput();
        }

        $officeKey = is_array($result) ? ($result['id'] ?? $id_) : $result;
        $noChanges = is_array($result) && !empty($result['no_changes']);

        if ($noChanges) {
            flash('無實質更新，資料未變更 @ '.Carbon::now(), 'info');
        } else {
            flash('Update success @ '.Carbon::now(), 'success');
        }

        // 解析新的主鍵（officeUpdateById 返回查詢參數格式，如 c_office_id=87473&c_posting_id=2104406）
        $newPk = CompositePrimaryKey::parseStoredResourceId($officeKey, 'POSTED_TO_OFFICE_DATA');
        if ($newPk === null) {
            \Log::error('officeUpdateById 返回的 resource_id 無法解析', ['resource_id' => $officeKey]);

            return redirect(route('basicinformation.offices.index', $id, false));
        }

        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.offices.edit.query',
            ['id' => $id],
            $newPk
        ));
    }

    //20190225新增另存功能
    public function saveas($id, $cpk) {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        //20251203遮除原本的資料另存程式，改用transaction程式碼
        //dd($cpk);
        return DB::transaction(function () use ($id, $cpk) {
            $request = $this->biogMainRepository->officeById($cpk);
            //dd($request);
            $payload = json_encode($request['row']);
            $payload = json_decode($payload, true);
            $c_addr_ori = $request['addr_str'] ?? [];
            $c_addr = [];
            $sum = count($c_addr_ori);
            for ($i = 0;$i < $sum;$i++) {
                array_push($c_addr, $c_addr_ori[$i][0]);
            }
            $data = Arr::except($payload, ['_token', 'c_addr']);
            $data['c_fy_intercalary'] = (int)($data['c_fy_intercalary']);
            $data['c_ly_intercalary'] = (int)($data['c_ly_intercalary']);

            $lastPostingId = DB::table('POSTING_DATA')
                ->lockForUpdate()
                ->orderByDesc('c_posting_id')
                ->value('c_posting_id');
            $data['c_posting_id'] = ((int) $lastPostingId) + 1;
            $data['c_personid'] = $id;

            //移除原資料的更新資訊，將操作另存的使用者與當下時間紀錄為c_created_by與c_created_date。
            $c_created_by = Auth::user()->name;
            $c_created_date = Carbon::now();
            $data['c_modified_by'] = $data['c_modified_date'] = '';
            $data['c_created_by'] = $c_created_by;
            $data['c_created_date'] = $c_created_date;

            DB::table('POSTING_DATA')->insert([
                'c_personid' => $data['c_personid'],
                'c_posting_id' => $data['c_posting_id'],
                'c_created_by' => $data['c_created_by'],
                'c_created_date' => $data['c_created_date'],
            ]);

            $this->insertAddr($c_addr, $id, $data['c_posting_id'], $data['c_office_id'], $c_created_by, $c_created_date);

            $data = (new ToolsRepository())->timestamp($data, true);
            DB::table('POSTED_TO_OFFICE_DATA')->insert($data);

            (new OperationRepository())->store(Auth::id(), $id, 1, 'POSTED_TO_OFFICE_DATA', $data['c_office_id'] . '-' . $data['c_posting_id'], $data);

            $addressRows = DB::table('POSTED_TO_ADDR_DATA')
                ->where('c_personid', $id)
                ->where('c_posting_id', $data['c_posting_id'])
                ->where('c_office_id', $data['c_office_id'])
                ->get()
                ->map(function ($row) {
                    return [
                        'c_personid' => (int) $row->c_personid,
                        'c_posting_id' => (int) $row->c_posting_id,
                        'c_office_id' => (int) $row->c_office_id,
                        'c_addr_id' => (int) $row->c_addr_id,
                    ];
                })
                ->values()
                ->all();
            if (!empty($addressRows)) {
                (new OperationRepository())->store(
                    Auth::id(),
                    $id,
                    1,
                    'POSTED_TO_ADDR_DATA',
                    $data['c_office_id'] . '-' . $data['c_posting_id'],
                    ['rows' => $addressRows]
                );
            }

            $newPk = [
                'c_office_id' => $data['c_office_id'],
                'c_posting_id' => $data['c_posting_id'],
            ];

            flash('Store success @ '.Carbon::now(), 'success');

            return redirect(CompositePrimaryKey::buildUrl(
                'basicinformation.offices.edit.query',
                ['id' => $id],
                $newPk
            ));
        });

        //20251203遮除原本的資料另存程式，改用transaction程式碼。
        /*
        $res = $this->biogMainRepository->officeById($cpk);
        $res2 = json_encode($res['row']);
    $res2 = json_decode($res2, true);
        $res3 = $res['addr_str'];
        $data2 = array();
        $x = count($res3);
        for($i=0;$i<$x;$i++) {
            array_push($data2, $res3[$i][0]);
        }
        $data = $res2;
        $c_addr = $data2;
        $data = Arr::except($data, ['_token', 'c_addr']);
        $data['c_fy_intercalary'] = (int)($data['c_fy_intercalary']);
        $data['c_ly_intercalary'] = (int)($data['c_ly_intercalary']);
        $data['c_posting_id'] = DB::table('POSTED_TO_OFFICE_DATA')->max('c_posting_id') + 1;
        $data['c_personid'] = $id;
        DB::table('POSTING_DATA')->insert(['c_personid' => $data['c_personid'], 'c_posting_id' => $data['c_posting_id']]);
        $this->insertAddr($c_addr, $id, $data['c_posting_id'], $data['c_office_id']);
        $data = (new ToolsRepository)->timestamp($data, True);
        $data['c_modified_by'] = $data['c_modified_date'] = '';
        DB::table('POSTED_TO_OFFICE_DATA')->insert($data);
        (new OperationRepository())->store(Auth::id(), $id, 1, 'POSTED_TO_OFFICE_DATA', $data['c_posting_id'], $data);
        $_id = $data['c_office_id']."-".$data['c_posting_id'];
        flash('Store success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.offices.edit', [
            'basicinformation' => $id,
            'office' => $_id,
    ]);
    */
    }

    public function insertAddr(array $c_addr, $_id, $_postingid, $_officeid, $c_created_by = '', $c_created_date = '') {
        DB::table('POSTED_TO_ADDR_DATA')->where('c_personid', $_id)->where('c_posting_id', $_postingid)->delete();
        foreach ($c_addr as $item) {
            DB::table('POSTED_TO_ADDR_DATA')->insert(
                [
                    'c_personid' => $_id,
                    'c_posting_id' => $_postingid,
                    'c_office_id' => $_officeid,
            'c_addr_id' => $item == -999 ? 0 : $item,
            'c_created_by' => $c_created_by,
                    'c_created_date' => $c_created_date,
                ]
            );
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */

    /**
     * @param array $array
     * @return null
     */
    protected function serialAddr(array $array) {
        $res = [];
        //        dd($array);
        foreach ($array as $item) {
            $postingId = $item['pivot']['c_posting_id'];
            if (Arr::has($res, $postingId)) {
                $res[$postingId] = $res[$postingId].';'.$item['c_name_chn'];
            } else {
                $res[$postingId] = $item['c_name_chn'];
            }
        }

        return $res;
    }

    // ===================================================================
    // 查詢參數模式方法（推薦使用）
    // 使用 HTTP 查詢參數傳遞複合主鍵，避免自定義編碼邏輯
    // 參考：docs/COMPOSITE_PRIMARY_KEY_URL_DESIGN.md
    // ===================================================================

    /**
     * 查詢參數模式：編輯官名記錄
     *
     * URL 格式：/basicinformation/{id}/offices/edit?c_office_id=...&c_posting_id=...
     *
     * @param Request $request
     * @param int $id 人物 ID
     * @return \Illuminate\Http\Response
     */
    public function editQuery(Request $request, $id) {
        // 從查詢參數提取複合主鍵
        $schema = CompositePrimaryKey::SCHEMAS['POSTED_TO_OFFICE_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 驗證必填欄位
        CompositePrimaryKey::validateOrFail($pk, 'POSTED_TO_OFFICE_DATA');

        // 構建舊格式 ID（格式：c_office_id-c_posting_id）
        $office = $pk['c_office_id'].'-'.$pk['c_posting_id'];
        $res = $this->biogMainRepository->officeById($office);

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

        return view('biogmains.offices.edit', [
            'id' => $id,
            'row' => $res['row'],
            'res' => $res,
            'pk' => $pk,
            'page_title' => '官名',
            'page_description' => '基本信息表 官名',
            'page_url' => '/basicinformation/'.$id.'/offices',
            'archer' => '<li>編輯</li>',
            'breadcrumb_home' => '人物基本資料',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '官名', 'url' => route('basicinformation.offices.index', $id)],
                ['label' => '编辑', 'url' => '#'],
            ],
        ]);
    }

    /**
     * 查詢參數模式：更新官名記錄
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
        $schema = CompositePrimaryKey::SCHEMAS['POSTED_TO_OFFICE_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 驗證必填欄位
        CompositePrimaryKey::validateOrFail($pk, 'POSTED_TO_OFFICE_DATA');

        // 構建舊格式 ID（格式：c_office_id-c_posting_id）
        $id_ = $pk['c_office_id'].'-'.$pk['c_posting_id'];

        // 處理 c_inst_code
        $temp = explode("-", $request->c_inst_code);
        $c_inst_code = $temp[0];
        if (!empty($temp[1])) {
            $c_inst_name_code = $temp[1];
        } else {
            $c_inst_code = '0';
            $c_inst_name_code = '0';
        }

        if ($c_inst_name_code != '') {
            $request->c_inst_code = $c_inst_code;
            $request->c_inst_name_code = $c_inst_name_code;
            $request->merge(['c_inst_code' => $c_inst_code]);
            $request->merge(['c_inst_name_code' => $c_inst_name_code]);
        }

        try {
            $result = $this->biogMainRepository->officeUpdateById($request, $id_, $id);
        } catch (ValidationException $e) {
            // 捕獲地址記錄衝突等驗證錯誤，顯示完整的錯誤消息列表
            $errorMessages = $e->validator->errors()->all();
            foreach ($errorMessages as $message) {
                flash($message, 'error');
            }

            return redirect()->back()->withInput();
        }

        $officeKey = is_array($result) ? ($result['id'] ?? $id_) : $result;
        $noChanges = is_array($result) && !empty($result['no_changes']);

        if ($noChanges) {
            flash('無實質更新，資料未變更 @ '.Carbon::now(), 'info');
        } else {
            flash('Update success @ '.Carbon::now(), 'success');
        }

        // 解析新的主鍵（officeUpdateById 返回查詢參數格式，如 c_office_id=87473&c_posting_id=2104406）
        $newPk = CompositePrimaryKey::parseStoredResourceId($officeKey, 'POSTED_TO_OFFICE_DATA');
        if ($newPk === null) {
            \Log::error('officeUpdateById 返回的 resource_id 無法解析', ['resource_id' => $officeKey]);

            return redirect(route('basicinformation.offices.index', $id, false));
        }

        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.offices.edit.query',
            ['id' => $id],
            $newPk
        ));
    }

    /**
     * 查詢參數模式：刪除官名記錄
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
        $schema = CompositePrimaryKey::SCHEMAS['POSTED_TO_OFFICE_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 驗證必填欄位
        CompositePrimaryKey::validateOrFail($pk, 'POSTED_TO_OFFICE_DATA');

        // 構建舊格式 ID（格式：c_office_id-c_posting_id）
        $office = $pk['c_office_id'].'-'.$pk['c_posting_id'];

        $this->biogMainRepository->officeDeleteById($office, $id);

        flash('Delete success @ '.Carbon::now(), 'success');

        return redirect()->route('basicinformation.offices.index', ['basicinformation' => $id]);
    }

    /**
     * 更新 AI 填充日誌，記錄用戶實際提交的數據
     */
    private function updateAiFillLog(int $logId, Request $request): void {
        try {
            $relevantFields = [
                'c_office_id', 'c_addr', 'c_sequence', 'c_source', 'c_pages',
                'c_firstyear', 'c_fy_nh_code', 'c_fy_nh_year', 'c_fy_range',
                'c_fy_intercalary', 'c_fy_month', 'c_fy_day', 'c_fy_day_gz',
                'c_lastyear', 'c_ly_nh_code', 'c_ly_nh_year', 'c_ly_range',
                'c_ly_intercalary', 'c_ly_month', 'c_ly_day', 'c_ly_day_gz',
                'c_appt_code', 'c_assume_office_code', 'c_dy',
                'c_office_category_id', 'c_inst_code', 'c_notes',
            ];
            $submittedData = $request->only($relevantFields);

            DB::table('ai_fill_logs')
                ->where('id', $logId)
                ->where('user_id', Auth::id())
                ->update([
                    'user_submitted' => json_encode($submittedData, JSON_UNESCAPED_UNICODE),
                    'submitted_at' => now(),
                    'updated_at' => now(),
                ]);
        } catch (\Exception $e) {
            \Log::warning('[AI Fill Log] 更新用戶提交數據失敗: '.$e->getMessage());
        }
    }
}
