<?php

namespace App\Http\Controllers;

use App\Repositories\AddrcodeRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AddrCodesController extends Controller
{
    protected $addrcoderepository;
    public function __construct(AddrcodeRepository $addrcoderepository)
    {
        $this->addrcoderepository = $addrcoderepository;
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('addrcodes.index', ['page_title' => 'Addr Codes', 'page_description' => '中国行政地理单位编码表', 'codes' => session('codes')]);
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
        $data = $this->addrcoderepository->byId($id);
        return view('addrcodes.edit', ['page_title' => 'Addr Codes', 'page_description' => '中国行政地理单位编码表', 'id' => $id, 'row' => $data, 'codes' => session('codes')]);
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
        if (!Auth::check()) {
            flash('请登入后编辑 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        elseif (Auth::user()->is_active != 1){
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');
            return redirect()->back();
        }
        $this->addrcoderepository->updateById($request, $id);
        flash('Update success @ '.Carbon::now(), 'success');
        $id = $request['c_addr_id'];
        return redirect()->route('addrcodes.edit', $id);
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
