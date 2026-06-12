# All Tables 逐欄位布林過濾（AND / OR / NOT）— 設計文檔

**狀態：** **Phase 1–4 已實作**（2026-06-12）。本文設計即實作依據；維護時請以實際程式碼為準。
**建立日期：** 2026-06-12
**適用範圍：** 「All Tables」選單（`/codes`）底下的所有代碼表（`/codes/{table}`，共用 `CodesController::show()` + `resources/views/codes/show.blade.php`）

> **實作狀態**：`ui_hidden`（Phase 1）、`App\Support\ColumnFilterExpression`（Phase 2，含 `parse`/`applyToBuilder`/`describe`）、`CodesController::show()` 接線（Phase 3）、blade UI + i18n（Phase 4）皆已落地，並有 `ColumnFilterExpressionTest`（真 SQLite）與 `CodesControllerTest` 覆蓋。所有設計決策見第 9 節「設計決策總表」。

---

## 1. 背景與目標

`/codes/{table}` 列表頁目前已有兩層過濾（見 [`CODES_FILTER_SORT_PLAN.md`](./CODES_FILTER_SORT_PLAN.md)）：

1. **頂部全文搜尋**（`?search=`）：對所有可搜尋欄做 `OR + LIKE '%term%'`（跨欄位）。
2. **逐欄位過濾**（`?filters[col]=value`）：每欄一個輸入框，欄位之間 **AND**，每欄目前只支援單一 `LIKE '%value%'`。

**本次目標：** 在「逐欄位過濾」這一層，讓**單一欄位的輸入框內**支援 AND / OR / NOT 布林運算，並提供「完整關鍵字」與「速記符號」兩種寫法。

**設計決策（已與需求方確認，2026-06-12）：**
- 布林邏輯加在**逐欄位輸入框內**，不是頂部全文搜尋框。
- 適用「All Tables 選單下的所有代碼表」，即 `config/codes.php` 的所有表（共用同一 view / controller，因此天然全表生效）。
- 欄位**之間**維持現狀（AND）；**運算子僅限單一欄位內部，不做跨欄位運算子**（已定案，不再討論）。

---

## 2. 與現有行為的關係

| 層級 | 現狀 | 本次變更 |
|------|------|----------|
| 頂部全文 `search` | 跨欄位 OR + `%term%` | **不變** |
| 逐欄位 `filters[col]` 之間 | AND | **不變**（仍 AND） |
| 單一欄位 `filters[col]` 內部 | 單一 `%value%` | **新增** AND / OR / NOT 布林，**但僅在頁面布林模式開啟時**（見 2.2） |
| 游標分頁表（如 `CBDB__NAME_FTS`） | 見下方 ⚠ | **硬短路**：拒絕所有逐欄 / 布林 filter（不只靠 ui_hidden，見 2.3） |

> 因此（布林模式開啟下）一個欄位內輸入 `黃州 OR 隨州`，而另一欄輸入 `蘇軾`，整體語意為：
> `(col_A LIKE %黃州% OR col_A LIKE %隨州%) AND (col_B LIKE %蘇軾%)`。
> 「整個表共同運算」由「多個欄位的布林結果再 AND」達成；跨欄位的自由布林**不做**（已定案）。

### 2.1 行為相容性（向後相容，無語義改變）

**空白不是運算子**（已定案，不採用「空白＝隱含 AND」）。空白一律視為 term 內部的字面文字。

因此現狀下 `filters[col]=王 安` 仍是字面搜尋 `LIKE '%王 安%'`，**行為完全不變**；既有書籤 / 分享連結不受影響。
布林只在使用者明確輸入運算子（`AND` `OR` `NOT` `&` `|` `!`）或括號時才啟動，未輸入運算子的內容一律照舊當單一字面值比對。

### 2.2 頁面級布林開關（**預設關閉**，已定案）

布林解析以**整頁一個開關**控制，預設關閉。這是讓「進階語法」與「零負擔的預設體驗」並存的關鍵設計：

| 狀態 | 逐欄 filter 行為 |
|------|------------------|
| **關閉（預設）** | 與現狀**完全相同**：輸入一律當單一字面 `%value%`，運算子／括號／引號全部視為普通文字、不解析。零行為改變、零學習成本、既有連結不受影響。 |
| **開啟** | 所有逐欄 filter 輸入改走 `ColumnFilterExpression` 解析器（第 3–6 節），運算子與括號生效。 |

**設計要點：**
- **UI（桌面限定，見下）**：toolbar 放一個開關（enable/disable 連結），例如「進階篩選（AND / OR / NOT）」，旁附 `?` 語法說明（details/summary 可鍵盤開啟，點明「空白＝字面、不是 AND」「排除用 `NOT`/`!`，不用 `-`」「`&`/`|`/括號的字面值要加引號」）。另提供數個語法**範例**（**唯讀** `<code>` 展示、非可點按鈕——它們是給人閱讀的範例，不是動作）。
- **不做送出前即時預覽（決策 #12，見 9.2）**：回車（或 Apply）送出整個查詢，送出後由後端權威解析、回填每欄語義說明與錯誤；不在前端重做 parser。
- **行動版（B12，已定案；M6 補判定）**：布林為**桌面功能**，以 **CSS 斷點 `md`（768px）** 為界，行動寬度下**隱藏開關**、不顯示語法說明/chip。**注意**：開關隱藏是純前端；後端對 viewport 無感，因此一個帶 `?filter_bool=1` 的分享連結在手機上後端仍會解析生效——這是**可接受的已知行為**（查詢本身合法、不造成錯誤），不是漏洞，文檔明載即可。不另做後端強制關閉。
- **狀態攜帶**：以 URL query param `?filter_bool=1` 表示開啟，未帶或 `=0` 即關閉（預設）。**所有送出/連結路徑都必須帶上它（C6，含本次補齊的兩條）**：
  - offset 分頁：`appends`，來源用「**有效欄位** `$appliedFilters`」（M4/M9，排除語法錯誤欄位）。
  - 手工組裝的 URL：Clear Filters、三態排序連結、游標 before/after（同樣用有效欄位）。
  - **`show.blade.php` 的「上方 search form」與 `filter-form` 兩個 GET form**：各加 `<input type="hidden" name="filter_bool">`；**reset 按鈕**也要在布林開啟時帶 `filter_bool=1`。
