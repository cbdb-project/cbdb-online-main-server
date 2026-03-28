<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;

class AddressDeleteHandler extends AbstractPersonSubresourceDeleteHandler {
    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
    }

    protected function resourceName(): string {
        return 'addresses';
    }

    protected function tableName(): string {
        return 'BIOG_ADDR_DATA';
    }

    protected function displayName(): string {
        return '地址';
    }

    protected function resourceAliases(): array {
        return ['addresses', 'address', 'biog_addr_data'];
    }

    protected function keyColumns(): array {
        return ['c_personid', 'c_addr_id', 'c_addr_type', 'c_sequence'];
    }
}
