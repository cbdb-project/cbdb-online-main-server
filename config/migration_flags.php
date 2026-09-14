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
|   - 'default' => 'old' 只是 **unknown-key fallback**：本檔列出的每個已知頁面都明設 'new'
|     （全站已於 2026-06-26 翻 new 上線），所以 default 只影響「本檔沒列到的 key」。
|   - 🔴 **「改回 'old' 即時回退」只對「未被封路」的頁面成立**（2026-09 起）：
|     Blade 下架計畫環節 3 之後，多數 legacy 頁面由 `legacy.page` middleware 封路
|     （顯示頁 302／寫入端 410），**該 middleware 不讀本檔任何 flag**。那批頁面的回退鍵是
|     LEGACY_PAGE_RETIREMENT=false（見 config/legacy_page_retirement.php）——
|     🔴 **但那個開關自 2026-09-15（環節 4b-4b）起也沒有作用了**：所有 legacy 頁面
|     都已改成 closure，沒有任何路由掛封路 middleware。要回到 Blade 只能 git revert。本檔的 flag
|     對它們只影響**連結／URL payload 的指向**（Navigation 側邊欄、code_table_edit_url()、
|     CodesController 的 URL payload、HandleInertiaRequests::profileUrl()、audit-log URL 等），
|     **不影響 legacy 頁面是否可開啟或其渲染**。仍由 flag 決定渲染的只剩 'auth' 與 'welcome'
|     （分支在 controller 內部、路由未封路）。
|   - 🔴 **已實體刪除、連 kill switch 都救不回的**：人物編輯 basicinformation.*（環節 2），
|     以及環節 4a-3 的 9 條唯讀頁——operations／dashboard／view／view/{key}／merge-preview／
|     crowdsourcing／nl-query-logs／admin.audit-logs／admin.ai-fill-logs。它們已改成 redirect
|     closure、不掛封路 middleware，所以 LEGACY_PAGE_RETIREMENT=false 對它們**無作用**。
|     🔴 **2026-09-15（環節 4b-4a／4b-4b）起，codes 全套與其餘表單／寫入頁也都實體刪除了**
|     ——kill switch 的涵蓋範圍自此是**空集合**，沒有任何路由掛封路 middleware。
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

        // Phase 3/4（人物列表、檢視與編輯器）的 15 個 basicinformation.* flag 已於 2026-09-14
        // 隨 legacy Blade 人物編輯全套實體下架一併移除（Blade 下架計畫環節 2）：對應的舊視圖、
        // 路由、LegacyBladeFormGate 與 12 個子資源 controller 都已刪除，flag 沒有可切回的對象。
        // 舊 .env 裡的 MIGRATION_FLAG_BASICINFO_* 可安全刪除（清單見該計畫 §三之四）。

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
            // wiki-maintenance（外部資料庫引用瀏覽器）已無 Blade 版：/external-db-link 硬導向 React，不走 flag。
            'cbdb-table-maintenance' => env('MIGRATION_FLAG_TABLE_MAINTENANCE', 'new'),
            'unidirectional-relationship-repair' => env('MIGRATION_FLAG_UNIDIRECTIONAL_REPAIR', 'new'),
        ],

        // query-playground.* 子頁（query-playground 含 hyphen 無 dot，是扁平 key，
        // 其下 nl-query-logs 才以巢狀對應 "query-playground.nl-query-logs"）
        'query-playground' => [
            'nl-query-logs' => env('MIGRATION_FLAG_NL_QUERY_LOGS', 'new'),
        ],

        // View Tables（React 版已翻 new 上線，2026-06-26）
        // 🔴 Blade 版已於環節 4a-3 **實體刪除**：view／view/{key} 現在是 redirect closure，
        // 翻回 old 不會回到 Blade，而且 LEGACY_PAGE_RETIREMENT=false 對它**也無作用**
        // （已不掛封路 middleware）。本 flag 現在只影響連結指向。
        'view' => env('MIGRATION_FLAG_VIEW', 'new'),

        // Phase 6 — 認證頁與入口（已翻 new 上線；flag 可逆回 old）
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
