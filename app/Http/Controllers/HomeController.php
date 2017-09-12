<?php

namespace App\Http\Controllers;

use App\Repositories\BiogMainRepository;
use App\Repositories\CodesRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        return view('home', ['page_title' => 'Dashboard', 'page_description' => 'Version 1.0']);
    }

}
