<?php

namespace App\Http\Controllers;

use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\TextCode;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BasicInformationEntriesController extends Controller
{
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
    public function __construct(BiogMainRepository $biogMainRepository, OperationRepository $operationRepository, ToolsRepository $toolsRepository)
    {
        $this->biogMainRepository = $biogMainRepository;
        $this->operationRepository = $operationRepository;
        $this->toolsRepository = $toolsRepository;
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index($id)
    {
        $biogbasicinformation = $this->biogMainRepository->byIdWithEntries($id);
        return view('biogmains.entries.index', ['basicinformation' => $biogbasicinformation,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 入仕']);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create($id)
    {
        return view('biogmains.entries.create', [
            'id' => $id,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 入仕', 'page_url' => '/basicinformation/'.$id.'/entries']);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, $id)
    {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        //20181217建安遮除原本儲存方式，改以[别名]方式儲存。
        /*
        $_id = $this->biogMainRepository->entryStoreById($request, $id);
        flash('Store success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.entries.edit', ['id' => $id, '_id' => $_id]);
        */
        $data = $request->all();
        $data = array_except($data, ['_token']);
        $data['c_personid'] = $id;
        $data = $this->toolsRepository->timestamp($data, True);
        //20181217新增片段，api回傳值有-999需要轉為0
        $data['c_entry_code'] = $data['c_entry_code'] == -999 ? '0' : $data['c_entry_code'];
        $data['c_entry_addr_id'] = $data['c_entry_addr_id'] == -999 ? '0' : $data['c_entry_addr_id'];
        $data['c_kin_code'] = $data['c_kin_code'] == -999 ? '0' : $data['c_kin_code'];
        $data['c_assoc_code'] = $data['c_assoc_code'] == -999 ? '0' : $data['c_assoc_code'];
        $data['c_inst_code'] = $data['c_inst_code'] == -999 ? '0' : $data['c_inst_code'];
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        //新增結束
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
        return redirect()->route('basicinformation.entries.edit', ['id' => $id, 'alt' => $newKey]);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id, $id_)
    {
        //聯合主鍵的樣式
        //"c_personid":34445,"c_entry_code":39,"c_sequence":1,"c_kin_code":0,"c_kin_id":0,"c_assoc_code":0,"c_assoc_id":0,"c_year":1351,"c_inst_code":0,"c_inst_name_code":0
        $res = $this->biogMainRepository->entryById($id_);

        return view('biogmains.entries.edit', ['id' => $id, 'row' => $res['row'], 'res' => $res,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 入仕',
            'page_url' => '/basicinformation/'.$id.'/entries',
            'archer' => "<li><a href='#'>Entries</a></li>",
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id, $id_)
    {
        //建安修改20181109
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        /*
        //原本的寫法，保留做為參考。
        $this->biogMainRepository->entryUpdateById($request, $id_, $id);
        flash('Update success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.entries.edit', ['id'=>$id, 'id_'=>$id_]);
        */
        $data = $request->all();
        $data = array_except($data, ['_method', '_token']);
        $data = $this->toolsRepository->timestamp($data);
        //20181217新增片段，api回傳值有-999需要轉為0
        $data['c_entry_code'] = $data['c_entry_code'] == -999 ? '0' : $data['c_entry_code'];
        $data['c_entry_addr_id'] = $data['c_entry_addr_id'] == -999 ? '0' : $data['c_entry_addr_id'];
        $data['c_kin_code'] = $data['c_kin_code'] == -999 ? '0' : $data['c_kin_code'];
        $data['c_assoc_code'] = $data['c_assoc_code'] == -999 ? '0' : $data['c_assoc_code'];
        $data['c_inst_code'] = $data['c_inst_code'] == -999 ? '0' : $data['c_inst_code'];
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        //新增結束
        $addr_a = explode("-", $id_);
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
        $this->operationRepository->store(Auth::id(), $id, 3, 'ENTRY_DATA', $id_, $data);
        $newid = $id.'-'.$data['c_entry_code'].'-'.$data['c_sequence'].'-'.$data['c_kin_code'].'-'.$data['c_assoc_code'].'-'.$data['c_kin_id'].'-'.$data['c_year'].'-'.$data['c_assoc_id'].'-'.$data['c_inst_code'].'-'.$data['c_inst_name_code'];
        flash('Update success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.entries.edit', ['id'=>$id, 'id_'=>$newid]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id, $id_)
    {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        //建安修改20191112
        //$this->biogMainRepository->entryDeleteById($id_, $id);
        $addr_a = explode("-", $id_);
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
        return redirect()->route('basicinformation.entries.index', ['id' => $id]);
    }
}
