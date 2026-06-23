<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;

class AddressCreateHandler extends AbstractPersonSubresourceCreateHandler {
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
            'c_personid',
            'c_addr_id',
            'c_addr_type',
            'c_sequence',
            'c_firstyear',
            'c_lastyear',
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

    protected function preprocessCreateData(array $data): array {
        $data = $this->normalizeSentinelValues($data, ['c_addr_id', 'c_source']);

        // 對齊 legacy emptyToSentinel：地名／出處清空時正規化為 '0'（不可 NULL）。
        foreach (['c_addr_id', 'c_source'] as $f) {
            if (array_key_exists($f, $data) && ($data[$f] === null || $data[$f] === '')) {
                $data[$f] = '0';
            }
        }

        if (array_key_exists('c_fy_intercalary', $data)) {
            $data['c_fy_intercalary'] = (int) ($data['c_fy_intercalary'] ?? 0);
        }
        if (array_key_exists('c_ly_intercalary', $data)) {
            $data['c_ly_intercalary'] = (int) ($data['c_ly_intercalary'] ?? 0);
        }

        return $data;
    }
}
