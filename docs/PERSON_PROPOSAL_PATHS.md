# 人物提案（proposal）路徑現況

> 本文只描述**人物相關記錄**（`BIOG_MAIN` 與 12 個人物子資源）的提案提交／核准路徑現況：
> 哪些已收斂到「核准＝重放 direct handler」，哪些仍是獨立實作，各自的具體風險是什麼，
> 以及既有建模的根本缺陷與長期方向（§7）。
> 提案通則（資料模型、審核流程、稽核欄語義）見 [APPROVAL_FLOWS.md](./APPROVAL_FLOWS.md)。
> 實體聚合（office／social-institution 等非人物實體）的提案見
> [ENTITY_AGGREGATE_ARCHITECTURE.md](./ENTITY_AGGREGATE_ARCHITECTURE.md) §4.5。

最後更新：2026-08-05

---

## 1. 問題的本質：核准 ≠ 直接編輯；快照 ≠ 意圖

`operations` 的資源模型是 `{resource, resource_id, resource_data}`——天生表達的是**一張下層表的一列**。
提案送出時被拍平成**行快照**，核准時再把那一列蓋回去。這造成兩層問題：

1. **核准與直接編輯是兩份寫實作**：直接編輯走 v2 mutation handler（派生、白名單、引用完整性、
   幂等正規化、operations＋audit_log），核准卻可能繞過其中一部分或全部。
   修法：**提案＝一次延遲執行的 direct mutation**——核准時把提案還原成
   `{resource, 'direct', operation, personId, targetPk, changes}`，交回**同一個 handler** 重放（路徑 A）。
2. **快照與意圖語義不同**：快照可含系統代管欄（稽核欄）與多表副產物；handler 的 changes 只認
   使用者意圖（白名單）。重放層必須做「快照 → 意圖」的翻譯（§4.7 的稽核欄剔除即為此），
   而這個翻譯的存在本身就說明**行快照不是提案的正確原語**——根本方向見 §7。

---

## 2. 目前並存的三條核准路徑

核准全部經過 `OperationsProposalController::applyProposal()`，依序分派到三條路徑之一：

| # | 路徑 | 實作 | 寫入方式 | 派生／護欄／正規化 | operations＋audit | 對等性 |
|---|---|---|---|---|---|---|
| **A** | **handler 重放**（收斂目標） | `applyViaMutationHandler()` → `MutationHandlerRegistry` | 呼叫 v2 handler 的 direct 路徑 | ✅ 全部（與直接編輯同一份程式） | ✅ handler 自寫，落庫 id 記回 `__applied_operation_id` | ✅ **逐位一致** |
| **B** | **legacy 委派** | `applyKinship/AssocProposal()` | 重建假 `Request` → `BiogMainRepository::*StoreById/*UpdateById` | ⚠ legacy 那一份（與 v2 handler 是**兩份實作**） | ✅ 委派端自寫；**未回報 id**（「比較」不可用） | ⚠ 靠測試與共用 mirror helper 手動維持 |
| **C** | **通用行覆寫** | `applyCreate/Update/DeleteProposal()` | `DB::table()->insert/update/delete` 蓋行快照 | ❌ 全部繞過（稽核欄除外，見 §4.7） | ⚠ 由 `approve()` 事後補記 | ❌ **盲寫，會落殘缺資料** |

> 路徑 C 是原始狀態：核准把 `resource_data` 當成「這一列該長的樣子」直接蓋上去。
> 引用不存在的碼、缺配套欄、未經幂等正規化的值——一律照單全收。
> 人物資源已全部脫離 C（kinship／assoc 的 DELETE 除外）；C 仍服務 codes 等非人物表。

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

### 4.1 段一：7 張人物子資源表收斂到路徑 A

`HANDLER_ROUTED_RESOURCES` 建立「表名 → API resource」對照，核准時重建意圖並重放 direct handler。
三個 commit：UPDATE／DELETE 先行（`d2350296`）→ office 實體級提案（`eb29d292`）→ CREATE 補完（`d604293b`）。

