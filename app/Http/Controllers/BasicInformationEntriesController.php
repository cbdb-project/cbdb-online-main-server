<?php

namespace App\Http\Controllers;

use App\Repositories\BiogMainRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;

class BasicInformationEntriesController extends Controller
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
        $biogbasicinformation = $this->biogMainRepository->byIdWithEntries($id);
        return view('biogmains.entries.index', ['basicinformation' => $biogbasicinformation,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 入仕']);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create($id)
    {
        return view('biogmains.entries.create', [
            'id' => $id,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 入仕']);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, $id)
    {
        $_id = $this->biogMainRepository->entryStoreById($request, $id);
        flash('Store success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.entries.edit', ['id' => $id, '_id' => $_id]);
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
        $res = $this->biogMainRepository->entryById($id_);
        return view('biogmains.entries.edit', ['id' => $id, 'row' => $res['row'], 'res' => $res,
            'page_title' => 'Basicinformation', 'page_description' => '基本信息表 入仕',
            'page_url' => '/basicinformation/'.$id.'/entries',
            'archer' => "<li><a href='#'>Entries</a></li>",
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
        $this->biogMainRepository->entryUpdateById($request, $id_);
        flash('Update success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.entries.edit', ['id'=>$id, 'id_'=>$id_]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id, $id_)
    {
        $this->biogMainRepository->entryDeleteById($id_);
        flash('Delete success @ '.Carbon::now(), 'success');
        return redirect()->route('basicinformation.entries.index', ['id' => $id]);
    }
}
