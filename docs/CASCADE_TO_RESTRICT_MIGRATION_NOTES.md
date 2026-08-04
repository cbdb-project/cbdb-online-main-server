# CASCADE → RESTRICT：翻轉 migration 的機制與分批 rollout（驗證平台 MariaDB 10.3；prod 為 10.11.14）

> 狀態：**批次 1–4 與末批全部落地並實測完成**——全庫 ON DELETE CASCADE 歸零（僅餘 1 條既有且正確的 SET NULL）。
> 上位文件：[docs/ON_DELETE_CASCADE_RISK.md](./ON_DELETE_CASCADE_RISK.md)（§6「RESTRICT 先行、按被引用表分批」）
> 目的：把 §6.1 的翻轉方案落成可執行、可分批、可回滾的 migration，並記錄 §6.1 範例 SQL 在
> **MariaDB 10.3** 上跑不起來的實測修正（該文件的範例是在 10.11 驗的）。全部批次都在 10.3
> 上驗證——這是兩個候選版本中較嚴格的一個；prod 實際為 10.11.14（§2），10.3 通過即 10.11 通過。

---

## 1. 結論：一個 migration／批，每條 FK 兩條 ALTER

- **不是**「一支 migration 去掉 FK、另一支加回」——中間會有無外鍵空窗，危險且無意義。
- 是**一個 migration（一批）**，對該批每條 FK 跑**兩條獨立 ALTER**：
  1. `ALTER TABLE <child> DROP FOREIGN KEY <name>;`
  2. `ALTER TABLE <child> ADD CONSTRAINT <name> FOREIGN KEY (<col>) REFERENCES <parent>(<col>) ON DELETE RESTRICT ON UPDATE CASCADE;`
- **分批單位＝被引用表**（§6.1）：一張（或一組）被引用表的所有入邊 FK 放同一 migration 翻轉，
  按入邊數由高至低推進，逐批觀察、逐批可退。

## 2. 為什麼必須兩條 ALTER，而非 §6.1 的「同一 ALTER 內 DROP＋ADD」——10.3 實測

| 做法 | MariaDB 10.3 結果 |
|---|---|
| 同一 `ALTER` 內 `DROP FOREIGN KEY x, ADD CONSTRAINT x ...`（同名） | ❌ `ERROR 1826 (HY000): Duplicate FOREIGN KEY constraint name` |
| 拆成兩條 `ALTER`：先 `DROP`、再 `ADD`（同名） | ✅ 成功；`DELETE_RULE` 變更；刪被引用父列 `ERROR 1451` 擋下 |

- §6.1 的範例在 **MariaDB 10.11** 驗（見該文件附錄 C）。本文件早期依 `AGENTS.md` 假設 prod 為
  10.3，故全部批次都在 10.3 容器上驗——10.3 不允許在單一 `ALTER` 內 DROP 又 ADD 同名 FK。
- ✅ **已查明（2026-08-03）**：prod 實際為 **MariaDB `10.11.14`**（經 prod MCP `SELECT VERSION()`）。
  這對已落地的 migration **沒有影響**：驗證是在**更嚴格**的 10.3 上做的（1826 限制為 10.3 獨有），
  兩條獨立 ALTER 在 10.11 同樣合法，10.3 通過即 10.11 通過。**兩條 ALTER 在兩版皆安全，一律採用**。

## 3. 10.3 的坑：RESTRICT 在 information_schema 多半顯示為 NO ACTION

寫 `ON DELETE RESTRICT`，`information_schema.REFERENTIAL_CONSTRAINTS.DELETE_RULE` 在 10.3
回報 **`NO ACTION`**（InnoDB 中 RESTRICT ≡ NO ACTION）。因此驗證須 `DELETE_RULE IN ('RESTRICT','NO ACTION')`，
或**直接驗行為**（刪被引用列 → 1451 擋下、資料一列不少）。顯式寫 `RESTRICT`（可讀），驗證兩者皆收。

## 4. `foreign_key_checks` 與大表

