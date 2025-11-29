# Changelog

## 技術棧升級 (2025-11)
- 詳見 `UPGRADE.md` 以了解完整升級經歷。
  - Laravel: 5.5 -> 5.6 -> 5.7 -> 5.8 -> 6 -> 7 -> 8 -> 10
  - PHP: 7.4 -> 8.4

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
- 新增「地址層級檢視」(`/view/Addresses`)，直接使用資料庫 `View_Address` 展開地址與最多五層隸屬關係，並在側邊欄加入快速連結。
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
