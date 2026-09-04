<?php

/*
 * 複合實體聚合註冊表（docs/ENTITY_AGGREGATE_ARCHITECTURE.md §6.5）。
 *
 * 每個「上層實體」（聚合根 Service 獨佔下層表寫入的複合實體）在此聲明一項，
 * 作為橫向接線的單一真源，驅動：
 *  - codes UI 封寫：closed_code_tables 內的表在 CodesController 一律唯讀
 *    （寫入路徑由實體頁獨佔；讀取／匯出開放）。回退＝移除該表或整個實體項。
 *  - 封寫表的編輯連結改指：operations 等處指向被封寫下層表的連結，改導向該實體的
 *    edit_route（App\Support\EntityAggregateRegistry）。封寫與連結解析同源，不會再出現
 *    「點進去只吃到唯讀警告」的死連結。
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
            // 通用 mutation handler 依此分派（EntityAggregate*Handler → definition）。
            'definition' => \App\Services\Mutations\EntityAggregate\OfficeAggregateDefinition::class,
            'pk' => 'c_office_id',
            // 實體聚合編輯頁路由：封寫下層表後，operations 等處的「查閱」連結改指這裡
            // （EntityAggregateRegistry::editUrl()）。不由 resource 推導——text-entity 的
            // resource 與路由前綴刻意不同名。
            'edit_route' => 'app.office.edit',
            // 該實體的編輯表單需要什麼能力（'propose'＝canPropose、'write'＝canWriteDirectly）。
            // 必須與該實體 Controller 的守衛一致，否則連結解析會發出一條必然 403 的連結。
            // office 是 OfficeEntityController::ensureCanReachForm()＝canPropose；
            // social-institution／text-entity 是 ensureWrite()＝canWriteDirectly。
            'form_capability' => 'propose',
            // 聚合認領的下層表（文件化用；封寫範圍以 closed_code_tables 為準——
            // 僅列 codes UI 實際可瀏覽的表）。
            'tables' => ['OFFICE_CODES', 'OFFICE_CODE_TYPE_REL'],
            'closed_code_tables' => ['OFFICE_CODES'],
            // nav 是**列表頁**節點設定（側欄），與上面的 edit_route 各司其職。
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
            'definition' => \App\Services\Mutations\EntityAggregate\SocialInstitutionAggregateDefinition::class,
            'pk' => 'c_inst_code',
            'edit_route' => 'app.social-institution.edit',
            'form_capability' => 'write',
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
        [
            // 文獻實體（含 TEXT_INSTANCE_DATA 版本層級）。resource 不叫 text——那是人物
            // 子資源 BIOG_TEXT_DATA 的既有 mutation 別名（見 TextAggregateDefinition 類註）。
            'resource' => 'text-entity',
            'service' => \App\Services\Import\TextImportService::class,
            'definition' => \App\Services\Mutations\EntityAggregate\TextAggregateDefinition::class,
            'pk' => 'c_textid',
            'edit_route' => 'app.text.edit',
            'form_capability' => 'write',
            'tables' => ['TEXT_CODES', 'TEXT_INSTANCE_DATA'],
            // step 4（下層直寫封閉）整體暫緩，兩條裸表路徑都仍在役：
            //  - codes UI：裸表編輯頁尚有實體頁未對齊的功能（TEXT_CODES 編輯頁的作者列表
            //    面板、TEXT_INSTANCE_DATA 的 textid 提示／載入動作與部分版本欄位）；
            //  - 機器面的 text-codes 裸表 create（config/code_table_writes.php）：異體字
            //    S5 才剛把落地替換接進這條路徑，是「就這一列、就這些欄」的機器化寫入，
            //    與聚合的完整語義並存（比照 OFFICE_CODES 拼音欄與 office 聚合並存）。
            // §4.4「封閉是終態、不是起手式」——parity 補齊且確認無外部依賴後，
            // 把兩表加進此清單即自動封寫 codes UI，裸表 create 另行評估。
            'closed_code_tables' => [],
            'nav' => [
                'key' => 'text-codes',
                'label' => 'codes.text_codes',
                'icon' => 'fas fa-book',
                'route' => 'app.text.index',
                'table' => 'TEXT_CODES',
                'pattern' => 'app.text.*',
            ],
        ],
    ],
];
