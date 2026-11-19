# 入仕途徑↔類型關聯（ENTRY_CODE_TYPE_REL）v2 寫入計畫

> 狀態：**已實作（§D 為權威）**
> 分支：`feature/v2-entry-code-type-rel`
> 關聯：[入仕途徑代碼表 v2 寫入計畫](./ENTRY_CODES_V2_WRITE_PLAN.md)（其 D-8 把本表列為「不在範圍、另開環節」）、
> [代碼表受審計寫入 API 建設計畫](./CODE_TABLE_MUTATION_API_PLAN.md)、API.md §13

## 0. 背景

下游錄入代理已能經 `/api/v2/create` 新增入仕途徑（`entry-codes`），但新代碼要掛到入仕類型
（`ENTRY_TYPES`，如 `"08"`）上，仍只能走 `/codes` UI（session＋CSRF）。本表是純關聯表：

```sql
CREATE TABLE `ENTRY_CODE_TYPE_REL` (
  `c_entry_code` smallint NOT NULL,                -- FK → ENTRY_CODES  ON DELETE RESTRICT
  `c_entry_type` varchar(255) NOT NULL,            -- FK → ENTRY_TYPES  ON DELETE RESTRICT
  PRIMARY KEY (`c_entry_code`,`c_entry_type`)
)
```

沒有任何非主鍵欄；全庫也沒有任何外鍵引用本表。下游請求：登錄 create、驗兩個參照存在、
建議開放刪除（掛錯要能撤掉）、最好有讀取入口。

## §D. 決策定案

- **D-1 範圍：create ＋ delete（皆只支援 `mode: direct`），不開 update。** 本表沒有非主鍵欄，
  update 唯一能做的是改鍵——那等於「刪一列、建一列」，以兩個呼叫表達更清楚，也不需要在
  `code_table_mutations.php` 登錄（drift 測試只比對兩份 config 都有的表）。`mode: proposal`
  一律 501（與所有代碼表一致）。
- **D-2 主鍵兩欄皆必填、不自動配發。** `c_entry_code` 是 smallint（數值主鍵，非數值 422、
  超出 smallint 422）；`c_entry_type` 是**文本主鍵**——handler 依實際 schema 判斷、絕不 `(int)`
  轉型，`"08"` 原樣落庫。送 JSON 數字 `8` 會變成字串 `"8"`，不存在於 `ENTRY_TYPES` → 422
  （呼叫端必須送帶前導零的字串）。
- **D-3 參照存在性由應用層顯式驗證**，新增 config 鍵 `key_references`（create handler 通用機制）：
  - `c_entry_code` 必須存在於 `ENTRY_CODES.c_entry_code`、`c_entry_type` 必須存在於
    `ENTRY_TYPES.c_entry_type`；不存在回 422，錯誤鍵 `target.pk.<欄>` = `['not_found:<表>']`。
  - 比 DB 外鍵兜底（`changes: foreign_key_violation`，不指名哪一欄）更明確；DB 外鍵仍保留為
    並發兜底。
  - **文本參照要精確相等**：prod 是 `utf8mb4_general_ci`（不分大小寫、PAD SPACE），`'XA'`
    在 SQL 比較下等於 `'xa'`，外鍵也會放行，於是會建出一列鍵值與被參照列不同形的關聯。所以
    取回被參照列的實際值，與輸入做 PHP 嚴格字串比較，不相等視同不存在。前後空白另由全域
    `TrimStrings` middleware 先修掉（`' 08 '` → `'08'`，有測試記錄這個既有行為）。
    測試以 SQLite `COLLATE NOCASE` 模擬 general_ci，並做過變異驗證（拿掉守衛兩條測試會紅）。
  - 參照檢查排在「重複主鍵 409」之前：指向不存在代碼的請求，先告訴呼叫端輸入錯在哪裡。
