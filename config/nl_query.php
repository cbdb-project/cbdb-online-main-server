<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 简单对照表配置
    |--------------------------------------------------------------------------
    |
    | 这些表的数据量较小，可以直接在 LLM 提示词中包含完整数据，
    | 避免生成复杂的 JOIN 查询。
    |
    */

    // 定义哪些表应该直接包含数据而不仅是 schema
    'lookup_tables' => [
        'DYNASTIES' => [
            'max_rows' => 100, // 最多包含的行数
            'display_columns' => ['c_dy', 'c_dynasty_chn', 'c_sort'], // 要显示的列
        ],
        // BIOG_ADDR_CODES 是「地址類型」代碼表（籍貫／居住地／葬地…），不是地名表。
        // 欄名務必與實際 schema 一致：display_columns 直接進 select()，列了不存在的欄
        // 會讓整張對照表查詢丟 1054、被 getLookupTableData() 的 catch 吞成空陣列 ⇒
        // 不會 500，但提示詞裡從此**靜默少了這張對照表**，NL 生的 SQL 會變差。
        // 曾誤列 ADDR_CODES 的 c_addr_id 與根本不存在的 c_firstlevel_desc。
        'BIOG_ADDR_CODES' => [
            'max_rows' => 100,
            'display_columns' => ['c_addr_type', 'c_addr_desc', 'c_addr_desc_chn'],
        ],
    ],
];
