# 人物資訊 Proposal API 實作計畫

本文件說明如何在現有「人物資訊提案（proposal）」機制之上，新增可供外部客戶端使用的 API，讓使用者可透過 API 提交人物資料的新增/修改提案（而非直接寫入資料表）。

- 文檔性質：實作計畫（Plan）
- 適用範圍：`/basicinformation/*` 模組（BIOG_MAIN 與各子資源）
- 目標版本：第一版 API（v1）

## 1. 現況摘要（已存在功能）

目前人物資訊 proposal 流程已在 Web 表單完成，核心能力如下：

1. 提案提交（Web）
- `BasicInformationProposalController`
  - `proposalStore()`：新增提案
  - `proposalUpdate()` / `proposalUpdateWithPk()`：修改提案
- 提案內容寫入 `operations` 表：
  - `op_type = 8`：新增提案
  - `op_type = 9`：修改提案
- `resource_data` 會包含：
  - `__proposal_meta`
  - `__review_status = pending`
  - `__key_columns`

2. 提案審核（Web）
- `OperationsProposalController@approve` / `@reject`
- 管理員核准後才套用到實際資料表
- 同步寫入正式操作紀錄（`op_type = 1/3`）與 `audit_log`

3. 權限規則（現況）
- 提案提交者：需登入且 `is_active == 1`
- 審核者：需 `canRestoreOperations()`（活躍管理員）

## 2. 本計畫目標

提供一組 API，使外部應用（腳本、前端整合、批次工具）可：

1. 提交人物資訊新增提案
2. 提交人物資訊修改提案
3. 查詢提案建立結果（至少回傳 `operation_id` 與狀態）

本期不包含（可列入後續）：

1. API 審核（approve/reject）
2. API 撤回/修改既有提案
3. 刪除提案（目前 Web 也未開放人物資訊刪除提案）

## 3. 核心設計原則

1. 不重寫 proposal 核心邏輯
- 仍以 `BasicInformationProposalController` / `OperationRepository` / `OperationsProposalController` 為核心。

2. API 與 Web 行為一致
- 同一資源的欄位預處理（正規化）必須一致，避免 Web 可提案但 API 失敗，或反之。

3. 優先抽象「正規化層」，不要在 API Controller 複製各資源 Controller 的前置處理
- 目前 `BasicInformation*Controller` 內存在資源專屬預處理（例如 `ENTRY_DATA` 的 `c_inst_code` 分割、`BIOG_MAIN` 姓名合成）。
- 若 API 直接呼叫 `BasicInformationProposalController` 而未經這些預處理，行為會不一致。

4. 使用 Sanctum Bearer Token（與現有 API 認證架構一致）
- 參考 `docs/API_AUTHENTICATION.md`

## 4. 現有程式碼重點（與 API 直接相關）

1. `app/Http/Controllers/BasicInformationProposalController.php`
- 資源配置（`$resourceConfigs`）已定義表名、主鍵欄位、顯示名稱
- 主鍵檢查、重複資料檢查、待審核衝突檢查
- 寫入 `operations` 提案紀錄

2. `app/Http/Controllers/OperationsProposalController.php`
- 核准/退回提案與實際套用邏輯
- 說明 API 提交端不需重複處理落表邏輯

3. 各 `BasicInformation*Controller`
- 在 `action=proposal` 分支之前做資源專屬預處理（非常重要）
- 例：
  - `BasicInformationController@update`（BIOG_MAIN）：姓名欄位合成、旗標型別轉換、timestamp
  - `BasicInformationEntriesController@store/update`：`-999 -> 0`、`c_inst_code` 分割
  - `BasicInformationAssocController@store`：`c_inst_code` 分割、`c_assoc_first_year` 缺省值處理

4. 測試證據（現況）
- `tests/Feature/BasicInformationProposalTest.php`
- `tests/Feature/BiogMainProposalTest.php`
- `tests/Feature/ProposalNormalizationTest.php`

`ProposalNormalizationTest` 已證明：提案前正規化對 API 成功與否有直接影響，不能略過。

## 5. API 範圍（v1 建議）

### 5.1 路由設計（建議）

新增於 `routes/api.php`（使用 Sanctum）：

```php
Route::middleware(['auth:sanctum'])->prefix('v2/biog-proposals')->group(function () {
    Route::post('{personid}/{resource}', 'Api\\BiogProposalController@store');   // 新增提案
    Route::post('{personid}/{resource}/update', 'Api\\BiogProposalController@update'); // 修改提案（以 original_pk 傳遞）
});
```

說明：

1. 使用 `v2` 避免和既有歷史 API 混淆（`routes/api.php` 內已有大量舊 API）。
2. `update` 採 body 傳 `original_pk`，不走路徑複合主鍵字串，避免編碼歧義。

### 5.2 API 請求格式（建議）

