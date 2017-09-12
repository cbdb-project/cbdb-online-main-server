<?php

namespace App\Http\Controllers;

use App\Repositories\AddrCodeRepository;
use App\Repositories\BiogMainRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Class AddressCodesController
 * @package App\Http\Controllers
 */
class AddressCodesController extends Controller
{
    /**
     * @var AddrCodeRepository
     */
    protected $addrcodeRepository;


    /**
     * AddressCodesController constructor.
     * @param AddrCodeRepository $addrcodeRepository
     */
    public function __construct(AddrCodeRepository $addrcodeRepository)
    {
        $this->middleware('auth');
        $this->addrcodeRepository = $addrcodeRepository;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('addresscodes.index', ['page_title' => 'Address Codes', 'page_description' => '地址编码表', 'codes' => session('codes')]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
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
    public function edit($id)
    {
        $data = $this->addrcodeRepository->byId($id);
        return view('addresscodes.edit', ['page_title' => 'Address Codes', 'page_description' => '地址编码表', 'id' => $id, 'row' => $data, 'codes' => session('codes')]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $this->addrcodeRepository->updateById($request, $id);
        flash('Update success @ '.Carbon::now(), 'success');

        return redirect()->route('addresscodes.edit', $id);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
