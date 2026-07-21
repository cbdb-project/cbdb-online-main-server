# 社會機構實體模型（Social Institution Entity Model）

> 狀態：設計定案（Design of Record）
> 範圍：CBDB「社會機構」作為上層聚合實體的資料模型、實體介面、以及所有操作
> （加／減名字、加／減地址、編輯屬性、刪除、搜尋機構）的行為規範。
> 上位文件：[docs/ENTITY_AGGREGATE_ARCHITECTURE.md](./ENTITY_AGGREGATE_ARCHITECTURE.md)（§2.5、§6.5）。
> 本文取代該文 §2.5 中「機構身分＝c_inst_code 單鍵、名碼為屬性」的暫定框架——
> 經與領域專家（Michael）確認後改採下述「一機構多名」模型。

---

## 0. 一句話定義

**一個「機構」＝ `SOCIAL_INSTITUTION_CODES` 中共享同一 `c_inst_code` 的一組列。**
`c_inst_code` 是這個聚合的「虛擬主鍵」（沒有專屬的 header 列）；每一列是該機構的
一個「名字條目（name-entry）」，帶有自己的名稱、屬性與地址。此模型與「一個人
（`c_personid`）掛多筆任官（posting）」同構——機構是分組鍵，name-entry 是完整明細列。

---

## 1. 設計意圖（領域背景）

- **複合主鍵是有意的**：`SOCIAL_INSTITUTION_CODES` 的 PRIMARY KEY 為
  `(c_inst_code, c_inst_name_code)`。名碼與機構身分刻意**解耦**——fact 表
  （`BIOG_INST_DATA` 等）以兩條獨立外鍵分別引用「機構」（`c_inst_code`）與
  「名字」（`c_inst_name_code` → 名稱字典），用來表達「知其名、未必定其為哪一座」
  （例如史料只說某座『相國寺』，但天下相國寺甚多，無法斷定是哪一座）。唯一硬約束是
  **名字必須是登記過的名碼**（`ibfk_6`）。
- **名字是歷史問題，不是機械操作**：當你在某地發現一座寺／書院的著錄，判斷它是
  「既有機構的新名字」還是「另一座需要自己 ID 的機構」，**必須回到史料判斷**。資料庫
  只能輔助（見 §7 搜尋），不能自動決定。
- **地址綁在「機構＋名字」，不綁在抽象機構**：一座建物在某名字下位於某地；復建後
  可能在城中別處。因此地址天生屬於某個 name-entry，而非機構整體。
- **這些記錄應相對穩定**：機構與其名字、地址一旦考訂收錄，變動不頻繁（性質上接近
  地址權威資料）。

---

## 2. 儲存層（下層表）

| 表 | 角色 | 主鍵 |
|---|---|---|
| `SOCIAL_INSTITUTION_NAME_CODES` | 名稱字典（string↔碼），跨機構共享 | `c_inst_name_code` |
| `SOCIAL_INSTITUTION_CODES` | **name-entry**：一機構的一個名字＋該名字的屬性 | `(c_inst_code, c_inst_name_code)` |
| `SOCIAL_INSTITUTION_ADDR` | 地址：掛在某個 name-entry 下 | `(c_inst_addr_id, c_inst_addr_type_code, c_inst_code, c_inst_name_code, inst_xcoord, inst_ycoord)` |
| `SOCIAL_INSTITUTION_TYPES` | 類型字典（書院／寺廟／詩社…） | `c_inst_type_code` |

**name-entry 的屬性欄位**（都在 `(c_inst_code, c_inst_name_code)` 粒度，非機構粒度）：
`c_inst_type_code`、`c_inst_begin_dy`／`c_inst_floruit_dy`／`c_inst_end_dy`、
起訖年與年號／year-range 欄、`c_inst_first_known_year`／`c_inst_last_known_year`、
`c_source`、`c_pages`、`c_notes`。

### 2.1 生產資料現況（作為模型的事實基準）

- `SOCIAL_INSTITUTION_CODES` 共 **4011 列，`c_inst_code` 全數唯一**——即**目前每個機構
  都只有單一 name-entry**。「一機構多名」是本模型**新支援**、但現有資料尚未出現的形態。
