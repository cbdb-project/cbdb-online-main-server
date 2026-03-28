<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;

class AssociationMutationHandler extends AbstractPersonSubresourceMutationHandler {
    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
    }

    protected function resourceName(): string {
        return 'associations';
    }

    protected function tableName(): string {
        return 'ASSOC_DATA';
    }

    protected function displayName(): string {
        return '社會關係';
    }

    protected function resourceAliases(): array {
        return ['associations', 'association', 'assoc_data'];
    }

    protected function keyColumns(): array {
        return [
            'c_personid',
            'c_assoc_code',
            'c_assoc_id',
            'c_kin_code',
            'c_kin_id',
            'c_assoc_kin_code',
            'c_assoc_kin_id',
            'c_text_title',
            'c_assoc_first_year',
        ];
    }

    protected function allowedFields(): array {
        return [
            'c_assoc_code',
            'c_assoc_id',
            'c_kin_code',
            'c_kin_id',
            'c_assoc_kin_code',
            'c_assoc_kin_id',
            'c_text_title',
            'c_assoc_first_year',
            'c_assoc_last_year',
            'c_source',
            'c_pages',
            'c_notes',
            'c_supplement',
            'c_sequence',
            'c_assoc_count',
        ];
    }

    protected function preprocessUpdateData(array $data): array {
        $data = $this->normalizeSentinelValues($data, [
            'c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id',
            'c_assoc_kin_code', 'c_assoc_kin_id', 'c_source',
        ]);

        return $data;
    }
}
