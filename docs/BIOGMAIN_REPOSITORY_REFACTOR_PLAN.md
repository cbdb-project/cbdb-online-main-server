# BiogMainRepository Refactor Plan

## Goal
在不改變既有行為的前提下，逐步降低 `BiogMainRepository`（目前約 3182 行）複雜度，並把邏輯按「業務流程」拆分，而非按資料表拆分。

## Scope and Non-Goals
- 本計畫以重構為主，不做功能擴充。
- 不變更資料庫 schema。
- 不變更既有路由與 API 合約。
- 不變更使用者可見 UI 行為。

## Current State Snapshot (2026-02-12)
- `BiogMainRepository` 仍為單一大型類別，包含 Basic Info / Office / Relations / Events / Sources 等多模組方法。
- `officeStoreById`、`officeUpdateById`、`officeDeleteById` 仍在 `BiogMainRepository`，但已具備：
  - 單一交易中同步 `POSTING_DATA`、`POSTED_TO_OFFICE_DATA`、`POSTED_TO_ADDR_DATA`
  - `operations` 寫入（含 `POSTED_TO_ADDR_DATA` 的 `rows` before/after）
  - `audit_log` 寫入
  - `resource_id` 使用 `c_office_id=...&c_posting_id=...` 格式
- 控制器仍直接依賴 `BiogMainRepository`（`BasicInformationOfficesController`），且 `saveas()` 內仍有一段與 repository 重複的寫入流程。
- 既有測試已覆蓋 Office 核心流程（`OfficePostingStoreTest`、`OfficeAddressOperationLoggingTest`、`OfficeIdChangeAddressLossTest`），可作為重構安全網。

## Why Workflow-Based Split (Not Table-Based)
Office 流程橫跨多表且要求交易一致性、操作記錄一致性、稽核一致性。若按「一表一 repository」拆分，會把同一交易流程拆散，風險高。  
因此維持「一個 workflow 一個 entry point」原則。

## Target Architecture
命名比照現有 repository 風格（功能導向、簡潔命名）：

1. `OfficePostingRepository`
2. `RelationshipRepository`
3. `EventStatusRepository`
4. `EntryRepository`
5. `PossessionRepository`
6. `SocialInstitutionRepository`
7. `BiogSourceRepository`（或 `SourceRepository`，避免與其他 source 模組混淆）

`BiogMainRepository` 保留「人物自身」相關職責，不另拆 `BasicInfoRepository`：
- `BIOG_MAIN` 主記錄讀寫
- 以人物為中心的聚合查詢（`byPersonId`、`byIdWith*`）
- 人名與拼音相關邏輯（例如 `namesByQuery`、`auto_pinyin`）

過渡期由 `BiogMainRepository` 作 façade，逐步轉呼叫新 repository，避免一次性改動大量 controller/test。

## Refactor Phases (Updated)

### Phase A — Baseline Lock (Must Do First)
- 固定 Office 流程回歸測試作為守門：
  - `tests/Feature/OfficePostingStoreTest.php`
  - `tests/Feature/OfficeAddressOperationLoggingTest.php`
  - `tests/Feature/OfficeIdChangeAddressLossTest.php`
- 以 `rg` 建立方法呼叫清單（特別是 `office*ById`）並記錄遷移範圍。

**Acceptance**
- 上述測試在重構前全綠，作為 baseline。

**Execution Record (Completed, 2026-02-12)**
- 測試命令：
  - `./vendor/bin/phpunit tests/Feature/OfficePostingStoreTest.php tests/Feature/OfficeAddressOperationLoggingTest.php tests/Feature/OfficeIdChangeAddressLossTest.php`
- 結果：
  - `OK (17 tests, 59 assertions)`
- `office*ById` 直接呼叫點（遷移影響面）：
  - `app/Http/Controllers/BasicInformationOfficesController.php`
  - `tests/Feature/OfficePostingStoreTest.php`
  - `tests/Feature/OfficeAddressOperationLoggingTest.php`
  - `tests/Feature/OfficeIdChangeAddressLossTest.php`
- `office*ById` 定義位置（待 Phase B 搬移）：
  - `app/Repositories/BiogMainRepository.php`

### Phase B — Office Workflow Internal Extraction (No External Behavior Change)
- 新增 `OfficePostingRepository`，搬移：
  - `officeStoreById`
  - `officeUpdateById`
  - `officeDeleteById`
  - `insertAddr` / `updateAddr` 等 Office 專用 helper
- `BiogMainRepository` 保留同名 public 方法，僅轉呼叫新 repository。
- 保持以下行為完全一致：
  - 回傳 `resource_id` 字串格式
  - `ValidationException` 拋出時機
  - `operations` 與 `audit_log` 內容結構
  - 交易邊界（單一 `DB::transaction`）

