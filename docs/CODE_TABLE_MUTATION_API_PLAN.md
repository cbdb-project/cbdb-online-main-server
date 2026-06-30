# Code 表受審計寫入 API 建設計畫

> 狀態：計畫草案（提交討論）
> 分支：`feature/pinyin-v-to-umlaut-migration`
> 關聯計畫：[拼音 v → ü 全庫正規化遷移計畫](./PINYIN_V_TO_UMLAUT_MIGRATION.md)（本計畫為其**階段 B 的前置依賴**）
> English version: [CODE_TABLE_MUTATION_API_PLAN.en.md](./CODE_TABLE_MUTATION_API_PLAN.en.md)

## 0. 背景與動機

拼音遷移計畫的**階段 B**需要對 code／lookup 表（地名、官名、年號、書名、社會機構、行政類別等）做**受審計的批次資料修正**。修正必須走受審計流程（寫 `audit_log`）、可由外部腳本以 token 呼叫，且不得用繞過 audit 的集中式 SQL。經評估現況，此能力**大致缺失**，需新建。

## 1. 現況評估

### 1.1 `/codes` 管理介面（`CodesController`）
- **通用**：直接寫入路徑（`store`/`update`/`destroy`）由 `config/codes.php` 的 `tables` 驅動，可對任意 code 表的任意欄位寫入；複合主鍵以 URL 編碼（`col1_._col2_._col3`）處理。
- **但只寫 `operations`，不寫 `audit_log`**：三個直寫方法都呼叫 `recordOperation()` → `OperationRepository::store()`（寫 `operations` 表），**全程未呼叫 `AuditLogService::write()`**。
- **不適合外部批次**：為 `web` 路由，需 session + CSRF token；非 token API。
- 結論：適合**人工 UI 修改**（記於 `operations` 供審核），但**不滿足**「外部腳本 + 完整 audit_log」需求。

### 1.2 v2 mutation API（`/api/v2/*`）
- 具備完整審計管線：交易、`AuditLogService::write()`（寫 `audit_log`）、`OperationRepository::store()`（寫 `operations`）、`CompositePrimaryKey` 主鍵驗證、欄位白名單、變更偵測、proposal 模式。
- handler 經 `MutationHandlerRegistry` 註冊分派。
- **但 code 表幾乎沒有 handler**：唯一例外是 **`NianHaoMutationHandler`（NIAN_HAO）**——它直接繼承 `AbstractMutationHandler`、將 `person_id` 設為 `0`、照常寫 `audit_log` + `operations`。其餘 code 表（`ADDR_CODES`、`OFFICE_CODES`、`DYNASTIES`、`CHORONYM_CODES`、`ETHNICITY_TRIBE_CODES`、`TEXT_CODES`、`TEXT_INSTANCE_DATA`、`TEXT_BIBLCAT_CODES`、`GANZHI_CODES`、`SOCIAL_INSTITUTION_*`、`ADMIN_CAT_CODES` 等）**皆無 handler**。

### 1.3 結論
- **審計與主鍵基礎設施已就緒**（`NianHaoMutationHandler` 已證明 code 表可走 mutation API 並完整審計）。
- **缺的是各 code 表的 handler 與註冊**，以及一個讓擴充最小化的共用基底。
- 規模估計：**中等**（約 300–500 LOC，視表數與是否抽共用基底）。

## 2. 目標與範圍

- **目標**：讓外部腳本能以 **Bearer token**、經 **audit_log** 審計、可複核地修改 code 表欄位（首要為拼音欄位，但設計為通用欄位寫入）。
- **範圍**（對齊拼音遷移階段 B）：`ADDR_CODES`、`OFFICE_CODES`、`DYNASTIES`、`NIAN_HAO`（已具備，作為樣板）、`CHORONYM_CODES`、`ETHNICITY_TRIBE_CODES`、`TEXT_CODES`、`TEXT_INSTANCE_DATA`、`TEXT_BIBLCAT_CODES`、`GANZHI_CODES`、`SOCIAL_INSTITUTION_NAME_CODES`、`SOCIAL_INSTITUTION_TYPES`、`SOCIAL_INSTITUTION_ALTNAME_DATA`、`ADMIN_CAT_CODES`。
- **非目標**：不改既有 person sub-resource handler 行為；本計畫不強制改 `/codes` UI（其補 audit 為可選後續，見 §5）。

## 3. 可重用的現有基礎

- `app/Services/AuditLogService.php`：`write()` 已支援任意表名。
- `OperationRepository::store()`：`personId` 可為 `0`（code 表適用）。
- `app/Support/CompositePrimaryKey.php`：`SCHEMAS` 需登錄 code 表主鍵；`validateOrFail()`、`buildStoredResourceId()` 可重用。
- `MutationHandlerRegistry` / `Api/MutationController` / `MutationReadService`：handler 註冊、分派與 resource 定義。
- **樣板**：`app/Services/Mutations/NianHaoMutationHandler.php`（code 表審計寫入的可行範例）。