- ADD FK 在 `foreign_key_checks=1` 下會**掃描子表**（大表如 `POSTED_TO_OFFICE_DATA` 會慢）。
- 舊 FK 已保證一致性，翻轉時 `SET SESSION foreign_key_checks=0` 跳過掃描（近乎即時），ADD 後即 `=1`。
- 此變數是 **session 級、非交易性**：在 up() 內成對開關，且用 `try/finally` 確保**即使拋錯也還原為 1**
  （否則連線歸還連線池後會遺留「無 FK 檢查」污染後續請求）。

## 5. Laravel + SQLite 相容（AGENTS.md §1）

- **SQLite 不支援 ALTER 改 FK**，且測試 SQLite 本就無外鍵（baseline 關 FK 檢查）。
  故 migration **必須 MySQL-only**：`if (!is_mysql()) return;`，SQLite 為 no-op。
- 不用可攜 Schema Builder（SQLite 會炸），直接 `DB::statement()` raw ALTER。
- 行為驗證**只能在 MariaDB 上做**（CI 起 MariaDB 容器為長期項，§6.1）。
- **baseline `import_cbdb_schema.php` 不必改**：fresh install 先跑 baseline（CASCADE）、再跑翻轉
  migration，終態一致；編輯 186 條 FK 的 baseline 風險大於收益。

## 6. 批次 migration 的實作範式：資料驅動但範圍鎖定被引用表

批次 migration **不手抄 FK 清單**，而是讀 `information_schema` 取該批被引用表的實際入邊 FK
（含欄位）。理由：

- 免手抄數十條、避免轉錄錯誤；
- 容忍**約束名與表名不一致**（如 `BIOG_TEXT_DATA` 上的約束叫 `TEXT_DATA_ibfk_1`）；
- 自動涵蓋後續 migration 對該批新增的 FK。

範圍由 `REFERENCED_TABLES` 常數鎖定（＝這批要翻的被引用表），因此仍是**受控分批**、非大爆炸。
參考實作：[`database/migrations/2026_07_20_000000_restrict_fks_referencing_dynasty_batch.php`](../database/migrations/2026_07_20_000000_restrict_fks_referencing_dynasty_batch.php)
（批次 1）。骨架：

```php
private const REFERENCED_TABLES = [/* 這批的被引用表 */];

public function up(): void   { $this->flip('RESTRICT', ['CASCADE']); }
public function down(): void { $this->flip('CASCADE', ['RESTRICT', 'NO ACTION']); }

private function flip(string $onDelete, array $fromRules): void {
    if (!is_mysql()) return;                       // SQLite no-op
    // 讀 REFERENTIAL_CONSTRAINTS：REFERENCED_TABLE_NAME IN (REFERENCED_TABLES) AND DELETE_RULE IN (fromRules)
    // 每條 FK：讀 KEY_COLUMN_USAGE 取欄位 → 兩條 ALTER（DROP、ADD ... ON DELETE {$onDelete} ON UPDATE 原值）
    // 全程 foreign_key_checks=0（try/finally 還原）
}
```

## 7. 驗證（MariaDB，非 SQLite）

```sql
-- 該批被引用表的入邊應全為 RESTRICT/NO ACTION（10.3 顯示後者）
SELECT REFERENCED_TABLE_NAME, DELETE_RULE, COUNT(*)
FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND REFERENCED_TABLE_NAME IN (/* 這批的被引用表 */)
GROUP BY REFERENCED_TABLE_NAME, DELETE_RULE;
```
＋行為抽測：造一條被引用的父列，`DELETE` 之應回 1451、資料一列不少（建議納入 MariaDB 回歸）。

## 8. 分批推進進度（rollout log）

