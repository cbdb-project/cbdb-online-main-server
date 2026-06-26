<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;

class SourceDeleteHandler extends AbstractPersonSubresourceDeleteHandler {
    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
    }

    protected function resourceName(): string {
        return 'sources';
    }

    protected function tableName(): string {
        return 'BIOG_SOURCE_DATA';
    }

    protected function displayName(): string {
        return '出處';
    }

    protected function resourceAliases(): array {
        return ['sources', 'source', 'biog_source_data'];
    }

    protected function keyColumns(): array {
        return ['c_personid', 'c_textid', 'c_pages'];
    }

    /** c_pages 為 varchar、可空（與 BasicInformationSourcesController::destroyQuery 一致） */
    protected function optionalKeyFields(): array {
        return ['c_pages'];
    }

    /**
     * c_pages 與 BiogSourceRepository::normalizePk 對齊：null/缺省 canonical 為 ''（空字串）。
     * create/update 一律以 '' 儲存空 c_pages，故 delete 也須以 '' 比對，否則省略 c_pages 時
     * 走 whereNull 會漏刪 create 剛建立的記錄（round-trip 破口）。
     */
    protected function normalizeTargetPk(array $pk): array {
        $pk['c_pages'] = (string) ($pk['c_pages'] ?? '');

        return $pk;
    }
}
