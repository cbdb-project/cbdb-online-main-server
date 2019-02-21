<?php

namespace App\Http\Controllers;

use App\Repositories\OfficeCodeRepository;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\Repositories\YearRangeRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\OfficeCode;

class OfficeCodesController extends Controller
{
    protected $officecoderepository;
    protected $operationRepository;
    protected $toolRepository;
    protected $yearRangeRepository;

    public function __construct(OfficeCodeRepository $officecoderepository, OperationRepository $operationRepository, ToolsRepository $toolsRepository, YearRangeRepository $yearRangeRepository)
    {
        $this->officecoderepository = $officecoderepository;
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
        return view('officecodes.index', ['page_title' => 'Office Codes', 'page_description' => '机构单位编码表', 'codes' => session('codes')]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $temp_id = OfficeCode::max('c_office_id') + 1;
        return view('officecodes.create', ['page_title' => 'Office Codes', 'page_description' => '机构单位编码表', 'temp_id' => $temp_id]);
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
        if ($data['c_office_id'] == null or $data['c_office_id'] == 0 or !OfficeCode::where('c_office_id', $data['c_office_id'])->get()->isEmpty()){
            flash('c_office_id 未填或已存在 '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $flight = OfficeCode::create($data);
        $this->operationRepository->store(Auth::id(), '', 1, 'OFFICE_CODES', $data['c_office_id'], $data);
        flash('Create success @ '.Carbon::now(), 'success');
        return redirect()->route('officecodes.edit', $data['c_office_id']);
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
        $data = $this->officecoderepository->byId($id);
        return view('officecodes.edit', ['page_title' => 'Office Codes', 'page_description' => '机构单位编码表', 'id' => $id, 'row' => $data, 'codes' => session('codes')]);
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
        $this->officecoderepository->updateById($request, $id);
        flash('Update success @ '.Carbon::now(), 'success');
        $id = $request['c_office_id'];
        return redirect()->route('officecodes.edit', $id);
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
