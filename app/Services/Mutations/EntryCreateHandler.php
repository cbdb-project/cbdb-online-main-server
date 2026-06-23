<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;

class EntryCreateHandler extends AbstractPersonSubresourceCreateHandler {
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
            'c_entry_addr_id',
            'c_source',
            'c_pages',
            'c_notes',
            // 年號代碼欄：ENTRY_DATA 真實欄為 c_entry_nh_id（2026_01_22 rename 將 c_nianhao_id 改為此名）。
            'c_entry_nh_id',
            'c_entry_nh_year',
            'c_entry_range',
            // Task 27 補回舊表單可錄入欄（皆 ENTRY_DATA 真實欄；c_parental_status_code 為真實欄名）。
            'c_exam_rank',
            'c_attempt_count',
            'c_exam_field',
            'c_parental_status_code',
            'c_age',
            'c_posting_notes',
        ];
    }

    protected function preprocessCreateData(array $data): array {
        return $this->normalizeSentinelValues($data, [
            'c_entry_code', 'c_entry_addr_id', 'c_kin_code',
            'c_assoc_code', 'c_inst_code', 'c_source',
        ]);
    }
}
