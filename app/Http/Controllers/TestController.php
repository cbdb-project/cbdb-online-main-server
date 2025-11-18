<?php

namespace App\Http\Controllers;

use App\TextCode;
use Illuminate\Http\Request;

class TestController extends Controller
{
    /**
     * Test route for development/debugging.
     *
     * @param Request $request
     * @return \App\TextCode
     */
    public function index(Request $request)
    {
        $data = TextCode::find(2031);
        $data->type;
        return $data;
    }

    /**
     * Test progress endpoint for wiki maintenance.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function testProgress()
    {
        return response()->json(['success' => true, 'message' => 'Test route works']);
    }
}
