# 人物提案（proposal）路徑現況

> 本文只描述**人物相關記錄**（`BIOG_MAIN` 與 12 個人物子資源）的提案提交／核准路徑現況：
> 哪些已收斂到「核准＝重放 direct handler」，哪些仍是獨立實作，各自的具體風險是什麼。
> 實體聚合（office／social-institution 等非人物實體）的提案見
> [ENTITY_AGGREGATE_ARCHITECTURE.md](./ENTITY_AGGREGATE_ARCHITECTURE.md) §4.5。

---

## 1. 問題的本質：核准 ≠ 直接編輯

`operations` 的資源模型是 `{resource, resource_id, resource_data}`——天生表達的是**一張下層表的一列**。
提案送出時被拍平成行快照，核准時再把那一列蓋回去。於是「核准」與「直接編輯」是**兩份獨立的寫實作**：
直接編輯走 v2 mutation handler（派生、白名單、引用完整性、幂等正規化、operations＋audit_log），
核准卻可能繞過其中一部分或全部。

修法（§4.5）：**提案＝一次延遲執行的 direct mutation**。核准時不是「蓋一列」，而是把提案還原成
`{resource, 'direct', operation, personId, targetPk, changes}`，交回**同一個 handler** 重放。
direct 與 proposal 因此天然對等，不需要兩邊各自維護。

---

## 2. 目前並存的三條核准路徑

核准全部經過 `OperationsProposalController::applyProposal()`，依序分派到三條路徑之一：

| # | 路徑 | 實作 | 寫入方式 | 派生／護欄／正規化 | operations＋audit | 對等性 |
|---|---|---|---|---|---|---|
| **A** | **handler 重放**（本次收斂到的目標） | `applyViaMutationHandler()` → `MutationHandlerRegistry` | 呼叫 v2 handler 的 direct 路徑 | ✅ 全部（與直接編輯同一份程式） | ✅ handler 自寫 | ✅ **逐位一致** |
| **B** | **legacy 委派** | `applyKinship/Assoc/Office/Possession/EventProposal()` | 重建假 `Request` → `BiogMainRepository::*StoreById/*UpdateById` | ⚠ legacy 那一份（與 v2 handler 是**兩份實作**） | ✅ 委派端自寫 | ⚠ 靠測試與共用 mirror helper 手動維持 |
| **C** | **通用行覆寫** | `applyCreate/Update/DeleteProposal()` | `DB::table()->insert/update/delete` 蓋行快照 | ❌ 全部繞過 | ⚠ 由 `approve()` 事後補記 | ❌ **盲寫，會落殘缺資料** |

> 路徑 C 是原始狀態：核准把 `resource_data` 當成「這一列該長的樣子」直接蓋上去。
> 引用不存在的碼、缺配套欄、未經幂等正規化的值——一律照單全收。

---

## 3. 逐資源現況總表

「提交端」＝使用者送出提案時經過什麼；「核准端」＝審核通過落庫時走上表哪條路徑。

| 資源（API 名） | 下層表 | 提交端 | 核准 CREATE | 核准 UPDATE | 核准 DELETE |
|---|---|---|---|---|---|
| altnames | `ALTNAME_DATA` | v2 handler | ✅ **A** | ✅ **A** | ✅ **A** |
| addresses | `BIOG_ADDR_DATA` | v2 handler | ✅ **A** | ✅ **A** | ✅ **A** |
| entries | `ENTRY_DATA` | v2 handler | ✅ **A** | ✅ **A** | ✅ **A** |
| statuses | `STATUS_DATA` | v2 handler | ✅ **A** | ✅ **A** | ✅ **A** |
| texts | `BIOG_TEXT_DATA` | v2 handler | ✅ **A** | ✅ **A** | ✅ **A** |
| sources | `BIOG_SOURCE_DATA` | v2 handler | ✅ **A** | ✅ **A** | ✅ **A** |
| social_institutions | `BIOG_INST_DATA` | v2 handler | ✅ **A** | ✅ **A** | ✅ **A** |
| kinship | `KIN_DATA` | v2 handler | ⚠ B | ⚠ B | ❌ **C** ＋ 鏡像同步 |
| associations | `ASSOC_DATA` | v2 handler | ⚠ B | ⚠ B | ❌ **C** ＋ 鏡像同步 |
| postings | `POSTED_TO_OFFICE_DATA` | v2 handler | ⚠ B | ⚠ B | ❌ **C** ＋ 副表清理 |
| possessions | `POSSESSION_DATA` | v2 handler | ⚠ B | ⚠ B | ❌ **C** ＋ 副表清理 |
| events | `EVENTS_DATA` | v2 handler | ⚠ B | ⚠ B | ❌ **C** |
| **biogmain** | `BIOG_MAIN` | v2 handler | ❌ **C** | ❌ **C**（Eloquent＋不可清空守衛） | ❌ **C** |

