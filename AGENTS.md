# AGENTS 指南

本文件彙整 AI 代理在此專案工作時必備的背景知識、流程與測試指引，請在開始作業前閱讀並依循。

## 專案速覽
- **技術棧**：Laravel 10.0（PHP 8.1+，建議 8.4）、MariaDB 10.3.39、Blade、Vue 3（透過 `laravel-mix` 編譯）、Bootstrap 3/AdminLTE 2（部分頁面已逐步切換至 AdminLTE 3 CDN 佈局，如 `/codes`）。
- **數據庫環境**：
  - **生產環境**：MariaDB 10.3.39 (Debian)
  - **重要原則**：避免使用特定數據庫專屬功能（如 MySQL 的 ngram parser、MariaDB 專屬插件），以保持未來遷移至其他數據庫實現的可能性
  - **兼容性目標**：代碼應能在標準 SQL 和通用的 MySQL/MariaDB/PostgreSQL 功能上運行
- **內部輔助表**（`CBDB__` 前綴表示內部使用，不直接對終端用戶曝光）：
  - `CBDB__NAME_FTS`：姓名搜尋倒排索引，支援高效能後綴匹配查詢
  - `CBDB__TRAD_SIMP_MAP`：繁簡字符映射，基於 OpenCC 標準，使用 VARBINARY(4) 支援非BMP字符
- **日期/時間處理**：使用 Carbon 2.x。
  - **時區設定**：目前 database 內部分表格欄位出現 datetime 與 timestamp 混用的情況。為避免日期衝突，統一使用 GMT+8 時區。
- **主要資料夾**：
  - `app/Http/Controllers`：Laravel 控制器（如 `OperationsController`）。
  - `app/Repositories`：資料存取與封裝邏輯（如 `OperationRepository`）。
  - `resources/views`：Blade 模板與各模組頁面。
  - `resources/assets/js`：Vue 元件與前端入口（代碼提交前需跑 `npm run prod` 重新編譯）。
  - `tests/Feature`、`tests/Unit`：PHPUnit 測試。
- **重要設定**：
  - `config/codes.php`：`/codes/*` 白名單。
  - `routes/web.php`：所有 Web 端路由。
  - `phpunit.xml`：測試環境設定（已設定 `SKIP_CSRF_TOKEN=true`，避免 Feature 測試碰到 CSRF）。

## 核心功能備忘
- 官名設定在寫入操作紀錄時會將地址清單（POSTED_TO_ADDR_DATA）以 JSON rows 輸出，方便稽核與還原。 目前這類操作在 Operations 頁面暫停提供一鍵復原。

- `/codes/{table}` 泛用代碼表頁面：`CodesController` 根據表名直接查資料庫，會套用欄位覆寫、搜尋功能。白名單目前已納入 `ADDRESSES`、`CBDB__NAME_FTS`（姓名倒排索引）、`CBDB__TRAD_SIMP_MAP`（繁簡映射表），可直接檢視原始資料。內部表（`CBDB__` 前綴）為只讀模式，僅供查詢不可編輯。
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
| 匯入繁簡映射 | `php artisan cbdb:import-trad-simp-map --truncate` |
| 管理用戶 | `php artisan cbdb:manage-user` （交互式）或帶選項創建/更新 |

> 若修改 Vue/JS，記得在本機跑 `npm run prod` 產出 `public/js/app.js`；專案不會自動編譯。

### 用戶管理
- **管理用戶命令**：使用 `php artisan cbdb:manage-user` 創建或更新用戶。適用於環境初始設置和日常用戶管理。
  - **交互式模式**：直接運行 `php artisan cbdb:manage-user`，系統會逐步詢問所需信息。
  - **命令行模式**：使用選項直接指定參數，適合腳本自動化：
    ```bash
    # 創建系統管理員
    php artisan cbdb:manage-user \
      --email=admin@example.com \
      --name="系統管理員" \
      --password=secret123 \
      --active=1 \
      --role=super-admin

    # 更新現有用戶角色
    php artisan cbdb:manage-user --email=user@example.com --role=expert

    # 列出所有用戶
    php artisan cbdb:manage-user --list
    ```
  - **支持的角色**：`regular`（一般）、`expert`（專家）、`crowdsourcing`（眾包）、`super-admin`（系統管理員）
  - **激活狀態**：`0`（未激活）、`1`（激活）、`2`（預留）
