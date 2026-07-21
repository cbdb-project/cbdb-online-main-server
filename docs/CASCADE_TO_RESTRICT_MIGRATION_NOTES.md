# CASCADE → RESTRICT：翻轉 migration 的機制與分批 rollout（MariaDB 10.3）

> 狀態：實作中（分批進行）｜ 批次 1 已落地並實測
> 上位文件：[docs/ON_DELETE_CASCADE_RISK.md](./ON_DELETE_CASCADE_RISK.md)（§6「RESTRICT 先行、按被引用表分批」）
> 目的：把 §6.1 的翻轉方案落成可執行、可分批、可回滾的 migration，並記錄 §6.1 範例 SQL 在
> **prod 版本 MariaDB 10.3** 上跑不起來的實測修正（該文件是在 10.11 驗的）。

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

- §6.1 的範例在 **MariaDB 10.11** 驗（見該文件附錄 C），但 **prod 是 10.3**（AGENTS.md）。
  10.3 不允許在單一 `ALTER` 內 DROP 又 ADD 同名 FK。
- ⚠ **待確認**：prod 究竟 10.3 還是 10.11（AGENTS.md 說 10.3、cascade 文件說在 10.11 驗）。
  無論如何，**兩條 ALTER 在兩版皆安全，一律採用**。

## 3. 10.3 的坑：RESTRICT 在 information_schema 顯示為 NO ACTION

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
| 3… | 其餘小詞表（KINSHIP_CODES(6)、ASSOC_CODES(4)、OFFICE_CODES(3)、EVENT_CODES(3)…） | 待做 | — |
| n | SOCIAL_INSTITUTION_CODES(5) | 待做——「一機構多名」安全前提（[SOCIAL_INSTITUTION_ENTITY_MODEL §5.9](./SOCIAL_INSTITUTION_ENTITY_MODEL.md)）；**前置**：先封 codes UI 對該表的刪除（社會機構 step 4） | — |
| 末 | BIOG_MAIN(25)＋operations→BIOG_MAIN | 待做——需配套顯式級聯刪除服務 | — |

**節奏（§6.1「app-layer-first」）**：翻一批 → 觀察 1–2 週盯 1451（1451 會把漏網的 cascade
依賴刪除路徑逼出，fail-closed 零損失）→ 修應用層 → 下一批。`ON UPDATE CASCADE`（187 條）本階段一律保留。

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

## 10. 對 `ON_DELETE_CASCADE_RISK.md` 的修正建議

1. §6.1「同一 `ALTER` 內 DROP＋ADD」→ 改為「**兩條獨立 ALTER**（先 DROP 再 ADD）」，註明 10.3 的 1826 限制。
2. 附錄 B 驗證：`DELETE_RULE='RESTRICT'` → `DELETE_RULE IN ('RESTRICT','NO ACTION')`。
3. 標註 prod 版本待確認（10.3 vs 10.11）；兩條 ALTER 為跨版本安全選擇。
