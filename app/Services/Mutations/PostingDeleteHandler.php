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
 * POSTED_TO_OFFICE_DATA（任官）delete handler（API v2）
 *
 * 任官刪除連動 POSTED_TO_ADDR_DATA 與 POSTING_DATA，且 PK 不含 c_personid，
 * 故 direct 模式委派既有 BiogMainRepository::officeDeleteById()（刪主表 + 地址副表 + POSTING_DATA + operation + audit）。
 * proposal 模式僅寫 TYPE_PROPOSAL_DELETE operation（不實際刪除），由審核端核准後才連帶刪除主／副表。
 * 委派前先驗證記錄存在且屬於該 person（repository 找不到時靜默返回，需在此回 404）。
 */
class PostingDeleteHandler extends AbstractMutationHandler {
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
        return in_array($resource, ['postings', 'posting', 'offices', 'posted_to_office_data'], true)
            && in_array($mode, ['direct', 'proposal'], true)
            && $operation === 'delete';
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $authorizationError = $mode === 'proposal' ? $this->authorizeProposal() : $this->authorizeDirect();
        if ($authorizationError) {
            return $authorizationError;
        }

        try {
            CompositePrimaryKey::validateOrFail($targetPk, 'POSTED_TO_OFFICE_DATA');
        } catch (\Throwable $e) {
            return $this->errorResponse('主鍵格式不正確', 422, ['pk' => [$e->getMessage()]]);
        }

        $officeId = $targetPk['c_office_id'];
        $postingId = $targetPk['c_posting_id'];

        $row = DB::table('POSTED_TO_OFFICE_DATA')
            ->where('c_office_id', $officeId)
            ->where('c_posting_id', $postingId)
            ->first();
        if (!$row) {
            return $this->errorResponse('POSTED_TO_OFFICE_DATA 記錄不存在', 404);
        }

        // person 歸屬：避免刪到別人的記錄
        if ((string) ($row->c_personid ?? '') !== (string) $personId) {
            return $this->errorResponse('person_id 與目標記錄不一致', 422, ['person_id' => ['mismatch']]);
        }

        $comment = is_string($meta['comment'] ?? null) ? trim($meta['comment']) : '';
        $normalizedPk = ['c_office_id' => $officeId, 'c_posting_id' => $postingId];

        if ($mode === 'proposal') {
            return $this->handleProposal($personId, $normalizedPk, $this->auditLogService->normalizeRow($row), $comment);
        }

        // officeDeleteById 以 "c_office_id-c_posting_id" 字串定位（兩者皆數字，無分隔符歧義）
        $this->biogMainRepository->officeDeleteById($officeId . '-' . $postingId, $personId);

        return response()->json([
            'ok' => true,
            'resource' => 'postings',
            'mode' => 'direct',
            'operation' => 'delete',
            'result' => [
                'pk' => $normalizedPk,
            ],
        ]);
    }

    protected function handleProposal(int $personId, array $targetPk, array $originalArray, string $comment): JsonResponse {
        $resourceId = CompositePrimaryKey::buildStoredResourceId($targetPk);

        if ($this->operationRepository->hasPendingDeleteProposal('POSTED_TO_OFFICE_DATA', $resourceId)) {
            return $this->errorResponse('相同主鍵已有待審核的刪除提案', 409, [
                'target.pk' => ['pending_proposal_exists'],
            ]);
        }

        $proposalData = array_merge($originalArray, [
            '__proposal_meta' => [
                'action' => 'delete',
                'resource_type' => 'postings',
                'table' => 'POSTED_TO_OFFICE_DATA',
                'display_name' => '任官',
                'submitted_by' => Auth::user()->name ?? Auth::id(),
                'submitted_by_id' => Auth::id(),
                'submitted_at' => Carbon::now()->format('Y-m-d H:i:s'),
                'comment' => $comment,
            ],
            '__review_status' => 'pending',
            '__key_columns' => ['c_office_id', 'c_posting_id'],
        ]);

        $operation = $this->operationRepository->store(
            Auth::id(),
            $personId,
            Operation::TYPE_PROPOSAL_DELETE,
            'POSTED_TO_OFFICE_DATA',
            $resourceId,
            $proposalData,
            $originalArray
        );

        return response()->json([
            'ok' => true,
            'resource' => 'postings',
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
