# 人物合併預覽工具說明

本專案提供 `/merge-preview` 介面，協助管理員在刪除或合併人物記錄前進行全面檢視與驗證。本頁面由 `MergePreviewController` 與 `resources/views/manage/merge-preview.blade.php` 驅動，僅對 `is_admin == 1` 的使用者開放。

## 功能摘要

- **基礎資訊對比**  
  輸入保留 (`primary_id`) 與合併來源 (`secondary_id`) 的人物 ID 後，介面會顯示雙方姓名、朝代與性別等欄位，並模擬合併後 (`merged_person`) 的欄位值。姓名差異會以紅色標示並出現警告，提醒管理員需要人工再確認。

- **欄位差異表**  
  `BIOG_MAIN` 全欄位會以表格呈現「保留人物值 / 合併來源值 / 合併後預覽」，同時標示出所有差異與最終更新的欄位。`c_notes` 會依序寫入「保留人物備註」「來源人物備註」與一行 `[merged #ID1 and #ID2 on YYYYMMDD with reason] 合併理由` 的記錄（若缺少其中一個 ID 則僅顯示存在的 ID）；`c_modified_by` 與 `c_modified_date` 則使用當前登入者與今日日期。

- **相關資料預覽**  
  額外列出 `ALTNAME_DATA`（別名）與 `KIN_DATA`（親屬）之筆數與內容摘要，並將 `KinID` 連結到對應的人物編輯頁面。頁面也會顯示 `ASSOC_DATA` 對四個欄位（`c_personid`、`c_kin_id`、`c_assoc_id`、`c_assoc_kin_id`）的資料、以及其它會受影響的資料表（如 `BIOG_ADDR_DATA`、`ENTRY_DATA`、`EVENTS_DATA` 等）在保留與來源人物的筆數與前 20 筆 JSON 摘要，以協助評估合併範圍。
  其中 `MERGED_PERSON_DATA` 額外統計 `c_personid` 與 `c_merged_from_personid` 兩種角色，便於確認歷史合併紀錄。

- **SQL 操作預覽**  
  工具會產生兩段 SQL 交易：
  1. 第一段 `START TRANSACTION` 會執行欄位更新、將所有關聯欄位的 `c_personid` 由來源 ID 改為保留 ID、然後列出 `SELECT COUNT(*)` 供確認各表已無來源 ID 資料，最後刪除 `BIOG_MAIN` 的來源記錄並 `COMMIT`。  
  2. 如果使用者勾選「將較大 ID 自動合併至較小 ID（合併後會進行一次 ID 移動）」，在第一段完成後會再開啟第二段交易，將資料最終調整到較小的 ID（包含所有關聯表與 `BIOG_MAIN` 主鍵），最後再 `COMMIT`。
  SQL 區塊僅供人工審閱與複製執行；實際寫入仍需由管理員於資料庫中逐步確認。

- **複製連結**  
  「複製連結」按鈕會根據目前輸入的 ID、`merge_to_min` 勾選狀態與合併理由即時生成 URL，格式如：
  ```
  /merge-preview?primary_id=100388&secondary_id=339737&merge_to_min=false&reason=...
  ```
  方便與其他審核者分享。

## 欄位補充

- `merge_to_min=true` 或勾選「將較大 ID 自動合併至較小 ID」時，頁面僅模擬欄位值，實際 SQL 仍會先以原 ID 順序合併，並在第一段交易完成後另行執行「轉至最小 ID」的交易，避免前端邏輯自動交換 ID。
- `merge_reason` 欄位會被寫入 `c_notes`、用於複製連結與提示文字，請在此描述合併依據、史料證據或分析方法。
- 如果 `ALTNAME`、`KIN` 或 `ASSOC` 等表在合併前有待人工比對的差異，管理員可以利用 JSON 摘要快速檢視各欄位，必要時手動調整資料並重新預覽。
- `MERGED_PERSON_DATA` 會同步列出（`c_personid` 與 `c_merged_from_personid`兩種角色），目前僅保存合併理由（`c_notes`），預設不刪除歷史記錄。

## 注意事項

