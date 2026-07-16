# 「物理刪除 × ON DELETE CASCADE」是唯一不可接受的組合

> English version: [ON_DELETE_CASCADE_RISK.en.md](./ON_DELETE_CASCADE_RISK.en.md)
>
> 撰寫日期：2026-07-07 ｜ 依據：`database/migrations/2025_01_01_000000_import_cbdb_schema.php`（生產 MariaDB schema 的入庫來源）
>
> **本文目的**：說明本專案 schema 目前處於「物理刪除 × 級聯刪除」這個對資料資產最危險的組合中，
> 解釋其原理性風險（這在資料庫應用領域本應是共識）；並給出破局矩陣——**去級聯（RESTRICT）與
> 軟刪除（deprecate）各自解決什麼、為何兩者互補而非二選一**——以及可執行的改造路線圖。
>
> 文中所有數字皆可用附錄 A 的指令重跑驗證。

---

## TL;DR

1. 本專案 schema 有 **186 個 `ON DELETE CASCADE` 外鍵**（另有 187 個 `ON UPDATE CASCADE`），遍布所有核心表，其中絕大多數掛在「傳記資料 → 詞表（code 表）」的**引用關係**上；而應用層對詞表執行的是**物理刪除**（且多數路徑無引用檢查）。
2. 這個組合的後果：**刪除一筆詞表記錄（一個年號、一個朝代、一筆書名），資料庫會靜默連帶刪除所有引用它的傳記資料**——包括 `BIOG_MAIN` 的人物本身，並繼續向下刪光這些人物的全部記錄。應用層的審計（audit_log）、操作紀錄（operations）、提案審核、回退機制**全部無感知**。
3. 破局矩陣（本文核心論點）：

   | | `ON DELETE CASCADE` | `RESTRICT` |
   |---|---|---|
   | **物理刪除** | **現狀：災難**（一條 DELETE 靜默毀庫） | 可接受（配合引用檢查與遷移工具） |
   | **軟刪除 / deprecate** | 帶電的陷阱（僅靠約定護體，違約即災難） | **目標形態** |

   必須至少打破一個因子；兩個都打破才是完整方案。**去級聯是保險絲（出錯時的後果封頂），軟刪除是產品語義（正常路徑不再發生刪除）——層次不同，互補而非替代。**
4. 改造路徑：先翻約束（一個 migration，成本最低的止血步），再上詞表生命週期（deprecate 為主、重複合併為輔、零引用才允許真刪）。兩步互相成就：全面軟刪除後沒有合法硬刪流程，翻 RESTRICT 阻力為零；翻了 RESTRICT 後，軟刪除約定被違反時有兜底。詳見 §5。

---

## 1. 現狀（證據）

`import_cbdb_schema.php` 中的外鍵行為統計：

| ON DELETE 行為 | 數量 |
|---|---|
| `CASCADE` | **186** |
| `SET NULL` | 1（`fk_merged_person_source`，較新的表，做法正確） |
| `RESTRICT` / `NO ACTION` | 0 |

被引用最多的目標表（入邊數）：

| 被引用表 | 引用它的 CASCADE 外鍵數 | 性質 |
|---|---|---|
| `BIOG_MAIN` | 25 | 人物主表 |
| `NIAN_HAO` | 24 | 詞表（年號） |
| `YEAR_RANGE_CODES` | 23 | 詞表 |
| `TEXT_CODES` | 22 | 詞表（書目） |
| `ADDR_CODES` | 11 | 詞表（地名） |
| `GANZHI_CODES` / `DYNASTIES` | 各 9 | 詞表 |

特別注意：**`BIOG_MAIN` 自身有 13 個指向詞表的 CASCADE 外鍵**（`BIOG_MAIN_ibfk_1`–`13`，指向 `NIAN_HAO`×4、`YEAR_RANGE_CODES`×3、`GANZHI_CODES`×2、`DYNASTIES`、`CHORONYM_CODES`、`ETHNICITY_TRIBE_CODES`、`HOUSEHOLD_STATUS_CODES`）。

同時，應用層對詞表的「刪除」目前是**物理 DELETE**：`/codes` 編輯器與 `AdminBatchLoadBookTitlesController::deleteBatch` 硬刪 `TEXT_CODES`，刪除前不做引用檢查——矩陣左上角的兩個條件同時成立。

