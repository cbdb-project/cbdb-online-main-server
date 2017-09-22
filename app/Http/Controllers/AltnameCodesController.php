<?php

namespace App\Http\Controllers;

use App\Repositories\AltCodeRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AltnameCodesController extends Controller
{
    protected $altcodeRepository;

    public function __construct(AltCodeRepository $altcodeRepository)
    {
        $this->middleware('auth');
        $this->altcodeRepository = $altcodeRepository;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('altnamecodes.index', ['page_title' => 'Altname Codes', 'page_description' => '别名编码表', 'codes' => session('codes')]);
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
        $data = $this->altcodeRepository->byId($id);
        return view('altnamecodes.edit', ['page_title' => 'Altname Codes', 'page_description' => '别名编码表', 'id' => $id, 'row' => $data, 'codes' => session('codes')]);
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
        $this->altcodeRepository->updateById($request, $id);
        flash('Update success @ '.Carbon::now(), 'success');

        return redirect()->route('altnamecodes.edit', $id);
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
