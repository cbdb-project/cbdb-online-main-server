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

class BasicInformationTextsController extends Controller
{
    /**
     * @var BiogMainRepository
     */
    protected $biogMainRepository;
    protected $table_name;
    protected $operationRepository;
    protected $toolsRepository;

    /**
     * TextsController constructor.
     * @param BiogMainRepository $biogMainRepository
     */
    public function __construct(BiogMainRepository $biogMainRepository,OperationRepository $operationRepository, ToolsRepository $toolsRepository)
    {
        $this->biogMainRepository = $biogMainRepository;
        $this->table_name = 'TEXT_DATA';
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
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 著述', 'page_url' => '/basicinformation/'.$id.'/texts']);
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
        elseif (Auth::user()->is_active != 1){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $data = $request->all();
        $data = array_except($data, ['_token']);
        $data['c_personid'] = $id;
        $data = $this->toolsRepository->timestamp($data, True);
        $temp = DB::table($this->table_name)->where([
            ['c_personid', '=', $data['c_personid']],
            ['c_textid', '=', $data['c_textid']],
            ['c_role_id', '=', $data['c_role_id']],
        ])->first();
        if (!blank($temp)) {
            flash('重复数据，保存失败 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        DB::table($this->table_name)->insert($data);
        $this->operationRepository->store(Auth::id(), $id, 1, $this->table_name, $data['c_personid']."-".$data['c_textid']."-".$data['c_role_id'], $data);
        flash('Store success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.texts.edit', ['id' => $id, 'id_' => $data['c_personid']."-".$data['c_textid']."-".$data['c_role_id']]);
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
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $data = $request->all();
        $data = array_except($data, ['_method', '_token']);
        $data = $this->toolsRepository->timestamp($data);
        $temp_l = explode("-", $id_);
        DB::table($this->table_name)->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_textid', '=', $temp_l[1]],
            ['c_role_id', '=', $temp_l[2]],
        ])->update($data);
        $data['c_personid'] = $temp_l[0];
        $new_id_ = $data['c_personid']."-".$data['c_textid']."-".$data['c_role_id'];
        $this->operationRepository->store(Auth::id(), $id, 3, $this->table_name, $new_id_, $data);
        flash('Update success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.texts.edit', ['id' => $id, 'id_' => $new_id_]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id, $id_)
    {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $temp_l = explode("-", $id_);
        $row = DB::table($this->table_name)->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_textid', '=', $temp_l[1]],
            ['c_role_id', '=', $temp_l[2]]
        ])->first();
        DB::table($this->table_name)->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_textid', '=', $temp_l[1]],
            ['c_role_id', '=', $temp_l[2]]
        ])->delete();
        $this->operationRepository->store(Auth::id(), $id, 4, $this->table_name, $id_, $row);
        flash('Delete success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.texts.index', ['id' => $id]);
    }
}
