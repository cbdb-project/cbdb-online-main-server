<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;

class TextMutationHandler extends AbstractPersonSubresourceMutationHandler {
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
            'c_textid',
            'c_role_id',
            'c_source',
            'c_pages',
            'c_notes',
            'c_supplement',
            'c_text_year',
        ];
    }

    protected function preprocessUpdateData(array $data): array {
        $data = $this->normalizeSentinelValues($data, ['c_textid', 'c_source']);

        // 對齊 legacy emptyToSentinel：著述／出處為碼表 FK（legacy 哨兵 0=Unknown），清空時正規化為 '0'，
        // 不可寫成 NULL（real DDL 雖 nullable，但 legacy 資料流空碼一律落 0）。
        foreach (['c_textid', 'c_source'] as $f) {
            if (array_key_exists($f, $data) && ($data[$f] === null || $data[$f] === '')) {
                $data[$f] = '0';
            }
        }

        return $data;
    }
}
