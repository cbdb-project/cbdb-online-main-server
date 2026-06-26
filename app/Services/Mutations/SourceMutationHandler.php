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
            CompositePrimaryKey::validateOrFail($targetPk, 'BIOG_SOURCE_DATA', ['c_pages']);
        } catch (\Throwable $e) {
            return $this->errorResponse('主鍵格式不正確', 422, ['pk' => [$e->getMessage()]]);
        }

        $validationErrors = $this->biogSourceRepository->validateMutation($personId, $targetPk, $changes, $operation);
        if ($validationErrors !== []) {
            return $this->errorResponse('參數校驗失敗', 422, $validationErrors);
        }

        if ($operation === 'create') {
            $data = $this->biogSourceRepository->buildCreatePayload($personId, $targetPk, $changes);
            $existing = $this->biogSourceRepository->findByPk($data);
            if ($existing) {
                return $this->errorResponse('BIOG_SOURCE_DATA 記錄已存在', 409, [
                    'target.pk' => ['duplicate'],
                ]);
            }

            if ($this->biogSourceRepository->hasPendingCreateProposal($data)) {
                return $this->errorResponse('相同主鍵已有待審核提案', 409, [
                    'target.pk' => ['pending_proposal_exists'],
                ]);
            }
        } else {
            $existing = $this->biogSourceRepository->findByPk($targetPk);
            if (!$existing) {
                return $this->errorResponse('BIOG_SOURCE_DATA 記錄不存在', 404);
            }

            $data = $this->biogSourceRepository->buildUpdatePayload($personId, $targetPk, $changes, $existing);

            // 改鍵（c_textid/c_pages 變更）碰撞偵測：新主鍵已存在另一列時擋下，避免 UPDATE 覆寫他列。
            if ($this->biogSourceRepository->isReKeyed($targetPk, $changes) && $this->biogSourceRepository->findByPk($data)) {
                return $this->errorResponse('變更後的出處主鍵與現有記錄重複', 409, [
                    'target.pk' => ['duplicate'],
                ]);
            }

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

        try {
            $result = $operation === 'create'
                ? $this->biogSourceRepository->createDirect($personId, $data)
                : $this->biogSourceRepository->updateDirect($personId, $targetPk, $data, $existing);
        } catch (\Illuminate\Database\QueryException $e) {
            // 改鍵/新增競態：findByPk 預檢後另一請求搶占同主鍵 → DB 唯一鍵衝突，縱深防禦轉 409
            // （與其他子資源 handler 一致），不冒成未捕捉的 500。
            if ($this->isUniqueConstraintViolation($e)) {
                return $this->errorResponse('變更後的出處主鍵與現有記錄重複', 409, [
                    'target.pk' => ['duplicate'],
                ]);
            }

            throw $e;
        }

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

    /**
     * 判斷 QueryException 是否為唯一性約束衝突（MySQL 1062 / SQLite 19 = SQLITE_CONSTRAINT）。
     * 供改鍵競態縱深防禦轉 409 用（對齊 AbstractPersonSubresourceMutationHandler 同名判斷）。
     */
    private function isUniqueConstraintViolation(\Illuminate\Database\QueryException $e): bool {
        $code = (int) ($e->errorInfo[1] ?? 0);
        if (in_array($code, [1062, 19], true)) {
            return true;
        }
        $msg = $e->getMessage();

        return str_contains($msg, 'UNIQUE') || str_contains($msg, 'Duplicate entry');
    }
}
