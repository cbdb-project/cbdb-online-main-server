# CBDB 實體聚合架構（Entity Aggregate Architecture）

> 狀態：設計提案（Design Proposal）
> 目的：確立 CBDB 頂層「實體」的正確抽象層級，說明目前除「人物」外其餘實體抽象洩漏（leaky abstraction）的成因，並提出「上層包裝、下層封閉」的收斂路線。
> 本文只描述方向與目標架構，**不包含即刻的程式改動**；具體實作依此分階段開 PR。

---

## 1. 兩層結構：實體層 vs 儲存層

CBDB 的資料在概念上是**兩層**的：

- **上層（實體層 / Domain Entity）**：使用者心智中的物件——**人物、書籍（及其 collection／instance）、地點、官職、社交機構**。這是 API 與前端**應該**操作的單位。
- **下層（儲存層 / Storage Tables）**：實體被關聯式正規化後散落的多張資料表。一個實體往往橫跨數張表，並含**派生欄位**（拼音、朝代解析）、**配套列**（類型關聯、地址）、**去重規則**。

理想狀態：**實體是唯一的操作單元（聚合根 / Aggregate Root）**，它獨佔對自己所有下層表的寫入（含派生、配套、不變量校驗）；使用者與 API 永遠只碰上層，下層表退化為實作細節。

CBDB 目前**只有「人物」做到了這一點**，其餘實體都停在「概念存在、但沒有聚合根」的狀態——這是一個**設計層級的問題**，不是零星的功能缺口。

---

## 2. 頂層實體與其下層表

以下是應被視為「聚合」的頂層實體，以及各自的下層儲存表。

### 2.1 人物（Person）— ✅ 已正確抽象
- 主表：`BIOG_MAIN`
- 子資源：`ALTNAME_DATA`、`BIOG_ADDR_DATA`、`BIOG_TEXT_DATA`、`BIOG_SOURCE_DATA`、`ENTRY_DATA`、`STATUS_DATA`、`EVENTS_DATA`、`ASSOC_DATA`、`KIN_DATA`、`POSSESSION_DATA`、`BIOG_INST_DATA`、`POSTED_TO_OFFICE_DATA`（＋`POSTED_TO_ADDR_DATA`）
- 抽象狀態：**聚合根已建立**。13 個 React 編輯器與 `/api/v2/*` 都走 person-scoped handler（`AbstractPersonSubresource*Handler` 等），統一做 `person_id ↔ PK` 一致性校驗、雙向鏡像同步、sentinel 幂等、`operations` ＋ `audit_log`。使用者操作的是「人物的某個子資源」，而非裸表。

### 2.2 書籍／文本（Text，含 collection／instance）— ⚠ 洩漏
- 文本本體：`TEXT_CODES`
- 版本／實例：`TEXT_INSTANCE_DATA`（一個 text 可有多個 edition／instance）
- 書目分類：`TEXT_BIBLCAT_CODES`、`TEXT_BIBLCAT_TYPES`、`TEXT_BIBLCAT_CODE_TYPE_REL`
- 角色／類型：`TEXT_ROLE_CODES`、`TEXT_TYPE`
- 聚合語義：新增一本書不只是寫一列 `TEXT_CODES`——書目導入工具還派生**拼音、朝代、`c_text_type_id`、作者關聯**；完整的「書」還牽涉 collection→instance 的層級。

### 2.3 地點（Place）— ⚠ 洩漏
- 地名本體：`ADDRESSES`（gazetteer，帶經緯度）
- 代碼／隸屬：`ADDR_CODES`、`ADDR_BELONGS_DATA`（行政隸屬層級）、`CHORONYM_CODES`
- 聚合語義：新增地點涉及座標校驗（CHGIS）、隸屬關係、郡望等，非單表可完整表達。

### 2.4 官職（Office）— ⚠ 洩漏
- 官名本體：`OFFICE_CODES`
- 類型關聯：`OFFICE_CODE_TYPE_REL`（官職→類型樹）
- 類型樹：`OFFICE_TYPE_TREE`
- 聚合語義：新增一個官職 = `OFFICE_CODES`（`c_office_id = max+1`、派生 `c_office_pinyin`、`c_dy`、來源）＋**必須**一併寫入 `OFFICE_CODE_TYPE_REL` 的類型關聯。少寫關聯即為殘缺實體。