- **開關（toggle）連結用「原始輸入」而非有效欄位（codex major 修正）**：enable/disable 連結帶**使用者原始輸入的所有欄位**（`$filters`，含語法錯誤被略過者），**不是** `$appliedFilters`。否則按「關閉進階篩選」時，含錯誤的欄位會憑空消失；正確行為是切回字面模式後，原輸入降級為字面 `%value%` 比對而**保留**。（分頁/排序連結仍用有效欄位，兩者刻意不同。）
- **黏性（可選）**：另以 cookie 記住使用者上次選擇，跨表沿用。
- **全域 kill-switch**：`config('codes.boolean_filter_enabled', true)`，營運端可整個停用——**後端忽略 `filter_bool` 且前端連開關都不顯示**（controller 傳 `booleanFilterAvailable` 給 view，blade 據此隱藏 toggle），確保緊急回退完整（codex major 修正）。
- **開關有效值的優先序（C3，單一函式計算）**：`kill-switch=false` → 一律忽略 `filter_bool`（布林永遠關）；否則 **URL `filter_bool` > cookie > 預設關閉**。由後端單一 helper 算出 effective 值，避免三層來源語意漂移。
- **後端**：`CodesController::show()` 先算 effective 開關；關閉時走現有字面 `where(col,'like','%'.$v.'%')` 路徑（完全不碰解析器），開啟時才呼叫解析器。兩條路徑都保留白名單與 binding。

### 2.3 游標分頁大表的硬短路（codex review 補強，必做）

現行 `CodesController::show()` 的判斷是
`$useCursorPagination = in_array($table, ['CBDB__NAME_FTS']) && empty($filters) && $sortBy === ''`，
意思是：**只要帶了 `filters` 或 `sort_by`，`CBDB__NAME_FTS` 就會脫離游標路徑、落回一般 offset 分頁並套用逐欄過濾**。因此「不套用 filter」其實**不成立**——有人直接帶 query 參數打 URL，布林 / 逐欄 filter 就會對這張百萬列大表跑 `%term%` 全表掃描。

**規則（不可只依賴 ui_hidden）**：對「游標分頁表清單」（目前 `CBDB__NAME_FTS`）**先行短路、無條件拒絕逐欄 / 布林 filter 與 sort**，永遠走游標路徑。`ui_hidden`（決策 #10）只把它從清單/側邊欄隱藏、**直連仍可達**（已定案），所以這條後端短路是它「可達但不可被 filter/sort 打全表掃描」的真正防線，必須做。

- **短路的精確插入點（minor 修正）**：游標前綴搜尋需要 `$thead`/`$searchableColumns`，因此插入點是「**算出 `$thead`/`$searchableColumns` 之後、讀 `$filters`/`$sortBy` 與 `useCursorPagination` 判斷之前**」，不是字面「`show()` 最前面」。命中游標表清單時：強制 `$filters=[]`、`$sortBy=''`、`$sortDir='asc'`、`$booleanEnabled=false`，再走游標路徑。
- **殘留 URL 的正確歸因（M3 修正）**：游標路徑（`showWithCursorPagination()`）**不呼叫** `appends()`；它的 before/after 連結是 `show.blade.php` 用 `$baseQuery`（取自 view 變數 `$filters/$sortBy/$search`）組的。`appends($request->except('page'))` 只在 **offset 分支**（`CodesController:356`）。因此「短路後 URL 不殘留」靠的是**把傳給 view 的 `$filters/$sortBy/$sortDir` 設空**，讓 blade 的 `$baseQuery` 與 jump-to-ID hidden inputs 自然乾淨——不是去動 appends。

---

## 3. 語義規格

### 3.1 基本單位（term）

單一欄位內的一個「詞」對應一個比對條件：

```
term  →  {resolved_column} LIKE '%{文字}%'
```

`{resolved_column}` 由現有 `resolveColumnForQuery($col, $joinConfig)` 解析（處理 JOIN 表的別名前綴）。

**term 的邊界**：一個 term 是一段字面文字，**可包含內部空白**；它一直延伸到下一個運算子 token（`AND` / `OR` / `NOT` / `&` / `|` / `!` / `(` / `)`）或引號為止。前後空白會被 trim。
換言之，空白本身不切分 term —— 只有運算子 token 才切分。例：`黃州 隨州`（無運算子）是**單一** term，比對 `%黃州 隨州%`；`黃州 OR 隨州` 才是兩個 term。

> **`!` 何時算 token（M2，與 §3.4/§5 對齊）**：`!` 列入上面的邊界運算子，但**只在「字串開頭 / 緊跟二元連接子（AND/OR/&/`|`）/ 左括號」之後**才是 NOT token；緊貼前一字元（如 `a!b`）時是字面、不切分。所以 `!黃州`（句首）= NOT；`黃州 !隨州`（`!` 在 term 後、無連接子）= 缺二元連接子的語法錯誤；`a!b` = 字面 `a!b`。三者與 §3.4、§5 一致。

### 3.2 運算子與優先序

- 二元連接子：`AND`、`OR`（連接兩個 term / 群組）
- 一元前綴：`NOT`（修飾其後緊接的 term 或括號群組）
- 群組：`( )` 可改變結合順序
- **優先序：`NOT` > `AND` > `OR`**，同級由左至右
- **沒有隱含運算子**：兩個 operand 之間必須有明確的 `AND`/`OR` 才會被連接（空白不算）。若兩個 operand 相鄰卻缺少二元連接子（如 `NOT 黃州 NOT 隨州`），視為**語法錯誤**，依 3.4 處理（標記該欄錯誤、略過 filter，**不**轉字面）。

> 釐清一個常見混淆：`NOT` 是**一元前綴**，不是二元連接子。
> 因為已取消隱含 AND，`NOT 黃州 NOT 隨州` 不會被自動補成 `(NOT 黃州) AND (NOT 隨州)`，而是**語法錯誤**。
> 要表達「兩者皆不含」必須明寫連接子：`NOT 黃州 AND NOT 隨州`。

### 3.3 NULL-safe NOT（正確性，必做）

SQL 中 `NULL LIKE '%x%'` 結果為 `NULL`，`NOT NULL` 仍為 `NULL`，會導致該欄為 NULL 的列被意外排除。
因此單一 `NOT term` 必須產生：

```sql
(col IS NULL OR col NOT LIKE '%x%')
```

**⚠ 不可把群組直接包成裸 `NOT (...)`（codex review 指正）**：在 SQL 三值邏輯下，`NOT (col LIKE '%a%' OR col LIKE '%b%')` 對 `col IS NULL` 的列結果為 `NULL`，仍會被 `WHERE` 排除，與 NULL-safe 要求矛盾。
**唯一正確做法**：`NOT` 在 AST 的 apply 階段一律**下推（De Morgan）到每個葉節點**，最終每個葉子都是 `(col IS NULL OR col NOT LIKE ?)`，群組層只剩 `AND`/`OR` 的組合，**絕不出現裸 `NOT (...)`**。
- 例：`NOT (a OR b)` → `(col IS NULL OR col NOT LIKE %a%) AND (col IS NULL OR col NOT LIKE %b%)`
- 例：`NOT (a AND b)` → `(col IS NULL OR col NOT LIKE %a%) OR (col IS NULL OR col NOT LIKE %b%)`
- 雙重否定 `NOT NOT a` 化簡為 `a`。

