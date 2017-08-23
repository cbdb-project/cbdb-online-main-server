<?php

namespace App\Http\Controllers;

use App\Repositories\ChoronymRepository;
use App\Repositories\DynastyRepository;
use App\Repositories\EthnicityRepository;
use App\Repositories\NianHaoRepository;
use Illuminate\Http\Request;

class ApiController extends Controller
{
    public function ethnicity()
    {
        $ethnicityRepository = new EthnicityRepository();
        return $ethnicityRepository->ethnicities();
    }

    public function choronym()
    {
        $choronymRepository = new ChoronymRepository();
        return $choronymRepository->choronyms();
    }

    public function dynasty()
    {
        $dynastyRepository = new DynastyRepository();
        return $dynastyRepository->dynasties();
    }

    public function nianhao()
    {
        $nianhaoRepository = new NianHaoRepository();
        return $nianhaoRepository->nianhaos();
    }
}