**Acceptance**
- Phase A 三個測試維持全綠。
- `BasicInformationOfficesController` 不需改動即可通過既有流程。

### Phase C — Remove Duplicate Office Write Logic from Controller
- 將 `BasicInformationOfficesController::saveas()` 的交易寫入流程收斂到 Office workflow repository（新增 `officeCloneById` 之類方法）。
- 移除 controller 內重複 `insertAddr` 寫法，避免雙實作漂移。

**Acceptance**
- `saveas()` 行為與既有 UI 流程一致。
- 新增/補強對 `saveas` 的 Feature 測試（至少涵蓋新增 posting + address + operation）。

### Phase D — Extract Remaining Workflows (One Module at a Time)
- 依風險與耦合度分批搬移：
  1. Status + Event
  2. Assoc + Kinship
  3. Entry + Possession + SocialInst + Source
- 每批均採「先搬內部、後替換呼叫點」策略，避免大爆炸。

**Acceptance**
- 每批搬移後，受影響測試與 smoke test 維持綠燈。

### Phase E — Final Cleanup
- 移除 `BiogMainRepository` 中已完全轉移的方法與過渡註記。
- 更新架構文檔（本檔、必要時補 `AGENTS.md` / `CHANGELOG.md`）。
## Risks and Mitigations (Reality-Based)
- 風險：交易被拆開，導致三表資料不一致  
  對策：workflow 類別只暴露交易入口，不暴露碎片化寫入 API。
- 風險：`operations.resource_id` 或 `resource_data` 格式漂移，影響復原/審計  
  對策：沿用既有 helper（`CompositePrimaryKey`）與既有測試斷言。
- 風險：控制器仍有重複寫入邏輯，重構後兩邊行為分叉  
  對策：優先完成 Phase C，確保 Office 寫入單一實作來源。

## Tracking
- 本檔為實施計畫文件，可隨重構進度更新。
- 每完成一個 phase，請在 PR 描述附上：
  - 受影響方法清單
  - 新增/調整測試清單
  - 與 baseline 的差異說明（應為「無行為差異」或明確列出差異）

### Execution Record (Completed, 2026-02-13)
- Phase B 已完成：
  - `officeStoreById`、`officeUpdateById`、`officeDeleteById` 搬移至 `app/Repositories/OfficePostingRepository.php`
  - `insertAddr`、`updateAddr` 搬移至 `app/Repositories/OfficePostingRepository.php`
  - `BiogMainRepository` 保留同名公開方法並轉呼叫 `OfficePostingRepository`
- 驗證結果：
  - `./vendor/bin/phpunit tests/Feature/OfficePostingStoreTest.php tests/Feature/OfficeAddressOperationLoggingTest.php tests/Feature/OfficeIdChangeAddressLossTest.php`
  - `OK (17 tests, 59 assertions)`

### Execution Record (Completed, 2026-02-13)
- Phase C 已完成：
  - `BasicInformationOfficesController::saveas()` 改為呼叫 repository（`officeCloneById`）
  - 新增 `OfficePostingRepository::officeCloneById()` 收斂另存交易寫入流程
  - 移除 controller 內重複的 `insertAddr` 寫入方法
  - 另存流程的 `operations.resource_id` 對齊 `CompositePrimaryKey::buildStoredResourceId()` 格式
- 驗證結果：
  - `./vendor/bin/phpunit tests/Feature/OfficePostingStoreTest.php tests/Feature/OfficeAddressOperationLoggingTest.php tests/Feature/OfficeIdChangeAddressLossTest.php tests/Feature/BasicInformationOfficesSaveAsTest.php`
  - `OK (18 tests, 66 assertions)`

### Execution Record (In Progress, 2026-02-14)
- Phase D 第一階段完成：
  - `STATUS_DATA` 相關方法遷移至 `app/Repositories/EventStatusRepository.php`
  - `EVENTS_DATA` 相關方法（含 `EVENTS_ADDR` 輔助方法）遷移至 `app/Repositories/EventStatusRepository.php`
  - `BiogMainRepository` 已完成 Status 與 Event 相關公開方法的委派呼叫
- 驗證結果：
  - `./vendor/bin/phpunit tests/Feature/EventStatusWriteActionsTest.php`
  - `OK (6 tests, 35 assertions)`
  - `./vendor/bin/phpunit --filter "CompositePrimaryKeyRoutesTest|BasicInformationPagesLoadTest"`
  - `OK (27 tests, 106 assertions)`（另有既有 PHPUnit deprecations）
  - `./vendor/bin/phpunit --filter "EventStatusWriteActionsTest|CompositePrimaryKeyRoutesTest|BasicInformationPagesLoadTest"`
  - `OK (33 tests, 141 assertions)`（另有既有 PHPUnit deprecations）

## Version
- Version: 0.5
- Date: 2026-02-14
