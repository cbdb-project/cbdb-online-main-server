<?php

namespace App\Services\Mutations;

use App\Repositories\BiogMainRepository;
use App\Support\CompositePrimaryKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PossessionMutationHandler extends AbstractMutationHandler {
    protected BiogMainRepository $biogMainRepository;

    public function __construct(BiogMainRepository $biogMainRepository) {
        $this->biogMainRepository = $biogMainRepository;
    }

    public function supports(string $resource, string $mode, string $operation): bool {
        return in_array($resource, ['possession', 'possessions'], true) && $mode === 'direct' && $operation === 'update';
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $authorizationError = $this->authorizeDirect();
        if ($authorizationError) {
            return $authorizationError;
        }

        try {
            CompositePrimaryKey::validateOrFail($targetPk, 'POSSESSION_DATA');
        } catch (\Throwable $e) {
            return $this->errorResponse('主鍵格式不正確', 422, ['pk' => [$e->getMessage()]]);
        }

        if (empty($changes)) {
            return $this->errorResponse('changes 不可為空', 422, ['changes' => ['empty']]);
        }

        $recordId = $targetPk['c_possession_record_id'] ?? null;
        $original = $recordId !== null ? DB::table('POSSESSION_DATA')->where('c_possession_record_id', $recordId)->first() : null;
        if (!$original) {
            return $this->errorResponse('POSSESSION_DATA 記錄不存在', 404);
        }

        if ((string) ($original->c_personid ?? '') !== (string) $personId) {
            return $this->errorResponse('person_id 與目標記錄不一致', 422, ['person_id' => ['mismatch']]);
        }

        $allowedQuickFields = ['c_sequence'];
        $updateData = array_intersect_key($changes, array_flip($allowedQuickFields));
        if (empty($updateData)) {
            return $this->errorResponse('目前此接口至少需包含可更新欄位（例如 c_sequence）', 422, [
                'changes' => ['no_supported_fields'],
            ]);
        }

        $proxy = Request::create('/api/v2/mutate', 'PATCH', array_merge($updateData, [
            'c_addr_id' => $original->c_addr_id ?? 0,
            'c_source' => $original->c_source ?? 0,
            'c_measure_code' => $original->c_measure_code ?? 0,
            'c_possession_act_code' => $original->c_possession_act_code ?? 0,
        ]));
        if (isset($meta['comment']) && is_string($meta['comment']) && trim($meta['comment']) !== '') {
            $proxy->request->set('__proposal_comment', trim($meta['comment']));
        }

        try {
            $this->biogMainRepository->possessionUpdateById($proxy, $personId, $recordId);
        } catch (\Throwable $e) {
            return $this->errorResponse('更新失敗：'.$e->getMessage(), 500);
        }

        $updated = DB::table('POSSESSION_DATA')->where('c_possession_record_id', $recordId)->first();

        return response()->json([
            'ok' => true,
            'resource' => 'possessions',
            'mode' => 'direct',
            'operation' => 'update',
            'result' => [
                'pk' => ['c_possession_record_id' => $recordId],
                'updated_fields' => array_keys($updateData),
                'row' => $updated ? (array) $updated : null,
            ],
        ]);
    }
}
