<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\AddrBelongsDataRepository;
use Illuminate\Http\Request;

class AddrBelongsDataController extends Controller
{
    /**
     * Query address belongs data by request parameters.
     *
     * @param Request $request
     * @return mixed
     */
    public function query(Request $request)
    {
        $repository = new AddrBelongsDataRepository();
        return $repository->AddrByQuery($request);
    }
}
