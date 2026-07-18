<?php

/**
 * Code／lookup 表受審計更新 API 的表定義（CODE_TABLE_MUTATION_API_PLAN.md）。
 *
 * 單一 config 驅動 {@see \App\Services\Mutations\ConfigCodeTableMutationHandler}——毋須為每張表寫子類。
 * 每筆定義：
 *   - resource：回應與提案 meta 使用的正規名（須含於 aliases）。
 *   - table：實際資料表名（＝ audit_log／operations 的 table、CompositePrimaryKey::SCHEMAS 鍵、
 *     OperationsController::resourceKeyColumns() 鍵——三處主鍵登錄必須一致）。
 *   - aliases：/api/v2/mutate 的 resource 字串接受值（勿與既有人物子資源 resource 撞名）。
 *   - display_name：提案 meta 顯示名。
 *   - key_columns：主鍵欄（順序須與 CompositePrimaryKey::SCHEMAS 完全一致；基底有 500 防呆）。
 *   - allowed_fields：允許更新的欄位白名單（Phase B 首要為拼音／羅馬字欄，見 §D-6 Tier 登錄表）。
 *   - tier1_fields：保存時**後端靜默** v→ü 歸一化的欄（定義上即漢語拼音、無西文）。
 *   - tier2_fields：**可能含西文**的混合欄——後端**不**靜默轉（由前端 altname 式彈窗讓使用者決定）。
 *     tier1_fields ∪ tier2_fields 必等於 allowed_fields。依 §D-6 Tier 登錄表（實測定案）。
 *   - integer_fields（可選，預設無）：allowed_fields 中允許以整數值更新的欄（見
 *     AbstractCodeTableMutationHandler::validateFields()）。預設所有欄一律要求 string|null；
 *     只有非拼音／文字的整數旗標欄（如 char_variant_map.c_strict_excluded）才需要登記，
 *     且僅登記該欄本身——不影響同一張表或其他表未登記欄位仍要求 string|null 的既有行為。
 *
 * 目前僅 update；c_personid 一律 0（code 表為全域代碼）。
 */