- **D-4 刪除只對本表開放**：新增 config 鍵 `allow_delete`（預設 false），`CodeTableDeleteHandler`
  只對 `allow_delete: true` 的定義放行，其餘代碼表維持 403（訊息不變）。理由：
  - 原本全面停用的理由是「碼表被人物資料以外鍵引用、刪一列會連帶刪人物列」；本表**沒有任何
    外鍵引用它**，刪掉一列不影響任何其他列。以護欄鎖住這個前提：
    `CodeTableWriteConfigDriftTest::tables_open_for_delete_are_not_referenced_by_any_foreign_key()`
    以 migration 建出的實際 schema 逐表讀外鍵，任何 `allow_delete` 表被引用就紅。
    （限制：只看得到 migration 宣告的外鍵；若 prod 另有未經 migration 的外鍵，執行期由
    1451 → 409 `referenced` 兜底，不會靜默刪除或冒 500。）
  - 權限與 create 對稱（`canWriteDirectly`）；寫 operations（TYPE_DELETE，快照為被刪列）＋
    audit_log（before-image），可由 operations 頁「還原」重建。
  - 原本不可達的刪除碼一併補強：主鍵宣告與 SCHEMAS 不一致／讀不到欄位型別 → 500 中止；
    數值主鍵非數值 → 422（不再 `(int) 'abc'` 變 0）；文本主鍵與資料庫實際值精確比對（`'XA'`
    不會刪到 `'xa'` 那列、也不會把 `'XA'` 記進 operations）；交易內實際刪除列數不是 1 → 回滾
    （0 列 404、多列 409）；
    `QueryException` 的外鍵違反（1451）轉 409 友好訊息、鎖競爭轉 409（AGENTS §1.1：不可吞掉、
    不可冒成 500）。回應帶被刪列 `row`。
  - `/codes` UI 的刪除維持停用（前端 `RISKY_DELETE_DISABLED` 與 `CodesController::performDestroy`
    不動）——本計畫只開 token API。
- **D-5 讀取入口：`/api/v2/get` 登錄 `entry-code-type-rel`**（`person_id_column` 為 null：
  `person_id` 仍須帶、不參與比對，比照 `nianhao`）。給齊兩欄主鍵回 200＋`row`，不存在 404——
  掛上／撤掉後可直接確認。`c_entry_type` 登記為 `text_key_columns`：必須是字串（數字 422）、
  與實際值精確比對（review 發現：否則 MariaDB 下 `8` 命中 `'08'`、get 200 而 delete 404）。另外既有的 `/api/entry_list?id=<類型前綴>` 可列出某類型下的所有途徑
  （該端點以 `LIKE '<id>%'` 前綴比對），在 API.md 指引。
- **D-6 別名：`entry-code-type-rel`、`entry_code_type_rel`、`entrycodetyperel`。**
  不得使用 `entry`／`entries`／`entry_data`（人物子資源 `ENTRY_DATA`），也不與 `entry-codes`
  的三個別名重疊。
- **D-7 主鍵三處登錄**：`CompositePrimaryKey::SCHEMAS`、`OperationsController::resourceKeyColumns()`、
  config 的 `key_columns`，順序 `['c_entry_code', 'c_entry_type']`（與資料庫 PRIMARY KEY 一致）。
  連帶讓 `/codes` UI 既有寫入產生的 operations 列可解析與還原。
- **D-8 異體字／座標／稽核欄**：`c_entry_type` 已在 `VariantReplaceScope` 的排除清單（跨表 join
  鍵），`c_entry_code` 是數值，沒有其他欄——落地替換是 no-op，create handler 的「文本主鍵不可在
  替換範圍內」fail-closed 檢查會通過。無座標欄（不涉 §1.4）。本表無稽核欄，比照 `ENTRY_CODES`
  刻意不補（operations＋audit_log 已記錄操作者與時間）。
