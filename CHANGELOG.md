# Changelog

## 技術棧升級 (2025-11)
- 詳見 `UPGRADE.md` 以了解完整升級經歷。
  - Laravel: 5.5 -> 5.6 -> 5.7 -> 5.8 -> 6 -> 7 -> 8 -> 10
  - PHP: 7.4 -> 8.4
  - **PHP 最低版本要求提升至 8.2+**（#687，因依賴 `phpmyadmin/sql-parser` v6.0）

## 最新更新 (2025-12)

### 技術棧與架構全面升級

#### 前端框架現代化
- **AdminLTE 2 → AdminLTE 3 完整遷移**：全站 UI 升級至 AdminLTE 3 (Bootstrap 4)，引入現代化 UI 模式
  - 新增 **Dark Mode** 支持：完整的深色模式切換功能，自動適配用戶偏好
  - 移動端響應式設計全面優化：修復多處小屏幕設備顯示問題
  - 統一組件庫：整合 basicinformation、管理員頁面等核心模組
- **Laravel Mix → Vite 構建系統**：完全移除 Mix，採用 Vite 實現更快的構建速度
  - Runtime Segmentation 策略優化前端資源加載和緩存
  - 清理 `resources/assets` 舊目錄，統一組件位置
  - 自動化部署流程，構建產物不再入庫

#### API 認證系統重構
- **Laravel Passport → Sanctum**：簡化 API 認證流程，減少依賴複雜度
- **API Token 管理改進**：優化 `/profile` 頁面的 Token 管理界面
- **前端調用優化**：
  - 移除硬編碼 Bearer token，改善安全性
  - Select 組件全局緩存機制，減少重複請求
  - FTS 索引加速人物搜索性能

#### 數據庫架構優化
- **權限分離與安全加固**：Migration 專用連線與一般應用帳號分離，降低安全風險
- **時間戳欄位統一重構**：完成全站時間戳管理的標準化
- **Migration 兼容性改進**：完善 SQLite 支援、外鍵處理和歷史遷移相容性
- **數據清理**：移除未使用的舊版 ID 欄位和 `View_Address` 視圖

### 核心功能增強

#### 智能查詢與數據探索
- **自然語言轉 SQL 查詢系統**
  - 集成 Google Gemini API，支持用戶以自然語言描述查詢需求
  - OpenAI 兼容 API 架構，提高第三方服務切換靈活性
  - 用戶同意機制：透明展示數據收集和 AI 模型使用情況
  - 管理員日誌界面：監控查詢使用、Token 消耗和模型性能
- **SQL 查詢練習場**
  - 交互式查詢環境，支持專家級 SQL 操作
  - SQL Parser 防止白名單繞過攻擊，確保安全性
  - SQL 自動格式化功能，支持複雜子查詢
  - 表名白名單機制，擴充可查詢數據表範圍
- **按入仕查詢功能**
  - 仿照 CBDB Access 數據庫的層級查詢功能
  - 三欄式布局：類型樹狀列表、代碼多選、搜索條件表單
  - 支持 URL 分享查詢結果

#### 年號與時間處理系統
- **年號轉換完整方案**
  - 年號 ↔ 西元年雙向轉換，支持朝代代碼精確過濾
  - 智能消歧機制：處理同名年號，提供年份範圍匹配和降級方案
  - API 緩存優化，避免重複請求
  - 移除朝代強制選擇限制，改為輔助判斷
- **時間欄位全面優化**
  - 改進日期驗證邏輯和排版布局
  - 統一時間格式化處理，提升載入穩定性
  - 更新 create/modify 欄位時間寫入方式

#### 用戶體驗提升

##### 視覺與交互改進
- **用戶頭像系統**
  - 18 個精美預設頭像 + CBDB Logo 默認頭像
  - 橫向滾動選擇器，支持深色模式自動適配
- **可復用組件庫**
  - Person ID 顯示組件：統一展示人物信息（ID、姓名、朝代）
  - Audit Fields 組件：標準化建檔/更新信息字段
  - 應用於 basicinformation 及其 12 個子頁面
- **UI/UX 細節優化**
  - 基本資訊導航支持自動換行
  - 出處表單布爾字段改為復選框
  - Select2 初始化方法統一
  - 導航標籤字體增大，改善可讀性

##### 移動端體驗
- 修復日期選擇器「日(干支)」標籤溢出問題
- 分頁欄自動換行，適配不同屏幕尺寸
- Sidebar 移動端行為優化

##### 數據展示改進
- **基本資料頁面重構**
  - `/basicinformation/{id}` 從 JSON 輸出改為格式化只讀視圖
  - 訪問控制：未授權用戶自動重定向到只讀頁面
  - SEO 優化：編輯頁面 noindex，只讀頁面作為公開入口
- **關係表功能擴展**
  - 七個 `*_CODE_TYPE_REL` 關係表支持 JOIN 查詢
  - 代碼自動轉換為中文名稱
- **登錄流程改進**
  - 登錄後正確重定向到原始訪問頁面（使用 `redirect()->intended()`）

