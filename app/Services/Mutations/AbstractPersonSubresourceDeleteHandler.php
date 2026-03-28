<?php

namespace App\Services\Mutations;

use App\Models\Operation;
use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 人物子資源 delete handler 共用基底類
 *
 * 抽象出 repeated-form delete 的共通邏輯：
 * - 驗證 composite PK
 * - 驗證 person_id 一致性
 * - 查原始 row（不存在 → 404）
 * - direct 刪除（含 transaction + operation + audit_log）
 * - proposal 寫 operation
 * - 統一 response shape
 */
abstract class AbstractPersonSubresourceDeleteHandler extends AbstractMutationHandler {
    protected OperationRepository $operationRepository;
    protected AuditLogService $auditLogService;

    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        $this->operationRepository = $operationRepository;
        $this->auditLogService = $auditLogService;
    }

    // ── 子類必須實作 ─────────────────────────────────────────

    /** 資源名稱（回傳用），例如 'altnames' */
    abstract protected function resourceName(): string;

    /** 資料表名稱，例如 'ALTNAME_DATA' */
    abstract protected function tableName(): string;

    /** 顯示名稱（proposal meta 用），例如 '別名' */
    abstract protected function displayName(): string;

    /** 可接受的 resource alias 列表（含主名） */
    abstract protected function resourceAliases(): array;

    /** __key_columns（proposal meta 用），通常與 CompositePrimaryKey SCHEMAS 一致 */
    abstract protected function keyColumns(): array;

    /** person_id 在主鍵中的欄位名，預設 'c_personid' */
    protected function personIdColumn(): string {
        return 'c_personid';
    }

    // ── supports ─────────────────────────────────────────────

    public function supports(string $resource, string $mode, string $operation): bool {
        return in_array($resource, $this->resourceAliases(), true)
            && $mode === 'direct'
            && $operation === 'delete';
    }

    // ── handle ───────────────────────────────────────────────

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        // 1. 授權
        $authorizationError = $mode === 'proposal' ? $this->authorizeProposal() : $this->authorizeDirect();
        if ($authorizationError) {
            return $authorizationError;
        }

        // 2. 驗證 PK 格式
        try {
            CompositePrimaryKey::validateOrFail($targetPk, $this->tableName());
        } catch (\Throwable $e) {
            return $this->errorResponse('主鍵格式不正確', 422, ['pk' => [$e->getMessage()]]);
        }

        // 3. person_id 與 PK 一致性
        $pkValidationError = $this->validatePersonIdInPk($personId, $targetPk);
        if ($pkValidationError) {
            return $pkValidationError;
        }

        // 4. 查原始記錄
        $original = $this->findOriginalRow($targetPk);
        if (!$original) {
            return $this->errorResponse($this->tableName() . ' 記錄不存在', 404);
        }

        // 5. 驗證 person_id 與記錄一致性
        $rowValidationError = $this->validatePersonIdInRow($personId, $original);
        if ($rowValidationError) {
            return $rowValidationError;
        }

        $comment = is_string($meta['comment'] ?? null) ? trim($meta['comment']) : '';
        $originalArray = $this->auditLogService->normalizeRow($original);

        // 6. 分派到 direct / proposal
        if ($mode === 'proposal') {
            return $this->handleProposal($personId, $targetPk, $originalArray, $comment);
        }

        return $this->handleDirect($personId, $targetPk, $originalArray, $comment);
    }

    /**
     * 驗證 person_id 與 PK 中的 c_personid 一致性
     */
    protected function validatePersonIdInPk(int $personId, array $targetPk): ?JsonResponse {
        $pkPersonId = $targetPk[$this->personIdColumn()] ?? null;
        if ((string) $pkPersonId !== (string) $personId) {
            return $this->errorResponse('person_id 與 target.pk.' . $this->personIdColumn() . ' 不一致', 422, [
                'person_id' => ['mismatch'],
            ]);
        }

        return null;
    }

    /**
     * 驗證 person_id 與原始記錄的 c_personid 一致性
     */
    protected function validatePersonIdInRow(int $personId, object $original): ?JsonResponse {
        if ((string) ($original->{$this->personIdColumn()} ?? '') !== (string) $personId) {
            return $this->errorResponse('person_id 與目標記錄不一致', 422, ['person_id' => ['mismatch']]);
        }

        return null;
    }

    // ── Direct Delete ────────────────────────────────────────

    protected function handleDirect(int $personId, array $targetPk, array $originalArray, string $comment): JsonResponse {
        $operationId = (string) Str::ulid();
        /** @var Operation|null $operation */
        $operation = null;

        try {
            DB::transaction(function () use ($personId, $targetPk, $originalArray, $comment, $operationId, &$operation) {
                // 刪除資料表記錄
                $this->performDelete($targetPk);

                $resourceId = CompositePrimaryKey::buildStoredResourceId($targetPk);

                // 寫 operation
                $resourceData = array_merge($originalArray, ['__operation_id' => $operationId]);
                if ($comment !== '') {
                    $resourceData['__note'] = $comment;
                }

                $operation = $this->operationRepository->store(
                    Auth::id(),
                    $personId,
                    Operation::TYPE_DELETE,
                    $this->tableName(),
                    $resourceId,
                    $resourceData,
                    $originalArray
                );

                // 寫 audit_log
                $this->auditLogService->write(
                    $this->tableName(),
                    'DELETE',
                    $targetPk,
                    $originalArray,
                    null,
                    'user',
                    (string) Auth::id(),
                    $operation ? (string) $operation->id : null
                );
            });
        } catch (\Illuminate\Database\QueryException $e) {
            throw $e;
        }

        return response()->json([
            'ok' => true,
            'resource' => $this->resourceName(),
            'mode' => 'direct',
            'operation' => 'delete',
            'result' => [
                'pk' => $targetPk,
                'operation_id' => $operation?->id,
            ],
        ]);
    }

    // ── Proposal Delete ──────────────────────────────────────

    protected function handleProposal(int $personId, array $targetPk, array $originalArray, string $comment): JsonResponse {
        $resourceId = CompositePrimaryKey::buildStoredResourceId($targetPk);

        // 檢查：相同 resource_id 不得已有待審核的刪除提案
        if ($this->operationRepository->hasPendingDeleteProposal($this->tableName(), $resourceId)) {
            return $this->errorResponse('相同主鍵已有待審核的刪除提案', 409, [
                'target.pk' => ['pending_proposal_exists'],
            ]);
        }

        $proposalData = array_merge($originalArray, [
            '__proposal_meta' => [
                'action' => 'delete',
                'resource_type' => $this->resourceName(),
                'table' => $this->tableName(),
                'display_name' => $this->displayName(),
                'submitted_by' => Auth::user()->name ?? Auth::id(),
                'submitted_by_id' => Auth::id(),
                'submitted_at' => Carbon::now()->format('Y-m-d H:i:s'),
                'comment' => $comment,
            ],
            '__review_status' => 'pending',
            '__key_columns' => $this->keyColumns(),
        ]);

        $operation = $this->operationRepository->store(
            Auth::id(),
            $personId,
            Operation::TYPE_PROPOSAL_DELETE,
            $this->tableName(),
            $resourceId,
            $proposalData,
            $originalArray
        );

        return response()->json([
            'ok' => true,
            'resource' => $this->resourceName(),
            'mode' => 'proposal',
            'operation' => 'delete',
            'result' => [
                'pk' => $targetPk,
                'status' => 'proposal_deleted',
                'operation_id' => $operation?->id,
            ],
        ]);
    }

    // ── 可覆寫的 helper ──────────────────────────────────────

    /** 查詢原始記錄 */
    protected function findOriginalRow(array $pk): ?object {
        $query = DB::table($this->tableName());
        foreach ($this->keyColumns() as $col) {
            $query->where($col, $pk[$col] ?? null);
        }

        return $query->first();
    }

    /** 執行資料表刪除 */
    protected function performDelete(array $targetPk): void {
        $query = DB::table($this->tableName());
        foreach ($this->keyColumns() as $col) {
            $query->where($col, $targetPk[$col] ?? null);
        }
        $query->delete();
    }
}
