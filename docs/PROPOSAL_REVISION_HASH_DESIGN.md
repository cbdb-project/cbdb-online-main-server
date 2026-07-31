# Proposal Revision Hash 設計

> **狀態：第一階段已實作**（`BIOG_MAIN`、`/api/v2/mutate`、`mode=proposal`、
> `operation=update`，提交端與審核端衝突檢查皆已完成，見下方「第一階段落地
> 範圍」與程式碼：[ProposalRevisionService](../app/Services/ProposalRevisionService.php)、
> [BiogMainMutationHandler](../app/Services/Mutations/BiogMainMutationHandler.php)、
> [OperationsProposalController](../app/Http/Controllers/OperationsProposalController.php)）。
> 其他章節描述的擴展（其他資源接入、create proposal、Web 表單）仍是未實作的未來方向。
>
> **前提已部分改變（2026-07）**：核准端對多數人物子資源已改為「重放 v2 handler」
> （見 [PERSON_PROPOSAL_PATHS.md](./PERSON_PROPOSAL_PATHS.md)），核准時是把
> **提案當時的差異（delta）套用到當下資料列**，而非蓋上提案當時的整列快照。
> 這已消除本文動機中「覆蓋掉提案後其他人對**其他欄位**的修改」那一半風險。
>
> **仍然存在的風險**：同一欄位被並行修改時，核准仍會以提案值覆寫較新的值——
> 這正是本設計要解決的部分，仍有價值。尚未收斂到重放路徑的資源
> （委派檔 5 資源、`BIOG_MAIN`）則兩種風險都還在；`BIOG_MAIN` 的這部分風險
> 已由本設計第一階段解決，其餘 5 個委派檔資源仍未處理。

## 目標

為 proposal 流程增加一套可擴展的「規範化形式 + hash」機制，用於衝突檢測，避免 API 局部 proposal 在提交後或審核時覆蓋掉後續已發生的資料變更。

本設計首先服務於 `BIOG_MAIN` 的 `/api/v2/mutate` proposal update，但設計本身必須能擴展到其他資源。

## 背景

目前 Web 端的 proposal 主要是整頁表單提交流程，語義接近：

- 以當前頁面完整內容作為候選版本
- 後續審核時按候選版本套用

但 `/api/v2/mutate` 已經在 `BIOG_SOURCE_DATA` 上建立了另一種交互模式：

- 客戶端只提交局部 `changes`
- 後端補齊為 proposal payload
- proposal 本質上變成「延遲執行的更新」

當這種模式擴展到 `BIOG_MAIN` 這類大表時，若缺少衝突檢查，會有以下風險：

1. 使用者基於舊版本提交 proposal
2. 其後正式資料已被其他人修改
3. proposal 仍被核准並覆蓋較新的正式資料

因此需要類似 git / perforce 的 optimistic concurrency control。

## 設計原則

1. 不修改業務表結構
- 不在 `BIOG_MAIN`、`BIOG_SOURCE_DATA` 等主表上新增 `version` 欄位。

2. revision 僅存在 proposal 中
- proposal 的基線版本資訊存放在 `operations.resource_data.__proposal_meta`。

3. 規範化規則需可擴展
- 不把 `BIOG_MAIN` 邏輯寫死在 controller。
- 每個資源都可定義自己的 canonicalization 規則。

4. 提交與審核必須使用同一套 hash 規則
- 避免提交與審核使用不同正規化方式而造成誤判。

5. 第一階段只先收斂 update proposal
- create proposal 不在第一階段解決範圍內。

## 核心概念

### 1. Canonical Form

Canonical form 是某資源資料列在比較用途下的「規範化表示」。

用途：

- 消除型別差異（如 `1` 與 `"1"`）
- 消除無意義空白差異
- 依固定欄位順序輸出
- 決定哪些欄位參與衝突判斷

### 2. Revision Hash

對 canonical form 進行穩定序列化後再做 hash，例如：

```text
sha256:<hex>
```

這個 hash 代表某資源在某一時刻的「基線版本」。

## 建議新增元件

建議新增服務：

`app/Services/ProposalRevisionService.php`

建議 API：

```php
public function canonicalize(string $resource, array $row): array;

public function hash(string $resource, array $row): string;

public function matches(string $resource, array $left, array $right): bool;
```

## 規範化策略

### 註冊式設計

服務內部應使用可擴展的資源註冊表，而不是散落的 `if ($resource === 'BIOG_MAIN')`。

概念上可長這樣：

```php
protected array $resourceNormalizers = [
    'BIOG_MAIN' => [...],
    'BIOG_SOURCE_DATA' => [...],
];
```

每個資源的規則至少包含：

- 參與 revision 的欄位列表
- 欄位固定順序
- 每個欄位的正規化方式
- 是否忽略 audit 欄位

### 預設正規化規則

若資源未定義特殊規則，可使用 fallback：

- `null` => `''`
- 字串 => `trim((string)$value)`
- 布林 => `0/1`
- 整數欄位 => `(int)$value`
- 陣列 => 遞迴正規化後依固定鍵序輸出

### `BIOG_MAIN` 第一版建議

第一版建議：

