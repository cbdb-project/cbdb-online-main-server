<?php

namespace App\Services\Mutations;

use App\Models\Operation;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * POSSESSION_DATA delete handler（API v2）
 *
 * POSSESSION_DATA 刪除連動 POSSESSION_ADDR 副表，且 PK 不含 c_personid，
 * 故 direct 模式委派既有 BiogMainRepository::possessionDeleteById()（刪主表 + 副表 + operation + audit）。
 * proposal 模式僅寫 TYPE_PROPOSAL_DELETE operation（不實際刪除），由審核端核准後才連帶刪除主／副表。
 * 委派前先驗證記錄存在且屬於該 person（repository 找不到時靜默返回，需在此回 404）。
 */
class PossessionDeleteHandler extends AbstractMutationHandler {
    protected BiogMainRepository $biogMainRepository;
    protected AuditLogService $auditLogService;
    protected OperationRepository $operationRepository;

    public function __construct(
        BiogMainRepository $biogMainRepository,
        AuditLogService $auditLogService,
        OperationRepository $operationRepository
    ) {
        $this->biogMainRepository = $biogMainRepository;
        $this->auditLogService = $auditLogService;
        $this->operationRepository = $operationRepository;
    }

    public function supports(string $resource, string $mode, string $operation): bool {
        return in_array($resource, ['possessions', 'possession', 'possession_data'], true)
            && in_array($mode, ['direct', 'proposal'], true)
            && $operation === 'delete';
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $authorizationError = $mode === 'proposal' ? $this->authorizeProposal() : $this->authorizeDirect();
        if ($authorizationError) {
            return $authorizationError;
        }

        $recordId = $targetPk['c_possession_record_id'] ?? null;
        if ($recordId === null || $recordId === '') {
            return $this->errorResponse('缺少 c_possession_record_id', 422, [
                'target.pk' => ['c_possession_record_id required'],
            ]);
        }

        $row = DB::table('POSSESSION_DATA')->where('c_possession_record_id', $recordId)->first();
        if (!$row) {
            return $this->errorResponse('POSSESSION_DATA 記錄不存在', 404);
        }

        // person 歸屬：避免刪到別人的記錄
        if ((string) ($row->c_personid ?? '') !== (string) $personId) {
            return $this->errorResponse('person_id 與目標記錄不一致', 422, ['person_id' => ['mismatch']]);
        }

        $comment = is_string($meta['comment'] ?? null) ? trim($meta['comment']) : '';

        if ($mode === 'proposal') {
            return $this->handleProposal($personId, ['c_possession_record_id' => $recordId], $this->auditLogService->normalizeRow($row), $comment);
        }

        $this->biogMainRepository->possessionDeleteById($recordId, $personId);

        return response()->json([
            'ok' => true,
            'resource' => 'possessions',
            'mode' => 'direct',
            'operation' => 'delete',
            'result' => [
                'pk' => ['c_possession_record_id' => $recordId],
            ],
        ]);
    }

    protected function handleProposal(int $personId, array $targetPk, array $originalArray, string $comment): JsonResponse {
        $resourceId = CompositePrimaryKey::buildStoredResourceId($targetPk);

        if ($this->operationRepository->hasPendingDeleteProposal('POSSESSION_DATA', $resourceId)) {
            return $this->errorResponse('相同主鍵已有待審核的刪除提案', 409, [
                'target.pk' => ['pending_proposal_exists'],
            ]);
        }

        $proposalData = array_merge($originalArray, [
            '__proposal_meta' => [
                'action' => 'delete',
                'resource_type' => 'possessions',
                'table' => 'POSSESSION_DATA',
                'display_name' => '財產',
                'submitted_by' => Auth::user()->name ?? Auth::id(),
                'submitted_by_id' => Auth::id(),
                'submitted_at' => Carbon::now()->format('Y-m-d H:i:s'),
                'comment' => $comment,
            ],
            '__review_status' => 'pending',
            '__key_columns' => ['c_possession_record_id'],
        ]);

        $operation = $this->operationRepository->store(
            Auth::id(),
            $personId,
            Operation::TYPE_PROPOSAL_DELETE,
            'POSSESSION_DATA',
            $resourceId,
            $proposalData,
            $originalArray
        );

        return response()->json([
            'ok' => true,
            'resource' => 'possessions',
            'mode' => 'proposal',
            'operation' => 'delete',
            'result' => [
                'pk' => $targetPk,
                'status' => 'proposal_deleted',
                'operation_id' => $operation?->id,
            ],
        ]);
    }
}
