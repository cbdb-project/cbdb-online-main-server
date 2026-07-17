<?php

/*
 * 複合實體聚合註冊表（docs/ENTITY_AGGREGATE_ARCHITECTURE.md §6.5）。
 *
 * 每個「上層實體」（聚合根 Service 獨佔下層表寫入的複合實體）在此聲明一項，
 * 作為橫向接線的單一真源，驅動：
 *  - codes UI 封寫：closed_code_tables 內的表在 CodesController 一律唯讀
 *    （寫入路徑由實體頁獨佔；讀取／匯出開放）。回退＝移除該表或整個實體項。
 *  - 側欄節點：Navigation 依 nav 設定把對應 codes 子表節點改指實體聚合頁。
 *
 * 領域邏輯（派生、去重、護欄、校驗）不進 config——留在各實體的
 * EntityAggregateService 實作內；此處只放接線用的宣告性資料。
 */

return [
    'entities' => [
        [
            'resource' => 'office',
            'service' => \App\Services\Import\OfficeImportService::class,
            'pk' => 'c_office_id',
            // 聚合認領的下層表（文件化用；封寫範圍以 closed_code_tables 為準——
            // 僅列 codes UI 實際可瀏覽的表）。
            'tables' => ['OFFICE_CODES', 'OFFICE_CODE_TYPE_REL'],
            'closed_code_tables' => ['OFFICE_CODES'],
            'nav' => [
                'key' => 'office-codes',
                'label' => 'codes.office_codes',
                'icon' => 'fas fa-id-badge',
                'route' => 'app.office.index',
                'table' => 'OFFICE_CODES',
                'pattern' => 'app.office.*',
            ],
        ],
        [
            'resource' => 'social-institution',
            'service' => \App\Services\Import\SocialInstituteImportService::class,
            'pk' => 'c_inst_code',
            'tables' => ['SOCIAL_INSTITUTION_NAME_CODES', 'SOCIAL_INSTITUTION_CODES', 'SOCIAL_INSTITUTION_ADDR'],
            'closed_code_tables' => ['SOCIAL_INSTITUTION_CODES', 'SOCIAL_INSTITUTION_NAME_CODES', 'SOCIAL_INSTITUTION_ADDR'],
            'nav' => [
                'key' => 'social-institution-codes',
                'label' => 'codes.social_institution_codes',
                'icon' => 'fas fa-university',
                'route' => 'app.social-institution.index',
                'table' => 'SOCIAL_INSTITUTION_CODES',
                'pattern' => 'app.social-institution.*',
            ],
        ],
    ],
];
