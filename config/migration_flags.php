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
        'dashboard' => env('MIGRATION_FLAG_DASHBOARD', 'old'),
        'profile' => env('MIGRATION_FLAG_PROFILE', 'old'),

        // Phase 2 — Codes 代碼表 CRUD
        'codes' => env('MIGRATION_FLAG_CODES', 'old'),

        // Phase 3/4 — 人物列表、檢視與編輯器（含 PersonBrowser 各分頁增量編輯器）
        'basicinformation' => [
            'index' => env('MIGRATION_FLAG_BASICINFO_INDEX', 'old'),
            // 維持上線（new）。⚠️ 已知缺漏（待 Task 27 逐頁內容對比修正）：React 版 Show.tsx 目前只渲染
            // 基本資料唯讀區，缺舊頁 13 子資源分頁（別名/官名/親屬…）、缺各子資源編輯入口、編輯互動模型
            // 與舊頁不同（舊可直接編輯／新需開啟編輯）。Task 27 將以「逐項內容對比」抓全並補齊後再確認。
            'show' => env('MIGRATION_FLAG_BASICINFO_SHOW', 'new'),
            // basic-info 編輯器（editor）已逐項驗證 parity（13 分頁、全 BIOG_MAIN 欄位可直接編輯、hints 齊），維持 new。
            'editor' => env('MIGRATION_FLAG_BASICINFO_EDITOR', 'new'),
            // ⚠️ 2026-06-22 Task 27 子資源逐欄對比發現：以下 React 子資源編輯器為「精簡版」，
            // 缺多個舊表單可錄入欄位（已對照舊 _form 確認）：
            //   assoc 缺 c_topic_code/c_occasion_code/c_tertiary_personid/c_assoc_claimer_id/c_addr_id/c_inst_code；
            //   offices 缺 c_assume_office_code/c_dy/c_inst_code/c_office_category_id；
            //   entries 缺 c_exam_rank/c_attempt_count/c_exam_field/c_parental_status_code/c_age/c_posting_notes；
            //   addresses 缺 c_natal；statuses 缺 c_supplement；sources 缺 c_main_source/c_self_bio；
            //   events/kinship/possession/socialinst 待逐欄確認。
            // 屬 §0.2 內容/錄入能力流失，全部翻回 old（分頁自動退回 Legacy 編輯入口＝完整舊編輯器），
            // 待各編輯器補齊欄位（UI + mutation handler allowedFields + 驗證）並逐個過 §6.0 後再逐一翻 new。
            // altname：審計確認字段完整、無資料流失向量、無互逆（送出字段全部被 tab 返回）。allowedFields 有 latent 幻影欄（編輯器不送、無影響，末尾清理）。
            'altname' => env('MIGRATION_FLAG_BASICINFO_ALTNAME', 'new'),
            // addresses：補回 c_natal（是否本貫，真實 int 欄）四件套 + tab-return 斷言；codex NO SEVERE；無互逆風險。
            'addresses' => env('MIGRATION_FLAG_BASICINFO_ADDRESSES', 'new'),
            // texts：審計確認字段完整、無資料流失向量（送出字段全部被 tab 返回）。allowedFields 有 latent 幻影欄（不送、末尾清理）。
            'texts' => env('MIGRATION_FLAG_BASICINFO_TEXTS', 'new'),
            // sources：c_main_source/c_self_bio 早已四件套接好（capture 誤報為缺）；codex NO SEVERE，repository 用 $existing 防清空。
            'sources' => env('MIGRATION_FLAG_BASICINFO_SOURCES', 'new'),
            // offices/postings：補回 4 缺欄（就任/朝代/機構/官職類別）四件套 + 移幻影 c_supplement + tab-return 斷言。
            'offices' => env('MIGRATION_FLAG_BASICINFO_OFFICES', 'new'),
            // assoc：已補回 7 缺欄（topic/occasion/tertiary 人物/claimer/addr/inst + tertiary_notes）四件套 + 移除幻影 c_supplement。
            'assoc' => env('MIGRATION_FLAG_BASICINFO_ASSOC', 'new'),
            // ⚠️ kinship 維持 old（不可翻 new）：v2 親屬為單列 mutation、無「互逆親屬」處理。
            // (1) c_kinship_pair 的自動建反向親屬未移植；(2) codex SEVERE：c_autogen_notes 在 v2 可編輯會破壞
            // legacy 互逆配對（reciprocal 依 c_autogen_notes 跨對相同來配對 update/delete/repair，單側改動會悄悄
            // 切斷配對、留下單向關係＝損壞既有資料）。須先把互逆事務（建/改/刪/c_autogen_notes 同步）移植到 v2
            // handler 並各自過閘，才可翻 new。c_autogen_notes UI/handler 代碼已就位但因此風險暫不啟用。
            'kinship' => env('MIGRATION_FLAG_BASICINFO_KINSHIP', 'old'),
            // events/statuses：已補齊缺字段(c_role/c_supplement)+ 修復「tab 查詢漏返回→保存清空」資料流失 bug。
            // 過 §6.0（內容對比 + 錄入交互測試含防清空 + I/J/K + review/codex）後翻 new。
            'events' => env('MIGRATION_FLAG_BASICINFO_EVENTS', 'new'),
            // entries：補回 6 缺欄（exam_rank/attempt_count/exam_field/parental_status_code/age/posting_notes）四件套。
            // ⚠️ c_parental_status_code 對齊真實 cbdb_data 欄名（import migration 誤作 c_parental_status，已用真實欄名）。
            'entries' => env('MIGRATION_FLAG_BASICINFO_ENTRIES', 'new'),
            'statuses' => env('MIGRATION_FLAG_BASICINFO_STATUSES', 'new'),
            // possession：補回年號/時限（c_possession_nh_code/nh_yr/yr_range）四件套；codex NO SEVERE。
            //   已知功能缺口（非資料流失）：編輯態地址多選 c_addr_id 未做（edit 不送→不清空既有地址；create 可設地址）。
            'possession' => env('MIGRATION_FLAG_BASICINFO_POSSESSION', 'new'),
            // socialinst：補回 6 年號/時限欄（c_bi_by/ey_nh_code/nh_year/range）四件套；codex NO SEVERE。
            'socialinst' => env('MIGRATION_FLAG_BASICINFO_SOCIALINST', 'new'),
        ],

        // Phase 5 — 管理與營運工具
        'operations' => env('MIGRATION_FLAG_OPERATIONS', 'old'),
        'manage' => env('MIGRATION_FLAG_MANAGE', 'old'),
        'merge-preview' => env('MIGRATION_FLAG_MERGE_PREVIEW', 'old'),
        'crowdsourcing' => env('MIGRATION_FLAG_CROWDSOURCING', 'old'),

        // admin.* 子頁（含點號，巢狀）：唯讀日誌、批次匯入與維護工具
        'admin' => [
            'audit-logs' => env('MIGRATION_FLAG_ADMIN_AUDIT_LOGS', 'old'),
            'ai-fill-logs' => env('MIGRATION_FLAG_ADMIN_AI_FILL_LOGS', 'old'),
            'explain-sql' => env('MIGRATION_FLAG_ADMIN_EXPLAIN_SQL', 'old'),
            'batch-load-book-titles' => env('MIGRATION_FLAG_BATCH_BOOKS', 'old'),
            'batch-load-offices' => env('MIGRATION_FLAG_BATCH_OFFICES', 'old'),
            'batch-load-social-institutes' => env('MIGRATION_FLAG_BATCH_SOCIAL', 'old'),
            'wiki-maintenance' => env('MIGRATION_FLAG_WIKI_MAINTENANCE', 'old'),
            'cbdb-table-maintenance' => env('MIGRATION_FLAG_TABLE_MAINTENANCE', 'old'),
            'unidirectional-relationship-repair' => env('MIGRATION_FLAG_UNIDIRECTIONAL_REPAIR', 'old'),
        ],

        // query-playground.* 子頁（query-playground 含 hyphen 無 dot，是扁平 key，
        // 其下 nl-query-logs 才以巢狀對應 "query-playground.nl-query-logs"）
        'query-playground' => [
            'nl-query-logs' => env('MIGRATION_FLAG_NL_QUERY_LOGS', 'old'),
        ],

        // View Tables（React 版已上線，待 flip）
        'view' => env('MIGRATION_FLAG_VIEW', 'old'),

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
