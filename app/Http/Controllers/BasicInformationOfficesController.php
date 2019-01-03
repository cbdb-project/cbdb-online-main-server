<?php

namespace App\Http\Controllers;

use App\OfficeCode;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\SocialInst;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BasicInformationOfficesController extends Controller
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
        $this->table_name = 'POSTED_TO_OFFICE_DATA';
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
        $biogbasicinformation = $this->biogMainRepository->byIdWithOff($id);
//        dd($biogbasicinformation->offices_addr->toArray());

        $serialAddr = $this->serialAddr($biogbasicinformation->offices_addr->toArray());
//        dd($serialAddr);
        return view('biogmains.offices.index', ['basicinformation' => $biogbasicinformation, 'post2addr' => $serialAddr,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 官名']);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create($id)
    {
        return view('biogmains.offices.create', [
            'id' => $id,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 官名', 'page_url' => '/basicinformation/'.$id.'/offices']);
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
        $_id = $this->biogMainRepository->officeStoreById($request, $id);
        flash('Store success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.offices.edit', ['id' => $id, 'office' => $_id]);
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
    public function edit($id, $office)
    {
        $res = $this->biogMainRepository->officeById($office);
        return view('biogmains.offices.edit', ['id' => $id, 'row' => $res['row'], 'res' => $res,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 官名',
            'page_url' => '/basicinformation/'.$id.'/offices',
            'archer' => "<li><a href='#'>Offices</a></li>",
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
        $id_ = $this->biogMainRepository->officeUpdateById($request, $id_, $id);
        flash('Update success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.offices.edit', ['id' => $id, 'office' => $id_]);
        //20181107建安修改
        //return redirect()->route('basicinformation.offices.edit', ['id'=>$id, 'office'=>$data['c_office_id']."-".$data['c_posting_id']]);
/*
        $data = $request->all();
        $data = array_except($data, ['_method', '_token']);
        $temp_l = explode("-", $id_);
        DB::table($this->table_name)->where([
            ['c_office_id', '=', $temp_l[0]],
            ['c_posting_id', '=', $temp_l[1]],
        ])->update($data);
        $this->operationRepository->store(Auth::id(), $id, 3, $this->table_name, $id_, $data);
        flash('Update success @ '.Carbon::now(), 'success');
        $data['c_office_id'] = $temp_l[0];
        return redirect()->route('basicinformation.offices.edit', ['id' => $id, 'office' => $data['c_office_id']."-".$data['c_posting_id']]);
*/
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id, $office)
    {
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $this->biogMainRepository->officeDeleteById($office, $id);
        flash('Delete success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.offices.index', ['id' => $id]);
    }

    /**
     * @param array $array
     * @return null
     */
    protected function serialAddr(Array $array){
        $res = [];
//        dd($array);
        foreach ($array as $item)
            if (array_has($res, $item['pivot']['c_posting_id'])) $res[$item['pivot']['c_posting_id']] = $res[$item['pivot']['c_posting_id']].';'.$item['c_name_chn'];
            else $res[$item['pivot']['c_posting_id']] = $item['c_name_chn'];
        return $res;
    }
}
