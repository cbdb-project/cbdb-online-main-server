# Operations「比較」功能收斂方案

狀態：**設計定稿、未動工**（2026-08-05）
前情：稽核欄語義定案與 `__applied_operation_id` 認領機制（commit 6a3c78ee）已讓「比較」按鈕恢復可用；本文檔規劃的是其後的**結構性收斂**——把三套差異機制減成一套權威來源＋一種現算預覽。

## 1. 問題：三套差異機制＋一次核准兩列記錄

`OperationsController`（index/show 展示層）目前疊了三套彼此獨立、不同年代的差異機制：

| # | 機制 | 來源 | 引入時代 | 現狀 |
|---|------|------|----------|------|
| 1 | 快照 diff | `operations.resource_data` vs `resource_original` 逐欄比對 | 提案功能初期 | update 提案可用；create 提案 original 空、必然無 diff |
| 2 | audit_log diff | `audit_log.old_data`/`new_data`（每次實際寫入產生） | v2 mutation 架構 | **唯一權威**；所有寫入路徑（direct、核准重放、顯式連帶刪除）皆已產出 |
| 3 | 「實時比對」 | 拿歷史操作對**當前** DB 列（`index()` 內 2019 年的 `BiogMain::find` 等段落） | 2019 | 語義混淆：把「這次改了什麼」和「現在長什麼樣」攪在一起 |

疊加的結構問題：**核准一次提案產生兩列 operations**——提案列（op_type 8/9/10）與 handler 重放落庫的 direct 列（op_type 2/3/4）。audit 掛在後者，提案列靠 `__applied_operation_id` 指標「認領」（`loadAuditLogsByOperation()`）。同一件事在列表出現兩行，能力還不對稱，對審核者是認知負擔。

`canCompare = (hasAuditLogs || hasDiffContent) && opType !== 4` 的判定要同時考慮三套機制的可用性，正是按鈕「有時灰、有時不灰、看不出規律」的根源。

## 2. 目標語義：使用者只需要兩種「比較」

1. **待審提案**（pending 8/9/10）：「核准後會變成什麼樣？」
   → 拿提案內容對**當前 DB 列**現算（不是對提案時的舊快照——提交後別人可能已改過同一列，對舊快照比較會給審核者過期的資訊，也蓋不住「核准時資料已漂移」的隱患）。
2. **歷史操作**（已核准的提案、direct 寫入）：「這次寫入實際改了什麼？」
   → 讀 audit_log 的 old/new，唯一權威，不需任何 fallback。

三套機制、兩列記錄，服務的就這兩個問題。

## 3. 設計決策

- **D1　歷史比較只讀 audit_log**。退役快照 diff（機制 1）與實時比對（機制 3）在「比較」中的角色。`resource_data`/`resource_original` 快照保留不動——它們仍是 restore 與審計事實的載體，只是不再作為比較的資料來源。
- **D2　待審提案比較改為「提案內容 vs 當前列」現算**。逐欄標示：新增／修改（含當前值 vs 提案值）／因漂移已與提案假設不符的欄（原 original 快照可順帶用於漂移偵測：original ≠ 當前列 ⇒ 顯著提示審核者）。
- **D3　核准後兩列在列表收合為一列**。以提案列為主顯示（含審核 meta），`__applied_operation_id` 指向的 direct 列預設從列表隱藏（query 加參數可展開）；其 audit 即提案列的比較內容。避免同一變更出現兩行。
- **D4　不兼容舊版提案、不回填存量**（使用者決策，2026-08-05）：
  - 缺 `__applied_operation_id` 的存量已核准提案不做回填，比較維持不可用；
  - kinship／assoc bespoke 核准路徑（`BiogMainRepository` 內部自建 operation、未回報 id）若屆時仍未收斂到 handler 重放，在本案內補回報 id 即可，不做歷史修補；
  - 展示層可直接假設「新格式」：有 `__applied_operation_id` ⇒ 讀 audit；沒有且 pending ⇒ 現算預覽；其餘 ⇒ 無比較。判定式從三套機制的交集縮成這一條。
- **D5　`canCompare` 隨之簡化**：`pending ⇒ true（現算）`；`approved 且有 __applied_operation_id ⇒ true（audit）`；`direct 寫入 ⇒ hasAuditLogs`；其餘 false。`opType !== 4` 的排除改為「delete 比較＝顯示被刪列全欄」（audit old_data 即全列快照，本來就做得到）。

## 4. 實施步驟

1. **後端資料面**
   1. kinship／assoc 核准路徑回報落庫 operation id（併入 `lastAppliedOperationId` 機制），或先把兩表收斂進 `HANDLER_ROUTED_RESOURCES`（後者是既定方向，見 `docs/PERSON_PROPOSAL_PATHS.md`）。
   2. 實體聚合提案（§4.5 office 等）核准時同樣寫入 `__applied_operation_id`。
2. **比較 API**：新端點（或整理現有 `show()`）輸出統一結構 `{mode: 'audit'|'preview', rows: [...]}`——audit 模式直讀 `loadAuditLogsByOperation` 已組好的 diff；preview 模式現算提案 vs 當前列＋漂移標記。
3. **展示層清理**（大頭）：
   1. `index()` 移除 2019 實時比對段落（`BiogMain::find`／`resource_id` 回查當前列那一大段）；
   2. `canCompare` 換成 D5 判定；
   3. 列表收合 direct 列（D3）；
   4. React operations 頁（flag=new 版）同步改判定與收合。
4. **測試**：pending 預覽（含漂移提示）、approved 讀 audit、delete 顯示全列、收合行為、存量無 `__applied_operation_id` 列表現（灰、不報錯）。
5. **收尾**：刪除快照 diff 比較碼路徑與相關輔助函式；CHANGELOG／AGENTS 更新。

## 5. 風險與邊界

- `OperationsController` 逾千行、index() 職責過載，步驟 3 動刀面積大——建議先抽出「列表組裝」私有方法再改行為，diff 才可讀。
- 漂移偵測依賴 original 快照品質；original 缺失（極舊資料）時 preview 退化為「提案值 vs 當前值」不標漂移即可，不需報錯。
- 收合 direct 列會改變「操作歷史」的完整可見性——保留展開參數（D3）即可兼顧審計需求。
- restore 功能讀快照的路徑（`restoreUpdate`/`restoreDelete`/`getPreviousSnapshot`）與本案無關，**不得**在清理快照 diff 時誤刪。

## 6. 驗收

- 審核者在 operations 列表上：pending 提案一律可比較（現算、含漂移提示）；核准後同一列直接看到實際寫入 diff；同一變更不再出現兩行。
- 「比較」按鈕灰/亮有單一可解釋規則（D5），不再取決於三套機制的偶然交集。
