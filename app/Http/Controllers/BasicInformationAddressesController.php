<?php

namespace App\Http\Controllers;

use App\AddressCode;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\TextCode;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BasicInformationAddressesController extends Controller
{
    /**
     * @var BiogMainRepository
     */
    protected $biogMainRepository;
    protected $operationRepository;

    /**
     * TextsController constructor.
     * @param BiogMainRepository $biogMainRepository
     */
    public function __construct(BiogMainRepository $biogMainRepository,OperationRepository $operationRepository)
    {
        $this->middleware('auth');
        $this->biogMainRepository = $biogMainRepository;
        $this->operationRepository = $operationRepository;
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
        $data['c_addr'] = 0;
        $data = $request->all();
        $data = array_except($data, ['_token']);
        $data['c_personid'] = $id;
        $data['c_fy_intercalary'] = (int)($data['c_fy_intercalary']);
        $data['c_ly_intercalary'] = (int)($data['c_ly_intercalary']);
        $data['tts_sysno'] = DB::table('BIOG_ADDR_DATA')->max('tts_sysno') + 1;
//        dd($data);
        DB::table('BIOG_ADDR_DATA')->insert($data);
        flash('Store success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.addresses.edit', ['id' => $id, 'addr' => $data['tts_sysno']]);
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
//        dd($id.' '.$addr);
        $row = DB::table('BIOG_ADDR_DATA')->where('tts_sysno', $addr)->first();
        $addr_str = null;
        if($row->c_addr_id || $row->c_addr_id === 0){
            $addr_ = AddressCode::find($row->c_addr_id);
            $addr_str = $addr_->c_addr_id." ".$addr_->c_name." ".$addr_->c_name_chn;
        }
        $text_str = null;
//        dd($row->c_source);
        if($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;

        }
        return view('biogmains.addresses.edit', ['id' => $id, 'row' => $row, 'addr_str' => $addr_str, 'text_str' => $text_str,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 地址',
            'page_url' => '/basicinformation/'.$id.'/addresses',
            'archer' => "<li><a href='#'>Address</a></li>",
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
        $data = $request->all();

        $data['c_fy_intercalary'] = (int)($data['c_fy_intercalary']);
        $data['c_ly_intercalary'] = (int)($data['c_ly_intercalary']);

        $data = array_except($data, ['_method', '_token']);
        DB::table('BIOG_ADDR_DATA')->where('tts_sysno',$addr)->update($data);
//        dd(DB::table('BIOG_ADDR_DATA')->where('tts_sysno',$id)->first());
        flash('Update success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.addresses.edit', ['id'=>$id, 'addr'=>$addr]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id, $addr)
    {
//        dd($id.' '.$addr);
        $row = DB::table('BIOG_ADDR_DATA')->where('tts_sysno', $addr)->first();
//        dd($row);
        $op = [
            'op_type' => 4,
            'resource' => 'BIOG_ADDR_DATA',
            'resource_id' => $addr,
            'resource_data' => json_encode((array)$row)
        ];
        $this->operationRepository->store($op);
        DB::table('BIOG_ADDR_DATA')->where('tts_sysno', $addr)->delete();
        flash('Delete success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.addresses.index', ['id' => $id]);
    }
}
