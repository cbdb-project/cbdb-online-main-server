# 刪除功能重建：影響預覽 → 確認 → 單一交易執行

> 狀態：設計中（尚未動工）｜ 撰寫：2026-08-03
> 上游：[docs/ON_DELETE_CASCADE_RISK.md](./ON_DELETE_CASCADE_RISK.md) §6.1 Phase 2、
> [docs/CASCADE_TO_RESTRICT_MIGRATION_NOTES.md](./CASCADE_TO_RESTRICT_MIGRATION_NOTES.md)（Phase 1 執行紀錄）
> 前置：**去級聯 Phase 1 已完成**——全庫 `ON DELETE CASCADE` 歸零，任何漏網刪除都會被 DB 以 1451 擋下。

## 1. 為什麼現在做這件事

Phase 1 把 DB 級聯拆掉後，系統落在矩陣 ②（物理刪除 × RESTRICT）：**安全了，但「刪除」這個能力
基本上被封死**——`/codes` 的 destroy、mutation `CodeTableDeleteHandler` 都是無條件封堵
（`RISKY_DELETE_DISABLED`），因為當初沒有引用護欄，放行等於放行災難。

本方案把刪除能力還給操作者，但換成一個**明示、可預覽、可審計、可回退**的形態：

> **刪除前把所有受影響記錄列出來給操作者確認；確認後在單一 transaction 內執行刪除。**

同時，執行層做成可替換的介面：**今天是物理刪除，未來換成軟刪除（deprecate / registry），
預覽與確認流程、API 形狀、使用者可見行為完全不變**（§6）。

## 2. 目標與非目標

**目標**

1. 任何刪除入口在執行前都能算出**完整受影響集合**（遞迴傳遞閉包），並以人看得懂的方式呈現；
2. 確認後的刪除在**單一 transaction** 內完成，逐列落 `operations` + `audit_log`（同一 `operation_id` 分組），可整組回退；
3. 刪除原因（reason）**必填**——落實風險文件 §6.0 決議 3；
4. 影響面過大時**拒絕**，並引導到合併＋重定向這條正規出口；
5. 執行層可替換為軟刪除，**使用者可見功能不變**。

**非目標（本方案不做）**

- 不引入 `record_lifecycle` registry 本身（§6.3，Phase 3 按需）——本方案只把**接口留好**；
- 不動 `ON UPDATE CASCADE`（190 條，另立項）；
- 不改離線發佈（SQLite / Access）匯出規則；
- 不重寫人物合併（`MergePreviewController`），但影響圖服務會與它共用同一份 FK 來源（§4.4）。

## 3. 三條出口（已定案）

預覽頁按「目標詞條的引用數」給出三條路徑：

| 情境 | 出口 | 門檻 |
|---|---|---|
| **零引用** | 直接刪除（帶 reason） | 一般編輯權限即可；風險等同刪一列孤立資料 |
| **有引用，且引用是「錯誤／重複」** | **合併＋重定向**到另一條詞條，引用清零後再刪 | 主線出口，Phase 2 的正規做法 |
| **有引用，且確實要連引用一起刪** | **確認級聯刪除**：預覽列出的全部記錄在同一交易內一併刪除 | 高權限 + 數量門檻 + 二次確認（輸入詞條名稱）+ 必填 reason |

第三條是把原本 DB CASCADE 的能力還給操作者，但差別是決定性的：**事前看得見、事後查得到、可以整組回退**。

## 4. 影響圖：`DeletionImpactService`

### 4.1 資料來源＝schema 本身，不手抄映射

沿用去級聯 migration 已驗證的資料驅動範式：**入邊 FK 一律從 schema 讀**，不維護手抄表
（`MergePreviewController` 的 `$map` 漏了 7 個欄位、導致連坐刪除的教訓見風險文件 §11.3）。

- MySQL/MariaDB：`information_schema.REFERENTIAL_CONSTRAINTS` + `KEY_COLUMN_USAGE`（`CodesController` 已有先例）；
- SQLite（測試環境）：`PRAGMA foreign_key_list(<table>)`——baseline 的 `sanitizeForSqlite()` **保留**了
  `CONSTRAINT ... FOREIGN KEY` 定義，所以測試庫查得到 FK 拓撲，影響圖邏輯**在 CI 可完整單元測試**。

