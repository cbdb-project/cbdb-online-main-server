<?php

namespace App\Services\Mutations;

use App\Models\Operation;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Services\AuditLogService;

class KinshipDeleteHandler extends AbstractPersonSubresourceDeleteHandler {
    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
    }

    /**
     * direct 正向列刪除成功後，於同交易內刪除反向鏡像列（重用 BiogMainRepository::syncKinMirrorOnDelete）。
     * $originalArray 為被刪除的正向列；共用方法依其 c_kin_code 配對重新定位反向列再刪除＋補 audit。
     */
    protected function afterDirectDelete(int $personId, array $targetPk, array $originalArray, ?Operation $operation, bool $force = false): void {
        // #81 §6：對面命中多筆反向列且 $force=false → 共用方法拋 MirrorDeleteMultipleException（base 轉 409）；$force=true 一併刪除。
        app(BiogMainRepository::class)->syncKinMirrorOnDelete($originalArray, $operation, $this->auditLogService, $force);
    }

    protected function resourceName(): string {
        return 'kinship';
    }

    protected function tableName(): string {
        return 'KIN_DATA';
    }

    protected function displayName(): string {
        return '親屬關係';
    }

    protected function resourceAliases(): array {
        return ['kinship', 'kin', 'kin_data'];
    }

    protected function keyColumns(): array {
        return ['c_personid', 'c_kin_id', 'c_kin_code'];
    }
}
