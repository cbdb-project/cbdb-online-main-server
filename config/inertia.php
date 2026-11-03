<?php

/*
 * inertia-laravel v3 的設定結構（v2 → v3 重整過）：
 *   - 頁面路徑／副檔名由 `pages.*` 統一管理（原 `page_paths`／`page_extensions`／`testing.page_paths`）
 *   - `testing` 只剩 `ensure_pages_exist`，assertInertia 沿用 `pages.paths`／`pages.extensions` 找檔案
 *   - `use_script_element_for_initial_page` 已移除（v3 一律以 <script type="application/json"> 輸出首屏資料）
 * 這裡只列出本專案與套件預設值不同的項目，其餘沿用套件預設。
 */
return [

    'ssr' => [

        // v3 預設為 true；本專案不使用 SSR，必須明確關閉。
        'enabled' => false,

        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),

        'ensure_bundle_exists' => false,

    ],

    'pages' => [

        'ensure_pages_exist' => false,

        'paths' => [

            resource_path('js/inertia/Pages'),

        ],

        'extensions' => [

            'js',
            'jsx',
            'ts',
            'tsx',

        ],

    ],

    'testing' => [

        'ensure_pages_exist' => true,

    ],

    'history' => [

        'encrypt' => false,

    ],

];
