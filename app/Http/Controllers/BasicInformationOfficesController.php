<?php

namespace App\Http\Controllers;

use App\OfficeCode;
use App\Repositories\BiogMainRepository;
use App\SocialInst;
use App\TextCode;
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

    /**
     * TextsController constructor.
     * @param BiogMainRepository $biogMainRepository
     */
    public function __construct(BiogMainRepository $biogMainRepository)
    {
        $this->biogMainRepository = $biogMainRepository;
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
        $this->biogMainRepository->officeUpdateById($request, $id_, $id);
        flash('Update success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.offices.edit', ['id'=>$id, 'office'=>$id_]);
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