| 批次 | 被引用表（入邊數） | migration | 狀態 |
|---|---|---|---|
| **1** | NIAN_HAO(24)、YEAR_RANGE_CODES(23)、DYNASTIES(10)、GANZHI_CODES(9)＝**66 條** | `2026_07_20_000000_restrict_fks_referencing_dynasty_batch` | ✅ 已實作＋MariaDB 10.3 端到端驗（見 §9） |
| **2** | TEXT_CODES(21)、ADDR_CODES(11)＝**32 條**（TEXT_CODES 另 1 條 `SET NULL` 本已正確、未觸碰） | `2026_07_21_000000_restrict_fks_referencing_text_addr_codes` | ✅ 已實作＋MariaDB 10.3 端到端驗（見 §9.1）；同 commit 為唯一活硬刪路徑 `AdminBatchLoadBookTitlesController::undo()` 補 1451 友好報錯垫片 |
| **3** | 其餘全部小詞表／類型表／樹表 35 張＝**52 條**（KINSHIP_CODES(6)、ASSOC_CODES(4)、OFFICE_CODES(3)、TEXT_BIBLCAT_CODES／STATUS_CODES／ENTRY_CODES／COUNTRY_CODES／APPOINTMENT_CODES／OFFICE_TYPE_TREE／ADMIN_CAT_CODES(各 2)、其餘各 1，含自引用 pair FK 與 *_TYPE_REL 入邊） | `2026_07_23_000000_restrict_fks_referencing_small_code_tables` | ✅ 已實作＋MariaDB 10.3 端到端驗（見 §9.2）；同 commit 為唯一活硬刪路徑（office 實體刪除，經通用 `EntityAggregateDeleteHandler`）補 1451 友好報錯垫片 |
| **4** | SOCIAL_INSTITUTION_CODES(5)＋SOCIAL_INSTITUTION_NAME_CODES(5)＝**10 條**（含 CODES→NAME_CODES pair FK；資料表仍為 dual-key 雙單欄 FK 形態、未改複合鍵） | `2026_07_23_000001_restrict_fks_referencing_social_institution_tables` | ✅ 已實作＋MariaDB 10.3 端到端驗（見 §9.3）。前置皆已就位：codes UI 三表封寫（step 4）、實體刪除為顯式級聯（`SocialInstituteImportService::delete()`）＋引用護欄、`EntityAggregateDeleteHandler` 通用 1451 垫片 |
| **末批** | BIOG_MAIN(25，含 operations→BIOG_MAIN)＋POSTING_DATA(2)＋POSSESSION_DATA(1)＝**28 條** | `2026_08_03_000000_restrict_fks_referencing_biog_main_batch` | ✅ 已實作＋MariaDB 10.3 端到端驗（見 §9.4）；翻完全庫 `CASCADE 0`，去級聯 Phase 1 收尾 |

**節奏（§6.1「app-layer-first」）**：翻一批 → 觀察 1–2 週盯 1451（1451 會把漏網的 cascade
依賴刪除路徑逼出，fail-closed 零損失）→ 修應用層 → 下一批。`ON UPDATE CASCADE`（實測 190 條）本階段一律保留。

## 9. 批次 1 端到端實測（fresh MariaDB 10.3，2026-07-20）

在乾淨 MariaDB 10.3 容器上，用 app 容器從頭 `php artisan migrate`（env 指向 MariaDB）跑完全部
migration，再套用批次 1：

| 檢查 | 結果 |
|---|---|
| 全部 migration（baseline dump ＋後續）在 10.3 執行 | ✅ 全 DONE，無錯 |
| 批次 1 翻轉 | ✅ `flipped 66`，112ms |
| 四張表入邊 `DELETE_RULE` | ✅ 全 `NO ACTION`（NIAN_HAO 24／YEAR_RANGE 23／DYNASTIES 10／GANZHI 9） |
| 其他批未動 | ✅ 全庫 `CASCADE 122`／`NO ACTION 66`／`SET NULL 1`；SOCIAL_INSTITUTION 入邊仍 `CASCADE 5` |
| 行為（真 schema） | ✅ 刪被 BIOG_MAIN 引用的 NIAN_HAO → `ERROR 1451` 擋下，`nianhao=1 biog=1` 一列不少 |
| 可逆 | ✅ `migrate:rollback --step=1` 跑 down()，66 條翻回 CASCADE |