return [
    'tables' => [
        [
            'resource' => 'nianhao',
            'table' => 'NIAN_HAO',
            'aliases' => ['nianhao', 'nian_hao'],
            'display_name' => '年號',
            'key_columns' => ['c_nianhao_id'],
            'allowed_fields' => ['c_nianhao_pin'],
            'tier1_fields' => ['c_nianhao_pin'],
            'tier2_fields' => [],
        ],
        [
            'resource' => 'office_codes',
            'table' => 'OFFICE_CODES',
            'aliases' => ['office_codes', 'office_code'],
            'display_name' => '官職代碼',
            'key_columns' => ['c_office_id'],
            'allowed_fields' => ['c_office_pinyin', 'c_office_pinyin_alt'],
            'tier1_fields' => ['c_office_pinyin', 'c_office_pinyin_alt'],
            'tier2_fields' => [],
        ],
        [
            'resource' => 'dynasties',
            'table' => 'DYNASTIES',
            'aliases' => ['dynasties', 'dynasty'],
            'display_name' => '朝代',
            'key_columns' => ['c_dy'],
            'allowed_fields' => ['c_dynasty'],
            'tier1_fields' => [],
            'tier2_fields' => ['c_dynasty'],
        ],
        [
            'resource' => 'choronym_codes',
            'table' => 'CHORONYM_CODES',
            'aliases' => ['choronym_codes', 'choronym_code', 'choronym'],
            'display_name' => '郡望代碼',
            'key_columns' => ['c_choronym_code'],
            'allowed_fields' => ['c_choronym_desc'],
            'tier1_fields' => [],
            'tier2_fields' => ['c_choronym_desc'],
        ],
        [
            'resource' => 'ethnicity_tribe_codes',
            'table' => 'ETHNICITY_TRIBE_CODES',
            'aliases' => ['ethnicity_tribe_codes', 'ethnicity', 'ethnicity_tribe'],
            'display_name' => '民族部族代碼',
            'key_columns' => ['c_ethnicity_code'],
            'allowed_fields' => ['c_name', 'c_romanized', 'c_surname'],
            'tier1_fields' => ['c_name'],
            'tier2_fields' => ['c_romanized', 'c_surname'],
        ],
        [
            'resource' => 'text_codes',
            'table' => 'TEXT_CODES',
            'aliases' => ['text_codes'],
            'display_name' => '文獻代碼',
            'key_columns' => ['c_textid'],
            'allowed_fields' => ['c_title'],
            'tier1_fields' => ['c_title'],
            'tier2_fields' => [],
        ],
        [
            'resource' => 'text_instance_data',
            'table' => 'TEXT_INSTANCE_DATA',
            'aliases' => ['text_instance_data', 'text_instance'],
            'display_name' => '文獻版本',
            'key_columns' => ['c_textid', 'c_text_edition_id', 'c_text_instance_id'],
            'allowed_fields' => ['c_instance_title', 'c_pub_year', 'c_publisher'],
            'tier1_fields' => ['c_instance_title'],
            // c_pub_year（出版年，整數）與 c_publisher（出版者，文字/可清空）非拼音欄，
            // 放 tier2 以避開 tier1 的 v→ü 靜默正規化；c_pub_year 另登記為整數欄。
            'tier2_fields' => ['c_pub_year', 'c_publisher'],
            'integer_fields' => ['c_pub_year'],
        ],
        [
            'resource' => 'text_biblcat_codes',
            'table' => 'TEXT_BIBLCAT_CODES',
            'aliases' => ['text_biblcat_codes', 'text_biblcat'],
            'display_name' => '文獻分類代碼',
            'key_columns' => ['c_text_cat_code'],
            'allowed_fields' => ['c_text_cat_pinyin'],
            'tier1_fields' => ['c_text_cat_pinyin'],
            'tier2_fields' => [],
        ],
        [
            'resource' => 'ganzhi_codes',
            'table' => 'GANZHI_CODES',
            'aliases' => ['ganzhi_codes', 'ganzhi'],
            'display_name' => '干支代碼',
            'key_columns' => ['c_ganzhi_code'],
            'allowed_fields' => ['c_ganzhi_py'],
            'tier1_fields' => ['c_ganzhi_py'],
            'tier2_fields' => [],
        ],
        [
            'resource' => 'social_institution_name_codes',
            'table' => 'SOCIAL_INSTITUTION_NAME_CODES',
            'aliases' => ['social_institution_name_codes'],
            'display_name' => '社會機構名稱代碼',
            'key_columns' => ['c_inst_name_code'],
            'allowed_fields' => ['c_inst_name_py'],
            'tier1_fields' => ['c_inst_name_py'],
            'tier2_fields' => [],
        ],
        [
            'resource' => 'social_institution_types',
            'table' => 'SOCIAL_INSTITUTION_TYPES',
            'aliases' => ['social_institution_types'],
            'display_name' => '社會機構類型',
            'key_columns' => ['c_inst_type_code'],
            'allowed_fields' => ['c_inst_type_py'],
            'tier1_fields' => ['c_inst_type_py'],
            'tier2_fields' => [],
        ],
        [
            'resource' => 'admin_cat_codes',
            'table' => 'ADMIN_CAT_CODES',
            'aliases' => ['admin_cat_codes', 'admin_cat'],
            'display_name' => '行政類別代碼',
            'key_columns' => ['c_admin_cat_code'],
            'allowed_fields' => ['c_admin_cat_py'],
            'tier1_fields' => ['c_admin_cat_py'],
            'tier2_fields' => [],
        ],
        [
            'resource' => 'addr_codes',
            'table' => 'ADDR_CODES',
            'aliases' => ['addr_codes'],
            'display_name' => '地址代碼',
            'key_columns' => ['c_addr_id'],
            'allowed_fields' => ['c_name'],
            'tier1_fields' => [],
            'tier2_fields' => ['c_name'],
        ],
        [
            'resource' => 'char_variant_map',
            'table' => 'char_variant_map',
            'aliases' => ['char_variant_map', 'char-variant-map', 'charvariantmap'],
            'display_name' => '異體字落地替換對照',
            'key_columns' => ['id'],
            'allowed_fields' => ['c_variant_char', 'c_reference_char', 'c_strict_excluded', 'c_notes'],
            'tier1_fields' => [],
            'tier2_fields' => ['c_variant_char', 'c_reference_char', 'c_strict_excluded', 'c_notes'],
            // c_strict_excluded 是整數旗標欄（tinyint 0/1），非本檔其餘表清一色的拼音／文字欄；
            // AbstractCodeTableMutationHandler::validateFields() 預設只接受 string|null，
            // 這裡明確登記允許整數，且僅限這一欄——不影響本檔其他表仍要求 string|null 的既有行為。
            'integer_fields' => ['c_strict_excluded'],
        ],
    ],
];
