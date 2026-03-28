<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;

class PostingMutationHandler extends AbstractPersonSubresourceMutationHandler {
    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
    }

    protected function resourceName(): string {
        return 'postings';
    }

    protected function tableName(): string {
        return 'POSTED_TO_OFFICE_DATA';
    }

    protected function displayName(): string {
        return '任官';
    }

    protected function resourceAliases(): array {
        return ['postings', 'posting', 'offices', 'posted_to_office_data'];
    }

    protected function keyColumns(): array {
        return ['c_office_id', 'c_posting_id'];
    }

    /** 任官表的 person_id 不在主鍵中，需另外查詢 */
    protected function personIdColumn(): string {
        return 'c_personid';
    }

    /**
     * 覆寫 PK 驗證邏輯：POSTED_TO_OFFICE_DATA 的 PK 不含 c_personid，
     * 但 API 仍需 person_id 做一致性檢查，因此跳過 PK 中的 person_id 比對。
     */
    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): \Illuminate\Http\JsonResponse {
        // 1. 授權
        $authorizationError = $mode === 'proposal' ? $this->authorizeProposal() : $this->authorizeDirect();
        if ($authorizationError) {
            return $authorizationError;
        }

        // 2. 驗證 PK 格式
        try {
            \App\Support\CompositePrimaryKey::validateOrFail($targetPk, $this->tableName());
        } catch (\Throwable $e) {
            return $this->errorResponse('主鍵格式不正確', 422, ['pk' => [$e->getMessage()]]);
        }

        // 3. changes 不可為空
        if (empty($changes)) {
            return $this->errorResponse('changes 不可為空', 422, ['changes' => ['empty']]);
        }

        // 4. 查原始記錄
        $original = $this->findOriginalRow($targetPk);
        if (!$original) {
            return $this->errorResponse($this->tableName() . ' 記錄不存在', 404);
        }

        // 5. 驗證 person_id 與記錄一致性（c_personid 在 row 中但不在 PK 定義中）
        if ((string) ($original->c_personid ?? '') !== (string) $personId) {
            return $this->errorResponse('person_id 與目標記錄不一致', 422, ['person_id' => ['mismatch']]);
        }

        // 6. 拒絕白名單外的欄位
        $disallowedFields = array_diff(array_keys($changes), $this->allowedFields());
        if (!empty($disallowedFields)) {
            return $this->errorResponse('包含不允許更新的欄位', 422, [
                'changes' => ['disallowed_fields: ' . implode(', ', $disallowedFields)],
            ]);
        }

        // 7. 過濾出可更新欄位
        $updateData = array_intersect_key($changes, array_flip($this->allowedFields()));
        if (empty($updateData)) {
            return $this->errorResponse('changes 至少需包含一個可更新欄位', 422, [
                'changes' => ['no_supported_fields'],
            ]);
        }

        // 8. 欄位值驗證
        $validationErrors = $this->validateFields($updateData);
        if (!empty($validationErrors)) {
            return $this->errorResponse('參數校驗失敗', 422, $validationErrors);
        }

        // 9. 前處理
        $updateData = $this->preprocessUpdateData($updateData);

        // 10. 檢查是否有實際變更
        $originalArray = $this->auditLogService->normalizeRow($original);
        if (!$this->hasEffectiveChanges($originalArray, $updateData)) {
            return $this->errorResponse('未偵測到任何修改內容', 422, [
                'changes' => ['no_effective_changes'],
            ]);
        }

        $comment = is_string($meta['comment'] ?? null) ? trim($meta['comment']) : '';

        if ($mode === 'proposal') {
            return $this->handleProposal($personId, $targetPk, $updateData, $originalArray, $comment);
        }

        return $this->handleDirect($personId, $targetPk, $updateData, $originalArray, $comment);
    }

    protected function allowedFields(): array {
        return [
            'c_office_id',
            'c_sequence',
            'c_source',
            'c_pages',
            'c_notes',
            'c_supplement',
            'c_firstyear',
            'c_fy_nh_code',
            'c_fy_nh_year',
            'c_fy_range',
            'c_fy_intercalary',
            'c_lastyear',
            'c_ly_nh_code',
            'c_ly_nh_year',
            'c_ly_range',
            'c_ly_intercalary',
            'c_appt_type_code',
            'c_assume_office_code',
        ];
    }

    protected function preprocessUpdateData(array $data): array {
        $data = $this->normalizeSentinelValues($data, ['c_office_id', 'c_source']);

        if (array_key_exists('c_fy_intercalary', $data)) {
            $data['c_fy_intercalary'] = (int) ($data['c_fy_intercalary'] ?? 0);
        }
        if (array_key_exists('c_ly_intercalary', $data)) {
            $data['c_ly_intercalary'] = (int) ($data['c_ly_intercalary'] ?? 0);
        }

        return $data;
    }
}