- **D-9 對 index year 的影響**：`IndexYearRebuildService` 的入仕規則以本表 join 決定「哪些途徑算
  某類型」。關聯的增刪**不會自動**重算 `BIOG_MAIN.c_index_year`，要等下次
  `cbdb:rebuild-index-year`。在 API.md 註明。
- **D-10 不在範圍**：`ENTRY_TYPES` 本身的寫入、update／改鍵、提案式新增／刪除、`/codes` UI 刪除。

## 1. Todo list

### 環節 1：後端（create／delete／get）＋測試
- [ ] `config/code_table_writes.php` 新增 `ENTRY_CODE_TYPE_REL`（D-1～D-4、D-6），並在檔頭說明
      `key_references`／`allow_delete` 兩個新鍵
- [ ] `CodeTableCreateHandler`：`key_references` 參照存在性驗證（D-3）
- [ ] `CodeTableDeleteHandler`：`allow_delete` 放行、數值主鍵校驗、文本主鍵精確比對、
      1451／鎖競爭轉 409、回應帶 `row`（D-4）
- [ ] `CompositePrimaryKey::SCHEMAS`、`OperationsController::resourceKeyColumns()` 登錄（D-7）
- [ ] `MutationReadService` 登錄 `entry-code-type-rel`（D-5）
- [ ] `CodeTableWriteConfigDriftTest`：`key_references` 只能指向 key_columns／allowed_fields、
      被參照的表與欄存在於 schema
- [ ] 新增 `tests/Feature/ApiV2MutateEntryCodeTypeRelTest.php`：create 成功（保留前導零）、
      參照不存在 422（兩欄各一）、`'08 '` 精確比對、數字 `8` 不等於 `"08"`、缺主鍵 422、
      重複 409、白名單外欄位 422、proposal 501、delete 成功＋operations／audit_log、delete 404、
      delete 非數值 422、其他代碼表 delete 仍 403、get 200／404、別名不與 `entry` 撞名、
      權限（未啟用帳號 403）、刪除後經 operations 還原；「無外鍵引用」護欄放在
      `CodeTableWriteConfigDriftTest`（需 `RefreshDatabase` 的 migration schema）
- [x] 跑受影響測試（新測試＋Drift＋`ApiV2MutateEntryCodesTest`＋其他代碼表測試＋
      `VariantReplaceHookCoverageTest`）
- [x] review agent → codex review 至無嚴重 issue（review 補強：`/api/v2/get` 文本主鍵精確比對
      ＋數字 422、`findExactRow` 依欄位型別判斷、數值參照只收整數、以 `DB::listen` 斷言字串綁定
      （SQLite 會掩蓋 MariaDB 的 `8 = '08'`）、逐別名鎖住其他代碼表 403、註解指向正確護欄；
      codex 補強：atomic 批次中途失敗的回滾測試）

### 環節 2：文檔
- [x] `API.md`：資源總表、代碼表分組說明、新增 §13.2a、§13.3 例外、〈讀取單列〉資源表＋注 4、
      `entry-codes` 那條「不在 API 範圍」更正、index year 註記
- [x] `docs/openapi/openapi.yaml`：未列舉代碼表 resource，無需更動
- [x] `CHANGELOG.md`；`ENTRY_CODES_V2_WRITE_PLAN.md` D-8 加指向本計畫
- [x] review agent → codex review 至無嚴重 issue（review 補強：主鍵可放 `changes`、batch 內 create
      須帶 `changes: {}`、delete 的 422 情形、護欄只涵蓋 migration 外鍵、舊版 `entry_list` 的 `id`
      是類型前綴字串；codex 補強：資源表欄名改「支援端點」、數字 `c_entry_type` 在 get（422 string）
      與 create／delete（轉字串後比對）的差異寫清楚、`OFFICE_TYPE_TREE`「唯一文本主鍵表」限定在 13.2）

### 收尾
- [x] 全套 `phpunit`（3537 綠、1 skip 為既有）、`php-cs-fixer`
- [ ] commit → PR → 等 CI → rebase merge