### 1.1 歷史背景：這是刻意的設計取捨，而非疏忽

「物理刪除 × CASCADE」在原本的設計中是有意識的省時設置：CBDB 的代碼表並非逐條經過字典式考證，而是在**批量建設、允許一定錯誤率**的前提下累積起來的，「物理刪除 × CASCADE」因此成為清理 codes 連同其引用資料的低成本手段。本文不否定這個取捨在當時的合理性，而是指出取捨賴以成立的成本結構已經改變：

- 在 agent 協助下，把外鍵機制承擔的清理邏輯搬進應用層代碼（引用檢查、合併＋重定向、顯式級聯）的開發時間成本已經可控；
- 專案的可追溯目標正從**離散可追溯**（發佈離散的版本，版本之間的差異不可考）走向**線性可追溯**（operations / audit_log 記全每個版本差異之間的 logs；Phase B 已為 `/codes` 直寫路徑補上 audit_log）。DB 級聯是這條線上的結構性盲區（§3.3），不移除它，線性可追溯在機制上就無法成立；
- 線上系統與離線發佈版（Michael 的 Access）在結構上分離，由匯出功能保證發佈版與 Access 架構一致（§5.3），因此線上 schema 的演進（如加 `c_deprecated` 欄）不受發佈格式束縛。

## 2. 一個具體的災難場景

以 `NIAN_HAO`（年號詞表）為例，執行一條看似無害的語句：

```sql
DELETE FROM NIAN_HAO WHERE c_nianhao_id = 630;   -- 刪除一筆「重複的」年號
```

InnoDB 實際執行的是：

1. 刪除該年號；
2. **級聯刪除所有 `c_by_nh_code` / `c_dy_nh_code` / `c_fl_ey_nh_code` / `c_fl_ly_nh_code` 引用它的 `BIOG_MAIN` 列**——即所有用這個年號記錄生卒年或活動年的**人物本身**；
3. 每一個被刪的人物，再級聯刪除 25 張表中屬於他的全部記錄：別名、親屬、社會關係、任職（連同任職地址）、著述、入仕、事件、財產……；
4. 其中親屬/社會關係的鏡像列牽涉**其他人物**的資料頁；
5. 全程：應用層收到的 affected rows 只有第 1 步的 `1`；`operations` 與 `audit_log` **一列都不會多**；提案審核流程完全未被觸發；沒有任何可回退的快照。

換句話說：**一條 DELETE 可以無聲地抹掉數百人的完整傳記，且事後無從得知發生過**。

> 此場景聽起來難以置信，因此已在真實 MariaDB 10.11 上以逐字取自本 schema 的約束做了最小重現實驗（附錄 C）：
> 刪 1 筆年號，`ROW_COUNT()` 回報 **1**，實際 8 列消失（2 個人物整列＋其別名/親屬記錄），級聯傳遞兩層，
> 第二層的刪除不存在任何對應的 DELETE 語句可供應用層攔截。

這不是純理論。現有代碼中已經存在完整的事故鏈路：

- `/codes` 編輯器與 `AdminBatchLoadBookTitlesController::deleteBatch` 會硬刪 `TEXT_CODES`，**刪除前不做任何引用檢查**；
- `TEXT_CODES` 有 22 條 CASCADE 入邊（`ALTNAME_DATA.c_source`、`BIOG_SOURCE_DATA.c_textid`、`TEXT_DATA`、`KIN_DATA.c_source`……甚至 `TEXT_CODES` 自引用），刪一筆書名會靜默刪除引用它的別名、出處、著述記錄；
- `deleteBatch` 還會主動刪除對應的 `operations` 紀錄——僅存的痕跡也被抹掉。

另外，**測試完全無法捕捉這類問題**：本地與 CI 測試環境是 SQLite，建表語句不含這些外鍵（`PRAGMA foreign_keys` 亦為 0），級聯行為只在生產 MariaDB 上存在。測試全綠證明不了任何事。

## 3. 為什麼「物理刪除 × CASCADE」危險——原理

### 3.1 它把「引用」誤當成「從屬」

級聯刪除的正當語義只有一種：**組合關係（composition）**——子記錄離開父記錄毫無意義，如訂單之於訂單明細。刪訂單連帶刪明細，天經地義。

