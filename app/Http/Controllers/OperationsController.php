<?php

namespace App\Http\Controllers;

use App\Operation;
use Illuminate\Http\Request;

class OperationsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function store()
    {
//        Operation::all();
    }
}