> 註：全庫共 188 條 CASCADE（baseline 186 ＋後續 migration 新增 2，如 events_data 複合 FK／admin_cat）；
> 另有 1 條既有正確的 `SET NULL`（`fk_merged_person_source`），全程未觸碰。
> 翻轉近乎即時（`foreign_key_checks=0` 免掃描）、完全可逆。

### 9.1 批次 2 端到端實測（fresh MariaDB 10.3，2026-07-21）

同 §9 流程（乾淨 10.3 容器、app 容器全量 `php artisan migrate`）：

| 檢查 | 結果 |
|---|---|
| 批次 2 翻轉 | ✅ `flipped 32`（TEXT_CODES 21＋ADDR_CODES 11；`fk_merged_person_source`→TEXT_CODES 的 `SET NULL` 未觸碰） |
| 兩表入邊 `DELETE_RULE` | ✅ 全 `NO ACTION`（10.3 顯示，§3），CASCADE 歸零 |
| 全庫 | ✅ `CASCADE 90`／`NO ACTION 98`／`SET NULL 1`（122−32＝90，其他批未動） |
| 行為：刪被引用列 | ✅ 刪被 BIOG_SOURCE_DATA 引用的 TEXT_CODES → 1451 擋下；刪被 BIOG_ADDR_DATA 引用的 ADDR_CODES → 1451 擋下；資料一列不少 |
| 行為：零引用刪除 | ✅ 引用移除後 DELETE 正常成功（物理刪除僅限零引用的目標行為成立） |
| 可逆 | ✅ `migrate:rollback --step=1` 翻回 CASCADE（32 條），re-migrate 恢復 RESTRICT |

> 註：風險文件附錄 A 稱 TEXT_CODES 有「22 CASCADE＋1 SET NULL」，實際 baseline 為 **21 CASCADE＋1 SET NULL**
> （grep 的 22 次 `REFERENCES TEXT_CODES` 已含 SET NULL 那條）。以 information_schema 實測為準。
>
> 應用層垫片（同 commit）：`AdminBatchLoadBookTitlesController::undo()` 是 TEXT_CODES 唯一未封堵的
> 硬刪路徑（只刪自己批次建立的列）。翻轉後批內列若已被引用，DELETE 撞 1451、整批交易回滾（含
> operations 清理一併回滾）；已捕捉 errno 1451 轉為友好 toast（「整批撤回已取消，未刪除任何資料」），
> 其他 QueryException 照舊上拋。`/codes` destroy 與 mutation `CodeTableDeleteHandler` 先前已無條件封堵，無需處理。

### 9.2 批次 3 端到端實測（fresh MariaDB 10.3，2026-07-23）

同 §9 流程（乾淨 10.3.39 容器、全量 `php artisan migrate`）：

| 檢查 | 結果 |
|---|---|
| 批次 3 翻轉 | ✅ `flipped 52`（35 張小詞表／類型表／樹表的全部現存 CASCADE 入邊） |
| 全庫 | ✅ `CASCADE 38`／`NO ACTION 150`／`SET NULL 1`（90−52＝38） |
| 剩餘 CASCADE 被引用表 | ✅ 僅 BIOG_MAIN(25)、SOCIAL_INSTITUTION_NAME_CODES(5)、SOCIAL_INSTITUTION_CODES(5)、POSTING_DATA(2)、POSSESSION_DATA(1)——與計畫排除項完全一致 |
| 行為：刪被引用列 | ✅ 刪被自引用 pair FK 引用的 ASSOC_CODES → 1451 擋下；刪被 OFFICE_CODE_TYPE_REL 引用的 OFFICE_CODES → 1451 擋下；資料一列不少 |
| 行為：零引用刪除 | ✅ 引用移除後 DELETE 正常成功 |
| 可逆 | ✅ `migrate:rollback --step=1` 翻回 CASCADE（52 條、全庫回到 CASCADE 90），re-migrate 恢復 RESTRICT |