**注意分派順序**：`applyProposal()` 先判 A（已路由的 7 張表，三種操作全收），再判 DELETE→C，
才輪到 B 的各表分支。所以**委派檔（B）的 DELETE 其實不走 B、而是掉進 C**，
只是 `approve()` 另外替 ASSOC／KIN 補了反向鏡像刪除、替 office／possession 補了副表清理。

---

## 4. 已經做了什麼

### 4.1 段一：7 張人物子資源表全部收斂到路徑 A

`OperationsProposalController::HANDLER_ROUTED_RESOURCES` 建立「表名 → API resource」對照，
核准時重建意圖並重放 direct handler。三個 commit：

1. **UPDATE／DELETE**（`d2350296`）——先收斂風險低的兩種操作。
2. **office 實體級提案**（`eb29d292`）——非人物範圍，見實體架構文件。
3. **CREATE 補完**（`d604293b`）。

### 4.2 CREATE 為何一度被排除，以及真正的原因

段一原本把 CREATE 排除，理由記為「direct-create handler 帶『同主鍵已有待審核提案則拒』
去重護欄，核准時會被自己擋下」。**該理由過寬**，查證後只對 sources 成立：

| handler | 護欄位置 | direct 路徑是否經過 | 影響 |
|---|---|---|---|
| `AbstractPersonSubresourceCreateHandler`（altnames／addresses／entries／statuses／texts／social_institutions） | `handleProposal()` **內部** | ❌ 不經過 | 本來就不受影響 |
| `SourceMutationHandler`（sources） | `if ($operation === 'create')` 區塊、**在 mode 分派之前** | ✅ 會跑 | **這才是當初擋住重放的那一個** |

修法：`BiogSourceRepository::hasPendingCreateProposal()` 加 `$excludeOperationId`，
由 `meta.__approving_operation_id` 排除「正在核准的自己」。

### 4.3 收斂後實際修好的行為

- **引用完整性生效**：核准 `c_textid` 不存在的 sources create 提案 → fail-closed
  （路徑 C 會盲插一列殘缺資料）。回歸測試：`testApproveCreateProposalEnforcesHandlerValidation`。
- **幂等正規化生效**：create 補寫 legacy 哨兵 0 欄（如 `c_entry_addr_id`／`c_source`），
  與 legacy 表單一致。
- **audit／operations 由 handler 統一寫**，`approve()` 不再事後補記（`usedDirectWorkflow=true`）。
- **改鍵碰撞偵測生效**：`BIOG_SOURCE_DATA` update 改鍵撞既有列 → 409 擋下。

### 4.4 兩處刻意保留／變更的使用者可見契約

| 情況 | 決定 | 理由 |
|---|---|---|
| update／delete 缺 `resource_original` | **保留**舊訊息「缺少原始資料，無法更新／刪除。」，在路由前先擋 | 比 handler 的「主鍵格式不正確」更有指向性；此檢查只是定位目標，不重複 handler 邏輯 |
| create 目標主鍵已存在 | **改用** handler 訊息「目標主鍵已存在」 | handler 於 `preprocessCreateData` 正規化後才比對主鍵（如 -999→0）；在控制器補一份預檢查會與正規化分歧，是真的正確性風險。行為契約（擋下／維持待審／無副作用）不變 |

---

## 5. 還沒做什麼

### 5.1 委派檔 5 個資源（路徑 B）——create／update 仍是兩份實作

