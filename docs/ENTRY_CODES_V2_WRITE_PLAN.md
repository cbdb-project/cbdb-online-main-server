# 入仕途徑代碼表（ENTRY_CODES）v2 寫入計畫

> 狀態：**已實作（§D 為權威）**
> 分支：`feature/entry-codes-v2-write`
> 關聯：[代碼表受審計寫入 API 建設計畫](./CODE_TABLE_MUTATION_API_PLAN.md)、API.md §13.1／§13.2

## 0. 背景

下游錄入代理要新增三個入仕途徑（經明行修、稅戶人才、懷才抱德），但本變更之前本庫沒有任何
token API 能新建 `ENTRY_CODES` 記錄：

- `/api/v2/create` 只認 `config/code_table_writes.php` 登錄的七張表（本變更後為八張），送 `entry-codes` 回 501。
- `/api/v2/mutate`（update）的 `config/code_table_mutations.php` 也沒有它。
- 唯一寫入口是 `/codes` UI 背後的 `POST app/codes/{table_name}`（session＋CSRF，非 token API）。

下游的請求是「在 `code_table_writes.php` 登記 ENTRY_CODES（`c_entry_desc`、`c_entry_desc_chn`，
自動分配 ID）」，之後走 proposal → 審核 → submit。

## §D. 決策定案

- **D-1 範圍：create ＋ update 兩端都開，delete 維持停用（403）。** 只開 create 會造成
  「新增時填得進去、之後改不了」——`CodeTableWriteConfigDriftTest` 也要求兩份 config 對同一張表
  的白名單一致。刪除與其他代碼表一致停用：本表被 `ENTRY_DATA.c_entry_code` 與
  `ENTRY_CODE_TYPE_REL.c_entry_code` 以外鍵引用。
- **D-2 主鍵 `c_entry_code`（smallint）可自動配發（`max+1`）也可由呼叫端指定。**
  單一數值主鍵，符合 `auto_assign_id` 前提；值域由既有 schema 推導的守衛把關（smallint ≤ 32767）。
- **D-3 白名單：`c_entry_desc`、`c_entry_desc_chn`**（即本表全部非主鍵欄）。兩欄皆
  `NOT NULL DEFAULT ''`（`2025_11_14_000000_michael_restructure_plan_schema_updates`），
  登記進 `not_null_fields`：送 `null` 回 422 欄位級錯誤。**以 JSON Content-Type 送 `''` 也是 422**——JSON 請求會
  先經全域 middleware `ConvertEmptyStringsToNull` 把 `''` 轉成 `null`（`CodeTableFieldValidator`
  雖然允許文字型 NOT NULL 欄收 `''`，但經 HTTP 永遠收不到）。只有「整個不送」才吃預設值 `''`；
  經 API 無法把名稱清空。API.md 既有「文字型 NOT NULL 欄允許送空字串」一句與實際行為不符，
  於環節 2 一併更正。
- **D-4 拼音 Tier：兩個 tier 都空。** `c_entry_desc` 是英文描述（不是漢語拼音），本表從未列入
  §D-6 Tier 登錄表；列進 tier2 會讓 `/codes` 編輯器對它彈 ü 轉換視窗，改變既有 UI 行為。
- **D-5 不新增「必填」機制。** 既有可新增的代碼表（ADMIN_CAT_CODES 等）都沒有 create 必填欄；
  若只在 create 擋，會違反 AGENTS §4「必填欄位 create／update 一致」；若兩端都擋，會讓既有
  名稱為空字串的歷史列（migration 把 NULL 回填成 `''`）任何 update 都做不了。查重與完整性由
  呼叫端負責，API.md「新增前請自行查重」一條已涵蓋，並補上本表名稱。
- **D-6 別名：`entry-codes`、`entry_codes`、`entrycodes`。** 不得使用 `entry`／`entries`／
  `entry_data`（已是人物子資源 `ENTRY_DATA` 的別名）。