但 CBDB 的外鍵幾乎全是**引用關係（reference）**：一筆別名記錄「引用」某書作為出處，不代表這筆別名「從屬」於那本書。書名詞條寫錯了要處理，是詞表維護；因此連帶銷毀幾百條傳記事實，是資料災難。詞表是**詞彙**，傳記資料是**資產**；詞彙的增刪改永遠不應該有權力銷毀資產。

用一個判別法：問「刪掉被引用方時，引用方記錄承載的歷史事實還成立嗎？」——「張三的別名見於某書」這個事實，不因某書的詞條被刪而消失。事實還在，記錄就不該被刪。CBDB 的 186 個 CASCADE 裡，能通過組合關係檢驗的屈指可數（如 `POSTED_TO_ADDR_DATA` 之於 `POSTED_TO_OFFICE_DATA`）。

### 3.2 它是一台無界的放大器

CASCADE 是**傳遞閉包**：詞表 → `BIOG_MAIN` → 25 張子表 → 鏡像列。一條 DELETE 的實際破壞範圍取決於整張外鍵圖和當下的資料分佈，寫這條 DELETE 的人（或代碼）**無法從語句本身看出**會刪掉多少東西。破壞量與操作意圖完全脫鉤——這違反了資料操作最基本的可預期性原則。

### 3.3 它對應用層完全不可見

這一點對本專案是致命的。我們投入了大量工程建立資料保護機制——`operations` 操作紀錄、`audit_log` 前後鏡像、提案審核（proposal/approve）、回退（restore）——它們全部工作在**應用層**。而 DB 級聯發生在**存儲引擎層**：

- 被級聯刪除的列，`audit_log` 沒有鏡像，`operations` 沒有紀錄；
- 提案審核形同虛設：單筆編輯要走審核，而一條詞表 DELETE 可以繞過一切；
- 「所有操作皆可回退」的目標在數學上不可能達成——你無法回放你從未看見的刪除。

**只要 CASCADE 存在，任何應用層的審計與撤銷機制都有一個結構性天窗。**

### 3.4 失效模式不對稱

工程上選擇機制，要比較的不是「正常時誰更方便」，而是「出錯時代價是什麼」：

| | 出錯情境 | 後果 | 可見性 | 可恢復性 |
|---|---|---|---|---|
| `RESTRICT` | 應用層忘了處理引用就刪 | **報錯，操作被拒** | 立即、明確 | 完全（什麼都沒發生） |
| `CASCADE` | 應用層發出一條錯誤的 DELETE | **資料被靜默銷毀** | 可能數月後才發現 | 備份輪換後即永久丟失 |

RESTRICT 的失敗模式是「煩人但無害」（fail-closed），CASCADE 的失敗模式是「安靜但毀滅」。CASCADE 唯一安全的前提是「應用層永遠不會發出錯誤的 DELETE」——這個前提在任何真實系統中都不成立，本專案的 `deleteBatch` 就是現成的反例。**注意這個論證不依賴「應用層打算怎麼刪」：無論應用層採用物理刪除還是軟刪除約定，只要 CASCADE 還在，違約/出錯的代價就是毀庫。這是 §4 矩陣「左下格仍然不可接受」的原因。**

### 3.5 為什麼說這是行業共識

- 幾乎所有以資料為資產的領域（金融、醫療、檔案）的資料庫規範都禁止或嚴格限制級聯刪除，正是出於 3.3/3.4 的理由：審計完整性要求一切變更經過應用層。
- 主流 ORM（Laravel/Eloquent、Rails、Django、Hibernate）都提供**應用層級聯**作為推薦路徑，因為只有應用層級聯才會觸發 hooks、observers、審計與業務校驗；Eloquent 的 model event 在 DB 級聯下完全不觸發，這正是本專案 audit 失明的機制。
- `ON DELETE CASCADE` 的合理使用場景被普遍限定為：純從屬的組合關係＋無應用層審計需求＋刪除範圍可預期。CBDB 的 186 個裡幾乎沒有同時滿足三條的。

順帶一提，schema 裡唯一的例外 `fk_merged_person_source`（`ON DELETE SET NULL`）出自較新的工作——說明專案內部已經有人在新表上做出了正確判斷，現在需要的是把這個判斷推廣回存量。

