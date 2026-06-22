<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;

class TextCreateHandler extends AbstractPersonSubresourceCreateHandler {
    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
    }

    protected function resourceName(): string {
        return 'texts';
    }

    protected function tableName(): string {
        return 'BIOG_TEXT_DATA';
    }

    protected function displayName(): string {
        return '著述';
    }

    protected function resourceAliases(): array {
        return ['texts', 'text', 'biog_text_data', 'text_data'];
    }

    protected function keyColumns(): array {
        return ['c_personid', 'c_textid', 'c_role_id'];
    }

    protected function allowedFields(): array {
        return [
            'c_personid',
            'c_textid',
            'c_role_id',
            'c_source',
            'c_pages',
            'c_notes',
            'c_supplement',
            'c_text_year',
        ];
    }

    protected function preprocessCreateData(array $data): array {
        return $this->normalizeSentinelValues($data, ['c_textid', 'c_source']);
    }
}
