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
            'max_rows' => 50, // 最多包含的行数
            'display_columns' => ['c_dy', 'c_dy_chn', 'c_sort'], // 要显示的列
        ],
        'BIOG_ADDR_CODES' => [
            'max_rows' => 100,
            'display_columns' => ['c_addr_id', 'c_addr_desc', 'c_firstlevel_desc'],
        ],
    ],
];
