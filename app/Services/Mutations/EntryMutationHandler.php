<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;

class EntryMutationHandler extends AbstractPersonSubresourceMutationHandler {
    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
    }

    protected function resourceName(): string {
        return 'entries';
    }

    protected function tableName(): string {
        return 'ENTRY_DATA';
    }

    protected function displayName(): string {
        return '入仕';
    }

    protected function resourceAliases(): array {
        return ['entries', 'entry', 'entry_data'];
    }

    protected function keyColumns(): array {
        return [
            'c_personid',
            'c_entry_code',
            'c_sequence',
            'c_kin_code',
            'c_assoc_code',
            'c_kin_id',
            'c_year',
            'c_assoc_id',
            'c_inst_code',
            'c_inst_name_code',
        ];
    }

    protected function allowedFields(): array {
        return [
            'c_entry_code',
            'c_sequence',
            'c_kin_code',
            'c_assoc_code',
            'c_kin_id',
            'c_year',
            'c_assoc_id',
            'c_inst_code',
            'c_inst_name_code',
            'c_entry_addr_id',
            'c_source',
            'c_pages',
            'c_notes',
            'c_supplement',
            'c_entry_nh_code',
            'c_entry_nh_year',
            'c_entry_range',
            'c_secondary_source_title',
        ];
    }

    protected function preprocessUpdateData(array $data): array {
        // -999 → 0 轉換
        $data = $this->normalizeSentinelValues($data, [
            'c_entry_code', 'c_entry_addr_id', 'c_kin_code',
            'c_assoc_code', 'c_inst_code', 'c_source',
        ]);

        return $data;
    }
}
