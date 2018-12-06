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
        $_id = $this->biogMainRepository->entryStoreById($request, $id);
        flash('Store success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.entries.edit', ['id' => $id, '_id' => $_id]);
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
        $addr_l = explode("-", $id_);
        DB::table('ENTRY_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_entry_code', '=', $addr_l[1]],
            ['c_sequence', '=', $addr_l[2]],
        ])->update($data);
        $this->operationRepository->store(Auth::id(), $id, 3, 'ENTRY_DATA', $id_, $data);
        $newid = $id.'-'.$data['c_entry_code'].'-'.$data['c_sequence'];
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
        $addr_l = explode("-", $id_);
        $row = DB::table('ENTRY_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_entry_code', '=', $addr_l[1]],
            ['c_sequence', '=', $addr_l[2]],
        ])->first();

        $this->operationRepository->store(Auth::id(), $id, 4, 'ENTRY_DATA', $id_, $row);
        DB::table('ENTRY_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_entry_code', '=', $addr_l[1]],
            ['c_sequence', '=', $addr_l[2]],
        ])->delete(); 
        flash('Delete success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.entries.index', ['id' => $id]);
    }
}
