<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\BiogAddressCollection;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class BiogAddressController extends Controller
{
    protected $biogMainRepository;

    /**
     * TextsController constructor.
     * @param BiogMainRepository $biogMainRepository
     */
    public function __construct(BiogMainRepository $biogMainRepository)
    {
        $this->biogMainRepository = $biogMainRepository;
    }
    /**
     * Display a listing of the resource.
     *
     * @return BiogAddressCollection
     */
    public function index($id)
    {
        //
        $biog = $this->biogMainRepository->byPersonId($id);
        $biog->addresses;
        return new BiogAddressCollection($biog->addresses);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create($id)
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store($id, Request $request)
    {
        //
        $data = $request->all();
        if (!$data) {
            return response()->json([
                "error" => 'no from data',
                "message" => "",
                "hint" => ""
            ]);
        }
        $data['c_addr'] = 0;
        $data = $request->all();
        $data = array_except($data, ['_token']);
        $data['c_personid'] = $id;
        $data['c_fy_intercalary'] = (int)($data['c_fy_intercalary']);
        $data['c_ly_intercalary'] = (int)($data['c_ly_intercalary']);
        $data['tts_sysno'] = DB::table('BIOG_ADDR_DATA')->max('tts_sysno') + 1;
//        dd($data);
        (new ToolsRepository())->timestamp($data, True);
        DB::table('BIOG_ADDR_DATA')->insert($data);
        (new OperationRepository())->store(Auth::id(), $id, 1, 'BIOG_ADDR_DATA', $data['tts_sysno'], $data);
        return response()->json([
            "error" => 0,
            "message" => "新增成功",
            "hint" => ""
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id, $add_id)
    {
        //
        return 'show';
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id, $addr_id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id, $addr_id)
    {
        $data = $request->all();
        if (!$data) {
            return response()->json([
                "error" => 'no from data',
                "message" => "",
                "hint" => ""
            ]);
        }
        $data['c_fy_intercalary'] = (int)($data['c_fy_intercalary']);
        $data['c_ly_intercalary'] = (int)($data['c_ly_intercalary']);

        $data = array_except($data, ['_method', '_token']);
        $data = $this->toolsRepository->timestamp($data);
        DB::table('BIOG_ADDR_DATA')->where('tts_sysno',$addr_id)->update($data);
//        dd(DB::table('BIOG_ADDR_DATA')->where('tts_sysno',$id)->first());
        (new OperationRepository())->store(Auth::id(), $id, 3, 'BIOG_ADDR_DATA', $addr_id, $data);
        return response()->json([
            "error" => 0,
            "message" => "修改成功",
            "hint" => ""
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id, $addr_id)
    {
        //
        $this->biogMainRepository->assocDeleteById($addr_id, $id);
        return response()->json([
            "error" => 0,
            "message" => "删除成功",
            "hint" => ""
        ]);
    }
}
