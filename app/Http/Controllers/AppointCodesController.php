<?php

namespace App\Http\Controllers;

use App\Repositories\AppointCodeRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AppointCodesController extends Controller
{
    protected $appcoderepository;
    public function __construct(AppointCodeRepository $appcoderepository)
    {
        $this->middleware('auth');
        $this->appcoderepository = $appcoderepository;
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('appointcodes.index', ['page_title' => 'Appointment Type Codes', 'page_description' => '任命编码表', 'codes' => session('codes')]);
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
        $data = $this->appcoderepository->byId($id);
        return view('appointcodes.edit', ['page_title' => 'Appointment Type Codes', 'page_description' => '任命类型编码表', 'id' => $id, 'row' => $data, 'codes' => session('codes')]);
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
        $this->appcoderepository->updateById($request, $id);
        flash('Update success @ '.Carbon::now(), 'success');

        return redirect()->route('appointcodes.edit', $id);
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
