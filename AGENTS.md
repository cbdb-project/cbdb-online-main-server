# AGENTS 指南

本文件提供 AI 代理在此專案工作的最小必要背景。目標是快速上手、降低踩坑，不再保留已失效的歷史操作細節。

## 專案現況
- 技術棧：Laravel 12、PHP 8.2+、MariaDB 10.11（prod 實測 10.11.14；相容性下限仍按 10.3 撰寫）、SQLite（測試）、Vite、Vue 3、Inertia/React。
- 全站主要互動頁面已遷移至 **React/Inertia 並翻 flag 上線**（`config/migration_flags.php` 頁面 flag 多為 `new`）：人物列表/檢視/詳情中樞、13 個 React 編輯器（basic-info + 12 個複合主鍵子資源）、Codes CRUD、operations/manage/crowdsourcing、admin 工具、認證頁、Query Playground（`/app/query-playground`）等。
- **舊版 Blade 視圖與 AdminLTE 3 + Bootstrap 4 仍實體保留**：flag-gated 頁面（basicinformation.*、view、codes、operations、manage、crowdsourcing、admin.*、auth.*、welcome 等）把對應 flag 改回 `old` 即可即時回退、不需改碼。少數頁面例外：**Query Playground 無主頁 flag，`/query-playground` 硬導向 `/app/query-playground`（React）、不走 flag 回退；外部資料庫引用瀏覽器亦同，`/external-db-link` 硬導向 `/app/external-db-link`、Blade 版已刪除**。AdminLTE 實體下架（Phase 7）尚未執行。**新功能一律只做在 React/Inertia 路徑（`resources/js/inertia/**`），不要再改舊 Blade。**
- 前端資源由 Vite 載入；React/Inertia 元件在 `resources/js/inertia/`。
- 使用者介面支援繁體中文／英文切換（預設 zh-TW），文件與 commit message 一律使用繁體中文。

## 關鍵原則

### 1. 資料庫相容性
- 所有 migration 必須同時兼容 MariaDB/MySQL 與 SQLite。
- 使用 `is_mysql()` / `is_sqlite()`，不要直接用 `DB::getDriverName()` 判斷。
- 優先使用 Laravel Schema Builder。
- 若必須寫原始 SQL，請移除 SQLite 不支援的語法，例如 `COMMENT`、`ENGINE`、`USING BTREE`。

### 1.1 外鍵一律 RESTRICT（去級聯已完成）
- 全庫 `ON DELETE CASCADE` 已於 2026-08 全數翻為 `RESTRICT`（唯一例外是本來就正確的
  `fk_merged_person_source`＝`SET NULL`）。**新 migration 建立外鍵一律用 `ON DELETE RESTRICT`
  ／`restrictOnDelete()`，禁止 `CASCADE`／`cascadeOnDelete()`**；`ON UPDATE CASCADE` 維持現狀。
- 連帶刪除改由應用層顯式執行（先子後父、父列僅在無剩餘引用時才刪、逐列寫 operations／audit，
  見 `App\Services\ExplicitCascadeLogger`）。刪除路徑撞到 errno 1451 要轉友好報錯，不可吞掉。
- 背景與執行紀錄：[docs/ON_DELETE_CASCADE_RISK.md](./docs/ON_DELETE_CASCADE_RISK.md)、
  [docs/CASCADE_TO_RESTRICT_MIGRATION_NOTES.md](./docs/CASCADE_TO_RESTRICT_MIGRATION_NOTES.md)；
  刪除功能重建設計見 [docs/DELETE_IMPACT_PREVIEW_PLAN.md](./docs/DELETE_IMPACT_PREVIEW_PLAN.md)。

### 2. 複合主鍵
- Laravel Eloquent 不支援複合主鍵。
- 複合主鍵表請使用 Query Builder，不要建立仰賴 Eloquent 主鍵行為的模型。
- 主鍵定義集中在 [app/Support/CompositePrimaryKey.php](./app/Support/CompositePrimaryKey.php)。
- 若修改子資源 API、URL 或 Person Browser `pk`，要同步檢查 `CompositePrimaryKey::SCHEMAS`、控制器與測試。

### 3. Query Playground
- 新功能只做在 React/Inertia 版。
- `/query-playground/*` API 仍為共用後端接口，修改時請同時檢查：
  - [app/Http/Controllers/QueryPlaygroundController.php](./app/Http/Controllers/QueryPlaygroundController.php)
  - [app/Services/QueryPlaygroundService.php](./app/Services/QueryPlaygroundService.php)
  - [app/Services/NaturalLanguageQueryService.php](./app/Services/NaturalLanguageQueryService.php)
  - [app/Services/SqlTableNameExtractor.php](./app/Services/SqlTableNameExtractor.php)
