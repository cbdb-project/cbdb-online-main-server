# AGENTS 指南

本文件彙整 AI 代理在此專案工作時必備的背景知識、流程與測試指引，請在開始作業前閱讀並依循。

## 專案速覽
- **技術棧**：Laravel 10.0（PHP 8.1+，建議 8.4）、MariaDB 10.3.39、Blade、Vue 3。前端已完成 **AdminLTE 3** (Bootstrap 4) 升級，使用 **Vite** 構建系統。所有頁面均透過 `layouts/dashboard-v3.blade.php` 佈局，使用 Vite 載入前端資源（`resources/js/jquery-global.js` 將 jQuery 暴露到全局，Bootstrap 4、AdminLTE 3、Select2 等在 `app.js` 中實現）。
- **數據庫環境**：
  - **生產環境**：MariaDB 10.3.39 (Debian)
  - **重要原則**：避免使用特定數據庫專屬功能（如 MySQL 的 ngram parser、MariaDB 專屬插件），以保持未來遷移至其他數據庫實現的可能性
  - **兼容性目標**：代碼應能在標準 SQL 和通用的 MySQL/MariaDB/PostgreSQL 功能上運行
- **內部輔助表**（`CBDB__` 前綴表示內部使用，不直接對終端用戶曝光）：
  - `CBDB__NAME_FTS`：姓名搜尋倒排索引，支援高效能後綴匹配查詢
  - `CBDB__TRAD_SIMP_MAP`：繁簡字符映射，基於 OpenCC 標準，使用 VARBINARY(4) 支援非BMP字符
- **日期/時間處理**：使用 Carbon 2.x。
  - **時區設定**：統一使用 GMT+8 時區（Asia/Shanghai）。
  - **CRITICAL 配置要求**：`.env` 中的 `DB_TIMEZONE` **必須**與 `config/app.php` 的 `timezone` 匹配！
    - 正確設定：`DB_TIMEZONE=+08:00`（對應 `APP_TIMEZONE=Asia/Shanghai`）
    - **必須使用數字偏移格式**（如 `+08:00`），不可使用命名時區（如 `Asia/Shanghai`）
    - 原因：MySQL 命名時區需要載入 time zone tables，否則會導致 "Unknown or incorrect time zone" 錯誤並中斷所有資料庫連線
    - 如果不匹配：MySQL TIMESTAMP 欄位會產生 8 小時時區偏移（字串看起來相同，但底層 UNIX timestamp 錯誤）
    - 詳細說明請參考 `app/Repositories/ToolsRepository.php` 中的註釋
- **主要資料夾**：
  - `app/Http/Controllers`：Laravel 控制器（如 `OperationsController`）。
  - `app/Repositories`：資料存取與封裝邏輯（如 `OperationRepository`）。
  - `resources/views`：Blade 模板與各模組頁面。
  - `resources/js`：Vue 元件與前端入口（使用 Vite 構建，代碼提交前需跑 `npm run build`）。
  - `tests/Feature`、`tests/Unit`：PHPUnit 測試。
- **重要設定**：
  - `config/codes.php`：`/codes/*` 白名單。
  - `routes/web.php`：所有 Web 端路由。
  - `phpunit.xml`：測試環境設定（已設定 `SKIP_CSRF_TOKEN=true`，避免 Feature 測試碰到 CSRF）。

## 代碼風格規範

本專案使用 **PHP-CS-Fixer** 自動化代碼格式化工具，配置文件為 `.php-cs-fixer.dist.php`。所有代碼提交前必須運行格式化工具以確保風格一致性。

### 核心規範

**基礎標準**：遵循 PSR-12 標準，並進行以下覆蓋調整：

**大括號位置**（重要！）：
- ✅ **函數/方法**：開括號與聲明在同一行
  ```php
  public function example() {
      // code
  }
  ```
- ✅ **類**：開括號與類名在同一行
  ```php
  class Example {
      // code
  }
  ```
