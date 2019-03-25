<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BiogMain;
use App\Operation;
use App\Repositories\ToolsRepository;
use App\Repositories\OperationRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class ModifiedController extends Controller
{
    protected $operationRepository;
    protected $toolRepository;

    public function __construct(ToolsRepository $toolsRepository,OperationRepository $operationRepository)
    {
        $this->toolRepository  = $toolsRepository;
        $this->operationRepository = $operationRepository;
    }

    public function store()
    {
//        Operation::all();
    }

    public function index()
    {
        $lists = Operation::whereIn('crowdsourcing_status', array(0,1))->orderBy('updated_at', 'desc')->limit(100)->paginate(20);
        return view('modified.index', ['lists' => $lists,
            'page_title' => 'Modified', 'page_description' => '最近修改紀錄',
            'page_url' => '/modified'
        ]);
    }
}
