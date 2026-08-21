<?php

/**
 * config 驅動的 code 表「新增／刪除」定義（供 CodeTableCreateHandler / CodeTableDeleteHandler）。
 *
 * 與 config/code_table_mutations.php（只做 update、拼音表）不同：此處是讓單主鍵 code 表可經
 * /api/v2/{create,delete} 與 batch_mutate 機器化寫入（token、operations + AuditLog、可回滾）。
 *
 * 每項欄位：
 * - resource / aliases：請求 resource 別名。
 * - table：資料表名。
 * - key_column：單一主鍵欄。
 * - auto_assign_id：create 未給主鍵時，服務端以 max(key)+1 分配（低頻；並發撞號由唯一鍵兜底 409）。
 * - allowed_fields：create 允許寫入的非主鍵欄白名單。
 */
return [
    'tables' => [
        'TEXT_CODES' => [
            'resource' => 'text-codes',
            'aliases' => ['text-codes', 'text_codes', 'textcodes'],
            'table' => 'TEXT_CODES',
            'display_name' => '文本／出處目錄',
            'key_column' => 'c_textid',
            'auto_assign_id' => true,
            'allowed_fields' => [
                'c_title_chn', 'c_title', 'c_title_trans', 'c_text_type_id', 'c_text_year',
                'c_text_nh_code', 'c_text_nh_year', 'c_text_range_code', 'c_bibl_cat_code',
                'c_extant', 'c_text_country', 'c_text_dy', 'c_source', 'c_pages',
                'c_url_api', 'c_url_api_coda', 'c_url_homepage', 'c_notes', 'c_title_alt_chn',
            ],
        ],
        // ⚠️ 新增登錄項前提：本檔的 handler 一律把主鍵 `(int)` 轉型，所以**只能登錄數值主鍵的
        // 表**。若日後要登錄文本型主鍵的代碼表（例如 *_TYPES 的中文名當 PK），必須先處理兩件事：
        // (1) 移除該 `(int)` 轉型；(2) 補上 D7「兩形並存」查重——
        // `VariantEquivalentLookup::findExistingRow()` 在「主鍵全部都在替換範圍內」時只記
        // warning 就跳過（沒有能收斂候選集的 SQL 條件），單一文本主鍵正好落在那個分支。
        'char_variant_map' => [
            'resource' => 'char-variant-map',
            'aliases' => ['char-variant-map', 'char_variant_map', 'charvariantmap'],
            'table' => 'char_variant_map',
            'display_name' => '異體字落地替換對照表',
            'key_column' => 'id',
            'auto_assign_id' => true,
            'allowed_fields' => ['c_variant_char', 'c_reference_char', 'c_strict_excluded', 'c_notes'],
        ],
    ],
];