- ✅ **控制結構**：開括號與條件在同一行
  ```php
  if ($condition) {
      // code
  }

  foreach ($items as $item) {
      // code
  }
  ```

**其他重要規範**：
- ✅ **陣列語法**：使用短陣列語法 `[]`，不使用 `array()`
- ✅ **導入排序**：`use` 語句按字母順序排列
- ✅ **多行陣列**：結尾必須有逗號
- ✅ **返回類型聲明**：PHP 7.0+ 函數應使用返回類型聲明（如 `: void`、`: array`）

### 格式化命令

```bash
# 格式化所有代碼（提交前必須執行）
./vendor/bin/php-cs-fixer fix

# 預覽將要修改的文件（不實際修改）
./vendor/bin/php-cs-fixer fix --dry-run --diff
```

### 覆蓋範圍

格式化工具會自動處理以下目錄：
- `app/` - 應用程序邏輯
- `config/` - 配置文件
- `database/` - 資料庫遷移和填充
- `routes/` - 路由定義
- `tests/` - 測試文件

**注意**：Blade 模板（`*.blade.php`）會自動排除，不進行格式化。

### 文檔代碼範例規範

在撰寫或更新文檔時，所有 PHP 代碼範例也應遵循項目的 same_line 大括號風格，確保文檔與實際代碼風格一致。這包括：
- `AGENTS.md`、`DATABASE.md` 等核心文檔
- `.claude/skills/` 目錄下的所有 skill 文件
- `README.md` 和其他項目文檔

## 核心功能備忘
- 官名設定在寫入操作紀錄時會將地址清單（POSTED_TO_ADDR_DATA）以 JSON rows 輸出，方便稽核與還原。 目前這類操作在 Operations 頁面暫停提供一鍵復原。

- `/codes/{table}` 泛用代碼表頁面：`CodesController` 根據表名直接查資料庫，會套用欄位覆寫、搜尋功能。白名單配置於 `config/codes.php`，包含所有主要代碼表及資料表，詳細清單請參考 `CODES_TABLES.md`。內部表（`CBDB__` 前綴）為只讀模式，僅供查詢不可編輯。
- `/view/{key}` 檢視表頁面：`ViewTableController` 會依 `config/view_tables.php` 設定執行查詢並套用分頁／搜尋；實際 SQL 可透過頁面右上角「顯示 SQL」按鈕檢視，判斷是否符合預期。只有登入使用者能瀏覽該模組。
- `/view` 提供檢視表總覽，列表資料源自 `config/view_tables.php` 並會依 `View_*` 名稱排序；如需檢視完整清單與說明可參考 `VIEWS.md`。
- 管理工具 `/admin/explainsql`：僅限活躍管理員，可輸入 SELECT / WITH 語句並查看 MySQL `EXPLAIN` 計畫，輸出表格供調校索引或查詢效能。
- 操作紀錄（`operations` 表）：透過 `OperationRepository::store()` 統一寫入，欄位 `op_type` 代表新增/覆寫/修改/刪除。
  - 任官（`POSTING_DATA`、`POSTED_TO_OFFICE_DATA`、`POSTED_TO_ADDR_DATA`）相關的新增、更新、刪除已全面改成由 `BiogMainRepository::officeStoreById()`／`officeUpdateById()`／`officeDeleteById()` 以資料庫交易處理：先鎖定/寫入主表，再同步管理地址清單並一次寫入操作紀錄。請勿在 Controller 直接操作 `DB::table()`。
  - `officeUpdateById()` 只有在實際欄位變動時才對 `POSTED_TO_OFFICE_DATA` 寫入 timestamp，純地址異動會在同一交易中只更新 `POSTED_TO_ADDR_DATA`，操作紀錄也會對應保留 before/after JSON。測試這段流程時務必透過 `actingAs()` 提供使用者資訊，避免 `ToolsRepository::timestamp()` 取不到登入者姓名。
