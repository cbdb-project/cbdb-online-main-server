<?php

namespace App\Http\Controllers;

use App\Address;
use App\Http\Requests\StoreInformationRequest;
use Illuminate\Http\Request;

class InformationsController extends Controller
{
    //
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $data = Address::all()->count();
        return $data;
    }

    public function store(StoreInformationRequest $request)
    {

    }

    public function create()
    {
        //
    }
}
