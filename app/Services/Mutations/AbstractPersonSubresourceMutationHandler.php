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
 * 人物子資源 mutation handler 共用基底類
 *
 * 抽象出 repeated-form update 的共通邏輯：
 * - 驗證 composite PK
 * - 驗證 person_id 一致性
 * - 查原始 row
 * - 驗證 allowed update fields
 * - 判斷是否有有效變更
 * - direct 更新（含 transaction + operation + audit_log）
 * - proposal 寫 operation
 * - 統一 response shape
 */
abstract class AbstractPersonSubresourceMutationHandler extends AbstractMutationHandler {
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

    /** 資源名稱（回傳用），例如 'addresses' */
    abstract protected function resourceName(): string;

    /** 資料表名稱，例如 'BIOG_ADDR_DATA' */
    abstract protected function tableName(): string;

    /** 顯示名稱（proposal meta 用），例如 '地址' */
    abstract protected function displayName(): string;

    /** 可接受的 resource alias 列表（含主名），例如 ['addresses', 'address', 'biog_addr_data'] */
    abstract protected function resourceAliases(): array;

    /** 允許更新的欄位白名單 */
    abstract protected function allowedFields(): array;

    /** __key_columns（proposal meta 用），通常與 CompositePrimaryKey SCHEMAS 一致 */
    abstract protected function keyColumns(): array;

    /** person_id 在主鍵中的欄位名，預設 'c_personid' */
    protected function personIdColumn(): string {
        return 'c_personid';
    }

    // ── supports ─────────────────────────────────────────────

    public function supports(string $resource, string $mode, string $operation): bool {
        return in_array($resource, $this->resourceAliases(), true)
            && in_array($mode, ['direct', 'proposal'], true)
            && $operation === 'update';
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

        // 3. changes 不可為空
        if (empty($changes)) {
            return $this->errorResponse('changes 不可為空', 422, ['changes' => ['empty']]);
        }

        // 4. person_id 與 PK 一致性（子類可覆寫跳過此步驟）
        $pkValidationError = $this->validatePersonIdInPk($personId, $targetPk);
        if ($pkValidationError) {
            return $pkValidationError;
        }

        // 5. 查原始記錄
        $original = $this->findOriginalRow($targetPk);
        if (!$original) {
            return $this->errorResponse($this->tableName() . ' 記錄不存在', 404);
        }

        // 6. 驗證 person_id 與記錄一致性
        $rowValidationError = $this->validatePersonIdInRow($personId, $original);
        if ($rowValidationError) {
            return $rowValidationError;
        }

        // 7. 拒絕白名單外的欄位
        $disallowedFields = array_diff(array_keys($changes), $this->allowedFields());
        if (!empty($disallowedFields)) {
            return $this->errorResponse('包含不允許更新的欄位', 422, [
                'changes' => ['disallowed_fields: ' . implode(', ', $disallowedFields)],
            ]);
        }

        // 8. 過濾出可更新欄位
        $updateData = array_intersect_key($changes, array_flip($this->allowedFields()));
        if (empty($updateData)) {
            return $this->errorResponse('changes 至少需包含一個可更新欄位', 422, [
                'changes' => ['no_supported_fields'],
            ]);
        }

        // 9. 欄位值驗證（子類可覆寫）
        $validationErrors = $this->validateFields($updateData);
        if (!empty($validationErrors)) {
            return $this->errorResponse('參數校驗失敗', 422, $validationErrors);
        }

        // 10. 前處理（子類可覆寫，如 -999 → 0 轉換）
        $updateData = $this->preprocessUpdateData($updateData);

        // 11. 檢查是否有實際變更
        $originalArray = $this->auditLogService->normalizeRow($original);
        if (!$this->hasEffectiveChanges($originalArray, $updateData)) {
            return $this->errorResponse('未偵測到任何修改內容', 422, [
                'changes' => ['no_effective_changes'],
            ]);
        }

        $comment = is_string($meta['comment'] ?? null) ? trim($meta['comment']) : '';

        // 12. 分派到 direct / proposal
        if ($mode === 'proposal') {
            return $this->handleProposal($personId, $targetPk, $updateData, $originalArray, $comment);
        }

        return $this->handleDirect($personId, $targetPk, $updateData, $originalArray, $comment);
    }

    /**
     * 驗證 person_id 與 PK 中的 c_personid 一致性
     *
     * 當 PK 不含 c_personid（如 POSSESSION_DATA、POSTED_TO_OFFICE_DATA）時，
     * 子類可覆寫此方法回傳 null 以跳過此檢查。
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
     *
     * 預設檢查 $original->{personIdColumn()} 是否與 $personId 相同。
     */
    protected function validatePersonIdInRow(int $personId, object $original): ?JsonResponse {
        if ((string) ($original->{$this->personIdColumn()} ?? '') !== (string) $personId) {
            return $this->errorResponse('person_id 與目標記錄不一致', 422, ['person_id' => ['mismatch']]);
        }

        return null;
    }