- 與 SQL allowlist、CTE、SSE、NL 工具調用有關的修改，必須補回歸測試。

### 4. 前端
- 所有頁面透過 `@vite` 載入資源。
- 不要重新引入外部 CDN 的 jQuery、Bootstrap、DataTables。
- 修改 `resources/js/**` 後，提交前需重新編譯前端。
- React 列表不要使用 index 當 key；若資料有複合主鍵，請使用穩定 `pk`。
- **必填欄位 create／update 一致**：若某欄位在新增（create）為必填，編輯（update）也必須維持必填——驗證邏輯與 UI 必填標記兩邊都要有，不可只擋 create。否則使用者在 update 時清空該欄仍能儲存，導致原本填好的資料被靜默清空。（驗證放在 `save()` 進入 create／update 分支之前，即可同時涵蓋兩者與 direct／proposal 四條路徑。）
- 頁面級內聯腳本若要對 `#app` 內的伺服器渲染節點綁定事件（`addEventListener`），必須包在 `onViteReady(function(){ ... })` 內。`app.js` 會在 DOM ready 時 `createApp(...).mount('#app')`，把整個 `#app`（layout 的 `<div class="wrapper" id="app">`）重新編譯並重建所有節點，掛載前直接綁定的監聽器會隨舊節點被丟棄而失效。`onViteReady` 的回呼在 mount 之後才執行，可確保綁在最終節點上。委託到 `document`/`window` 的監聽器與內聯 `onclick` 屬性不受影響。

### 6. i18n（繁體中文 / 英文切換）
- 系統預設語言為繁體中文（`zh-TW`），使用者可透過 navbar 切換至英文（`en`）。
- Blade 字串一律使用 `__('group.key')` 翻譯 helper；禁止在 Blade 中硬編碼中文字串。
- 翻譯檔：`resources/lang/zh-TW/*.php` 與 `resources/lang/en/*.php`；兩者必須同步。
- JS 字串透過 `{!! Js::from(__('group.key')) !!}` 注入；React/Inertia 元件從 `usePage().props.locale` 讀取 locale。
- Locale 切換由 `SetLocaleMiddleware`（`web` middleware group）處理，優先順序：session → cookie → Accept-Language。
- 測試環境中 Symfony 預設 `Accept-Language: en-us,en;q=0.5`；`TestCase::setUp()` 已覆蓋為 `zh-TW`，保持測試一致性。若個別測試需要英文語境，請用 `withSession(['locale' => 'en'])`。

### 5. 授權與安全
- 新路由必須有後端授權檢查，不能只靠前端隱藏。
- Query Playground 僅允許只讀 SQL，任何放寬都要先檢查白名單與測試。
- Blade 中若要產生前端 AJAX URL，優先使用相對路徑：`route('name', [], false)`，避免 HTTPS mixed content。

## 目前最常接觸的模組
- Query Playground / Historical QA：
  - [app/Http/Controllers/QueryPlaygroundController.php](./app/Http/Controllers/QueryPlaygroundController.php)
  - [app/Services/NaturalLanguageQueryService.php](./app/Services/NaturalLanguageQueryService.php)
  - [app/Services/Mcp/ReadOnlyTableQueryService.php](./app/Services/Mcp/ReadOnlyTableQueryService.php)
  - [tests/Feature/QueryPlaygroundTest.php](./tests/Feature/QueryPlaygroundTest.php)
  - [tests/Feature/HistoricalQaTest.php](./tests/Feature/HistoricalQaTest.php)
- Person Browser：
  - [app/Services/PersonBrowserService.php](./app/Services/PersonBrowserService.php)
  - [resources/js/inertia/components/PersonBrowser](./resources/js/inertia/components/PersonBrowser)
  - [tests/Feature/PersonBrowserTest.php](./tests/Feature/PersonBrowserTest.php)
- 複合主鍵子資源與 mutation：
  - `app/Http/Controllers/BasicInformation*Controller.php`
  - `app/Services/Mutations/*`
  - `tests/Feature/ApiV2Mutate*Test.php`
- Operations / Restore：
  - [app/Http/Controllers/OperationsController.php](./app/Http/Controllers/OperationsController.php)
  - [app/Repositories/OperationRepository.php](./app/Repositories/OperationRepository.php)