> ⚠ **陷阱（必須寫進實作註解）**：SQLite 測試庫的 FK 定義仍寫著 `ON DELETE CASCADE`（翻轉 migration
> 是 MySQL-only no-op），MariaDB 上則是 `RESTRICT`。因此影響圖**只能用 FK 的存在與欄位**，
> **不得依賴 `DELETE_RULE`** 判斷是否展開，否則兩個環境行為會分歧。

### 4.2 展開規則

- 節點 = `(表, 主鍵值)`；邊 = 指向已選節點的入邊 FK；**遞迴 BFS 展開傳遞閉包**（決策：全展開）。
- 複合主鍵表用 `CompositePrimaryKey::SCHEMAS` 建鍵（AGENTS.md §2）。
- 自引用 FK（`ASSOC_CODES.c_assoc_pair2`、`ADDR_BELONGS_DATA` 等）需 visited 集合防環。
- **上限（超過即拒絕，不截斷）**：預設 `MAX_ROWS = 500`、`MAX_DEPTH = 5`，`config/deletion_impact.php` 可調。
  超限時回 409 並附「已知至少 N 列、在第 K 層停止」＋引導改走合併重定向。
  **不做靜默截斷**——截斷的預覽等於謊報影響面，比不給預覽更危險。
- 回傳結構：按表分組的 `{table, count, sample_rows[], depth, via_fk}`，另附 `total_rows`、`truncated=false`、`digest`（§5）。

### 4.3 呈現

樣本列要人看得懂，不是一堆 id：沿用 `MergePreviewController::summarizeOtherRow()` 與 `getXxxLabel()`
既有的標籤化能力（人物名、地名、書名、官職名），第一版可先只對高頻表做標籤化，其餘顯示原始鍵。

### 4.4 與人物合併的關係

`MergePreviewController` 做的其實是同一件事的另一半（列出引用 → 改指 → 刪除）。本服務落地後，
**合併腳本的 `$map` 應改為由 `DeletionImpactService` 的同一份 FK 來源生成**，從機制上消滅「新增外鍵
忘了補進 `$map`」這類漏列（§11.3 的根因）。列為本方案的收尾項，不在第一版。

## 5. 預覽 → 確認之間的競態（TOCTOU）

預覽是一次讀，執行是另一次寫；中間資料可能變動（別人新增了引用）。做法：

1. 預覽回傳 `preview_token`：對「規範化後的影響集合」取 hash（表 → 主鍵排序後序列化），帶 TTL（預設 15 分鐘）；
2. 執行時**在交易內重算影響圖**，與 token 比對：
   - 一致 → 執行；
   - 不一致 → 整筆 409「資料已變動，請重新預覽」，**不刪任何東西**（fail-closed）。
3. token 只是防呆，不是鎖——真正的保證是「交易內重算」這一步。

## 6. 執行層：`DeletionExecutor` 介面（未來換軟刪除的落點）

```php
interface DeletionExecutor {
    /** @param ImpactGraph $graph 交易內已重算並驗證過的影響集合 */
    public function execute(ImpactGraph $graph, string $reason, int $actorId): DeletionResult;
}
```

- **`PhysicalDeletionExecutor`（v1）**：在單一 `DB::transaction()` 內
  1. 由**葉往根**（拓撲逆序）刪除——RESTRICT 下順序錯就會撞 1451，順序正確則全程不觸發；
  2. 每刪一組落 `ExplicitCascadeLogger::logDeletedRows()`（一組一筆 operations、逐列 audit before-image、共用 `operation_id`）；
  3. 任何一步失敗 → 整筆回滾，**一列不刪**；1451 仍捕捉為友好 409（表示影響圖漏算，屬 bug，需告警）。
- **`SoftDeletionExecutor`（未來）**：同樣的 `ImpactGraph` 進來，改為登記 `record_lifecycle`（§6.3）而不 DELETE。
  **預覽、確認、API、UI 全部不變**——這正是使用者可見功能不變的機制保證。