## 4. 破局矩陣：去級聯與軟刪除各解決什麼

### 4.1 物理刪除下的三種衝突解法

外鍵約束保證的不變量是**引用完整性**：不存在懸掛引用。當你**物理刪除**一個仍被引用的目標時，這個不變量受到威脅，解法在數學上只有三種：

1. **拒絕**（RESTRICT）：不許刪，先去處理引用；
2. **置空**（SET NULL）：引用欄設為 NULL（僅適用於「引用可缺失」的欄）；
3. **傳播**（CASCADE）：把引用方一起刪掉。

**三種解法維持的不變量一模一樣。** cascade 沒有提供任何額外的一致性——它只是選了「自動銷毀最多資料」的那一種，且不徵求任何人的同意。所以「不 cascade 一致性怎麼辦」是個偽問題：改用 RESTRICT 之後，資料庫依然**強制**保證無懸掛引用，變化只是把衝突拋給應用層和人來決策。

### 4.2 第四個選項：不做物理刪除（軟刪除 / deprecate）

上面三選一的前提是「刪除必鬚髮生」。現代資料資產系統的標準做法是消解這個前提：**詞表項根本不物理刪除，而是退役（deprecate）**。這正是權威檔案系統（圖書館規範檔、SNOMED、MeSH）的通行實踐——詞條從不消失，只標記「不再允許新引用」。CBDB 的詞表本質上就是規範檔。

對詞表加狀態欄（建議語義化為 `c_deprecated` 而非 `deleted_at`——詞條沒有被刪，只是退役）之後：

- 選擇器/搜尋端點過濾掉已退役詞條，達成「刪除」的業務目的；
- 既有傳記資料的外鍵照常成立，**不會產生懸掛引用**；
- 「某人的別名見於某書」的歷史事實得以保留。

兩個必要的細節：

- **可見性是按上下文的，不能全局一刀切**：選擇器裡要隱藏，但傳記資料頁 JOIN 詞表取顯示標籤時**必須照常顯示**（否則老記錄的年號/書名全部變空白）。實作上只改「選用」端點，工作量有界。
- **deprecate 解決「退役」，解決不了「錯誤與重複」**：實踐中觸發「刪詞條」需求最多的是錯字詞條、重複詞條（如重複的「至元」）。這時舊引用指向的是**錯誤記錄**，保留它不是保存歷史而是保存錯誤——引用被分裂在正確與錯誤詞條之間，按正確詞條檢索永遠漏掉那批記錄。這類場景的正解是**合併＋重定向**（把引用批次改指到正確詞條，錯誤詞條退役並留重定向），所以「引用遷移工具」不是去級聯方案強加的複雜度，是資料品質治理本身的需求，軟刪除方案同樣需要它。

### 4.3 為什麼軟刪除不能替代去級聯——矩陣

把兩個維度交叉，得到本文的核心結論：

| | `ON DELETE CASCADE` | `RESTRICT` |
|---|---|---|
| **物理刪除** | **① 現狀：災難。** 一條 DELETE 靜默毀庫，審計失明，不可恢復 | **② 可接受。** 需配套引用檢查與遷移工具；忘了配套時得到報錯而非資料丟失 |
| **軟刪除 / deprecate** | **③ 帶電的陷阱。** 正常路徑安全，但保護只來自「約定」；任何違約的 DELETE（腳本、遷移、bug）觸發與 ① 相同的毀滅 | **④ 目標形態。** 正常路徑無刪除，違約時 fail-closed |

關鍵在 ③：**軟刪除是應用層約定，約束是資料庫層保險絲，層次不同**。約定管「正常時怎麼做」，保險絲管「出錯時會怎樣」。選了軟刪除而保留 CASCADE，等於拆掉煙霧報警器並承諾「我不會失火」——`deleteBatch` 已經示範過約定會被違反。§3.4 的失效模式論證對軟刪除方案原封不動地適用。

反過來，兩者互相成就：

- **全面軟刪除之後，翻 RESTRICT 的成本歸零**——不再存在任何合法的硬刪流程，約束不會擋住任何正常功能，純粹變成防呆；
- **翻了 RESTRICT 之後，軟刪除約定有了兜底**——新人不知道約定、舊腳本沒改到，得到的是報錯，不是無聲的災難。

