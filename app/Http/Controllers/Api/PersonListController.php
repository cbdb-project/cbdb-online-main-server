<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BiogMain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonListController extends Controller {
    public function index(Request $request): JsonResponse {
        $perPage = min((int) $request->input('per_page', 100), 1000);
        if ($perPage < 1) {
            $perPage = 100;
        }

        $paginator = BiogMain::select(['c_personid'])
            ->orderBy('c_personid', 'asc')
            ->paginate($perPage);

        return response()->json([
            'ok' => true,
            'data' => $paginator->items(),
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem() ?? 0,
                'to' => $paginator->lastItem() ?? 0,
            ],
        ]);
    }
}