### 4.2 CREATE 一度被排除的真正原因

「direct-create 帶去重護欄、核准會被自己擋下」的理由**只對 sources 成立**
（`SourceMutationHandler` 的護欄在 mode 分派之前、direct 也會跑；
`AbstractPersonSubresourceCreateHandler` 系的護欄在 `handleProposal()` 內、direct 不經過）。
修法：`meta.__approving_operation_id` 讓護欄排除「正在核准的自己」。

### 4.3 收斂後實際修好的行為

- 引用完整性（核准 `c_textid` 不存在的 sources create → fail-closed，路徑 C 會盲插）。
- 幂等正規化（legacy 哨兵 0 欄補寫，與 legacy 表單一致）。
- audit／operations 由 handler 統一寫（`usedDirectWorkflow=true`，`approve()` 不再補記）。
- 改鍵碰撞偵測（`BIOG_SOURCE_DATA` update 改鍵撞既有列 → 409）。

### 4.4 兩處刻意保留／變更的使用者可見契約

| 情況 | 決定 | 理由 |
|---|---|---|
| update／delete 缺 `resource_original` | **保留**舊訊息「缺少原始資料，無法更新／刪除。」，在路由前先擋 | 比 handler 的「主鍵格式不正確」更有指向性 |
| create 目標主鍵已存在 | **改用** handler 訊息「目標主鍵已存在」 | handler 於正規化（-999→0 等）後才比對主鍵，控制器預檢查會與正規化分歧 |

### 4.5 段二：postings／possessions／events 收斂到路徑 A

這 3 張表不涉及「兩人互為鏡像」——地址副表是單一人物自己的子表。唯一落差是地址意圖
（`c_addr`／`c_addr_id`／`c_addr_cleared`）不屬於主表白名單、只存在 `__proposal_aux`：
`applyViaMutationHandler()` 將其併回 changes（handler 的 direct 路徑本就從 changes 讀這些鍵）。
DELETE 一併收斂（`*DeleteHandler` 委派與去級聯末批同一批修正過的「先子後父」邏輯）。
legacy 委派死碼已移除；`BiogMainRepository::office/possession/event*ById` 保留服務 Blade 直接編輯。

### 4.6 段三：`BIOG_MAIN` 收斂到路徑 A

| 操作 | 重放對象 | 語義決定 |
|---|---|---|
| UPDATE | `BiogMainMutationHandler` | 核准＝把 delta 套用**當下**資料列＋重跑 `BasicInformationRequest` 驗證；「名（中）／拼音名不可清空」由 handler `validationRules($original)` 提供 |
| DELETE | `BiogMainDeleteHandler` | 防禦性封洞：通用 C 會物理 DELETE（與 direct 軟刪除相反、CASCADE 觀察期會連鎖刪 25 張子表） |
| CREATE | `BiogMainCreateHandler` | 帶 `c_personid` 驗證與白名單，取代盲 Eloquent create |

回歸測試：`tests/Feature/BiogMainProposalTest.php`。

### 4.7 稽核欄語義定案＋legacy 提交入口下架（2026-08-05，commit `6a3c78ee`）

事故：一筆經 legacy 無白名單入口存入的 create 提案 payload 夾帶四個稽核欄，核准重放撞
handler 白名單 → 422「包含不允許的欄位」整筆失敗。修正分四塊：

1. **快照 → 意圖翻譯補全**：`applyViaMutationHandler()` 重放前統一剔除 `AUDIT_COLUMNS`
   （create 與 update 兩分支——update 提案 data＝original∪changes **天然含**稽核欄，先前僅靠
   「diff 兩快照相等而抵銷」僥倖未炸，序列化一漂移就會復發）。
2. **稽核欄語義**（詳見 APPROVAL_FLOWS.md §3）：任何寫入蓋當下、署名雙人名
   「審核人 (Proposed by: 提案人)」，統一經 `App\Support\AuditActor`；通用路徑
   `enforceAuditFieldsForCreate/Update` 改無條件蓋章；restore 蓋還原人＋還原時刻。