## 4. 設計

- **共用基底 `AbstractCodeTableMutationHandler`**：整合交易、`audit_log`、`operations`、PK 驗證、欄位白名單、變更偵測等樣板邏輯；具體每表 handler 只需實作 `tableName()`、`resourceAliases()`、`keyColumns()`、`allowedFields()` 等少量方法（將 `NianHaoMutationHandler` 重構為此基底的第一個使用者，消除其「傳了 person_id 卻忽略」的設計異味）。
- **`person_id` 契約**：`MutationController` 目前對所有 resource 都要求 `person_id`。對 code 表 resource 應**讓 `person_id` 可選**（或提供 code 表專用解析路徑），避免硬塞無意義的 `person_id`。此調整需確保既有 person sub-resource handler 行為不變。
- **主鍵**：
  - 單鍵與複合鍵（如 `TEXT_INSTANCE_DATA` 3 鍵）登錄 `CompositePrimaryKey::SCHEMAS`。
  - **無主鍵特例 `SOCIAL_INSTITUTION_ALTNAME_DATA`**：須定合成識別策略（以可唯一定位的欄位組合）或排除於 API 之外、人工處理。
- **認證／授權**：沿用 Sanctum **Bearer token**、`active` 且非 crowdsourcing（`canWriteDirectly()`）；保留 `direct` / `proposal` 兩模式。
- **端點**：沿用 `/api/v2/mutate`、`/api/v2/create`、`/api/v2/delete`，以 `resource` 字串路由到對應 code 表 handler。
- **resource_id 編碼一致性**：複合主鍵的 `resource_id` 須與 `CodesController` / `OperationsController` 既有格式對齊，避免 `operations` 連結解析失準。

## 5. 實作步驟

1. 在 `CompositePrimaryKey::SCHEMAS` 登錄各 code 表主鍵（單鍵與複合鍵）。
2. 在 `MutationReadService` 的 definitions 新增各 code 表 resource（`person_id_column: null` + aliases）。
3. 新建 `AbstractCodeTableMutationHandler` 共用基底。
4. 為各表新建 concrete handler（首要 `update`；必要時 `create` / `delete`），並把 `NianHaoMutationHandler` 重構至新基底。
5. 在 `MutationHandlerRegistry` 註冊各 handler。
6. 調整 `MutationController`：code resource 時 `person_id` 可選。
7. 處理無主鍵表特例（`SOCIAL_INSTITUTION_ALTNAME_DATA`）。
8. 測試：每表 `update` + `audit_log` 斷言（old/new、operation_id）、複合主鍵解析、授權（active／非 crowdsourcing）、`direct`/`proposal` 模式、SQLite/MariaDB 相容。

## 6. 風險與注意事項

- **`person_id` 契約改動**：須回歸測試既有 person sub-resource handler 不受影響。
- **無主鍵表**：`SOCIAL_INSTITUTION_ALTNAME_DATA` 不可走逐列審計，需特例。
- **複合主鍵 resource_id 一致性**：與既有 `CodesController` / `OperationsController` 編碼對齊。
- **UI 與 API 落差**：`/codes` UI 仍只寫 `operations`、不寫 `audit_log`；若要 UI 與 API 一致，可在後續把 `CodesController` 直寫路徑補上 `AuditLogService::write()`（本計畫列為可選後續）。
- **資料庫相容**：遵守 `is_mysql()` / `is_sqlite()`。

## 7. 與拼音遷移計畫的關係

- 本計畫是 [拼音遷移計畫](./PINYIN_V_TO_UMLAUT_MIGRATION.md) **階段 B（其他非人名拼音欄位）的前置依賴**。
- 完成本 API 後，階段 B 的 code 表拼音修正即可比照階段 A 人名，以**外部腳本 + 受審計 mutation API** 進行。
- 階段 A（人名）**不依賴**本計畫——人名走既有的 `basicinformation` / `altnames` mutation handler 即可。

## 8. 待辦帳本

- [ ] 登錄 code 表主鍵於 `CompositePrimaryKey::SCHEMAS`
- [ ] `MutationReadService` 新增 code 表 resource 定義（`person_id_column: null`）
- [ ] 新建 `AbstractCodeTableMutationHandler` 共用基底
- [ ] 各 code 表 concrete handler（`update` 起步），重構 `NianHaoMutationHandler` 至新基底
- [ ] `MutationHandlerRegistry` 註冊
- [ ] `MutationController`：code resource 的 `person_id` 改為可選（並回歸既有 handler）
- [ ] 無主鍵表 `SOCIAL_INSTITUTION_ALTNAME_DATA` 特例處理
- [ ] 測試（update / audit / 複合主鍵 / 授權 / 模式 / 相容）
- [ ] （可選後續）`CodesController` 直寫路徑補 `AuditLogService::write()`，使 UI 與 API 審計一致
- [ ] 文件同步：`AGENTS.md` 模組入口、必要時 `CHANGELOG.md`