- **555 個名碼被多個機構共享**（多座同名機構，如多座相國寺各有自己的 `c_inst_code`）。
- 地址共 3858 列；**153 個 name-entry 沒有任何地址**（地址是選填）；
  **0 條地址是孤兒**（每條地址都有其母 name-entry）。
- `c_inst_code = 0` 是「機構未詳」哨兵列（名碼 0 ＝ `[未詳]`），供 fact 表表達
  「只知名字、不知哪座」。

---

## 3. 兩種粒度：寫入 vs 讀取

- **寫入／變更／審計粒度 ＝ name-entry ＝ 複合鍵 `(c_inst_code, c_inst_name_code)`。**
  每一次 mutation 精確作用於一個 name-entry；`CompositePrimaryKey::SCHEMAS` 已把
  `SOCIAL_INSTITUTION_CODES` 登記為此複合鍵，`operations` / restore 依此運作。
- **讀取／介面粒度 ＝ 機構 ＝ `c_inst_code`（分組）。** 列表與詳情把共享 `c_inst_code`
  的多列聚合成「一個機構」呈現。

**關鍵原則：`c_inst_name_code` 是 name-entry 身分的一部分，不是可原地變動的屬性。**
因此**沒有「改名（rename）」這個操作**——要改名字，是「加一個名字」（新 name-entry），
必要時再「減一個名字」。這避免了原地變動半個主鍵、以及 operations 指向消失主鍵的問題。

---

## 4. 實體介面長什麼樣

### 4.1 機構列表（Index）
- **一列一機構**（按 `c_inst_code` 分組）。
- 顯示：`c_inst_code`、**該機構全部名字**（例：`月泉書院 / 月泉吟社`）、（代表性）類型與朝代、
  名字數、地址數。
- 支援與裸表頁對等的排序／逐欄篩選／關鍵字搜尋（見 §7）。

### 4.2 機構詳情／編輯
- 頂部：機構 = `c_inst_code`（＋全部名字一覽）。
- 主體：**name-entry 清單**，每個 name-entry 展開為
  「名稱｜類型｜起訖朝代／年｜來源｜頁碼｜備註｜其地址清單」。
- 一等操作：**加名字**、對每個 name-entry **加／減地址**、**編輯該 name-entry 屬性**、
  **減名字**、**刪整個機構**。

---

## 5. 操作與行為

下述皆走共用 mutation API（`resource = social-institution`），
name-scoped 操作的 `target.pk` 帶 `(c_inst_code, c_inst_name_code)`，
機構級操作帶 `c_inst_code`。所有寫入逐列記 `operations` + `audit_log`，可回滾。

### 5.1 建立機構（Create institution）
- 配 `c_inst_code = max(c_inst_code)+1`（`lockForUpdate` 防並發撞號）。
- 建立**第一個 name-entry**：名稱去重解析（同名複用既有名碼，否則配
  `max(c_inst_name_code)+1` 並派生拼音、寫入字典），填類型／朝代／來源等屬性。
- 可同時附地址列。

### 5.2 加一個名字（Add a name）
- 對既有 `c_inst_code` **插入一個新 name-entry**（同 `c_inst_code`，解析新名稱→名碼）。
- **屬性獨立填寫**（類型／朝代／來源…）；介面**以同機構既有 name-entry 預填作參考**（可改）。
- 新 name-entry 的**地址從零開始收集**，不從兄弟名字自動複製（地址綁名字、復建可能異地）。
- **護欄**：同一 `c_inst_code` 不得重複加入同一 `c_inst_name_code`（複合主鍵撞號）→ 擋。
- **軟驗證（不擋）**：若新 name-entry 的類型／朝代與同機構兄弟列不一致，提示使用者確認
  （避免無意造出自相矛盾的機構）。
- **歷史判斷（見 §7）**：加名字前應先用搜尋確認「這是既有機構的新名，還是另一座機構」。

### 5.3 減一個名字（Remove a name）
- **不得刪到最後一個 name-entry**——刪最後一個 ＝ 刪整個機構（見 §5.6）。
- 刪一個**非最後**的 name-entry 時：
  - **連帶刪除該 name-entry 的所有地址**（向下級聯，原子）。
  - **護欄**：若有任何 fact 列正引用**這個具體** `(c_inst_code, c_inst_name_code)` 組合
    （`BIOG_INST_DATA` 等），**擋**——否則會製造「有名無主」的孤兒引用。

