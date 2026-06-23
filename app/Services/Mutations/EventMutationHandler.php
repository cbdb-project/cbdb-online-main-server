<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;

class EventMutationHandler extends AbstractPersonSubresourceMutationHandler {
    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
    }

    protected function resourceName(): string {
        return 'events';
    }

    protected function tableName(): string {
        return 'EVENTS_DATA';
    }

    protected function displayName(): string {
        return '事件';
    }

    protected function resourceAliases(): array {
        return ['events', 'event', 'events_data'];
    }

    protected function keyColumns(): array {
        return ['c_personid', 'c_sequence', 'c_event_code'];
    }

    protected function allowedFields(): array {
        return [
            'c_event_code',
            'c_sequence',
            'c_source',
            'c_pages',
            'c_notes',
            'c_year',
            'c_month',
            'c_day',
            'c_day_ganzhi',
            'c_nh_code',
            'c_nh_year',
            'c_yr_range',
            'c_intercalary',
            'c_role',
            'c_event',
            // c_addr_id 不列入：legacy 將事件地址寫入 EVENTS_ADDR 副表，從不寫 EVENTS_DATA.c_addr_id 純量欄。
            // v2 為單表寫入，若允許 c_addr_id 會直接覆寫純量欄且不同步副表，與 legacy 分歧，故移除（fail closed）。
        ];
    }

    protected function preprocessUpdateData(array $data): array {
        $data = $this->normalizeSentinelValues($data, ['c_event_code', 'c_source']);

        if (array_key_exists('c_intercalary', $data)) {
            $data['c_intercalary'] = (int) ($data['c_intercalary'] ?? 0);
        }

        return $data;
    }
}
