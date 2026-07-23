<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;

class StatusCreateHandler extends AbstractPersonSubresourceCreateHandler {
    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
    }

    /** 社會區分 create 經 AI 智能識別（category='status'）時，回寫 ai_fill_logs（見 RecordsAiFillSubmission）。 */
    protected function aiFillCategory(): ?string {
        return 'status';
    }

    protected function resourceName(): string {
        return 'statuses';
    }

    protected function tableName(): string {
        return 'STATUS_DATA';
    }

    protected function displayName(): string {
        return '社會區分';
    }

    protected function resourceAliases(): array {
        return ['statuses', 'status', 'status_data'];
    }

    protected function keyColumns(): array {
        return ['c_personid', 'c_sequence', 'c_status_code'];
    }

    protected function allowedFields(): array {
        return [
            'c_personid',
            'c_sequence',
            'c_status_code',
            'c_source',
            'c_pages',
            'c_notes',
            'c_supplement',
            'c_firstyear',
            'c_fy_nh_code',
            'c_fy_nh_year',
            'c_fy_range',
            'c_lastyear',
            'c_ly_nh_code',
            'c_ly_nh_year',
            'c_ly_range',
        ];
    }

    protected function preprocessCreateData(array $data): array {
        $data = $this->normalizeSentinelValues($data, ['c_status_code', 'c_source']);

        // #71：非 PK 碼欄 c_source 完全幂等（null/''/-999→0），對齊已修的 StatusMutationHandler。
        return $this->normalizeEmptyCodeFields($data, ['c_source']);
    }
}