#### A. 新增提案

`POST /api/v2/biog-proposals/{personid}/{resource}`

Request JSON：

```json
{
  "payload": {
    "c_alt_name_chn": "測試別名",
    "c_alt_name_type_code": 1,
    "c_sequence": 1
  },
  "proposal_comment": "新增別名提案"
}
```

#### B. 修改提案

`POST /api/v2/biog-proposals/{personid}/{resource}/update`

Request JSON：

```json
{
  "original_pk": {
    "c_personid": 1,
    "c_alt_name_chn": "原始別名",
    "c_alt_name_type_code": 1
  },
  "payload": {
    "c_alt_name_chn": "原始別名",
    "c_alt_name_type_code": 1,
    "c_alt_name": "Updated Name"
  },
  "proposal_comment": "修改別名資訊"
}
```

說明：

1. `original_pk` 用於定位原始資料列（等同 `proposalUpdateWithPk()` 的 `array $originalPk`）。
2. `payload` 為提案後資料內容。
3. `proposal_comment` 對應現有 `__proposal_comment`。

### 5.3 API 回應格式（建議）

成功建立提案：

```json
{
  "message": "提案已提交，等待管理員審核",
  "proposal": {
    "operation_id": 12345,
    "op_type": 8,
    "resource": "ALTNAME_DATA",
    "resource_id": "c_personid=1&c_alt_name_chn=%E6%B8%AC%E8%A9%A6%E5%88%A5%E5%90%8D&c_alt_name_type_code=1",
    "review_status": "pending",
    "submitted_at": "2026-02-22 14:00:00"
  }
}
```

失敗（例：主鍵缺失）：

```json
{
  "message": "提案失敗：請確認主鍵欄位已填寫完整。",
  "errors": {
    "missing_key_columns": ["c_alt_name_type_code"]
  }
}
```

建議狀態碼：

1. `201 Created`：提案建立成功
2. `400 Bad Request`：請求格式錯誤 / 缺欄位
3. `401 Unauthorized`：未認證或 token 無效
4. `403 Forbidden`：帳號非活躍，無提案權限
5. `404 Not Found`：未知資源類型 / 原始資料列不存在
6. `409 Conflict`：重複資料或待審核提案衝突
7. `422 Unprocessable Entity`：無實質變更等業務校驗失敗

## 6. 實作架構建議（重點）

## 6.1 新增 API Controller（薄層）

新增 `app/Http/Controllers/Api/BiogProposalController.php`

職責：

1. 驗證 API 認證（`auth:sanctum`）
2. 驗證請求格式（`payload`、`proposal_comment`、`original_pk`）
3. 呼叫「資源正規化服務」進行預處理
4. 將資料轉成 `Request` 格式後，委派給 `BasicInformationProposalController`
5. 把 Web redirect/flash 結果轉為 JSON 回應

不建議在此 Controller 直接複製 `proposalStore` / `proposalUpdate` 邏輯。

## 6.2 抽出資源正規化服務（關鍵）

新增建議：

- `app/Services/BiogProposalPayloadNormalizer.php`

用途：

1. 統一處理各資源在提案前的資料預處理
2. 被 Web Controller 與 API Controller 共用

介面建議：

```php
public function normalizeForProposal(string $resourceType, int $personId, array $payload, string $mode, ?array $context = null): array
```

- `mode`：`create` / `update`
- `context`：可放原始 PK、原始 row（如某些 update 正規化需要）

第一階段至少覆蓋：

1. `biogmain`
2. `altnames`
3. `assoc`
4. `entries`
5. `texts`

之後逐步覆蓋所有 `resourceConfigs` 支援資源。

### 為何必須先做這一步

目前 Web 提案入口散落在各 `BasicInformation*Controller` 的 `action=proposal` 分支前；API 若跳過這些邏輯，會出現：

1. PK 驗證失敗（實際上只是缺正規化）
2. `resource_id` 與 Web 不一致
3. 審核時才失敗（例如欄位型別或分割欄位不一致）
4. 測試案例（`ProposalNormalizationTest`）在 API 路徑下重現失敗

## 6.3 讓 `BasicInformationProposalController` 支援 JSON 模式（建議）

現況該 Controller 回傳 `redirect + flash`。API 若直接重用，建議新增一層服務，而不是硬解析 session flash。

兩種可行方案：

1. 建議方案（較乾淨）
- 抽出 `BiogProposalService`（或 `BasicInformationProposalService`）
- `BasicInformationProposalController` 與 `Api\BiogProposalController` 都呼叫 service
- service 回傳結構化結果（成功/失敗、operation、錯誤碼）

2. 過渡方案（較快）
- API Controller 建立子請求呼叫 `BasicInformationProposalController`
- 解析 redirect/flash 成 JSON
- 缺點：耦合高、測試脆弱

建議採方案 1。

