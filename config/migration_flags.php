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

        // 注意：含點號（dot）的頁面 key 必須以「巢狀陣列」表達，否則 Laravel
        // config() 會把 "migration_flags.pages.auth.login" 的點號當巢狀路徑解析，
        // 讀不到字面含點號的 flat key（一律回退 default）。只有 dot 是分隔符，
        // hyphen（如 audit-logs、query-playground）不是，故維持為單一字面 key。
        // 無點號的 key 維持扁平。env() 對應一律保持原樣，僅搬到巢狀位置。

        // Phase 1 — 唯讀葉節點
        'dashboard' => env('MIGRATION_FLAG_DASHBOARD', 'new'),
        'profile' => env('MIGRATION_FLAG_PROFILE', 'new'),

        // Phase 2 — Codes 代碼表 CRUD
        'codes' => env('MIGRATION_FLAG_CODES', 'new'),

        // Phase 3/4 — 人物列表、檢視與編輯器（含 PersonBrowser 各分頁增量編輯器）
        'basicinformation' => [
            'index' => env('MIGRATION_FLAG_BASICINFO_INDEX', 'new'),
            // ✅ 2026-06-26 全部翻 new 上線（使用者人工逐頁驗收通過）：React 人物編輯器已「重新對齊 legacy
            // 編輯頁」——重建年號轉換 React 元件（EraTimeField）、補 CHGIS 地圖 place-link、對齊版面/互動、
            // 補齊 v2 互逆鏡像（assoc/kinship 雙向同步 + #66/#70 衝突/疑似偵測）、必填/改鍵等 parity 缺口。
            // 全量 1977 測試綠、build 綠、review agent + codex 雙閘逐環節通過。如需回退改回 'old' 即可（可逆）。
            'show' => env('MIGRATION_FLAG_BASICINFO_SHOW', 'new'),
            'editor' => env('MIGRATION_FLAG_BASICINFO_EDITOR', 'new'),
            'altname' => env('MIGRATION_FLAG_BASICINFO_ALTNAME', 'new'),
            'addresses' => env('MIGRATION_FLAG_BASICINFO_ADDRESSES', 'new'),
            'texts' => env('MIGRATION_FLAG_BASICINFO_TEXTS', 'new'),
            'sources' => env('MIGRATION_FLAG_BASICINFO_SOURCES', 'new'),
            'offices' => env('MIGRATION_FLAG_BASICINFO_OFFICES', 'new'),
            'assoc' => env('MIGRATION_FLAG_BASICINFO_ASSOC', 'new'),
            'kinship' => env('MIGRATION_FLAG_BASICINFO_KINSHIP', 'new'),
            'events' => env('MIGRATION_FLAG_BASICINFO_EVENTS', 'new'),
            'entries' => env('MIGRATION_FLAG_BASICINFO_ENTRIES', 'new'),
            'statuses' => env('MIGRATION_FLAG_BASICINFO_STATUSES', 'new'),
            'possession' => env('MIGRATION_FLAG_BASICINFO_POSSESSION', 'new'),
            'socialinst' => env('MIGRATION_FLAG_BASICINFO_SOCIALINST', 'new'),
        ],

        // Phase 5 — 管理與營運工具
        'operations' => env('MIGRATION_FLAG_OPERATIONS', 'new'),
        'manage' => env('MIGRATION_FLAG_MANAGE', 'new'),
        'merge-preview' => env('MIGRATION_FLAG_MERGE_PREVIEW', 'new'),
        'crowdsourcing' => env('MIGRATION_FLAG_CROWDSOURCING', 'new'),

        // admin.* 子頁（含點號，巢狀）：唯讀日誌、批次匯入與維護工具
        'admin' => [
            'audit-logs' => env('MIGRATION_FLAG_ADMIN_AUDIT_LOGS', 'new'),
            'ai-fill-logs' => env('MIGRATION_FLAG_ADMIN_AI_FILL_LOGS', 'new'),
            'explain-sql' => env('MIGRATION_FLAG_ADMIN_EXPLAIN_SQL', 'new'),
            'batch-load-book-titles' => env('MIGRATION_FLAG_BATCH_BOOKS', 'new'),
            'batch-load-offices' => env('MIGRATION_FLAG_BATCH_OFFICES', 'new'),
            'batch-load-social-institutes' => env('MIGRATION_FLAG_BATCH_SOCIAL', 'new'),
            'wiki-maintenance' => env('MIGRATION_FLAG_WIKI_MAINTENANCE', 'new'),
            'cbdb-table-maintenance' => env('MIGRATION_FLAG_TABLE_MAINTENANCE', 'new'),
            'unidirectional-relationship-repair' => env('MIGRATION_FLAG_UNIDIRECTIONAL_REPAIR', 'new'),
        ],

        // query-playground.* 子頁（query-playground 含 hyphen 無 dot，是扁平 key，
        // 其下 nl-query-logs 才以巢狀對應 "query-playground.nl-query-logs"）
        'query-playground' => [
            'nl-query-logs' => env('MIGRATION_FLAG_NL_QUERY_LOGS', 'new'),
        ],

        // View Tables（React 版已上線，待 flip）
        'view' => env('MIGRATION_FLAG_VIEW', 'new'),

        // Phase 6 — 認證頁與入口（flag 可逆、預設 old、不自動上線）
        // 三個認證頁 flag 可獨立或整體回退：login / register / passwords（含忘記密碼與重設密碼）。
        // 維持上線（new）。Inertia 重導 bug 已修（Login/Register/ResetPassword 改用 Inertia::location）。
        // Task 27 須補做認證頁逐項內容對比（label/提示/連結/欄位），缺漏即補齊。
        'auth' => [
            'login' => env('MIGRATION_FLAG_AUTH_LOGIN', 'new'),
            'register' => env('MIGRATION_FLAG_AUTH_REGISTER', 'new'),
            'passwords' => env('MIGRATION_FLAG_AUTH_PASSWORDS', 'new'),
        ],
        // 維持上線（new）。Task 27 須補做 Welcome 逐項內容對比。
        'welcome' => env('MIGRATION_FLAG_WELCOME', 'new'),
    ],
];
