# Changelog

## 技術升級 (2025-11)

### Laravel 7.0 → 8.0 (LTS) (2025-11-25)
- 升級 Laravel Framework 從 7.30.7 至 8.83.29（LTS 長期支援版本）
- 升級 Laravel Passport 從 8.x 至 10.x
- 升級 Laravel UI 從 2.x 至 3.x
- 升級 Guzzle 從 6.x 至 7.0（PSR-18 支援）
- 升級 Nunomaduro Collision 至 ^5.0
- 新增 Laravel Legacy Factories ^1.0（保持工廠模式向後兼容）
- 新增 Laravel Serializable Closure ^1.0（取代 opis/closure）
- PHP 最低版本要求從 7.2.5 提升至 7.3.0
- PHPUnit 維持在 ^9.0（已於先前升級）
- 更新 public/index.php 添加維護模式檢查和現代化結構
- 遷移 Seeders 到 database/seeders/ 並添加命名空間
- 修復 Console Command 方法訪問級別衝突（RegenerateAddresses::newLine）
- 支援 Job Batching、Rate Limiting 改進、Time Testing Helpers
- 詳見 `UPGRADE.md` 了解完整升級說明

### Laravel 6.0 LTS → 7.0 (2025-11-23)
- 升級 Laravel Framework 從 6.20.44 至 7.x
- 升級 Laravel Passport 從 7.5.x 至 8.x
- 升級 Laravel Tinker 從 1.x 至 2.x
- 新增 Laravel UI 套件 ^2.0
- 新增 Facade Ignition ^2.0（改進的錯誤頁面）
- 升級 Nunomaduro Collision 至 ^4.1（美化的測試錯誤輸出）
- PHP 最低版本要求從 7.2.0 提升至 7.2.5
- PHPUnit 維持在 ^8.5（因兼容性問題暫不升級至 9.x）
- 郵件配置更新：`MAIL_DRIVER` → `MAIL_MAILER`
- 異常處理器類型提示：`Exception` → `Throwable`
- 新增 Email 驗證路由和通知
- 改進的授權回應
- 詳見 `UPGRADE.md` 了解完整升級說明

### Laravel 5.8 → 6.0 LTS (2025-11-22)
- 升級 Laravel Framework 從 5.8.38 至 6.20.44（LTS 長期支援版本）
- 升級 Carbon 從 1.39.1 至 2.72.5（重大版本升級）
- 升級 PHPUnit 從 7.5.20 至 8.5.40
- 升級 Mockery 至 1.6.12
- PHP 最低版本要求從 7.1.3 提升至 7.2.0
- 新增 LazyCollection 支援，處理大數據集更高效
- 改進 Eloquent 子查詢功能
- 新增 Job 中介軟體支援
- 新增密碼確認功能
- 新增 friendsofphp/php-cs-fixer 代碼風格檢查工具
- 新增 GitHub Actions 自動格式化工作流程
- 詳見 `UPGRADE.md` 了解完整升級說明

### Laravel 5.7 → 5.8 (2025-11-18)
- 升級 Laravel Framework 從 5.7.29 至 5.8.38
- 升級 Laravel Passport 從 6.0.7 至 7.5.1
- Cache TTL 格式從「分鐘」改為「秒」
- 路由閉包序列化支援改進
- 移除 Nexmo 和 Slack 通知渠道獨立套件（已內建到框架）
- 新增 PSR-16 Cache 相容性
- Email 驗證從 RFC822 升級到 RFC6530
- 詳見 `UPGRADE.md` 了解完整升級說明

### Laravel 5.6 → 5.7 (2025-11-17)
- 升級 Laravel Framework 從 5.6.40 至 5.7.29
- 升級 Laravel Passport 從 4.0.3 至 6.0.7
- 升級 Carbon 從 1.26.6 至 1.39.1
- 升級 Symfony 組件至 4.4.x 版本
- 新增 Nexmo 和 Slack 通知渠道支援
- 新增 `opis/closure` 支援閉包序列化
- PHP 最低版本要求從 7.0.0 提升至 7.1.3
- 詳見 `UPGRADE.md` 了解完整升級說明