`kinship`／`associations`／`postings`／`possessions`／`events` 的核准走 legacy repository，
而**直接編輯走 v2 handler**（例如 `AssociationMutationHandler` 自己 `DB::table('ASSOC_DATA')` 寫，
只共用 `syncAssocMirrorOnUpdate` 這個 mirror helper）。兩者是**獨立的寫實作**，
靠測試與共用 helper 手動維持對齊——程式註解可見這個持續校準的痕跡
（如 `#82：核准 CREATE 啟用鏡像衝突/疑似偵測（對齊 v2 direct create）`）。

**為何比段一難**：核准路徑的鏡像語義（`MirrorConflictException`／`MirrorSuspectedException`／
`MirrorIntegrityException`、缺鏡像 backfill、刪除時的 `$force=true` 廣集孤兒語義）都實作在 legacy 那側，
有十餘個測試釘著。遷到 A 等於**在語義層重新裁定「核准時採用哪一套鏡像行為」**——是領域決策，不只是接線。

### 5.2 `BIOG_MAIN`（路徑 C）——人物主記錄的核准仍是盲寫

`BIOG_MAIN` 的 update 提案核准走通用 Eloquent 更新（`tableModelMap` → `BiogMain::update()`），
直接編輯卻走 `BiogMainMutationHandler`。要收斂需先確認 handler 側具備等價於
`NO_CLEAR_COLUMNS_ON_APPLY`（`c_mingzi_chn`／`c_mingzi` 不可被清空）的護欄，否則會失去這道保護。

### 5.3 DELETE（依指示暫不處理）

委派檔 5 個資源的 DELETE 目前掉進路徑 C，另由 `approve()` 補鏡像刪除與副表清理。
這是鏡像機制最敏感的部分，本輪刻意不動。

### 5.4 提交端的第二條路徑（legacy）

`BasicInformationProposalController`（`POST basicinformation/{personid}/{resource}/proposal`）是
Blade 時代的提案提交入口，涵蓋全部 13 個資源，**直接 `recordProposalOperation()` 存行快照、
繞過 v2 handler 的驗證**。React 編輯器已全部改走 `/api/v2`，但這些路由**仍然掛著、未下架**。
只要它還在，就可能繞過提交端驗證產生「存量壞提案」——目前靠核准端的 handler 重放（路徑 A）兜住，
但路徑 B／C 的資源沒有這層保護。

---

## 6. 建議順序

1. **`BIOG_MAIN` 收斂到 A**（範圍小、風險可控）：先在 `BiogMainMutationHandler` 補上「不可清空」等價護欄，再路由。
2. **委派檔 create／update 收斂到 A**（需先裁定鏡像語義）：建議單獨開分支、單獨 review，
   因為它改的是核准時的鏡像衝突行為。
3. **DELETE 收斂**：最後做，鏡像刪除與副表清理需逐一對齊。
4. **下架 legacy 提交路徑**：確認 Blade 頁全數不再使用後移除路由，消滅繞過提交端驗證的入口。

完成 1–3 後，`OperationsProposalController` 的路徑 B／C 可整段移除
（含 `applyKinship/Assoc/Office/Possession/EventProposal`、`applyCreate/Update/DeleteProposal`、
`deleteOfficeAuxiliaryTables`、`deletePossessionAuxiliaryTables` 等重複實作），
控制器塌成「decode → resolve → handle → updateStatus」。

---

## 7. 相關程式碼

- [app/Http/Controllers/OperationsProposalController.php](../app/Http/Controllers/OperationsProposalController.php)
  ——`HANDLER_ROUTED_RESOURCES`、`applyProposal()`、`applyViaMutationHandler()`
- [app/Services/Mutations/MutationHandlerRegistry.php](../app/Services/Mutations/MutationHandlerRegistry.php)
- [app/Services/Mutations/AbstractPersonSubresourceCreateHandler.php](../app/Services/Mutations/AbstractPersonSubresourceCreateHandler.php)
  ／[…MutationHandler.php](../app/Services/Mutations/AbstractPersonSubresourceMutationHandler.php)
- [app/Http/Controllers/BasicInformationProposalController.php](../app/Http/Controllers/BasicInformationProposalController.php)（legacy 提交端）
- 測試：`tests/Feature/OperationsProposalControllerTest.php`、`tests/Feature/BasicInformationProposalTest.php`
