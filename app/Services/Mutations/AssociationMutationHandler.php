<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Support\CompositePrimaryKey;

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
            // ⚠️ 移除幻影 c_supplement（ASSOC_DATA 無此欄）。
            'c_sequence',
            'c_assoc_count',
            // Task 27：補回舊表單可錄入欄位（皆 ASSOC_DATA 真實欄）。
            'c_topic_code',
            'c_occasion_code',
            'c_tertiary_personid',
            'c_tertiary_type_notes',
            'c_assoc_claimer_id',
            'c_addr_id',
            'c_inst_code',
            'c_inst_name_code',
        ];
    }

    protected function preprocessUpdateData(array $data): array {
        $data = $this->normalizeSentinelValues($data, [
            'c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id',
            'c_assoc_kin_code', 'c_assoc_kin_id', 'c_source',
        ]);

        // 與 AssociationCreateHandler 及 legacy BasicInformationAssocController 對齊：
        // c_text_title / c_assoc_first_year 為 NOT NULL 複合主鍵欄位，空值須轉哨兵，
        // 否則編輯一筆「未知出處/未知年份」記錄（'[n/a]' / '-9999'）時，前端送空（middleware 轉 null）
        // 會把 PK 寫成 '' / 0，造成主鍵漂移。emptyToSentinel 同時涵蓋 null / '' / -999。
        if (array_key_exists('c_text_title', $data)) {
            $data['c_text_title'] = CompositePrimaryKey::emptyToSentinel($data['c_text_title'], '[n/a]');
        }
        if (array_key_exists('c_assoc_first_year', $data)) {
            $data['c_assoc_first_year'] = CompositePrimaryKey::emptyToSentinel($data['c_assoc_first_year'], '-9999');
        }

        return $data;
    }
}