- **D-7 主鍵三處登錄**：`CompositePrimaryKey::SCHEMAS`、`OperationsController::resourceKeyColumns()`、
  兩份 config 的 `key_columns`。連帶讓 `/codes` UI 既有寫入產生的 operations 列可解析與還原。
- **D-8 不在範圍**：`ENTRY_CODE_TYPE_REL`（入仕途徑↔入仕類型的關聯）。新代碼若要掛上類型，
  目前仍需走 `/codes` UI；若下游之後需要，另開環節。
- **D-9 異體字落地替換／稽核蓋章／外鍵 422**：繼承 `AbstractCodeTableMutationHandler`／
  `CodeTableCreateHandler` 自動生效，不需額外掛鉤。本表沒有 `c_created_*`／`c_modified_*`
  稽核欄，比照 ADMIN_CAT_CODES 刻意不補（operations＋audit_log 已記錄操作者與時間）。
  本表無座標欄，不涉 §1.4。
- **D-10 新增只支援 `mode: direct`，不做提案式新增。** 與所有代碼表一致
  （`CodeTableCreateHandler::supports()` 只收 direct；`mode: proposal` 回 501）。下游所說的
  「proposal.yaml → 審核 → submit」是下游自己的本地審核流程，最後一步 submit 必須以具
  直接寫入權限（`canWriteDirectly`，已啟用的一般帳號以上）的 token 呼叫 `/api/v2/create`。
  update 則可提案（`mode: proposal` → 待審核）。開放代碼表提案式新增牽涉核准重放與配號時機，
  屬另一個題目，不在本計畫。
- **D-11 自動配號落在現有最大碼之後。** `max(0, 現有最大碼)+1`（負碼不參與配號）；若最大碼已到 smallint 上限回 422 `auto_assign_exhausted`（環節 1 codex review 後補上，適用所有可自動配發的代碼表）；CBDB 的入仕途徑碼有按類別
  分段的習慣，若下游要把新途徑放進特定區段，請在 `target.pk` 明確指定代碼（值域同樣受 smallint
  守衛檢查）。

## 1. Todo list

### 環節 1：後端登錄＋測試
- [x] `config/code_table_writes.php` 新增 `ENTRY_CODES`（D-1～D-3、D-6）
- [x] `config/code_table_mutations.php` 新增 `entry_codes`（同白名單、tier 皆空、not_null_fields）
- [x] `CompositePrimaryKey::SCHEMAS` 與 `OperationsController::resourceKeyColumns()` 登錄 `c_entry_code`
- [x] 新增 `tests/Feature/ApiV2MutateEntryCodesTest.php`：自動配發／指定主鍵、別名、update、
      not null 422、主鍵重複 409、白名單外欄位 422、delete 403、operations／audit_log、
      異體字替換、不與 `entry` 人物子資源撞名、無稽核欄也能新增
- [x] 跑受影響測試（新測試＋`CodeTableWriteConfigDriftTest`＋`MutationCreateUpdateParityTest`
      ＋`VariantReplaceHookCoverageTest`＋代碼表相關）
- [x] review agent → codex review 至無嚴重 issue（review 補強：測試斷言欄位級錯誤、tier 測試改用 `lv`、`resourceKeyColumns()` 護欄、空字串註解更正；codex 補強：自動配號值域）

### 環節 2：文檔
- [x] `API.md` §13 總表、§13.1 update 表、§13.2 create 表、NOT NULL 說明、查重須知
- [x] `docs/openapi/openapi.yaml`：未列舉代碼表 resource，無需更動
- [x] `CHANGELOG.md`
- [x] review agent → codex review 至無嚴重 issue（review 補強：空字串限定 JSON Content-Type、`char_variant_map` 兩欄無預設值）

### 收尾
- [x] 全套 `phpunit`（3506 綠、1 skip 為既有）、`php-cs-fixer`
- [ ] commit → PR → 等 CI → rebase merge