1. 此工具僅提供預覽與 SQL 示例，實際執行資料庫操作前務必確認各項 `SELECT COUNT(*)` 結果為 0，再進行刪除或轉移。
2. 若遇到姓名 (`c_name` / `c_name_chn`) 不一致，頁面仍會顯示 SQL，但會以紅字與警告提醒使用者檢視是否為同一人物。
3. 頁面只列出前 20 筆相關資料摘要；若資料量龐大，應在資料庫中進一步查詢驗證。

## 後續開發規劃：支援合併提交

為了讓 `/merge-preview` 未來可以直接執行合併（而不是只讀預覽），建議按下列方向迭代：

1. **權限與觸發條件**
   - 只有 `is_admin == 1` 的帳號能看到並使用「Submit」按鈕。
   - 需先完成預覽且通過所有檢查（`merge_blocked == false`、所有 `remaining_xxx` 查詢為 0），否則按鈕應保持停用。
   - 按鈕前附上確認提示（Dialog 或 `confirm()`），明確指出 primary / secondary ID 與合併後變動。

2. **前端提交流程**
   - 新增 `POST /merge-preview/execute`（或類似）路由，表單帶上：`primary_id`、`secondary_id`、`merge_reason`、`auto_arrange` 等原預覽參數。
   - 送出前可將預覽結果產生的 checksum／timestamp 一併提交，以便後端驗證使用者是否先看過最新預覽。
   - 防止重複送出（按鈕 disabled、顯示 loading）。

3. **後端執行邏輯**
   - Controller 需再次檢查：使用者權限、兩個人物是否存在、姓名/朝代/性別是否仍一致、各關聯表是否已清空 secondary ID。
   - 合併交易流程（建議抽 Service）：
     1. `BEGIN` 交易。
     2. 更新 `BIOG_MAIN`：保留人物欄位與 `c_notes`，刷 `c_modified_*`。
     3. 更新所有關聯表的 `c_personid`（`ASSOC_DATA` 還包含其他欄位）、`MERGED_PERSON_DATA` 等指向。
       - `MERGED_PERSON_DATA`：以 `INSERT ... ON DUPLICATE KEY UPDATE` 保存 reason，不刪歷史；若 `merge_to_min`，只交換 `c_personid`/`c_merged_from_personid`。
     4. 刪除來源人物的 `BIOG_MAIN`。
     5. 若勾選「merge_to_min」，第二段交易把保留人物轉成較小 ID，並更新關聯表與 `MERGED_PERSON_DATA` 的鍵值。
     6. 全程捕捉例外，失敗時 `ROLLBACK`。
   - 寫操作紀錄：透過 `OperationRepository::store()` 或另行紀錄合併事件，以利稽核。

4. **驗證與安全**
   - 後端重新驗證所有輸入（整數化、非空），並防止 race condition（必要時 `SELECT ... FOR UPDATE` 或比對 `updated_at`）。
   - 若任何檢查失敗（仍有殘留資料、資料已變更等），要清楚回報錯誤資訊。
   - 依情況紀錄系統 log（包含操作者、ID、理由）。

5. **前端回饋**
   - 成功：flash 提示並導向合併後人物（或重新載入預覽頁）。
   - 失敗：flash 錯誤訊息並保留表單內容。

6. **測試策略**
   - 撰寫 Feature test 覆蓋正向與阻擋情境（姓名不合、剩餘資料未清空、權限不足等）。
   - 在測試中建立 `MERGED_PERSON_DATA` schema（若 SQLite 環境沒有）。
   - 建議先在 staging 進行人工測試：預覽 → 合併 → 驗證資料表 → 確認 `MERGED_PERSON_DATA` 與 `BIOG_MAIN` 變化。

7. **迭代建議**
   - 初期可掛 feature flag，與管理員確認流程。
   - 可考慮加入 dry-run / 只產生 SQL 但不執行的模式。
   - 長期規劃可將合併邏輯抽成 Service + Job，且搭配更完整的審計紀錄與回滾方案。

依照以上步驟執行，可確保合併流程在可控、可驗證的情況下對資料庫寫入變更。
