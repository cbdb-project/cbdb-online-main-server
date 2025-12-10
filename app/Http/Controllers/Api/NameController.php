<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BiogMainRepository;
use Illuminate\Http\Request;

class NameController extends Controller {
    /**
     * Query names by request parameters.
     *
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request) {
        return BiogMainRepository::namesByQuery($request);
    }
}