> 註 1：本批入邊數與 baseline grep 對不上是正常的——若干入邊 FK 已被後續 migration 移除
> （如 OFFICE_CATEGORIES、APPOINTMENT_TYPES 的入邊），資料驅動範式自然只翻現存者。
>
> 註 2：實測中發現既有問題（非本批引入）：`EVENTS_ADDR_ibfk_3`（EVENTS_ADDR→EVENT_CODES）
> 已不存在——`2026_02_12_000001_convert_fields_to_smallint` 為改型別先卸下相關 FK，但重建
> 清單漏了這條，該 FK 自此靜默遺失。EVENTS_DATA 亦無任何入邊 FK（EVENTS_ADDR→EVENTS_DATA
> 複合 FK 同樣不在最終 schema）。是否補回（補回前需先清洗 `c_event_code=0` 等孤兒值）另案處理。
>
> **已補回**（`database/migrations/2026_07_24_000000_restore_events_addr_event_code_fk.php`）：
> 補回前先查 orphan（`c_event_code` 在 `EVENT_CODES` 找不到對應值）， 若有則丟例外中止、
> fail-closed，不靜默清資料；補回時直接落終態 `ON DELETE RESTRICT ON UPDATE CASCADE`，
> 與同表已翻轉的 `EVENTS_DATA_ibfk_3` 一致（本批 `flip()` 只翻當下存在的 FK，事後補回的
> CASCADE 邊不會被追溯翻轉，故直接落終態而非補回原 CASCADE 再等下一批）。
>
> 應用層垫片（同 commit）：office 實體刪除是本批詞表唯一活硬刪路徑
> （`OfficeImportService::delete()`：先刪 OFFICE_CODE_TYPE_REL、有 POSTED_TO_OFFICE_DATA
> 引用護欄回 409；bespoke `OfficeDeleteHandler` 已收斂進通用 `EntityAggregateDeleteHandler`
> ＋`OfficeAggregateDefinition::guardWrite`，見 3e9f012d）。翻轉後若仍有漏網引用（如
> POSTED_TO_ADDR_DATA 殘留列），DELETE 撞 1451、交易回滾；已在 `EntityAggregateDeleteHandler`
> 捕捉 errno 1451 轉友好 409（office／social-institution 等聚合實體通用），其他 QueryException
> 照舊上拋。`/codes` destroy 與 `CodeTableDeleteHandler` 先前已無條件封堵；app 內無其他硬刪
> 呼叫點（含 console commands）。

### 9.3 批次 4 端到端實測（fresh MariaDB 10.3，2026-07-23）

同 §9 流程（乾淨 10.3 容器、app 容器全量 `php artisan migrate`）：

| 檢查 | 結果 |
|---|---|
| 批次 4 翻轉 | ✅ `flipped 10`（SOCIAL_INSTITUTION_CODES 5＋SOCIAL_INSTITUTION_NAME_CODES 5） |
| 兩表入邊 `DELETE_RULE` | ✅ 全 `NO ACTION`（10.3 顯示，§3），CASCADE 歸零 |
| 全庫 | ✅ `CASCADE 28`／`NO ACTION 160`／`SET NULL 1`；剩餘 28 條＝BIOG_MAIN(25)＋POSTING_DATA(2)＋POSSESSION_DATA(1)，與計畫排除項完全一致 |
| 行為：§5.9 紅線場景 | ✅ 刪被 pair FK（`SOCIAL_INSTITUTION_CODES_ibfk_7`）引用的 name-entry → 1451 擋下、機構列一列不少（翻轉前會靜默級聯掉機構列並穿透其全部資料） |
| 行為：刪被引用機構 | ✅ 刪被 SOCIAL_INSTITUTION_ADDR 引用的 CODES 列 → 1451 擋下 |
| 行為：零引用刪除 | ✅ 引用移除後 ADDR→CODES→NAME_CODES 依序刪除正常成功 |
| 可逆 | ✅ `migrate:rollback --step=1` 翻回 CASCADE（10 條），re-migrate 恢復 RESTRICT |