因此本文的主張不是「去級聯 vs 軟刪除」二選一，而是：**至少打破矩陣的一個因子（其中翻約束是一個 migration 就能完成的止血步），並以 ④ 為目標形態。**

### 4.4 每一類關係的完整策略

| 關係類型 | 例子 | DB 約束 | 應用層職責 |
|---|---|---|---|
| **詞表引用**（絕大多數） | `ALTNAME_DATA.c_source → TEXT_CODES`、`BIOG_MAIN.c_dy → DYNASTIES` | `RESTRICT` | 詞條生命週期：**deprecate 為主**（退役，選擇器隱藏、顯示照常）；**合併＋重定向**處理錯誤/重複；**真刪僅限零引用**的詞條 |
| **組合從屬**（少數） | `POSTED_TO_ADDR_DATA → POSTED_TO_OFFICE_DATA` | `RESTRICT`（兜底） | 應用層**顯式級聯**：同一交易內先展開子記錄集合、逐列快照寫 audit_log（同一 operation_id）、再刪除；UI 事前告知「將連帶刪除 N 列」 |
| **對等鏡像** | `KIN_DATA` / `ASSOC_DATA` 反向列 | `RESTRICT` | 應用層同組處理＋既有的鏡像衝突偵測 |
| **可缺失引用** | `fk_merged_person_source` | `SET NULL` 可接受 | 已是現行正確樣板 |

「應用層顯式級聯」不是新發明——`OfficePostingRepository` 刪任職時在交易內顯式刪除 `POSTED_TO_ADDR_DATA`，就是現成的正確寫法。最後一道防線是**週期性孤兒掃描**（縱深防禦，不是主要機制）。

### 4.5 「這樣『刪除』會變麻煩」——對，這正是目的

改造後，「刪」一筆還被引用的年號不再是隨手一按：退役它、或先把引用遷走。**銷毀（或藏起）一個仍被幾百筆傳記引用的詞條，本來就不該是一個能隨手完成的操作。** 麻煩沒有消失，只是從「事後追查資料去哪了」變成「事前多點兩下」——這是所有資料資產系統都做出的取捨。

## 5. 改造路線圖

### 5.0 目標形態（矩陣 ④）

- **詞表項的生命週期**取代「刪除」：正常退役走 **deprecate**（選擇器隱藏、顯示照常、既有引用不動）；錯誤/重複走**合併＋重定向**（受審計的批次 re-point，錯誤詞條退役）；**物理刪除僅限引用數為零**的詞條，且經引用檢查確認。
- **刪從屬記錄**（任職→任職地址等真組合關係）：統一刪除服務——同一交易內展開子記錄、逐列快照寫 `audit_log`（同一 operation_id）、刪除、可整組回退。
- **DB 約束全面 `RESTRICT`**：以上任何一層被繞過時，得到報錯而不是資料丟失。所有寫入皆有 operations/audit 紀錄、皆可回放。

### 5.1 改造步驟

> 順序刻意安排為「應用層先行、DB 約束殿後」：若先把約束翻成 RESTRICT，現存**隱式依賴級聯**的刪除路徑會立刻開始報 500。先補齊應用層，再翻約束，全程無行為斷崖。

**Step 0：核實生產庫（半天）**
在生產 MariaDB 跑附錄 B 查詢，導出實際生效的約束清單。本文以 migration 為據，**線上以此為準**。
產出：`cascade_inventory.csv`（約束名、表、欄、目標表、DELETE_RULE、UPDATE_RULE）。

**Step 1：分類與影響面盤點（1–2 天）**
對每個 CASCADE 外鍵標注關係類型（§4.4 四類）；同時 grep 全部刪除代碼路徑（controllers/repositories/commands），標記哪些**隱式依賴 DB 級聯**清理引用（已知：`/codes` 刪除、`AdminBatchLoadBookTitlesController::deleteBatch`；需全面排查）。
產出：決策表（每個約束一行：現狀 → 目標規則 → 依賴它的代碼路徑 → 需要的應用層改動）。

