<?php

namespace App\Http\Controllers;

use App\Repositories\BiogMainRepository;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    protected $biogMainRepository;
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(BiogMainRepository $biogMainRepository)
    {
        $this->middleware('auth');
        $this->biogMainRepository = $biogMainRepository;
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $names = \App\BiogMain::select(['c_personid', 'c_name', 'c_name_chn'])->paginate(25);

        return view('home', ['names' => $names]);
    }
}