3. **入口下架**：`LegacyBladeFormGate` middleware——flag=new 時 legacy 表單 GET 302 導向
   `/app` 對應頁、寫入端點（含 `proposalStore`）回 410；flag=old 完整放行（回退承諾保持）。
   `extractFormData()` 另加剔除稽核欄保險帶。
4. **「比較」認領**：核准時把 handler 落庫的 direct operation id 寫回提案 payload
   （`__applied_operation_id`），operations 列表據此把 audit_log 認領回提案列。
   依決策**不回填存量**；路徑 B（kinship／assoc）未回報 id、其新核准提案「比較」仍不可用。

回歸測試：`ProposalAuditFieldSemanticsTest`、`LegacyBladeFormGateTest`。

---

## 5. 還沒做什麼

### 5.1 路徑 B——kinship／associations，暫緩

核准仍走 legacy repository，而直接編輯走 v2 handler，兩者是獨立寫實作、靠測試與共用 mirror
helper 手動對齊。**為何暫緩**：`KIN_DATA`／`ASSOC_DATA` 每筆描述**兩個人之間**的關係、各存
互為鏡像的兩列；核准路徑的鏡像語義（Mirror{Conflict,Suspected,Integrity}Exception、缺鏡像
backfill、刪除 `$force=true` 廣集孤兒）都實作在 legacy 側、十餘個測試釘著。兩人分別提案同一組
關係時的衝突裁定是**領域決策**而非接線問題。另注意：**§7 的意圖原語遷移會直接改寫這一塊的
建模**（「關係」本就該是一個意圖、鏡像列是派生輸出）——若決定做 §7，此項應併入而非先單獨收斂。

### 5.2 legacy 提交路徑——✅ 已封（見 §4.7）

`POST basicinformation/{personid}/{resource}/proposal` 在 flag=new 時 410。殘餘風險僅剩
flag=old 回退窗口（此時 `extractFormData` 的稽核欄剔除仍有效，但其它欄位仍無白名單）。
路由與控制器**實體仍在**，隨 Phase 7 AdminLTE 下架一併移除。

---

## 6. 建議順序

1. ~~`BIOG_MAIN` 收斂到 A~~ ✅（§4.6）
2. ~~封死 legacy 提交入口~~ ✅（§4.7；實體移除待 Phase 7）
3. **先裁定 §7 是否立項**——它決定 kinship／assoc 的收斂形狀與「比較」重構
   （[OPERATIONS_COMPARE_CONSOLIDATION_PLAN.md](./OPERATIONS_COMPARE_CONSOLIDATION_PLAN.md)）
   騎在哪個模型上做。若立項：kinship／assoc 直接以新原語重做（跳過「先收斂到 A 再遷」的重工）；
   若不立項：kinship／assoc 收斂到 A（需先裁定鏡像衝突語義，單獨分支、單獨 review）。
4. 完成後 `OperationsProposalController` 的路徑 B／C 可整段移除，控制器塌成
   「decode → resolve → handle → updateStatus」。

---

## 7. 方向：提案原語應為「意圖」，而非「原表上的 CRUD 行快照」（2026-08-05 提出）

### 7.1 診斷

把提案建模成「在原有表上做 pending CRUD」是**原始設計錯的那一步**。當一次人物子資源修改
實際涉及多表資料更新時，行快照裝不下完整意圖，系統於是長出一批補償機制——每一個都是同一個
建模錯誤的症狀：