- 選用哪個 executor 由 config 決定，可按資源逐步切換。

## 7. 回退

`ExplicitCascadeLogger` 已把整組刪除掛在同一個 `operation_id` 下。回退需要擴充 `OperationsController::restoreDelete()`：

- 現行只處理**單列** `resource_data`；連帶刪除寫的是 `resource_data['rows']`（多列）——需支援多列還原；
- 還原順序必須**由根往葉**（與刪除相反），否則插子列時父列不存在，RESTRICT 下會撞 1452；
- 同一 `operation_id` 的多筆 operations 要能一次整組還原（目前是逐筆）。

這一項是本方案**唯一動到既有回退機制**的地方，要獨立 PR ＋回歸測試。

## 8. API 形狀

| 端點 | 用途 |
|---|---|
| `POST /api/v2/delete-preview`（新增） | 輸入 `{resource, pk}`，回影響圖 + `preview_token` |
| `POST /api/v2/mutate`（既有） | `operation=delete` 增加 `meta.preview_token`、`meta.reason`（必填）、`meta.cascade`（預設 false） |

- `cascade=false` 且有引用 → 409 + 影響圖摘要（等於「僅零引用可刪」語義）；
- `cascade=true` → 需高權限，且影響面不得超限；
- 授權沿用既有 `authorizeDirect()`；級聯刪除額外要求 admin（實作時確認專案現有角色定義）。

## 9. 前端（React/Inertia，`resources/js/inertia/Pages/Codes/`）

- 解除 `RISKY_DELETE_DISABLED`，刪除按鈕改為開啟「刪除影響預覽」對話框；
- 對話框：總計數 + 按表分組（可展開看樣本）+ 超限紅色提示 + reason 輸入框；
- 有引用時預設引導「合併到另一條詞條」，級聯刪除放在次要位置並要求輸入詞條名稱二次確認；
- i18n：zh-TW / en 兩份同步（AGENTS.md §6）。

## 10. 首發範圍與交付切分

**首發＝Codes 詞表刪除**（`CodesController::performDestroy` + `CodeTableDeleteHandler`，目前全面封堵中）。
office / social-institution（`EntityAggregateDeleteHandler`）、子資源連帶刪除（`ExplicitCascadeLogger` 三條路徑）
在服務穩定後接入同一框架。

| PR | 內容 | 風險 |
|---|---|---|
| **P1** | `DeletionImpactService` + `/api/v2/delete-preview` + 單元測試（SQLite PRAGMA 路徑）＋MariaDB 容器回歸 | 無（純唯讀） |
| **P2** | `DeletionExecutor` 介面 + `PhysicalDeletionExecutor` + 交易/逐列落帳 + 1451 告警 | 中；未接前端，僅 API |
| **P3** | `restoreDelete` 多列/整組還原擴充 | 中；動既有回退機制，需回歸 |
| **P4** | 前端預覽對話框 + 解封 codes 刪除（feature flag，預設關） | 中；flag 可即時關 |
| **P5** | 合併＋重定向工具（風險文件 §6.1 Phase 2 主線）＋ `MergePreviewController` 改用同一 FK 來源 | 中 |
| **P6**（未來） | `SoftDeletionExecutor` + registry（§6.3），executor 換實作 | 低（介面已就位） |

## 11. 測試計畫

- **SQLite（CI 常規）**：影響圖展開（含自引用防環、複合主鍵、上限拒絕）、token 比對、執行順序、
  operations/audit 逐列落帳與分組、整組還原；
- **MariaDB 容器（去級聯回歸同一套設施）**：真 FK 下級聯刪除**不應觸發 1451**（觸發即影響圖漏算）、
  刪除後無殘留、超限拒絕、交易回滾後一列不少。

## 12. 紅線

1. **不做靜默截斷**——影響面算不完就拒絕，不給不完整的清單讓人按確認；
2. **不繞過 operations/audit**——任何一列消失都必須有紀錄（風險文件 §3.3 的盲區不得重現）；
3. **不依賴 `DELETE_RULE`** 判斷展開（§4.1 陷阱）；
4. **不放寬 `/codes` 的封堵**，直到 P1–P4 全數到位且 flag 明確開啟。
