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
| **B** | **legacy 委派** | `applyKinship/AssocProposal()` | 重建假 `Request` → `BiogMainRepository::*StoreById/*UpdateById` | ⚠ legacy 那一份（與 v2 handler 是**兩份實作**） | ✅ 委派端自寫 | ⚠ 靠測試與共用 mirror helper 手動維持 |
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
| postings | `POSTED_TO_OFFICE_DATA` | v2 handler | ✅ **A** | ✅ **A** | ✅ **A** |
| possessions | `POSSESSION_DATA` | v2 handler | ✅ **A** | ✅ **A** | ✅ **A** |
| events | `EVENTS_DATA` | v2 handler | ✅ **A** | ✅ **A** | ✅ **A** |
| kinship | `KIN_DATA` | v2 handler | ⚠ B | ⚠ B | ❌ **C** ＋ 鏡像同步 |
| associations | `ASSOC_DATA` | v2 handler | ⚠ B | ⚠ B | ❌ **C** ＋ 鏡像同步 |
| **biogmain** | `BIOG_MAIN` | v2 handler | ✅ **A** | ✅ **A** | ✅ **A**（軟刪除） |

**注意分派順序**：`applyProposal()` 先判 A，再判 DELETE→C，才輪到 B（kinship／associations）的
兩個分支。委派檔（B）的 DELETE 因此其實不走 B、而是掉進 C，只是 `approve()` 另外替 ASSOC／KIN
補了反向鏡像刪除。

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

### 4.5 段二：postings／possessions／events（單人屬性、無鏡像）收斂到路徑 A

這 3 張表的委派實作（`applyOfficeProposal`／`applyPossessionCreateProposal`／
`applyPossessionUpdateProposal`／`applyEventProposal`）與 kinship／associations 不同，
**不涉及「兩人互為鏡像」**——地址副表（`POSTED_TO_ADDR_DATA`／`POSSESSION_ADDR`）是單一人物
記錄自己的子表，不是另一個人身上的鏡像列，故遷到路徑 A 不需要重新裁定鏡像語義。

**唯一需要解決的落差**：地址副表意圖（`c_addr`／`c_addr_id`／`c_addr_cleared`）從不屬於主表
欄位白名單，提案送出時只存進 `__proposal_aux`（`data`/`original` 的行快照抓不到）。解法：
`applyViaMutationHandler()` 新增 `$auxiliaryPayload` 參數，create／update 皆將其併入 `changes`——
`PostingMutationHandler`／`PossessionMutationHandler`／`EventMutationHandler`／對應
`*CreateHandler` 的 `handle()` 本就會從 `changes` 抽出這些鍵（direct 路徑既有邏輯），故此舉
只是把「地址意圖從哪裡讀」對齊到 handler 既有的讀取點，不是新邏輯。其餘 7 張已收斂的表
無此類副表，`$auxiliaryPayload` 恆為 `[]`，合併為 no-op。

DELETE 一併收斂（非本文先前建議的「最後做」）：這 3 張表的 `*DeleteHandler` 於 direct 模式
委派既有的 `BiogMainRepository::officeDeleteById()`／`possessionDeleteById()`——與去級聯
Phase 1 末批前置（見 `docs/CASCADE_TO_RESTRICT_MIGRATION_NOTES.md` §11）同一批修正過的
「先子後父」「父列僅在無剩餘引用時才刪」邏輯，本就是目前生產環境 direct API／Blade 表單共用
的路徑，比路徑 C 另外维護的 `deleteOfficeAuxiliaryTables`／`deletePossessionAuxiliaryTables`
更貼近實際使用的程式碼，故一併移除、不再分階段。

移除的死碼：`applyOfficeProposal`／`applyPossessionCreateProposal`／
`applyPossessionUpdateProposal`／`applyEventProposal`、`buildLegacyOfficeId`／
`buildLegacyEventId`、`applyDeleteProposal()` 內的 office／possession 特例、
`deleteOfficeAuxiliaryTables`／`deletePossessionAuxiliaryTables`（`applyProposal()` 的
`HANDLER_ROUTED_RESOURCES` 判斷在這些分支之前，加入後這些分支恆不可達）。

