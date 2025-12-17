<?php

namespace App\Http\Controllers;

use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

        //        dd($serialAddr);
        return view('biogmains.offices.index', ['basicinformation' => $biogbasicinformation, 'post2addr' => $serialAddr,
            'page_title' => '官名', 'page_description' => '基本信息表 官名', 'breadcrumb_home' => '人物基本資料']);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create($id) {
        return view('biogmains.offices.create', [
            'id' => $id,
            'page_title' => '官名', 'page_description' => '基本信息表 官名', 'page_url' => '/basicinformation/'.$id.'/offices', 'breadcrumb_home' => '人物基本資料', 'archer' => '<li>新增</li>']);
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
        $_id = $this->biogMainRepository->officeStoreById($request, $id);
        flash('Store success @ '.Carbon::now(), 'success');

        return redirect()->route('basicinformation.offices.edit', [
            'basicinformation' => $id,
            'office' => $_id,
        ]);
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
    public function edit($id, $office) {
        $res = $this->biogMainRepository->officeById($office);

        return view('biogmains.offices.edit', ['id' => $id, 'row' => $res['row'], 'res' => $res,
            'page_title' => '官名', 'page_description' => '基本信息表 官名',
            'page_url' => '/basicinformation/'.$id.'/offices',
            'archer' => "<li>編輯</li>",
            'breadcrumb_home' => '人物基本資料',
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
        $result = $this->biogMainRepository->officeUpdateById($request, $id_, $id);
        $officeKey = is_array($result) ? ($result['id'] ?? $id_) : $result;
        $noChanges = is_array($result) && !empty($result['no_changes']);

        if ($noChanges) {
            flash('無實質更新，資料未變更 @ '.Carbon::now(), 'info');
        } else {
            flash('Update success @ '.Carbon::now(), 'success');
        }

        return redirect()->route('basicinformation.offices.edit', [
            'basicinformation' => $id,
            'office' => $officeKey,
        ]);
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

            $_id = $data['c_office_id'] . '-' . $data['c_posting_id'];

            return redirect()->route('basicinformation.offices.edit', ['basicinformation' => $id, 'office' => $_id]);
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
    public function destroy($id, $office) {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        $this->biogMainRepository->officeDeleteById($office, $id);
        flash('Delete success @ '.Carbon::now(), 'success');

        return redirect()->route('basicinformation.offices.index', ['basicinformation' => $id]);
    }

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
}
