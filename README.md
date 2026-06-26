# cbdb-online-main-server

[中國歷代人物傳在線記录入系统](https://input.cbdb.fas.harvard.edu/)原始碼。

**授權 / License:** [CC BY-NC-SA 4.0 International](https://creativecommons.org/licenses/by-nc-sa/4.0/) (主要授權，詳見 [LICENSE.md](./LICENSE.md))

## 文件導覽（2026 更新）

### 開發指南
* [AGENTS 指南](./AGENTS.md) - AI 代理開發必讀
* [數據庫完整指南](./DATABASE.md) - ⚠️ 必讀：環境、兼容性、Schema 管理
* [Dev Container 使用說明](./.devcontainer/README.md) - VS Code / Codespaces 一鍵啟動開發環境
* [CHANGELOG](./CHANGELOG.md)

### 開發手記
* [升級指南](./docs/UPGRADE.md) - Laravel 框架升級記錄
* [AdminLTE 在 CBDB Online 項目中的使用分析](./docs/ADMINLTE.md)
* [Proposal / Approval Flows](./docs/APPROVAL_FLOWS.md)
* [代碼表格前端實作比較](./docs/CODES.md)
* [代碼表界面白名單維護](./docs/CODES_TABLES.md)
* [代碼表界面效能優化](./docs/CODES_PERFORMANCE.md)
* [Posting/Office 流程備忘](./docs/POSTING_OFFICE.md)
* [Resource TTS 注意事項](./docs/RESOURCE_TTS_NOTES.md)
* [使用者權限說明](./docs/USER_PRIVILEGES.md)
* 若需批次導入資料，請參考 [BATCH_UPLOADERS](./docs/BATCH_UPLOADERS.md)
* [CBDB 公共 API v1 說明](./docs/CBDB_PUBLIC_API_V1.md)
* [單向關係修復工具](./docs/UNIDIRECTIONAL_RELATIONSHIP_REPAIR.md)
* [MERGE 工具說明](./docs/MERGE.md)
* [檢視表總覽](./docs/VIEWS.md)
* [Wiki 导入任务管理](./docs/WIKI_TASK_MANAGEMENT.md)
* [姓名搜索索引管理](./docs/NAME_SEARCH_COMMANDS.md)
* [SQLite 每週同步](#sqlite-每週同步) - 自動匯出並同步到 HuggingFace

### 更多文檔
* [AdminLTE 4 升級可行性](./docs/ADMINLTE4_UPGRADE_FEASIBILITY.md)
* [AI 任官自動填充設計](./docs/AI_POSTING_AUTOFILL_DESIGN.md)
* [API 認證方案](./docs/API_AUTHENTICATION.md)
* [稽核日誌提案](./docs/AUDIT_LOG_PROPOSAL.md)
* [人物提案流程計畫](./docs/BIOGMAIN_APPROVAL_FLOWS_PLAN.md)
* [BiogMain Repository 重構計畫](./docs/BIOGMAIN_REPOSITORY_REFACTOR_PLAN.md)
* [CHGIS 地圖 Place Name 連結與浮出地圖](./docs/CHGIS_MAP_PLACE_LINK.md)
* [複合主鍵 URL 設計](./docs/COMPOSITE_PRIMARY_KEY_URL_DESIGN.md)
* [資料庫 Schema（MySQL/SQLite）](./docs/DATABASE_SCHEMA.md)
* [Laravel Query 審查](./docs/LARAVEL_QUERY_REVIEW.md)
* [MCP HTTP 介面提案](./docs/MCP_HTTP_INTERFACE_PROPOSAL.md)
* [MCP 使用指南](./docs/MCP_USER_GUIDE.md)
* [姓名搜尋效能改進](./docs/NAME_SEARCH_PERFORMANCE_IMPROVEMENT.md)
* [Prometheus 指標說明](./docs/PROMETHEUS_METRICS.md)
* [Schema 文檔生成說明](./docs/SCHEMA_DOCS_GENERATION.md)
* [SQLite 數據發佈流程](./docs/SQLITE_DATA_RELEASE.md)
* [SQLite 遷移計畫](./docs/SQLITE_MIGRATION_PLAN.md)

## 技術環境

### 生產環境
- **PHP**: 8.2+ (最低 8.2.0，建議 8.4)
- **Laravel**: 12.x (已從 11.x 升級，參見 [UPGRADE.md](./docs/UPGRADE.md))
- **數據庫**: MariaDB 10.3.39 (Debian)
- **Web Server**: Caddy
- **Node.js**: 22.x（建議搭配 npm 10）

### 前端構建現況
- 主要互動頁面已遷移至 **React/Inertia 並翻 flag 上線**（人物列表/檢視/詳情中樞、13 個編輯器、Codes、營運管理工具、認證頁、Query Playground 等），React 元件在 `resources/js/inertia/**`，feature flag 見 `config/migration_flags.php`（多為 `new`）。
- **AdminLTE 3** (Bootstrap 4) + Blade 仍實體保留作回退相容期：flag-gated 頁面把對應 flag 改回 `old` 即可回退（可逆）；Query Playground 例外（無主頁 flag、硬導向 React，不走 flag 回退）。尚未實體下架（Phase 7 未執行）。新功能一律只做在 React/Inertia 路徑。
- 構建系統為 **Vite**；主要入口：`resources/js/app.js`（AdminLTE/jQuery UI 組件）、`resources/js/datatables.js`、`resources/js/inertia/**`（React/Inertia）。
- `resources/js/jquery-global.js` 將 jQuery 暴露到全局（供保留中的 Blade 頁使用）。
- 所有頁面均使用 `@vite` 載入前端資源，**請勿引入外部 CDN 的 jQuery/Bootstrap**，以免版本衝突。

⚠️ **重要**：本專案現已升級到 Laravel 12.x 並要求 PHP 8.2+。**建議使用 PHP 8.4** 以獲得最佳性能和安全性。Laravel 12 已完全支持 PHP 8.4。

### 數據庫兼容性原則
⚠️ **重要**：為保持未來遷移到其他數據庫實現的靈活性，請遵循以下原則：
- 避免使用特定數據庫專屬功能（如 MySQL 的 ngram parser、MariaDB 專屬插件）
- 優先使用標準 SQL 語法
- 如需使用數據庫特性，應在代碼中提供降級方案或文檔說明
- 索引策略應基於通用的 B-Tree 或其他跨數據庫支持的類型

### 帳號權限分離（重要）

基線 migration 會觸及完整 schema，為了降低事故風險，**務必**將一般應用連線與 migration 連線分離，避免日常帳號擁有過高權限。

**配置原則**：
- `foo`：一般應用使用者，僅需 CRUD 權限（不含 DROP/ALTER/CREATE）。
- `foo_migrate`：僅供 migration 使用的專用帳號，具備 schema 變更所需權限。

**範例設定（.env）**：
```env
DB_USERNAME=foo
DB_PASSWORD=***

DB_MIGRATE_USERNAME=foo_migrate
DB_MIGRATE_PASSWORD=***
```

> 實際對應欄位請以 `config/database.php` 為準。

## 開發入口

### 常用命令
```bash
composer install
npm install
./vendor/bin/php-cs-fixer fix
./vendor/bin/phpunit
npm run build
php artisan cbdb:fetch-chgis-map
php artisan cbdb:rebuild-person-change-index   # 部署後須跑一次：回填人物層級修改水位線（否則 /api/v2/persons 的 c_modified_date 全為 null）
```

> 部署提醒：`person_change_index`（供 `/api/v2/persons` 的 `c_created_date` / `c_modified_date`）的 migration 只建表不回填。部署到任何環境後須**手動執行一次** `php artisan cbdb:rebuild-person-change-index` 做初始全量回填；之後日常由系統即時維護，並可定期以 `--since` 增量校正。詳見 [docs/PERSON_CHANGE_INDEX_DESIGN.md](docs/PERSON_CHANGE_INDEX_DESIGN.md)。

### 前端
- 主要 React/Inertia 線上路徑：`/app/basicinformation`（人物列表 / 詳情中樞 / 13 個編輯器）、`/app/query-playground`、`/app/codes`、`/app/operations` 等。
- 舊版 Blade 路由多數保留作回退（flag-gated 頁面改 flag 回 `old`）；Query Playground 例外（硬導向 React，無 flag 回退）。皆不再新增功能；新功能一律做在 React/Inertia。
- 前端入口：
  - `resources/js/inertia/**`（React/Inertia，主要互動頁）
  - `resources/js/app.js`、`resources/js/datatables.js`（保留中的 AdminLTE/Blade 頁）

### 後端
- 主要路由定義：`routes/web.php`
- Query Playground / Historical QA：
  - `app/Http/Controllers/QueryPlaygroundController.php`
  - `app/Services/QueryPlaygroundService.php`
  - `app/Services/NaturalLanguageQueryService.php`
- 複合主鍵定義：`app/Support/CompositePrimaryKey.php`

### 重要規則
- 複合主鍵表請使用 Query Builder，不要依賴 Eloquent 主鍵行為
- Migration 必須同時兼容 MariaDB/MySQL 與 SQLite
- 修改 `resources/js/**` 後，提交前請執行 `npm run build`
- commit message、介面文案與文檔使用繁體中文

### 更多說明
- 日常開發請先看 [AGENTS.md](./AGENTS.md)
- 近期變更摘要請看 [CHANGELOG.md](./CHANGELOG.md)
- 資料庫與 migration 規範請看 [DATABASE.md](./DATABASE.md)

## 其他說明

- API 控制器位置：`app/Http/Controllers/Api`
- Windows 本地部署、TLS/SSL 與其他歷史維運說明，請優先查閱 `docs/` 與相關 issue，不再在 README 展開維護。
- 若前端依賴異常，可依序嘗試：

```bash
rm -rf node_modules
npm cache clear --force
npm install
npm run build
```

- 建議使用 Node.js 22 與 npm 10；Ubuntu 可用 `nvm` 安裝。
- 若 `storage/logs/laravel.log` 無法寫入，請檢查 web server 使用者是否擁有該檔案權限。

## SQLite 每週同步

本專案提供自動化腳本，將生產環境的數據庫匯出為 SQLite 格式，並同步到 HuggingFace 公開數據集 [cbdb/cbdb-sqlite](https://huggingface.co/datasets/cbdb/cbdb-sqlite) 供研究者下載使用。

最新下載入口：
- `https://huggingface.co/datasets/cbdb/cbdb-sqlite/resolve/main/latest.zip`
- `https://input.cbdb.fas.harvard.edu/latest.zip`

### 腳本位置

- **匯出腳本**：`scripts/export-daily-sqlite.sh` - 將 MySQL/MariaDB 表格匯出為 SQLite
- **同步腳本**：`scripts/weekly-sqlite-sync.sh` - 匯出、壓縮並上傳到 HuggingFace

### 前置安裝（Ubuntu）

```bash
# 安裝 zip 壓縮工具
sudo apt-get install zip

# 安裝 hf CLI
sudo apt-get install -y pipx
pipx install huggingface-hub
pipx ensurepath
```

### HuggingFace 認證設定

腳本支持兩種認證方式（二擇一）：

```bash
# 方式一：hf auth login（推薦，token 安全存儲於 ~/.cache/huggingface/）
hf auth login

# 方式二：HF_TOKEN 環境變數
export HF_TOKEN=hf_你的token

# 驗證認證狀態
hf auth status
```

**Access Token 設定**：
1. 前往 https://huggingface.co/settings/tokens
2. Create new token → 選擇 Fine-grained
3. 勾選 Repositories → Write 權限
4. 複製 token 並在服務器上執行認證

### Cron 定時任務

```bash
# 編輯 crontab
crontab -e

# 每週日凌晨 3 點執行（GMT+8）
0 3 * * 0 /path/to/cbdb-online-main-server/scripts/weekly-sqlite-sync.sh >> /var/log/cbdb-sqlite-sync.log 2>&1
```

### 手動執行

```bash
# 執行完整同步流程
bash scripts/weekly-sqlite-sync.sh

# 僅匯出 SQLite（不上傳）
bash scripts/export-daily-sqlite.sh
```

### 腳本流程說明

`weekly-sqlite-sync.sh` 執行以下步驟：

1. **前置檢查**：確認 `zip`、`hf` 已安裝且 HuggingFace 認證有效
2. **匯出數據庫**：呼叫 `export-daily-sqlite.sh` 產生 `cbdb_YYYYMMDD.sqlite3` 與 `cbdb_YYYYMMDD.json`
3. **壓縮檔案**：產生 `cbdb_YYYYMMDD.zip`（zip 內使用平面檔名，不包含絕對路徑）
4. **上傳到 HuggingFace**：將 `history/cbdb_YYYYMM/cbdb_YYYYMMDD.zip`、`latest.zip`、`metadata/YYYY-MM/YYYY-MM-DD.json` 及 `latest.json`（metadata 副本）以單一 commit 上傳至數據集倉庫
5. **清理**：刪除所有臨時檔案（包括原始 SQLite 匯出檔與 metadata）
