<?php

namespace App\Http\Controllers;

use App\Repositories\AddrBelongsDataRepository;
use App\Repositories\OperationRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddrBelongsDataController extends Controller
{
    protected $addrbelongsdatarepository;
    public function __construct(AddrBelongsDataRepository $addrbelongsdatarepository, OperationRepository $operationRepository)
    {
        $this->addrbelongsdatarepository = $addrbelongsdatarepository;
        $this->operationRepository = $operationRepository;
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('addrbelongsdata.index', ['page_title' => 'Addr Belongs Data', 'page_description' => '行政單位等級编码表', 'codes' => session('codes')]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
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
    public function edit($id)
    {
        $data = $this->addrbelongsdatarepository->byId($id);
        return view('addrbelongsdata.edit', ['page_title' => 'AddrBelongsData Type Codes', 'page_description' => '行政單位等級编码表', 'id' => $id, 'row' => $data, 'codes' => session('codes')]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $this->addrbelongsdatarepository->updateById($request, $id);
        flash('Update success @ '.Carbon::now(), 'success');
        //建安修改20181115，使用更新後的id來跳轉。
        $id = $request['c_addr_id'];
        return redirect()->route('addrbelongsdata.edit', $id);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $table_name = "ADDR_BELONGS_DATA";
        $row = DB::table($table_name)->where('c_addr_id', $id)->first();
        $row2 = json_encode((array)$row);
        $this->operationRepository->store(Auth::id(), '', 4, $table_name, $id, $row2);
        DB::table($table_name)->where('c_addr_id', $id)->delete();
        flash('Delete success @ '.Carbon::now(), 'success');
        return view('addrbelongsdata.index', ['page_title' => 'Addr Belongs Data', 'page_description' => '行政單位等級编码表', 'codes' => session('codes')]);
    }
}