## UI 調整 (2025)

- `/codes/*` 模組新增「提交提案」按鈕，可在新增／編輯時先送出提案而不直接寫入資料庫，系統會以 `op_type = 8/9` 於操作紀錄留下待審核紀錄，並於 `/operations`、`/modified` 提供管理員核准／退回按鈕。
- 提案流程新增提案者自助「修改／撤回」功能：待審核或退修的提案可重新編輯並自動重設狀態，若選擇撤回則標記 `cancelled` 並留存原因；列表篩選亦支援顯示「已撤回」。
- `/operations?proposals_only=1` 篩選列新增「已撤回」選項，並於提案列出提案者／撤回者資訊與動作按鈕，提案者可直接從此頁修改或撤回自己的草稿。
- 強化基本信息首頁與資料檢視頁在手機／平板上的表格瀏覽體驗：
  - `NameList`、`AddrBelongsDataList`、`TextInstanceDataList` 加入 `table-scroll-x` 包裝，縮小螢幕時可橫向滑動，並集中樣式於 `styles.css` 管理。
  - `/view/{key}` 檢視表新增水平捲動與欄寬限制，防止欄位過多時被擠壓，同步停用左右滑動觸發的瀏覽器返回／前進手勢。
- 新增「檢視表 VIEW」模組，可在 `/view/{key}` 透過設定檔註冊查詢（目前含別名、社會關係、人物地址、社會機構地址、社會機構任職、人物來源、人物著作等資料）；頁面支援搜尋、分頁並內建「顯示 SQL」按鈕，便於比對實際執行的查詢語句。
- 新增「地址層級檢視」(`/view/Addresses`)，直接使用資料庫 `View_Address` 展開地址與最多五層隸屬關係，並在側邊欄加入快速連結。
- `/codes` 白名單新增 `ADDRESSES`，現可直接於 `/codes/ADDRESSES` 查詢地址主表。
- 管理員工具新增 `/admin/explainsql`，可輸入只讀 SQL 並檢視 MySQL `EXPLAIN` 查詢計畫。
- 新增 `/view` 檢視表總覽頁面，可瀏覽所有註冊的 View_* 定義與說明。請參閱[檢視表總覽](./VIEWS.md)。
- ALTNAME_DATA 在 `/modified` 顯示現況時改以 log 中的主鍵（`c_personid`／`c_alt_name_type_code`／`c_alt_name_chn`）查詢，不再依賴 `resource_id` 裡的舊別名，避免別名更新或含 dash 時抓不到現況，並補上 `OperationsAltnameResolverTest` 覆蓋。
- Basicinformation → 任官（office）操作全面交易化：`BiogMainRepository::officeStoreById`／`officeUpdateById`／`officeDeleteById` 會鎖定職官、同步維護地址清單並一併寫入 Operations；`officeUpdateById` 僅在主表欄位有異動時才更新 timestamp，純地址調整會重建 `POSTED_TO_ADDR_DATA` 並留下前後 JSON。


- Basicinformation 子頁面（addresses、altnames、assoc、entries、events、kinship、offices、possession、socialinst、sources、statuses、texts）之列表新增 `.table-responsive`，改善窄螢幕上的橫向捲動體驗。
- Operations / Modified 頁面套用 `.table-responsive`，按鈕文字調整為「內容快照」與「比較」，相關 Bootstrap Modal 皆設 `tabindex="-1"` 以支援 `ESC` 關閉；Crowdsourcing 頁面則加入 `.table-responsive` 與 `tabindex="-1"`。
- 操作復原僅開放活躍管理員使用，還原後會以當前使用者身分寫入 `op_type = 3` 的修改紀錄，並補上 `OperationsRestoreAuthorizeTest` 覆蓋授權與紀錄流程；介面按鈕改為「復原」，提示訊息說明此動作等同自行修改。