> 應用層前置（無需新垫片）：codes UI 三表已封寫（step 4，`closed_code_tables` 推導）且
> `performDestroy` 對全部碼表無條件封刪；實體刪除走 `SocialInstituteImportService::delete()`
> 顯式級聯（先刪 ADDR 子列再刪 CODES 列，逐列 operations／audit）；`guardWrite` 引用護欄
> （被人物資料引用回 409）＋`EntityAggregateDeleteHandler` 通用 1451 垫片（批次 3 落地）
> 兜住漏網情形。app 內無其他 SOCIAL_INSTITUTION_* 硬刪呼叫點。

### 9.4 末批端到端實測（fresh MariaDB 10.3.39，2026-08-03）

同 §9 流程（乾淨 10.3.39 容器、app 容器全量 `php artisan migrate`）：

| 檢查 | 結果 |
|---|---|
| 全部 migration 在 10.3 執行 | ✅ 全 DONE，無錯 |
| 末批翻轉 | ✅ `flipped 28`，59ms |
| 三表入邊 `DELETE_RULE` | ✅ BIOG_MAIN 25／POSTING_DATA 2／POSSESSION_DATA 1 全 `NO ACTION` |
| 全庫 | ✅ **`CASCADE 0`**／`NO ACTION 188`／`RESTRICT 1`／`SET NULL 1`（共 190 條）——去級聯完成 |
| 唯一非 RESTRICT 邊 | ✅ `fk_merged_person_source`（MERGED_PERSON_DATA→TEXT_CODES，`SET NULL`），既有且正確，全程未觸碰 |
| `ON UPDATE CASCADE` | ✅ 190 條全數保留（本階段不動，§8） |
| 行為 (a)：刪被引用的 `BIOG_MAIN` 列 | ✅ 1451 擋下（`POSSESSION_ADDR_ibfk_2`）；biog/posted/posting 一列不少 |
| 行為 (b)：刪仍被引用的 `POSTING_DATA` 父列 | ✅ 1451 擋下（`POSTED_TO_OFFICE_DATA_ibfk_15`）；**共用同一 `c_posting_id` 的 2 條兄弟列全數保留** |
| 行為 (b2)：刪仍被引用的 `POSSESSION_DATA` 父列 | ✅ 1451 擋下（`POSSESSION_ADDR_ibfk_3`）；子列保留 |
| 行為 (c)：零引用刪除（先子後父） | ✅ ADDR→POSSESSION_DATA→POSTED_TO_OFFICE_DATA→POSTING_DATA→BIOG_MAIN 依序刪除全部成功 |
| 可逆 | ✅ `migrate:rollback --step=1` 翻回 CASCADE（28 條，回到批次 4 終態 `CASCADE 28`／`NO ACTION 160`），re-migrate 恢復 `CASCADE 0` |

> **對照組（翻轉前 CASCADE 的實際危害，於 rollback 後同一庫實測）**：同樣的
> `DELETE FROM POSTING_DATA WHERE c_posting_id=...`，在 CASCADE 下**無報錯地成功**，
> 並把共用該 `c_posting_id` 的 **2 條 `POSTED_TO_OFFICE_DATA` 兄弟列一併靜默刪除**
> （刪除前 2 → 刪除後 0）。prod 實測有 31 個被多列共用的 `c_posting_id`（§11.2），
> 翻成 RESTRICT 後此路徑轉為 fail-closed。
>
> 註（§3 的補充）：10.3 對 `RESTRICT` 多數回報 `NO ACTION`，但由
> `2026_07_24_000000_restore_events_addr_event_code_fk` 建立的 `EVENTS_ADDR_ibfk_3`
> 回報字面 `RESTRICT`。兩者在 InnoDB 語義相同，驗證查詢仍應 `IN ('RESTRICT','NO ACTION')`。

## 10. 對 `ON_DELETE_CASCADE_RISK.md` 的修正（已回補）

前兩項已於 `b05670c2`（分支 `docs-on-delete-cascade-risk`／PR #1143）回補進該文件 §6.1：