### 2.5 社交機構（Social Institution）— ⚠ 洩漏
- 名稱代碼：`SOCIAL_INSTITUTION_NAME_CODES`（帶去重：已存在則複用名稱碼）
- 機構本體：`SOCIAL_INSTITUTION_CODES`（`c_inst_code = max+1`、類型、起始朝代）
- 地址：`SOCIAL_INSTITUTION_ADDR`（複合鍵、地址類型、座標）
- 類型：`SOCIAL_INSTITUTION_TYPES`
- 聚合語義：新增一個機構橫跨**三張表**、含**名稱去重**與朝代／類型／地址解析。

---

## 3. 問題：抽象洩漏（Leaky Abstraction）

除人物外，上述實體的「聚合」邏輯**目前只隱含存在於管理員批量導入工具裡**（`AdminBatchLoad*Controller` 的 `store()`），而非一個可重用、被獨佔的聚合根。這導致三重洩漏：

### 3.1 下層是敞開的：可繞過實體直接改裸表
`codes` CRUD（`CodesController`）允許使用者**直接編輯下層裸表**——例如新增一列 `OFFICE_CODES` 卻不寫 `OFFICE_CODE_TYPE_REL`、拼音亂填、或單獨改 `SOCIAL_INSTITUTION_CODES` 而不管名稱碼去重。結果是**產出殘缺／不一致的聚合**：實體的不變量（invariant）沒有任何一處在守。

### 3.2 上層沒有真正的入口
- 沒有「編輯一個 office／institution／text／place」的**實體級操作**。
- 現有入口只有兩種，都不是聚合根：
  - **批量導入表單**：只增、只批量、僅管理員，且是各自 Controller 內聯的一次性程序碼。
  - **裸表 `codes` CRUD**：直接操作下層，破壞封裝。
- **連單獨編輯這些實體的前端頁面都沒有**——這是最直接的缺口。

### 3.3 提案流程也洩漏到下層（重要）
現行 proposal（眾包／審核）流程本身就是**以單一下層表列為粒度**建立的，複合實體在此完全散架：

- `CodesController::proposalEdit()` 直接用 `Schema::getColumnListing($table)` 把**某一張裸表的欄位**攤成表單。
- `proposalUpdateExisting()` 寫回的 `resource_data` 也是**單表行**的鍵值快照。
- 因此：**針對複合實體提案／修改提案時，操作的其實是「某一張下層表的一列」**，配套表（`OFFICE_CODE_TYPE_REL`、`SOCIAL_INSTITUTION_ADDR`、`TEXT_INSTANCE_DATA`…）根本不在提案範圍內，也無從一致審核。審核通過後落庫的只有那一張表——**提案在此退化為對下層表格的修改**。

> 換言之：`operations` / proposal 的資源模型是「表 + resource_id（複合主鍵）」，天生表達的是**下層表列**，而非**上層聚合**。人物子資源之所以沒事，是因為每個子資源恰好對應一張表、且人物層已有聚合根在其上統籌；但官職／機構／文本／地點的「一個實體＝多張表」在這個模型下無法被當作一個提案單位。

---

## 4. 目標架構：上層包裝、下層封閉

對**複合實體**（office / social institution / text＋instance / place）採「聚合根」模式；對**扁平實體**（實體恰＝單表）維持輕量。

### 4.1 區分兩類，不要一刀切
- **扁平實體（實體＝1 張表）**：如 `DYNASTIES`、`GANZHI_CODES`、各類 admin 分類。表**就是**實體，裸的 `CodeTable*Handler`（config 驅動、單主鍵、可 `max+1`）已是正確抽象，**無需**再包一層，也**無需**封閉。
- **複合實體（實體跨多表＋派生）**：office / social institution / text / place。**才需要**聚合根，且**才應該封閉下層直寫**。

### 4.2 聚合根（單一寫入路徑）
每個複合實體有一個領域 Service 作為聚合根，**獨佔**其所有下層表的 create／update／delete：
- 內含派生（拼音、朝代、`max+1` id）、配套列（類型關聯、地址）、去重（名稱碼）、不變量校驗。
- `OfficeImportService` 是這個模式的雛形（目前僅 create）。
- Service 不自開交易，由呼叫端（handler／web）統一包 `DB::transaction`。

### 4.3 上層操作同時供兩端，共用同一 Service
- **Mutation API**：`resource = office | social-institution | text | place`，經 `MutationHandlerRegistry` 分派到對應 handler，handler 呼叫聚合 Service。單筆與 `batch_mutate` 自動同時可用。
- **專屬前端實體編輯頁**：每個實體一個 React／Inertia 編輯器，呼叫同一組實體級端點。**這是目前完全空白、需要新建的部分。**