    // ── Direct Update ────────────────────────────────────────

    protected function handleDirect(int $personId, array $targetPk, array $updateData, array $originalArray, string $comment): JsonResponse {
        // 對齊 legacy ToolsRepository::timestamp()（update 分支）：direct 更新一律於主列蓋更新者/
        // 更新時間，並移除建檔欄位以免覆寫原始建檔資訊。修正 v2 子資源直改未寫 c_modified_* 的稽核/
        // 對齊缺口（11/12 子資源原本不刷新；source 走 BiogSourceRepository 另已處理）。
        // 須在 transaction 閉包捕獲 $updateData 前注入；有效變更判斷已在 handle() 以未注入前的 changes 完成，
        // 故此注入不影響「無變更」攔截。
        $updateData['c_modified_by'] = Auth::user()->name ?? '';
        $updateData['c_modified_date'] = Carbon::now();
        unset($updateData['c_created_by'], $updateData['c_created_date']);

        $operationId = (string) Str::ulid();
        /** @var Operation|null $operation */
        $operation = null;
        $newArray = [];

        try {
            DB::transaction(function () use ($personId, $targetPk, $updateData, $originalArray, $comment, $operationId, &$operation, &$newArray) {
                // 更新資料表（子類 performUpdate() 可能在 PK 衝突時拋出 InvalidArgumentException）
                $this->performUpdate($targetPk, $updateData);

                // 讀回更新後的資料
                $updatedRow = $this->findUpdatedRow($targetPk, $updateData);
                $newArray = $this->auditLogService->normalizeRow($updatedRow);

                // 計算新 PK
                $newPk = $this->buildNewPk($targetPk, $updateData);
                $resourceId = CompositePrimaryKey::buildStoredResourceId($newPk);

                // 寫 operation
                $resourceData = array_merge($newArray, ['__operation_id' => $operationId]);
                if ($comment !== '') {
                    $resourceData['__note'] = $comment;
                }

                $operation = $this->operationRepository->store(
                    Auth::id(),
                    $personId,
                    Operation::TYPE_UPDATE,
                    $this->tableName(),
                    $resourceId,
                    $resourceData,
                    $originalArray
                );

                // 寫 audit_log
                $this->auditLogService->write(
                    $this->tableName(),
                    'UPDATE',
                    $newPk,
                    $originalArray,
                    $newArray,
                    'user',
                    (string) Auth::id(),
                    $operation ? (string) $operation->id : null
                );

                // 子類在同一交易內的後續處理（例如任官地址副表同步），保證原子性
                $this->afterDirectUpdate($personId, $targetPk, $updateData, $newArray, $operation);
            });
        } catch (\InvalidArgumentException $e) {
            // performUpdate() 明確拋出的 PK 衝突（如 AltnameMutationHandler、AddressMutationHandler）
            return $this->errorResponse($e->getMessage(), 409, ['target.pk' => ['conflict']]);
        } catch (\Illuminate\Database\QueryException $e) {
            // 資料庫唯一性約束衝突（未覆寫 performUpdate() 的 handler，PK 欄位更新時由 DB 層報錯）
            if ($this->isUniqueConstraintViolation($e)) {
                return $this->errorResponse('資料更新導致主鍵衝突', 409, ['target.pk' => ['conflict']]);
            }

            throw $e;
        }

        return response()->json([
            'ok' => true,
            'resource' => $this->resourceName(),
            'mode' => 'direct',
            'operation' => 'update',
            'result' => [
                'pk' => $this->buildNewPk($targetPk, $updateData),
                // updated_fields 只反映使用者實際變更，排除自動蓋的稽核欄（c_modified_*）；
                // 刷新後的稽核欄由 result.row 提供給前端。
                'updated_fields' => array_values(array_diff(array_keys($updateData), ['c_modified_by', 'c_modified_date'])),
                'operation_id' => $operation?->id,
                'row' => $newArray,
            ],
        ]);
    }

    // ── Proposal Update ──────────────────────────────────────

