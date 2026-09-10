<?php

/**
 * config 驅動的 code 表「新增／刪除」定義（供 CodeTableCreateHandler / CodeTableDeleteHandler）。
 *
 * 與 config/code_table_mutations.php（只做 update）不同：此處是讓 code／查找表可經
 * /api/v2/{create,delete} 與 batch_mutate 機器化寫入（token、operations + AuditLog、可回滾）。
 *
 * 每項欄位：
 * - resource / aliases：請求 resource 別名。
 * - table：資料表名。
 * - key_columns：主鍵欄（**陣列**，順序須與 CompositePrimaryKey::SCHEMAS 及
 *   OperationsController::resourceKeyColumns() 完全一致；handler 有防呆會擋不一致）。
 * - auto_assign_id：create 未給主鍵時，服務端以 max(key)+1 分配（低頻；並發撞號由唯一鍵兜底 409）。
 *   **僅單一主鍵欄的表可用**——複合主鍵沒有「下一個 id」的語義，handler 會擋。
 * - allowed_fields：create 允許寫入的非主鍵欄白名單。
 * - integer_fields / float_fields / long_text_fields / not_null_fields（皆可選）：欄位型別登記，
 *   語義與 config/code_table_mutations.php 相同，判定本體共用 {@see \App\Support\CodeTableFieldValidator}。
 *   未登記的欄一律要求 string|null 且長度 ≤ 255。
 *
 * ⚠️ 新增登錄項的前提：本檔的 handler 一律把主鍵 `(int)` 轉型，所以**只能登錄數值主鍵的
 * 表**。若日後要登錄文本型主鍵的代碼表（例如 *_TYPES 的中文名當 PK），必須先處理兩件事：
 * (1) 移除該 `(int)` 轉型；(2) 補上 D7「兩形並存」查重——
 * `VariantEquivalentLookup::findExistingRow()` 在「主鍵全部都在替換範圍內」時只記
 * warning 就跳過（沒有能收斂候選集的 SQL 條件），單一文本主鍵正好落在那個分支。
 */