`BiogMainRepository::officeStoreById/officeUpdateById/possessionStoreById/possessionUpdateById/
eventStoreById/eventUpdateById` **未刪除**：Blade 版 `BasicInformationOfficesController`／
`BasicInformationPossessionController`／`BasicInformationEventsController` 仍在用（AGENTS.md
所述 flag-gated 回退頁面），這幾個 repository 方法繼續服務直接編輯，只是核准不再另外呼叫它們。

### 4.6 段三：`BIOG_MAIN`（人物主檔）收斂到路徑 A——三種操作各按 direct 語義路由

依 §6 建議先查清三種操作的實際語義後收斂（不照抄 update 的形狀硬套）：

| 操作 | 重放對象 | 語義決定 |
|---|---|---|
| UPDATE | `BiogMainMutationHandler`（direct） | 唯一有活提交端的操作（v2 `mode=proposal` 與 legacy Blade `action=proposal` 兩條）。核准＝把提案 delta（`diff(original, data)`，BLOCKED_FIELDS 由 handler 濾除）套用到**當下**資料列並重跑 `BasicInformationRequest` 驗證。「名（中）／拼音名不可清空」護欄由 handler 的 `validationRules($original)` 提供（原值非空才掛 required，§5.2 確認之等價護欄），控制器層的 `NO_CLEAR_COLUMNS_ON_APPLY`／`assertNoClearColumns`／`tableModelMap` Eloquent 分支隨之移除（收斂後不可達） |
| DELETE | `BiogMainDeleteHandler`（direct） | **現行沒有任何提交端會產生 `BIOG_MAIN` 的 TYPE_PROPOSAL_DELETE**（眾包刪除走 op_type=4＋crowdsourcing_status=2，另一條審核流）；此路由是防禦性封洞——收斂前通用 `applyDeleteProposal()` 會對 BIOG_MAIN 做**物理 DELETE**，與 direct 的軟刪除（`c_name_chn='<待删除>'` 的 UPDATE）語義相反，且在入邊 FK 尚為 CASCADE 期間會靜默連鎖刪除 25 張子表資料（見 CASCADE_TO_RESTRICT_MIGRATION_NOTES.md §11.1） |
| CREATE | `BiogMainCreateHandler`（direct） | 僅 legacy 提交路由理論可達（UI 不產生；人物新增另有流程）。重放帶 `c_personid` 驗證（非 0、不得已存在、不得過大）與欄位白名單，取代先前的盲 Eloquent create |

回歸測試：`tests/Feature/BiogMainProposalTest.php`（含軟刪除取代物理 DELETE、create 撞既有
personid fail-closed、清空名（中）被 handler 驗證擋下）。核准失敗訊息現會攤平 handler 的
欄位級錯誤（如「參數校驗失敗：名不能為空」），對審核者保留指向性。

---

## 5. 還沒做什麼

### 5.1 委派檔 2 個資源（路徑 B）——kinship／associations，暫緩

`kinship`／`associations` 的核准仍走 legacy repository，而**直接編輯走 v2 handler**
（例如 `AssociationMutationHandler` 自己 `DB::table('ASSOC_DATA')` 寫，只共用
`syncAssocMirrorOnUpdate` 這個 mirror helper）。兩者是**獨立的寫實作**，
靠測試與共用 helper 手動維持對齊——程式註解可見這個持續校準的痕跡
（如 `#82：核准 CREATE 啟用鏡像衝突/疑似偵測（對齊 v2 direct create）`）。

