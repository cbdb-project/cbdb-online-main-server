<?php

namespace App\Http\Controllers;

use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use function PHPSTORM_META\map;

class BasicInformationAssocController extends Controller
{
    /**
     * @var BiogMainRepository
     */
    protected $biogMainRepository;

    /**
     * TextsController constructor.
     * @param BiogMainRepository $biogMainRepository
     */
    public function __construct(BiogMainRepository $biogMainRepository, OperationRepository $operationRepository)
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
        $biogbasicinformation = $this->biogMainRepository->byIdWithAssoc($id);
        $assoc_name_id = array();
        $assoc_name_sequence = array();
        $assoc_name_name = array();
        foreach ($biogbasicinformation->assoc_name as $key => $value){
            $assoc_name_id[$key] = $value->pivot->c_assoc_id;
            $assoc_name_sequence[$key] = $value->c_sequence;
            $assoc_name_name[$key] = $value->c_name.' '.$value->c_name_chn;
        }
        $assoc_name = $biogbasicinformation->assoc->map(function ($item, $key) use ($assoc_name_id, $assoc_name_sequence, $assoc_name_name) {
            if (in_array($item->pivot->c_assoc_id, $assoc_name_id)) {
                return ['c_personid' => $item->pivot->c_assoc_id, 'c_sequence' => $assoc_name_sequence[array_search($item->pivot->c_assoc_id, $assoc_name_id)], 'assoc_name' => $assoc_name_name[array_search($item->pivot->c_assoc_id, $assoc_name_id)]];
            }
            return ['c_personid' => 0, 'c_sequence' => 0, 'assoc_name' => ''];
        });
        //dd($biogbasicinformation);
        //dd($assoc_name);
        return view('biogmains.assoc.index', ['basicinformation' => $biogbasicinformation,
            'assoc_name' => $assoc_name, 'page_title' => 'Basicinformation', 'page_description' => '基本信息表 社會關係']);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create($id)
    {
        return view('biogmains.assoc.create', [
            'id' => $id,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 社會關係', 'page_url' => '/basicinformation/'.$id.'/assoc']);
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
        $_id = $this->biogMainRepository->assocStoreById($request, $id);
        flash('Store success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.assoc.edit', ['id' => $id, '_id' => $_id]);
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
        $res = $this->biogMainRepository->assocById($id_);
        return view('biogmains.assoc.edit', ['id' => $id, 'row' => $res['row'], 'res' => $res,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 社會關係',
            'page_url' => '/basicinformation/'.$id.'/assoc',
            'archer' => "<li><a href='#'>Assoc</a></li>",
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
        $this->biogMainRepository->assocUpdateById($request, $id_, $id);
        flash('Update success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.assoc.edit', ['id'=>$id, 'id_'=>$id_]);
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
        $this->biogMainRepository->assocDeleteById($id_, $id);
        flash('Delete success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.assoc.index', ['id' => $id]);
    }
}