必須補 NULL 分支並寫測試（含 NULL 列、群組否定兩種案例）。

### 3.4 字面值與引號

- 關鍵字 `AND`/`OR`/`NOT` **大小寫不敏感**，且僅在「獨立 token」（前後為 **ASCII 空白或字串邊界**）時視為運算子；`brand` 內的 `and` 不算。
- **CJK 與關鍵字的邊界（B6，必須明訂）**：關鍵字兩側只認 **ASCII 空白／字串邊界／括號**為邊界，**不可用 `\b`**（`\b` 對 UTF-8/CJK 不可靠）。因此 CJK 詞間若不加空白，`黃州AND隨州` 不切分、整串當字面 term；要用關鍵字必須加空白（`黃州 AND 隨州`）。**已知限制**：CJK 使用者若不想加空白，請改用速記符號 `& | !`（見下，符號不需空白邊界）。文檔把「完整關鍵字」定位為需空白、「符號」為免空白，兩者並非任何情況都等價。
- **速記符號 `&` `|` 的邊界（B4）**：作為二元連接子，出現在兩個 operand 之間即生效，**不需**空白（`黃州&隨州` = `黃州 AND 隨州`）。但這代表合法字面 `R&D`、`甲|乙`、`M&A` 會被切開——要當字面**必須用引號**：`"R&D"`、`"甲|乙"`（與括號同規則）。
- NOT 的速記符號是 `!`（**不使用** `-`，見下）。**`!` 的位置語義（B5，易踩，務必看範例）**：`!` 只在「**字串開頭**」或「**緊接在二元連接子（AND/OR/&/`|`）或左括號之後**」時才視為 NOT；其餘位置（例如緊跟在一個 term 後面）不構成合法否定，會造成相鄰 operand 的語法錯誤。
  - `!黃州 隨州`（句首）→ 合法：否定整個 term `黃州 隨州`（依 3.1 邊界延伸），即 `NOT %黃州 隨州%`。
  - `黃州 !隨州`（`!` 在 term 後、缺二元連接子）→ **語法錯誤**：應寫 `黃州 AND !隨州`。
  - token 中間的 `!`（如 `a!b`）視為文字。
- **`-` 不是運算子（已定案）**：代碼表大量出現連字號與負值（如索引年 `-987`、年代範圍 `1023-1025`），把 `-` 當 NOT 會踩到無數陷阱。因此 `-` 一律視為**字面文字**，`-987`、`1023-1025` 都照舊比對 `LIKE '%-987%'` / `%1023-1025%`，**無需任何特例處理**。要排除請用 `NOT` 或 `!`。
- **括號 `( )` 為群組運算子**：要搜尋**字面括號**（如真實條目 `宋代節度(地區未詳)`）必須用引號包起來，例：`"宋代節度(地區未詳)"`。同理 `&`、`|` 的字面值也用引號保護。
- **未閉合 / 空引號（C1）**：未閉合引號（`"黃州`）與空引號（`""`）皆視為**語法錯誤**，依本節「解析失敗」處理（標記該欄錯誤、略過 filter）。
- **引號不支援跳脫（已知限制）**：引號內**不能**再含雙引號（無 `\"` 跳脫）；如 `"a"b"` 會先解析出 `"a"`，殘留的 `b"` 再觸發未閉合引號（`unterminated_quote`）語法錯誤、整欄略過。含雙引號的字面值無法在布林模式搜尋——代碼表地名幾乎不含雙引號，影響極小；真有需要時關閉布林模式即可字面比對。
- **全半形正規化（已定案）**：tokenizer 先把全形運算字元正規化為半形再解析 —— 全形括號 `（ ）`、全形 `！`、全形 `｜`、全形 `＆`、全形空白都比照半形對應運算子（中文輸入法預設打出全形，否則括號分組在中文鍵盤下難用）。代價：開啟布林模式時，含全形括號的字面值也需用引號。**此正規化只在布林模式開啟時生效**；關閉時全形字元一律字面。
- 以雙引號包住的內容視為**字面文字**，可含空白與運算字：`"黃州 AND 隨州"`、`"宋代節度(地區未詳)"` 比對的是整段字串。
- **解析失敗的處理（codex review 修正，分模式）**：
  - **布林模式關閉（預設）**：根本不解析，整串當字面 `%value%`（現狀，無所謂「失敗」）。
  - **布林模式開啟**：解析失敗時（括號不對、懸空運算子如 `黃州 AND`、相鄰 operand 如 `NOT a NOT b`、未閉合/空引號、超限、空群組 `( )`）**不可靜默回退成字面**——因為那會把使用者意圖縮小結果的查詢（特別是含 `NOT`）無聲放大成另一個語意。應改為：**該欄輸入框顯示可見的語法錯誤標記，並略過（不套用）該欄 filter**（其他正常欄位照常套用）。錯誤呈現與送出後的語義回填見 9.2。

---

## 4. 運算子對照表（完整 + 速記）

| 邏輯 | 完整關鍵字 | 速記符號 | 備註 |
|------|-----------|----------|------|
| AND | `AND` | `&` | 必須明寫；空白**不是** AND |
| OR | `OR` | `\|` | |
| NOT | `NOT` | `!`（前綴） | 一元，貼在 term／群組前；**不用** `-`（見 3.4） |
| 群組 | — | `( )` | 改變結合順序 |
| 字面 | — | `"…"` | 內含運算字時當普通文字 |

> **無隱含運算子**：相鄰兩詞之間沒有 `AND`/`OR` 時，不會自動連接 —— 它們要嘛屬於同一個字面 term（中間只有空白），要嘛構成語法錯誤（兩個 operand 相鄰，如 `NOT a NOT b`），依 3.4 標記錯誤並略過該欄。
>
> `,`（逗號）**不啟用**為 OR 別名（已定案，決策 #8）；逗號一律當字面文字（代碼表內容常含逗號，避免歧義）。

---

## 5. 範例（單一欄位輸入框內）

