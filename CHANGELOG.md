# Changelog

## UI 調整 (2025)

- 官名地址（POSTED_TO_ADDR_DATA）變動現在會額外留下操作紀錄，內容採取整筆列資料的 JSON （包含 c_personid、c_posting_id、c_office_id、c_addr_id），以利差異比對與復原。 同時暫時隱藏此類操作的復原按鈕，避免使用者誤觸不支援的還原流程。


- Basicinformation 子頁面（addresses、altnames、assoc、entries、events、kinship、offices、possession、socialinst、sources、statuses、texts）之列表新增 `.table-responsive`，改善窄螢幕上的橫向捲動體驗。
- Operations / Modified 頁面套用 `.table-responsive`，按鈕文字調整為「內容快照」與「比較」，相關 Bootstrap Modal 皆設 `tabindex="-1"` 以支援 `ESC` 關閉；Crowdsourcing 頁面則加入 `.table-responsive` 與 `tabindex="-1"`。
- 操作復原僅開放活躍管理員使用，還原後會以當前使用者身分寫入 `op_type = 3` 的修改紀錄，並補上 `OperationsRestoreAuthorizeTest` 覆蓋授權與紀錄流程；介面按鈕改為「復原」，提示訊息說明此動作等同自行修改。