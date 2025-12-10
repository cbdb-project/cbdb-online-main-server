<?php

namespace App\Http\Controllers;

use App\Repositories\BiogMainRepository;

class HomeController extends Controller {
    protected $biogMainRepository;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(BiogMainRepository $biogMainRepository) {
        $this->biogMainRepository = $biogMainRepository;
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {
        return redirect('/basicinformation');
        //        return view('home', ['page_title' => 'Dashboard', 'page_description' => 'Version 1.0']);
    }
}