| 輸入 | 等價完整寫法 | 產生的條件（單欄 `col`） |
|------|-------------|--------------------------|
| `黃州 隨州` | （字面，無運算子） | `col LIKE %黃州 隨州%`（空白照舊當字面，**向後相容**） |
| `黃州 AND 隨州` | `黃州 AND 隨州` | `col LIKE %黃州% AND col LIKE %隨州%` |
| `黃州 & 隨州` | `黃州 AND 隨州` | 同上（`&` 等同 `AND` 符號速記） |
| `黃州 OR 隨州` | `黃州 OR 隨州` | `col LIKE %黃州% OR col LIKE %隨州%` |
| `!黃州 AND 隨州` | `NOT 黃州 AND 隨州` | `(col IS NULL OR col NOT LIKE %黃州%) AND col LIKE %隨州%` |
| `NOT 黃州 AND NOT 隨州` | `NOT 黃州 AND NOT 隨州` | 兩個 NULL-safe NOT 以 AND 連接 |
| `(黃州 \| 隨州) AND !羈縻` | `(黃州 OR 隨州) AND NOT 羈縻` | OR 群組 AND 一個 NULL-safe NOT（排除羈縻州） |
| `(京兆 \| 開封) AND (府 \| 州)` | `(京兆 OR 開封) AND (府 OR 州)` | 兩個 OR 群組以 AND 連接（括號改變優先序；命中京兆府/開封府） |
| `黃州 AND 隨州 OR 京兆府` | `(黃州 AND 隨州) OR 京兆府` | **混合優先序**：`AND` 比 `OR` 緊 → `(黃州 AND 隨州) OR 京兆府`（golden case，須有測試） |
| `"黃州 AND 隨州"` | 字面 | `col LIKE %黃州 AND 隨州%` |
| `"宋代節度(地區未詳)"` | 字面 | `col LIKE %宋代節度(地區未詳)%`（真實宋代條目；引號保護字面括號，否則 `(地區未詳)` 會被當群組） |
| `"R&D"` | 字面 | `col LIKE %R&D%`（引號保護 `&`，否則 `R&D` 會被切成 `R AND D`） |
| `!黃州 隨州` | `NOT 黃州 隨州`（單一被否定 term） | `(col IS NULL OR col NOT LIKE %黃州 隨州%)`（`!` 在句首，否定整個 term） |
| `黃州 !隨州` | （語法錯誤） | `!` 在 term 後、缺二元連接子 → 標記錯誤、略過該欄；應寫 `黃州 AND !隨州` |
| `NOT 黃州 NOT 隨州` | （語法錯誤） | 缺二元連接子 → 該欄**標記錯誤、略過 filter**（不靜默轉字面，見 3.4） |
| `1023-1025` | 字面 | `col LIKE %1023-1025%`（`-` 不是運算子） |

> 以上皆為**布林模式開啟**時的行為；關閉（預設）時整列輸入一律當字面 `%value%`。

> 取消隱含 AND 的代價：Google 式的 `!黃州 隨州`（排除黃州、同時含隨州）**不再成立** —— `!黃州 隨州` 會把 `黃州 隨州` 當成一個被否定的字面 term。要表達「排除黃州且含隨州」必須明寫 `!黃州 AND 隨州`。這是為了保住「含空白字面值向後相容」所做的取捨。

---

## 6. 解析器設計

建議新增一個純函式服務（PHP），與 controller 解耦、好測試：

```
App\Support\ColumnFilterExpression
  parse(string $raw): ExprNode          // tokenize → AST；失敗丟例外
  applyToBuilder($builder, string $resolvedColumn, ExprNode $ast): void
```

**流程：**
1. **Tokenizer**：切出 token —— 字面詞、`"引號字串"`、`( )`、`AND/OR/NOT`（大小寫不敏感、獨立 token）、`&`、`|`、`!`（`!` 僅前綴，token 規則見 3.1）。`-` 不是運算子，當字面字元處理。**具體上限（M5，需可斷言）**：原始輸入長度 ≤ **256** 字元、token 數 ≤ **64**、括號巢狀深度 ≤ **5**；用**線性掃描**（不可用回溯式 regex，防 ReDoS）。任一上限超出即解析失敗。
2. **Parser**：遞迴下降，依優先序 `OR ← AND ← NOT ← (group | term)` 建 AST。**無隱含運算子**：相鄰兩 operand 缺二元連接子即視為語法錯誤（丟例外），不自動補 `AND`。
3. **Apply（節點與葉子的閉包包裝，M1，務必照此）**：遞迴把 AST 轉成巢狀 `where`/`orWhere` 閉包，值一律走 **binding**（不拼字串）。**每個 operand（不論 term 或子群組）都各自包進一層閉包**，避免 fluent chain 把不同層的 AND/OR 攤平、翻轉分組：
   - 正向葉子：`->where($col, 'like', '%'.$term.'%')`
   - NULL-safe NOT 葉子（決策 #13）：`->where(fn($q) => $q->whereNull($col)->orWhere($col, 'not like', '%'.$term.'%'))`
   - **AND 節點**：`->where(function($q) use(...) { foreach(children) $q->where(fn($c)=>applyChild($c, $q? )); })` —— 每個 child 用 `where(Closure)` 串接（AND）。
   - **OR 節點**：`->where(function($q){ 第一個 child 用 $q->where(Closure)，其餘用 $q->orWhere(Closure); })` —— 整個 OR 群組外再包一層 `where(Closure)`，確保它與兄弟節點之間的邊界正確。
   - 規則一句話：**AND→連續 `where(Closure)`；OR→群組外包一層、組內首 `where` 其餘 `orWhere`；每個 child（含 NULL-safe NOT 葉子）一律再各包一層 Closure**。`NOT` 不在群組層出現（已在 parse/normalize 階段 De Morgan 下推到葉子）。
   - 一律用 `whereNull()` + `'not like'`，**不要 `whereRaw`**（維持 MariaDB/SQLite 跨 DB 一致）。
   - **Golden（必測）**：`(黃|王) AND (安|石)`、`NOT(a AND b)`、`NOT(NOT a OR b)`、`a AND b OR c` 都要驗證產生的巢狀 where 群組邊界正確（見 9.3 用真 SQLite 斷言）。
4. **解析失敗**：`parse()` 丟例外時（括號不對、懸空運算子、相鄰 operand、未閉合或空引號 `""`、超限等）——布林模式下**回傳錯誤給呼叫端，該欄標記語法錯誤並略過 filter**（見 3.4、9.2），**不**靜默轉字面；只有布林模式關閉時才走純字面路徑（那條路徑根本不呼叫解析器）。
   - **空輸入不是失敗（minor）**：整欄 `trim($value)===''` 在進 parser **之前** `continue`（不套用、也不報錯）；與「空群組 `()`／空引號 `""`＝失敗」區隔。