**為何暫緩**：`KIN_DATA`／`ASSOC_DATA` 每筆記錄描述的是**兩個人之間**的關係，資料庫裡各自
存一份互為鏡像的列（正向／反向）。核准路徑的鏡像語義（`MirrorConflictException`／
`MirrorSuspectedException`／`MirrorIntegrityException`、缺鏡像 backfill、刪除時的
`$force=true` 廣集孤兒語義）都實作在 legacy 那側，有十餘個測試釘著。這與 §4.5 的地址副表
不同——地址副表是單一人物自己的子表，鏡像卻是**另一個人身上的另一列**，兩人若分別從各自
頁面獨立提案／編輯同一組關係，核准時要怎麼判定衝突、要不要覆寫對面、由誰的版本為準，
是尚未理清的領域決策，遷到路徑 A 前需要先裁定清楚，不只是接線。**本輪刻意不動**。

### 5.2 `BIOG_MAIN`——✅ 已收斂（見 §4.6）

原記載的前置確認（handler 側「不可清空」護欄）與 CREATE／DELETE 語義查證均已完成並落地，
三種操作全部路由到對應 direct handler 重放。

### 5.3 提交端的第二條路徑（legacy）

`BasicInformationProposalController`（`POST basicinformation/{personid}/{resource}/proposal`）是
Blade 時代的提案提交入口，涵蓋全部 13 個資源，**直接 `recordProposalOperation()` 存行快照、
繞過 v2 handler 的驗證**。React 編輯器已全部改走 `/api/v2`，但這些路由**仍然掛著、未下架**。
只要它還在，就可能繞過提交端驗證產生「存量壞提案」——目前靠核准端的 handler 重放（路徑 A）兜住，
但路徑 B 的 kinship／associations 沒有這層保護。

---

## 6. 建議順序

1. ~~**`BIOG_MAIN` 收斂到 A**~~ ✅ 已完成（§4.6，三種操作各按 direct 語義路由）。
2. **kinship／associations 收斂到 A**（需先裁定鏡像語義）：建議單獨開分支、單獨 review，
   因為它改的是核准時的鏡像衝突行為——這是唯一還需要「重新裁定域邏輯」而非單純接線的項目。
3. **下架 legacy 提交路徑**：確認 Blade 頁全數不再使用後移除路由，消滅繞過提交端驗證的入口。

完成 2 後，`OperationsProposalController` 的路徑 B／C 可整段移除
（含 `applyKinship/AssocProposal`、`applyCreate/Update/DeleteProposal` 等重複實作；
`tableModelMap` 與 `NO_CLEAR_COLUMNS_ON_APPLY` 已隨 §4.6 移除），
控制器塌成「decode → resolve → handle → updateStatus」。

---

## 7. 相關程式碼

- [app/Http/Controllers/OperationsProposalController.php](../app/Http/Controllers/OperationsProposalController.php)
  ——`HANDLER_ROUTED_RESOURCES`、`applyProposal()`、`applyViaMutationHandler()`
- [app/Services/Mutations/MutationHandlerRegistry.php](../app/Services/Mutations/MutationHandlerRegistry.php)
- [app/Services/Mutations/AbstractPersonSubresourceCreateHandler.php](../app/Services/Mutations/AbstractPersonSubresourceCreateHandler.php)
  ／[…MutationHandler.php](../app/Services/Mutations/AbstractPersonSubresourceMutationHandler.php)
- [app/Services/Mutations/PostingMutationHandler.php](../app/Services/Mutations/PostingMutationHandler.php)
  ／[PossessionMutationHandler.php](../app/Services/Mutations/PossessionMutationHandler.php)
  ／[EventMutationHandler.php](../app/Services/Mutations/EventMutationHandler.php)（地址副表 direct 同步邏輯）
- [app/Services/Mutations/BiogMainMutationHandler.php](../app/Services/Mutations/BiogMainMutationHandler.php)（§5.2 不可清空護欄）
- [app/Http/Controllers/BasicInformationProposalController.php](../app/Http/Controllers/BasicInformationProposalController.php)（legacy 提交端）
- 測試：`tests/Feature/OperationsProposalControllerTest.php`、`tests/Feature/BasicInformationProposalTest.php`
