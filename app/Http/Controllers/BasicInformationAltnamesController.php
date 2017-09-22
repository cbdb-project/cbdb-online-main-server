<?php

namespace App\Http\Controllers;

use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\TextCode;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BasicInformationAltnamesController extends Controller
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
    public function __construct(BiogMainRepository $biogMainRepository, OperationRepository $operationRepository)
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
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 别名']);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, $id)
    {
        $data = $request->all();
        $data = array_except($data, ['_token']);
        $data['c_personid'] = $id;
        $data['tts_sysno'] = DB::table('ALTNAME_DATA')->max('tts_sysno') + 1;
        DB::table('ALTNAME_DATA')->insert($data);
        flash('Store success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.altnames.edit', ['id' => $id, 'alt' => $data['tts_sysno']]);
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
        $row = DB::table('ALTNAME_DATA')->where('tts_sysno', $alt)->first();
        $text_str = null;
//        dd($row->c_source);
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
        $data = $request->all();
//        dd($data);
        $data = array_except($data, ['_method', '_token']);
        DB::table('ALTNAME_DATA')->where('tts_sysno',$alt)->update($data);
//        dd(DB::table('BIOG_ADDR_DATA')->where('tts_sysno',$id)->first());
        flash('Update success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.altnames.edit', ['id'=>$id, 'addr'=>$alt]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id, $alt)
    {

        $row = DB::table('ALTNAME_DATA')->where('tts_sysno', $alt)->first();
//        dd($row);
        $op = [
            'op_type' => 1,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => $alt,
            'resource_data' => json_encode((array)$row)
        ];
        $this->operationRepository->store($op);
        DB::table('ALTNAME_DATA')->where('tts_sysno', $alt)->delete();
        flash('Delete success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.altnames.index', ['id' => $id]);
    }
}
