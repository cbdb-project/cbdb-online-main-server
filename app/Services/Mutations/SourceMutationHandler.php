<?php

namespace App\Services\Mutations;

use App\Repositories\BiogSourceRepository;
use App\Support\CompositePrimaryKey;
use Illuminate\Http\JsonResponse;

class SourceMutationHandler extends AbstractMutationHandler {
    protected BiogSourceRepository $biogSourceRepository;

    public function __construct(BiogSourceRepository $biogSourceRepository) {
        $this->biogSourceRepository = $biogSourceRepository;
    }

    public function supports(string $resource, string $mode, string $operation): bool {
        return $resource === 'sources'
            && in_array($mode, ['proposal', 'direct'], true)
            && in_array($operation, ['create', 'update'], true);
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $authorizationError = $mode === 'proposal' ? $this->authorizeProposal() : $this->authorizeDirect();
        if ($authorizationError) {
            return $authorizationError;
        }

        try {
            CompositePrimaryKey::validateOrFail($targetPk, 'BIOG_SOURCE_DATA');
        } catch (\Throwable $e) {
            return $this->errorResponse('主鍵格式不正確', 422, ['pk' => [$e->getMessage()]]);
        }

        $validationErrors = $this->biogSourceRepository->validateMutation($personId, $targetPk, $changes, $operation);
        if ($validationErrors !== []) {
            return $this->errorResponse('參數校驗失敗', 422, $validationErrors);
        }

        $existing = $this->biogSourceRepository->findByPk($targetPk);
        if ($operation === 'create') {
            if ($existing) {
                return $this->errorResponse('BIOG_SOURCE_DATA 記錄已存在', 409, [
                    'target.pk' => ['duplicate'],
                ]);
            }

            if ($this->biogSourceRepository->hasPendingCreateProposal($targetPk)) {
                return $this->errorResponse('相同主鍵已有待審核提案', 409, [
                    'target.pk' => ['pending_proposal_exists'],
                ]);
            }

            $data = $this->biogSourceRepository->buildCreatePayload($personId, $targetPk, $changes);
        } else {
            if (!$existing) {
                return $this->errorResponse('BIOG_SOURCE_DATA 記錄不存在', 404);
            }

            $data = $this->biogSourceRepository->buildUpdatePayload($personId, $targetPk, $changes, $existing);
            if (!$this->biogSourceRepository->hasMeaningfulUpdate($existing, $data)) {
                return $this->errorResponse('未偵測到任何修改內容', 422, [
                    'changes' => ['no_effective_changes'],
                ]);
            }
        }

        $comment = is_string($meta['comment'] ?? null) ? trim($meta['comment']) : '';

        if ($mode === 'proposal') {
            $result = $operation === 'create'
                ? $this->biogSourceRepository->createProposal($personId, $data, $comment)
                : $this->biogSourceRepository->updateProposal($personId, $targetPk, $data, $existing, $comment);

            return response()->json([
                'ok' => true,
                'resource' => 'sources',
                'mode' => $mode,
                'operation' => $operation,
                'result' => [
                    'pk' => $result['pk'],
                    'status' => $operation === 'create' ? 'proposal_created' : 'proposal_updated',
                    'operation_id' => $result['operation_id'],
                ],
            ]);
        }

        $result = $operation === 'create'
            ? $this->biogSourceRepository->createDirect($personId, $data)
            : $this->biogSourceRepository->updateDirect($personId, $targetPk, $data, $existing);

        return response()->json([
            'ok' => true,
            'resource' => 'sources',
            'mode' => $mode,
            'operation' => $operation,
            'result' => [
                'pk' => $result['pk'],
                'status' => $operation === 'create' ? 'created' : 'updated',
                'operation_id' => $result['operation_id'],
                'row' => $result['row'],
            ],
        ]);
    }
}