| 症狀 | 說明 |
|---|---|
| `__proposal_aux` 側通道 | 任官地址等副表意圖不屬於主表白名單，只能塞在 payload 旁邊、核准時顯式併回 |
| kinship／assoc 鏡像機制 | 「A 與 B 是某關係」是一個語義事實，被拆成兩列存放後，需要整套衝突／疑似／fail-closed／強制收斂機制事後縫合 |
| 稽核欄 422 事故（§4.7） | 快照天然拖著系統代管欄；「重放前剔稽核欄」本質是在從快照**反推**意圖 |
| 「比較」兩列問題 | 核准要把 pending CRUD「轉正」成另一列 direct operation，同一件事出現兩行、audit 掛錯邊，需 `__applied_operation_id` 縫合 |
| `POSTED_TO_ADDR_DATA` 借格式 | 一個任官意圖橫跨兩表，單表原語裝不下，明細只能塞 `resource_data['rows']`（AGENTS 高風險備忘） |

### 7.2 目標模型

**operations 存命令（意圖），audit_log 存事件（事實）。**

- 提案＝一條 pending **命令**：資源級動詞＋使用者欄位＋目標身分
  （例：`{command: 'kinship.create', person: A, target: B, kin_code, source…}`），不含任何
  系統代管欄，也不預先拆成多列。
- 核准＝**執行命令**：交給與 direct 完全同一個 handler；鏡像列、副表列、派生欄都是執行時的
  輸出，由 handler 產生並逐表寫 audit_log。
- 比較的兩種語義自然落位：pending 比較＝把命令對**當前**資料渲染一遍（與執行共用同一條
  路徑，順帶得到漂移偵測）；歷史比較＝讀 audit_log 事件。
- restore 不受影響：繼續走 operations 快照（audit_log 的 old/new 即逐表快照）。

### 7.3 已有的萌芽與遷移順序

§4.5 實體聚合提案（office 試點）**就是這個原語**：`resource`＝聚合 API 名、payload 存
`__entity_operation`＋`changes`（意圖非快照）、核准＝同一 handler 以 direct 語義重放。
段一～段三的 handler 重放也是往此收斂——只是意圖還得從快照反推。

遷移順序（受益排序）：

1. **kinship／associations 最痛也最受益**：鏡像機制的一半可隨「關係＝單一意圖」直接消失；
   且它們尚未收斂到 A，以新原語重做可跳過一次重工（§6-3）。
2. **postings（＋possessions／events）次之**：`__proposal_aux` 側通道可廢。
3. 其餘單表資源收益低，可最後或不遷（單表 CRUD 下「意圖」與「行快照剔系統欄」幾乎同構）。

順風條件：「不兼容舊提案、不回填」已是既定決策（§4.7），新命令 schema 不必背歷史包袱；
§4.5 管線已驗證可行。約束：operations 列表的按表／按主鍵篩選需為命令補派生索引欄；
命令 schema 需版本欄以備演進。

---

## 8. 相關程式碼

- [app/Http/Controllers/OperationsProposalController.php](../app/Http/Controllers/OperationsProposalController.php)
  ——`HANDLER_ROUTED_RESOURCES`、`applyProposal()`、`applyViaMutationHandler()`、`AUDIT_COLUMNS`
- [app/Support/AuditActor.php](../app/Support/AuditActor.php)（稽核署名，§4.7）
- [app/Http/Middleware/LegacyBladeFormGate.php](../app/Http/Middleware/LegacyBladeFormGate.php)（legacy 表單下架閘門）
- [app/Services/Mutations/MutationHandlerRegistry.php](../app/Services/Mutations/MutationHandlerRegistry.php)
- [app/Services/Mutations/AbstractPersonSubresourceCreateHandler.php](../app/Services/Mutations/AbstractPersonSubresourceCreateHandler.php)
  ／[…MutationHandler.php](../app/Services/Mutations/AbstractPersonSubresourceMutationHandler.php)
- [app/Http/Controllers/BasicInformationProposalController.php](../app/Http/Controllers/BasicInformationProposalController.php)（legacy 提交端，已封）
- 測試：`OperationsProposalControllerTest`、`BasicInformationProposalTest`、
  `ProposalAuditFieldSemanticsTest`、`LegacyBladeFormGateTest`、`BiogMainProposalTest`
