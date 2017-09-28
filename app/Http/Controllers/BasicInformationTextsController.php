<?php

namespace App\Http\Controllers;

use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\TextCode;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BasicInformationTextsController extends Controller
{
    /**
     * @var BiogMainRepository
     */
    protected $biogMainRepository;
    protected $table_name;
    protected $operationRepository;

    /**
     * TextsController constructor.
     * @param BiogMainRepository $biogMainRepository
     */
    public function __construct(BiogMainRepository $biogMainRepository,OperationRepository $operationRepository)
    {
        $this->middleware('auth');
        $this->biogMainRepository = $biogMainRepository;
        $this->table_name = 'TEXT_DATA';
        $this->operationRepository = $operationRepository;
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index($id)
    {
        $biogbasicinformation = $this->biogMainRepository->byIdWithText($id);
        return view('biogmains.texts.index', ['basicinformation' => $biogbasicinformation,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 著述']);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create($id)
    {
        return view('biogmains.texts.create', [
            'id' => $id,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 著述']);
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
        $data['tts_sysno'] = DB::table($this->table_name)->max('tts_sysno') + 1;
        DB::table($this->table_name)->insert($data);
        flash('Store success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.texts.edit', ['id' => $id, 'id_' => $data['tts_sysno']]);
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
    public function edit($id, $id_)
    {
        $res = $this->biogMainRepository->textById($id_);
        return view('biogmains.texts.edit', ['id' => $id, 'row' => $res['row'], 'res' => $res,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 著述',
            'page_url' => '/basicinformation/'.$id.'/texts',
            'archer' => "<li><a href='#'>Texts</a></li>",
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id, $id_)
    {
        $data = $request->all();
        $data = array_except($data, ['_method', '_token']);
        if ($data['c_textid'] == -999) $data['c_textid'] = 0;
        DB::table($this->table_name)->where('tts_sysno',$id_)->update($data);
        flash('Update success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.texts.edit', ['id'=>$id, 'id_'=>$id_]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id, $id_)
    {
        $row = DB::table($this->table_name)->where('tts_sysno', $id_)->first();
//        dd($row);
        $op = [
            'op_type' => 4,
            'resource' => $this->table_name,
            'resource_id' => $id_,
            'resource_data' => json_encode((array)$row)
        ];
        $this->operationRepository->store($op);
        DB::table($this->table_name)->where('tts_sysno', $id_)->delete();
        flash('Delete success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.texts.index', ['id' => $id]);
    }
}
