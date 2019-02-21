<?php

namespace App\Http\Controllers;

use App\Repositories\AddrCode2Repository;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\Repositories\YearRangeRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\AddrCode;

class AddrCodesController extends Controller
{
    protected $addrcoderepository;
    protected $operationRepository;
    protected $toolRepository;
    protected $yearRangeRepository;

    public function __construct(AddrCode2Repository $addrcoderepository, OperationRepository $operationRepository, ToolsRepository $toolsRepository, YearRangeRepository $yearRangeRepository)
    {
        $this->addrcoderepository = $addrcoderepository;
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
        return view('addrcodes.index', ['page_title' => 'Addr Codes', 'page_description' => '地址编码表(ADDR_CODES)', 'codes' => session('codes')]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $temp_id = AddrCode::max('c_addr_id') + 1;
        return view('addrcodes.create', ['page_title' => 'Addr Codes', 'page_description' => '地址编码表(ADDR_CODES)', 'temp_id' => $temp_id]);
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
        if ($data['c_addr_id'] == null or $data['c_addr_id'] == 0 or !AddrCode::where('c_addr_id', $data['c_addr_id'])->get()->isEmpty()){
            flash('c_addr_id 未填或已存在 '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $flight = AddrCode::create($data);
        $this->operationRepository->store(Auth::id(), '', 1, 'ADDR_CODES', $data['c_addr_id'], $data);
        flash('Create success @ '.Carbon::now(), 'success');
        return redirect()->route('addrcodes.edit', $data['c_addr_id']);
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
        $data = $this->addrcoderepository->byId($id);
        return view('addrcodes.edit', ['page_title' => 'Addr Codes', 'page_description' => '地址编码表(ADDR_CODES)', 'id' => $id, 'row' => $data, 'codes' => session('codes')]);
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
        $this->addrcoderepository->updateById($request, $id);
        flash('Update success @ '.Carbon::now(), 'success');
        $id = $request['c_addr_id'];
        return redirect()->route('addrcodes.edit', $id);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
