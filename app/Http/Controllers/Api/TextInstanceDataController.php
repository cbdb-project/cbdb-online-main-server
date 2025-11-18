<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\TextInstanceDataRepository;
use Illuminate\Http\Request;

class TextInstanceDataController extends Controller
{
    /**
     * Query text instance data by request parameters.
     *
     * @param Request $request
     * @return mixed
     */
    public function query(Request $request)
    {
        $repository = new TextInstanceDataRepository();
        return $repository->textByQuery($request);
    }
}