### 4.4 封閉下層直寫（分階段、可回退）
- 對「被實體支撐的下層表」，`codes` CRUD 的**寫入**改為只讀（或路由到聚合 Service），使用者無法再產出殘缺聚合。
- 讀取／瀏覽維持開放。
- 封閉須在實體級入口（API＋前端）齊備、且資料回填驗證後才執行，保留回退。

### 4.5 提案模型升級為「實體級提案」
- proposal 的 `resource_data` 應能承載**整個聚合**（主表＋配套表的完整意圖），而非單一下層表列。
- `proposalEdit` 不再用 `Schema::getColumnListing(單表)`，而是用實體的欄位模型（由聚合 Service／handler 提供）。
- 審核通過時，由聚合根一次性、原子地落庫所有下層表，與 direct 路徑對等。
- 在此之前，複合實體的提案應**明確標示為未支援**，避免使用者以為改了單表就等於改了實體。

---

## 5. 現況對照與差距總表

| 實體 | 下層表 | 聚合根 | 實體級 API | 專屬前端編輯頁 | 下層直寫已封閉 | 實體級提案 |
|---|---|---|---|---|---|---|
| 人物 | BIOG_MAIN ＋ 12 子資源 | ✅ | ✅ CRUD | ✅（13 編輯器）| N/A（子資源即實體單位）| ✅ |
| 書籍／文本 | TEXT_CODES ＋ INSTANCE ＋ BIBLCAT… | ❌ | 僅裸 TEXT_CODES CRUD | ❌ | ❌ | ❌（退化下層）|
| 地點 | ADDRESSES ＋ ADDR_CODES ＋ … | ❌ | ❌ | ❌ | ❌ | ❌ |
| 官職 | OFFICE_CODES ＋ TYPE_REL | ✅ | ✅ CRUD | ✅（/app/office，與裸表頁 feature parity 的超集；側欄「任官編碼表」已改指此頁）| ✅（codes 寫入封閉，讀取／匯出開放）| ❌（裸表提案一併封閉、標示未支援，待實體級提案）|
| 社交機構 | NAME_CODES ＋ CODES ＋ ADDR | ❌ | ❌ | ❌ | ❌ | ❌ |

（🟡＝進行中／部分；本表隨實作推進更新。）

---

## 6. 分階段路線（建議）

對每個複合實體，重複同一套四步（與 office／social 已定的重構步驟一致）：

1. **建立聚合根 Service ＋ 實體級 handler（create）**，忠實複製既有批量導入工具的「存儲過程」語義（派生、配套、去重、審計）。**不改底層 API，而是以聚合語義封裝下層寫入。**
2. **把既有 web 寫入路徑（批量導入 Controller）遷移到聚合 Service**，消除重複的內聯程序碼、補齊 `audit_log`。
3. **補齊實體級 update／delete**（複合資源的改／刪必須連帶配套表），並建立**專屬前端實體編輯頁**。
4. **封閉下層 `codes` CRUD 的寫入**（改只讀或路由到聚合根），並**升級 proposal 為實體級**。

橫向優先級建議：先收斂 **office → social institution**（已有明確存儲過程、批量工具現成），再處理 **text（含 instance）**與 **place（含座標）**這兩個層級更深的實體。

---

## 7. 設計原則備忘

- **不修改底層 mutation API 的通用機制，而是以「模擬實際存儲過程」的方式，在其上建立實體語義**——聚合根是新增的一層，不是對既有 handler 的破壞。
- **扁平實體不強行套聚合**：實體＝單表時，裸 CodeTable handler 就是對的抽象。
- **封閉下層是終態、不是起手式**：必先備齊實體級入口與回填驗證，且全程可回退。
- **提案與 direct 必須對等**：任何實體級寫入語義（含配套表、去重、不變量），proposal 核准路徑都要一致，否則審核會落庫出殘缺聚合。

---

## 8. 相關文件與程式碼

- `app/Services/Import/OfficeImportService.php`（聚合根雛形）
- `app/Services/Mutations/MutationHandlerRegistry.php`（實體→handler 分派）
- `app/Support/CompositePrimaryKey.php`（下層表主鍵定義）
- `app/Http/Controllers/AdminBatchLoadOfficesController.php`、`AdminBatchLoadSocialInstitutesController.php`、`AdminBatchLoadBookTitlesController.php`（現行批量導入「存儲過程」的來源）
- `app/Http/Controllers/CodesController.php`（下層裸表 CRUD 與 proposal 洩漏點：`proposalEdit` / `proposalUpdateExisting`）
- `.claude/skills/mutation-api-record-editing.md`
- `docs/APPROVAL_FLOWS.md`