1. ~~§6.1「同一 `ALTER` 內 DROP＋ADD」~~ → 已改為「**兩條獨立 ALTER**（先 DROP 再 ADD）」，並註明 10.3 的 1826 限制。
2. ~~附錄 B 驗證 `DELETE_RULE='RESTRICT'`~~ → 已改為 `DELETE_RULE IN ('RESTRICT','NO ACTION')`。
3. **prod 版本已查明：MariaDB `10.11.14`**（2026-08-03 經 prod MCP `SELECT VERSION()` 實測），
   不是先前假設的 10.3。影響評估：
   - **不需重跑或改寫任何已落地的 migration**——全部批次都是在**更嚴格**的 10.3 上驗過的
     （10.3 才有 1826 的同名同語句限制；兩條獨立 ALTER 在 10.11 同樣合法），10.3 通過即 10.11 通過；
   - `AGENTS.md`「MariaDB 10.3」的記述已同步更正；
   - §3 的 `NO ACTION` 顯示差異屬 InnoDB 語義等價問題，驗證條件 `IN ('RESTRICT','NO ACTION')` 兩版皆適用；
   - 唯一遺留待辦：本文件與該風險文件既有的「10.3／10.11 待確認」措辭應一併清掉（風險文件在 PR #1143 上修訂）。

## 11. 末批前置：應用層顯式級聯（已完成，觀察期已結束）

末批（28 條）的前置不是「再寫一支 migration」，而是**把連帶刪除從 DB 搬進應用層並讓它做對**。
本節記錄前置的盤點結論與已落地的修正；**刻意先上線觀察一段時間，再翻末批約束**（§6.1
「app-layer-first」節奏——真實流量會把漏網路徑逼出來，此時仍有 CASCADE 兜底、不會 500）。

### 11.1 盤點：28 條入邊實際上只有 3 條有活刪除路徑

| 被引用表 | 入邊 | 活的硬刪路徑 | 結論 |
|---|---|---|---|
| `BIOG_MAIN` | 25 | **無**——人物「刪除」是軟刪除（`c_name_chn = '<待删除>'`，`BiogMainDeleteHandler`，UPDATE 非 DELETE） | 翻轉成本趨近零 |
| `POSTING_DATA` | 2 | `OfficePostingRepository::officeDeleteById`、`OperationsProposalController::applyDeleteProposal` | 需修（見 11.2） |
| `POSSESSION_DATA` | 1 | `BiogMainRepository::possessionDeleteById`、同上核准路徑 | 需修（見 11.2） |

唯一產生 `DELETE FROM BIOG_MAIN` 的地方是 `MergePreviewController`——它**生成給人工執行的 SQL 腳本**，
應用本身不執行（見 11.3）。

### 11.2 修正一：先子後父、父列僅在無剩餘引用時才刪

| 位置 | 原行為 | 翻轉後會怎樣 | 修正 |
|---|---|---|---|
| `BiogMainRepository::possessionDeleteById` | 先刪父列 `POSSESSION_DATA`、再刪子列 `POSSESSION_ADDR` | 第一句就 1451 | 改為先子後父 |
| `OperationsProposalController::applyDeleteProposal` | 主列 `POSTED_TO_OFFICE_DATA` 尚未刪，就先刪它的**父列** `POSTING_DATA` | 1451 | `POSTING_DATA` 移到主列刪除**之後** |
| `OfficePostingRepository::officeDeleteById`、上述核准路徑 | 無條件刪 `POSTING_DATA` | 1451 | 抽出 `OfficePostingRepository::deletePostingIfUnreferenced()`：確認無 `POSTED_TO_OFFICE_DATA`／`POSTED_TO_ADDR_DATA` 仍引用才刪 |

最後一項不只是為了翻轉——**prod 實測有 31 個 `c_posting_id` 被多列 `POSTED_TO_OFFICE_DATA` 共用**，
現行 CASCADE 下逕刪父列會**靜默連坐刪掉兄弟列**（既有的資料遺失風險，非翻轉引入）。

### 11.3 修正二：合併腳本漏列 7 個指向 BIOG_MAIN 的欄位

