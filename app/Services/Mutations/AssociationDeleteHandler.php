<?php

namespace App\Services\Mutations;

use App\Models\Operation;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Services\AuditLogService;

class AssociationDeleteHandler extends AbstractPersonSubresourceDeleteHandler {
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

    /**
     * direct 刪除成功後，於同交易內同步刪除反向鏡像列（重用 BiogMainRepository::syncAssocMirrorOnDelete）。
     * assoc 反向定位以自身配對碼 + 完整親屬維度精確匹配，且回退層唯一才刪、多筆即跳過（既有安全行為），
     * 不適用 #81 §6 的 legitReverses 廣集多筆裁決，故 $force 在此不使用。
     */
    protected function afterDirectDelete(int $personId, array $targetPk, array $originalArray, ?Operation $operation, bool $force = false): void {
        app(BiogMainRepository::class)->syncAssocMirrorOnDelete($originalArray, $operation, $this->auditLogService);
    }
}