**Step 2：應用層補課（每項獨立 PR）**
1. **引用檢查服務**：輸入（詞表, 主鍵值），輸出各引用表的計數與樣例——資料來源就是 Step 0 的外鍵清單，可直接由 `information_schema` 驅動，不必手工維護映射。
2. **詞表生命週期**：詞表加 `c_deprecated` 狀態欄（僅詞表、非資料表；**新增欄位須先經 executive committee 討論，離線發佈版的對齊規則與匯出更新見 §5.3，匯出更新與本欄位同一里程碑交付**）；選擇器/搜尋端點過濾退役詞條，顯示 JOIN 不過濾；`/codes` 的「刪除」改為「退役」，物理刪除僅在引用數為零時開放；廢除 `deleteBatch` 刪 `operations` 紀錄的行為。
3. **合併＋重定向工具**：把 A 詞條的全部引用批次改指到 B 詞條，受審計、可回退，A 退役留重定向——這也是日後「人物合併」的同構地基。
4. **顯式級聯刪除服務**：覆蓋 Step 1 標記為「組合」的少數關係（參考 `OfficePostingRepository` 現行寫法，補齊快照與同 operation_id 分組）。

**Step 3：翻轉約束（止血 migration，維護窗口執行）**
按 Step 1 決策表批量執行：

```sql
ALTER TABLE ALTNAME_DATA
  DROP FOREIGN KEY ALTNAME_DATA_ibfk_3,
  ADD CONSTRAINT ALTNAME_DATA_ibfk_3 FOREIGN KEY (c_source)
      REFERENCES TEXT_CODES (c_textid)
      ON DELETE RESTRICT ON UPDATE CASCADE;   -- UPDATE 行為此階段先不動
```

- 只改約束行為，不動表結構與資料；重建 FK 需要驗證掃描，先在 staging 副本量測大表的執行時間，決定是否用 `SET foreign_key_checks=0` 跳過驗證（資料既有一致性由原約束保證）。
- 分批執行（每批一組表），批間可回退：回退動作＝把該批約束改回 CASCADE，一條 ALTER 即可，無資料風險。
- 優先順序按入邊數：`NIAN_HAO`(24)、`YEAR_RANGE_CODES`(23)、`TEXT_CODES`(22)、`ADDR_CODES`(11)、`GANZHI_CODES`/`DYNASTIES`(9)，再及其餘。
- 同步更新 `import_cbdb_schema.php`（或新增 migration），保持新裝環境一致。

**Step 4：驗證與回歸（與 Step 3 同窗口）**
- staging 上實測：刪一筆被引用的年號 → 期望應用層擋下（或 DB 報 1451 錯誤），傳記資料一列不少；退役一筆年號 → 選擇器消失、既有記錄顯示照常；
- 核對 `information_schema`：目標批次內 `DELETE_RULE='CASCADE'` 歸零；
- 生產執行後同樣核對＋抽測。

**Step 5：收尾與長期項**
- **孤兒掃描**排程：週期核對引用完整性作為縱深防禦（覆蓋約束缺口與歷史遺留）。
- **`ON UPDATE CASCADE`（187 個）單獨立項**：改鍵傳播不銷毀資料、危險性低一級，但同樣繞過審計。長期應把「詞表改鍵」收為應用層受審計操作後改為 RESTRICT；在那之前先在文件與 UI 標明現狀。
- **測試環境缺口單獨立項**：SQLite 無外鍵意味著 CI 永遠測不到本文所有行為，MariaDB 約束相關變更需要專門的整合驗證手段（如 CI 起 MariaDB 容器跑約束測試）。

### 5.2 工作量與依賴總覽

| 步驟 | 工作量 | 依賴 | 風險 |
|---|---|---|---|
| 0 核實 | 半天 | 生產庫只讀權限 | 無 |
| 1 分類盤點 | 1–2 天 | Step 0 | 無（純分析） |
| 2 應用層補課 | 中（4 個小 PR） | Step 1 | 低；每項獨立可回退 |
| 3 翻轉約束 | 低（migration）＋維護窗口 | Step 2 完成 | 中；staging 量測＋分批＋單條 ALTER 可回退 |
| 4 驗證 | 半天 | Step 3 | 無 |
| 5 長期項 | 各自立項 | — | — |

