<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TextLookupController extends Controller {
    public function index(Request $request): JsonResponse {
        $ids = $this->parseIds($request->query('ids'));
        if ($ids === []) {
            return response()->json([
                'ok' => false,
                'message' => '缺少 ids 參數',
                'errors' => [
                    'ids' => ['required'],
                ],
            ], 422);
        }

        $rows = $this->baseQuery()
            ->whereIn('texts.c_textid', $ids)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->keyBy('c_textid');

        $ordered = [];
        foreach ($ids as $id) {
            $row = $rows->get($id);
            if ($row !== null) {
                $ordered[] = $row;
            }
        }

        $foundIds = array_map('intval', array_column($ordered, 'c_textid'));
        $missingIds = array_values(array_diff($ids, $foundIds));

        return response()->json([
            'ok' => true,
            'data' => $ordered,
            'meta' => [
                'requested_ids' => $ids,
                'found_count' => count($ordered),
                'missing_ids' => $missingIds,
            ],
        ]);
    }

    public function show(int $textId): JsonResponse {
        $row = $this->baseQuery()
            ->where('texts.c_textid', $textId)
            ->first();

        if (!$row) {
            return response()->json([
                'ok' => false,
                'message' => 'TEXT_CODES 記錄不存在',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'data' => (array) $row,
        ]);
    }

    private function baseQuery() {
        return DB::table('TEXT_CODES as texts')
            ->select([
                'texts.*',
                'source.c_title_chn as c_source_title_chn',
                'source.c_title as c_source_title',
                'source.c_url_api as c_source_url_api',
                'source.c_url_api_coda as c_source_url_api_coda',
                'source.c_url_homepage as c_source_url_homepage',
            ])
            ->leftJoin('TEXT_CODES as source', 'source.c_textid', '=', 'texts.c_source');
    }

    /**
     * @return array<int,int>
     */
    private function parseIds(mixed $raw): array {
        if (is_string($raw)) {
            $segments = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } elseif (is_array($raw)) {
            $segments = $raw;
        } else {
            return [];
        }

        $normalized = [];
        foreach ($segments as $segment) {
            $value = is_scalar($segment) ? trim((string) $segment) : '';
            if ($value === '' || !preg_match('/^\d+$/', $value)) {
                continue;
            }

            $normalized[] = (int) $value;
        }

        return array_values(array_unique($normalized));
    }
}