`MergePreviewController` 生成的合併腳本把來源人物的引用改指到存活人物後，末尾執行
`DELETE FROM BIOG_MAIN`。但其 `$map` 只涵蓋 18 個欄位，漏掉 7 個：`ASSOC_DATA.c_tertiary_personid`／
`c_assoc_claimer_id`、`ENTRY_DATA.c_assoc_id`／`c_kin_id`、`EVENTS_ADDR.c_personid`、
`POSSESSION_ADDR.c_personid`、`operations.c_personid`。

後果分兩種，**現況更糟**：

- **現行 CASCADE**：那 7 類列在 DELETE 時被**靜默連帶刪除**；而腳本自帶的「確認以下查詢結果皆為 0」
  檢查只掃 `$map` 列出的欄位——**檢查全過、資料照樣消失**（正是 §3.3 的審計盲區）；
- **翻成 RESTRICT**：DELETE 被 1451 擋下，fail-closed。

已把 7 個欄位補進 `$map`（re-point 與驗證 SELECT 皆自動涵蓋），並在該處加註「新增指向 BIOG_MAIN
的外鍵時務必同步補進本表」。

### 11.4 修正三：連帶刪除的每一列都要進 operations

搬進應用層若只記父列，等於把 DB 級聯的盲區原樣搬過來。盤點發現三處缺口：
`POSTED_TO_ADDR_DATA` 只有 audit_log、沒有 operations；`POSTING_DATA`／`POSSESSION_ADDR` 兩者皆無。

新增 `App\Services\ExplicitCascadeLogger`，三條路徑統一使用。紀錄形式沿用專案既有慣例
（AGENTS.md 高風險區備忘）：同一組子列合寫**一筆** operations（`resource_data['rows']` 放全部被刪
列、resource_id 沿用父資源格式）＋**逐列** audit_log before-image，整組共用同一 `operation_id`、
可整組回退。

回歸測試：[`tests/Feature/ExplicitCascadeDeleteTest.php`](../tests/Feature/ExplicitCascadeDeleteTest.php)
（8 tests）——涵蓋父列保留/刪除、子列清理、operations＋audit 的逐列落帳與 operation_id 分組、
合併腳本涵蓋全部 25 條入邊。SQLite 無外鍵、驗不到 1451 本身，故鎖定的是**與外鍵無關但翻轉後
正確性所依賴**的刪除語義。

### 11.5 觀察期要盯什麼

上線後（仍是 CASCADE，故任何漏網都不會 500，只會靜默）重點觀察：

- `/operations` 上 `POSTED_TO_ADDR_DATA`／`POSTING_DATA`／`POSSESSION_ADDR` 的 op_type=4 紀錄是否
  如期出現——**若某條刪除路徑沒留下紀錄，就是還沒被本次修正覆蓋的路徑**，正是翻轉前要補的；
- 任職刪除後 `POSTING_DATA` 是否仍有孤兒（無人引用卻殘留）或誤刪（兄弟列連坐消失）；
- 觀察無虞後，末批 migration 依 §6 範式一次翻完 28 條（`REFERENCED_TABLES = ['BIOG_MAIN',
  'POSTING_DATA', 'POSSESSION_DATA']`），並於 MariaDB 10.3 補 §9 同規格實測。

### 11.6 末批 migration（2026-08-03）

`database/migrations/2026_08_03_000000_restrict_fks_referencing_biog_main_batch.php`——與批次 1–4
同範式（資料驅動、`REFERENCED_TABLES` 鎖範圍、兩條 ALTER、`foreign_key_checks=0` try/finally、
SQLite no-op、`down()` 可逆）。無新增應用層垫片：本批三張被引用表的刪除路徑在 §11.2–11.4 已改為
應用層顯式級聯，`BIOG_MAIN` 則無活的硬刪路徑。

端到端實測已完成（乾淨 MariaDB 10.3.39 容器，§9.4）：`flipped 28`、全庫 `CASCADE 0`、
三項 1451 行為抽測與零引用刪除、rollback／re-migrate 皆如預期。
