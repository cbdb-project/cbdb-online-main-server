<?php

namespace App\Http\Controllers;

use App\AddressCode;
use App\AddrCode;
use App\AddrBelong;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\TextCode;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BasicInformationAddressesController extends Controller
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
    public function __construct(BiogMainRepository $biogMainRepository,OperationRepository $operationRepository, ToolsRepository $toolsRepository)
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
        $biogbasicinformation = $this->biogMainRepository->byIdWithAddr($id);
        return view('biogmains.addresses.index', ['basicinformation' => $biogbasicinformation,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 地址']);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create($id)
    {
        return view('biogmains.addresses.create', [
            'id' => $id,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 地址', 'page_url' => '/basicinformation/'.$id.'/addresses']);
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
        $data['c_fy_intercalary'] = (int)($data['c_fy_intercalary']);
        $data['c_ly_intercalary'] = (int)($data['c_ly_intercalary']);

        $temp = DB::table('BIOG_ADDR_DATA')->where([
            ['c_personid', '=', $data['c_personid']],
            ['c_addr_id', '=', $data['c_addr_id']],
            ['c_addr_type', '=', $data['c_addr_type']],
            ['c_sequence', '=', $data['c_sequence']]
        ])->first();
        if (!blank($temp)) {
            flash('重复数据，保存失败 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $data = $this->toolsRepository->timestamp($data, True);
        DB::table('BIOG_ADDR_DATA')->insert($data);
        $this->operationRepository->store(Auth::id(), $id, 1, 'BIOG_ADDR_DATA', $data['c_personid']."-".$data['c_addr_id']."-".$data['c_addr_type']."-".$data['c_sequence'], $data);
        flash('Store success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.addresses.edit', ['id' => $id, 'addr' => $data['c_personid']."-".$data['c_addr_id']."-".$data['c_addr_type']."-".$data['c_sequence']]);
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
    public function edit($id, $addr)
    {
        $addr = str_replace("--","-minus",$addr);
        $addr_l = explode("-", $addr);
        foreach($addr_l as $key => $value) {
            $addr_l[$key] = str_replace("minus","-",$value);
        }
//        dd($addr_l);
        $row = DB::table('BIOG_ADDR_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_addr_id', '=', $addr_l[1]],
            ['c_addr_type', '=', $addr_l[2]],
            ['c_sequence', '=', $addr_l[3]]
        ])->first();
        $addr_str = null;
        $other_belongs_str =null;
        if($row->c_addr_id || $row->c_addr_id === 0){
            //20210805修改「地址」中利用 ADDRESSES 表和 ADDR_CODES 表
            //$addr_ = AddressCode::find($row->c_addr_id);
            //$addr_str = $addr_->c_addr_id." ".$addr_->c_name." ".$addr_->c_name_chn." ".$addr_->c_firstyear."~".$addr_->c_lastyear;
            $item = AddrCode::find($row->c_addr_id);
            $belongs = "";
            $originalText = $item->c_addr_id." ".$item->c_name." ".$item->c_name_chn." ".trim($belongs)." ".$item->c_firstyear."~".$item->c_lastyear;
            $add = "";
            //$dy = AddrBelong::where('c_addr_id', $item->c_addr_id)->value('c_belongs_to');
            //$dy2 = AddrCode::where('c_addr_id', $dy)->value('c_name_chn');
            // if($dy == null) {
            //     $dy = 0; $add = "";
            // }
            // else {
            //     $dy2 = AddrCode::where('c_addr_id', $dy)->value('c_name_chn');
            //     $add = "[[".$dy." ".$dy2."]]";
            // }
            // $addr_str = $originalText." ".$add;
            //修改結束
            
            //20231002賢瑛修改
            $add = [];    
            $dy = AddrBelong::where('c_addr_id', '=' ,$item->c_addr_id)->get();
            if($dy->isEmpty()) { 
                $add[] = ""; 
            }
            else {
                foreach($dy as $d){
                    //找出上一層資料
                    $dy2 = AddrCode::where('c_addr_id','=',$d->c_belongs_to)->first();
                    if(!$dy2->empty){
                        $add_str = "[[".$dy2->c_addr_id." ".$dy2->c_name_chn." ".$dy2->c_firstyear."~".$dy2->c_lastyear."]]";
                        $add[] = $add_str;
                    }else{
                        $add[] = ""; 
                    }
                } 
            }
            $addr_str = trim($originalText." ".$add[0]);
           
            if(count($add) > 1){
                for($i = 1; $i < count($add); $i++){
                    if($i>1) {
                        $other_belongs_str = $other_belongs_str."、".trim($add[$i]);
                    }
                    else{
                        $other_belongs_str = $other_belongs_str.trim($add[$i]);   
                    }
                }
            }    
        }
        $text_str = null;
//      dd($row->c_source);
        if($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;
        }

        return view('biogmains.addresses.edit', ['id' => $id, 'row' => $row, 'addr_str' => $addr_str, 'text_str' => $text_str,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 地址',
            'page_url' => '/basicinformation/'.$id.'/addresses',
            'archer' => "<li><a href='#'>Address</a></li>", 'other_belongs_str' => $other_belongs_str
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id, $addr)
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

        $data['c_fy_intercalary'] = (int)($data['c_fy_intercalary']);
        $data['c_ly_intercalary'] = (int)($data['c_ly_intercalary']);

        $data = array_except($data, ['_method', '_token']);
        $data = $this->toolsRepository->timestamp($data);
        $addr = str_replace("--","-minus",$addr);
        $addr_l = explode("-", $addr);
        foreach($addr_l as $key => $value) {
            $addr_l[$key] = str_replace("minus","-",$value);
        }
        DB::table('BIOG_ADDR_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_addr_id', '=', $addr_l[1]],
            ['c_addr_type', '=', $addr_l[2]],
            ['c_sequence', '=', $addr_l[3]]
        ])->update($data);
        $data['c_personid'] = $addr_l[0];
        $new_addr = $data['c_personid']."-".$data['c_addr_id']."-".$data['c_addr_type']."-".$data['c_sequence'];
        $this->operationRepository->store(Auth::id(), $id, 3, 'BIOG_ADDR_DATA', $new_addr, $data);
        flash('Update success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.addresses.edit', ['id' => $id, 'addr' => $new_addr]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id, $addr)
    {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1 || Auth::user()->is_admin == 2){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $addr = str_replace("--","-minus",$addr);
        $addr_l = explode("-", $addr);
        foreach($addr_l as $key => $value) {
            $addr_l[$key] = str_replace("minus","-",$value);
        }
        $row = DB::table('BIOG_ADDR_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_addr_id', '=', $addr_l[1]],
            ['c_addr_type', '=', $addr_l[2]],
            ['c_sequence', '=', $addr_l[3]]
        ])->first();

        DB::table('BIOG_ADDR_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_addr_id', '=', $addr_l[1]],
            ['c_addr_type', '=', $addr_l[2]],
            ['c_sequence', '=', $addr_l[3]]
        ])->delete();
        $this->operationRepository->store(Auth::id(), $id, 4, 'BIOG_ADDR_DATA', $addr, $row);
        flash('Delete success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.addresses.index', ['id' => $id]);
    }
}
