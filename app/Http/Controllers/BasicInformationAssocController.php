<?php

namespace App\Http\Controllers;

use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
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
        } elseif (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        //20210309在這裡處理c_inst_code傳遞過來的值，分別儲存至c_inst_code與c_inst_name_code欄位
        //20210315修正$c_inst_name_code預設為0
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
        $data = $this->biogMainRepository->assocStoreById($request, $id);
        flash('Store success @ '.Carbon::now(), 'success');
        //20200709引用聯合主鍵保留字弱點防禦函式
        // 對每個主鍵欄位分別編碼，然後用 - 連接（- 是分隔符，不應該被編碼）
        // 只有 c_text_title 可能包含特殊字符，其他都是數字
        $_id = $data['c_personid']."-".$data['c_assoc_code']."-".$data['c_assoc_id']."-".$data['c_kin_code']."-".$data['c_kin_id']."-".$data['c_assoc_kin_code']."-".$data['c_assoc_kin_id']."-".$this->biogMainRepository->unionPKDef($data['c_text_title']);

        return redirect()->route('basicinformation.assoc.edit', [
            'basicinformation' => $id,
            'assoc' => $_id,
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
        } elseif (!Auth::user()->canWriteDirectly()) {
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        //20210309在這裡處理c_inst_code傳遞過來的值，分別儲存至c_inst_code與c_inst_name_code欄位
        //20210315修正$c_inst_name_code預設為0
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
        $data = $this->biogMainRepository->assocUpdateById($request, $id_, $id);
        flash('Update success @ '.Carbon::now(), 'success');
        //20200709引用聯合主鍵保留字弱點防禦函式
        // 對每個主鍵欄位分別編碼，然後用 - 連接（- 是分隔符，不應該被編碼）
        // 只有 c_text_title 可能包含特殊字符，其他都是數字
        $id_ = $id."-".$data['c_assoc_code']."-".$data['c_assoc_id']."-".$data['c_kin_code']."-".$data['c_kin_id']."-".$data['c_assoc_kin_code']."-".$data['c_assoc_kin_id']."-".$this->biogMainRepository->unionPKDef($data['c_text_title']);

        return redirect()->route('basicinformation.assoc.edit', [
            'basicinformation' => $id,
            'assoc' => $id_,
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
        $this->biogMainRepository->assocDeleteById($id_, $id);
        flash('Delete success @ '.Carbon::now(), 'success');

        return redirect()->route('basicinformation.assoc.index', ['basicinformation' => $id]);
    }
}
