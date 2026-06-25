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
            'c_natal',
            'c_fy_nh_code',
            'c_fy_nh_year',
            'c_fy_range',
            'c_fy_intercalary',
            'c_fy_month',
            'c_fy_day',
            'c_fy_day_gz',
            'c_ly_nh_code',
            'c_ly_nh_year',
            'c_ly_range',
            'c_ly_intercalary',
            'c_ly_month',
            'c_ly_day',
            'c_ly_day_gz',
        ];
    }

    protected function preprocessUpdateData(array $data): array {
        // -999 → 0 轉換
        $data = $this->normalizeSentinelValues($data, ['c_addr_id', 'c_source']);

        // 對齊 legacy emptyToSentinel：地名／出處為碼表 FK（legacy 哨兵 0=Unknown），清空時正規化為 '0'，
        // 不可寫成 NULL（real DDL 雖 nullable，但 legacy 資料流空碼一律落 0）。
        foreach (['c_addr_id', 'c_source'] as $f) {
            if (array_key_exists($f, $data) && ($data[$f] === null || $data[$f] === '')) {
                $data[$f] = '0';
            }
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
        // 若任一 PK 欄位有變動，檢查新 PK 是否衝突
        $newAddrId = $updateData['c_addr_id'] ?? $targetPk['c_addr_id'];
        $newAddrType = $updateData['c_addr_type'] ?? $targetPk['c_addr_type'];
        $newSequence = $updateData['c_sequence'] ?? $targetPk['c_sequence'];
        $pkChanged = (string) $newAddrId !== (string) $targetPk['c_addr_id']
                  || (string) $newAddrType !== (string) $targetPk['c_addr_type']
                  || (string) $newSequence !== (string) $targetPk['c_sequence'];

        if ($pkChanged) {
            $conflict = DB::table('BIOG_ADDR_DATA')->where([
                ['c_personid', '=', $targetPk['c_personid']],
                ['c_addr_id', '=', $newAddrId],
                ['c_addr_type', '=', $newAddrType],
                ['c_sequence', '=', $newSequence],
            ])->exists();

            if ($conflict) {
                throw new \InvalidArgumentException(
                    '目標地址、地址類別與遷徙次序的組合已存在，請使用不同的值。'
                );
            }
        }

        parent::performUpdate($targetPk, $updateData);
    }
}
