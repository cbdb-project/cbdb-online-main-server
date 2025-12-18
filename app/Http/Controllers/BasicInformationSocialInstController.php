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
        } elseif (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        //20210804在這裡處理c_inst_code傳遞過來的值，分別儲存至c_inst_code與c_inst_name_code欄位，$c_inst_name_code預設為0
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
        $_id = $this->biogMainRepository->socialInstStoreById($request, $id);
        flash('Store success @ '.Carbon::now(), 'success');

        return redirect()->route('basicinformation.socialinst.edit', [
            'basicinformation' => $id,
            'socialinst' => $_id,
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
        } elseif (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        /*
        //原本的寫法，保留做為參考。
        $this->biogMainRepository->socialInstUpdateById($request, $id_, $id);
        flash('Update success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.socialinst.edit', [
            'basicinformation' => $id,
            'socialinst' => $id_,
        ]);
        */

        $data = $request->all();
        $data = Arr::except($data, ['_method', '_token']);
        $data = $this->toolsRepository->timestamp($data);
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
        $newid = $id.'-'.$data['c_inst_code'].'-'.$data['c_inst_name_code'].'-'.$data['c_bi_role_code'];
        $this->operationRepository->store(Auth::id(), $id, 3, 'BIOG_INST_DATA', $newid, $data, $ori);
        flash('Update success @ '.Carbon::now(), 'success');

        return redirect()->route('basicinformation.socialinst.edit', [
            'basicinformation' => $id,
            'socialinst' => $newid,
        ]);
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
        //建安修改20191113
        //$this->biogMainRepository->socialInstDeleteById($id_, $id);
        $addr_l = explode("-", $id_);
        $row = DB::table('BIOG_INST_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_inst_code', '=', $addr_l[1]],
            ['c_inst_name_code', '=', $addr_l[2]],
            ['c_bi_role_code', '=', $addr_l[3]],
        ])->first();

        $this->operationRepository->store(Auth::id(), $id, 4, 'BIOG_INST_DATA', $id_, $row);
        DB::table('BIOG_INST_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_inst_code', '=', $addr_l[1]],
            ['c_inst_name_code', '=', $addr_l[2]],
            ['c_bi_role_code', '=', $addr_l[3]],
        ])->delete();
        flash('Delete success @ '.Carbon::now(), 'success');

        return redirect()->route('basicinformation.socialinst.index', ['basicinformation' => $id]);
    }
}
