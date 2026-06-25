<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;

class StatusMutationHandler extends AbstractPersonSubresourceMutationHandler {
    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
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
            'c_status_code',
            'c_sequence',
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

    protected function preprocessUpdateData(array $data): array {
        // -999 → 0 轉換
        $data = $this->normalizeSentinelValues($data, ['c_status_code', 'c_source']);
        // sentinel 完全幂等：c_source（legacy 哨兵 0=Unknown）的 null/'' 也→0（normalizeSentinelValues 只做 -999）。
        $data = $this->normalizeEmptyCodeFields($data, ['c_source']);

        return $data;
    }
}
