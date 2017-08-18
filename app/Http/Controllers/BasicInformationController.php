<?php

namespace App\Http\Controllers;

use App\Http\Requests\BasicInformationRequest;
use App\Repositories\BiogMainRepository;
use App\Repositories\EthnicityRepository;
use Auth;
use Illuminate\Http\Request;

/**
 * Class BiogBasicInformationController
 * @package App\Http\Controllers
 *
 * 人物基本信息主要包括如下几个Model的内容
 * BiogMain Dynasty NianHao YearRangeCode ChoronymCode TextCode Text
 */
class BasicInformationController extends Controller
{
    protected $biogMainRepository;
    protected $ethnicityRepository;

    /**
     * Create a new controller instance.
     *
     * @param BiogMainRepository $biogMainRepository
     */
    public function __construct(BiogMainRepository $biogMainRepository, EthnicityRepository $ethnicityRepository)
    {
        $this->middleware('auth');
        $this->biogMainRepository = $biogMainRepository;
        $this->ethnicityRepository = $ethnicityRepository;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('biogmains.basicinformation.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
//        return redirect()->route('basicinformation.show', [1]);
    }

    /**
     * Display the specified resource.
     *
     * @param  int $id
     * @return \App\BiogMain|\Illuminate\Http\Response
     */
    public function show($id)
    {
        $biogbasicinformation = $this->biogMainRepository->byPersonId($id);
        return $biogbasicinformation;
//        return view('biogmains.show', $result);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $biogbasicinformation = $this->biogMainRepository->byPersonId($id);
        return view('biogmains.basicinformation.edit', ['basicinformation' => $biogbasicinformation]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param BasicInformationRequest|Request $request
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function update(BasicInformationRequest $request, $id)
    {
        $this->biogMainRepository->updateById($request, $id);
        flash('Update success', 'success');

        return redirect()->route('basicinformation.edit', $id);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }


}