- **User Factory**：測試和內部工具可使用 `User::factory()` 創建測試用戶：
  ```php
  // 創建普通用戶
  $user = User::factory()->create();

  // 創建活躍的系統管理員
  $admin = User::factory()->active()->superAdmin()->create();

  // 批量創建用戶
  $users = User::factory()->count(5)->create();

  // 舊語法仍然支持（向後兼容）
  $user = factory(User::class)->create();
  ```

### 內部表維護
- **繁簡映射表**：使用 `php artisan cbdb:import-trad-simp-map --truncate` 從 OpenCC 匯入最新繁簡對照。支援 `--batch=N` 參數調整批次大小（預設 1000）。
- **檢視內部表**：可透過 `/codes/CBDB__NAME_FTS` 和 `/codes/CBDB__TRAD_SIMP_MAP` 檢視表內容（只讀）。
- **项目未用表格**：`ADDRESSES` 表僅供 CBDB Public API 使用。`CBDB_NAME_SEARCH` 表格僅供舊版 CBDB API 姓名搜索功能使用，其功能現已被 `CBDB__NAME_FTS` 替代。

### 檢視表 ViewTable 模組補充
- 新增或調整檢視請更新 `config/view_tables.php`（欄位標題、描述、每頁筆數、對應 builder）。
- 搜尋欄位維護在 `config/view_table_searchable.php`，欄位名稱必須與查詢 builder 中使用的資料表別名一致。
- 查詢邏輯集中在 `app/ViewTables/ViewTableQueries.php`。若需透過資料庫 view，請確保 migration 會建立對應 View_*，否則改以查詢組合實作。
- `/view` 首頁會列出所有檢視；如需逐一查看別名、說明，建議參考 `VIEWS.md`，並保持此文件與設定內容同步。
- 頁面右上角的「顯示 SQL」會呈現實際執行語句及 bindings，可用來對照 `ViewTableQueries` 或資料庫查詢。

## 測試策略
1. **PHPUnit 基本原則**
   - 預設使用 SQLite 內存資料庫（若測試自行切換，記得還原）。
   - Feature 測試已停用 CSRF middleware；若需要真正驗證 CSRF，請額外撰寫整合測試。

2. **In-Memory 數據庫測試模式**（⭐ 推薦標準做法）
   - **遵循現有模式**：參考 `tests/Feature/UserIpLoggingTest.php` 等現有測試
   - **配置方式**：在 `setUp()` 方法中設置：
     ```php
     config()->set('database.default', 'sqlite');
     config()->set('database.connections.sqlite', [
         'driver' => 'sqlite',
         'database' => ':memory:',
         'prefix' => '',
     ]);
     ```
   - **表結構創建**：使用 `Schema::create()` 創建測試所需的最小化表結構
   - **測試數據**：使用 `DB::table()->insert()` 預填充必要的測試數據
   - **隔離性**：每個測試方法都有獨立的內存數據庫，完全隔離
   - **優勢**：快速、可靠、不依賴外部數據庫，CI 環境友好

3. **避免複雜數據庫依賴**
   - ❌ **不要**：依賴完整的 MySQL 數據庫遷移
   - ❌ **不要**：依賴複雜的外鍵約束和大型 schema
   - ✅ **要**：創建測試所需的最小化表結構
   - ✅ **要**：模擬業務邏輯而非數據庫結構

4. **範例測試**
   - `tests/Feature/CodesControllerTest.php`：涵蓋 `/codes/*` 授權、搜尋、操作記錄等行為。
   - `tests/Feature/OperationsRestoreAuthorizeTest.php`：驗證操作復原的授權與記錄流程。
   - `tests/Feature/WikiMaintenanceControllerTest.php`：In-memory SQLite 測試的標準範例

