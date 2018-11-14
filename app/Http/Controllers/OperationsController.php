<?php

namespace App\Http\Controllers;

use App\Operation;
use Illuminate\Http\Request;

class OperationsController extends Controller
{
    public function __construct()
    {
    }

    public function store()
    {
//        Operation::all();
    }

    public function index()
    {
        $lists = Operation::orderBy('updated_at', 'desc')->limit(100)->paginate(20);
        return view('operations.index', ['lists' => $lists,
            'page_title' => 'NewUpdate', 'page_description' => '最近编辑列表',
            'page_url' => '/operations'
        ]);
    }
}