- **操作復原**：
  - 僅活躍管理員 (`is_active == 1` 且 `is_admin == 1`) 可觸發 `OperationsController::restore()`。
  - 還原成功後，系統會以執行者的身份新增一筆 `op_type = 3` 記錄，內容反映復原後的資料及前一版值。
  - UI 按鈕文字為「復原」，提示訊息：「將以你的名義對該資源進行一次修改，恢復至本次改動之前，是否繼續？」。
- `POSTED_TO_ADDR_DATA` 操作紀錄的 `resource_id`（列表上標示為「資源tts」）會沿用對應 `POSTED_TO_OFFICE_DATA` 的 `{c_office_id}-{c_posting_id}` 值，以共用 `/basicinformation/{personid}/offices/{pk}/edit` 編輯介面；實際資料表主鍵為 `c_personid`、`c_posting_id`、`c_office_id` 三欄，完整變更內容紀錄在 `resource_data['rows']` 中。

## 常用命令

| 操作 | 指令 |
|------|------|
| 安裝 PHP 依賴 | `composer install` |
| 安裝前端依賴 | `npm install` |
| 編譯前端資源 | `npm run prod`（或 `npm run dev` 用於調試）|
| 執行完整測試 | `./vendor/bin/phpunit` |
| 執行單一測試 | `./vendor/bin/phpunit --filter TestName` |
| 代碼格式化 | `./vendor/bin/php-cs-fixer fix` |
| 匯入繁簡映射 | `php artisan cbdb:import-trad-simp-map --truncate` |
| 管理用戶 | `php artisan cbdb:manage-user` |