### 開發工具與維護

#### 用戶管理
- 管理頁面重構：移除 DataTables，改用 Laravel 服務端分頁
- cbdb:manage-user 工具修復：正確設置 is_active 欄位

#### Docker 開發環境
- SQLite 支持增強：完善 db:export-to-sqlite 命令
- 開發環境優化：添加 Node.js、數據庫持久化、信號處理
- 初始化腳本：完整資料庫內容快速部署

#### 代碼質量
- Model 文件統一遷移至 `app/Models/` 目錄
- 表單驗證樣式統一為 Bootstrap 4 標準
- Kernel 中間件配置升級為 Laravel 11 標準
- 測試環境強制使用 in-memory SQLite，避免誤觸外部資料庫
- 清理死代碼路由和遺留配置

### 問題修復與細節完善

#### 數據庫查詢與數據處理
- 修正親屬關係查詢中的 `orWhere` 分組問題
- 改進 TEXT_CODES 作者列表顯示
- 修復成對親屬關係和社會關係下拉框顯示問題
- CODES 表特殊字符處理，改善 URL 編碼

#### 組件與表單修復
- Select.vue 組件 prop 和 method 命名衝突解決
- Select2 寬度自動縮放修復
- 提案狀態顯示準確性提升

#### 測試與兼容性
- UserProfileTest 測試環境路由問題修復
- API 響應處理改進，提高兼容性
- 代碼清理：移除死代碼路由和遺留配置

---

### 相關 Pull Requests 參考
本次更新涵蓋 #593-#702 約 110 個 PR/commits，主要包括：
- 技術棧升級：#593, #597, #601, #603, #604, #607, #609, #610, #613, #622, #628, #630
- 智能查詢功能：#644, #671, #677, #678, #683, #687
- 年號轉換系統：#668, #679, #680, #685, #696
- UI/UX 改進：#670, #673, #674, #676, #684, #694
- 數據庫優化：#647, #648, #649, #653, #654, #655, #698, #701
- 開發工具：#639, #650, #660, #661, #664
- 問題修復：#602, #634, #637, #640, #652, #669, #686, #689, #700, #702

## 新增功能 (2025)

### 使用者體驗調整 
- 強化基本信息首頁與資料檢視頁在手機／平板上的表格瀏覽體驗：
  - `NameList`、`AddrBelongsDataList`、`TextInstanceDataList` 加入 `table-scroll-x` 包裝，縮小螢幕時可橫向滑動，並集中樣式於 `styles.css` 管理。
  - `/view/{key}` 檢視表新增水平捲動與欄寬限制，防止欄位過多時被擠壓，同步停用左右滑動觸發的瀏覽器返回／前進手勢。
- Basicinformation 及其 12 個子頁面（addresses、altnames、assoc、entries、events、kinship、offices、possession、socialinst、sources、statuses、texts）之列表新增 `.table-responsive`，改善窄螢幕上的橫向捲動體驗。
- Operations / Modified 頁面套用 `.table-responsive`，按鈕文字調整為「內容快照」與「比較」，相關 Bootstrap Modal 皆設 `tabindex="-1"` 以支援 `ESC` 關閉；Crowdsourcing 頁面則加入 `.table-responsive` 與 `tabindex="-1"`。
- 對特定表格，前後端增加了拒絕「非實質性修改」的功能。目前僅對 BIOG_MAIN 和 POSTED_TO_OFFICE_DATA 有效。
- 頁面底部增加了查詢次數及耗時統計；管理員可以查看具體 SQL 查詢。
- 頁面底部增加了版本號信息。

### 查看 CBDB 任意表格功能
- `/codes` 新增白名單，可供查看 CBDB 表格內容。例如：可於 `/codes/ADDRESSES` 查詢地址主表。詳見 [CODES 介面整合備忘](./CODES.md) 及 [`/codes` 列表白名單說明](./CODES_TABLES.md)。
  - 关于效能優化方面，参见[`/codes` 介面效能優化說明](./CODES_PERFORMANCE.md)。
  - 支持在表格中搜索任意欄位的功能。

### 姓名搜索查詢優化
- 参见[CBDB 姓名搜尋效能改進方案](./NAME_SEARCH_PERFORMANCE_IMPROVEMENT.md)。
- 管理員专用[FTS 表格維護工具](./NAME_SEARCH_COMMANDS.md)。

### 新增「檢視表」功能，模擬 Access 中的相應檢視表資訊
- 新增 `/view` 檢視表總覽頁面，可瀏覽所有註冊的 View_* 定義與說明。
- 新增「檢視表 VIEW」模組，可在 `/view/{key}` 透過設定檔註冊查詢（目前含別名、社會關係、人物地址、社會機構地址、社會機構任職、人物來源、人物著作等資料）；頁面支援搜尋、分頁並內建「顯示 SQL」按鈕，便於比對實際執行的查詢語句。獲悉全部「檢視表」定義，請參閱[檢視表總覽](./VIEWS.md)。
- ~~新增「地址層級檢視」(`/view/Addresses`)，直接使用資料庫 `View_Address` 展開地址與最多五層隸屬關係，並在側邊欄加入快速連結。~~（已於 #701 移除）
- 為防止搜索及 AI 爬蟲拖慢系統性能，目前本功能僅供登錄用戶使用。

