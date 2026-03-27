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

class NianHaoMutationHandler extends AbstractMutationHandler {
    /**
     * 允許更新的欄位白名單（僅拼音相關）
     */
    protected const ALLOWED_FIELDS = [
        'c_nianhao_pin',
    ];

    protected OperationRepository $operationRepository;
    protected AuditLogService $auditLogService;

    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        $this->operationRepository = $operationRepository;
        $this->auditLogService = $auditLogService;
    }

    public function supports(string $resource, string $mode, string $operation): bool {
        return in_array($resource, ['nianhao', 'nian_hao'], true)
            && in_array($mode, ['direct', 'proposal'], true)
            && $operation === 'update';
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $authorizationError = $mode === 'proposal' ? $this->authorizeProposal() : $this->authorizeDirect();
        if ($authorizationError) {
            return $authorizationError;
        }

        try {
            CompositePrimaryKey::validateOrFail($targetPk, 'NIAN_HAO');
        } catch (\Throwable $e) {
            return $this->errorResponse('主鍵格式不正確', 422, ['pk' => [$e->getMessage()]]);
        }

        if (empty($changes)) {
            return $this->errorResponse('changes 不可為空', 422, ['changes' => ['empty']]);
        }

        $nianhaoId = $targetPk['c_nianhao_id'] ?? null;

        $original = DB::table('NIAN_HAO')->where('c_nianhao_id', $nianhaoId)->first();
        if (!$original) {
            return $this->errorResponse('NIAN_HAO 記錄不存在', 404);
        }

        // 過濾出允許更新的欄位
        $updateData = array_intersect_key($changes, array_flip(self::ALLOWED_FIELDS));

        // 拒絕白名單外的欄位
        $disallowedFields = array_diff(array_keys($changes), self::ALLOWED_FIELDS);
        if (!empty($disallowedFields)) {
            return $this->errorResponse('包含不允許更新的欄位', 422, [
                'changes' => ['disallowed_fields: ' . implode(', ', $disallowedFields)],
            ]);
        }

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

        // 檢查是否有實際變更
        $originalArray = $this->auditLogService->normalizeRow($original);
        $hasEffectiveChange = false;
        foreach ($updateData as $field => $value) {
            if ((string) ($originalArray[$field] ?? '') !== (string) $value) {
                $hasEffectiveChange = true;

                break;
            }
        }
        if (!$hasEffectiveChange) {
            return $this->errorResponse('未偵測到任何修改內容', 422, [
                'changes' => ['no_effective_changes'],
            ]);
        }

        $pk = ['c_nianhao_id' => $nianhaoId];
        $resourceId = CompositePrimaryKey::buildStoredResourceId($pk);
        $comment = is_string($meta['comment'] ?? null) ? trim($meta['comment']) : '';

        if ($mode === 'proposal') {
            return $this->handleProposal($personId, $pk, $resourceId, $updateData, $originalArray, $comment);
        }

        return $this->handleDirect($personId, $nianhaoId, $pk, $resourceId, $updateData, $originalArray, $comment);
    }

    protected function handleDirect(int $personId, $nianhaoId, array $pk, string $resourceId, array $updateData, array $originalArray, string $comment): JsonResponse {
        $operationId = (string) Str::ulid();

        DB::table('NIAN_HAO')->where('c_nianhao_id', $nianhaoId)->update($updateData);

        $updatedRow = DB::table('NIAN_HAO')->where('c_nianhao_id', $nianhaoId)->first();
        $newArray = $this->auditLogService->normalizeRow($updatedRow);

        $resourceData = array_merge($newArray, ['__operation_id' => $operationId]);
        if ($comment !== '') {
            $resourceData['__note'] = $comment;
        }

        $operation = $this->operationRepository->store(
            Auth::id(),
            $personId,
            Operation::TYPE_UPDATE,
            'NIAN_HAO',
            $resourceId,
            $resourceData,
            $originalArray
        );

        $this->auditLogService->write(
            'NIAN_HAO',
            'UPDATE',
            $pk,
            $originalArray,
            $newArray,
            'user',
            (string) Auth::id(),
            $operationId
        );

        return response()->json([
            'ok' => true,
            'resource' => 'nianhao',
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
                'resource_type' => 'nianhao',
                'table' => 'NIAN_HAO',
                'display_name' => '年號',
                'submitted_by' => Auth::user()->name ?? Auth::id(),
                'submitted_by_id' => Auth::id(),
                'submitted_at' => Carbon::now()->format('Y-m-d H:i:s'),
                'comment' => $comment,
            ],
            '__review_status' => 'pending',
            '__key_columns' => ['c_nianhao_id'],
        ]);

        $operation = $this->operationRepository->store(
            Auth::id(),
            $personId,
            Operation::TYPE_PROPOSAL_UPDATE,
            'NIAN_HAO',
            $resourceId,
            $proposalData,
            $originalArray
        );

        return response()->json([
            'ok' => true,
            'resource' => 'nianhao',
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

    protected function validateFields(array $data): array {
        $errors = [];

        if (array_key_exists('c_nianhao_pin', $data)) {
            $value = $data['c_nianhao_pin'];
            if ($value !== null && !is_string($value)) {
                $errors['c_nianhao_pin'] = ['c_nianhao_pin 必須為字串或 null'];
            } elseif (is_string($value) && mb_strlen($value) > 255) {
                $errors['c_nianhao_pin'] = ['c_nianhao_pin 長度不可超過 255 字元'];
            }
        }

        return $errors;
    }
}
