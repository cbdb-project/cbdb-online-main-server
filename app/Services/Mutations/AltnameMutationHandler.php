<?php

namespace App\Services\Mutations;

use App\Repositories\BiogMainRepository;
use App\Services\NameSearchIndexService;
use App\Support\CompositePrimaryKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AltnameMutationHandler extends AbstractMutationHandler {
    protected BiogMainRepository $biogMainRepository;
    protected NameSearchIndexService $nameSearchIndexService;

    public function __construct(BiogMainRepository $biogMainRepository, NameSearchIndexService $nameSearchIndexService) {
        $this->biogMainRepository = $biogMainRepository;
        $this->nameSearchIndexService = $nameSearchIndexService;
    }

    public function supports(string $resource, string $mode, string $operation): bool {
        return $resource === 'altnames' && $mode === 'direct' && $operation === 'update';
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $authorizationError = $this->authorizeDirect();
        if ($authorizationError) {
            return $authorizationError;
        }

        try {
            CompositePrimaryKey::validateOrFail($targetPk, 'ALTNAME_DATA');
        } catch (\Throwable $e) {
            return $this->errorResponse('主鍵格式不正確', 422, ['pk' => [$e->getMessage()]]);
        }

        if (empty($changes)) {
            return $this->errorResponse('changes 不可為空', 422, ['changes' => ['empty']]);
        }

        $original = $this->biogMainRepository->altnameById($targetPk);
        if (!$original) {
            return $this->errorResponse('ALTNAME_DATA 記錄不存在', 404);
        }

        if ((string) ($targetPk['c_personid'] ?? '') !== (string) $personId) {
            return $this->errorResponse('person_id 與 target.pk.c_personid 不一致', 422, [
                'person_id' => ['mismatch'],
            ]);
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

        $proxy = Request::create('/api/v2/mutate', 'PATCH', $updateData);
        if (isset($meta['comment']) && is_string($meta['comment']) && trim($meta['comment']) !== '') {
            $proxy->request->set('__proposal_comment', trim($meta['comment']));
        }

        try {
            $newPk = $this->biogMainRepository->altnameUpdateById($proxy, $personId, $targetPk);
        } catch (\Throwable $e) {
            return $this->errorResponse('更新失敗：'.$e->getMessage(), 500);
        }

        if (!$newPk) {
            return $this->errorResponse('ALTNAME_DATA 記錄不存在', 404);
        }

        $this->syncAltnameIndexAfterUpdate($original, $updateData, $targetPk, $newPk);
        $updated = $this->biogMainRepository->altnameById($newPk);

        return response()->json([
            'ok' => true,
            'resource' => 'altnames',
            'mode' => 'direct',
            'operation' => 'update',
            'result' => [
                'pk' => $newPk,
                'updated_fields' => array_keys($updateData),
                'row' => $updated ? (array) $updated : null,
            ],
        ]);
    }

    protected function syncAltnameIndexAfterUpdate($original, array $changes, array $targetPk, array $newPk): void {
        if (!$original || !Schema::hasTable('CBDB__NAME_FTS')) {
            return;
        }

        $newName = $changes['c_alt_name_chn'] ?? ($targetPk['c_alt_name_chn'] ?? null);
        $newType = $changes['c_alt_name_type_code'] ?? ($targetPk['c_alt_name_type_code'] ?? null);

        $nameChanged = ($original->c_alt_name_chn ?? null) !== $newName;
        $typeChanged = ($original->c_alt_name_type_code ?? null) !== $newType;

        if (!$nameChanged && !$typeChanged) {
            return;
        }

        if (!empty($original->c_alt_name_chn)) {
            $this->nameSearchIndexService->removeAltname(
                $original->c_personid,
                $original->c_alt_name_type_code,
                $original->c_alt_name_chn
            );
        }

        if (!empty($newName)) {
            $this->nameSearchIndexService->indexAltname(
                $newPk['c_personid'] ?? $targetPk['c_personid'],
                $newType,
                $newName
            );
        }
    }
}
