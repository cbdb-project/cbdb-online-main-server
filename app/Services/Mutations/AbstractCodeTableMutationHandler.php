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
 * Code／lookup 表受審計更新 handler 的共用基底。
 *
 * 整合交易、`audit_log`、`operations`、複合主鍵驗證、欄位白名單、變更偵測與 direct/proposal 兩模式；
 * 具體每表 handler 只需實作 tableName／resourceName／resourceAliases／displayName／keyColumns／allowedFields
 * 少量方法（見 CODE_TABLE_MUTATION_API_PLAN.md §4）。
 *
 * code 表為全域代碼、非人物子資源，故 `operations.c_personid` 一律設為 0（不受呼叫端 person_id 控制；
 * 呼叫端仍須依 MutationController 契約傳 person_id，通常為 0）。
 */
abstract class AbstractCodeTableMutationHandler extends AbstractMutationHandler {
    protected OperationRepository $operationRepository;
    protected AuditLogService $auditLogService;

    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        $this->operationRepository = $operationRepository;
        $this->auditLogService = $auditLogService;
    }

    /** 實際資料表名（＝ audit_log／operations 的 table 值、CompositePrimaryKey::SCHEMAS 鍵）。 */
    abstract protected function tableName(): string;

    /** 回應與提案 meta 使用的正規 resource 名（須包含於 resourceAliases()）。 */
    abstract protected function resourceName(): string;

    /** supports() 接受的 resource 別名清單。 */
    abstract protected function resourceAliases(): array;

    /** 提案 meta 顯示名（如「年號」）。 */
    abstract protected function displayName(): string;

    /** 主鍵欄位（單鍵或複合鍵，順序需與 CompositePrimaryKey::SCHEMAS 一致）。 */
    abstract protected function keyColumns(): array;

    /** 允許更新的欄位白名單。 */
    abstract protected function allowedFields(): array;

    public function supports(string $resource, string $mode, string $operation): bool {
        return in_array($resource, $this->resourceAliases(), true)
            && in_array($mode, ['direct', 'proposal'], true)
            && $operation === 'update';
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $authorizationError = $mode === 'proposal' ? $this->authorizeProposal() : $this->authorizeDirect();
        if ($authorizationError) {
            return $authorizationError;
        }

        $table = $this->tableName();

        try {
            CompositePrimaryKey::validateOrFail($targetPk, $table);
        } catch (\Throwable $e) {
            return $this->errorResponse('主鍵格式不正確', 422, ['pk' => [$e->getMessage()]]);
        }

        // 防呆：keyColumns() 必須與 CompositePrimaryKey::SCHEMAS 登錄的主鍵完全一致（順序＋欄名）。
        // validateOrFail 依 SCHEMAS 驗證「全鍵存在且非 null」；若子類 keyColumns() 為 SCHEMAS 的真子集，
        // 驗證仍會通過、但 whereByPk 只用部分鍵→UPDATE 可能命中多列。此處硬擋子類設定錯誤（500）。
        $schemaKeys = CompositePrimaryKey::getSchema($table);
        if ($schemaKeys === null || $this->keyColumns() !== $schemaKeys) {
            return $this->errorResponse($table . ' 主鍵宣告與登錄不一致（handler 設定錯誤）', 500, ['pk' => ['schema_mismatch']]);
        }

        if (empty($changes)) {
            return $this->errorResponse('changes 不可為空', 422, ['changes' => ['empty']]);
        }

        // 依 keyColumns 取出主鍵（已驗證＝SCHEMAS、全鍵存在且非 null）
        $pk = [];
        foreach ($this->keyColumns() as $col) {
            $pk[$col] = $targetPk[$col];
        }

        $original = $this->findByPk($pk);
        if (!$original) {
            return $this->errorResponse($table . ' 記錄不存在', 404);
        }

        $allowed = $this->allowedFields();

        // 拒絕白名單外的欄位
        $disallowedFields = array_diff(array_keys($changes), $allowed);
        if (!empty($disallowedFields)) {
            return $this->errorResponse('包含不允許更新的欄位', 422, [
                'changes' => ['disallowed_fields: ' . implode(', ', $disallowedFields)],
            ]);
        }

        $updateData = array_intersect_key($changes, array_flip($allowed));
        if (empty($updateData)) {
            return $this->errorResponse('changes 至少需包含一個可更新欄位', 422, [
                'changes' => ['no_supported_fields'],
            ]);
        }

        // 驗證欄位值
        $validationErrors = $this->validateFields($updateData);
        if (!empty($validationErrors)) {
            return $this->errorResponse('參數校驗失敗', 422, $validationErrors);
        }

        // 保存前處理（如 §D-6 Tier 1 拼音 v→ü 歸一化）；於變更偵測前，確保冪等（已是 ü→不觸發更新）。
        $updateData = $this->preprocessUpdateData($updateData);

        // 檢查是否有實際變更
        $originalArray = $this->auditLogService->normalizeRow($original);
        $hasEffectiveChange = false;
        foreach ($updateData as $field => $value) {
            if (($originalArray[$field] ?? null) !== $value) {
                $hasEffectiveChange = true;

                break;
            }
        }
        if (!$hasEffectiveChange) {
            return $this->errorResponse('未偵測到任何修改內容', 422, [
                'changes' => ['no_effective_changes'],
            ]);
        }

        $resourceId = CompositePrimaryKey::buildStoredResourceId($pk);
        $comment = is_string($meta['comment'] ?? null) ? trim($meta['comment']) : '';

        // code 表為全域代碼，c_personid 一律設為 0，不受呼叫端控制
        $operationPersonId = 0;

        if ($mode === 'proposal') {
            return $this->handleProposal($operationPersonId, $pk, $resourceId, $updateData, $originalArray, $comment);
        }

        return $this->handleDirect($operationPersonId, $pk, $resourceId, $updateData, $originalArray, $comment);
    }

    protected function handleDirect(int $personId, array $pk, string $resourceId, array $updateData, array $originalArray, string $comment): JsonResponse {
        $table = $this->tableName();
        $operationId = (string) Str::ulid();

        /** @var \App\Models\Operation|null $operation */
        $operation = null;
        $newArray = [];

        DB::transaction(function () use ($table, $pk, $resourceId, $updateData, $originalArray, $comment, $operationId, $personId, &$operation, &$newArray) {
            $this->whereByPk(DB::table($table), $pk)->update($updateData);

            $updatedRow = $this->findByPk($pk);
            $newArray = $this->auditLogService->normalizeRow($updatedRow);

            $resourceData = array_merge($newArray, ['__operation_id' => $operationId]);
            if ($comment !== '') {
                $resourceData['__note'] = $comment;
            }

            $operation = $this->operationRepository->store(
                Auth::id(),
                $personId,
                Operation::TYPE_UPDATE,
                $table,
                $resourceId,
                $resourceData,
                $originalArray
            );

            $this->auditLogService->write(
                $table,
                'UPDATE',
                $pk,
                $originalArray,
                $newArray,
                'user',
                (string) Auth::id(),
                $operationId
            );
        });

        return response()->json([
            'ok' => true,
            'resource' => $this->resourceName(),
            'mode' => 'direct',
            'operation' => 'update',
            'result' => [
                'pk' => $pk,
                'updated_fields' => array_keys($updateData),
                'operation_id' => $operation?->id,
                'row' => $newArray,
            ],
        ]);
    }

    protected function handleProposal(int $personId, array $pk, string $resourceId, array $updateData, array $originalArray, string $comment): JsonResponse {
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
                'pk' => $pk,
                'updated_fields' => array_keys($updateData),
                'status' => 'proposal_updated',
                'operation_id' => $operation?->id,
            ],
        ]);
    }

    /**
     * 欄位值校驗：預設對每個白名單欄做「字串或 null、長度 ≤ 255」檢查。
     * 需要更嚴格規則的表可覆寫。
     */
    protected function validateFields(array $data): array {
        $errors = [];
        foreach ($data as $field => $value) {
            if ($value !== null && !is_string($value)) {
                $errors[$field] = [$field . ' 必須為字串或 null'];
            } elseif (is_string($value) && mb_strlen($value) > 255) {
                $errors[$field] = [$field . ' 長度不可超過 255 字元'];
            }
        }

        return $errors;
    }

    /**
     * 寫入前對 updateData 的最後加工（預設為 no-op）。
     * 子類可覆寫以套用 §D-6 保存時拼音 v→ü 歸一化等。於變更偵測前呼叫。
     *
     * @param array<string,mixed> $updateData
     * @return array<string,mixed>
     */
    protected function preprocessUpdateData(array $updateData): array {
        return $updateData;
    }

    /** 以主鍵定位單列。 */
    protected function findByPk(array $pk): ?object {
        return $this->whereByPk(DB::table($this->tableName()), $pk)->first();
    }

    /** 把主鍵條件套到 query builder。 */
    protected function whereByPk(\Illuminate\Database\Query\Builder $query, array $pk): \Illuminate\Database\Query\Builder {
        foreach ($pk as $col => $value) {
            $query->where($col, $value);
        }

        return $query;
    }
}
