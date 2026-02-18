# BiogMain 審批流程實作狀態

本文件改為「實作現況追蹤」，取代早期規劃草案。  
如需流程細節，請一併參考 `APPROVAL_FLOWS.md` 與實際程式碼（`routes/web.php`、`BasicInformationProposalController`、`OperationsProposalController`）。

## 1. 基本資訊

- 文檔性質：實作狀態（Status）
- 文檔版本：4.1
- 創建日期：2025-11-27
- 最後更新：2026-02-15

## 2. 目前實作概覽

### 2.1 技術環境（現況）

- Laravel：12.x
- PHP：8.2+（建議 8.4）
- PHPUnit：11.x
- 資料庫：MariaDB 10.3.39（正式）/ SQLite（測試）

### 2.2 已落地能力

1. 提案送出（op_type 8/9）
- 入口已接入 `action=proposal`：
  - `BasicInformationController`（BIOG_MAIN update）
  - `BasicInformationAltnamesController`
  - `BasicInformationAddressesController`
  - `BasicInformationTextsController`
- 通用提案控制器：`BasicInformationProposalController`
  - 支援 `proposalStore` / `proposalUpdate` / `proposalUpdateWithPk`
  - 會寫入 `operations`，並附帶 `__proposal_meta`、`__review_status`、`__key_columns`

2. 提案審核（/operations）
- 路由：
  - `POST /operations/{operation}/approve`
  - `POST /operations/{operation}/reject`
- 控制器：`OperationsProposalController`
  - 核准時會套用至目標資料表（新增或更新）
  - 嚴格檢查主鍵欄位與不可修改主鍵
  - 寫回提案狀態（approved/rejected）與審核資訊
  - 產生正式操作紀錄（op_type 1/3）
  - 同步寫入 `audit_log`（INSERT/UPDATE）

3. 提案列表與狀態篩選
- `/operations?proposals_only=1` 已支援狀態篩選：
  - `pending` / `approved` / `rejected` / `cancelled`

## 3. 資源別狀態

| 資源 | 狀態 | 說明 |
|---|---|---|
| `BIOG_MAIN` | 已實作 | 可提交修改提案，且可由審核流程核准寫回主表。 |
| `ALTNAME_DATA` | 已實作 | 提案提交與審核流程可用（含複合主鍵處理）。 |
| `BIOG_ADDR_DATA` | 已實作 | 提案提交與審核流程可用（含複合主鍵處理）。 |
| `BIOG_TEXT_DATA` | 已實作 | 提案提交與審核流程可用（含複合主鍵處理）。 |
| `STATUS_DATA` | 已實作 | 提案提交與審核流程可用。 |
| `POSSESSION_DATA` | 已實作 | 提案提交與審核流程可用。 |
| `POSTED_TO_OFFICE_DATA` | 已實作 | 提案提交與審核流程可用。 |
| `ASSOC_DATA` | 已實作 | 提案提交與審核流程可用。 |
| `ENTRY_DATA` | 已實作 | 提案提交與審核流程可用。 |
| `EVENTS_DATA` | 已實作 | 提案提交與審核流程可用。 |
| `KIN_DATA` | 已實作 | 提案提交與審核流程可用。 |
| `BIOG_INST_DATA` | 已實作 | 提案提交與審核流程可用。 |
| `BIOG_SOURCE_DATA` | 已實作 | 提案提交與審核流程可用。 |

## 4. 測試覆蓋（已存在）

- `tests/Feature/BiogMainProposalTest.php`
  - 覆蓋 BIOG_MAIN 提案提交與核准寫回
- `tests/Feature/BasicInformationProposalTest.php`
  - 覆蓋提案控制器核心流程（提交、衝突、核准、退回、例外情境）
- `tests/Feature/OperationsProposalControllerTest.php`
  - 覆蓋審核控制器通用審核流程

## 5. 已知缺口

1. 提案者「修改/撤回」目前沿用 `CodesController` 的提案管理路由與頁面，流程可用但命名層面仍偏向 codes。
2. 仍需持續維護各資源的 `__key_columns` 與實際 schema 一致性，避免提案審核階段出現主鍵誤判。

## 6. 近期修正紀錄（2026-02-15）

1. 提案主鍵配置修正
- `BasicInformationProposalController` 的資源主鍵配置已校正為與 schema 一致：
  - `POSSESSION_DATA`：`['c_possession_record_id']`
  - `BIOG_TEXT_DATA`：`['c_personid', 'c_textid', 'c_role_id']`
  - `BIOG_ADDR_DATA`：`['c_personid', 'c_addr_id', 'c_addr_type', 'c_sequence']`
- 影響：後續新提交提案的 `resource_data.__key_columns` 與 `resource_id` 生成邏輯一致，降低審核時誤判風險。

2. 單一數值主鍵提案補號
- 在 `BasicInformationProposalController::proposalStore()` 增加單一數值主鍵的自動補號（max + 1）流程。
- 目的：避免 `POSSESSION_DATA` 等單主鍵資源在未帶主鍵時寫入錯誤提案資料。

3. Codes 模組主鍵覆寫補齊
- `CodesController::$tablePrimaryKeyOverrides` 新增 `POSSESSION_DATA => ['c_possession_record_id']`。
- 目的：確保 `/codes/POSSESSION_DATA` 的提案主鍵判定與資料表一致。

4. 測試補強
- `tests/Feature/BasicInformationProposalTest.php`
  - 新增 `POSSESSION_DATA` 提案自動補號與 `__key_columns` 驗證。
  - 新增提案資源主鍵配置一致性檢查（addresses/texts/possessions）。
- `tests/Feature/CodesControllerTest.php`
  - 新增 `/codes/POSSESSION_DATA/proposal` 主鍵覆寫流程驗證。

## 7. 後續維護規則

- 每次新增一個 basicinformation 子資源的 proposal 入口，需同步更新：
  - 本文件第 3 節狀態表
  - `APPROVAL_FLOWS.md` 支援範圍
  - `CHANGELOG.md`（如屬對外可見變更）
