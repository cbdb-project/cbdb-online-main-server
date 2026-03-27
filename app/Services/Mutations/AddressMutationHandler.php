<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;

class AddressMutationHandler extends AbstractPersonSubresourceMutationHandler {
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

    protected function allowedFields(): array {
        return [
            'c_addr_id',
            'c_addr_type',
            'c_firstyear',
            'c_lastyear',
            'c_sequence',
            'c_notes',
            'c_source',
            'c_pages',
            'c_fy_nh_code',
            'c_fy_nh_year',
            'c_fy_range',
            'c_fy_intercalary',
            'c_ly_nh_code',
            'c_ly_nh_year',
            'c_ly_range',
            'c_ly_intercalary',
        ];
    }

    protected function preprocessUpdateData(array $data): array {
        // -999 → 0 轉換
        if (array_key_exists('c_addr_id', $data) && $data['c_addr_id'] == -999) {
            $data['c_addr_id'] = '0';
        }
        if (array_key_exists('c_source', $data) && $data['c_source'] == -999) {
            $data['c_source'] = '0';
        }

        // 布林欄位轉 int
        if (array_key_exists('c_fy_intercalary', $data)) {
            $data['c_fy_intercalary'] = (int) ($data['c_fy_intercalary'] ?? 0);
        }
        if (array_key_exists('c_ly_intercalary', $data)) {
            $data['c_ly_intercalary'] = (int) ($data['c_ly_intercalary'] ?? 0);
        }

        return $data;
    }

    protected function performUpdate(array $targetPk, array $updateData): void {
        // 若 addr_type 或 sequence 有變動，檢查新 PK 是否衝突
        $newAddrType = $updateData['c_addr_type'] ?? $targetPk['c_addr_type'];
        $newSequence = $updateData['c_sequence'] ?? $targetPk['c_sequence'];
        $pkChanged = (string) $newAddrType !== (string) $targetPk['c_addr_type']
                  || (string) $newSequence !== (string) $targetPk['c_sequence'];

        if ($pkChanged) {
            $conflict = DB::table('BIOG_ADDR_DATA')->where([
                ['c_personid', '=', $targetPk['c_personid']],
                ['c_addr_id', '=', $updateData['c_addr_id'] ?? $targetPk['c_addr_id']],
                ['c_addr_type', '=', $newAddrType],
                ['c_sequence', '=', $newSequence],
            ])->exists();

            if ($conflict) {
                throw new \InvalidArgumentException(
                    '目標地址類別與遷徙次序的組合已存在，請使用不同的值。'
                );
            }
        }

        parent::performUpdate($targetPk, $updateData);
    }
}
