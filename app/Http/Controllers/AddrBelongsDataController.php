<?php

namespace App\Http\Controllers;

use App\Repositories\AddrBelongsDataRepository;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\Repositories\YearRangeRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\AddrBelongsData;

class AddrBelongsDataController extends Controller
{
    protected $addrbelongsdatarepository;
    protected $operationRepository;
    protected $toolRepository;
    protected $yearRangeRepository;
    public function __construct(AddrBelongsDataRepository $addrbelongsdatarepository, OperationRepository $operationRepository, ToolsRepository $toolsRepository, YearRangeRepository $yearRangeRepository)
    {
        $this->addrbelongsdatarepository = $addrbelongsdatarepository;
        $this->operationRepository = $operationRepository;
        $this->toolRepository  = $toolsRepository;
        $this->yearRangeRepository = $yearRangeRepository;
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('addrbelongsdata.index', ['page_title' => 'Addr Belongs Data', 'page_description' => '地址從屬表', 'codes' => session('codes')]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //20211112地址從屬表c_addr_id預設值為空
        //$temp_id = AddrBelongsData::max('c_addr_id') + 1;
        $temp_id = '';
        return view('addrbelongsdata.create', ['page_title' => 'Addr Belongs Data', 'page_description' => '地址從屬表', 'temp_id' => $temp_id]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $data = $request->all();
        if ($data['c_addr_id'] == null or $data['c_addr_id'] == 0){
            flash('c_addr_id 未填 '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $flight = AddrBelongsData::create($data);
        $this->operationRepository->store(Auth::id(), '', 1, 'ADDR_BELONGS_DATA', $data['c_addr_id'].'-'.$data['c_belongs_to'], $data);
        flash('Create success @ '.Carbon::now(), 'success');
        return redirect()->route('addrbelongsdata.edit', $data['c_addr_id'].'-'.$data['c_belongs_to']);
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
        return view('addrbelongsdata.edit', ['page_title' => 'AddrBelongsData Type Codes', 'page_description' => '地址從屬表', 'id' => $id, 'row' => $data, 'codes' => session('codes')]);
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
        $id = $request['c_addr_id'].'-'.$request['c_belongs_to'];
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
        $id_l = explode("-", $id);
        $table_name = "ADDR_BELONGS_DATA";
        $row = DB::table($table_name)->where([
            ['c_addr_id', '=', $id_l[0]],
            ['c_belongs_to', '=', $id_l[1]]
        ]
        )->first();
        $this->operationRepository->store(Auth::id(), '', 4, $table_name, $id, $row);
        DB::table($table_name)->where([
            ['c_addr_id', '=', $id_l[0]],
            ['c_belongs_to', '=', $id_l[1]]
        ])->delete();
        flash('Delete success @ '.Carbon::now(), 'success');
        return view('addrbelongsdata.index', ['page_title' => 'Addr Belongs Data', 'page_description' => '地址从属表', 'codes' => session('codes')]);
    }
}
