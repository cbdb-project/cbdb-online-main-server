<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Operation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationListController extends Controller {
    public function index(Request $request): JsonResponse {
        $perPage = min((int) $request->input('per_page', 20), 100);
        if ($perPage < 1) {
            $perPage = 20;
        }

        $proposalsOnly = filter_var($request->input('proposals_only', false), FILTER_VALIDATE_BOOLEAN);

        $query = Operation::where('crowdsourcing_status', 0);

        if ($proposalsOnly) {
            $query->whereIn('op_type', [
                Operation::TYPE_PROPOSAL_CREATE,
                Operation::TYPE_PROPOSAL_UPDATE,
            ]);

            $rawStatuses = $request->input('status', []);
            if (!is_array($rawStatuses)) {
                $rawStatuses = [$rawStatuses];
            }
            $allowedStatuses = ['pending', 'approved', 'rejected', 'cancelled'];
            $statusFilters = array_values(array_intersect($rawStatuses, $allowedStatuses));

            if (!empty($statusFilters)) {
                $query->where(function ($sub) use ($statusFilters) {
                    foreach ($statusFilters as $status) {
                        $sub->orWhere('resource_data', 'like', '%"__review_status":"' . $status . '"%');
                    }
                });
            }
        } else {
            $query->whereNotIn('op_type', [
                Operation::TYPE_PROPOSAL_CREATE,
                Operation::TYPE_PROPOSAL_UPDATE,
            ]);

            $rawOpTypes = $request->input('op_type', []);
            if (!is_array($rawOpTypes)) {
                $rawOpTypes = [$rawOpTypes];
            }
            $opTypeFilter = array_values(array_intersect(
                array_filter(array_map('intval', $rawOpTypes)),
                [
                    Operation::TYPE_CREATE,
                    Operation::TYPE_UPDATE_FULL,
                    Operation::TYPE_UPDATE,
                    Operation::TYPE_DELETE,
                ]
            ));
            if (!empty($opTypeFilter)) {
                $query->whereIn('op_type', $opTypeFilter);
            }
        }

        $editorFilter = trim((string) $request->input('editor', ''));
        if ($editorFilter !== '') {
            $query->whereHas('user', function ($q) use ($editorFilter) {
                if (ctype_digit($editorFilter)) {
                    $q->where('id', (int) $editorFilter);
                } else {
                    $escaped = addcslashes($editorFilter, '%_');
                    $q->where('name', 'like', '%' . $escaped . '%');
                }
            });
        }

        $paginator = $query->orderBy('updated_at', 'desc')->paginate($perPage);

        $items = collect($paginator->items())->map(function (Operation $op) {
            return [
                'id' => $op->id,
                'user_id' => $op->user_id,
                'c_personid' => $op->c_personid,
                'op_type' => $op->op_type,
                'resource' => $op->resource,
                'resource_id' => $op->resource_id,
                'resource_data' => is_string($op->resource_data) ? json_decode($op->resource_data, true) : $op->resource_data,
                'resource_original' => is_string($op->resource_original) ? json_decode($op->resource_original, true) : $op->resource_original,
                'crowdsourcing_status' => $op->crowdsourcing_status,
                'created_at' => $op->created_at,
                'updated_at' => $op->updated_at,
            ];
        })->values()->all();

        return response()->json([
            'ok' => true,
            'data' => $items,
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
