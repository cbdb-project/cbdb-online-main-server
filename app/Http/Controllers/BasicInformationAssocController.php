<?php

namespace App\Http\Controllers;

use App\Repositories\BiogMainRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;

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
    public function __construct(BiogMainRepository $biogMainRepository)
    {
        $this->middleware('auth');
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
//        dd($biogbasicinformation->assoc_name);
        return view('biogmains.assoc.index', ['basicinformation' => $biogbasicinformation,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 社會關係']);
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
        $this->biogMainRepository->assocUpdateById($request, $id_);
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
        $this->biogMainRepository->assocDeleteById($id_);
        flash('Delete success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.assoc.index', ['id' => $id]);
    }
}