**接入點（順序必須照此，避免 JOIN 表 ambiguous column）：** `CodesController::show()` 的逐欄 filter 迴圈，先看頁面布林開關（`filter_bool`，見 2.2）。對每一欄：
1. `$resolvedColumn = resolveColumnForQuery($col, $joinConfig)`；
2. **`$resolvedColumn === null` 即 `continue`**（不呼叫解析器；此為 JOIN 表防 ambiguous column 的唯一防線，現有字面路徑已有，布林路徑必須同樣保留）；
3. 開啟時才對該欄 `ColumnFilterExpression::parse()` → `applyToBuilder($query, $resolvedColumn, $ast)`；關閉時走現有字面 `where($resolvedColumn,'like','%'.$v.'%')`。

> **欄位解析只做一次**：`$resolvedColumn` 在進解析器**之前**算好，整棵 AST 的所有葉子共用同一個已解析欄位；**解析器內部完全不接觸欄位名**（只處理值與布林結構），杜絕使用者輸入跑進欄位位置。其餘流程（白名單、分頁、tie-breaker）不動。

---

## 7. 安全性

| 風險 | 緩解 |
|------|------|
| 欄位名注入 | 欄位仍經 `$thead` 白名單 + `resolveColumnForQuery()`，使用者輸入只當「值」 |
| 值注入 | 全部走 Query Builder binding，運算子只控制 `where` 巢狀結構，不進 SQL 字串 |
| ReDoS / 解析爆量 | 具體上限（見 §6 step 1）：長度 ≤ 256、token ≤ 64、深度 ≤ 5，線性掃描不用回溯式 regex；**超限視為解析失敗**——該欄標記錯誤、略過 filter（見 3.4），**不**靜默轉字面（否則含 NOT 的超長輸入會被放大成 `LIKE '%整串%'`，語意翻轉） |
| **回填 XSS（M7）** | §9.2 送出後回填的「人話語義」會印出使用者輸入的 term，**一律用 `{{ }}` / `e()` escape**，禁用 `{!! !!}` 與未 escape 的 `Js::from`；錯誤原因走 i18n key、不回顯原始輸入；涵蓋 placeholder/aria 屬性 |
| 效能（`%term%` 全表掃描） | 與現狀同性質；多 term 會放大掃描成本，僅用於中小代碼表，游標大表不適用 |

---

## 8. 業界方案參考與技術選型（research，2026-06-12）

研究了搜尋引擎運算子慣例、資料庫全文檢索、以及現成解析函式庫，結論如下。

### 8.1 運算子慣例：速記與主流一致，但**刻意不採用「空白＝AND」**

主流搜尋介面對「布林運算子速記」高度收斂，本文第 4 節的符號選擇與它們一致；唯一刻意分歧處是「隱含 AND」：

| 慣例 | Google / Gmail | GitHub Issues | MySQL FULLTEXT 布林模式 | 本文設計 |
|------|----------------|----------------|--------------------------|----------|
| NOT（排除） | `-term`（前綴、不留空白） | `-qualifier` | `-term`（僅前置） | `!` / `NOT`（**不用** `-`） |
| 片語 | `"..."` | `"..."` | `"..."` | `"..."` |
| OR | `OR` | `OR`（關鍵字高亮） | 空白（optional） | `OR` / `\|` |
| AND | 空白＝預設 | 空白＝預設（隱含 AND） | `+term`（required） | **僅** `AND` / `&`（空白＝字面） |
| 群組 | 有限 | `( )` 巢狀 | `( )` | `( )` |

> **刻意分歧**：Google/GitHub 用「空白＝隱含 AND」，本文**不採用**。原因：代碼表的欄位值常含空白（如多字人名、含空白的字串），若空白變 AND，會破壞既有 `filters[col]=王 安` 的字面比對語義與既有連結（見 2.1）。代價是 Google 式 `-a b` 不能省略連接子，須明寫 `-a AND b`。
>
> **NOT 為何用 `!` 而非 `-`**：Gmail/GitHub 用 `-` 排除（且須緊貼詞），但它們的內容很少出現連字號。本專案的代碼表充斥 `-987`、`1023-1025` 這類含 `-` 的值，沿用 `-` 會製造大量誤判與特例。改用 `!` 作前綴運算子（同樣「緊貼詞、僅前綴」原則），即可徹底迴避，`-` 全部回歸字面。

### 8.2 優先序：採 `NOT > AND > OR`（與 GitHub 一致）

- GitHub Issues 重建布林搜尋時明確採「**AND 比 OR 結合更緊**」，本文的優先序與之相同；惟 GitHub「省略運算子時隱含 AND」本文**不採用**（見 8.1，空白＝字面）。
- 注意分歧：PHP 套件 `DuncanWilder/BooleanSearchParser` 的優先序是 `OR > AND > NOT`（與主流相反）。**本文採主流的 `NOT > AND > OR`**，不沿用該套件的順序。

### 8.3 關鍵技術選型：LIKE 自建解析器 vs. MySQL FULLTEXT 布林模式

這是最重要的決策。資料庫原生有現成方案 `MATCH(col) AGAINST('+a -b' IN BOOLEAN MODE)`，運算子（`+ - " * ~`）開箱即用、有索引時很快。**但對本專案不適用**，原因有二，都是硬傷：

1. **SQLite 相容性（AGENTS.md 硬性要求）**：`MATCH ... AGAINST ... IN BOOLEAN MODE` 是 MySQL/MariaDB 專屬語法，SQLite 完全不支援。測試跑 SQLite 會直接壞掉，違反「所有查詢必須同時相容 MariaDB/MySQL 與 SQLite」。
2. **中文（CJK）分詞問題**：MySQL 內建全文解析器以空白斷詞，對中文無效，必須改用 `ngram` 解析器，且：
   - 需要為每個可搜尋欄位**新增 FULLTEXT 索引**（schema migration，每張代碼表都要動）。
   - `ngram_token_size` 會影響可搜尋的最短詞長；萬用字元（`*`）在 ngram 索引上「行為非預期」（官方明載）；布林模式下短於 token size 的詞會被轉成 ngram 片語，結果常不直覺。

**結論（建議）：維持 LIKE-based 自建解析器**（本文第 3、6 節）：
- 對中文子字串天然可用（`col LIKE '%蘇軾%'` 不需斷詞、不需索引）。
- 不需 schema 變更，SQLite/MariaDB 都能跑，測試一致。
- 代價：`%term%` 全表掃描、無相關性排序——但代碼表規模中小、且本就是這個性質，可接受。
- 取捨備忘：若未來要對**大表**做高效布林全文（例如 `CBDB__NAME_FTS`，本就已是 FTS 表），才考慮走 FULLTEXT/ngram，且需獨立評估 SQLite 測試替身。

