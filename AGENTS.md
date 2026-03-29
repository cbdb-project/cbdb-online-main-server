# AGENTS 指南

本文件提供 AI 代理在此專案工作的最小必要背景。目標是快速上手、降低踩坑，不再保留已失效的歷史操作細節。

## 專案現況
- 技術棧：Laravel 12、PHP 8.2+、MariaDB 10.3、SQLite（測試）、Vite、Vue 3、Inertia/React。
- 版面系統：全站使用 AdminLTE 3 + Bootstrap 4，前端資源由 Vite 載入。
- 現行 Query Playground 主路徑是 React/Inertia 版本：`/app/query-playground`。
- 舊版 Blade Query Playground 仍在相容期內，但不應再新增功能；新功能請只做在 React/Inertia 路徑。
- 使用者介面、文件與 commit message 一律使用繁體中文。

## 關鍵原則

### 1. 資料庫相容性
- 所有 migration 必須同時兼容 MariaDB/MySQL 與 SQLite。
- 使用 `is_mysql()` / `is_sqlite()`，不要直接用 `DB::getDriverName()` 判斷。
- 優先使用 Laravel Schema Builder。
- 若必須寫原始 SQL，請移除 SQLite 不支援的語法，例如 `COMMENT`、`ENGINE`、`USING BTREE`。

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

## 常用命令

```bash
composer install
npm install
./vendor/bin/php-cs-fixer fix
./vendor/bin/phpunit
./vendor/bin/phpunit --filter TestName
npm run build
php artisan cbdb:manage-user
php artisan cbdb:import-trad-simp-map --truncate
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

## 文檔維護原則
- `AGENTS.md` 只保留目前有效的規則與入口，不記錄已淘汰的歷史流程。
- `CHANGELOG.md` 只記錄近階段高價值變更與方向，不再維護超長流水帳。
- 重大 UI / 流程變更時，同步更新 `README.md`、`CHANGELOG.md`。

## 相關文檔
- [README.md](./README.md)
- [CHANGELOG.md](./CHANGELOG.md)
- [DATABASE.md](./DATABASE.md)
- [docs/APPROVAL_FLOWS.md](./docs/APPROVAL_FLOWS.md)
- [docs/VIEWS.md](./docs/VIEWS.md)
- [docs/POSTING_OFFICE.md](./docs/POSTING_OFFICE.md)
- [docs/COMPOSITE_PRIMARY_KEY_URL_DESIGN.md](./docs/COMPOSITE_PRIMARY_KEY_URL_DESIGN.md)

## AI / Claude skills
- `.claude/skills/database-schema.md`
- `.claude/skills/migration-guide.md`
- `.claude/skills/pre-commit-checks.md`
- `.claude/skills/testing-guide.md`
- `.claude/skills/commit-messages/SKILL.md`
