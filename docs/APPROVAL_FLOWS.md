# Proposal / Approval Flows（提案與審核通則）

本文件說明 `/codes/*` 與人物記錄（`basicinformation`）模組的提案與審核流程**通則**。

- 文檔版本：2.0
- 最後更新：2026-08-05

> 人物相關資源逐資源 × 操作的核准路徑矩陣、收斂歷史與後續方向，見
> **[PERSON_PROPOSAL_PATHS.md](./PERSON_PROPOSAL_PATHS.md)**。
> 實體聚合（office 等非人物實體）的提案見 [ENTITY_AGGREGATE_ARCHITECTURE.md](./ENTITY_AGGREGATE_ARCHITECTURE.md) §4.5。
> operations 列表「比較」功能的收斂方案見 [OPERATIONS_COMPARE_CONSOLIDATION_PLAN.md](./OPERATIONS_COMPARE_CONSOLIDATION_PLAN.md)。

## 1. 資料模型

提案不動 schema，借 `operations` 表承載：

- `op_type`：`8`（TYPE_PROPOSAL_CREATE）／`9`（TYPE_PROPOSAL_UPDATE）／`10`（TYPE_PROPOSAL_DELETE）。
- `resource`＝下層表名、`resource_id`＝主鍵（query-string 格式，`CompositePrimaryKey::buildStoredResourceId()`）。
- `resource_data`＝提案內容＋控制鍵（`__` 前綴、核准套用前由 `sanitizePayload()` 剝除）：

  | 控制鍵 | 寫入時機 | 用途 |
  |---|---|---|
  | `__proposal_meta` | 提交 | action／table／display_name／submitted_by(_id)／submitted_at／comment；撤回與修改時追記 |
  | `__review_status` | 提交＋審核 | `pending` → `approved`／`rejected`／`cancelled` |
  | `__key_columns` | 提交 | 主鍵欄清單，核准端據此重建 targetPk |
  | `__proposal_aux` | 提交（限 postings／possessions／events／kinship／assoc） | 主表白名單外的副表／鏡像意圖（如 `c_addr`），核准時併回 changes |
  | `__reviewed_by(_id)`／`__reviewed_at`／`__review_comment` | 審核 | 審核者與備註 |
  | `__applied_operation_id` | 核准（handler 重放路徑） | 實際落庫的 direct operation id；operations 列表據此把 audit_log 認領回提案列（「比較」按鈕） |

- `resource_original`＝提交當下的原始列快照（update／delete 提案）；核准時用於定位目標與計算 delta，restore 功能也讀它。

**payload 的語義注意**：`resource_data`／`resource_original` 是**快照**——可能含系統代管欄（稽核欄四欄）；
handler 的 `changes` 是**使用者意圖**——白名單刻意不含稽核欄。核准端負責兩者間的翻譯（見 §3）。
這個「快照 vs 意圖」的張力是既有建模的已知缺陷，長期方向見 PERSON_PROPOSAL_PATHS.md §7。

## 2. 提交端

| 入口 | 現況 |
|---|---|
| **`/api/v2/mutate`（`mode=proposal`）** | **現役唯一的人物記錄提案入口**。React 13 個編輯器全走此路；欄位白名單於提交當下生效，稽核欄等系統欄根本進不了 payload。create／update／delete 三種提案皆支援 |
| codes 模組（`CodesController@proposalStore/@proposalUpdate`） | 現役（codes 自有流程，不在 LegacyBladeFormGate 範圍） |
| legacy Blade（`BasicInformationProposalController@proposalStore/@proposalUpdate`） | **已下架**：flag=new 時 `LegacyBladeFormGate` 對這兩條 POST 一律回 410。此入口**沒有欄位白名單**（任何表真實欄位照單全收，含稽核欄），是 2026-08-05 髒提案事故的源頭。flag=old 回退時才放行，且 `extractFormData()` 已加剔除稽核欄的保險帶 |

- 只有活躍帳號（`is_active == 1`）能送出提案或直接儲存。
- 新增提案在提交時即檢查資料表與其它 pending 提案的主鍵衝突，避免審核階段才失敗。

