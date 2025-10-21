# MERGE 工具說明

本專案提供 `/merge-preview` 介面，協助管理員在刪除或合併人物記錄前進行全面檢視與驗證。本頁面由 `MergePreviewController` 與 `resources/views/manage/merge-preview.blade.php` 驅動，僅對 `is_admin == 1` 的使用者開放。

## 功能摘要

- **基礎資訊對比**  
  輸入保留 (`from` 或 `primary_id`) 與合併來源 (`to` 或 `secondary_id`) 的人物 ID 後，介面會顯示雙方姓名、朝代與性別等欄位，並模擬合併後 (`merged_person`) 的欄位值。姓名差異會以紅色標示並出現警告，提醒管理員需要人工再確認。

- **欄位差異表**  
  `BIOG_MAIN` 全欄位會以表格呈現「保留人物值 / 合併來源值 / 合併後預覽」，同時標示出所有差異與最終更新的欄位。`c_notes` 會依序寫入「保留人物備註」「來源人物備註」與一行 `[merged #ID1 and #ID2 on YYYYMMDD with reason] 合併理由` 的記錄（若缺少其中一個 ID 則僅顯示存在的 ID）；`c_modified_by` 與 `c_modified_date` 則使用當前登入者與今日日期。

- **相關資料預覽**  
  額外列出 `ALTNAME_DATA`（別名）與 `KIN_DATA`（親屬）之筆數與內容摘要，並將 `KinID` 連結到對應的人物編輯頁面。頁面也會顯示 `ASSOC_DATA` 對四個欄位（`c_personid`、`c_kin_id`、`c_assoc_id`、`c_assoc_kin_id`）的資料、以及其它會受影響的資料表（如 `BIOG_ADDR_DATA`、`ENTRY_DATA`、`EVENTS_DATA` 等）在保留與來源人物的筆數與前 20 筆 JSON 摘要，以協助評估合併範圍。

- **SQL 操作預覽**  
  工具會產生兩段 SQL 交易：
  1. 第一段 `START TRANSACTION` 會執行欄位更新、將所有關聯欄位的 `c_personid` 由來源 ID 改為保留 ID、然後列出 `SELECT COUNT(*)` 供確認各表已無來源 ID 資料，最後刪除 `BIOG_MAIN` 的來源記錄並 `COMMIT`。  
  2. 如果使用者勾選「將較大 ID 自動合併至較小 ID（合併後會進行一次 ID 移動）」，在第一段完成後會再開啟第二段交易，將資料最終調整到較小的 ID（包含所有關聯表與 `BIOG_MAIN` 主鍵），最後再 `COMMIT`。
  SQL 區塊僅供人工審閱與複製執行；實際寫入仍需由管理員於資料庫中逐步確認。

- **複製連結**  
  「複製連結」按鈕會根據目前輸入的 ID、`merge_to_min` 勾選狀態與合併理由即時生成 URL，格式如：
  ```
  /merge-preview?from=100388&to=339737&merge_to_min=false&reason=...
  ```
  方便與其他審核者分享。

## 欄位補充

- `merge_to_min=true` 或勾選「將較大 ID 自動合併至較小 ID」時，頁面僅模擬欄位值，實際 SQL 仍會先以原 ID 順序合併，並在第一段交易完成後另行執行「轉至最小 ID」的交易，避免前端邏輯自動交換 ID。
- `merge_reason` 欄位會被寫入 `c_notes`、用於複製連結與提示文字，請在此描述合併依據、史料證據或分析方法。
- 如果 `ALTNAME`、`KIN` 或 `ASSOC` 等表在合併前有待人工比對的差異，管理員可以利用 JSON 摘要快速檢視各欄位，必要時手動調整資料並重新預覽。

## 注意事項

1. 此工具僅提供預覽與 SQL 示例，實際執行資料庫操作前務必確認各項 `SELECT COUNT(*)` 結果為 0，再進行刪除或轉移。
2. 若遇到姓名 (`c_name` / `c_name_chn`) 不一致，頁面仍會顯示 SQL，但會以紅字與警告提醒使用者檢視是否為同一人物。
3. 頁面只列出前 20 筆相關資料摘要；若資料量龐大，應在資料庫中進一步查詢驗證。