    protected function handleProposal(int $personId, array $targetPk, array $updateData, array $originalArray, string $comment): JsonResponse {
        $newPk = $this->buildNewPk($targetPk, $updateData);
        $resourceId = CompositePrimaryKey::buildStoredResourceId($newPk);

        // 檢查 1：若 PK 欄位有變動，確認新 PK 對應的記錄不已存在
        $pkChanged = false;
        foreach ($this->keyColumns() as $col) {
            if ((string) ($newPk[$col]) !== (string) ($targetPk[$col] ?? '')) {
                $pkChanged = true;

                break;
            }
        }
        if ($pkChanged && $this->findOriginalRow($newPk) !== null) {
            return $this->errorResponse('目標主鍵已存在，無法建立提案', 409, [
                'target.pk' => ['conflict'],
            ]);
        }

        // 檢查 2：相同 resource_id 不得已有待審核的更新提案
        if ($this->operationRepository->hasPendingUpdateProposal($this->tableName(), $resourceId)) {
            return $this->errorResponse('相同主鍵已有待審核提案', 409, [
                'target.pk' => ['pending_proposal_exists'],
            ]);
        }

        $proposalData = array_merge($originalArray, $updateData, [
            '__proposal_meta' => [
                'action' => 'update',
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

        // 子類可附加副表提案資料（例如任官地址 c_addr，核准時由 applyOfficeProposal 套用）
        $auxiliaryPayload = $this->proposalAuxiliaryPayload();
        if ($auxiliaryPayload !== []) {
            $proposalData['__proposal_aux'] = $auxiliaryPayload;
        }

        $operation = $this->operationRepository->store(
            Auth::id(),
            $personId,
            Operation::TYPE_PROPOSAL_UPDATE,
            $this->tableName(),
            $resourceId,
            $proposalData,
            $originalArray
        );

        return response()->json([
            'ok' => true,
            'resource' => $this->resourceName(),
            'mode' => 'proposal',
            'operation' => 'update',
            'result' => [
                'pk' => $newPk,
                'updated_fields' => array_keys($updateData),
                'status' => 'proposal_updated',
                'operation_id' => $operation?->id,
            ],
        ]);
    }

    // ── 可覆寫的 helper ──────────────────────────────────────

    /** 查詢原始記錄（子類可覆寫以處理特殊查詢邏輯） */
    protected function findOriginalRow(array $pk): ?object {
        $query = DB::table($this->tableName());
        foreach ($this->keyColumns() as $col) {
            $query->where($col, $pk[$col] ?? null);
        }

        return $query->first();
    }

    /** 執行資料表更新 */
    protected function performUpdate(array $targetPk, array $updateData): void {
        $query = DB::table($this->tableName());
        foreach ($this->keyColumns() as $col) {
            $query->where($col, $targetPk[$col] ?? null);
        }
        $query->update($updateData);
    }

    /** 讀回更新後的記錄（考慮 PK 可能因更新而改變） */
    protected function findUpdatedRow(array $targetPk, array $updateData): ?object {
        $newPk = $this->buildNewPk($targetPk, $updateData);
        $query = DB::table($this->tableName());
        foreach ($this->keyColumns() as $col) {
            $query->where($col, $newPk[$col] ?? null);
        }

        return $query->first();
    }

    /** 根據 targetPk 和 updateData 計算新 PK */
    protected function buildNewPk(array $targetPk, array $updateData): array {
        $newPk = [];
        foreach ($this->keyColumns() as $col) {
            $newPk[$col] = $updateData[$col] ?? $targetPk[$col];
        }

        return $newPk;
    }

    /**
     * direct 更新成功後、仍在同一交易內的後續處理鉤子（預設無動作）。
     * 子類可覆寫以在原子交易內同步副表（例如任官 PostingMutationHandler 同步 POSTED_TO_ADDR_DATA）。
     */
    protected function afterDirectUpdate(int $personId, array $targetPk, array $updateData, array $newArray, ?Operation $operation): void {
    }

    /**
     * proposal 更新時附加的副表提案資料（預設空）。
     * 子類可覆寫以把副表（例如任官地址 c_addr）寫入 __proposal_aux，核准時套用。
     */
    protected function proposalAuxiliaryPayload(): array {
        return [];
    }

    /** 欄位值驗證（預設無驗證，子類可覆寫） */
    protected function validateFields(array $data): array {
        return [];
    }

    /** 前處理更新資料（預設無處理，子類可覆寫） */
    protected function preprocessUpdateData(array $data): array {
        return $data;
    }

    /**
     * 將指定欄位中的 -999 轉換為 '0'
     *
     * CBDB 前端編輯頁面以 -999 表示「未選擇」或「不適用」，
     * 在存入資料庫前需統一轉換為 '0'。
     *
     * @param array $data 待處理的資料陣列
     * @param array $fields 需要轉換的欄位名稱列表
     */
    protected function normalizeSentinelValues(array $data, array $fields): array {
        foreach ($fields as $field) {
            if (array_key_exists($field, $data) && ($data[$field] === -999 || $data[$field] === '-999')) {
                $data[$field] = '0';
            }
        }

        return $data;
    }

    /** 判斷是否有實際有效變更 */
    protected function hasEffectiveChanges(array $originalArray, array $updateData): bool {
        foreach ($updateData as $field => $value) {
            $originalValue = $originalArray[$field] ?? null;
            // 統一以字串比對（處理 int/string 混合問題）
            if ($this->normalizeForComparison($originalValue) !== $this->normalizeForComparison($value)) {
                return true;
            }
        }

        return false;
    }

    /** 統一值的比對格式 */
    protected function normalizeForComparison($value): ?string {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    /**
     * 判斷 QueryException 是否為唯一性約束衝突
     *
     * 涵蓋 MySQL（error code 1062）與 SQLite（error code 19 = SQLITE_CONSTRAINT）。
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
