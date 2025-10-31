# AGENTS 指南

本文件彙整 AI 代理在此專案工作時必備的背景知識、流程與測試指引，請在開始作業前閱讀並依循。

## 專案速覽
- **技術棧**：Laravel 5.5（PHP 7.x）、MySQL、Blade、Vue 3（透過 `laravel-mix` 編譯）、Bootstrap/AdminLTE。
- **主要資料夾**：
  - `app/Http/Controllers`：Laravel 控制器（如 `OperationsController`）。
  - `app/Repositories`：資料存取與封裝邏輯（例如 `OperationRepository`）。
  - `resources/views`：Blade 模板與各模組頁面。
  - `resources/assets/js`：Vue 元件與前端入口（需跑 `npm run dev` 重新編譯）。
  - `tests/Feature`、`tests/Unit`：PHPUnit 測試。
- **重要設定**：
  - `config/codes.php`：`/codes/*` 白名單。
  - `routes/web.php`：所有 Web 端路由。
  - `phpunit.xml`：測試環境設定（已設定 `SKIP_CSRF_TOKEN=true`，避免 Feature 測試碰到 CSRF）。

## 核心功能備忘
- 官名設定在寫入操作紀錄時會將地址清單（POSTED_TO_ADDR_DATA）以 JSON rows 輸出，方便稽核與還原。 目前這類操作在 Operations 頁面暫停提供一鍵復原。


- `/codes/{table}` 泛用代碼表頁面：`CodesController` 根據表名直接查資料庫，會套用欄位覆寫、搜尋功能。白名單目前已納入 `ADDRESSES`，可直接於 `/codes/ADDRESSES` 檢視原始地址主表資料。
- `/view/{key}` 檢視表頁面：`ViewTableController` 會依 `config/view_tables.php` 設定執行查詢並套用分頁／搜尋；實際 SQL 可透過頁面右上角「顯示 SQL」按鈕檢視，判斷是否符合預期。只有登入使用者能瀏覽該模組。
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
| 編譯前端資源 | `npm run dev`（或 `npm run prod`）|
| 執行完整測試 | `./vendor/bin/phpunit` |
| 執行單一測試 | `./vendor/bin/phpunit --filter TestName` |

> 若修改 Vue/JS，記得在本機跑 `npm run dev` 產出 `public/js/app.js`；專案不會自動編譯。

### 檢視表 ViewTable 模組補充
- 新增或調整檢視請更新 `config/view_tables.php`（欄位標題、描述、每頁筆數、對應 builder）。
- 搜尋欄位維護在 `config/view_table_searchable.php`，欄位名稱需與查詢 builder 中的 alias 對應。
- 查詢邏輯集中在 `app/ViewTables/ViewTableQueries.php`，若要將 SQL 直接換成資料庫 view，也可在 builder 中 `DB::table('YOUR_VIEW')` 取代。
- 頁面會在 modal 中顯示加上 `limit/offset` 的 SQL 及 bindings，除錯時可比對資料庫是否存在同樣結果。
- `View_Address`（地址層級檢視）已註冊於 `/view/Addresses`，列出 `ADDR_CODES` 與 `ADDR_BELONGS_DATA` 組成的五層隸屬結構；若調整 SQL 或欄位，記得同步更新設定檔與側邊欄連結。

## 測試策略
1. **PHPUnit**  
   - 預設使用 SQLite 內存資料庫（若測試自行切換，記得還原）。  
   - Feature 測試已停用 CSRF middleware；若需要真正驗證 CSRF，請額外撰寫整合測試。
2. **範例測試**  
   - `tests/Feature/CodesControllerTest.php`：涵蓋 `/codes/*` 授權、搜尋、操作記錄等行為。
   - `tests/Feature/OperationsRestoreAuthorizeTest.php`：驗證操作復原的授權與記錄流程。
3. **撰寫測試建議**  
   - 覆蓋授權、Side effect（資料變動）、例外情境（資料缺失或查不到）。
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
   - 避免在 Controller 中直接寫 SQL；如需操作資料庫，優先利用 Repository 或 Eloquent。
   - 所有新路由需通過授權檢查，避免只靠前端限制。
5. **文檔更新建議**：
   - 有任何 UI／流程重大調整時，請同步更新 `README.md` 與 `CHANGELOG.md`。
   - 若整理出新的知識或踩坑，務必補充至 `AGENTS.md`，讓後續代理能快速掌握背景。
   - 重要設定或部署注意事項建議集中在專用文件（如 `MERGE.md`、`API.md`）維護。

## 常見坑位
- `resource_id` 可能是複合主鍵並經過特殊編碼（`(slash)`、`minus` 等），還原/比對前需解析。
- Feature 測試手動建立資料表時，記得設置必要的 primary key 與時間戳；否則模型邏輯可能出錯。
- Vue/JS 變更未重新編譯會導致前端顯示舊版本，部署前請確認產物最新。

## 快速回顧
- 需要了解的主要模組：`CodesController`、`OperationsController`、`OperationRepository`、`resources/views/operations/*`。
- 測試重點：保持 PHPUnit 可隨時跑、復原流程需有充分覆蓋。
- UI 調整與變更記錄請同步更新 `CHANGELOG.md`，維持團隊共識。

若遇到未在文件中記載的設定或流程，請再補充至此文，讓後續 AI 代理能更快上手。
