# Posting & Office 流程備忘

**適用範圍**：`POSTING_DATA`、`POSTED_TO_OFFICE_DATA`、`POSTED_TO_ADDR_DATA`，即「任官」主檔、職官資料與職官地址對應。

## 1. 建立流程 (`BiogMainRepository::officeStoreById`)

- 一律經由 Repository，方法使用資料庫交易 (`DB::transaction`).
- 主要步驟：
  1. 取出新 posting id：`POSTING_DATA` 使用 `lockForUpdate()` 取得目前最大值並加一。
  2. 寫入 `POSTING_DATA`，確認 `c_personid` 與 `c_posting_id` 建檔。
  3. 呼叫 `insertAddr($c_addr, $personId, $postingId, $officeId)` 重建地址清單。傳入陣列內若含 `-999` 代表「未填」，會存成 `c_addr_id = 0`；若陣列為空，最後 `POSTED_TO_ADDR_DATA` 會沒有紀錄。
  4. 呼叫 `ToolsRepository::timestamp($data, true)` 產生 `c_created_by`/`c_created_date`/`c_modified_by`/`c_modified_date`。
  5. 寫入 `POSTED_TO_OFFICE_DATA`。
  6. 以 `OperationRepository::store(..., op_type=1, resource='POSTED_TO_OFFICE_DATA')` 建立操作紀錄，`resource_id` 為 `{c_office_id}-{c_posting_id}`。

## 2. 更新流程 (`BiogMainRepository::officeUpdateById`)

- 交易化：整個更新（主表 + 地址）包在單一 transaction 中。
- 入口會把 request 拆成：原始職官資料 (`$ori`)、新資料 (`$data`)、地址陣列 (`$incomingAddr`)。
- 判斷變動：
  - `$hasPostingChange = hasMeaningfulChanges($data, $ori, ['c_modified_by', 'c_modified_date'])`；
  - `$hasAddressChange` 會比較 `$incomingAddr` 與既有地址清單（`selectionListHasChanges`，`-999` 視為 0）。
- 更新邏輯：
  1. 若職官欄位有變動，才會呼叫 `timestamp()` 並更新 `POSTED_TO_OFFICE_DATA`，然後寫 `op_type = 3` 的 operation snapshot。只動地址時不會改主表 timestamp，以避免在測試場景（無登入使用者）碰到 `Auth::user()->name` 例外。
  2. 若地址有變動或 `c_office_id` 改變，會：
     - 讀取舊地址集合作為 `beforeRows`；
     - 如 `c_office_id` 改變且舊資料存在，先清掉舊的 `POSTED_TO_ADDR_DATA`；
     - 重建地址資料：使用新的陣列（`$incomingAddr`）或沿用舊列表。
     - 取得重新寫入後的地址資料 (`afterRows`)，並以 `OperationRepository::store(..., resource='POSTED_TO_ADDR_DATA')` 存成 `rows` JSON。
  3. 回傳 `{ 'id' => '{officeId}-{postingId}', 'no_changes' => false }` 或 `{..., 'no_changes' => true }`。

## 3. 刪除流程 (`BiogMainRepository::officeDeleteById`)

- 交易化處理。傳入 `id` 格式 `{officeId}-{postingId}`。
- 步驟：
  1. 讀取對應 `POSTED_TO_OFFICE_DATA` 紀錄作為 operation 快照。
  2. 刪除 `POSTED_TO_OFFICE_DATA`。
  3. 刪除 `POSTED_TO_ADDR_DATA` 中所有同 `c_posting_id`（不限定 office 或 person）。
  4. 刪除 `POSTING_DATA` 主檔。
  5. 寫入 `op_type = 4` 的刪除操作紀錄，資源 `POSTED_TO_OFFICE_DATA`。

## 4. 地址維護 (`insertAddr`)

- 先刪除該 posting+office 既有地址，再用傳入陣列寫回。
- `-999` 代表「無地址」，存入時換成 0。
- 建立與更新都會調用。
- 刪除任官時不會呼叫此方法，改由 transaction 直接清空對應 posting。

## 5. 操作紀錄（Operations）

- `resource` 欄位分別：`POSTED_TO_OFFICE_DATA`、`POSTED_TO_ADDR_DATA`、`POSTING_DATA`。
- `resource_id` 統一採 `{c_office_id}-{c_posting_id}`。
- 地址變動的 `resource_data` 以 `rows` 包含 before/after 清單（每列含 `c_personid`, `c_posting_id`, `c_office_id`, `c_addr_id`）。
- 刪除任官後會寫入整筆 `POSTED_TO_OFFICE_DATA` 的 JSON 快照，但地址是以 posting id chained 的 `POSTED_TO_ADDR_DATA` 收集。

## 6. 測試重點

- Feature 測試需 `actingAs()` 讓 `ToolsRepository::timestamp()` 能取得登入者姓名。
- 若只測地址更新，請確認 Operations 會寫入 `POSTED_TO_ADDR_DATA` 的 before/after JSON，而 `POSTED_TO_OFFICE_DATA` 不會誤觸。
- 刪除流程確認 `POSTED_TO_ADDR_DATA` 與 `POSTING_DATA` 一併清空。
- 常用測試：
  - `tests/Feature/OfficePostingStoreTest.php`
  - `tests/Feature/OfficeAddressOperationLoggingTest.php`

## 7. 其他注意事項

- UI 表單的 address multi-select 會在送出時將 "未選" 表示為 `-999`；POSTING repository 會自動轉換為 `c_addr_id = 0`。
- 若之後需要支援「某官職無地址欄位」，記得前端送出空陣列即可，Repository 會維持無資料狀態。
- Restore 功能目前對任官類型仍停用；operations snapshot 主要作為稽核依據。
- 方法命名沿用歷史：`officeStoreById` / `officeUpdateById` / `officeDeleteById` 實際處理的是整個 posting 及其地址與相關資料，若未來要重構可考慮改成 `posting*` 類型名稱，但現階段先維持現狀以免破壞既有呼叫點。
