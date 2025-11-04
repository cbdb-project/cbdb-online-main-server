# Proposal / Approval Flows

本文件說明目前在 `/codes/*` 模組導入的提案與審核流程，供後續擴充至其他頁面時參考。

## 背景

- 不想立即寫入資料庫，但需要記錄使用者的建議或修改草稿。
- 透過既有 `operations` 表保存提案內容與審核狀態，避免修改 schema。
- 管理員在 `/operations`、`/modified` 介面上即可看到提案紀錄，並後續實作核准／退回行為。

## 目前支援範圍

- `/codes/{table}` 新增、編輯頁面。
- 刪除提案目前不開放。
- 只有活躍帳號 (`is_active == 1`) 才能送出提案或直接儲存；只讀代碼表不提供提案按鈕。

## 操作流程

1. 使用者在新增或編輯頁面填寫表單。
2. 若選擇 **直接儲存**，沿用原流程，資料立即寫回代碼表並產生 `op_type = 1/2` 的操作紀錄。
3. 若選擇 **提交提案**：
   - 後端呼叫 `CodesController@proposalStore`（新增）或 `@proposalUpdate`（修改）。
   - 依主鍵確認資料是否存在，以決定屬於新增提案或修改提案。
   - 組出 `resource_data`，附帶：
     ```json
     {
       "...欄位內容...": "...",
       "__proposal_meta": {
         "action": "create|update",
         "table": "TABLE_NAME",
         "submitted_by": "使用者名",
         "submitted_by_id": 123,
         "submitted_at": "2025-11-01 23:40:00",
         "comment": "提案說明"
       },
       "__review_status": "pending"
     }
     ```
   - 透過 `OperationRepository::store()` 寫入 `operations` 表，`op_type` 分別為：
     - `8` (`Operation::TYPE_PROPOSAL_CREATE`)
     - `9` (`Operation::TYPE_PROPOSAL_UPDATE`)
   - 預設 `resource_original` 保存原始資料（修改提案）。
   - 回傳提示：「已提交提案，等待管理員審核」。
- 提案送出後，使用者可於 `/operations?proposals_only=1` 檢視自己的提案，若狀態為 `pending` 或 `rejected`，介面會提供「修改提案」與「撤回提案」按鈕。

## 審核流程（已實作於 `/operations`、`/modified`）

- **核准**：
  - 進入操作紀錄頁面，管理員可見「核准」按鈕。
  - 系統會依 `op_type` 執行 `insert`（提案新增）或 `update`（提案修改），套用至代碼表。
  - 成功後更新提案紀錄：`__review_status = 'approved'`，並記錄審核者與時間，另新增一筆正式操作 (`op_type = 1/2`) 供追蹤。
- **退回**：
  - 於提案卡片點選「退回」，可填寫備註。
  - 提案紀錄會標示 `__review_status = 'rejected'` 與審核備註，但不變更原資料表內容。
- 若提案仍為 `pending`，只允許管理員操作；審核後按鈕自動隱藏。

## 提案修改與撤回

- **提案者修改**：`pending`／`rejected` 狀態下可重新開啟表單調整內容，儲存後 `__review_status` 會重設為 `pending`，並更新 `__proposal_meta.updated_at`。新增提案若更換主鍵，`resource_id` 也會同步更新。
- **提案者撤回**：點選「撤回提案」後，系統會將狀態改為 `cancelled`，紀錄撤回者、時間與選填原因，提案不再出現在待審核清單。
- **主鍵衝突保護**：新增提案在提交與修改時都會檢查資料表與其它提案是否已使用相同主鍵，若有衝突會請提案者調整後再送出，避免審核階段才失敗。

## 延伸建議

- 其他模組若欲導入提案制，可：
  1. 新增對應的 `proposalStore`／`proposalUpdate` 動作或整合至既有 Controller。
  2. 重複利用 `recordProposalOperation()` 的概念，統一差異與審核欄位。
  3. 在 `operations`、`modified` 頁面依 `op_type` 加上醒目標示，提供核准／退回按鈕。
- 視需求補充通知機制（Mail、Slack 等），於提案或審核時提醒相關人員。
