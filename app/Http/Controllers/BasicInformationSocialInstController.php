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

class BasicInformationSocialInstController extends Controller
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
        $biogbasicinformation = $this->biogMainRepository->byIdWithSocialInst($id);
        return view('biogmains.socialinst.index', ['basicinformation' => $biogbasicinformation,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 社交機構']);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create($id)
    {
        return view('biogmains.socialinst.create', [
            'id' => $id,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 社交機構', 'page_url' => '/basicinformation/'.$id.'/socialinst']);
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
        $_id = $this->biogMainRepository->socialInstStoreById($request, $id);
        flash('Store success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.socialinst.edit', ['id' => $id, '_id' => $_id]);
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
        $res = $this->biogMainRepository->socialInstById($id_);
        return view('biogmains.socialinst.edit', ['id' => $id, 'row' => $res['row'], 'res' => $res,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 社交機構',
            'page_url' => '/basicinformation/'.$id.'/socialinst',
            'archer' => "<li><a href='#'>SocialInst</a></li>",
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
        elseif (Auth::user()->is_active != 1 || Auth::user()->is_admin == 2){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        /*
        //原本的寫法，保留做為參考。
        $this->biogMainRepository->socialInstUpdateById($request, $id_, $id);
        flash('Update success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.socialinst.edit', ['id'=>$id, 'id_'=>$id_]);
        */
        
        $data = $request->all();
        $data = array_except($data, ['_method', '_token']);
        $data = $this->toolsRepository->timestamp($data);
        $addr_l = explode("-", $id_);
        DB::table('BIOG_INST_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_bi_role_code', '=', $addr_l[1]],
        ])->update($data);
        $newid = $id.'-'.$data['c_bi_role_code'];
        $this->operationRepository->store(Auth::id(), $id, 3, 'BIOG_INST_DATA', $newid, $data);
        flash('Update success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.socialinst.edit', ['id'=>$id, 'id_'=>$newid]);
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
        elseif (Auth::user()->is_active != 1 || Auth::user()->is_admin == 2){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        //建安修改20191113
        //$this->biogMainRepository->socialInstDeleteById($id_, $id);
        $addr_l = explode("-", $id_);
        $row = DB::table('BIOG_INST_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_bi_role_code', '=', $addr_l[1]],
        ])->first();

        $this->operationRepository->store(Auth::id(), $id, 4, 'BIOG_INST_DATA', $id_, $row);
        DB::table('BIOG_INST_DATA')->where([
            ['c_personid', '=', $addr_l[0]],
            ['c_bi_role_code', '=', $addr_l[1]],
        ])->delete();
        flash('Delete success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.socialinst.index', ['id' => $id]);
    }
}