整條路線沒有一步是「大爆炸」：應用層 PR 各自獨立，約束翻轉分批且逐批可退。完成後系統落在矩陣的 ④（軟刪除 × RESTRICT）：正常路徑上「刪除」不再發生，異常路徑上代價被封頂為一個報錯——「任何操作皆可回退」才第一次在機制上成為可能。在此之前，DB 級聯這個天窗會讓任何審計/撤銷工程的保證落空，這也是為什麼本文的結論是：**打破「物理刪除 × CASCADE」組合應排在所有資料安全工程之前。**

### 5.3 離線發佈（SQLite / Access）與治理對齊

線上系統與離線發佈版在結構上分離，由匯出功能保證發佈版與 Access 架構一致。`c_deprecated` 落地時，離線發佈有兩件事必須同步處理：

**(1) 匯出排除規則——deprecated codes 與人物軟刪除結構不同，不能照搬整批排除。**

已有的先例是 BIOG_MAIN 人物軟刪除的匯出排除（commit 8930d73，見 `docs/SQLITE_DATA_RELEASE.md`）：已刪除人物是**整組排除**——人物列、其所有資料列、他人紀錄中提及它的關係列（經 `information_schema` 動態偵測 FK 欄位）一起消失，匯出檔中不留懸掛引用；`information_schema` 查詢失敗時 fail-closed 中止整個匯出，配專屬回歸測試。這個**模式**（過濾集中在 query 建構處、fail-closed、回歸測試）值得沿用。

但 deprecate 的**語義**與人物軟刪除相反：既有引用保留、只禁止新引用。引用某個 deprecated code 的資料列都是正常人物的正常資料，不能連坐排除；若把 deprecated codes 整批從匯出剔除，這些資料列會帶著懸掛外鍵值（指向 Access/SQLite 中不存在的 code），Access 端 JOIN 直接漏資料。因此匯出規則按引用數分兩檔：

| deprecated code 的引用數 | 匯出行為 | 說明 |
|---|---|---|
| **零** | 從匯出排除 | 對 Access 而言等同於「已刪除」，與現行認知一致 |
| **仍有引用** | 照常匯出為普通 code 列（**不帶 `c_deprecated` 欄**） | 對 Access 完全透明、不產生懸掛引用；治理上由合併＋重定向（Step 2-3）逐步把引用遷走，引用歸零後自然從下一個發佈版消失 |

匯出功能的更新與 `c_deprecated` 欄位**放在同一里程碑交付**，不允許出現「欄位上了、匯出還沒跟上」的窗口。

**(2) 治理前提——新增欄位須經 executive committee 討論。**

線上系統新增欄位一律需經 executive committee 討論。由於 `c_deprecated` 只存在於線上系統、依上表規則**不匯出到 Access**，對 committee 而言這不是共享 schema 的變更，而是**詞表生命週期政策的變更**（「刪除」變為「退役／合併」，以及發佈版本的收錄規則）。提交討論時準備一頁說明：`c_deprecated` 的語義、上述匯出規則、以及發佈的 Access 版本 schema 保持不變。此討論是 Step 2 里程碑 2 的前置條件。

---

## 附錄 A：可重跑的驗證指令

```bash
# ON DELETE 行為統計（against migration）
grep -o "ON DELETE [A-Z ]*" database/migrations/2025_01_01_000000_import_cbdb_schema.php | sort | uniq -c

# 各表被 CASCADE 引用的入邊數排名
grep -o "CONSTRAINT \`[A-Za-z_0-9]*\` FOREIGN KEY (\`[a-z_]*\`) REFERENCES \`[A-Z_]*\`" \
  database/migrations/2025_01_01_000000_import_cbdb_schema.php \
  | sed 's/.*REFERENCES `\([A-Z_]*\)`/\1/' | sort | uniq -c | sort -rn | head -20

# BIOG_MAIN 自身的外鍵（13 個，全部 CASCADE）
grep -o "CONSTRAINT \`BIOG_MAIN_ibfk_[0-9]*\`[^,]*" database/migrations/2025_01_01_000000_import_cbdb_schema.php

# 引用 TEXT_CODES 的全部外鍵（22 個 CASCADE + 1 個 SET NULL）
grep -o "CONSTRAINT \`[A-Za-z_0-9]*\` FOREIGN KEY (\`[a-z_]*\`) REFERENCES \`TEXT_CODES\`[^,]*" \
  database/migrations/2025_01_01_000000_import_cbdb_schema.php
```

