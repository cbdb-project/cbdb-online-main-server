<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;

class EntryDeleteHandler extends AbstractPersonSubresourceDeleteHandler {
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
}