5. **撰寫測試建議**
   - 覆蓋授權、Side effect（資料變動）、例外情境（資料缺失或查不到）。
   - 優先使用 in-memory 數據庫模式，避免外部依賴
   - 測試邏輯而非數據庫結構，保持測試簡潔和可維護
   - 需 mock DB transaction 時，可使用 `DB::swap()` 注入假交易器。

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
  - 目前前端樣式與互動高度依賴 AdminLTE（`resources/assets/sass/app.scss`、`resources/assets/js/bootstrap.js` 持續匯入相關資產）；`/codes` 已改用 `layouts/dashboard-v3`（AdminLTE 3 CDN、未載入 Mix 產物），其他頁面仍是 AdminLTE 2。若要移除舊資產需先規畫替代的樣式框架、重構 Blade 樣板與 JS 初始化流程，並逐頁驗證後才能刪除舊資產，避免界面崩壞。
5. **文檔更新建議**：
   - 有任何 UI／流程重大調整時，請同步更新 `README.md` 與 `CHANGELOG.md`。
   - 若整理出新的知識或踩坑，務必補充至 `AGENTS.md`，讓後續代理能快速掌握背景。
   - 重要設定或部署注意事項建議集中在專用文件（如 `MERGE.md`、`API.md`）維護。
6. **提交規範補充**：所有 Git commit message、必須使用繁體中文敘述。使用者介面使用繁體中文。

## 常見坑位
- `resource_id` 可能是複合主鍵並經過特殊編碼（`(slash)`、`minus` 等），還原/比對前需解析。
- Feature 測試手動建立資料表時，記得設置必要的 primary key 與時間戳；否則模型邏輯可能出錯。
- Vue/JS 變更未重新編譯會導致前端顯示舊版本，部署前請確認產物最新。
- **測試數據庫依賴陷阱**：避免依賴完整 MySQL schema 或複雜遷移文件，這會導致 CI 失敗和測試不穩定。
- **PHPUnit 版本兼容性**：專案使用 PHPUnit 10.1，注意使用相容的斷言方法（如 `assertStringContainsString` 替代舊版 `assertContains`）。
- **用戶模型測試**：記得為 `users` 表的 `confirmation_token` 字段提供值，避免 NOT NULL 約束錯誤。
- **Eloquent 與複合主鍵的限制**：Laravel Eloquent ORM **官方不支持**複合主鍵（composite primary key）。雖然社群有第三方套件（如 `laravel-composite-primary-keys`）提供支援，但引入第三方包會增加維護上的不確定性（套件的長期維護狀態難以保證）。因此，本專案決定對於擁有複合主鍵的表（如 `ALTNAME_DATA` 使用 `c_personid + c_sequence + c_alt_name_chn + c_alt_name_type_code`），直接使用 Query Builder（`DB::table()`）而非建立 Eloquent 模型。若需要類似 Observer 的副作用（如自動索引），應在 Repository 或 Service 層手動調用對應服務（如 `NameSearchIndexService`）。

  使用 Query Builder 的優勢：
  - 官方完整支援，無需依賴第三方套件
  - 程式碼更明確，易於理解和維護
  - 避免 Eloquent 在複合主鍵下的各種問題（`delete()`/`update()` 方法無法正常運作、`getDirty()` 判斷失效等）
  - 手動調用服務比 Observer 更加明確且易於測試

## 快速回顧
- 需要了解的主要模組：`CodesController`、`OperationsController`、`OperationRepository`、`resources/views/operations/*`。
- 測試重點：保持 PHPUnit 可隨時跑、復原流程需有充分覆蓋。
- UI 調整與變更記錄請同步更新 `CHANGELOG.md`，維持團隊共識。

若遇到未在文件中記載的設定或流程，請再補充至此文，讓後續 AI 代理能更快上手。
