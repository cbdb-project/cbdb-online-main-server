<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;

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
            'c_appt_code',
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

        // c_appt_code 欄位為 NOT NULL；null／空字串／-999 統一回填 0（APPOINTMENT_CODES 的「未詳」哨兵）
        if (array_key_exists('c_appt_code', $data)) {
            $value = $data['c_appt_code'];
            if ($value === null || $value === '' || $value == -999) {
                $data['c_appt_code'] = 0;
            }
        }

        return $data;
    }
}
