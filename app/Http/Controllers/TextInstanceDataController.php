<?php

namespace App\Http\Controllers;

use App\Repositories\TextInstanceDataRepository;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\Repositories\YearRangeRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\TextInstanceData;

class TextInstanceDataController extends Controller
{
    protected $textinstancedatarepository;
    protected $operationRepository;
    protected $toolRepository;
    protected $yearRangeRepository;
    public function __construct(TextInstanceDataRepository $textinstancedatarepository, OperationRepository $operationRepository, ToolsRepository $toolsRepository, YearRangeRepository $yearRangeRepository)
    {
        $this->textinstancedatarepository = $textinstancedatarepository;
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
        return view('textinstancedata.index', ['page_title' => 'Text Instance Data', 'page_description' => '著作版本表', 'codes' => session('codes')]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //20201116依據TEXT_CODE與TEXT_INSTANCE_DATA的邏輯，在[著作版本表]中新建記錄時，c_textid欄位直接留空。
        //$temp_id = TextInstanceData::max('c_textid') + 1;
        $temp_id = '';
        return view('textinstancedata.create', ['page_title' => 'Text Instance Data', 'page_description' => '著作版本表', 'temp_id' => $temp_id]);
    
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
//dd($data);
        if ($data['c_textid'] == null or $data['c_textid'] == 0 or TextInstanceData::where('c_textid', $data['c_textid'])->get()->isEmpty()){
            flash('c_textid 未填或已存在 '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $flight = TextInstanceData::create($data);
        $this->operationRepository->store(Auth::id(), '', 1, 'TEXT_INSTANCE_DATA', $data['c_textid']."-".$data['c_text_edition_id']."-".$data['c_text_instance_id'], $data);
        flash('Create success @ '.Carbon::now(), 'success');
        return redirect()->route('textinstancedata.edit', $data['c_textid']."-".$data['c_text_edition_id']."-".$data['c_text_instance_id']);
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
        $data = $this->textinstancedatarepository->byId($id);
        return view('textinstancedata.edit', ['page_title' => 'Text Instance Data', 'page_description' => '著作版本表', 'id' => $id, 'row' => $data, 'codes' => session('codes')]);
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
        $this->textinstancedatarepository->updateById($request, $id);
        flash('Update success @ '.Carbon::now(), 'success');
        //使用更新後的id來跳轉。
        $id = $request['c_textid']."-".$request['c_text_edition_id']."-".$request['c_text_instance_id'];
        return redirect()->route('textinstancedata.edit', $id);
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
        $table_name = "TEXT_INSTANCE_DATA";
        //$row = DB::table($table_name)->where('c_textid', $id)->first();
        $id_l = explode("-", $id);
        $row = DB::table($table_name)->where([
            ['c_textid', '=', $id_l[0]],
            ['c_text_edition_id', '=', $id_l[1]],
            ['c_text_instance_id', '=', $id_l[2]]
        ])->first();
        $this->operationRepository->store(Auth::id(), '', 4, $table_name, $id, $row);
        //DB::table($table_name)->where('c_textid', $id)->delete();
        DB::table($table_name)->where([
            ['c_textid', '=', $id_l[0]],
            ['c_text_edition_id', '=', $id_l[1]],
            ['c_text_instance_id', '=', $id_l[2]]
        ])->delete();
        flash('Delete success @ '.Carbon::now(), 'success');
        return view('textinstancedata.index', ['page_title' => 'Text Instance Data', 'page_description' => '著作版本表', 'codes' => session('codes')]);
    }
}
