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
        return view('home', ['page_title' => 'Home', 'page_description' => 'Search name in database']);
    }
}
