<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;

class EventDeleteHandler extends AbstractPersonSubresourceDeleteHandler {
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
}
