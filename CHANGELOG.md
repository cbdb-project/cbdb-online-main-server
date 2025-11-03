# Changelog

## UI 調整 (2025)

- 強化基本信息首頁與資料檢視頁在手機／平板上的表格瀏覽體驗：
  - `NameList`、`AddrBelongsDataList`、`TextInstanceDataList` 加入 `table-scroll-x` 包裝，縮小螢幕時可橫向滑動，並集中樣式於 `styles.css` 管理。
  - `/view/{key}` 檢視表新增水平捲動與欄寬限制，防止欄位過多時被擠壓，同步停用左右滑動觸發的瀏覽器返回／前進手勢。
- 新增「檢視表 VIEW」模組，可在 `/view/{key}` 透過設定檔註冊查詢（目前含別名、社會關係、人物地址、社會機構地址、社會機構任職、人物來源、人物著作等資料）；頁面支援搜尋、分頁並內建「顯示 SQL」按鈕，便於比對實際執行的查詢語句。
- 新增「地址層級檢視」(`/view/Addresses`)，直接使用資料庫 `View_Address` 展開地址與最多五層隸屬關係，並在側邊欄加入快速連結。
- `/codes` 白名單新增 `ADDRESSES`，現可直接於 `/codes/ADDRESSES` 查詢地址主表。
- 管理員工具新增 `/admin/explainsql`，可輸入只讀 SQL 並檢視 MySQL `EXPLAIN` 查詢計畫。
- 新增 `/view` 檢視表總覽頁面，可瀏覽所有註冊的 View_* 定義與說明。
- 新增「人物事件資料檢視」(`/view/events-data`)，透過 `View_EventData` 整合人物事件、年號、干支與來源資訊。
- 新增「人物事件地址檢視」(`/view/event-addr-data`)，整合事件、地址與干支資訊，補充事件地點查詢。
- 新增「人物親屬資料檢視」(`/view/kin-addr-data`)，透過 `View_KinAddrData` 彙整親屬關係、人物主檔與來源資訊。
- 新增「人物基本資料檢視」(`/view/people-data`)，透過 `View_PeopleData` 將 BIOG_MAIN 與相關編碼資訊統整成單一查詢。
- 新增「人物索引地址檢視」(`/view/people-addr-data`)，以索引地址欄位串接地址名稱與類型描述。
- 新增「人物財產資料檢視」(`/view/posessions-data`)，透過 `View_PossessionsData` 彙整財產行為、度量、年號與來源資訊。
- 新增「人物財產地址檢視」(`/view/posessions-addr-data`)，透過 `View_PossessionsAddrData` 加入財產地址名稱對照。
- 新增「任官地址資料檢視」(`/view/posting-addr-data`)，直接串接 `POSTED_TO_ADDR_DATA` 與 `ADDR_CODES` 快速檢視官職與地址對應。
- 新增「任官職務資料檢視」(`/view/posting-office-data`)，透過 `POSTED_TO_OFFICE_DATA` 搭配官職、任命代碼與年號資訊，提供更完整的任官視角。
- 新增「人物身份資料檢視」(`/view/status-data`)，整合 `STATUS_DATA` 與身份代碼、年號、來源資訊，快速查閱身份紀錄。

- ALTNAME_DATA 在 `/modified` 顯示現況時改以 log 中的主鍵（`c_personid`／`c_alt_name_type_code`／`c_alt_name_chn`）查詢，不再依賴 `resource_id` 裡的舊別名，避免別名更新或含 dash 時抓不到現況，並補上 `OperationsAltnameResolverTest` 覆蓋。
- Basicinformation → 任官（office）操作全面交易化：`BiogMainRepository::officeStoreById`／`officeUpdateById`／`officeDeleteById` 會鎖定職官、同步維護地址清單並一併寫入 Operations；`officeUpdateById` 僅在主表欄位有異動時才更新 timestamp，純地址調整會重建 `POSTED_TO_ADDR_DATA` 並留下前後 JSON。


- Basicinformation 子頁面（addresses、altnames、assoc、entries、events、kinship、offices、possession、socialinst、sources、statuses、texts）之列表新增 `.table-responsive`，改善窄螢幕上的橫向捲動體驗。
- Operations / Modified 頁面套用 `.table-responsive`，按鈕文字調整為「內容快照」與「比較」，相關 Bootstrap Modal 皆設 `tabindex="-1"` 以支援 `ESC` 關閉；Crowdsourcing 頁面則加入 `.table-responsive` 與 `tabindex="-1"`。
- 操作復原僅開放活躍管理員使用，還原後會以當前使用者身分寫入 `op_type = 3` 的修改紀錄，並補上 `OperationsRestoreAuthorizeTest` 覆蓋授權與紀錄流程；介面按鈕改為「復原」，提示訊息說明此動作等同自行修改。