### 5.4 加一個地址（Add an address）
- 在指定 name-entry 之下插入 `SOCIAL_INSTITUTION_ADDR` 一列（地址屬於該
  `(c_inst_code, c_inst_name_code)`）。
- 地址列鍵含座標；`c_inst_addr_type_code` 預設 1；座標缺省 0。

### 5.5 減一個地址（Remove an address）
- **一律允許，無下限**——name-entry 可以有 0 個地址（現有 153 個就是）。
  **沒有「保留最後一個地址」規則。**
- 刪最後一條地址只會讓該 name-entry 變成「無地址」，**不牽動 name-entry 本身**。

### 5.6 刪整個機構（Delete institution）
- 僅當該 `c_inst_code` **未被任何 fact 表引用**時允許
  （`BIOG_INST_DATA` / `ENTRY_DATA` / `ASSOC_DATA` / `POSTED_TO_OFFICE_DATA`，
  皆為 `ON DELETE CASCADE`——有引用時刪除會靜默損毀人物資料）。
- 原子刪除該機構的**所有 name-entry ＋ 其所有地址**。
- **名碼不回收**（`SOCIAL_INSTITUTION_NAME_CODES` 被多機構共享、且被人物表級聯引用，
  誤刪代價不對稱）。

### 5.7 編輯 name-entry 屬性（Update attributes）
- 原地更新該 name-entry 的**非鍵欄位**（類型／朝代／年／來源／頁碼／備註）。
- **`c_inst_name_code`（身分）不變**——改名字請用「加名字／減名字」，不是編輯此欄。
- 地址以「集合對賬」維護（同鍵改非鍵值、僅增刪差異），等同 §5.4／§5.5 的合併結果。

### 5.8 刪除護欄三級（總表）

| 刪除對象 | 護欄 | 級聯 |
|---|---|---|
| 一條地址 | 無（可刪到 0） | 無 |
| 一個 name-entry（非最後一個） | 該 `(c_inst_code, c_inst_name_code)` 被 fact 引用 → 擋 | 連帶刪其地址 |
| 最後一個 name-entry ＝ 整機構 | 該 `c_inst_code` 被任何 fact 引用 → 擋 | 連帶刪所有 name-entry ＋ 所有地址 |

---

## 6. 名字如何呈現（多名處理）

- **機構語境**（列表／詳情）：**列出全部名字**（按 `c_inst_code` 聚合所有 name-entry
  的名稱）。不需要 `is_primary` 旗標，也就不必改 schema。
- **fact 語境**（人物隸屬某機構）：顯示該 fact 列**當時著錄的那個名字**（fact 列自帶
  `c_inst_name_code`）為主，其後可掛「（亦名：…該機構全部名字）」。

---

## 7. 搜尋機構（Search）

搜尋的核心用途是**加名字／建機構前的判斷**：「這座已經有了嗎？」搜尋是**輔助人工判斷**，
不做權威去重。依 Michael 的工作流程分兩步：

1. **「我們已經有了嗎？」**——以**名稱**（完全／包含，跨該機構全部名字）＋
   **大致地點**比對。命中即候選。
2. **「那這個地點附近有哪些機構？」**——以**地點**（`c_addr_id` / place）搜尋。
   - ⚠ 地點搜尋本質偏鬆：地址綁在「機構＋名字」，且建物可能復建於別處；
     同一地方常有多座寺／書院。**命中不代表同一座**——很可能是另一座、需要自己的 ID。

**搜尋維度**（列表頁與搜尋端點共用）：
- 按**名稱**（跨所有 name-entry 的 `c_inst_name_hz`；contains／完全）。
- 按**地點／地址**（`c_addr_id`；粗粒度、僅供人工複核）。
- 按**類型**（`c_inst_type_code`）、**朝代**。
- 結果**按機構（`c_inst_code`）分組**回傳，附全部名字與地址，供人判斷。

> 註：溫和的長期方向是盡量把外部佛教／寺廟資料庫的 Temple ID 收齊，讓「這座已經有了嗎」
> 更可靠（現有 notes 多帶外部「Temple ID」，覆蓋率待評估）。這屬資料收錄，不影響本模型。

---

## 8. 完整性與不變量

- **硬約束（DB 外鍵）**：`c_inst_name_code` 必須是合法名碼（`ibfk_6`）；
  `c_inst_code`、`c_inst_name_code` 為**兩條獨立外鍵**（有意；支援「知名不知機構」）。