### 提案功能
- `/codes/*` 模組新增「提交提案」按鈕，可在新增／編輯時先送出提案而不直接寫入資料庫，系統會以 `op_type = 8/9` 於操作紀錄留下待審核紀錄，並於 `/operations`、`/modified` 提供管理員核准／退回按鈕。详情参阅[提案與審核流程参考文档](./APPROVAL_FLOWS.md)。
- 提案流程新增提案者自助「修改／撤回」功能：待審核或退修的提案可重新編輯並自動重設狀態，若選擇撤回則標記 `cancelled` 並留存原因；列表篩選亦支援顯示「已撤回」。
- `/operations?proposals_only=1` 篩選列新增「已撤回」選項，並於提案列出提案者／撤回者資訊與動作按鈕，提案者可直接從此頁修改或撤回自己的草稿。
- 進一步實作人物信息相關提案[計畫档案](./BIOGMAIN_APPROVAL_FLOWS_PLAN.md)。

### 新增管理員工具
- 「用戶列表」管理介面新增逐筆紀錄修改的功能。
- 管理員工具新增 `/admin/explainsql`，可輸入只讀 SQL 並檢視 MySQL `EXPLAIN` 查詢計畫。
- 「操作復原」功能，僅開放活躍管理員使用，還原後會以當前使用者身分寫入 `op_type = 3` 的修改紀錄，並補上 `OperationsRestoreAuthorizeTest` 覆蓋授權與紀錄流程；介面按鈕改為「復原」，提示訊息說明此動作等同自行修改。
- 管理员专用特定表格的[批量导入功能](./BATCH_UPLOADERS.md)。
- 管理员专用[Wiki 数据批量维护功能](./WIKI_TASK_MANAGEMENT.md)。

### 其他調整
- ALTNAME_DATA 在 `/modified` 顯示現況時改以 log 中的主鍵（`c_personid`／`c_alt_name_type_code`／`c_alt_name_chn`）查詢，不再依賴 `resource_id` 裡的舊別名，避免別名更新或含 dash 時抓不到現況，並補上 `OperationsAltnameResolverTest` 覆蓋。
- Basicinformation → 任官（office）操作全面交易化：`BiogMainRepository::officeStoreById`／`officeUpdateById`／`officeDeleteById` 會鎖定職官、同步維護地址清單並一併寫入 Operations；`officeUpdateById` 僅在主表欄位有異動時才更新 timestamp，純地址調整會重建 `POSTED_TO_ADDR_DATA` 並留下前後 JSON。
- Basicinformation 編輯頁面新增後端變更檢測：`BiogMainRepository::updateById()` 使用 `DetectsModelChanges` trait 的 `hasMeaningfulChanges()` 方法檢查資料是否有實質變更，若無變更則返回 `['no_changes' => true]` 並顯示「無實質更新，資料未變更」訊息；前端已有按鈕狀態管理（commit 18bc6d8f），後端檢測可防止繞過前端驗證的提交。新增 `BiogMainRepositoryUpdateTest` 單元測試驗證變更檢測邏輯，包括數值型字串與整數的等價比較、忽略指定欄位等情境。
  - [2025-11-29] - 修復基本資料編輯的變更檢測問題
    - 修復基本資料編輯頁面在無修改時仍會寫入資料庫和操作記錄的問題
    - 原因：Laravel 表單框架欄位（`_method`、`_token`、`_wysihtml5_mode`）被錯誤地包含在變更檢測中
    - 解決方案：在 `BiogMainRepository::updateById()` 中，於變更檢測前過濾掉框架欄位，只比對實際業務資料
    - 影響範圍：`BiogMainRepository::updateById()` 方法
    - 新增 `BasicInformationUpdateTest` 測試驗證修復效果，包含三個測試場景：
      - 測試無修改時不寫入資料庫
      - 測試有修改時正常寫入
      - 測試 Laravel 框架欄位被正確過濾
- Basicinformation 子頁面（addresses、altnames、assoc、entries、events、kinship、offices、possession、socialinst、sources、statuses、texts）之列表新增 `.table-responsive`，改善窄螢幕上的橫向捲動體驗。
- Operations / Modified 頁面套用 `.table-responsive`，按鈕文字調整為「內容快照」與「比較」，相關 Bootstrap Modal 皆設 `tabindex="-1"` 以支援 `ESC` 關閉；Crowdsourcing 頁面則加入 `.table-responsive` 與 `tabindex="-1"`。
- 操作復原僅開放活躍管理員使用，還原後會以當前使用者身分寫入 `op_type = 3` 的修改紀錄，並補上 `OperationsRestoreAuthorizeTest` 覆蓋授權與紀錄流程；介面按鈕改為「復原」，提示訊息說明此動作等同自行修改。
