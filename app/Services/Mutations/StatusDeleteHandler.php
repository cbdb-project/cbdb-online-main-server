<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;

class StatusDeleteHandler extends AbstractPersonSubresourceDeleteHandler {
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
}