- **不加複合外鍵**：fact 表 `(c_inst_code, c_inst_name_code)` **不**指向 master 複合鍵——
  否則會拒絕 `inst=0`（未詳）與變體名等合法組合（實測 11 個 fact 組合不在 master，
  其中 8 個是 `inst=0` 哨兵、3 個是待清理的錯誤，皆不應以「補 master 列」方式硬塞）。
- **聚合不變量（應用層維護，DB 未強制）**：
  - 每條地址都有母 name-entry（不得有孤兒地址）。
  - 一個機構至少有一個 name-entry（不得刪到零，除非刪整機構）。
  - 刪除時名碼不回收。
- **`c_inst_code` 在 master 的唯一性不再是不變量**：本模型**刻意允許**一個 `c_inst_code`
  對應多個 name-entry 列。任何假設「一 `c_inst_code` 一列」的既有讀取都需改為按
  `c_inst_code` 分組（見 §10 相容性）。

---

## 9. 資料清理（與本模型分離、獨立進行）

以下為既有資料品質項，**不屬於本模型的實作**，另案處理：

- **3 筆 fact 層錯掛**（共 4 個 `BIOG_INST_DATA` 列）：`(4008, 397)` 月泉書院掛到詩社
  （山長角色屬書院、應為 inst 857）、`(4009, 2526)`、`(3911, 1)`。
- **重複空殼機構**：如 `3885`（月泉書院，無出處／無地址／零引用），與有出處的 `857` 重複。
  全庫「無出處且零引用」的孤兒殘檔約 11 筆。
- ⚠ 這些個案的裁決是**歷史判斷**（是否同一機構、該併到哪個 ID），需人工＋史料，
  不可用「加名字」把錯掛洗白成正式別名。

---

## 10. 與 `feature/social-institution-entity-step4` 的落地差異

現有 feature 分支實作把機構身分當成 **`c_inst_code` 單鍵**、並支援**原地改名**
（`update()` 會變動 `c_inst_name_code` 並同步 ADDR）。改採本模型需要：

1. **移除「改名」**：`update()` 只改 name-entry 的非鍵屬性，不再解析／變動 `c_inst_name_code`；
   刪掉改名護欄與 ADDR name_code 重寫。
2. **身分改複合鍵**：`load/update/delete`、handler `target.pk`、URL、前端 target.pk 全部帶
   `(c_inst_code, c_inst_name_code)`（沿用專案複合鍵 URL 慣例，見
   [docs/COMPOSITE_PRIMARY_KEY_URL_DESIGN.md](./COMPOSITE_PRIMARY_KEY_URL_DESIGN.md)）。
3. **新增「加名字」**：以既有 `c_inst_code` 插入新 name-entry（獨立填＋兄弟預填＋重複名護欄）。
4. **刪除三級護欄**（§5.8）：地址無護欄；非最後 name-entry 按 `(inst, name)` 引用；
   最後一個＝整機構按 `c_inst_code` 引用。
5. **列表按 `c_inst_code` 分組**、詳情呈現 name-entry 清單、名字全帶（§6）。
6. **`CompositePrimaryKey` 不動**（已是複合鍵）。
7. **框架漣漪**：`docs/ENTITY_AGGREGATE_ARCHITECTURE.md §6.5` 的通用抽象目前假設**單鍵**
   身分（office ＝ `c_office_id`）。social institution 變雙鍵後，通用
   `EntityAggregateDefinition` / `EntityTableBrowser` / handler / 前端 `EntityIndexPage`
   需泛化成「鍵列表」；建議**先在本 feature 分支定型雙鍵實體，再回頭泛化框架**。

---

## 11. 相關文件

- [docs/ENTITY_AGGREGATE_ARCHITECTURE.md](./ENTITY_AGGREGATE_ARCHITECTURE.md)（實體聚合總架構、§2.5／§6.5）
- [docs/COMPOSITE_PRIMARY_KEY_URL_DESIGN.md](./COMPOSITE_PRIMARY_KEY_URL_DESIGN.md)（複合鍵 URL 編碼慣例）
- `app/Services/Import/SocialInstituteImportService.php`（聚合根存儲過程）
- `app/Support/CompositePrimaryKey.php`（下層表主鍵定義）