return [
    'tables' => [
        'TEXT_CODES' => [
            'resource' => 'text-codes',
            'aliases' => ['text-codes', 'text_codes', 'textcodes'],
            'table' => 'TEXT_CODES',
            'display_name' => '文本／出處目錄',
            'key_columns' => ['c_textid'],
            'auto_assign_id' => true,
            'allowed_fields' => [
                'c_title_chn', 'c_title', 'c_title_trans', 'c_text_type_id', 'c_text_year',
                'c_text_nh_code', 'c_text_nh_year', 'c_text_range_code', 'c_bibl_cat_code',
                'c_extant', 'c_text_country', 'c_text_dy', 'c_source', 'c_pages',
                'c_url_api', 'c_url_api_coda', 'c_url_homepage', 'c_notes', 'c_title_alt_chn',
            ],
            // 歷史行為：本表登錄時 create 端完全沒有型別校驗，任何欄位送什麼都往下丟。
            // 現在 create 端與 update 端共用校驗，為了不改變既有呼叫端的可用性，
            // 這裡把本表實際的數值欄與長文欄如實登記出來。
            // 逐欄對照 prod schema：以下全是 smallint／int（c_text_type_id 是 varchar(128)，不在列）。
            'integer_fields' => [
                'c_text_year', 'c_text_nh_code', 'c_text_nh_year', 'c_text_range_code',
                'c_bibl_cat_code', 'c_extant', 'c_text_country', 'c_text_dy', 'c_source',
            ],
            'long_text_fields' => ['c_notes'],
        ],
        'char_variant_map' => [
            'resource' => 'char-variant-map',
            'aliases' => ['char-variant-map', 'char_variant_map', 'charvariantmap'],
            'table' => 'char_variant_map',
            'display_name' => '異體字落地替換對照表',
            'key_columns' => ['id'],
            'auto_assign_id' => true,
            'allowed_fields' => ['c_variant_char', 'c_reference_char', 'c_strict_excluded', 'c_notes'],
            'integer_fields' => ['c_strict_excluded'],
            // NOT NULL：送 null 不擋的話是資料庫層 1048（500）而不是 422。
            'not_null_fields' => ['c_variant_char', 'c_reference_char', 'c_strict_excluded'],
        ],
        // 地名表（#報告：「API 無法新增地名表記錄」）。ADDR_CODES 原本只有 update 端、
        // 且只開放 c_name 一欄；新增與隸屬關係完全沒有 v2 入口。
        'ADDR_CODES' => [
            'resource' => 'addr-codes',
            'aliases' => ['addr-codes', 'addr_codes', 'addrcodes'],
            'table' => 'ADDR_CODES',
            'display_name' => '地址代碼',
            'key_columns' => ['c_addr_id'],
            'auto_assign_id' => true,
            'allowed_fields' => [
                'c_name', 'c_name_chn', 'c_alt_names',
                'c_firstyear', 'c_lastyear',
                'c_admin_type', 'c_admin_cat_code',
                'x_coord', 'y_coord', 'CHGIS_PT_ID',
                'c_notes',
            ],
            'integer_fields' => ['c_firstyear', 'c_lastyear', 'c_admin_cat_code', 'CHGIS_PT_ID'],
            'float_fields' => ['x_coord', 'y_coord'],
            'long_text_fields' => ['c_notes'],
            // NOT NULL DEFAULT 0 且帶 FK → ADMIN_CAT_CODES：可以整個不送（吃預設值 0），
            // 但送 null／空字串是明確的錯誤，要在 422 就擋下。
            'not_null_fields' => ['c_admin_cat_code'],
        ],
        // 行政類別代碼（州／府／縣…）。原本只能 update 拼音欄、沒有新增入口，
        // 於是要建一個新的行政類別只能走 /codes UI（機器化匯入地名時卡在這裡）。
        // 被 ADDR_CODES.c_admin_cat_code 與 ADMIN_CAT_CODE_TYPE_REL 以外鍵引用，
        // 所以刪除仍然停用（與其他代碼表一致）。
        'ADMIN_CAT_CODES' => [
            'resource' => 'admin-cat-codes',
            'aliases' => ['admin-cat-codes', 'admin_cat_codes', 'admin-cat', 'admin_cat'],
            'table' => 'ADMIN_CAT_CODES',
            'display_name' => '行政類別代碼',
            'key_columns' => ['c_admin_cat_code'],
            'auto_assign_id' => true,
            'allowed_fields' => ['c_admin_cat_py', 'c_admin_cat_hz', 'c_admin_cat_trans', 'c_notes'],
            'long_text_fields' => ['c_notes'],
            // 本表沒有 c_created_by／c_modified_* 那組稽核欄，**刻意不補**：v2 的每一次寫入
            // 都已經在 operations 與 audit_log 留下操作者與時間，補欄只會讓 /codes 列表多出
            // 四個永遠是空的欄。CodeTableCreateHandler 按實際欄位蓋章，缺欄不會 500
            // （ADDR_CODES 當年就是因為缺欄而必然新增失敗，那張表選擇補欄是因為它已經有
            // 姊妹表 ADDR_BELONGS_DATA 帶著同一組欄位、補齊才一致）。
        ],
        // 地名隸屬關係：四欄複合主鍵（地名、上級地名、起訖年）全部由呼叫端給定——
        // 沒有 auto_assign 的餘地，也不該有：換上級或換年段就是另一筆記錄。
        'ADDR_BELONGS_DATA' => [
            'resource' => 'addr-belongs-data',
            'aliases' => ['addr-belongs-data', 'addr_belongs_data', 'addr-belongs', 'addr_belongs'],
            'table' => 'ADDR_BELONGS_DATA',
            'display_name' => '地址隸屬關係',
            'key_columns' => ['c_addr_id', 'c_belongs_to', 'c_firstyear', 'c_lastyear'],
            'auto_assign_id' => false,
            'allowed_fields' => ['c_source', 'c_pages', 'c_notes'],
            'integer_fields' => ['c_source'],
        ],
    ],
];
