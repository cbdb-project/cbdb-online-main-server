<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;

class SocialInstitutionDeleteHandler extends AbstractPersonSubresourceDeleteHandler {
    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
    }

    protected function resourceName(): string {
        return 'social_institutions';
    }

    protected function tableName(): string {
        return 'BIOG_INST_DATA';
    }

    protected function displayName(): string {
        return '社會機構';
    }

    protected function resourceAliases(): array {
        return ['social_institutions', 'social_institution', 'socialinst', 'biog_inst_data'];
    }

    protected function keyColumns(): array {
        return ['c_personid', 'c_inst_code', 'c_inst_name_code', 'c_bi_role_code'];
    }
}
