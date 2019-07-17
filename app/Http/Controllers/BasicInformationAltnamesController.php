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

class BasicInformationAltnamesController extends Controller
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
        $biogbasicinformation = $this->biogMainRepository->byIdWithAlt($id);
        return view('biogmains.altname.index', ['basicinformation' => $biogbasicinformation,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 别名']);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create($id)
    {
        return view('biogmains.altname.create', [
            'id' => $id,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 别名', 'page_url' => '/basicinformation/'.$id.'/altnames']);
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
        elseif (Auth::user()->is_active != 1 || Auth::user()->is_admin == 2){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $data = $request->all();
        $data = array_except($data, ['_token']);
        $data['c_personid'] = $id;
        $data = $this->toolsRepository->timestamp($data, True);
        $temp = DB::table('ALTNAME_DATA')->where([
            ['c_personid', '=', $data['c_personid']],
            ['c_alt_name_chn', '=', $data['c_alt_name_chn']],
            ['c_alt_name_type_code', '=', $data['c_alt_name_type_code']],
        ])->first();
        if (!blank($temp)) {
            flash('重复数据，保存失败 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        DB::table('ALTNAME_DATA')->insert($data);
        $this->operationRepository->store(Auth::id(), $id, 1, 'ALTNAME_DATA', $data['c_personid']."-".$data['c_alt_name_chn']."-".$data['c_alt_name_type_code'], $data);
        flash('Store success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.altnames.edit', ['id' => $id, 'alt' => $data['c_personid']."-".$data['c_alt_name_chn']."-".$data['c_alt_name_type_code']]);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {

    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id, $alt)
    {
        $alt = str_replace("--","-minus",$alt);
        $addr_l = explode("-", $alt);
        foreach($addr_l as $key => $value) {
            $addr_l[$key] = str_replace("minus","-",$value);
        }
        $row = DB::table('ALTNAME_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_alt_name_chn', '=', $addr_l[1]],
            ['c_alt_name_type_code', '=', $addr_l[2]],
        ])->first();
        $text_str = null;
        if($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;

        }

        return view('biogmains.altname.edit', ['id' => $id, 'row' => $row, 'alt' => $alt, 'text_str' => $text_str,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 别名',
            'page_url' => '/basicinformation/'.$id.'/altnames',
            'archer' => "<li><a href='#'>Altname</a></li>",
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id, $alt)
    {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1 || Auth::user()->is_admin == 2){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $data = $request->all();
        $data = array_except($data, ['_method', '_token']);
        $data = $this->toolsRepository->timestamp($data);
        $alt = str_replace("--","-minus",$alt);
        $addr_l = explode("-", $alt);
        foreach($addr_l as $key => $value) {
            $addr_l[$key] = str_replace("minus","-",$value);
        }
        DB::table('ALTNAME_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_alt_name_chn', '=', $addr_l[1]],
            ['c_alt_name_type_code', '=', $addr_l[2]],
        ])->update($data);
        $new_alt = $id.'-'.$data['c_alt_name_chn'].'-'.$data['c_alt_name_type_code'];
        $this->operationRepository->store(Auth::id(), $id, 3, 'ALTNAME_DATA', $new_alt, $data);
        flash('Update success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.altnames.edit', ['id'=>$id, 'addr'=>$new_alt]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id, $alt)
    {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1 || Auth::user()->is_admin == 2){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $alt = str_replace("--","-minus",$alt);
        $addr_l = explode("-", $alt);
        foreach($addr_l as $key => $value) {
            $addr_l[$key] = str_replace("minus","-",$value);
        }
        $row = DB::table('ALTNAME_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_alt_name_chn', '=', $addr_l[1]],
            ['c_alt_name_type_code', '=', $addr_l[2]],
        ])->first();

        $this->operationRepository->store(Auth::id(), $id, 4, 'ALTNAME_DATA', $alt, $row);
        DB::table('ALTNAME_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_alt_name_chn', '=', $addr_l[1]],
            ['c_alt_name_type_code', '=', $addr_l[2]],
        ])->delete();
        flash('Delete success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.altnames.index', ['id' => $id]);
    }
}