### 8.4 現成 PHP 函式庫：可借鏡語法，但不直接採用

| 函式庫 | 產出 | 是否可用 |
|--------|------|----------|
| `pecee/boolean-query-parser`（`DuncanWilder/BooleanSearchParser` 的維護分支） | MySQL 布林模式字串（`+a -b`） | ❌ 產出是 FULLTEXT 字串，非 `WHERE LIKE` 樹；且需 FULLTEXT 索引 |
| `yonaka/SearchWordsSQL` | Google 式 → SQL 布林 + IN BOOLEAN MODE | ⚠️ 部分概念可借鏡 |
| `aripap/php-boolean-parser` | Postgres 全文輸入的解析/驗證 | ⚠️ 解析思路可參考 |

> 這些套件都把布林**轉成全文檢索字串**，而非我們要的「巢狀 `where`/`orWhere` + NULL-safe NOT」的 LIKE 條件樹，因此**仍需自寫小型解析器**（第 6 節）。可借鏡其文法與「不過度修正錯誤輸入」的務實態度；但**本文的失敗處理不同**——不靜默回傳 null/轉字面，而是標記該欄錯誤並略過 filter（決策 #14），避免無聲改變查詢語意。

### 8.5 解析技術與 UX 借鏡

- **解析技術**：GitHub 用 PEG（Parslet）建文法 → AST → 遞迴轉成後端查詢（AND→must、OR→should、NOT→should_not）。本文文法簡單，**遞迴下降**或 **shunting-yard** 皆可，產出巢狀 `where` 閉包即可。
- **巢狀深度上限**：GitHub 將巢狀限制在 **5 層**（「在實用與可用性之間的甜蜜點」）。本文比照設深度 ≤ 5，超限視為解析失敗（標記錯誤、略過該欄，見 3.4）。
- **UX**：GitHub 的做法是「**高亮 AND/OR 關鍵字** + 篩選詞自動補全」，而非送出前的即時語意預覽。本文據此定案（決策 #12、見 9.2）：**不做送出前即時預覽**（避免前後端兩份 parser 漂移），改為「**唯讀語法範例 `<code>`** + 送出後由後端權威回填每欄語義說明」。

---

## 9. 設計決策總表（全部已定案）

| # | 決策 | 結論 | 出處 |
|---|------|------|------|
| 1 | 頁面級布林開關 | **預設關閉**；關閉時行為與現狀完全相同，開啟才解析 | 2.2 |
| 2 | 空白是否為運算子 | **否**，一律字面（含空白字面值向後相容） | 2.1 |
| 3 | 隱含 AND | **無**；相鄰 operand 須明寫 `AND`/`OR`，否則視為語法錯誤（見 #14） | 3.2 |
| 4 | NOT 速記符號 | 用 `!`（與 `NOT`）；**不用 `-`**，`-987`/`1023-1025` 一律字面 | 3.4 |
| 5 | 括號 `( )` | **支援**，可覆寫優先序；字面括號用引號 `"(號)"` 保護 | 3.2、5 |
| 6 | 全半形正規化 | 布林模式下全形運算字元（含 `（）`）正規化為半形；關閉時全字面 | 3.4 |
| 7 | 優先序 | `NOT > AND > OR`，同級左結合 | 3.2、8.2 |
| 8 | 逗號 `,` 作 OR 別名 | **不啟用**（代碼表內容常含逗號，避免歧義） | — |
| 9 | 技術選型 | LIKE 自建解析器，**不用** FULLTEXT 布林模式 | 8.3 |
| 10 | 隱藏 `pinyin`/`CBDB__TRAD_SIMP_MAP`/`CBDB__NAME_FTS` | **只從清單/側邊欄隱藏**（新增 `codes.ui_hidden`），**直連 `/codes/{table}` 仍可用**，**不刪** `codes.tables` | 9.1 |
| 11 | 跨欄位運算子 | **不做**；欄位之間固定 AND | 1、2 |
| 12 | UI 呈現 | 開關旁語法說明（details/summary 可鍵盤）**必做** + **唯讀範例 `<code>`**（非可點、無 JS）；**不做送出前即時預覽** | 2.2、9.2 |
| 13 | NOT 的 NULL-safe | `NOT` 一律 De Morgan **下推到葉節點**，葉子皆 `(col IS NULL OR col NOT LIKE ?)`，**絕不出現裸 `NOT(...)`** | 3.3、6 |
| 14 | 布林模式解析失敗 | **不靜默轉字面**；該欄標記語法錯誤並略過 filter（僅關閉模式才走純字面） | 3.4、6、9.2 |
| 15 | 游標分頁大表（`CBDB__NAME_FTS`） | 後端**硬短路**，無條件拒絕逐欄/布林 filter 與 sort（不只靠 ui_hidden） | 2.3 |
| 16 | 行動版 | 布林為**桌面功能**；行動版隱藏開關、不提供布林，維持現狀字面 filter | 2.2 |
| 17 | 送出與語義回填 | 回車/Apply 送出整個查詢；**送出後**由後端權威解析回填每欄語義說明與錯誤（單一 parser，無前後端漂移） | 9.2 |
| 18 | 測試前置（blocker） | 布林 Feature test 開**新 test class、用真 SQLite memory**（不沿用全域 swap fakeDb 的舊 class）；升級 `FakeQueryBuilder` 僅作備案 | 9.3 |
| 19 | 解析失敗欄位的資料流 | 分 `$appliedFilters`（餵 query/分頁/排序連結）與 `$rawFilters`（回填/錯誤），**錯誤欄位不得進翻頁/排序連結** | 9.2 |
| 20 | 行動版判定 | CSS 斷點 `md`（768px）隱藏開關；後端 viewport 無感，分享的 `?filter_bool=1` 連結在手機仍生效屬已知可接受行為 | 2.2 |

### 9.1 `ui_hidden` 規格（決策 #10 的落地）

只從 codes **清單**隱藏三張表，**直連 `/codes/{table}` 仍可用**（已定案，2026-06-12），且**保留**其在 Query Playground / NL / MCP / schema 的白名單身分（避免附錄 A 的副作用）。

> **為何不 404 直連（解 codex High 矛盾）**：`CBDB__NAME_FTS` 的游標瀏覽頁就是**為它建的**，若 `guardTable()` 404 會讓該頁完全停用，且與 §2.3「短路後永遠走游標路徑」互斥。故 `ui_hidden` **不** 404；它只是「不出現在清單」。`CBDB__NAME_FTS` 直連可達、由 §2.3 短路保護（拒 filter/sort、永遠 cursor）。兩節因此一致、無矛盾。