- 納入大多數業務欄位
- 排除 audit 欄位：
  - `c_created_by`
  - `c_created_date`
  - `c_modified_by`
  - `c_modified_date`

原因：

- 這些欄位會因任何保存而變動
- 若將其納入 revision，衝突會過於敏感
- 第一階段應優先檢測「內容衝突」，不是「任何保存痕跡變動」

### `BIOG_SOURCE_DATA` 第一版建議

可只針對：

- `c_notes`
- `c_main_source`
- `c_self_bio`

做 revision 計算，因為這正是其可變欄位集合。

## 資料存放位置

不修改資料表 schema，revision 只存 proposal 自身：

```json
{
  "__proposal_meta": {
    "action": "update",
    "resource_type": "biogmain",
    "table": "BIOG_MAIN",
    "base_revision": "sha256:3f7c...",
    "revision_algo": "canonical-v1"
  }
}
```

其中：

- `base_revision`
  - proposal 建立時的基線版本
- `revision_algo`
  - 方便未來升級 canonicalization 規則時做兼容處理

`resource_original` 仍保留提案提交時的原始快照，用於：

- 顯示差異
- 衝突排查
- 審核失敗時人工比較

## 提交 proposal 時的流程

以 `BIOG_MAIN` 的 `/api/v2/mutate` proposal update 為例：

1. 客戶端先取得當前資料列
2. 客戶端取得該資料列對應的 `base_revision`
3. 客戶端提交：
   - `target.pk`
   - `changes`
   - `base_revision`
4. 後端重新讀取目前資料列
5. 後端計算 `current_revision`
6. 若 `current_revision !== base_revision`
   - 返回 `409 Conflict`
   - 不建立 proposal
7. 若一致
   - 建立 proposal operation
   - 將 `base_revision` 寫入 `__proposal_meta`

建議錯誤格式：

```json
{
  "ok": false,
  "message": "資料已被更新，請重新載入後再提交提案",
  "errors": {
    "base_revision": ["stale"]
  }
}
```

## 審核 proposal 時的流程

審核時也要再次檢查衝突，不能只在提交時檢查一次。

理由：

1. proposal 建立時版本一致
2. proposal 尚未審核期間，正式資料可能又被其他人更新
3. 若審核時不重檢，仍會覆蓋掉較新的正式資料

審核流程建議：

1. 讀取 proposal 的 `__proposal_meta.base_revision`
2. 讀取 proposal 的 `resource_original`
3. 讀取目前資料表中的最新資料列
4. 計算當前 `current_revision`
5. 若 `current_revision !== base_revision`
   - 拒絕核准
   - proposal 狀態維持原樣
   - 提示需要重新整理並重提
6. 若一致
   - 套用 proposal
   - 更新 proposal 為 approved

建議審核失敗文案：

- `審核失敗：資料自提案提交後已被更新，請要求提案者重新整理並重提。`

## 為什麼不需要改主表 schema

本方案不依賴主表中的 `version` 欄位。

原因：

1. revision 可由目前資料列即時計算
2. proposal 只需保存提交時的 `base_revision`
3. 審核時重新計算當前 revision 即可比對

因此：

- 不需要 migration
- 不需要在 `BIOG_MAIN` 加 `lock_version`
- 不需要在各表新增 `row_hash`

## 與 `resource_original` 的關係

`resource_original` 不能完全替代 `base_revision`，但可作為輔助。

差異如下：

- `resource_original`
  - 保存提交當時的完整原始快照
  - 適合顯示與追查
- `base_revision`
  - 保存一個可快速比較的版本 token
  - 適合 API 提交與審核時做衝突判斷

實作上可以考慮：

- `base_revision = hash(canonicalize(resource_original))`

但仍建議把 `base_revision` 顯式寫進 `__proposal_meta`，避免每次都依賴重新從 `resource_original` 計算與推測。

## 可擴展性要求

未來其他資源接入時，應只需：

1. 在 `ProposalRevisionService` 註冊該資源的 canonicalization 規則
2. 在 proposal 提交入口加上 `base_revision` 驗證
3. 在 proposal 審核流程加上 revision 檢查

不應再為每個資源額外重寫一套 hash 邏輯。

## 第一階段建議落地範圍

第一階段只做：

- `BIOG_MAIN`
- `/api/v2/mutate`
- `mode=proposal`
- `operation=update`
- proposal 提交衝突檢查
- proposal 審核衝突檢查

先不做：

- Web 表單 proposal 強制帶 revision
- create proposal revision 檢查
- 其他資源全面接入

## 後續可選擴展

1. 對 `BIOG_SOURCE_DATA` 補上同一套 conflict 機制
2. 提供 API 讀取當前 `base_revision`
3. proposal 衝突時返回 canonical diff 摘要
4. 將 canonicalization 規則抽到 `config/` 檔案

## 決策建議

若要讓 `/api/v2/mutate` 的 proposal 真正支援局部變更，應採用本方案，而不是沿用 Web 整頁 proposal 的隱含語義。

建議執行順序：

1. 實作 `ProposalRevisionService`
2. 僅在 `BIOG_MAIN proposal update` 強制要求 `base_revision`
3. 提交與審核都檢查 revision
4. 再決定是否推廣到其他資源
