# BasicInformation Write Architecture Refactor Plan

## 1. Goal
在不改變既有對外行為的前提下，將 BasicInformation 寫入流程從「大型 repository + 混合語義記錄」重構為「workflow 分層 + 單一操作語義」。

核心目標：
- 降低 `BiogMainRepository` 複雜度。
- 以 workflow（業務流程）為單位拆分寫入責任，而非按資料表分割。
- 明確區分 `operations`（操作語義）與 `audit_log`（資料變更明細）的責任邊界。

## 2. Scope and Non-Goals
### Scope
- BasicInformation 各子模組寫入路徑（Office、Status、Event、Assoc、Kinship、Entry、Possession、SocialInst、Source、Texts、Addresses、Altnames）。
- Repository 與 Controller 的責任重整。
- `operations` / `audit_log` 產生點與關聯方式整理。

### Non-Goals
- 不做功能擴充。
- 不變更資料庫 schema。
- 不改既有路由、API 合約與 UI 互動行為。

## 3. Architecture Principles
### 3.1 Workflow-Based Split (Not Table-Based)
跨表寫入（例如任官）本質上是單一 workflow，需在同一交易內維持一致性。  
因此採「一個 workflow 一個 entry point」原則，避免「一表一 repository」造成交易與邏輯碎片化。

### 3.2 Operation vs Audit Layering (2026-02-16 Consensus)
為避免「一次使用者操作對應多筆 operations」的語義混亂，記錄責任需分層：

- Controller / Application 層（互動語義層）：
  - 定義一次使用者操作邊界。
  - 建立 `operations` 主記錄（operation-level）。
  - 原則：一次互動操作對應一筆主 `operations`（除非規格明確例外）。

- Repository 層（資料持久層）：
  - 處理 underlying tables 寫入、交易與資料一致性。
  - 建立 `audit_log` 明細（table-level / row-level before-after）。
  - 原則：一筆 `operations` 可對應多筆 `audit_log`。

## 4. Current State Snapshot (2026-02-16)
- `BiogMainRepository` 仍為大型 façade，但已完成部分 workflow 內部拆分與委派。
- Office workflow 已抽出至 `OfficePostingRepository`，並支援同交易中同步三表與審計資料。
- Status/Event workflow 已抽出至 `EventStatusRepository`。
- `/operations` 已可用 `audit_log.operation_id` 聚合顯示多筆明細 diff。
- 仍處於過渡期：部分路徑尚由 Repository 直接寫 `operations`，需逐步上移到語義層。

## 5. Target Architecture
### 5.1 Repository Layout (Workflow-Oriented)
1. `OfficePostingRepository`
2. `RelationshipRepository`
3. `EventStatusRepository`
4. `EntryRepository`
5. `PossessionRepository`
6. `SocialInstitutionRepository`
7. `BiogSourceRepository`（或 `SourceRepository`）

`BiogMainRepository` 最終保留：
- `BIOG_MAIN` 主記錄讀寫
- 人物聚合查詢（`byPersonId`、`byIdWith*`）
- 人名/拼音等人物中心邏輯
- 作為過渡期 façade（逐步縮小）

### 5.2 Logging Responsibility
- `operations`：Controller / Application service 產生。
- `audit_log`：Repository 產生。
- 透過 operation context（同一 operation id）串接一筆主操作與多筆資料明細。

## 6. Migration Roadmap
### Phase A — Baseline Lock
- 固定核心回歸測試（Office 為首要守門）。
- 建立主要寫入方法呼叫清單，明確影響面。

### Phase B — Office Workflow Extraction
- 搬移 `officeStoreById` / `officeUpdateById` / `officeDeleteById` 與 helper 至 `OfficePostingRepository`。
- `BiogMainRepository` 保留同名方法，轉呼叫新 repository。

### Phase C — Controller Duplicate Removal
- 將 `BasicInformationOfficesController::saveas()` 重複寫入收斂到 repository（`officeCloneById`）。

### Phase D — Remaining Workflows (Incremental Batches)
1. Status + Event  
2. Assoc + Kinship  
3. Entry + Possession + SocialInst + Source