- **新增設定**：`config/codes.php` 增加 `ui_hidden` 陣列：
  ```php
  'ui_hidden' => ['pinyin', 'CBDB__TRAD_SIMP_MAP', 'CBDB__NAME_FTS'],
  ```
- **`config/codes.php` 的 `tables` 不動**（QP/NL/MCP/schema 照舊可用）。
- **消費點（僅一處）**：`CodesRepository::codes()`——產生 `/codes` 首頁清單時，濾掉 `ui_hidden` 內的表。**`guardTable()` 不動**（不 404，直連仍可達）。
- **側邊欄不受 `ui_hidden` 影響（minor 修正）**：`resources/views/layouts/sidebar-v3.blade.php` 的代碼表選單是**硬編碼 markup**，`codes()` 只供 `/codes` 首頁；側邊欄本就只列 8 張固定表、不含這三張，確認即可，不需也不會被 `ui_hidden` 過濾。
- **大小寫對齊（C5）**：`codes()` 的清單是「表名 => 說明」關聯陣列。比對 `ui_hidden` 時兩邊都先 `strtoupper`（含 `ui_hidden` 自身元素），確保 `pinyin` 這種小寫表名也一致命中。
- **共用白名單的真正來源是 `config('codes.tables')` 本身**（QP/NL/MCP/schema 都是直接 `array_keys(config('codes.tables'))`，見附錄 A），因此**保留 `tables` 不動即維持白名單完整**，與 `ui_hidden` 無關。
- 釐清（codex review 指正）：`CodesController` **並未**使用 `CodesRepository::allowedTableMap()`（建構子直接用 `allowedTables()` 自建 map）；`allowedTableMap()` 內有 MySQL-only 的 `SHOW TABLES`，**不要當跨 DB 基礎設施依賴**。`ui_hidden` 只需動 `codes()`。
- **測試**：驗證 `ui_hidden` 的表**不出現在 `/codes` 首頁清單**、但**直連 `/codes/{table}` 仍可達**、且仍在 `config('codes.tables')` 中。

### 9.2 送出、語義回填與錯誤呈現（決策 #12 / #14 / #17 的落地）

定案：**不做送出前即時預覽**（避免前端重做一份 parser、與 `ColumnFilterExpression` 漂移）。流程改為：

1. **送出**：沿用現狀的回車送出（使用者可邊寫邊回車、也可寫完一起送）；布林模式下整頁 GET 帶 `filter_bool=1` + 各欄 `filters[col]`。
2. **後端權威解析 → 兩套集合（M4/M9，關鍵資料流）**：`show()` 對每欄解析，分流成：
   - **`$appliedFilters`**（有效欄位）：解析成功並實際套用到查詢者。**只有這套**用來餵 query、組**分頁/排序連結**與 offset 的 `appends`（**不要**再用 `$request->except('page')`，否則會把壞欄位回灌每頁 URL）。
   - **`$rawFilters`**（原始輸入）：每欄使用者原樣輸入，**只**用於回填輸入框與錯誤回顯。
   - **`$filterErrors[col] = error_code`**：解析失敗的欄位與錯誤碼。
   這樣「點排序/翻頁」不會把語法錯誤的壞值一直帶著走、也不會每頁重複報錯。
3. **送出後回填（取代即時預覽）**：view 對每個成功欄位顯示「**等價人話描述**」（由後端用同一棵 AST 產生，權威、不漂移）。回填的 term 值**一律 escape**（見 §7 M7）。
4. **錯誤呈現（B10）**：
   - 失敗欄位輸入框加 `is-invalid` + `invalid-feedback` + `aria-invalid`，顯示該欄錯誤原因。
   - toolbar 顯著處彙總警示「**有 N 個欄位的篩選因語法錯誤未套用**」並列欄名——「略過某欄」會讓結果集**變大**（尤其被略過的是 `NOT`），必須讓使用者有感。
5. **無障礙（C8）**：`?` 說明可鍵盤開啟、label 與輸入框關聯、彙總警示用 `role="alert"`。
6. **新增 view 變數（M8，兩個 return 分支都要傳，否則 undefined）**：`$booleanEnabled`、`$appliedFilters`、`$rawFilters`、`$filterErrors=[]`、`$filterDescriptions=[]`。**offset 與 cursor 兩個 `return view()` 點都要傳齊**（cursor 短路時：`$booleanEnabled=false`、`$filterErrors=[]`、`$filterDescriptions=[]`），對齊 `CODES_FILTER_SORT_PLAN.md §7`「兩分支傳值一致」。
7. **i18n 與文案（M10/M11/M12）細節在實作期落地，見第 10 節清單**（AST→中英人話映射表、逐類錯誤碼對應 i18n key、完整 key 表）。

### 9.3 測試前置條件（B1，blocker，動工第一件事）

`tests/Feature/CodesControllerTest.php` 的 `FakeQueryBuilder` 目前把 `where(Closure)` 攤平進單一條件陣列、`rowMatches()` 左到右 fold、**無子群組、無 `whereNull`/`orWhereNull`、`matchCondition` 無 `not like` 分支**。因此布林產生的巢狀 where 與 NULL-safe NOT 在替身上會被**錯誤求值**（`(A OR B) AND (C OR D)` 會被當 `A OR B AND C OR D`），導致回歸測試**假性通過**。

**注意（codex review 補強）**：`CodesControllerTest::setUp()` 目前**全域** `DB::swap($fakeDb)` 並把 controller/repository binding 換成 fake 版，整個 class 都在 fake DB 上跑。所以「改用真 SQLite」不是加幾條測試就好，需要**拆一個獨立 test class**（或條件化 `setUp`，布林相關案例不 swap fakeDb、改連 SQLite memory）。否則會以為在跑 SQLite、其實仍在跑 `FakeQueryBuilder`。

動工前二擇一：
- **(b) 推薦**：布林相關 Feature test 開**新 test class**、用**真 SQLite memory** 連線（建臨時表、塞資料、斷言 `(A OR B) AND (C OR D)`、NULL-safe NOT、群組否定的實際列），最能驗證跨 DB 行為，且繞開 fake 替身的表達力限制。
- (a) 備案：升級 `FakeQueryBuilder`——`where(Closure)` 建獨立子節點（**不可攤平**）、`rowMatches` 改**遞迴**求值、補 `whereNull/orWhereNull` 與 `matchCondition` 的 `not like` 分支。工作量比想像大（尤以「閉包子群組須獨立節點」最關鍵），易做半套，故僅作備案。