> 若修改 Vue/JS，記得在本機跑 `npm run prod` 產出 `public/js/app.js`；專案不會自動編譯。
> 詳細的用戶管理、測試、數據庫 schema 查詢等操作指南，請參考 [AI 代理專用 Skills](#ai-代理專用-skills)。

## 檢視表 ViewTable 模組補充
- 新增或調整檢視請更新 `config/view_tables.php`（欄位標題、描述、每頁筆數、對應 builder）。
- 搜尋欄位維護在 `config/view_table_searchable.php`，欄位名稱必須與查詢 builder 中使用的資料表別名一致。
- 查詢邏輯集中在 `app/ViewTables/ViewTableQueries.php`。若需透過資料庫 view，請確保 migration 會建立對應 View_*，否則改以查詢組合實作。
- `/view` 首頁會列出所有檢視；如需逐一查看別名、說明，建議參考 `VIEWS.md`，並保持此文件與設定內容同步。
- 頁面右上角的「顯示 SQL」會呈現實際執行語句及 bindings，可用來對照 `ViewTableQueries` 或資料庫查詢。

## AI 代理專用 Skills

本專案在 `.claude/skills/` 目錄下提供了專門的技能指南，供 AI 代理（如 Claude Code）在特定場景下參考使用。這些 skills 整合了專案的最佳實踐和規範，可提高工作效率並確保代碼質量。

### 可用的 Skills

#### 1. **database-schema.md** - 數據庫表格 Schema 查詢與維護
**何時使用**：需要了解數據庫表格結構、字段類型、索引，或維護內部表格時。

**主要內容**：
- 從 `database/migrations/2025_01_01_*` 找到 baseline migration
- 使用 `grep` 搜索表格的後續修改
- 使用 `php artisan tinker` 驗證 schema 並查看示例數據
- 複合主鍵表格的特殊處理說明
- 內部輔助表（`CBDB__` 前綴）的識別和維護
- 繁簡映射表的匯入和更新

#### 2. **pre-commit-checks.md** - 代碼提交前檢查規範
**何時使用**：在提交任何代碼變更到 Git 之前（必須執行）。

**必要檢查項目**：
- ✅ 代碼格式化：`./vendor/bin/php-cs-fixer fix`
- ✅ 測試驗證：`./vendor/bin/phpunit`（必須全部通過）
- ✅ 前端編譯：`npm run prod`（如修改了 Vue/JS/SCSS）
- ✅ 提交信息使用繁體中文

#### 3. **user-management.md** - 用戶管理操作指南
**何時使用**：需要創建、更新用戶帳號，或在測試中使用 User Factory 時。

**主要內容**：
- `php artisan cbdb:manage-user` 命令的交互式和命令行模式
- 支持的角色：`regular`、`expert`、`crowdsourcing`、`super-admin`
- User Factory 在測試中的使用方法
- Feature 測試中的用戶認證設置
- 常見用戶管理場景和最佳實踐

#### 4. **testing-guide.md** - PHPUnit 測試編寫指南
**何時使用**：編寫或運行測試時，了解項目的測試策略和最佳實踐。

**主要內容**：
- PHPUnit 基本原則和運行方法
- ⭐ In-Memory 數據庫測試模式（推薦標準做法）
- 避免複雜數據庫依賴的策略
- 測試編寫建議（授權、Side effect、例外情境）
- 常見測試場景範例（Controller、Repository）
- 測試數據庫常見陷阱和解決方案
- PHPUnit 10.1 版本兼容性說明

#### 5. **migration-guide.md** - Laravel Migration 編寫指南
**何時使用**：需要創建或修改數據庫結構時。

**主要內容**：
- Migration 編寫核心原則（數據庫兼容性）
- 創建新表和修改現有表的完整模板
- 複合主鍵表的 Migration 處理
- 常用字段類型和索引策略
- 避免的模式（數據庫專屬功能）
- Migration 檢查清單
- 常見錯誤和解決方案

### 如何使用 Skills

- **AI 代理**：在需要時直接讀取對應的 skill 文件（如 `.claude/skills/database-schema.md`）
- **開發者**：可以作為快速參考指南查閱
- **擴展**：如發現新的通用場景，可以添加新的 skill 文件並更新此列表

> **注意**：Skills 的內容會隨專案演進持續更新，請以 `.claude/skills/` 目錄中的最新版本為準。

## 迭代流程與守則
1. **變更前**：檢查 `git status`，確認工作樹是否乾淨；若不乾淨，先理解現存修改，避免誤刪。
2. **實作順序建議**：
   - 閱讀相關 Controller/Repository/Blade 了解資料流。
   - 補齊或更新測試（先寫測試再改程式碼可降低回歸）。
   - 實作功能後重新跑受影響的測試（至少 `phpunit` 與必要的 lint/編譯）。
3. **提交前檢查**：
   - `git diff` 確認只包含預期改動。
   - `./vendor/bin/phpunit` 或相應子集測試需為綠燈。
   - 若改了前端資源，記得提交編譯後檔案（除非專案流程另有規範）。
4. **開發注意事項**：
   - 嚴禁在未授權情況下直接修改資料庫結構或大量資料。
   - 避免在 Controller 中直接寫 SQL；如需操作資料庫，優先利用 Repository。對於**單一主鍵**的表可以使用 Eloquent 模型，但對於**複合主鍵**的表（如 `ALTNAME_DATA`、`POSTED_TO_ADDR_DATA` 等）必須使用 Query Builder（`DB::table()`）而非 Eloquent 模型。
   - 所有新路由需通過授權檢查，避免只靠前端限制。
  - 全站已完成 AdminLTE 3 升級，所有頁面使用 `layouts/dashboard-v3.blade.php` 佈局，透過 Vite 載入前端資源（入口位於 `resources/js/`）。請勿引入外部 CDN 的 jQuery/Bootstrap，以免與 Vite bundle 產生版本衝突。Laravel Mix 時期的 `resources/assets/` 目錄及相關檔案已完全移除。
5. **文檔更新建議**：
   - 有任何 UI／流程重大調整時，請同步更新 `README.md` 與 `CHANGELOG.md`。
   - 若整理出新的知識或踩坑，務必補充至 `AGENTS.md`，讓後續代理能快速掌握背景。
   - 重要設定或部署注意事項建議集中在專用文件（如 `MERGE.md`、`API.md`）維護。
6. **提交規範補充**：所有 Git commit message、必須使用繁體中文敘述。使用者介面使用繁體中文。

## 常見坑位
- `resource_id` 可能是複合主鍵並經過特殊編碼（`(slash)`、`minus` 等），還原/比對前需解析。
- Feature 測試手動建立資料表時，記得設置必要的 primary key 與時間戳；否則模型邏輯可能出錯。
- Vue/JS 變更未重新編譯會導致前端顯示舊版本，部署前請確認產物最新。
- dashboard-v3 佈局改用 Vite 打包（`@vite` 載入），共用 `resources/js/jquery-global.js` 並內建 Bootstrap 4 bundle；不要再引用外部 CDN 的 jQuery/Bootstrap/Datatables 以免載入順序衝突。modal 關閉的焦點修復也在 Vite 入口內，全站共用。
- **測試數據庫依賴陷阱**：避免依賴完整 MySQL schema 或複雜遷移文件，這會導致 CI 失敗和測試不穩定。
- **PHPUnit 版本兼容性**：專案使用 PHPUnit 10.1，注意使用相容的斷言方法（如 `assertStringContainsString` 替代舊版 `assertContains`）。
- **用戶模型測試**：記得為 `users` 表的 `confirmation_token` 字段提供值，避免 NOT NULL 約束錯誤。
- **Eloquent 與複合主鍵的限制**：Laravel Eloquent ORM **官方不支持**複合主鍵（composite primary key）。雖然社群有第三方套件（如 `laravel-composite-primary-keys`）提供支援，但引入第三方包會增加維護上的不確定性（套件的長期維護狀態難以保證）。因此，本專案決定對於擁有複合主鍵的表（如 `ALTNAME_DATA` 使用 `c_personid + c_sequence + c_alt_name_chn + c_alt_name_type_code`），直接使用 Query Builder（`DB::table()`）而非建立 Eloquent 模型。若需要類似 Observer 的副作用（如自動索引），應在 Repository 或 Service 層手動調用對應服務（如 `NameSearchIndexService`）。

  使用 Query Builder 的優勢：
  - 官方完整支援，無需依賴第三方套件
  - 程式碼更明確，易於理解和維護
  - 避免 Eloquent 在複合主鍵下的各種問題（`delete()`/`update()` 方法無法正常運作、`getDirty()` 判斷失效等）
  - 手動調用服務比 Observer 更加明確且易於測試

- **HTTPS 混合內容問題**：在 Blade 模板中使用 `route()` 函數生成 URL 時，預設會產生**絕對 URL**（包含協議和域名，如 `http://localhost/api/endpoint`）。當應用程式透過 Cloudflare 等反向代理以 HTTPS 提供服務時，會導致瀏覽器阻擋混合內容（Mixed Content）錯誤。
  
  **解決方案**：使用 `route()` 的第三個參數設為 `false` 來生成**相對 URL**：
  ```php
  // ❌ 錯誤：會生成 http://localhost/query-playground/run
  url: "{{ route('query-playground.run') }}"
  
  // ✅ 正確：會生成 /query-playground/run
  url: "{{ route('query-playground.run', [], false) }}"
  ```
  
  參考範例：`resources/views/profile/edit.blade.php` 中的 API token 相關 AJAX 請求。

## 快速回顧
- 需要了解的主要模組：`CodesController`、`OperationsController`、`OperationRepository`、`resources/views/operations/*`。
- 測試重點：保持 PHPUnit 可隨時跑、復原流程需有充分覆蓋。
- UI 調整與變更記錄請同步更新 `CHANGELOG.md`，維持團隊共識。

若遇到未在文件中記載的設定或流程，請再補充至此文，讓後續 AI 代理能更快上手。
