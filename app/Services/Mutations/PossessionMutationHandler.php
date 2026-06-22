<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;

class PossessionMutationHandler extends AbstractPersonSubresourceMutationHandler {
    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
    }

    protected function resourceName(): string {
        return 'possessions';
    }

    protected function tableName(): string {
        return 'POSSESSION_DATA';
    }

    protected function displayName(): string {
        return '財產';
    }

    protected function resourceAliases(): array {
        return ['possessions', 'possession', 'possession_data'];
    }

    protected function keyColumns(): array {
        return ['c_possession_record_id'];
    }

    /** PK 不含 c_personid，跳過 PK 中的 person_id 檢查 */
    protected function validatePersonIdInPk(int $personId, array $targetPk): ?JsonResponse {
        return null;
    }

    /** 透過 row 的 c_personid 欄位驗證 person_id 一致性 */
    protected function validatePersonIdInRow(int $personId, object $original): ?JsonResponse {
        if ((string) ($original->c_personid ?? '') !== (string) $personId) {
            return $this->errorResponse('person_id 與目標記錄不一致', 422, ['person_id' => ['mismatch']]);
        }

        return null;
    }

    protected function allowedFields(): array {
        // 僅列 POSSESSION_DATA 真實欄位（對照 schema migration）。
        // 早期版本含 c_supplement / c_measure_value / c_firstyear / c_lastyear 等不存在欄位（假欄），已移除。
        return [
            'c_sequence',
            'c_possession_act_code',
            'c_possession_desc',
            'c_possession_desc_chn',
            'c_quantity',
            'c_measure_code',
            'c_possession_yr',
            'c_possession_nh_code',
            'c_possession_nh_yr',
            'c_possession_yr_range',
            'c_source',
            'c_pages',
            'c_notes',
        ];
    }

    protected function preprocessUpdateData(array $data): array {
        $data = $this->normalizeSentinelValues($data, [
            'c_source', 'c_measure_code', 'c_possession_act_code',
        ]);

        return $data;
    }
}