## 附錄 B：生產庫核實查詢（MariaDB）

```sql
SELECT rc.CONSTRAINT_NAME, rc.TABLE_NAME, kcu.COLUMN_NAME,
       rc.REFERENCED_TABLE_NAME, rc.DELETE_RULE, rc.UPDATE_RULE
FROM information_schema.REFERENTIAL_CONSTRAINTS rc
JOIN information_schema.KEY_COLUMN_USAGE kcu
  ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
 AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
ORDER BY rc.DELETE_RULE, rc.REFERENCED_TABLE_NAME, rc.TABLE_NAME;

-- 只看 CASCADE 的
-- ... AND rc.DELETE_RULE = 'CASCADE'
```

## 附錄 C：災難場景的最小重現實驗（已於 MariaDB 10.11 實測）

約束子句逐字取自 `import_cbdb_schema.php`（`BIOG_MAIN_ibfk_2`、`ALTNAME_DATA_ibfk_2`、`KIN_DATA_ibfk_1`）：

```bash
docker run -d --name cascade-test -e MARIADB_ROOT_PASSWORD=test \
  -e MARIADB_DATABASE=cbdbtest mariadb:10.11
```

```sql
CREATE TABLE NIAN_HAO (c_nianhao_id INT PRIMARY KEY, c_nianhao_chn VARCHAR(50)) ENGINE=InnoDB;
CREATE TABLE BIOG_MAIN (
  c_personid INT PRIMARY KEY, c_name_chn VARCHAR(255), c_by_nh_code INT DEFAULT NULL,
  CONSTRAINT BIOG_MAIN_ibfk_2 FOREIGN KEY (c_by_nh_code)
    REFERENCES NIAN_HAO (c_nianhao_id) ON DELETE CASCADE ON UPDATE CASCADE) ENGINE=InnoDB;
CREATE TABLE ALTNAME_DATA (
  c_personid INT NOT NULL, c_alt_name_chn VARCHAR(255),
  CONSTRAINT ALTNAME_DATA_ibfk_2 FOREIGN KEY (c_personid)
    REFERENCES BIOG_MAIN (c_personid) ON DELETE CASCADE ON UPDATE CASCADE) ENGINE=InnoDB;
CREATE TABLE KIN_DATA (
  c_personid INT NOT NULL, c_kin_id INT NOT NULL,
  CONSTRAINT KIN_DATA_ibfk_1 FOREIGN KEY (c_personid)
    REFERENCES BIOG_MAIN (c_personid) ON DELETE CASCADE ON UPDATE CASCADE) ENGINE=InnoDB;

INSERT INTO NIAN_HAO VALUES (630, '至元');
INSERT INTO BIOG_MAIN VALUES (1,'張三',630),(2,'李四',630),(3,'王五',NULL);
INSERT INTO ALTNAME_DATA VALUES (1,'張三別名A'),(1,'張三別名B'),(2,'李四別名'),(3,'王五別名');
INSERT INTO KIN_DATA VALUES (1,3),(2,3),(3,1);

DELETE FROM NIAN_HAO WHERE c_nianhao_id = 630;
SELECT ROW_COUNT();          -- → 1（應用層只看得到這個數字）
SELECT COUNT(*) FROM BIOG_MAIN;    -- 3 → 1（張三、李四整列消失）
SELECT COUNT(*) FROM ALTNAME_DATA; -- 4 → 1
SELECT COUNT(*) FROM KIN_DATA;     -- 3 → 1
```

實測結果：一條 DELETE，回報 1 列，實刪 8 列，級聯傳遞兩層（年號→人物→人物的別名/親屬）。
第二層的刪除**不存在任何 DELETE 語句**，應用層（audit、operations、Eloquent observer）無從掛鉤。
註：真實 schema 中 `KIN_DATA.c_kin_id` 亦有 CASCADE 外鍵指向 `BIOG_MAIN`，故**其他人物**記錄中
「親屬為被刪者」的列也會連帶消失（本實驗僅建單邊外鍵，故王五指向張三的親屬列倖存）——實際擴散比實驗更廣。
