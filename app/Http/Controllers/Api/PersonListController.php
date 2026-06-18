<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BiogMain;
use App\Services\PersonChangeIndexService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonListController extends Controller {
    public function index(Request $request): JsonResponse {
        $perPage = min((int) $request->input('per_page', 100), 1000);
        if ($perPage < 1) {
            $perPage = 100;
        }

        $modifiedSince = PersonChangeIndexService::parseThreshold($request->input('modified_since'));

        // c_created_date 取自 BIOG_MAIN（人物建檔時間）；c_modified_date 取自 person_change_index
        // 的人物層級水位線（c_last_modified_date），別名輸出為 c_modified_date。
        // 刻意不選 BIOG_MAIN.c_modified_date（本表語意，會與別名衝突）；用 query builder 明確表名 join，
        // 避免 SQLite/MySQL 表名大小寫差異。person_change_index 一人一列，leftJoin 為 1:1，不影響分頁筆數。
        $query = BiogMain::query()
            ->leftJoin('person_change_index', 'BIOG_MAIN.c_personid', '=', 'person_change_index.c_personid')
            ->select([
                'BIOG_MAIN.c_personid',
                'BIOG_MAIN.c_created_date',
                'person_change_index.c_last_modified_date as c_modified_date',
            ]);

        // 增量同步：只回傳 c_modified_date >= modified_since 的人物（含邊界、利用水位線索引）。
        // c_last_modified_date 為 NULL（無水位線列）者不符 >= 比較而被排除——語意正確（修改時間未知不算「此後被改」）。
        if ($modifiedSince !== null) {
            $query->where('person_change_index.c_last_modified_date', '>=', $modifiedSince);
        }

        $paginator = $query
            ->orderBy('BIOG_MAIN.c_personid', 'asc')
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