附錄 B「測試」列以 (b) 為準。

---

## 附錄 A：`config('codes.tables')` 的共用情況（佐證決策 #10 / 9.1）

| 使用處 | 用途 | 從 `tables` 刪表的影響 |
|--------|------|------------------------|
| `CodesRepository::codes()` | `/codes` 首頁清單 | 從清單消失（**ui_hidden 想要的效果**；直連不受影響） |
| `QueryPlaygroundController.php:116` | 唯讀 SQL 表白名單檢查 | 該表 SQL 被拒（副作用） |
| `QueryPlaygroundService::getQbeTables()` | QBE 可選表清單 | QBE 不再列出（副作用） |
| `NaturalLanguageQueryService` / `NlQueryToolsService` | NL 查詢可用表 | NL 不再認得該表（副作用） |
| `config/mcp.php:12` | MCP `allowed_tables` 預設 | MCP 唯讀查詢拒絕（副作用） |
| `DatabaseSchemaService` | 給 LLM 的 schema prompt | schema 不含該表（副作用） |
| `operations/index.blade.php:126` | 代碼表連結判斷 | 連結判斷改變（副作用） |

**結論：** `tables` 不是 codes-only。「只從 codes 頁面隱藏」應走 `ui_hidden` 清單，而非刪除。

---

## 附錄 B：相關檔案

| 檔案 | 角色 |
|------|------|
| `app/Http/Controllers/CodesController.php` | `show()` 算 effective `filter_bool`、FTS 短路、`$appliedFilters`/`$rawFilters`/`$filterErrors` 分流、接入解析器；`guardTable()` **不動**（ui_hidden 不 404） |
| `app/Support/ColumnFilterExpression.php` | tokenizer + parser + applyToBuilder（節點/葉子閉包包裝、上限）+ `describe()`（AST→人話）+ `ERROR_CODES` const，**已實作** |
| `resources/views/codes/show.blade.php` | 布林開關 switch（CSS md 斷點桌面限定）、`?` 語法 popover、範例 chip、逐欄錯誤標記（`is-invalid`）、送出後語義回填（escape）、彙總警示；search form / filter-form / reset 補 `filter_bool` |
| `config/codes.php` | **新增** `ui_hidden`；可選 `boolean_filter_enabled` kill-switch；`tables` 不動 |
| `app/Repositories/CodesRepository.php` | `codes()` 套用 `ui_hidden` 過濾（strtoupper 對齊）；`allowedTables()` 維持完整 |
| `resources/lang/zh-TW/codes.php`、`resources/lang/en/codes.php` | 布林開關 / 語法說明 / 各類解析錯誤 / 語義連接詞字串（i18n key 總表見第 10 節，禁硬編碼） |
| `tests/Feature/`（**新 test class、真 SQLite memory**，見 9.3） | 布林過濾、NULL-safe NOT（**群組否定** De Morgan + NULL 列）、**混合優先序** golden（`A AND B OR C`）、括號巢狀閉包邊界、`!` 位置（句首/term 後/`a!b`）、`& \| !`/括號字面需引號、超限、解析失敗**不轉字面**且標記錯誤、`$appliedFilters` vs `$rawFilters`（壞欄位不進分頁/排序連結）、開關有效值優先序、`CBDB__NAME_FTS` filter/sort **硬短路**且不殘留 URL、`ui_hidden` 不在首頁清單但**直連可達**且仍在 `codes.tables` |
| `tests/Feature/CodesControllerTest.php`（既有，全域 swap fakeDb） | 不在此 class 加布林案例；如選備案 (a) 才升級 `FakeQueryBuilder`（巢狀群組/`whereNull`/`not like`） |
| `docs/CODES_FILTER_SORT_PLAN.md` | 既有逐欄 filter / sort 設計（本文的基礎） |

---

## 10. 實作落地對照（Phase 1–4 已完成）

第二輪 review 列出的「規格細節」項，實作時的最終落地如下：

1. ✅ **AST→人話（M10）**：`ColumnFilterExpression::describe($ast, $labels)` 實作，雙重否定化簡對齊查詢；片語由 i18n `codes.filter_desc_*` 注入；golden 見 `ColumnFilterExpressionTest::testDescribeRendersHumanReadable`。
2. ✅ **逐類錯誤碼 + i18n（M11）**：`ColumnFilterParseException::$errorCode`，全部碼集中於 `ColumnFilterExpression::ERROR_CODES`，對應 `codes.filter_err_*`（zh/en）；`ColumnFilterExpressionTest::testEveryErrorCodeHasLocalizedMessageInBothLocales` 鎖住中英同步；blade `$filterErrMsg` 對未定義碼退回 `filter_err_unknown`。
3. ✅ **i18n key（M12）**：`resources/lang/{zh-TW,en}/codes.php` 的 `advanced_filter*` / `filter_chip_*` / `filter_errors_heading` / `filter_applied_label` / `filter_err_*` / `filter_desc_*`，中英對齊。
4. ✅ **語法範例（B11）**：`codes.filter_chip_examples`（i18n 陣列，zh 中文例、en 拼音例，全用 ADDRESSES 宋代真名）以**唯讀 `<code>`** 展示（非可點按鈕、無 JS——範例是給人閱讀的，不是動作）。
5. ✅ **全半形正規化位置**：只在 `ColumnFilterExpression::normalizeFullWidth()`（tokenizer 內），不碰 `sanitizeColumnFilters`；關閉模式不解析故不改寫。
6. ✅ **單一正向 term 等價**：布林開啟的正向葉子為 `where(col,'like','%value%')`，與關閉路徑一致；`sanitizeColumnFilters` 已 trim。
7. ✅ **實作 phase**：
   - **Phase 1**：`ui_hidden`（`config/codes.php` + `CodesRepository::codes()`）+ 測試。
   - **Phase 2**：`App\Support\ColumnFilterExpression` + `ColumnFilterParseException` + 真 SQLite 測試。
   - **Phase 3**：`CodesController::show()` 接開關 / FTS 短路 / `$appliedFilters` 分流 + Feature 測試。
   - **Phase 4**：blade 開關 UI / `?` 說明（details/summary，可鍵盤）/ chip / 逐欄錯誤（`is-invalid` + `aria-describedby`）/ 語義回填 + i18n。
   - **Phase 5**：`php-cs-fixer`、跑測試（blade 為伺服器渲染，本功能未動 Vite 資源故不需 `npm run build`）。

> 後續可選的非阻擋增強：`?` 說明改 popover/modal 並加 `aria-expanded`/`aria-controls`；補一條 `withSession(['locale'=>'en'])` 的英文語境 view 測試。
