# Changelog

## UI 調整 (2025)

- 新增「檢視表 VIEW」模組，可在 `/view/{key}` 透過設定檔註冊查詢（目前含別名、社會關係、人物地址、社會機構地址、社會機構任職、人物來源、人物著作等資料）；頁面支援搜尋、分頁並內建「顯示 SQL」按鈕，便於比對實際執行的查詢語句。
- 新增「地址層級檢視」(`/view/Addresses`)，直接使用資料庫 `View_Address` 展開地址與最多五層隸屬關係，並在側邊欄加入快速連結。
- `/codes` 白名單新增 `ADDRESSES`，現可直接於 `/codes/ADDRESSES` 查詢地址主表。
- 官名地址（POSTED_TO_ADDR_DATA）變動現在會額外留下操作紀錄，內容採取整筆列資料的 JSON （包含 c_personid、c_posting_id、c_office_id、c_addr_id），以利差異比對與復原。 同時暫時隱藏此類操作的復原按鈕，避免使用者誤觸不支援的還原流程。
- ALTNAME_DATA 在 `/modified` 顯示現況時改以 log 中的主鍵（`c_personid`／`c_alt_name_type_code`／`c_alt_name_chn`）查詢，不再依賴 `resource_id` 裡的舊別名，避免別名更新或含 dash 時抓不到現況，並補上 `OperationsAltnameResolverTest` 覆蓋。
- Basicinformation → 任官（office）操作全面交易化：`BiogMainRepository::officeStoreById`／`officeUpdateById`／`officeDeleteById` 會鎖定職官、同步維護地址清單並一併寫入 Operations；`officeUpdateById` 僅在主表欄位有異動時才更新 timestamp，純地址調整會重建 `POSTED_TO_ADDR_DATA` 並留下前後 JSON。


- Basicinformation 子頁面（addresses、altnames、assoc、entries、events、kinship、offices、possession、socialinst、sources、statuses、texts）之列表新增 `.table-responsive`，改善窄螢幕上的橫向捲動體驗。
- Operations / Modified 頁面套用 `.table-responsive`，按鈕文字調整為「內容快照」與「比較」，相關 Bootstrap Modal 皆設 `tabindex="-1"` 以支援 `ESC` 關閉；Crowdsourcing 頁面則加入 `.table-responsive` 與 `tabindex="-1"`。
- 操作復原僅開放活躍管理員使用，還原後會以當前使用者身分寫入 `op_type = 3` 的修改紀錄，並補上 `OperationsRestoreAuthorizeTest` 覆蓋授權與紀錄流程；介面按鈕改為「復原」，提示訊息說明此動作等同自行修改。
