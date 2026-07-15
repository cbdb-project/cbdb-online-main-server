<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;

/**
 * 合併人物記錄（MERGED_PERSON_DATA）的 delete handler，供回滾/清理錯誤合併映射。
 * PK = (c_personid=survivor, c_merged_from_personid=已刪 id)；person_id 對應 survivor。
 */
class MergedPersonDeleteHandler extends AbstractPersonSubresourceDeleteHandler {
    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
    }

    protected function resourceName(): string {
        return 'merged-person';
    }

    protected function tableName(): string {
        return 'MERGED_PERSON_DATA';
    }

    protected function displayName(): string {
        return '合併人物記錄';
    }

    protected function resourceAliases(): array {
        return ['merged-person', 'merged_person', 'merged_person_data', 'mergedperson'];
    }

    protected function keyColumns(): array {
        return ['c_personid', 'c_merged_from_personid'];
    }
}
