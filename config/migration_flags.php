<?php

/*
|--------------------------------------------------------------------------
| React + Inertia 漸進式遷移開關（feature flags）
|--------------------------------------------------------------------------
|
| 見 docs/REACT_INERTIA_MIGRATION_PLAN.md §五之二。每個可遷移頁面一個開關，
| 值為 'old'（指向舊 Blade 路由）或 'new'（指向新 React/Inertia 路由）。
| 導覽單一來源 App\Support\Navigation 依此決定側邊欄連結指向新或舊頁。
|
| 不變量：
|   - 預設一律 'old'，flip 成 'new' = 上線新頁，改回 = 即時回退，不需改碼。
|   - 「翻 flag 上線」只能由人執行（見計畫附錄 C 寫入禁止清單）；
|     AI executor 不得自動切換。
|   - 可用環境變數覆蓋（部署時），key 形如 MIGRATION_FLAG_<UPPER_SNAKE>。
|
| 每個 key 對應 Navigation schema 中的 'flag' 欄位；新增遷移頁時，在此登記
| 一個 flag（預設 'old'）並在 Navigation::routes() 提供 old/new 兩個路由名。
|
*/

return [

    // 預設值；個別頁面未列出時採此值。
    'default' => env('MIGRATION_FLAG_DEFAULT', 'old'),

    'pages' => [

        // Phase 1 — 唯讀葉節點
        'dashboard' => env('MIGRATION_FLAG_DASHBOARD', 'old'),
        'profile' => env('MIGRATION_FLAG_PROFILE', 'old'),
        'admin.audit-logs' => env('MIGRATION_FLAG_ADMIN_AUDIT_LOGS', 'old'),
        'admin.ai-fill-logs' => env('MIGRATION_FLAG_ADMIN_AI_FILL_LOGS', 'old'),
        'admin.explain-sql' => env('MIGRATION_FLAG_ADMIN_EXPLAIN_SQL', 'old'),
        'query-playground.nl-query-logs' => env('MIGRATION_FLAG_NL_QUERY_LOGS', 'old'),

        // Phase 2 — Codes 代碼表 CRUD
        'codes' => env('MIGRATION_FLAG_CODES', 'old'),

        // Phase 3 — 人物列表與檢視
        'basicinformation.index' => env('MIGRATION_FLAG_BASICINFO_INDEX', 'old'),
        'basicinformation.show' => env('MIGRATION_FLAG_BASICINFO_SHOW', 'old'),

        // Phase 4 — 人物編輯器（硬前置 F7，整體仍 old）
        'basicinformation.editor' => env('MIGRATION_FLAG_BASICINFO_EDITOR', 'old'),

        // Phase 5 — 管理與營運工具
        'operations' => env('MIGRATION_FLAG_OPERATIONS', 'old'),
        'manage' => env('MIGRATION_FLAG_MANAGE', 'old'),
        'merge-preview' => env('MIGRATION_FLAG_MERGE_PREVIEW', 'old'),
        'crowdsourcing' => env('MIGRATION_FLAG_CROWDSOURCING', 'old'),
        'admin.batch-load-book-titles' => env('MIGRATION_FLAG_BATCH_BOOKS', 'old'),
        'admin.batch-load-offices' => env('MIGRATION_FLAG_BATCH_OFFICES', 'old'),
        'admin.batch-load-social-institutes' => env('MIGRATION_FLAG_BATCH_SOCIAL', 'old'),
        'admin.wiki-maintenance' => env('MIGRATION_FLAG_WIKI_MAINTENANCE', 'old'),
        'admin.cbdb-table-maintenance' => env('MIGRATION_FLAG_TABLE_MAINTENANCE', 'old'),
        'admin.unidirectional-relationship-repair' => env('MIGRATION_FLAG_UNIDIRECTIONAL_REPAIR', 'old'),

        // View Tables（React 版已上線，待 flip）
        'view' => env('MIGRATION_FLAG_VIEW', 'old'),

        // Phase 6 — 認證頁與入口
        'welcome' => env('MIGRATION_FLAG_WELCOME', 'old'),
    ],
];
