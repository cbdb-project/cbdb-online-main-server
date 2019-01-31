<?php

namespace App\Http\Controllers;

use App\Repositories\SocialInstitutionCodeRepository;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\Repositories\YearRangeRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\SocialInstitutionCode;

class SocialInstitutionCodesController extends Controller
{
    protected $socialinstitutioncoderepository;
    protected $operationRepository;
    protected $toolRepository;
    protected $yearRangeRepository;

    public function __construct(SocialInstitutionCodeRepository $socialinstitutioncoderepository, OperationRepository $operationRepository, ToolsRepository $toolsRepository, YearRangeRepository $yearRangeRepository)
    {
        $this->socialinstitutioncoderepository = $socialinstitutioncoderepository;
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
        return view('socialinstitutioncodes.index', ['page_title' => 'Social Institution Codes', 'page_description' => '社会机构编码表', 'codes' => session('codes')]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $temp_id = SocialInstitutionCode::max('c_inst_name_code') + 1;
        return view('socialinstitutioncodes.create', ['page_title' => 'Social Institution Codes', 'page_description' => '社会机构编码表', 'temp_id' => $temp_id]);
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
        if ($data['c_inst_name_code'] == null or $data['c_inst_name_code'] == 0 or !SocialInstitutionCode::where('c_inst_name_code', $data['c_inst_name_code'])->get()->isEmpty()){
            flash('c_inst_name_code 未填或已存在 '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $flight = SocialInstitutionCode::create($data);
        $_id = $data['c_inst_name_code']."-".$data['c_inst_code']."-".$data['c_inst_type_code'];
        $this->operationRepository->store(Auth::id(), '', 1, 'SOCIAL_INSTITUTION_CODES', $data['c_inst_name_code'], $data);
        flash('Create success @ '.Carbon::now(), 'success');
        return redirect()->route('socialinstitutioncodes.edit', $_id);
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
        $data = $this->socialinstitutioncoderepository->byUnionId($id);
        return view('socialinstitutioncodes.edit', ['page_title' => 'Social Institution Codes', 'page_description' => '社会机构编码表', 'id' => $id, 'row' => $data, 'codes' => session('codes')]);
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
        $data = $this->socialinstitutioncoderepository->updateByUnionId($request, $id);
        flash('Update success @ '.Carbon::now(), 'success');
        $id = $data['c_inst_name_code']."-".$data['c_inst_code']."-".$data['c_inst_type_code'];
        return redirect()->route('socialinstitutioncodes.edit', $id);
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