## 7. 與現有權限/認證的整合

1. 認證
- 使用 `auth:sanctum`
- 透過 `Authorization: Bearer {token}` 存取

2. 提案權限
- 與 Web 一致：沿用 `ensureCanPropose()`（登入且 `is_active == 1`）
- API 需將 `abort(403, ...)` 轉為 JSON 錯誤格式

3. Token 能力（可選強化）
- 可新增 Sanctum abilities（例如 `biog:proposal:write`）
- 第一版可先使用一般 token，第二版再加 middleware（如 `EnsureMcpAbility` 類似模式）

## 8. 實作步驟（分階段）

### Phase 1：基礎 API 骨架（可用）

1. 新增 `Api\BiogProposalController`
2. 新增 API 路由（`/api/v2/biog-proposals/*`）
3. 先支援 `altnames`、`biogmain` 兩種資源
4. 直接建立 proposal（op_type 8/9）並回傳 JSON
5. 撰寫 Feature tests（API token + JSON）

交付標準：

1. 可透過 Bearer token 提交 ALTNAME_DATA 新增/修改提案
2. 可透過 Bearer token 提交 BIOG_MAIN 修改提案
3. `operations` 寫入內容與 Web 路徑一致（含 `__proposal_meta` / `__key_columns`）

### Phase 2：抽象正規化層並覆蓋高風險資源

1. 新增 `BiogProposalPayloadNormalizer`
2. 將 `entries`、`assoc`、`texts` 等正規化邏輯搬入服務
3. Web Controller `action=proposal` 分支改用該服務（避免雙軌邏輯）
4. API Controller 同步使用該服務

交付標準：

1. `ProposalNormalizationTest` 類似場景可用 API 測試重現且通過
2. Web/API 提案 payload 一致

### Phase 3：擴充所有 `resourceConfigs` 支援資源

1. 逐一補齊各資源正規化規則
2. 補齊 API 驗證規則與錯誤訊息
3. 文件更新（`API.md` / `docs/APPROVAL_FLOWS.md`）

## 9. 測試計畫（必做）

新增建議測試檔：

- `tests/Feature/Api/BiogProposalApiTest.php`

至少涵蓋：

1. 認證與權限
- 未帶 token -> `401`
- 非活躍用戶 -> `403`

2. 新增提案
- `altnames` 成功建立 proposal（`op_type = 8`）
- 重複資料 -> `409`
- pending 衝突 -> `409`

3. 修改提案
- `altnames` 成功建立 proposal（`op_type = 9`）
- `original_pk` 對應不到資料 -> `404`
- 無實質變更 -> `422`

4. 正規化一致性（高優先）
- `entries` 的 `c_inst_code = "123-4"` 分割為 `c_inst_code=123`, `c_inst_name_code=4`
- `entries` 的 `-999 -> 0`
- `assoc` 的 `c_text_title=''` 與 `c_assoc_first_year` 缺省值
- `biogmain` 姓名合成與旗標型別轉換

5. 回應格式
- 成功回傳 `operation_id`, `review_status`
- 錯誤回傳一致 JSON 結構

## 10. 風險與注意事項

1. 風險：資源正規化邏輯分散
- 這是本計畫最大風險。
- 若未先抽出服務，API 很容易與 Web 行為分岔。

2. 風險：複合主鍵編碼格式不一致
- Web 現況已逐步採 query-string 型 `resource_id`
- API 必須統一使用 `CompositePrimaryKey::buildStoredResourceId()` 的結果

3. 風險：例外處理仍偏向 Web（flash/redirect）
- API 端需統一轉換為 JSON 錯誤碼與訊息

4. 風險：部分資源欄位依賴 Repository / ToolsRepository 時間戳邏輯
- 需確認在 API 路徑下同樣可取得使用者資訊（`Auth::user()`）

## 11. 建議後續文檔同步

完成實作後，請同步更新：

1. `docs/APPROVAL_FLOWS.md`
- 新增「API 提案提交」段落

2. `docs/API_AUTHENTICATION.md`
- 新增 `biog-proposals` 使用範例

3. `docs/BIOGMAIN_APPROVAL_FLOWS_PLAN.md`
- 在「已落地能力」補充 API 狀態

4. `CHANGELOG.md`
- 若屬對外功能，補充 API 端點與使用方式

## 12. 建議的第一個實作切入點（務實版本）

若要用最小風險快速落地，建議順序如下：

1. 先做 `altnames` API（新增/修改）
- 規則相對簡單、現有測試完整

2. 再做 `biogmain` API（修改）
- 可驗證姓名合成與 timestamp 的共用抽象是否合理

3. 最後擴充 `entries` / `assoc`
- 用來驗證「分散正規化邏輯抽出」是否成功

這樣可以在最短時間內建立 API 骨架，同時避免一次擴張到所有資源導致回歸風險過高。