- CHGIS 地圖（Place Name 連結與浮出地圖）：
  - [app/Http/Controllers/ChgisMapController.php](./app/Http/Controllers/ChgisMapController.php)（tile/status/personPoints）
  - [app/Services/PersonMapPointsService.php](./app/Services/PersonMapPointsService.php)、[app/Services/ChgisMapManager.php](./app/Services/ChgisMapManager.php)、[app/Support/CoordinateValidator.php](./app/Support/CoordinateValidator.php)
  - [resources/js/chgis-map](./resources/js/chgis-map)、[config/chgis_map.php](./config/chgis_map.php)
  - 底圖 `storage/app/chgis/chgis_map.mbtiles`（不入版控，`php artisan cbdb:fetch-chgis-map` 下載）；設計見 [docs/CHGIS_MAP_PLACE_LINK.md](./docs/CHGIS_MAP_PLACE_LINK.md)

## 常用命令

```bash
composer install
npm install
./vendor/bin/php-cs-fixer fix
./vendor/bin/phpunit
./vendor/bin/phpunit --filter TestName
npm run build
php artisan cbdb:manage-user
php artisan cbdb:sync-opencc-trad-simp   # 更新 vendored third_party/opencc/TSCharacters.txt，需 git diff 審查後提交
php artisan cbdb:fetch-chgis-map        # 下載 CHGIS 底圖（缺檔才下載）
```

## 提交前最低檢查
- `git diff` 只包含預期改動。
- PHP 代碼已格式化：`./vendor/bin/php-cs-fixer fix`
- 跑過受影響測試；若改動面大，跑 `./vendor/bin/phpunit`
- 改了前端資源時，跑 `npm run build`
- commit message 使用繁體中文

## 高風險區域備忘
- `ALTNAME_DATA` 現行主鍵是 3-key，不含 `c_sequence`。
- `POSTED_TO_ADDR_DATA` 的 `resource_id` 會沿用 `POSTED_TO_OFFICE_DATA` 格式，地址明細存於 `resource_data['rows']`。
- 與時間欄位有關的修改，請注意 `DB_TIMEZONE` 必須與 `APP_TIMEZONE` 對齊；資料庫時區使用數字偏移，例如 `+08:00`。
- 若測試需自行建表，請補齊必要主鍵、nullable、timestamps；很多回歸都來自測試表結構過度簡化。
- `app/codes/{table_name}`（`CodesController@appShow`）帶 `sort_by`／`filters[...]` 時需登入且 `Auth::user()->isActive()`（見 `guardSortFilterRequiresAuth()`）；**Blade 版 `codes/{table_name}`（`show()`）未同步處理**，若把 `codes` migration flag 切回 `old` 會重新暴露無門檻的深分頁排序查詢，需重新評估。詳見 [docs/CODES_SORT_FILTER_AUTH_GATE.md](./docs/CODES_SORT_FILTER_AUTH_GATE.md)。

## 文檔維護原則
- `AGENTS.md` 只保留目前有效的規則與入口，不記錄已淘汰的歷史流程。
- `CHANGELOG.md` 只記錄近階段高價值變更與方向，不再維護超長流水帳。
- 重大 UI / 流程變更時，同步更新 `README.md`、`CHANGELOG.md`。

## 相關文檔
- [README.md](./README.md)
- [CHANGELOG.md](./CHANGELOG.md)
- [DATABASE.md](./DATABASE.md)
- [docs/APPROVAL_FLOWS.md](./docs/APPROVAL_FLOWS.md)
- [docs/PERSON_PROPOSAL_PATHS.md](./docs/PERSON_PROPOSAL_PATHS.md)（人物提案：三條核准路徑與逐資源現況；改動核准行為前必讀）
- [docs/ZHWIKI_SOURCE_SYNC.md](./docs/ZHWIKI_SOURCE_SYNC.md)（中文維基連結增量維護：走 mutation API，勿用 WikiMaintenanceController 全量重灌）
- [docs/VIEWS.md](./docs/VIEWS.md)
- [docs/POSTING_OFFICE.md](./docs/POSTING_OFFICE.md)
- [docs/COMPOSITE_PRIMARY_KEY_URL_DESIGN.md](./docs/COMPOSITE_PRIMARY_KEY_URL_DESIGN.md)

## AI / Claude skills
- `.claude/skills/database-schema.md`
- `.claude/skills/mutation-api-record-editing.md`
- `.claude/skills/migration-guide.md`
- `.claude/skills/pre-commit-checks.md`
- `.claude/skills/testing-guide.md`
- `.claude/skills/commit-messages/SKILL.md`
- `.claude/skills/frontend-event-handler-debugging.md`