每批遵循「先內部搬移，後替換呼叫點」。

### Phase E — Operation Layer Realignment
- 將 `operations` 主記錄建立點逐步上移至 Controller / Application service。
- Repository 端最終收斂為只寫 `audit_log` 明細。
- 驗證準則：一次操作 = 1 筆 `operations` + N 筆 `audit_log`。

### Phase F — Final Cleanup
- 清理 `BiogMainRepository` 過渡方法與註記。
- 同步更新文檔（本檔、`AGENTS.md`、必要的 CHANGELOG）。

## 7. Risks and Mitigations
- 風險：交易被拆散，導致跨表不一致。  
  對策：workflow repository 僅暴露交易入口，不暴露碎片 API。

- 風險：`resource_id` / `resource_data` 格式漂移，影響比對與復原。  
  對策：持續使用 `CompositePrimaryKey` 與既有斷言測試。

- 風險：過渡期雙軌寫入（Controller 與 Repository）造成語義分叉。  
  對策：先引入 operation context，再分批上移 `operations` 產生點。

## 8. Quality Gates
- 受影響模組必跑對應 Feature 測試。
- 每個 phase 完成時需提供：
  - 受影響方法清單
  - 新增/調整測試清單
  - 與 baseline 差異說明（無行為差異或明確列差異）

## 9. Execution Records
### 2026-02-12 — Phase A Completed
- 測試：
  - `./vendor/bin/phpunit tests/Feature/OfficePostingStoreTest.php tests/Feature/OfficeAddressOperationLoggingTest.php tests/Feature/OfficeIdChangeAddressLossTest.php`
  - `OK (17 tests, 59 assertions)`
- 完成 Office 寫入呼叫點盤點。

### 2026-02-13 — Phase B Completed
- `officeStoreById` / `officeUpdateById` / `officeDeleteById` 及 helper 搬移至 `OfficePostingRepository`。
- `BiogMainRepository` 改為委派。
- 測試維持全綠（同 Phase A 套件）。

### 2026-02-13 — Phase C Completed
- `BasicInformationOfficesController::saveas()` 收斂至 `officeCloneById`。
- 移除 controller 內重複地址寫入邏輯。
- 測試：
  - `./vendor/bin/phpunit tests/Feature/OfficePostingStoreTest.php tests/Feature/OfficeAddressOperationLoggingTest.php tests/Feature/OfficeIdChangeAddressLossTest.php tests/Feature/BasicInformationOfficesSaveAsTest.php`
  - `OK (18 tests, 66 assertions)`

### 2026-02-14 — Phase D Batch 1 Completed
- `STATUS_DATA` / `EVENTS_DATA`（含 `EVENTS_ADDR`）遷移至 `EventStatusRepository`。
- `BiogMainRepository` 完成委派。
- 測試：
  - `./vendor/bin/phpunit tests/Feature/EventStatusWriteActionsTest.php`
  - `OK (6 tests, 35 assertions)`

### 2026-02-17 — Transitional Hardening (BasicInformation Core Writes)
- `ALTNAME_DATA`、`BIOG_ADDR_DATA`、`BIOG_TEXT_DATA`、`ENTRY_DATA` 的主要寫入路徑已收斂至 repository 寫入與審計流程。
- 補強「不存在記錄」防護：
  - 更新流程對 repository 回傳 `null` 統一改為 `404`（避免 `CompositePrimaryKey::buildUrl()` 型別錯誤導致 `500`）。
  - 刪除流程對 repository 回傳 `false` 統一改為 `404`（避免刪除假成功提示）。
- 修正 ALTNAME 舊格式主鍵解析中的 `c_sequence = "NULL"`，確保提案更新可正確命中 nullable PK 記錄。
- 測試：
  - `./vendor/bin/phpunit tests/Feature/BasicInformationAltnamesControllerTest.php tests/Feature/BasicInformationTextsControllerTest.php`
  - `./vendor/bin/phpunit`（全量）

## 10. Version
- Version: 1.1
- Date: 2026-02-17