## 3. 稽核欄語義（2026-08-05 定案）

- `c_modified_by/date`＝**最後一次實際寫入**：核准提案、還原（restore）都是寫入，落庫一律蓋當下，
  不從 payload／快照沿用舊值；`c_created_*` 只在 create 蓋、之後永遠沿用（建檔是歷史事實）。
- 核准署名採雙人名 **`審核人 (Proposed by: 提案人)`**（提案人取自 `__proposal_meta.submitted_by`；
  相同或缺失時只署審核人）。署名統一經 [app/Support/AuditActor.php](../app/Support/AuditActor.php)
  注入，核准期間 override、平時＝當前登入者。**新增程式碼不得直接寫 `Auth::user()->name` 進稽核欄。**
- 核准重放前，`applyViaMutationHandler()` 統一從 changes 剔除四個稽核欄（`AUDIT_COLUMNS`）；
  非 handler 表的通用路徑由 `enforceAuditFieldsForCreate/Update()` 無條件蓋章。
- 快照裡的稽核欄**保留不動**——它們是審計事實（restore／比對用），只是任何寫入路徑不得原樣回寫。

## 4. 審核流程（`/operations`）

- **核准**（`POST /operations/{operation}/approve`）：
  - 套用方式依資源分派（`OperationsProposalController::applyProposal()`），三條路徑
    （handler 重放／legacy 委派／通用行覆寫）的逐資源矩陣見 PERSON_PROPOSAL_PATHS.md §2–§3。
    多數人物資源已走 **handler 重放**：把提案還原成一次 direct mutation
    `{resource, 'direct', operation, personId, targetPk, changes}` 交回與「直接編輯」同一個
    handler，派生／白名單／引用完整性／幂等正規化全部生效，operations＋audit_log 由 handler 自寫。
  - 成功後：`__review_status='approved'`、記審核者與時間；handler 重放路徑另寫
    `__applied_operation_id`（供「比較」認領 audit）。
  - 任何路徑失敗都整筆交易回滾、提案維持待審（fail-closed）；handler 的欄位級錯誤會攤平附在
    flash 訊息後，保留對審核者的指向性。
- **退回**（`reject`）：標記 `rejected` 與備註，不動資料表。
- **修改／撤回**（提案者）：`pending`／`rejected` 可修改（狀態重設 `pending`）；撤回標記
  `cancelled`、記錄撤回者／時間／原因。
- 提案列表：`/operations?proposals_only=1`，可按狀態篩選；行內按鈕依身分顯示。

## 5. 已知限制

- `/operations` 的「修改提案／撤回提案」統一復用 codes 模組的通用提案編輯流程（`codes.proposals.*`），
  表單排列與原 Basic Information 子頁不完全一致；任官兩表（`POSTED_TO_OFFICE_DATA`／
  `POSTED_TO_ADDR_DATA`）例外、需回原頁面處理。依賴複雜聯動欄位的資源建議回原頁面重新發起提案。
- kinship／associations 的核准仍走 legacy 委派（路徑 B），其鏡像語義收斂與「比較」支援
  （`__applied_operation_id` 回報）待 PERSON_PROPOSAL_PATHS.md §6 順序處理。
- 存量舊格式提案**不做兼容與回填**（2026-08-05 維護者決策）：核准端的稽核欄剔除能讓髒 payload
  存量正常核准，但缺 `__applied_operation_id` 的歷史已核准提案「比較」維持不可用。

## 6. 參考路由

- 審核：`POST /operations/{operation}/approve`（`operations.proposals.approve`）／
  `POST /operations/{operation}/reject`（`operations.proposals.reject`）
- 人物提案（現役）：`POST /api/v2/mutate`（`mode=proposal`）
- 人物提案（legacy，flag=new 時 410）：`POST /basicinformation/{personid}/{resource}/proposal`／
  `POST /basicinformation/{personid}/{resource}/{id}/proposal`
- codes 提案：`POST /codes/{table_name}/proposal`／`POST|PATCH /codes/{table_name}/{id}/proposal`
