<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 自然语言查询工具定义
    |--------------------------------------------------------------------------
    |
    | 定义可供 LLM 调用的工具（Function Calling）
    | 这些工具可以帮助 LLM 获取额外的上下文信息以生成更准确的 SQL 查询
    |
    */

    'enabled' => env('NL_QUERY_TOOLS_ENABLED', true),

    'tools' => [
        [
            'type' => 'function',
            'function' => [
                'name' => 'list_allowed_tables',
                'description' => '列出系統允許查詢的所有資料表。當你不確定可用表格時，優先調用此工具。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => (object) [],
                    'additionalProperties' => false,
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'query_table_schema',
                'description' => '獲取指定表格的完整 schema（欄位、索引、外鍵與表格 metadata）。適合建立 JOIN 與確認關聯時使用。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'table_name' => [
                            'type' => 'string',
                            'description' => 'Allowlisted table name',
                        ],
                    ],
                    'required' => ['table_name'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_table_schema',
                'description' => '獲取指定表格的結構信息（字段名、數據類型、描述等）。當你需要了解表格有哪些字段以及它們的含義時使用此工具。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'table_name' => [
                            'type' => 'string',
                            'description' => '要獲取結構信息的表名',
                        ],
                    ],
                    'required' => ['table_name'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_sample_data_for_table',
                'description' => '獲取指定表格中的樣例數據（默認 10 條記錄）。這有助於了解表格的實際數據格式、值的範圍、以及如何構造 JOIN 條件和 WHERE 子句。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'table_name' => [
                            'type' => 'string',
                            'description' => '要獲取樣例數據的表名（必須是系統提供的可用表格之一）',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => '返回的記錄數（可選，默認 10 條，最多 20 條）',
                            'minimum' => 1,
                            'maximum' => 20,
                            'default' => 10,
                        ],
                    ],
                    'required' => ['table_name'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'query_table',
                'description' => '查詢單一表格，可指定 filters、columns、limit、offset。適合先快速驗證欄位值與分佈。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'table_name' => [
                            'type' => 'string',
                            'description' => 'Allowlisted table name',
                        ],
                        'filters' => [
                            'type' => ['object', 'string', 'null'],
                            'description' => '可選過濾條件。可傳 JSON object 或 JSON string。',
                        ],
                        'columns' => [
                            'type' => ['array', 'string', 'null'],
                            'description' => '可選欄位清單。可傳字串陣列或逗號分隔字串。',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => '返回筆數（1-100）',
                            'minimum' => 1,
                            'maximum' => 100,
                            'default' => 10,
                        ],
                        'offset' => [
                            'type' => 'integer',
                            'description' => '跳過筆數',
                            'minimum' => 0,
                            'default' => 0,
                        ],
                    ],
                    'required' => ['table_name'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_table_row_by_id',
                'description' => '透過指定 id 欄位和值抓取單筆資料，適合快速驗證特定主鍵或代碼值。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'table_name' => [
                            'type' => 'string',
                            'description' => 'Allowlisted table name',
                        ],
                        'id_column' => [
                            'type' => 'string',
                            'description' => 'ID column name',
                        ],
                        'id_value' => [
                            'type' => ['string', 'number', 'boolean', 'null'],
                            'description' => 'ID value to match',
                        ],
                    ],
                    'required' => ['table_name', 'id_column', 'id_value'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'query_read_only_sql',
                'description' => '執行只讀 SQL（僅 SELECT/WITH）。適合在最終生成前先用小 limit 驗證查詢是否可執行。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'sql' => [
                            'type' => 'string',
                            'description' => 'Read-only SQL (SELECT/WITH only)',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max rows to return (1-100)',
                            'minimum' => 1,
                            'maximum' => 100,
                            'default' => 20,
                        ],
                        'offset' => [
                            'type' => 'integer',
                            'description' => 'Rows to skip',
                            'minimum' => 0,
                            'default' => 0,
                        ],
                    ],
                    'required' => ['sql'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_code_values',
                'description' => '獲取代碼表的所有可用值。當你需要構造 WHERE 條件但不確定代碼值時使用（如性別碼、朝代碼、入仕類型碼等）。可用的代碼類型：dynasties（朝代）, sex（性別）, entry_codes（入仕類型）, kinship_codes（親屬關係）, status_codes（社會身份）, address_types（地址類型）。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'code_type' => [
                            'type' => 'string',
                            'description' => '代碼類型',
                            'enum' => ['dynasties', 'sex', 'entry_codes', 'kinship_codes', 'status_codes', 'address_types'],
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => '返回的記錄數（可選，默認 50 條）',
                            'minimum' => 1,
                            'maximum' => 100,
                            'default' => 50,
                        ],
                    ],
                    'required' => ['code_type'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_person_ids',
                'description' => '根據人名搜索人物 ID。當用戶提到特定人名時使用此工具查找對應的 c_personid。支持模糊匹配，會返回人物的基本信息（姓名、朝代、生卒年等）。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'person_name' => [
                            'type' => 'string',
                            'description' => '要搜索的人名（支持部分匹配）',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => '返回的記錄數（可選，默認 20 條）',
                            'minimum' => 1,
                            'maximum' => 50,
                            'default' => 20,
                        ],
                    ],
                    'required' => ['person_name'],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 工具调用设置
    |--------------------------------------------------------------------------
    */

    // 最大工具调用次数（防止无限循环）
    'max_tool_calls' => (int) env('NL_QUERY_MAX_TOOL_CALLS', 40),

    // 工具调用超时时间（秒）
    'timeout' => 10,
];
