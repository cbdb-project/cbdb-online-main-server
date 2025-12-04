<?php

namespace App\Http\Controllers;

class TestController extends Controller
{
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
