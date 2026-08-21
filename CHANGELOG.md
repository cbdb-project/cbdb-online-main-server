# Changelog

本檔案改為維護近階段的重要變更與產品方向，不再保留完整歷史流水帳。較舊的大型升級請參考 `docs/` 下專門文檔。

## 2026-08

### LLM 產出的 Markdown 統一改用共用渲染器（Query Playground QA／NL 查詢／NL 查詢日誌／AI 代碼查詢）
- 起因：`/app/query-playground?mode=qa` 的回答與 `/app/query-playground/nl-query-logs` 的 LLM Response 都是 Markdown，但前者靠 `HistoricalQaPanel.tsx` 裡一個手刻的正則渲染器、後者根本沒渲染。實測手刻版對一段典型回答的輸出：有序清單 `1. ` 原樣輸出（只認 `^[-*] `）、`[文字](網址)` 完全不處理、巢狀清單的子項會**漏到 `<ul>` 外面**變成裸文字，最嚴重的是「`

` → `</p><p>`」那一輪跑在程式碼區塊**之後**，於是 ``` fence 內只要有空行就會被插進 `</p><p>`、換行又被重複轉成 `<br/>`——程式碼區塊等於整塊壞掉。區塊元素也一律被包進最外層 `<p>`（`<p><h4>`、`<p><table>`），是無效 HTML。
- 新增 `resources/js/inertia/utils/markdown.ts`（marked 18 + DOMPurify 3）與 `components/ui/Markdown.tsx`，並刪掉手刻渲染器（HistoricalQaPanel 735 → 645 行）。排版樣式在 `resources/css/inertia.css` 的 `.cbdb-markdown`，色彩全走語意 token，深色模式自動翻轉。
- **內容視為不可信輸入**：LLM 回答可經由使用者提問與資料庫內容被提示注入誘導，因此設兩道獨立防線——(1) marked 的 `html` renderer 把區塊級（`Tokens.HTML`）與行內（`Tokens.Tag`）的原始 HTML 全部逸出成文字，`link` 限制協定（僅 http/https/mailto 與站內相對路徑）並強制 `rel="noopener noreferrer nofollow"`，`image` 退化成替代文字（不對第三方主機發請求）；(2) DOMPurify 白名單再過一次。**兩道刻意不互相依賴**：第一道已足以讓所有安全測試變綠，所以特地把 `sanitizeHtml` 匯出、直接餵 HTML 做測試——實測把它換成 identity 後有 10 條測試變紅（改之前是 0 條，等於第二道防線完全沒被覆蓋）。
- **`class`／`style` 不放行**：Markdown 語法產不出有意義的 class，而本站是 Tailwind 且無 CSP，放行 `class` 等於送出 `fixed inset-0 z-50` 這種可覆蓋整頁的版面原語。`align` 放行是為了 GFM 表格對齊，CSS 端相應改成 `th:not([align])`——`align` 是呈現性屬性，層疊優先級低於作者樣式，原本的 `text-align:left` 會把每一欄都壓回靠左。
- **GFM 任務清單改用 ☑／☐ 符號**：`<input type="checkbox">` 不在白名單內會被整個拿掉，導致「已完成」與「未完成」渲染後長得一模一樣（資訊遺失），故改在 `checkbox` renderer 輸出符號。
- **`href` 用另一套逸出、不動 `&`**：網址裡的 `&` 是合法的查詢字串分隔符，比照內文再逸出一次會讓 `?a=1&b=2` 變成 `?a=1&amp;amp;b=2` 而連到錯的位址。
- CSS 包在 `@layer components`：要贏過 Tailwind preflight 的 `ol,ul{list-style:none}`，但必須輸給 utilities，否則呼叫端用 `className` 傳進來的 Tailwind class 會被無聲蓋掉（元件用 `cn()` 合併 class，理應 utilities 優先）。
- 接上的呼叫點（逐一確認欄位是否真的可能含 Markdown，不是全部套上去）：
  - `HistoricalQaPanel`：`answer_markdown`（區塊）、`caveat`／`evidence[].label`／`evidence[].detail`（行內）。
  - `NlQueryPanel`：`explanation`（行內）。
  - `Admin/NlQueryLogs`：頂層 `explanation` 欄（區塊），以及 `llm_response` 展開後的欄位。**QA 與 NL→SQL 共用 `nl_query_logs` 表**（QA 的 `question` 前綴 `[QA] `），所以這頁本來就會渲染完整的 QA payload。
  - `AiCodeLookupPanel`：`summary`（區塊；`CodeLookupService` 是另一個 LLM 功能）。
- **日誌頁採「指定 key 白名單」而不是一律渲染**：`JsonValue`／`JsonTable` 原本不把 key 往下傳，若對每個字串葉節點都套 Markdown，`sql`／`sql_used` 會被打壞——區塊模式下單獨一行 `---` 會把前一行變成 `<h2>`（`SELECT a | b FROM t` 後面接 `---` 實測就是這樣），字串裡出現**成對**的 `*`（如 `LIKE '%*%' ... LIKE '%*%'`）會被吃成斜體，`tool_calls[].function.arguments` 的 JSON 字串同理。因此把 key 串進 `JsonValue`，只有 `answer_markdown`（區塊）與 `summary`／`caveat`／`explanation`（行內）會被渲染，其餘維持 `MultilineText`。原始 JSON 切換鈕保留，管理員隨時能看未處理的位元組。
  - 附帶一提，CBDB 的識別碼本身是安全的：CommonMark 禁止詞中 `_` 強調，所以 `BIOG_MAIN`、`c_name_chn`、`POSTED_TO_ADDR_DATA` 實測都原樣輸出。真正會被吃掉的是一個字串裡出現成對的 `*` 或前後包夾的 `_`。
  - **`label`／`detail` 刻意不在白名單內**：這裡比對的是裸 key，而 `label` 會誤中 tool_results 的 `result_summary.preview[].label`——那是原始資料庫值，且 SQL 由模型自己寫，`SELECT c_name_chn AS label` 就足以把任意欄位值送進來，歷史人名裡的 `*`／`_` 會被靜默吃掉。QA 面板的 `evidence` 直接來自模型 JSON、不經 tool preview，所以在面板裡仍以行內模式渲染。
  - **無 key 的頂層內容依模式分流**：`choices[0].message.content` 解析不出 JSON 時會退回原文，此時沒有 key 可依據。QA 模式的 `parseQaResponse` 會把原文整段當成 `answer_markdown`（確實是 Markdown 回答），但 NL→SQL 模式的同一條路徑可能是模型直接吐出的裸 SQL——**codex 覆核指出這條會繞過整個 key 白名單**。因此後端 `prepareNlLog()` 多回一個 `is_qa`（依 `question` 的 `[QA] ` 前綴判定，判定集中在後端、不讓前端各處自己比對字串），只有 QA 模式才渲染。
  - 判斷條件抽成純函式 `utils/nlLogMarkdownFields.ts`（7 tests）：這是整個改動裡最容易寫反的一段——**預設分支（原樣顯示）才是安全的那一邊**，任何「多渲染一點」的改動都會默默打壞 SQL，而它埋在 `.tsx` 裡沒有元件測試環境可以釘。後端那條 `is_qa` 也補了測試，並實測把 `str_starts_with` 放寬成 `str_contains` 會讓它變紅。
  - 中間版本曾讓陣列元素沿用父層 key（`inheritedKey`），**review 後移除**：QA 契約裡六個白名單 key 全是字串、沒有一個是陣列，所以它對真實 payload 完全沒作用；而它唯一可達的效果恰好是反的——會讓白名單 key 底下的陣列元素被套上 Markdown。`sql_used[#0]` 本來就落在安全的預設分支，不需要這層。
- **建議追問（`suggested_follow_ups`）刻意不渲染**：那些字串會被 `onPickSuggestion()` 原樣塞進提問輸入框，渲染後標籤會與實際送出的值不一致。
- **兩處容器底色與 Markdown 元素撞色**，都是開始真的渲染之後才會顯形（舊路徑是純文字，沒有 `code`／`pre`／`th` 可撞）：`caveat` 由 `--muted` 改成 `--surface-sunken`（`--muted` 正是 `.cbdb-markdown code` 的底色）；答案卡片由 `--surface-sunken` 改成 `--card`（`--surface-sunken` 正是 `.cbdb-markdown` 的 `pre` 與 `th` 底色，程式碼區塊與表頭會融進卡片）。
- 測試 `resources/js/inertia/utils/markdown.test.ts`（32 tests，檔首 `// @vitest-environment jsdom` 單檔覆寫；DOMPurify 需要 window，另外兩個純函式測試維持 node 環境）：逐條釘住舊渲染器的具體失效（有序清單、連結、巢狀清單、程式碼區塊不得含 `<br>`／`<p>`、區塊元素不得被包進 `<p>`），以及兩道防線各自的行為。
- **未動 API.md**：沒有新路由，也沒有改任何請求／回應欄位——這次全部是既有欄位在前端的呈現方式。
- **舊版 Blade `query_playground/nl_query_logs.blade.php` 未同步**（按 AGENTS.md「新功能只做在 React/Inertia」）。注意該路由 `routes/web.php` 的 `query-playground/nl-query-logs` **沒有 migration flag、也不會轉址**，直接 render Blade 視圖，所以 super admin 走舊網址仍會看到未渲染的 `explanation`。

### 新增 `KINREL_REDUCTION`（親屬關係化簡規則表）
- 需求：把 CBDB 團隊維護的親屬關係化簡規則（來源試算表 `KINREL_REDUCTION.xlsx`，8 筆）落進資料庫，並接上全站既有的「代碼表」機制。規則語義是把複合親屬關係字串（`KINSHIP_CODES.c_kinrel`，如 `BB`＝兄弟之兄弟）逐步化簡到等價最簡關係（`B`），同時調整四個親屬距離步數；它是 `KINSHIP_CODES.c_kinrel_simplified` 的規則來源。
- **Schema 沿用 CBDB 慣例而非 Laravel 預設**：全大寫表名、`c_` 前綴欄名、MySQL 端顯式指定 `utf8mb4_general_ci`（`config/database.php` 的預設是 `utf8mb4_unicode_ci`，但 CBDB 全庫是 general_ci，見 `2025_11_20_100000_alter_admin_cat_tables_collation`）、`ENGINE=InnoDB ROW_FORMAT=DYNAMIC`，與同族的 `KINSHIP_CODES`／`KIN_MOURNING`／`KIN_MOURNING_STEPS` 對齊。
- **主鍵是 (`c_kinrel_target`, `c_sex`) 而不是單欄**：同一個待化簡關係在不同 ego 性別下可以有不同替換結果（現行 8 筆全是 `B`＝不分性別，但規則表本身要能承載 M／F 分歧）。`CodesController` 的主鍵是從 live schema 反射的，所以不需要在 `CompositePrimaryKey::SCHEMAS` 另行登錄；有測試釘住反射結果就是這兩欄——若主鍵沒建起來，controller 會靜默退化成「拿前兩欄當 key」。
- **四個步數欄用有號 `smallInteger`**：化簡的語義就是減步數，現行資料 `c_col_change = -1`。若寫成 unsigned，`-1` 在 MariaDB 上會 out of range 報錯或被截成 0。**這條的測試方式被 review 改掉了**：原本寫「在 SQLite 插一筆 -1 再讀回來」，但 `SQLiteGrammar::$modifiers` **沒有 `Unsigned`**，所以把欄位改成 `unsignedSmallInteger()` 之後那條測試照樣全綠——它剛好在唯一要防的缺陷上失效。改成把 migration 的欄位定義抽成 `defineTable(Blueprint)`，測試用 **MySQL 的 schema grammar** 編譯**同一份定義**（`DB::connection('mysql')` 是 lazy 的，`Blueprint::toSql()` 全程不需要 PDO，CI 的純 SQLite 環境照樣能跑）並斷言 DDL 裡是 `smallint not null`、整段不含 `unsigned`。實測把欄位改成 unsigned：新斷言紅、舊的 SQLite 來回測試綠——正是 `feedback_sqlite_hides_missing_column` 那類坑。同一條路徑也順便釘住 `utf8mb4_general_ci` 與複合主鍵真的有進 DDL（COMMENT／COLLATE 在 SQLite 上同樣隱形）。
- **`up()` 刻意**不加** `Schema::hasTable()` 守衛**：`up()` 是「CREATE ＋ ALTER ＋ INSERT」三段而 MySQL 的 DDL 不進交易，中途失敗會留下「表存在、`migrations` 沒那一列」的半套用狀態，之後每次 `migrate` 都撞「Table already exists」。中間版本曾加過守衛讓重跑安全，**codex 覆核後移除**：它把「大聲失敗」換成了更糟的「靜默成功」——表若已存在（例如 DBA 先照試算表建了一張 schema 不同的表），migration 會被記成已套用，卻既沒驗證 schema 也沒寫入那 8 筆種子，而 `down()` 仍然會去 DROP 那張不是它建的表。半套用的 migration 應該要有人處理（`DROP TABLE` 後重跑），處置方式寫在 `up()` 的註解裡。種子也維持 `insert()` 而非 `insertOrIgnore()`：沒有守衛之後，`insertOrIgnore` 只剩遮蔽作用。
- **還原路徑要登錄在兩個地方**：這張表在泛用 `/codes` 介面是可寫的，所以編輯／刪除會產生 operations 列；而「還原」按鈕的顯示條件（admin ＋ opType 3/4 ＋ `can_compare`）**不看**主鍵登錄，漏登錄的症狀是按鈕出現、按下去卻 `restore_no_pk`。
  - `OperationsController::resourceKeyColumns()`：`buildKeyConditions()` 的主路徑（從稽核快照取主鍵欄）靠它。
  - `CompositePrimaryKey::SCHEMAS`：快照湊不齊主鍵欄時的**回退路徑**靠它。CodesController 寫的 `resource_id` 是 `_._` 格式（例如 `BB_._B`），而只有 `parseStoredResourceId()` 認得那個格式、且它在表沒登記 SCHEMAS 時直接回 `null`，接著退到按 `-` 切的舊格式解析——`BB_._B` 會被當成單一段，主鍵永遠湊不齊。實測拿掉這筆登錄，`parseStoredResourceId('BB_._B', 'KINREL_REDUCTION')` 回 `null`，新測試立刻紅。
  - （同族的 `KIN_MOURNING`／`EXTANT_CODES` 等兩處目前也都缺，那是既有問題，這次只補新表。另注意 opType 2＝直接更新本來就不在可還原範圍內，這不是這次的改動造成的。）
- **不加稽核欄／`timestamps`**：與同族代碼表一致；經 `/codes` 介面的寫入仍會留 operations ＋ `audit_log`。
- 註冊點（漏改任一處的症狀都是「靜默少一張表」，因此逐項寫了斷言）：
  - `config/codes.php` 的 `tables`——一鍵三用，同時決定 All Tables 列表頁、Query Playground 的 SQL 白名單（`QueryPlaygroundService` 直接 `array_keys(config('codes.tables'))`）與 MCP 在 `MCP_ALLOWED_TABLES` 未設定時的 fallback。
  - `resources/lang/{zh-TW,en}/codes.php` 的 `table_desc`（兩份必須同步）。
  - `app/Support/SqliteReleaseTables::PUBLIC_TABLES`（77 → 78 張）與 `scripts/export-daily-sqlite.sh` 的 `TABLES`——這兩份是「意圖」與「產物自檢」兩份清單，`SqliteReleaseAllowlistTest` 會逐項比對，漏改任一邊就紅。
  - `.env.example` 的 `MCP_ALLOWED_TABLES`。**部署備忘**：prod 若把這個變數顯式釘死在 `.env`（現行就是），config fallback 不生效，必須手動把新表加進去並 `php artisan config:clear && php artisan config:cache`，否則 MCP 讀不到這張表。
- **API.md 未動**，因為沒有 API 面的改變：沒有新路由、沒有改任何請求／回應欄位，也沒有把這張表登錄進 `config/code_table_writes.php`／`code_table_mutations.php`（前者只支援單欄主鍵，後者是拼音欄專用），所以 `/api/v2/mutate` 的可寫資源集合不變。
- `docs/DATABASE_SCHEMA.md` 是 `php artisan cbdb:generate-schema-docs` 從連線中的資料庫產出的，本次沒有重跑（需要 prod 連線）；下次重新生成時會自動含這張表。
- 回歸測試 `KinrelReductionTableTest`（18 tests）：直接 `require` migration 檔跑 `up()`／`down()`（不是在測試裡手搓同名表——手搓會讓「migration 在 SQLite 跑不起來」這類錯誤完全測不到）、欄位順序、複合主鍵、NOT NULL／nullable 分佈、8 筆種子資料逐格比對、MySQL DDL 的 signedness／collation／主鍵、同 target 不同 `c_sex` 可並存、operations 還原的主鍵欄，以及上述每一個註冊點。兩條刻意避開「斷言等於環境」的陷阱：MCP 那條斷言 `.env.example` 而不是 `config('mcp.cbdb.allowed_tables')`（後者取決於執行機器的 `.env`，會隨環境紅綠不定）；Query Playground 那條**真的送一次 `SELECT`** 到 `query-playground.run` 而不是再斷言一次 config（後者在「controller 改成讀別的清單」時仍會綠）。匯出腳本那條只比對 `TABLES=( ... )` 區塊，整檔比對會被註解裡的表名蒙過去。
- 順手把既有測試裡寫死的 `77` 去掉（`AssertSqliteReleaseScopeTest` 的 `--min-tables => 77` 改成 `count(SqliteReleaseTables::PUBLIC_TABLES)`，其餘敘述改成不綁具體數字），這樣下次再加表就不會留下一個「還是綠、但已經不代表完整清單」的字面值。

### 註冊／忘記密碼／重設密碼補上限流，並修好停用中的 password-reset 節流（#1264）
- 起因：`POST /register`、`POST /password/email`、`POST /password/reset` 三條未認證端點**完全沒有限流**（`web` 群組沒有 throttle，`Auth::routes()` 也不在任何群組裡），而每次請求都有成本：register 至少一次 `unique:users` 查詢、成功還會建一列待管理員啟用的帳號；`password/email` 會寫 `password_resets` 並**在請求執行緒內同步連 SMTP 寄一封信**（`QUEUE_CONNECTION=sync`）；`password/reset` 有 token 查詢與一次 bcrypt。對照組 `POST /login` 早就有 `ThrottlesLogins` 的 5 次／分鐘——缺的不是「登入相關端點的防護」，而是**只有登入那條有**。
- **最嚴重的一項是設定漏鍵**：`config/auth.php` 的 `passwords.users` 沒有 `throttle` 鍵，而框架取 `$config['throttle'] ?? 0`、`DatabaseTokenRepository` 又寫 `if ($this->throttle <= 0) return false;`，於是 `recentlyCreatedToken()` **永遠回 false**——同一個 email 可以被無限次觸發「查 users ＋ 重寫 password_resets ＋ 寄一封信」。補上 `throttle => 60` 後，第二次會由 broker 擋下（`passwords.throttled`），不再寄信也不再動 `password_resets`。（broker 這道在框架內部仍是「先查再寫」、不是原子的，不過上游的 per-IP 閘已把並發量壓住。）這條與新的 per-IP 閘是兩個維度：換 IP 繞不過它，換 email 繞不過 per-IP 那道。
- **「檢查 ＋ 計數」用 cache lock 串列化**：codex 覆核指出 `FileStore::increment()` 是**沒有上鎖的** read-modify-write，並發請求會讀到同一個計數再各自寫回同一個較小值（lost update），於是計數可以被持續壓在上限以下——那不是「略微超出上限」，是這道閘被大量繞過。改用 `Cache::lock()` 包住臨界區（file store 上就是真的 flock：`FileLock::acquire()` → `FileStore::add()` → `LockableFile::getExclusiveLock()`），**拿不到鎖時一律視為超額**（鎖名含 IP 與端點，會競爭的只有同一個來源的並發突發本身，合法使用者不會和自己搶鎖）。殘留限制寫進註解與文件：`file` store 是每個節點一份目錄，多節點部署要跨節點共享得改用 redis store。測試用「先把鎖佔住再送請求」驗證這條 fail-closed 路徑（並發本身無法在 PHPUnit 裡重現）。
- 新增 middleware `ThrottleGuestAuthRequests`（別名 `throttle.guest`），按來源 IP 每分鐘封頂，掛在三個 controller 建構子的 `->only(...)`（`POST /register` 沒有路由名稱，而「同 URI 後註冊覆蓋」在本專案已被 `McpEndpointMiddlewareTest` 列為壞味道）。三條端點各有獨立的桶——數值型 `throttle:N,1` 的 key 是 `sha1(domain|ip)`、不含路由，會與全站其他數值型 throttle 共用計數器（#1249 的教訓）。
- **上限按成本分別定，不是統一值**：`register` 30、`password/email` 5、`password/reset` 10。因為計數在 controller 之前，**被 validator 打回的請求也算一次**（密碼太短、confirmed 不符、email 已存在），真人常要重試 2～3 次；而機構 NAT 後面一整班同時註冊是 CBDB 的真實情境。review 指出第一版的 10 與「擋得住腳本、擋不到人」這句自我否證，數字與理由都已改成一致。
- **超額回應刻意分兩種形狀**：要求 JSON 的客戶端得到 429 ＋ `Retry-After`／`X-RateLimit-*`；瀏覽器與 Inertia 表單得到 302 導回表單頁 ＋ `email` 欄位錯誤（與登入被鎖定同一形狀）。這偏離了 issue 原本寫的驗收（「回 429 且帶 Retry-After」）：框架 `throttle` middleware 超額時回 HTML 429，而 Inertia 收到非 Inertia 回應會彈全螢幕 iframe modal，使用者看不到任何訊息、表單也不會停在正常狀態。也刻意不在 Handler 裡把 `ThrottleRequestsException` 對 Inertia 全域改寫——那會動到全站每一條 throttle 路由。
- 實作用 `ValidationException::withMessages()->redirectTo()` 而不是自己 `back()->withErrors()`，理由都是 review 實測出來的：(1) 回填表單要排除的敏感欄位清單改由 `App\Exceptions\Handler::$dontFlash` 單一真源決定——自己維護時漏了 `token`，於是被限流的 `POST /password/reset` 會把重設 token 明文寫進 session 檔（`SESSION_DRIVER=file`）；順手把 `token` 加進 `$dontFlash`，這同時修掉**任何**一次驗證失敗都會外流 token 的既有行為。(2) `back()` 依賴 Referer，沒有 Referer 時實測會導到**首頁**，flash 的欄位錯誤永遠不會被渲染——而測試環境預設不帶 Referer，等於當時全綠的測試跑的正是壞掉那條路徑。
- `$request->ip()` 完全取不到時 fail-open（與 #1254 的 `ThrottleFailedAuthentication` 一致）：這裡上限只有 5～30，若讓這種請求共用 `sha1('')` 一個桶，某個環境一旦取不到 IP 就會變成全站註冊與重設密碼一起被封。**注意這條救不到「前置 CDN 卻忘了設 TrustProxies」**——那種情形 `$request->ip()` 是 proxy 的位址（非空），所有使用者會塌進同一個桶，只能由部署端設定 TrustProxies 解決（codex 覆核指出我原本的註解把這兩件事混為一談）。應用層改為偵測到 `X-Forwarded-For` 而沒有受信任代理時記一行警告，讓這個設定漏洞可見。另外每個窗口只記一行 `Log::warning`（用 `Cache::add` 去重）——沒有訊號的話攻擊在 ops 眼裡只是一堆普通 302，但每個被擋的請求都記一行等於把攻擊流量放大進 log。
- 順手修 `RegisterController` 的一個既有 500：`institution` 完全不在 validator 規則裡，但 `create()` 直接讀 `$data['institution']`，於是任何沒帶該欄位的 POST 都會炸 `Undefined array key`＝HTTP 500。用 `nullable` 而非 `required`（欄位本來就可為 null，React 註冊頁也沒標必填），並補 `?? null`——`nullable` 規則不會補上缺少的鍵。同時拿掉 Blade 註冊表單那個**被 `novalidate` 抵銷、根本不會執行**的 `required`：它讓 flag=old 的真人留白送出就會撞上那個 500。
- 回歸測試 `GuestAuthEndpointThrottleTest`（28 tests）：三條端點各自會被擋、額度用完後**不再寄信也不再寫 `password_resets`**、每條端點與每個來源 IP 各有獨立額度、換送出的 email 或換 `X-Forwarded-For`／`User-Agent` 都拿不到新額度、窗口是一分鐘（`travel(30)` 仍被擋、`travel(61)` 恢復）、超額導回的是**表單頁**、密碼與重設 token 都不會被回填進 session、兩個語系的 `auth.throttle_requests` 都存在且含 `:seconds`、GET 表單頁與 `POST /login` 不受影響、middleware 恰好只掛在三個 POST 動作上。**其中「用真實 Inertia 標頭送出」那條是 review 逼出來的**：原本的測試只用 `post()`／`postJson()`，於是「對 Inertia 請求豁免限流」這種改動（生產路徑等於沒有限流）會完全隱形而 18 條測試全綠。
- 同一個 issue 的第二部分是兩條 API 端點：
  - **`GET|POST /api/operations/token`**（眾包舊通道拿 email＋密碼換**長期有效**的 `confirmation_token`）原本只有 `api` 群組共用的 600／分鐘＝每分鐘 600 次密碼嘗試、每次一發 bcrypt。改掛具名 limiter `crowdsourcing-token`，**兩個維度一起套**：per-email+IP 5 次／分鐘（擋針對某帳號猜密碼，比照 `ThrottlesLogins`）與 per-IP 20 次／分鐘（擋換帳號繼續猜——換 email 會換掉前一個桶，只有 IP 維度攔得住）。email 先做小寫與去空白正規化，否則 `A@x`／`a@x ` 會是不同的桶。不能用數值型 throttle：它的 key 是 `sha1(domain|ip)`、不含路由與上限值（#1249 的坑）。
  - 同時把該端點三條失敗路徑從 **200** 改成 **401**（帳密錯誤）與 **403**（帳號未啟用／非眾包身分）。原本全部回 200 ＋一段中文字串，於是「把 200 的 body 直接當 token 用」的既有客戶端會拿著「您的帳號與密碼輸入錯誤」當憑證，監控也看不出這裡正在被暴力破解。**body 文字刻意不變**（既有客戶端可能在比對字串），只補狀態碼；成功路徑（200 ＋ raw token）完全不動，有測試釘住。
  - **`POST /api/v1/user/login` 改為固定回 `410 Gone`、不再驗證任何密碼**。它從來不可能成功：帳密正確會轉發到早已不存在的 `oauth/token`（回 404，而且**驗證成功時還會先留下一個已登入的 session cookie**），帳密錯誤則呼叫一個根本不存在的 `failed()` → `BadMethodCallException` ＝ **500**。也就是說它是一條「永遠失敗、卻要付 bcrypt」的無節流密碼端點——與其修好失敗路徑讓它看起來還能用，不如明確下架並指向 Sanctum token。保留路由（而非刪除）是為了讓既有呼叫端拿到「已下架、請改用 Bearer token」而不是 404／500。`Api\ApiController` 裡只服務這條流程的四個死方法（`authenticateClient`／`authenticated`／`sendLoginResponse`／`sendFailedLoginResponse`）一併移除。
  - review 在這一環抓到一個**我自己引入的回歸**：limiter 的 closure 寫 `(string) $request->input('q')`，而 `q` 是陣列時 `(string)` 在 PHP 8 是 `E_WARNING`、被 Laravel 轉成 `ErrorException`＝**HTTP 500**；更糟的是那個 closure 跑在 limiter 解析 limits 的階段、**在 `hit()` 之前**，所以 `?q[]=a` 可以無限量產 500 與 stack trace 進 log 而完全不消耗額度。controller 自己本來就有 `is_string()` 守衛——閘門比它保護的程式碼還脆弱。已改成先判型別，並補測試（把守衛改回原樣會紅）。
  - 另外 review 實測出四種「改壞仍全綠」而補了測試：限流只掛 POST（既有客戶端用的是 GET，而所有功能測試都用 `post()`，等於換個方法就繞過整道閘；`the_route_uses_the_named_limiter` 原本還寫成覆寫而非累積，只斷言到最後一條匹配路由）、per-IP 的 key 塌成常數（＝全世界共用 20 次／分鐘）、per-email 的 key 不看 email（退化成第二個 per-IP 桶）或不看 IP（任何人都能把某個眾包帳號鎖到拿不到 token）、以及出貨設定值只釘 env 鍵名而沒釘數字（改成 99999 會夾回 600＝正好是這次要取代的舊上限）。順帶把「去空白」那半的假釘子講清楚：全域 `TrimStrings` 早就處理掉了，limiter 裡的 `trim()` 是縱深防禦、測試碰不到。
  - `POST /api/v1/user/login` 連 `guest` 一起拿掉（並改為不繼承 `ApiController`——它的建構子對所有 Api controller 掛 `guest`）：原本帶著 session 的請求會被 `RedirectIfAuthenticated` 導向 `/home`、看不到下架訊息，而文件寫的是「固定回 410」。現在訪客與已登入者都拿到 410，有測試釘住。
  - 回歸測試 `CrowdsourcingTokenEndpointTest`（14 tests）：具名 limiter 有掛、per-email 與 per-IP 兩個維度各自會擋、email 大小寫與空白正規化、被限流的請求**不再進 controller**（不再付 bcrypt、也不再寫 `audit_log`）、成功路徑仍回 200 ＋ raw token、失敗路徑的新狀態碼、上限夾範圍與出貨設定值、以及 v1 login 對「正確密碼／錯誤密碼／不存在的 email／空 payload」一律 410 且不留下 session。（那個 `audit_log` fixture 一開始欄位不全，而 `SecurityAuditLogger` 會吞掉寫入例外，於是「被限流不再寫審計」的斷言變成「證明了 0 筆」的假通過——已改成與真實 schema 一致。）
- **部署備忘**：prod 若跑過 `config:cache`，這次的 `config/auth.php` 改動必須 `php artisan config:clear && php artisan config:cache`，否則 `passwords.users.throttle` 會沿用舊快取＝per-email 節流仍然停用，等於這次修的一半沒生效。

### 帶錯 Bearer token 的認證失敗次數封頂（#1254）
- 起因：框架的 `$middlewarePriority` 把 `AuthenticatesRequests` 排在 `ThrottleRequests` 之前，所以掛 `auth`／`auth:sanctum` 的路由上，未認證請求會在認證階段就返回，路由自己的限流一條都不會執行。實測：把 MCP 上限調成 3、不帶 token 連打 8 次 → 8 個失敗、**0 個 429**。每一次帶 token 的失敗都會付一次 `personal_access_tokens` 查詢，所以那是「無限次、每次一個索引查詢」的路徑。
- **調查先把 issue 的判斷改小了**：全站約 96 條 `auth` 路由裡，只有 4 條**真的失去**一道原本存在的限額（`GET /api/user` 的 600/分、`POST /api/mcp` 的 120/分、2 條 `throttle:qa-answer`）；其餘都在 `web` 群組，那裡本來就沒有任何路由層 throttle——不是被繞過，是從來沒有。單次失敗的成本也比原文低：session driver 是 file，不帶 token 時 Sanctum 走完整條 guard 是 **0 次 DB 查詢**，只有「不含 `|` 的 token」才真的查一次。
- **刻意不覆寫 `$middlewarePriority`**（原 issue 的提案）：限流若跑在 auth 之前，limiter callback 裡的 `$request->user()` 會走預設 guard（把預設 guard 切成 sanctum 的正是 auth middleware），bearer 請求恆為 null，於是 `qa-answer`／`mcp` 的按帳號計數全退成按 IP。`HistoricalQaTest` 現有測試就斷言「同 IP 換使用者額度必須獨立」會被打破，MCP 也會讓同一個 NAT 後面的機構共用額度（那正是 #1249 改用具名 limiter 要避免的）。加上專案沒有 TrustProxies、限流計數器落在 file store，全站排序調整的代價遠大於收益。
- 改為新增全域 middleware `ThrottleFailedAuthentication`：在 auth 之前短路，**只管「帶 `Authorization: Bearer` 卻認證失敗」的請求**，按來源 IP 每分鐘封頂（預設 60，config 可調並夾範圍：0／負數／髒值退回預設、不得超過 600）。註冊在 `Kernel::$middleware` 是保護的一部分——全域 stack 不受 `$middlewarePriority` 排序影響，也排除不掉。
- **範圍是 review 實測後收窄的，兩個方向都是真問題**：第一版無條件短路，額度用完後該 IP 的**所有**請求都 429，包含 `GET /`、`GET /login`、`POST /login` 與帶著**有效** token 的請求——機構 NAT 後面一個 token 過期的腳本就能讓整個機構斷線且**無法自救**；同時第一版只認 401，而未認證請求只有 `expectsJson()` 為真才回 401，否則是 302 到登入頁，於是攻擊者**只要不帶 `Accept: application/json`** 就能無限試 token（方向還是反的：守規矩的客戶端才被計數）。現在「帶 token 卻被導向登入頁」也算失敗，而沒帶憑證的請求既不累加也不會被擋——`/metrics` 的 Basic Auth challenge、MCP 規範要求的未認證握手、session 過期的站內 XHR 因此都不會誤傷。
- 回歸測試 `FailedAuthenticationThrottleTest`（20 tests）分兩半：閘門真的會關（超額 429、封頂**不再查 `personal_access_tokens`**、跨端點共用額度、非 JSON 的導向登入頁也計、窗口會過期）與閘門不會誤傷（沒帶 token 的請求永不被擋、被擋期間仍可開登入頁與登入、未帶憑證的 401 不計、403／404／422 不計、成功請求不歸零計數、每個來源 IP 各自一份額度、輪替 `X-Forwarded-For`／`User-Agent` 拿不到新額度）。另釘住出貨設定值就是 60 與 env 鍵名——其餘測試都用 `config([...])` 覆寫上限，`config/auth.php` 與 `.env` 的接線本來完全沒被測到。把 middleware 從 Kernel 拿掉會讓多數測試變紅。
- 附帶記下兩個**優先度更高、不受本次改動影響**的缺口（開 #1264 追，前三條已於上方條目修掉）：`POST /register`、`POST /password/email`、`POST /password/reset` 完全沒有限流，其中忘記密碼連框架內建的 password-reset token 節流都因 `config/auth.php` 的 `passwords.users` 缺 `throttle` 鍵而永久停用（`$config['throttle'] ?? 0` → `recentlyCreatedToken()` 恆 false），等於無上限的信件與寫庫放大器；以及 `web` 群組那 7 條 `/api/v2` 寫入端點零 throttle。

### 修好操作紀錄提案列表的 500：現況比對不再被歷史資料打掛
- 起因：核准一筆 `POSTED_TO_OFFICE_DATA` 提案後，`/app/operations?proposals_only=1`（與 Blade 版 `/operations?proposals_only=1`）整頁 500。以 `editor`／`status` 參數二分定位到具體列，再核對生產 `laravel.log` 與 `audit_log`，確認是**兩個各自獨立**的成因，都落在「查現行資料列以顯示前值／後值／現況」這段共用邏輯（`buildOperationsListing()`）：
  1. `ErrorException: Undefined array key 1`：`POSTED_TO_OFFICE_DATA` 的 switch/case 只認 `-` 分隔符，但該筆的 `resource_id` 是 `_._` 格式（`61211_._2108722`，與同檔其他 case 一致的格式），`explode('-')` 只切出一段，`$temp_l[1]` 未定義。Laravel 會把 PHP warning 轉成 ErrorException，於是**一列壞資料打掛整頁**。
  2. `SQLSTATE[42S22] Unknown column 'lastname_chn'`：`resolveAuditCurrentRow()` 直接拿 `audit_log.row_pk` 組 WHERE，而 `row_pk` 是**寫入當下的欄名快照**；`pinyin.lastname_chn` 早已被 `2026_07_10_000000_restructure_pinyin_table` 改名為 `c_chn`，4 筆歷史稽核列的欄名在現行 schema 已不存在。
- 修法分兩層。**降級**：`POSTED_TO_OFFICE_DATA`／`POSTED_TO_ADDR_DATA` 補上 `_._` 分隔符（欄位順序已對照 `CompositePrimaryKey::SCHEMAS` 與生產實際資料列核實）；靠 `explode` 拆主鍵又沒有守衛的 case 全部改經 `hasEnoughIdSegments()` 擋片段數（`EVENTS_DATA`、`ASSOC_DATA` 的 dash 分支與 `POSTED_TO_ADDR_DATA` 本來就各有行內守衛，保留原樣）；查現況前先比對現行 schema 的地方有四處——新格式路徑、`resolveAuditCurrentRow()`、`ALTNAME_DATA` 的舊格式分支、`POSTED_TO_ADDR_DATA` 的 rows 映射。查不到現況時該欄顯示「未取得」，**不影響提案本身的前後值比對與核准按鈕**。
- **補正資料**：新增 migration 把那 4 筆 `audit_log` 的 pinyin 欄名快照改名（`lastname_chn`→`c_chn`、`lastname_pinyin`→`c_pinyin`），讓現況真的顯示得出來。`row_pk`／`row_pk_text`／`old_data`／`new_data` 一起改，因為 `buildAuditDiff()` 是照 key 名逐欄比對的，只改 `row_pk` 會查到列卻每欄都對不上。**只改欄名、不動任何值**，稽核事實完整保留。篩選是 `table_name='pinyin'` 加上四個快照欄任一 `LIKE '%舊欄名%'` 的粗篩（粗篩會連值裡出現舊欄名的列一起撈進來，實際改名仍逐欄精確比對 key，所以不會誤改），可重複執行。生產實測影響範圍就是這 4 列（已用 `JSON_TABLE` 對 `information_schema` 全表掃過，全庫僅此一處欄名漂移）。
- migration 的三個刻意決定：**`down()` 留空**（比照 `2026_08_11_000001`）——`migrate:rollback` 只回滾最後一批，而 pinyin 表改名的 `2026_07_10` 是更早的批次，反向改名的篩選條件是「含新欄名」，命中的是**所有** pinyin 稽核列，等於把要修的漂移從 4 列擴散到全部（已實測確認這個放大效果）。**新舊欄名並存時整列跳過並記警告**——哪個才是權威無從判斷，硬改會覆蓋掉其中一個值；判定涵蓋四個快照欄，任一欄衝突就整列不動，不逐欄各自決定（否則會出現「`row_pk` 沒改、`new_data` 改了」的半套狀態，讓同一列的快照互相矛盾）。**只在能 byte-for-byte 重現原值時才改寫**——`json_decode()` 對重複 key 只留最後一個（`{"a":1,"a":2}` → `{"a":2}`），直接重編會永久丟掉前一個值；把未改名的重編結果與原字串比對，不相符就整列不動，這道守衛同時擋掉非標準轉義與空白排版的快照。全部判定都在 `rowSkipReason()` 於**列層級**一次做完，任一欄不安全就整列不動——codex 連兩輪都在同一個位置抓到漏網：第二輪是無損判定還逐欄，第三輪是「解不開的快照被略過但其他欄照改」與「`row_pk_text` 只有 key 沒有值的片段留在舊欄名」，兩者都會留下「`row_pk` 已是 `c_chn`、`new_data` 還是 `lastname_pinyin`」的半套狀態。現在**任一快照欄不是合法 JSON 就整列跳過**（第四輪指出「字面上有沒有舊欄名」擋不住 `\uXXXX` 轉義的 key，所以改成不靠字串比對——解不開的快照我們本來就不會動，整列跳過是唯一能保證不出現半套狀態的做法；合法 JSON 但是陣列／純量則安全略過，最外層沒有字串 key 就不可能需要改名），沒有 `=` 的片段則整段當 key 改名（與並存判定同一套解讀）。另一方面只對「真的需要改名」的欄要求無損重編，沒有要改的欄即使排版不標準也不該擋掉整列（有反面測試釘住，免得守衛過度保守）。
- codex 覆核另提一條「`__proposal_meta` 不是陣列時 `serializeOperationRow()` 會 TypeError」，實測是誤報：該處每一個下標都帶 `?? null`，而 PHP 的 `??` 對字串下標走 isset 語義、回 null 不拋錯。仍補了一條測試把這個行為釘住（同時打 Blade 與 React 兩條路徑），免得日後有人把 `??` 拿掉。
- 順帶修掉同一段的三個潛伏 500：`BiogMain::find()` 未檢查 null（新增人物的提案在核准前 `BIOG_MAIN` 還沒有那一列）；`ASSOC_DATA` 的 `_._` 分支讀 `[0]`～`[6]` 無守衛，而分隔符是照原始 id 嗅探的——`c_text_title` 是自由文字，標題本身含 `_._` 就會誤走該分支；`buildPostedToAddrDiff()` 的參數宣告是 `array`，而 `?? []` 只擋 missing／null 擋不到「存在但不是陣列」（payload 為 `{"rows":""}` 時是 TypeError）。另把迴圈內遮蔽外層分頁 builder 的 `$query` 改名為 `$currentRowQuery`。
- 降級警告同一次請求內去重，並區分「表不存在」與「欄名已改名」兩種訊息——這些是常駐的歷史資料狀況，每次翻頁重記整批只會淹掉真正的新訊號。
- 回歸測試 `OperationsIndexResilienceTest`（12 tests）＋`PinyinAuditLogColumnRenameMigrationTest`（19 tests）。已驗證抽掉控制器改動後前者 **8/12 會紅**；剩下 4 條是刻意的「別把本來能用的路徑弄壞」或釘住既有行為的守衛（dash 分隔符仍查得到現況、欄名都在時仍查得到現況、`POSTED_TO_ADDR_DATA` 欄位缺失、`__proposal_meta` 非陣列）。其中 schema 漂移那條**刻意斷言機制而非輸出**（用 `DB::listen` 證明那句 SQL 從未發出）：SQLite 會把無法解析的雙引號識別字當成字串常量、查詢照跑只是查不到列，只斷言「現況顯示未取得」的話修好前後都會過，等於沒測到。migration 那支另含空表、`audit_log` 不存在、`NULL`／空字串／非法 JSON 快照等 no-op 案例，並以真正的 `php artisan migrate` 在全新 SQLite 檔上實跑驗證過。

### `db:export-to-sqlite` 預設不再複製帳號／憑證表的資料列，並把釋出範圍寫成可執行的契約（#1251）
- 起因：命令會把來源庫的表整批匯出，`users`（含 `password` 雜湊與 `confirmation_token`＝眾包 API 憑證）、`personal_access_tokens`、`sessions`、`oauth_*` 都在內。**HuggingFace 上的公開釋出檔沒有受影響**——`scripts/export-daily-sqlite.sh` 逐表匯出 77 張 CBDB 表的 allowlist，本來就沒有這些表（issue 原本的嚴重度判斷因此下修並改寫；使用者實測下載的 sqlite 確實沒有 `users`）。真正的曝露面是「開發者裸跑這個命令指向 prod」：文件與 README 的範例都是不帶 `--tables` 的全量匯出。
- 修法是**資料層排除而非表層排除**：憑證表的**結構照匯、資料列跳過**（`CREDENTIAL_TABLES` 14 張，每張附「為什麼不能外流」的理由）。第一版改成整張表都不匯，會打斷本機開發流程——`scripts/patch_sqlite_db_for_dev.sh` 需要 `users`／`password_resets`／`personal_access_tokens` 存在，`docker/entrypoint.sh` 也要建管理員；表不存在時是執行期才炸的 `no such table: users`。需要連資料一起匯出時顯式帶 `--with-credentials`，命令會印出「你正在把密碼雜湊寫進這個檔案」。
- 跳過的**條件形狀**刻意與提示訊息解耦（`isCredentialTable()` ＋ `shouldSkipTableData()`）。原本寫成「訊息產生器回傳非 null 才判斷跳過」，review 實測只要在那個 helper 開頭加一句 `if ($this->option('quiet')) return null;`（看起來只是別洗訊息）就會讓 `-q` 把 `users` 的資料列照匯。測試改為**窮舉命令宣告的每一個 option ＋ Symfony 全域旗標**逐個打開後斷言「還是跳過」，因為原本的測試各自只傳自己關心的 option，`&& !$this->option('append')`／`('tables')` 這類弱化會全綠——而釋出腳本每一次呼叫都帶 `--tables`、第 2～77 張還都帶 `--append`。
- 釋出範圍另外加了**產物自檢** `cbdb:assert-sqlite-release-scope`：直接讀要上傳的那個 `.sqlite3`，比對 `App\Support\SqliteReleaseTables::PUBLIC_TABLES`（77 張的精確集合）、拒絕任何檢視、並要求表數不少於 allowlist 長度（預設值就是 77，不是 1）。三條合起來等於「產物的表集合恰好是那 77 張」。一律 fail-closed：檔案不存在／是目錄／開不起來都算失敗——`new PDO("sqlite:missing")` 會**建立**一個空資料庫然後回報「0 張表、沒問題」，那是最危險的假通過。`weekly-sqlite-sync.sh` 在打包上傳前再驗一次。
- 判準刻意是**精確集合**而不是「全大寫就算公開 CBDB 表」的形狀規則：codex 覆核指出後者會放行任何日後新增的大寫表，而 `AUDIT_LOG_ARCHIVE`、`USER_LOGIN_EVENTS` 這種名字一樣全大寫、卻是個資。`audit_log`（email＋登入 IP／UA）、`operations`、`nl_query_logs` 都不在憑證名單裡，只比對憑證名會整批漏掉。腳本的 `TABLES=(...)` 與那份常數是兩份必須逐項相同的清單，由測試比對（只改一邊會在 CI 就紅，不會拖到每週釋出的凌晨）。
- 自檢寫成**裸呼叫 + `set -e`**，不是 `if ! php artisan ...; then ... exit 1; fi`。review 與 codex 實測出整整一類「呼叫還在、結束碼被吞掉」的繞道：`| tee`（bash 的 `!` 否定的是 pipeline）、`&& false`、`&& [ -z "$SKIP" ]`（留一個環境變數開關給 cron，看起來無害）、刪掉區塊裡的 `exit 1`（只印訊息然後照樣產生 metadata）——這些都不是「文字不見了」而是「語意沒了」，純字串比對的守衛全綠。因此新增 `ReleaseScriptSelfCheckGateTest`：把假的 `php` 放到 `PATH` 最前面**真的跑一次 bash 腳本**，斷言自檢失敗時非零收尾且沒有產生 metadata、自檢拿到的路徑就是實際產出的檔案、且排在最後一次匯出之後。假 `php` 也會檢查自檢目標檔存在（否則「把自檢搬到匯出之前」會假綠）。已實測 `set +e` 這種靜態檢查看不見的改動會被它抓到。
- 靜態契約測試同步補強（都是 review 實測出繞道後才加的）：allowlist 解析改為剝註解＋依空白切 token（原本只抓雙引號項，單引號 `'audit_log'` 或裸字都能繞過）、匯出迴圈必須真的迭代 `"${TABLES[@]}"`（換成 `$(cat 外部檔)` 或另開 `EXTRA=(...)` 併進去就等於 allowlist 沒人讀）、每個 `--tables` 恰好是 `"$TABLE"`（`--tables="$TABLE,audit_log"` 可在不新增呼叫的情況下夾帶）、`db:export` 出現恰好兩次（`db:export"-to-sqlite"` 這種寫法 bash 會併回原命令卻能騙過完整命令名的計數）。
- 文件同步：`docs/SQLITE_DATA_RELEASE.md` 寫清釋出範圍契約與三道防線；`README-Docker.md` 補上「釋出檔缺哪些表、要怎麼補」與 host／container 路徑差異；`docs/SQLITE_MIGRATION_PLAN.md`、`scripts/use-sqlite.sh` 的裸指令範例改掉。順帶修正一句錯的因果：**metadata 缺席本身擋不住上傳**（`weekly-sqlite-sync.sh` 找不到 metadata 只會印警告然後照樣上傳 zip），真正擋住的是結束碼。

### MCP 端點的限流補上 GET，並改用具名 limiter（#1249）
- 起因：`Mcp::web()` 會註冊**兩條**路由，但它只回傳 POST，所以 `routes/ai.php` 的 `->middleware(['auth:sanctum', 'mcp.ability:…', 'throttle:120,1'])` 只套到 POST。套件的 GET 是 MCP 規範要求的 SSE 佔位（恆回 405），**沒有任何路由層 middleware**，僅靠 `api` 群組寬鬆的 600/分把關——同一個 URI 一半有閘一半沒有，成了便宜的請求放大面。
- 修法改為**用群組套限流**（`Route::middleware('throttle:mcp')->group(...)`），一次涵蓋 `Mcp::web()` 內部註冊的兩條路由，不必依賴「同 URI 後註冊者會逐出前者」這種 `RouteCollection` 實作細節。
- **同時改用具名 limiter `mcp`**（原本的 `throttle:120,1` 是數值型）。這一點是 review 實測抓出來的真缺陷：數值型 throttle 的 key 是 `sha1(domain|ip)`，**不含路由、方法與上限值**，所以路由上的 `120,1` 會與 `api` 群組的 `600,1` 共用同一個計數器——每個請求被 `hit()` 兩次，實測 120 的上限實際只有約 60/分，而且該 IP 打其他 `/api/*` 端點也會吃掉 MCP 的額度（同一個 NAT 後面的機構共用這個桶，合法客戶端可能在探測階段就 429）。具名 limiter 的 key 有命名空間隔離，上限才是真正的 config 值。**另以 `withoutMiddleware('throttle:600,1')` 拿掉 api 群組繼承來的那條**——這是 codex 覆核指出的：只換具名 limiter 還不夠，600 仍會套用，於是同 IP 的其他 `/api` 流量把 600 桶打滿時，MCP 自己的 120 還有額度卻照樣 429。拿掉之後才真的是獨立預算（120 本來就比 600 嚴格，不放寬任何東西）；該字串與 `app/Http/Kernel.php` 的耦合由測試斷言 resolved middleware 守住。
- **刻意只補限流、不在 GET 加 `auth:sanctum`**：回應是固定的 405、不碰資料庫、不含任何資料，加認證沒保護到東西，卻會讓未認證的 MCP 客戶端在探測階段拿到 401 而不是規範所期待的「不支援 SSE」405。
- 設定值加上範圍防護：`MCP_RATE_LIMIT_PER_MINUTE` 為 0／負數時退回預設（`Limit::perMinute(0)` 實測會放行第一個請求，等於幾乎不設限，那種值只可能是設定錯誤），上限夾在 600——因為已排除 api 群組那條，若不夾住，一個過大的設定值就會把全站上限放寬。
- 回歸測試 `McpEndpointMiddlewareTest`（7 tests）：GET 與 POST 都掛具名 limiter、**解析後只剩那一條 throttle**（守住與 Kernel 的字串耦合）、**限流真的會擋**（把上限調小後打過頭確認 429，同時證明 limiter 在請求時才讀 config）、GET 仍回 405、POST 的閘門未被動到、POST 無 token 仍被擋。守衛刻意不用 `markTestSkipped`（那會讓「誤關 `MCP_ENABLED`」變成全綠），並斷言路由恰好一條以偵測殘影。已驗證還原改動後測試會紅。
- 已知且不在本次範圍：**未認證的 POST 在 401 時完全繞過限流**——框架的 `$middlewarePriority` 把 `AuthenticatesRequests` 排在 `ThrottleRequests` 之前，這是全站 auth 路由的共同行為（每個 401 仍會查一次 `personal_access_tokens`），已另開 #1254 追。

### 清掉 13 條無用路由（其中 11 條指向不存在的控制器方法），並加測試防復發（#1250）
- 起因：`/api/select/codes` 指向從未存在的 `ApiController@codes`，命中時由基底 `Controller::__call` 拋 `BadMethodCallException`＝HTTP 500。這類路由不會在啟動時報錯（Laravel 只在請求進來才解析 action），所以能長期潛伏，只在被外部掃描或誤點時變成錯誤日誌噪音。
- 掃完全站後發現不只一條：`Route::resource('operations', ...)` 與 `Route::resource('crowdsourcing', ...)` 各生 7 條路由，但兩個控制器只實作 `index()` 與一個**空的 `store()`**，於是 `create`／`show`／`edit`／`update`／`destroy` 共 10 條全是 500。兩者的 `index` 早已在上方以顯式路由宣告（`crowdsourcing` 那條同在 superadmin 群組內，保護不變），空 `store` 無呼叫端，因此整段移除；已確認全庫沒有引用被拿掉的路由名稱（grep 命中的都是 SQL 欄名 `operations.created_at` 與翻譯鍵 `operations.edit_proposal` 之類的假陽性）。
- 兩個空的 `store()` 失去唯一入口後一併刪除（`OperationsController`／`CrowdsourcingController`），避免留著讓人誤以為還有寫入端點。
- **防復發是重點**：新增 `RouteActionsExistTest`，遍歷所有註冊路由斷言 action 真的可被 dispatch，失敗訊息直接列出死路由並提示用 `->only()` 收窄。判定刻意不用 `is_callable`（基底 `Controller` 有 `__call`，對任何方法名都回 true，正是這個 bug 能潛伏的原因），也不只用 `method_exists`——後者不看可見性，而 `private` 方法在 dispatch 時同樣會落到 `__call` 變 500。因此改以 Reflection 擋掉 private、abstract 與「指向框架基底 Controller 方法」三種情形。**刻意允許 `protected`**（`callAction()` 在同一繼承鏈內呼得到，本庫 `Api\ApiController*` 底下十多條路由正是這個形狀）**與 `static`**（PHP 允許以實例語法呼叫 static 方法，擋它會對合法 action 誤報——這點是 codex 覆核時實測糾正的）。已驗證還原路由檔後測試會紅。

### `/api/user` 不再外洩 `confirmation_token`（#1248）
- 起因：`Api\UserController@show` 是 `return $request->user()`，序列化範圍全靠 `User::$hidden` 的黑名單，而 `confirmation_token` 不在其中。它不是普通欄位，而是**第二套長期憑證**——`/api/operations/token` 直接把它當眾包 API token 發出去，`/api/operations/{add,update,delete}` 只憑它認證，且**無到期、無撤銷、不驗欄位白名單**。因此一個只被授予唯讀能力的 Sanctum token，可以換到一個能繞過 v2 全部白名單／主鍵校驗、直接往 `operations` 寫入的憑證：這是提權路徑，不只是欄位過度曝露。
- 端點改為**顯式白名單**（id／name／email／institution／avatar／is_admin／is_active／時間欄），而不是繼續維護黑名單——根因是「`users` 加一欄就默默對外」，白名單才治得住。順帶不再回傳 `settings`，它內含 `registration_ip`／`last_login_ip`。
- `User::$hidden` 補上 `confirmation_token` 作縱深防禦，擋住其他把模型整包序列化的路徑。`$hidden` 只影響序列化，屬性讀取不受影響，故 `/api/operations/token` 與 `resolveActiveUserByToken()` 不會壞（有測試反向鎖住）。
- 另修兩處 `$hidden` **擋不到**的同類洞：`AiFillLogController::logUsers()` 與 `QueryPlaygroundController::nlQueryLogUsers()` 用 query builder 撈 `users` 全欄列（stdClass，`$hidden` 對它零作用），補上 `select('id','name')`。目前 Blade 消費端只取 id/name 所以尚未實際印出，但同檔的 Inertia 分支都得手動收窄才安全——正說明這個形狀是陷阱。
- 回歸測試 `ApiUserPayloadTest`（6 tests）：白名單以「鍵名相等」斷言（新增欄位必須是刻意決定）、不得出現任何憑證欄、模型整包序列化也不得帶、屬性讀取仍可用、無 token 回 401、只回 token 主人的資料。已驗證抽掉修改後測試會紅。

### API.md 補完所有現行對外 API（v2 寫入端＋其餘開放端點）
- 起因：有外部協作者要以眾包帳號向 `/api/v2` 提交提案，但 `API.md` 原本只有讀取端（`persons`／`operations`），寫入端一字未提；寫入端的實用說明散在 `.claude/skills/mutation-api-record-editing.md`（內部技能檔、且通篇以 `mode=direct` 為前提）與不完整的 `docs/openapi/openapi.yaml`，沒有一份能直接交付給外部的文件。
- 新增 v2 第一～十四章：通用約定（認證、`Origin`/`Referer` 會讓 Bearer 失效、CORS 不含 `Authorization`、CSRF 豁免清單、限流、空字串一律轉 null）、寫入 API 總覽（direct／proposal 權限矩陣、提案占位規則、`target.pk` 與主鍵哨兵值、錯誤碼總表）、`get`／`create`／`mutate`／`delete`／`batch_mutate`／`resubmit`／`opposite-edges` 逐端點規格、14 個資源的欄位白名單、互逆鏡像的衝突與復原、代碼表與複合實體聚合、以及其餘開放端點（`texts`、`api/user`、api-tokens、`select/*`、AI 輔助、MCP、`cbdbapi/person`）。
- 舊版章節補上「現況與注意事項」：14 個查詢端點全掛訪客中間件，**帶著登入 session 呼叫會 302 到 `/home`**；並列出仍存在但未收錄的端點、以及已下架的 `/api/v1` 舊 CRUD。
- 後續補上寫入端的**節流契約**（§1.3）：`web` 群組那幾條在應用程式路由層沒有 throttle，責任全在呼叫方——寫入請求每秒不超過 1 次**且必須序列化**（等回應再發下一個，不是按固定節拍發），並釘住三個容易誤導的點：(1) `batch_mutate` 的 500 筆是**硬上限而非建議值**，且每批該放多少取決於 `mode`——`proposal` 每筆只寫一筆提案 operation（外部協作者的情形），`direct` 才會寫目標列＋`operations`＋`audit_log`，關係類另加對面鏡像列、聚合類的下層列增刪逐列各記一筆，故 direct 的重型資源每批建議 20～50 筆（保守起始值，非壓測結論）；(2) **請求可能被部署環境的執行時間上限中斷，而非原子模式下已處理的筆數仍會落庫**（必須先對帳、不可盲目重送）；(3) 改用批次省的是 HTTP 與認證開銷，**DB 工作量不變**。同時修掉三處與此相牴的舊敘述：`docs/API_AUTHENTICATION.md` 稱 600/分鐘是「全局」、`.claude/skills/mutation-api-record-editing.md` 稱 `/api/v2/*` 會回 429 並建議每筆 sleep 0.2s、以及 `/cbdbapi/person`「沒有額外限流」的措辭。
- 過程中以三輪 review agent ＋ codex 對照原始碼與 `tests/Feature/ApiV2*Test.php` 逐條查證，修掉多處會誤導呼叫者的敘述（例如 `proposals_only=true` 不涵蓋 op_type 10 的刪除提案、`batch_mutate` 非原子模式即使全數失敗 HTTP 仍是 200、部分資源的回應沒有 `operation_id`／`pk`、`basicinformation` create 會靜默丟棄未知欄位）。
- 連帶：`AGENTS.md` 立下「API 改動必須同步 `API.md`」的規則（並進「提交前最低檢查」清單）；`DATABASE.md` 修正 `ALTNAME_DATA`（3-key，不含 `c_sequence`）與 `POSTED_TO_ADDR_DATA` 的主鍵敘述。

### 補回 React 殼的 SQL 查詢明細，並把收集成本收斂到看得到的人身上；刪除使用者恢復兩段確認
- 起因：`AppServiceProvider` 的 `DB::listen` **無條件**把每筆查詢的 SQL 與 bindings 累進記憶體，而 React layout 從來沒有渲染這份資料——舊版 `layouts/dashboard-v3.blade.php` 底部的「本次查詢共 N 筆，耗時 X ms」＋管理員可開的明細 modal 在遷移時漏移植。等於每個 request（含 artisan 匯入、訪客瀏覽）都照樣收集、卻沒人看得到。
- **權限分界完全對齊舊版**，而不是順手改掉：舊版那條摘要行 **沒有任何權限閘**（`dashboard-v3.blade.php:346`，訪客也看得到），只有「查看詳細」連結與 modal 限管理員（同檔 `:348`／`:369`）。因此閘的是「**保留明細**」而不是「整個收集」：筆數與耗時一律累計，每筆 SQL／bindings 只在使用者已解析為 `isAdmin()` 時保留。若連筆數都不收，flag 回退到 Blade 的頁面也會少一行，那不在本次範圍。
- **顯示端自己再授權一次**，不只依賴收集端：`HandleInertiaRequests::queryProfile()` 獨立檢查 `isAdmin()`，非管理員的 `queries` 為空陣列。收集端的閘門長在全域 `DB::listen` 回呼裡、還包著 try/catch，一旦有人為了「讓筆數回到舊版數字」把它放寬，原始 SQL 與 bind 值就會直接出現在每個訪客的 `data-page` JSON（AGENTS.md §5：授權不該只有一道，更不該只長在除錯收集器內）。已用瀏覽器驗證非管理員的 payload 裡完全沒有 `"sql":`。
- **shared prop 必須延後求值**（實際踩到並修掉的 bug）：inertia-laravel 的 `Middleware::handle` 是在 `$next($request)` **之前**呼叫 `share()`，此刻控制器一筆查詢都還沒跑。原本直接呼叫 `queryProfile()`，摘要永遠只有 session／撈使用者那兩筆——畫面上看起來有東西、數字卻是錯的（實測 codes 頁 2 筆 vs 實際 6～7 筆）。改為 closure，由 `Response::resolveArrayableProperties` 在 `toResponse()` 才求值。
- 再包一層 `Inertia::always()`：局部重載只回傳 `only` 指定的 props，其餘 shared props 會被丟掉、前端沿用舊值——除錯輔助顯示上一次請求的筆數比不顯示更誤導。此行為以**真正的局部重載**測試鎖住（帶 `X-Inertia-Partial-Data` 指定別的 prop），換回普通 closure 即紅。
- **局部重載只更新摘要、不夾帶明細**：Inertia 會把整份 page props 存進 `window.history.state`，而切換人物分頁這類局部重載在編目工作中極頻繁；每個 XHR 都夾帶上百句 SQL 與 bind 值，只為了一個偶爾打開的 modal，並不划算，也會把 bind 值留在瀏覽器歷史。前端記住最後一次拿到的明細，讓「查看詳細」不會在局部更新後忽然消失，並在 modal 內明示「以下明細為本頁載入時的查詢」。兩個方向都有測試（局部重載回應不得出現 `"sql"`；整頁載入必須給管理員明細，否則「不夾帶」會退化成「永遠拿不到」）。
- 閘門判斷**刻意不做跨請求記憶**：`ServiceProvider` 是長生命週期物件，在 Octane／RoadRunner 這類常駐 worker 下會活過請求邊界，一旦某個管理員請求把「可留明細」記成 true，後續訪客請求就會開始保留 SQL——那是跨請求外洩。省下的只是每筆查詢幾個不碰資料庫的屬性讀取（`hasResolvedGuards()` 是 count、`hasUser()` 是 `is_null`、`user()` 走已快取屬性、`isAdmin()` 是 `in_array`），不值得用正確性去換。同理 `QueryProfile` 由 `singleton` 改為 **`scoped`**：它記的是「本次請求的查詢」，語意上就該隨請求結束；傳統 php-fpm 下兩者等價，常駐 worker 下 singleton 會讓筆數愈跑愈大、且管理員留下的明細可能被後續請求讀到。
- 前端沿用舊明細的條件由後端明說（`details_omitted`），不是「本次沒帶就沿用」：同一個 React 殼會活過登出／被降權／換 session，那時回應是 `queries: []` 且 `details_omitted=false`，前端因此會**清掉**先前的明細，而不是把管理員的 SQL 繼續顯示給已經不是管理員的人。非管理員的回應永遠 `details_omitted=false`（有測試明文鎖住）。
- 收集端加上記憶體上限（`QueryProfile::MAX_STORED = 200`），但**筆數與總耗時另行累計、永遠精確**——否則管理員在一個跑幾千筆查詢的頁面上只看到「200 筆」，反而掩蓋了要抓的效能問題。`summary()` 改為先切再編碼（先前編碼 200 筆再丟掉一半），`View::composer` 由 `'*'` 收窄到真正使用該變數的 `layouts.dashboard-v3`（先前一個 Blade 頁面渲染數十個 partial 就重複編碼數十遍）。
- 閘門判斷本身的兩個修正：**明確指定 web guard**（`OptionalAuthentication` 會在執行期 `Auth::shouldUse('sanctum')` 改寫預設 guard，否則「帶 token 打 API 的管理員」也開始留明細，而 JSON 回應永遠不顯示這份資料——正是要消除的浪費）；try/catch 只 **report 第一次**（每筆都 report 會淹沒日誌，完全不記錄則會讓閘門壞掉、功能無聲消失卻查不出原因），且 `report()` 自身再包一層 try/catch——它若丟出去就繞過了外層 catch，讓一個純除錯輔助弄壞請求。
- 判斷絕不能呼叫 `Auth::check()`／`Auth::user()`：那會在「解析使用者」那句 select 的監聽器裡再次觸發解析（遞迴）。改用 `hasResolvedGuards()` + `hasUser()`（皆不碰資料庫）。此性質改由**真的從 session 解析**的測試守住——先前的測試用 `actingAs()`，那是直接 `setUser()`、永遠走不到解析路徑，把閘門換成 `Auth::user()` 照樣綠燈。已知代價：解析完成前的查詢沒有明細（筆數仍算），故明細列數略少於總筆數，訊息文案因此不寫「前 N 筆」。
- 顯示端沿用共用 `Modal`（Radix：focus trap／Esc／a11y 內建）而非自製對話框，補回舊版 modal 底部的「關閉」鈕，總毫秒數用千分位（對齊舊版 `number_format`）。
- **刪除使用者恢復兩段確認**：舊版 `manage/edit.blade.php` 有兩道 `confirm()`（第一段說明不可恢復、第二段最後確認），React 遷移時收斂成一道。改用兩個 `ConfirmDialog` 串接，沿用同一組翻譯鍵；第一段的 `\n\n` 以 `whitespace-pre-line` 保住斷行（舊版走 `window.confirm`，空行就是重點強調）。送出 payload（`delete_user=1`）與後端契約未改動。
- 收集端與顯示端**用同一個 guard**（皆為 `web`）：兩端若各自看執行期可被改寫的預設 guard，會出現「有收集卻不給看」的不一致。
- 回歸測試 `QueryProfileGateTest`（15 tests：訪客／一般／眾包使用者有筆數但無明細、超級管理員與專家有明細、非管理員的 shared prop 不帶任何 SQL、延後求值含控制器查詢、真正的局部重載仍帶此 prop 但不帶明細、整頁載入才給明細、`details_omitted` 僅對「本來看得到明細的人」為 true、明細上限與筆數精確、記憶體上限不扭曲總計、從 session 解析使用者時不遞迴且解析前不留明細、summary 形狀穩定）。另以 headless Chrome 對真實庫驗證 11 項（6 項查詢明細，含非管理員 payload 無 SQL；5 項兩段刪除，含第一段按繼續不會刪除、第二段取消不會刪除、兩段都確認才真的刪除）。

### codes 表單的人物欄改為可搜尋的人物選擇器（判準改用外鍵）
- 承上一則：泛用 codes 表單漏移植的最後一項。舊版 `codes/edit.blade.php` 把人物欄渲染成 select2（姓名或 ID 皆可查），React 版是純數字輸入框——使用者必須先知道人物 ID 才能填。
- **判準改為「外鍵實際指向 `BIOG_MAIN`」，以 schema 宣告為唯一權威**（`CodesController::personFkColumns()`）。舊版是按欄名硬編碼 `c_personid`／`c_kin_id`，會漏掉 `ASSOC_DATA` 的 `c_assoc_id`（社會關係「對方是誰」）、`c_assoc_kin_id`、`c_assoc_claimer_id`、`c_tertiary_personid` 與 `ENTRY_DATA.c_assoc_id` 共 5 個真人物欄——恰恰是最需要用姓名搜尋的地方。改用外鍵後涵蓋 **17 張碼表、25 個欄位**，且隨 schema 自動跟上，不需維護人工白名單。
- 兩項刻意的取捨（已與使用者確認「錯判和漏都符合設計」）：`BIOG_MAIN.c_index_year_source_id` 會被納入（欄名像出處，但 schema 確實宣告外鍵指向 `BIOG_MAIN`）；`MERGED_PERSON_DATA.c_personid` 不納入（無外鍵——被合併的人可能已不存在）。兩者都由測試明文鎖住。
- 外鍵反射用 `Schema::getForeignKeys()` 而非 `information_schema`：後者在 SQLite 不存在，前者由 driver 各自實作（SQLite 走 `PRAGMA foreign_key_list`），符合雙資料庫相容要求；反射失敗只讓該欄退回純輸入框並記錄例外，不讓整頁掛掉。
- 選擇器沿用既有的 `CodeAutocomplete`（`mode="search"`，端點 `/api/select/search/biog`，與親屬編輯頁的「親屬姓名」同一支），因此自動獲得同一份 debounce 與過期回應守衛。後端另附上目前值的顯示名稱（`picker.label`，如「晁公武 / Chao Gongwu」），否則畫面上只看到一個數字；姓名兩欄皆空的人物退回顯示 ID，不讓欄位看起來像沒選。
- **「未詳」哨兵**：人物搜尋端點 `ApiController::searchBiog` 刻意把 person 0（未詳）的 option value 編成 `-999`，那是前端「未設定」哨兵、不是人物 ID。`BIOG_MAIN` 沒有 `-999` 這一列，直接落庫會撞外鍵 1452（錯誤訊息還指向「必填未填」），而提案路徑不碰資料表、會把 `-999` 原樣存進 `resource_data`，讓審核人看到 `-999` 並在核准時才爆掉——而「未詳」在 CBDB 極常見。改為在 `extractFormData()` 這個單一收口把人物欄的 `-999` 還原成 `0`（五條 codes 寫入／記錄路徑全部經過它；先前兩處 inline `Arr::except` 的控制鍵清單與它完全等價）。**只在「`-999` 確定不是真實人物」時才還原**：`c_personid` 是有號 int、無 UNSIGNED／CHECK 限制，schema 允許負值（現行資料 min=0、無負值），若真有 person `-999`，無條件改寫會把關係靜默改指到別人身上；查不到 `BIOG_MAIN` 時亦不改寫。同一份顧慮見 `ExactCodeMatchGuard`。
- **人物主鍵欄不再預填猜測值**：`appCreate` 原本把第一個主鍵欄預填成 `max+1`，而 `BIOG_ADDR_DATA`、`STATUS_DATA` 的第一個主鍵欄就是 `c_personid`；CBDB 人物 ID 很密集，那個猜測值往往真的存在，於是選擇器會把它解析成一位**真實人物姓名**，看起來像「已選好某人」——使用者填完其他欄一存，資料就被歸到隨機的人身上。先前是純數字輸入框，數字看起來就是佔位符，風險較低；改成選擇器後必須擋掉。留空反而能得到正確的「請確認主鍵欄位已填寫完整」提示。
- 有值就一定有可顯示文字：查不到人物時 `picker.label` 退回顯示 ID。否則 `CodeAutocomplete` 顯示空白而 `form.data` 仍藏著那個值，使用者以為沒填、送出後撞外鍵（提案調整頁尤其會遇到——`resource_data` 裡的人物可能在送審後被合併掉）。
- 外鍵表名比對改為大小寫不敏感：MySQL 在 `lower_case_table_names=1`（Windows 預設）會回報 `biog_main`，硬比大寫會讓**所有選擇器無聲消失**。控制器其他處（`guardTable`／`getKeyColumns`／`isReadOnlyTable`）也都先 `strtoupper`。
- 送出內容不變：選擇器回寫的仍是代碼字串，清空得到空字串——與先前純文字輸入框可被清空的行為一致。人物欄若同時是主鍵（如 `KIN_DATA.c_personid`），透過選擇器改值與先前用文字框改值走同一條 `performUpdate` 路徑（依 URL 主鍵定位、`update()` 就地換鍵，重複鍵與完整性違規各有友善訊息），**不是本次新增的能力**。
- 選擇器的顯示文字帶人物 ID（如「11 晁公武 / Chao Gongwu」）：改成選擇器後欄位本身不再顯示數字，而編目者是以 ID 工作的；搜尋候選同樣以 ID 開頭，選前選後讀法一致。
- 回歸測試 `CodesPersonPickerTest`（15 tests：六個有外鍵的欄位都有選擇器／涵蓋舊版按欄名會漏掉的 4 欄／非人物欄不給／帶 ID 的顯示名稱／無值與查不到時的退回行為／欄名像人物但無外鍵者不給／無人物欄的表為空／新增頁不預填人物主鍵／選未詳落庫為 0／`-999` 是真實人物時不改寫／非人物欄的 `-999` 原樣保留／提案不留 `-999`／人物選擇器與稽核欄唯讀互不覆寫）。另以 headless Chrome 對真實庫驗證 7 項（含姓名搜尋、ID 搜尋、`MERGED_PERSON_DATA` 維持純輸入）。

### 補回 codes 表單的逐欄行為：稽核欄不可編輯、欄位提示、依 c_textid 帶入書名
- 起因：上一則作者清單的缺口不是孤例。以「Blade 有用、React 端完全找不到的翻譯鍵」為橋樑掃過全站（961 個鍵→275 個孤兒；扣掉 `cbdbapi/person`、`maps`、`home` 三個本來就沒有 React 版也沒有 flag 的頁面共 81 個），確認**真缺口集中在 `Codes/Edit.tsx`**——React 版把新增／編輯頁寫成泛用表單（`columns` → 純 `Input`），舊版 `codes/edit.blade.php` 的逐欄特殊處理因此整批漏移植。掃描方法的侷限一併記下：先前想用「Blade 呼叫但 React 沒呼叫的端點」當主訊號會誤報，因為 React 的端點多由 server props 傳入（`Welcome.tsx` 的姓名搜尋即為例）。
- 新增 `CodesController::codeColumnBehaviour()` 作為逐欄行為的單一落點，Create／Edit 兩頁共用，避免各自再長出一套硬編碼：
  - **稽核欄（`c_created_by/date`、`c_modified_by/date`）一律灰底唯讀**。先前 React 可自由輸入卻毫無提示，而後端 `enforceAuditFieldsForUpdate` 本來就會覆蓋（`c_created_*` 還原原值、`c_modified_*` 蓋當下），等於讓使用者對著會被丟棄的輸入框打字。新增與編輯採同一條規則；用 `readOnly` 而非 `disabled`——這四欄的用途就是被讀，`disabled` 會讓文字無法選取複製，而舊版用的也是 `readonly`。**送出內容與改動前逐位元相同**（欄位仍在 `columns` 與 `form.data` 裡，只改 UI），另補測試鎖住「偽造稽核值送出也不生效」。
  - `c_modified_*` 補回「提交後會被替換為 X」預覽，且改走 `AuditActor::currentName()`——與實際落庫的署名同源，舊版用 `Auth::user()->name`，在核准情境下與實際寫入的雙人名不一致。
  - 欄位提示（TEXT_CODES／ADDR_CODES 複製提示）改由後端供給：`Edit.tsx` 先前完全沒有，`Create.tsx` 則是**硬編碼中文**（英文語境漏字、且與既有 `codes.*` 鍵重複）。新增無 HTML 的 `hint_*` 鍵，連結以結構化資料另傳（前端不需要 `dangerouslySetInnerHTML`），並用 flag-aware 的 `codesShowUrl()` 指向 React 版碼表頁。
- **TEXT_INSTANCE_DATA 依 `c_textid` 帶入書名**（舊版的「Load Data」鈕）。修掉舊版兩個缺陷：舊版打 `/api/select/search/text`（`c_title_chn LIKE %q% OR c_textid = q`）再取 `data[0]`，用 ID 查時可能撈到「標題剛好含這串數字」的別本書——改為新端點 `app/codes/text-title/{textId}` 主鍵精確查詢；舊版無條件覆寫兩個書名欄——改為**只填空欄**（使用者指定），不蓋掉人工修訂過的書名，並沿用舊版填入後標黃底的提示。
- 附帶修掉 `Create.tsx` 的結構缺陷：兩顆送出按鈕原本被包在 `{can_propose && ...}` 內，而 `app.codes.create` 沒有 auth middleware，訪客／非活躍帳號會看到一個完整表單卻一顆按鈕都沒有；改為與 `Edit.tsx` 對齊（「直接保存」永在、「提交建議」看 `can_propose`）。
- 新欄位元件刻意**不套用 `ui/FormField`**：它會把 id 與 aria 注入「單一子節點」，而這裡的子節點是包住輸入框＋動作鈕＋提示的 `<div>`——會讓 `<div>` 與 `<input>` 拿到相同 id（每頁重複十餘個），且 `<label for>` 指到不可標記的 `<div>` 而失效（點欄位標籤不再聚焦輸入框），`aria-invalid`／`aria-describedby` 也會落在 div 上而使 `Input` 的紅框與螢幕報讀關聯失效。改為自行組出 label／aria 關聯。
- 新端點補 `throttle:60,1`（與 `codes.export` 同理由：直連 live 生產庫且無登入門檻）。帶入結果訊息就近顯示在 `c_textid` 下方並帶 `role="status"`（原本放表單底部，`TEXT_INSTANCE_DATA` 欄位多時會離按鈕太遠），使用者一動表單即清除訊息與黃底。請求期間若使用者改了 `c_textid` 或手動填了書名欄，回應會被丟棄或跳過該欄，不覆蓋剛輸入的內容。
- 帶入結果的訊息**逐欄判定**而非看「整次請求有沒有書名」：書目常只有中文書名而無拼音書名（實測 21 筆 TEXT_CODES 如此、7 筆 instance 正好是這形狀），用單一旗標會把「拼音欄還空著、來源也沒有拼音書名」誤報成「兩欄皆已有值」。現在會如實列出哪些欄被帶入、哪些欄因來源沒有書名而仍為空。
- 欄位提示的連結改以 `:link` 佔位就地嵌回句中（保留舊版 inline `<a>` 的讀法，字串本身仍無 HTML）；「提交後會被替換為 X」加 `tone=warn` 以粗體主色呈現——舊版是 `text-info` + `<strong>`，不該與一般說明同重量。
- 提案調整頁（`Codes/ProposalEdit.tsx`）一併套用同一份逐欄行為：稽核欄同樣唯讀，但**不給替換預覽**（替換發生在核准當下、由審核人蓋章，此刻預告的署名與時間都會不同）。核准端本來就會剔除並重蓋，此處只是不再邀請使用者對著會被丟棄的輸入框打字。`Edit.tsx` 也補上舊版兩頁都有、React 只有 Create 有的「直接儲存會忽略此欄」提示。
- 回歸測試 `CodesColumnBehaviourTest`（18 tests：四個稽核欄唯讀／提案調整頁同樣唯讀但無替換預覽／替換預覽只給 `c_modified_*`／未登入無預覽但仍唯讀／新增頁同規則且欄位清單不變／偽造稽核值不生效／`c_textid` 提示與動作且提示不含 HTML／提示連結 flag-aware 指向 `/app/codes/TEXT_CODES`／新增頁也有提示／en 語境真的拿到英文／ADDR_BELONGS_DATA 兩欄提示／無特殊行為的表為空／端點精確查詢且不退回標題模糊命中／可查 `c_textid=0` 的「未知」書目／回報無書名的書目／非數字 ID 被拒）。另以 headless Chrome 對真實庫驗證 16 項（含實際按下帶入書名取得「愛日齋叢鈔」、第二次按不覆蓋、每欄僅一個 DOM id 且 label 正確關聯）。

### 補回 TEXT_CODES 編輯頁的作者清單（React 遷移時漏移植）
- 使用者回報 `/app/codes/TEXT_CODES/{id}/edit` 不再顯示作者。查證：該區塊只存在於舊版 `codes/edit.blade.php`（2022-01 #186 引入、2025-12 #655 改善多作者顯示），**2026-06-26 React/Inertia 上線（3f131d6）時漏移植**，而同一個 commit 把 `codes` flag 翻成 `new`，功能自此在正式站消失。旁證三項：後端端點 `/api/select/search/textauthor` 完好（12497 回 1 筆、35232 回 98 筆）、翻譯鍵 `author_label`／`no_author_data` 等留在原地無人使用、React 端零引用。
- 補回方式改為**伺服器端隨頁面一次 JOIN 取回**（`CodesController::textCodesAuthors()` → `text_authors` prop），順手修掉舊版三個缺陷：舊版走 AJAX 且每列再各查一次 `BIOG_MAIN` 與 `TEXT_ROLE_CODES`（N+1）、`paginate(100)` 會靜默截斷、連 `c_personid=0`（未詳哨兵）也給連結。現行回報真實 `total` 與顯示上限（200；全庫單書最多 98 位），超過時前端明示「共 N 位，僅顯示前 M 位」。
- **可直接跳轉到作者**：每位作者連到 `/app/basicinformation/{id}?tab=texts`（該作者的著述分頁，對齊舊版語義；此路徑正是 `LegacyBladeFormGate` 把舊 URL 導向的目標），另開新分頁以免弄丟表單上未儲存的輸入；`c_personid=0` 不給連結。`BIOG_TEXT_DATA` 主鍵為 `(c_personid, c_role_id, c_textid)`、含 `c_role_id`，同一人可在同一本書掛多個角色，故逐（人物, 角色）成對列出、React key 用此複合鍵；`c_textid` 固定時 `ORDER BY c_personid, c_role_id` 即全序，截斷取的永遠是同一批前 N 筆。
- 這一區是唯讀參考，不可拖垮編輯本身：`appEdit` 的 `try/catch` 在呼叫點之前就結束，故 `textCodesAuthors()` 自行接住例外並降級為 `failed` 態（前端顯示既有的 `codes.load_failed`），對齊舊版「AJAX 失敗只顯示紅字、表單照樣可編輯可儲存」的爆炸半徑。未加此保護時，缺表會讓整頁 500（已用測試反向驗證）。
- `c_textid=0` 不特別排除：它是真實可編輯的「未知」書目列，其下 37 筆關係人是「著作不明」的真實資料（角色含撰著者／編纂者），編目者編輯該列時需要看得到才能重新歸屬；改以 `isset($rowArray['c_textid'])` 區分「真的是 0」與「取不到欄位」。
- 回歸測試 `CodesTextAuthorsTest`（8 tests：單作者含連結／同一人多角色不去重／未詳哨兵無連結／未知書目列仍列出自己的關係人／不跨書洩漏／無作者空清單／非 TEXT_CODES 無此 prop／上限截斷回報真實總數）。另以 headless Chrome 對真實庫驗證 12 項（含 98 位多作者全列與滾動、實際點擊跳轉後落在該作者的著述分頁）。

### 人物編輯中樞切分頁一律重抓資料（修「別人改了、切分頁看不到」）
- 現象（使用者回報 `/app/basicinformation/{id}/edit`）：某條記錄被別人或自己在另一個瀏覽器分頁新增／刪除／修改後，在本頁切分頁（例如別名→親屬→別名）時，分頁徽章計數與列表內容都不變，必須整頁重載才看得到。
- 根因一：`TabContentLoader` 的分頁資料是「一載入就永久快取」（同一 personId 不重複請求），切走再切回沿用首次載入的快照。此前兩個 commit 只修了**自己在本頁的修改**（409fd9e 徽章、7f8d7a8 基本資料切分頁顯示舊值），別人的修改仍看不到。
- 根因二：判斷「已快取」的旗標設在 `setCache` 的 updater 裡、下一行讀取——而 React 只在 eager state 快路徑才會同步呼叫 updater，因此該旗標並不可靠。實測（headless Chrome 對真實庫）13 個分頁裡 7～8 個沿用舊快取、5～6 個湊巧重抓，行為不確定。
- 根因三：提供徽章計數與人物標題的 summary 端點只在掛載時抓一次（依賴不含 `activeTab`），切分頁完全不會重抓。兩個宿主頁（`PersonEditor` 中樞、`PersonBrowser/Index` 主從檢視）都是同一寫法。
- 修法：新增純函式模組 `tabCachePolicy.ts`——**每次「分頁啟用」（personId／activeTab／重載序號任一改變）都重新向後端取資料**。已有資料的分頁在重新驗證期間先繼續顯示舊資料（不閃載入佔位；子資源端點約 13ms 伺服器工作、12 個計數皆為覆蓋索引查詢）；`basic_info` 例外一律等新資料才渲染，因為其內容 `BasicInfoEditor` 在掛載時把欄位值快照進自己的 state、之後不跟 props 同步，先用舊資料掛載會永遠停在舊值、按下儲存還會把舊值寫回覆蓋他人修改。啟用起始狀態在 render 期間標記（React 允許的「隨 props 調整 state」用法）而非 effect 裡，避免編輯器先以舊資料掛載一次再被替換。
- 兩個宿主頁的 summary 依賴加入 `activeTab`：同一人物已有摘要時走靜默更新（不進 loading、失敗沿用舊摘要），並補 `AbortController` 讓慢的舊回應不會蓋掉新回應。每輪一律把 loading 設成「本輪的意圖」而非只在非靜默時設 true——否則「非靜默請求被中止→下一輪是靜默」會讓 `summaryLoading` 永遠卡住，而 `PersonSummaryPanel` 的 loading 分支在 summary 之前短路，整塊摘要面板會空掉（人物 A→B→A、或請求在途中 `person_id` 被移除即可重現）。連帶移除已無作用的 `refreshTabCache`（其職責由「每次啟用都重抓」結構性取代）與 `PersonEditor` 中從未被讀取的 `summaryLoading`／`summaryError`。
- 每筆分頁快取帶一個 activation 戳記，回應落庫前比對：快取只以分頁為鍵，而 abort 發生在 effect cleanup（commit 之後），若回應落在「新一輪已 commit、cleanup 未跑」的空隙，就會把上一個人物／上一輪的資料寫進當前分頁。比對放在 `setCache` 的 updater 內、對 committed state 進行，不在 render 期間寫 ref（那會被丟棄的並行 render 汙染）。`activationKeyOf` 的組成必須與抓取 effect 的依賴**完全一致**（personId／分頁／重載序號／資料端點）——少涵蓋任一項就會出現「effect 重跑卻沿用同一戳記」，兩個請求共用戳記、舊回應可蓋掉新回應，且不重新標記啟用（`basic_info` 會在編輯器仍掛載時被塞進新資料、畫面仍停在舊快照）。此條由測試鎖住。
- 順手補 i18n：`TabContentLoader` 的「載入中…／載入失敗／重新載入／未支援的分頁」原為硬編碼中文。改為一律重抓後這些提示的出現頻率大增（`basic_info` 每次回訪都會短暫顯示），英文語境不應再落回中文，故改走 `person` 翻譯（新增 `reload`／`unsupported_tab` 兩個鍵，zh-TW／en 同步）。
- 回歸測試：`tabCachePolicy.test.ts`（vitest，鎖住「切分頁必須重抓」與 `basic_info` 不可先用舊資料渲染）。另以 headless Chrome 對真實 MariaDB 做端到端驗證：修復前後同一組 23 項檢查（外部新增／刪除／改親屬關係類型／改基本資料欄位在兩個宿主頁切分頁後即時反映；每次切分頁只發 1＋1 個請求、停在同頁不輪詢；快速連點與離線／重試路徑；不回歸 409fd9e／7f8d7a8 的自身修改情境）由 6 項失敗轉為全綠。

### 修改提案改為「同一編輯器＋同一管線重發」（resubmit），廢除通用全欄表單改提案
- 根因追認（op 351725）：「修改提案」原復用 codes 通用編輯頁——按 `Schema::getColumnListing` **全欄**渲染（含四個稽核欄的空輸入框）、儲存時整包回寫 `resource_data` 且無白名單——提案被編輯一次，payload 就被灌入稽核欄 null 鍵，核准重放即撞 handler 白名單 422。「發提案」與「改提案」走不同介面與流程，正是髒 payload 的製造機。
- 新流程：**修改提案＝單一交易內撤回舊提案＋以完全相同的提交流程重發**。新端點 `POST api/v2/proposals/{operation}/resubmit`（`MutationController::resubmit`）：驗擁有權／狀態 → 交易內先把舊提案標 `cancelled`（讓「同主鍵已有待審提案」護欄天然放行）→ 與 `store()` 相同 dispatch 重放 registry handler（mode 強制 proposal）→ 失敗整筆回滾（舊提案回到 pending、handler 欄位級錯誤原樣回給編輯器）→ 成功則舊 meta 記 `superseded_by`、新 meta 記 `resubmit_of`。「編輯後的 payload」與「新提交的 payload」由構造保證一致。
- 前端：operations 列表「修改提案」對人物 12 個子資源改導向**各資源自己的 edit-v2 編輯器**（`?proposal={id}`；update 提案附原列 PK 進 edit 模式）。編輯器 resubmit 模式：提案內容 overlay 蓋在欄位上（不進 baseline、對原列正確算 diff）、預填修改說明、隱藏 direct／刪除按鈕、送出改打 resubmit 端點。codes 表、`BIOG_MAIN` 與 delete 提案維持 codes 通用編輯頁；任官／財產／事件的地址副表意圖（`__proposal_aux`）暫不預填（已知限制）。
- 回歸測試：`ProposalResubmitTest`（同主鍵重發護欄放行／handler 拒絕整筆回滾／眾包用戶 403／已審結 422／預填剔除控制鍵與稽核欄）。

### 稽核欄語義定案＋legacy Blade 表單下架閘門（修核准 422 與「比較」灰按鈕）
- **語義定案（2026-08-05）**：`c_modified_by/date` 一律記「最後一次實際寫入」——核准提案、還原記錄都是寫入，落庫時蓋當下，不從提案 payload 或歷史快照沿用舊值；`c_created_*` 只在 create 蓋、之後永遠沿用。核准署名採雙人名「審核人 (Proposed by: 提案人)」，經新增的 `App\Support\AuditActor`（請求級 override）統一注入 `ToolsRepository::timestamp()` 與各處直接蓋章點（update handler、kinship/assoc 鏡像列、Codes、BiogMain 匯入等）。
- **修核准 422**：提案 payload 是「快照」語義、可能夾帶四個稽核欄（legacy 提案入口無欄位白名單；update 提案 data＝original∪changes 天然含），核准重放 v2 handler 時會被白名單擋成 `disallowed_fields` 整筆失敗（2026-08-05 別名 create 提案實案）。`applyViaMutationHandler` 重放前統一剔除稽核欄（create／update 兩分支），由 handler 重新蓋章；通用路徑 `enforceAuditFieldsForCreate/Update` 同步改為無條件蓋章。restore 兩路徑（update／delete）也改蓋還原人＋還原時刻。
- **legacy Blade 表單下架**：新增 `LegacyBladeFormGate` middleware 把 migration flag 語義做實——flag=new 時 legacy 表單 GET（人物 index/create/edit/show 與 12 個子資源的 index/create/edit）302 導向 `/app` 對應頁（edit.query 的 PK 查詢參數原樣轉發直接進編輯模式），寫入端點（store/update/updateQuery/destroyQuery/proposalStore/proposalUpdate）回 410；flag 改回 old 即完整放行、不需改碼。髒 payload 源頭（無白名單的 `proposalStore`）從此封死；其 `extractFormData` 亦加保險帶剔除稽核欄。
- **修「比較」灰按鈕**：核准改走 handler 重放後 audit_log 掛在新建 direct operation id 上，提案列自身撈不到 audit 而灰掉。核准時把落庫 operation id 寫回提案 payload（`__applied_operation_id`），operations 列表據此把 audit 認領回提案列。（此前核准的存量提案無此指標、仍灰；kinship/assoc bespoke 路徑暫未回報 id——連同三套差異機制的收斂見 `docs/OPERATIONS_COMPARE_CONSOLIDATION_PLAN.md`。）
- 回歸測試：`ProposalAuditFieldSemanticsTest`（髒 payload 可核准／雙人名署名／restore 蓋章／audit 認領）、`LegacyBladeFormGateTest`（導向／410／flag=old 放行）；14 個仍測 legacy Blade CRUD 的既有測試類改在 setUp 撥回 flag=old（`TestCase::useLegacyPersonForms()`）。全量 2423 測試綠。

### 執行時間／記憶體上限改為「只放寬不縮限」，修掉整套 phpunit 跑不完的元凶
- `set_time_limit()`／`ini_set('max_execution_time')`／`ini_set('memory_limit')` 的作用域是**整個 PHP process**，而 PHPUnit 共用單一 process。原本散在 `AiPostingAutofillController`、`QueryPlaygroundController`（SSE）、`Api/ApiController*`、`BiogMainRepository`、`CbdbTableMaintenanceController` 的呼叫——執行時間 13 處（10 處在 class 宣告前的**檔案頂層**、autoload 到就生效）、記憶體 11 處（10 處在頂層）——會把上限套到其後整段測試流程，使全套測試必定被 `Maximum execution time exceeded` 攔腰砍斷，錯誤還指向無關檔案。全套實測需 368 秒，先撞 120 秒（autofill）再撞 300 秒（頂層 `ini_set`）兩層卡點。
- 新增 `App\Support\ExecutionTimeLimit` 與 `App\Support\MemoryLimit`，語義統一為**只放寬、絕不縮限**（現值為 0／-1＝無限制，或已更寬時一律 no-op）。**生產實測（2026-08-03）**：web（php-fpm 8.4）`max_execution_time = 30`／`memory_limit = 1G`，CLI `0`／`-1`。因此舊寫法在生產其實在**降級**——每次 `/api/select/*` 請求被從 1G 壓到 512M，artisan 則從無限制被壓到 300 秒／512M。收斂後 web 放寬行為完全不變，CLI 與測試環境不再被誤傷。
- `Api/ApiController3` 的 600 秒改為 300：生產 fpm pool 的 `request_terminate_timeout = 300s` 會先殺掉 worker，PHP 端寫 600 永遠跑不到，屬虛假餘量。
- `docker/php.ini` 補上「未被任何環境載入、數值與生產不符」的警示標頭（Dockerfile 未 COPY、compose 未 mount）。
- 回歸測試：`tests/Unit/ExecutionTimeLimitTest.php`、`tests/Unit/MemoryLimitTest.php`（皆以子行程驗證正式環境分支，涵蓋放寬／不縮限／無法解析三類）。全套 `phpunit` 首次能一次跑完。

### 依賴安全維護：清空 Dependabot 告警並補上版本更新設定
- `guzzlehttp/guzzle` 7.10.0 → 7.15.2、`guzzlehttp/psr7` 2.11.0 → 2.13.0（連帶 promises 2.5.1、symfony/deprecation-contracts v3.7.1），清掉 9 個 medium 告警，`composer audit` 歸零；`composer.json` 未動（落在既有 `^7.2` 約束內）。影響面最大的是兩個 proxy 相關（CVE-2026-55568 HTTPS proxy 靜默降級、Proxy-Authorization 洩漏給 origin）——三處 LLM 外呼都帶 `Authorization: Bearer`。
- 新增 `.github/dependabot.yml`：composer／npm weekly（minor＋patch 分組成單一 PR、major 逐套件）、github-actions 與 docker monthly（後者用 `directories` 指向 `/docker`、`/.devcontainer`，`directory: /` 找不到 Dockerfile）。**刻意不設 `target-branch`**：Dependabot 建立安全更新 PR 時會忽略設了該欄的設定項，導致分組與 prefix 對安全更新失效。
- 釐清一點：安全更新 PR 由 repo 的 Dependabot security updates 開關控制，**與這份設定檔無關**（#1191、#1205 在本檔加入前就已自動開出）。
- 移除 Laravel Mix／webpack 時代遺留的 `cross-env`／`sass-loader`／`resolve-url-loader`（建置早已改用 Vite，三者在 repo 內零引用；`package-lock.json` 隨之少 1086 行、79 個 webpack 工具鏈條目）。這是 Dependabot 首輪 PR 的實際產出之一——它對這些死依賴開了 major bump PR（#1211、#1213），正確處置是移除而非升級，否則每輪都會重複產生噪音。
- 首輪 8 個 Dependabot PR 的處置：合併 npm minor／patch 群組（#1210，18 個更新，含 Vite 8.2／React 19.2.8／Vue 3.5.40）與三個 GitHub Actions major（#1208 cache、#1209 setup-node、#1215 checkout——其 CI 即以新版 action 跑出綠燈，屬自我驗證）；關閉 #1212（`@inertiajs/react` 2→3 需 `inertiajs/inertia-laravel` 同步升 v3，是協調升級、另立項目）與 #1214（`datatables.net` 1.13→3.0，頂層無任何直接 import、runtime 實際使用 `datatables.net-bs4` 巢狀的 2.3.5，升級只會讓十餘個 1.x 插件包各自複製一份副本；該樹待 Phase 7 AdminLTE 下架時一併處理）。

## 2026-07

### 提案核准段三：BIOG_MAIN 收斂到 v2 handler 重放（人物主檔告別盲寫路徑 C）
- `BIOG_MAIN` 加入 `OperationsProposalController::HANDLER_ROUTED_RESOURCES`，三種操作各按 direct 語義重放：update → `BiogMainMutationHandler`（delta 套用當下資料列＋`BasicInformationRequest` 驗證，「名（中）／拼音名不可清空」護欄改由 handler 統一提供）；delete → `BiogMainDeleteHandler`（軟刪除）；create → `BiogMainCreateHandler`（c_personid 驗證＋欄位白名單）。
- **封掉物理 DELETE 洞**：收斂前若出現 BIOG_MAIN 的刪除提案，通用 `applyDeleteProposal()` 會直接 `DELETE FROM BIOG_MAIN`——與 direct 的軟刪除語義相反，且在入邊 FK 尚為 CASCADE 的觀察期會靜默連鎖刪除 25 張子表資料。現行雖無提交端會產生此類提案，屬防禦性封洞。
- 隨收斂移除不可達死碼：`tableModelMap`、`NO_CLEAR_COLUMNS_ON_APPLY`／`assertNoClearColumns`、applyCreate/UpdateProposal 的 Eloquent 分支。核准失敗訊息攤平 handler 欄位級錯誤（如「名不能為空」）保留審核指向性。
- 詳見 `docs/PERSON_PROPOSAL_PATHS.md` §4.6；回歸測試 `tests/Feature/BiogMainProposalTest.php`（13 tests）。

### 外部資料庫引用瀏覽器改版：/external-db-link、開放活躍帳號、Blade 版下架
- 原 admin/wiki-maintenance 頁面全面翻新：改用共用 DataTable（TanStack）元件，新增搜尋（人名／頁碼標題模糊、純數字比對人物 ID）、白名單欄位伺服器端排序，列表補朝代／指數年／指數地址欄；換頁／排序／搜尋／來源全同步進 URL query，連結可分享復現。
- 權限自「活躍管理員」降為「活躍帳號」（唯讀瀏覽頁；排序／搜尋門檻與 codes 對齊），側欄項目自「管理工具」移入「專家工具」。
- 路徑改為 `/app/external-db-link`（路由 `app.external-db-link`）；Blade 版刪除、無 flag 回退，`/external-db-link` 硬導向 React（同 Query Playground 模式）。後端 `WikiMaintenanceController` 等命名沿用。
- 另修正 `useTranslation` 佔位符取代順序（`:to` 吃掉 `:total` 前綴致「160tal」）；`DataTable` 新增可選 `getRowId` 供複合主鍵列使用穩定 key。

### 實體聚合橫向複用架構（entity aggregate framework，#1159 §6.5）
- 行為凍結重構：把 office／social institution 兩輪實作中重複的機制抽成五件套，後續實體（code+type-rel 家族、text、place）不再整套重寫。
- **`config/entity_aggregates.php` 實體註冊表（單一真源）**：聲明 resource／Service／`definition`／識別鍵／認領表／`closed_code_tables`／側欄 nav。codes UI 封寫改由 registry 推導（`isReadOnlyTable()`，實體上線即自動封寫、回退＝改 config）；側欄實體節點由 `Navigation::entityNavItem()` 依 config 生成。
- **`EntityAggregateService` 介面**＋`SharesImportHelpers` 新增三基元：`allocateNextId()`、`countReferences()`、`reconcileRowSet()`（配套列集合對賬：同鍵改非鍵值、僅增刪差異、逐筆記 op）；兩個 Service 遷移使用，audit payload 逐位不變。
- **通用 mutation handler ＋ `EntityAggregateDefinition` 契約**：`EntityAggregateCreate/Update/DeleteHandler` 三個通用 handler 承擔授權／resource 分派／pk 解析／404／交易／回應信封等共通骨架，取代原本每實體各 3 個 handler——office／social institution 從 **6 個 bespoke handler 收斂為 2 個 definition**（各只實作 validate／guardWrite／result 三處真差異）。單筆與 `batch_mutate` 皆自動生效。回應逐位不變（唯 type_label／dynasty_label 未解析的 422 訊息由專屬文案改為通用「參數校驗失敗」，errors payload 不變、無測試斷言該文案）。
- **`Support/EntityTableBrowser`**：描述子驅動的 parity 列表引擎，兩個實體 Controller 的 appIndex 各縮至 ~20 行；刻意不合併 CodesController（cursor 分頁／JOIN config 為裸表專屬）。
- **前端 `EntityIndexPage` 通用組件**：兩份 ~380 行的 Index.tsx 合併，各實體頁縮成注入 `{i18nGroup, resource, pkField, dynastyColumns}` 的薄殼。表單刻意不抽象（真領域 UI）。
- 設計文件新增 §6.5（含通用 handler 分派層、第二梯隊 code+type-rel 家族路線與 §4.5 實體級提案「對介面做一次」的落地路徑）。
- 全套測試 2310 綠（行為凍結驗證）；無新增功能。

### 社會機構實體聚合推進至 step 4：/app/social-institution 上線、三張裸表封寫（#1159）
- **實體識別定案＝`c_inst_code` 單鍵**：生產庫 4011 列 c_inst_code 全數唯一，複合主鍵 `(c_inst_code, c_inst_name_code)` 是「當前名稱冗餘進主鍵」的儲存層遺留；`c_inst_name_code` 為屬性、由聚合根內部解析（名稱去重）。詳見設計文件 §2.5。
- `SocialInstituteImportService` 補齊 `load()／update()／delete()／referenceCount()`：update 整體覆寫 CODES 非鍵欄、名稱走去重解析、ADDR 集合對賬（同鍵改值、僅增刪差異）；referenceCount 數齊 **BIOG_INST_DATA／ENTRY_DATA／ASSOC_DATA／POSTED_TO_OFFICE_DATA 四張** CASCADE 引用表。
- 新增 `SocialInstituteUpdateHandler`／`SocialInstituteDeleteHandler`（resource=social-institution）：刪除護欄（被引用回 409）；**改名護欄**（被引用時改名回 409——人物表存 (inst_code, name_code) 對，改名會使既存引用失配）；孤兒名碼不回收。
- `/app/social-institution/*` 三頁：Index 與裸表頁 feature parity 超集（全欄位、排序、逐欄＋布林篩選、公開讀＋排序篩選登入門檻，加機構名 joined 欄與地址數計算欄）；Create 對齊批量匯入語義；Edit 為全欄位＋多地址列編輯，被引用時名稱欄預先鎖定並提示。
- 側欄「社會機構編碼表」改指 `/app/social-institution`；`SOCIAL_INSTITUTION_CODES`／`NAME_CODES`／`ADDR` 三表加入 `$readOnlyTables` 封寫（讀取開放；`SOCIAL_INSTITUTION_TYPES` 為扁平字典維持可寫）。回退＝自清單移除。
- 測試 `tests/Feature/ApiV2MutateSocialInstituteEntityTest.php`、`tests/Feature/SocialInstitutionEntityIndexTest.php`；設計文件 §2.5／§5 同步更新。

### 官職實體聚合推進至 step 4：/app/office 成為唯一寫入入口、OFFICE_CODES 裸表封寫（#1159）
- `/app/office` 列表補齊與 `app/codes/OFFICE_CODES` 的 feature parity 並成為超集：全部 OFFICE_CODES 欄位、任意欄排序＋主鍵 tie-breaker、逐欄篩選（含 AND/OR/NOT 布林模式，复用 `ColumnFilterExpression` 與 codes 同組 i18n）、關鍵字全欄搜尋、朝代標籤、全表匯出連結，另加聚合特有的 `type_count` 計算欄（OFFICE_CODE_TYPE_REL 關聯數，exact 比對）。
- 訪問模型對齊 codes 頁：列表公開可讀；排序／篩選需登入且已激活（鏡像 `guardSortFilterRequiresAuth`）；新增／編輯／刪除仍需 `canWriteDirectly`。
- 側欄「任官編碼表」改指 `/app/office`（`Navigation::officeEntityItem()`），active 保留 `OFFICE_CODES` page-title 相容直訪裸表頁。
- `OFFICE_CODES` 加入 `CodesController::$readOnlyTables`：裸表 create／edit／update／destroy／proposal 全部封閉（讀取與匯出開放）。裸表 proposal 一併封閉是實體級提案（§4.5）就緒前的有意取捨；回退＝自 `$readOnlyTables` 移除一行。
- 測試 `tests/Feature/OfficeEntityIndexTest.php`；設計文件 §5 差距表同步更新。

### mutation API 支援 code 表寫入（先接 TEXT_CODES，resource=text-codes）
- 讓單主鍵 code 表可經 `/api/v2/{create,delete}` 與 `batch_mutate` 機器化寫入（token、`operations` + AuditLog、可回滾），補上目前 codes 網頁表單/書目導入工具缺的統一審計。
- config 驅動（`config/code_table_writes.php`）：每張表定 `resource/aliases/table/key_column/auto_assign_id/allowed_fields`。新增 `CodeTableCreateHandler` / `CodeTableDeleteHandler`（獨立於 person-subresource 基底，因 code 表無 c_personid）。
- **create 支援「顯式主鍵」與「服務端自動分配 id」（`max(key)+1`）兩種**：前者供補指定 textid（如 merge-recovery 的證據源）、後者供書目等批量新增；並發撞號由唯一鍵兜底 409。
- `CompositePrimaryKey::SCHEMAS` 補 `TEXT_CODES => (c_textid)`（供 Operations/Restore 解析 resource_id）。
- 註：既有 `AdminBatchLoadBookTitlesController`（書目導入）暫維持現狀（有 operations、無 audit_log），後續可改走此通道統一。
- 測試 `tests/Feature/ApiV2MutateCodeTableTextCodesTest.php`（顯式/自動分配/409/422/delete/batch）。

### mutation API 支援 MERGED_PERSON_DATA（合併人物記錄，resource=merged-person）
- 讓「補錄已刪人物併入哪個 survivor」的合併映射可經 `/api/v2/{create,delete}` 與 `batch_mutate` 機器化寫入（原本僅 `codes` CRUD 網頁表單直接 insert、無 operation_id）。
- 新增 `MergedPersonCreateHandler` / `MergedPersonDeleteHandler`（沿用 `AbstractPersonSubresourceCreate/DeleteHandler`），走既有授權（`canWriteDirectly`）+ `operations` + AuditLog → 可回滾；`CompositePrimaryKey::SCHEMAS` 補 `MERGED_PERSON_DATA => (c_personid, c_merged_from_personid)`。
- 語意注意：`c_merged_from_personid` 是**刻意的已刪 id**，僅做「person_id 與 PK 內 c_personid 一致性」校驗、**不對 BIOG_MAIN 做存在性檢查**；可寫欄 `c_notes`（合併原因，會由 `CbdbApiController::buildMergeHint` 展示）、`c_source`、`c_pages`。
- 因 batch_mutate 逐筆復用 handler dispatch，單筆與批次端點同時生效，無需分別改。
- 測試 `tests/Feature/ApiV2MutateMergedPersonTest.php`（create/409/person 不一致/batch/delete/已刪 from-id 不被校驗）。

### 新增批次變更端點 `POST /api/v2/batch_mutate`
- 一個請求帶多筆 `items`，逐筆分發到既有 `MutationHandlerRegistry` handler，**完全沿用單筆端點的校驗／改鍵碰撞偵測／授權／`operations`＋AuditLog**，不另起平行寫入邏輯；用於消除逐筆 HTTP 往返與限流（429）成本。
- `atomic=false`（預設）：逐筆獨立結算，單筆失敗不影響其餘，回 200 + `results[]` + `summary{total,ok,failed}`（`body.ok`＝是否全數成功）。
- `atomic=true`：整批單一交易，任一筆失敗整批回滾（handler 內層交易降為 savepoint），回 409 + `failed_index`。
- 支援頂層 `resource/mode/operation/meta` 預設（逐項可覆寫）；單次上限 `BATCH_MAX_ITEMS=500`（超過回 422）；單筆未預期例外隔離為該筆 500，不拖垮整批。
- 端點列入 CSRF 豁免、`auth.optional`；`direct` 寫入仍需 `canWriteDirectly()`。
- 測試 `tests/Feature/ApiV2MutateBatchTest.php`：missing-items/over-limit 422、非原子部分成功、原子全成、原子失敗回滾、頂層預設合併、群眾外包 direct 403。

### 修復 operations 表缺索引導致每筆 create 全表掃描（生產穩定性）
- **問題**：所有子資源 create 都會對 operations 做「pending 提案」預檢（`WHERE resource=? AND resource_id IN(?) AND op_type=?`，見 `AbstractPersonSubresourceCreateHandler` / `SourceMutationHandler` / `PostingCreateHandler`），但 operations 僅有 `PRIMARY(id)` 與 `KEY(c_personid)`，`resource`/`resource_id` 無索引 → 每寫一筆就**全表掃描一次** operations（該表隨每次 mutation 持續增長）。批次/並發寫入時大量並發全表掃描飽和 DB、堆積慢查詢、推爆 php-fpm（與 /codes 深分頁那次生產癱瘓同一模式）。
- **修復**：新增 migration 為 operations 補 `(resource, resource_id, op_type)` 複合索引（`2026_07_12_000000_add_resource_index_to_operations_table`），把預檢由全表掃描收斂為索引 seek。
- ⚠ **部署**：operations 表大，`ADD INDEX` 於 MariaDB 10.3 為 ONLINE（不長鎖表）但需建置時間，建議低峰執行。
- 後續可再收斂 `hasPendingCreateProposal` 系列由 `get()->contains()` 改為 `exists()` 語義（另議）。

### 中文維基連結改走 mutation API 增量維護（BIOG_SOURCE_DATA）
- 確立中文/英文維基與 Wikidata 連結在 CBDB prod 存於 `BIOG_SOURCE_DATA`（`c_textid` 60795/68943/68942，`c_pages` 存條目標題），維護一律走 `/api/v2/{mutate,create,delete}` 的 `sources` 資源（`direct`、Bearer PAT、CSRF 豁免，寫入靠 `canWriteDirectly()`），每筆有 `operation_id` 可回滾。
- **`WikiMaintenanceController`（全量刪除重灌）標記為僅限首次導入**，不得用於增量新增／修正；[docs/WIKI_TASK_MANAGEMENT.md](docs/WIKI_TASK_MANAGEMENT.md) 加說明橫幅。
- 批次追溯：direct 模式 `meta.comment` 不落庫，故批號寫入 `c_notes`（`日期 | 操作者 | batch_id`，batch_id = 來源報表內容 hash）。
- 新增技能 [.claude/skills/mutation-api-record-editing.md](.claude/skills/mutation-api-record-editing.md) 與流程文檔 [docs/ZHWIKI_SOURCE_SYNC.md](docs/ZHWIKI_SOURCE_SYNC.md)；執行腳本 `cbdb-dbs/d1_build_*/round3/sync_zhwiki_sources.py`（dry-run 預設、分批、429 退避）。

### `/codes` 排序／篩選功能加登入門檻（僅 React/Inertia 版）
- 背景：一次生產環境癱瘓事後分析發現，`/codes/{TABLE}?sort_by=...` 這類深分頁＋任意欄位排序／前導通配符 filter 查詢先於請求量異常變慢，推擠掉 php-fpm worker 拖垮全站。
- `app/codes/{table_name}`（`CodesController@appShow`）新增 `guardSortFilterRequiresAuth()`：請求帶 `sort_by` 或非空 `filters[...]` 時，未登入導向 `login`（記錄 intended URL）；已登入但未激活（`Auth::user()->isActive()` 為 false）改用 flash 訊息 + `redirect()->back()`（避免被 `login` 路由的 `guest` middleware 攔截）；已登入且已激活不受影響。無 sort/filter 的基礎瀏覽維持公開，不需登入。
- **Blade 版 `codes/{table_name}`（`CodesController@show`）本輪刻意不處理**，維持現況無門檻——若之後把 `codes` migration flag 切回 `old`，需重新評估。
- React 前端（`Codes/Show.tsx`）加對應 UX 提示（排序表頭/套用篩選按鈕在未激活時顯示提示與 disabled 樣式），純體驗加分，非防線。
- 設計、風險取捨、測試計劃詳見 [docs/CODES_SORT_FILTER_AUTH_GATE.md](docs/CODES_SORT_FILTER_AUTH_GATE.md)。

## 2026-06

### React / Inertia 遷移正式上線（已列頁面 flag 全翻 new；機制 `default` 仍 old）
- 全站可遷移的互動頁面 feature flag 由 `old` 翻為 `new`（`config/migration_flags.php`）：人物列表/檢視/詳情中樞、**13 個 React 編輯器**（basic-info + 12 個複合主鍵子資源：altname / addresses / texts / sources / offices / assoc / kinship / events / statuses / entries / possession / socialinst）、Codes CRUD、operations / manage / crowdsourcing、admin 日誌與批次工具、認證頁 / welcome 等，現以 React/Inertia 為**線上預設**。
- 上線採 **gate-before-flip**：每頁先做新舊機器逐項對比（內容/欄位/說明文字/字體/導流/視覺）+ review agent + codex 雙閘，差異清單清空且使用者人工逐頁驗收後，才翻 `new`（見 [docs/REACT_MIGRATION_SIMULATION_TEST_PLAN.md](docs/REACT_MIGRATION_SIMULATION_TEST_PLAN.md) §0）。
- 人物詳情中樞（`/app/basicinformation/{id}`）改用 legacy 風格 PersonBanner + 子資源分頁；重建年號轉換 React 元件（EraTimeField）、CHGIS place-link；補齊版面/互動/必填/改鍵 parity，子資源存檔後導向新記錄 edit 頁供複查（#120）。
- **回退保證**：舊 Blade 視圖與路由**未刪除**，flag-gated 頁面回退只需把對應 flag 改回 `old`（可逆、不需改碼）。例外：Query Playground 無主頁 flag、`/query-playground` 硬導向 React 版，不走 flag 回退。AdminLTE 實體下架（Phase 7）尚未執行，故本階段「下線」指**下線為線上預設、舊版保留供回退**，非移除。
- 清理 legacy-parity 臨時測試組（#68，刪 18 個耦合舊路徑的 M 寫入等價測試）。

### 親屬／社會關係雙向鏡像「行內化」（編輯器內確認閘）
- 互逆鏡像的「單邊補建」與「一對多／多對多人工裁決」由專屬 admin 修復頁搬進一般編輯器的點擊/存檔場景，以「鏡像寫入前確認閘」（409 → 彈窗列出將影響的人物/列 → 確認後落庫）處理；專屬修復頁降級為「暫不公開」。
- 提案核准路徑補上 #66/#70 鍵碰撞/鏡像偵測（與 direct 對等，#77、#82、#117）。全情境對真實資料庫實測通過（見 [docs/RELATIONSHIP_MIRROR_INLINE_DESIGN.md](docs/RELATIONSHIP_MIRROR_INLINE_DESIGN.md) §11.1）。

### 人物搜尋 ü／v 互通（#85）
- CBDB 拼音以 `v` 儲存 `ü`、collation 視 `ü≈u`；查詢端統一規範化 `ü→v`，移除「ü→v 替代」提示，7 個搜尋入口一致。

### 編輯器一致化收尾（#116–#123）
- 全 13 編輯器版面/必填標註/碼欄改鍵/按鈕字號一致化；出處 source 編輯期開放改鍵（#116/#117）。
- i18n：補齊編輯器英文缺漏 key、修年號對話框換行、譯名對齊 CBDB 既有術語（#119）；欄位重排（類型/角色/次序）依使用者建議調整（#118/#121/#122/#123）。

### v2 子資源 mutation 資料安全：雙向鏡像同步 + sentinel 完全幂等
- **雙向鏡像衝突偵測（#66）**：社會關係（ASSOC）／親屬（KIN）改動會同步對面互逆鏡像列；若對面對應欄已被獨立改成不同內容，改為**警告 + 可點連結跳對面 + 強制覆寫（meta.force）**，不再靜默覆寫（409 `errors.mirror_conflict`）。
- **鏡像「疑似匹配」（#70）**：嚴格定位（碼∈合法反向集）落空、但放寬查到對面有「碼漂移（∉ 合法代碼表）」的疑似同關係列時，不再靜默 backfill 補出重複鏡像，改為 409 `errors.mirror_suspected`（候選 PK + 權威反向碼）→ 前端跳對面 + 強制就地收斂。**Option 2 安全**：碼∈合法 code 的列視為他段合法關係**絕不覆寫**，只就地收斂純漂移垃圾列。UPDATE 與 **CREATE（#72）** 兩路徑皆覆蓋；子資源「edit 一條對面不存在的鏡像」改為優雅降級頁（取代硬 404）。
- **sentinel 完全幂等（#71）**：legacy 哨兵 0=Unknown 的碼/FK 欄（c_source 等），`null / '' / -999 / 0 /（CREATE 缺鍵）` 落庫一律為 0、**永不寫 null/''**，達成新舊前端寫入完全一致。修正 possession create 缺 c_source 時 legacy `possessionStoreById` 的 undefined-index（direct 與 proposal-核准兩路徑）。
- 互逆鏡像反向關係碼一律以代碼表權威配對碼（ASSOC_CODES / KINSHIP_CODES）補齊，不再洗成哨兵 0（「未详」）污染對方人物關係。
- 以「M 寫入等價」維度（舊版寫入為 ground truth）系統性回歸；全量 phpunit 1918 綠。

### `/api/v2/persons` 新增 c_created_date / c_modified_date（人物層級修改水位線）
- `/api/v2/persons` 每筆人物新增輸出 `c_created_date`（建檔時間，取自 BIOG_MAIN）與 `c_modified_date`（人物**任何**資訊——本體或子資源——最後修改時間）。
- `/api/v2/persons` 新增 `modified_since` 查詢參數供增量同步（只回傳 `c_modified_date >= modified_since` 的人物，含邊界）；嚴格格式守衛 + 時區正規化，無法辨識則忽略（回全部）；命令 `--since` 共用同套規則。水位線納入建檔時間，確保「只有建檔時間、從未被改」的人物不被漏抓。
- `c_modified_date` 為人物聚合層級水位線，存於新 sidecar 表 `person_change_index`，與 BIOG_MAIN 本表 `c_modified_date`（僅本列語意）分離互不污染。
- 日常由 `AuditLogService::logChange()` 收斂點即時維護（`DB::afterCommit` 交易外 best-effort，失敗由 rebuild 補回）；新增 `php artisan cbdb:rebuild-person-change-index` 供初始全量回填、定期校正、手動刷新（NULL-safe GREATEST upsert、c_personid 範圍分段、named lock、省資源；支援 `--since/--id-from/--id-to/--person/--prune/--chunk/--commit-interval`）。
- ⚠ **部署注意**：migration 只建表不回填，部署後須**手動執行一次** `php artisan cbdb:rebuild-person-change-index`，否則 `c_modified_date` 全為 null。
- 為 audit_log 補 `(table_name, occurred_at, id)` 複合索引以支撐 rebuild 的 keyset 掃描。
- 設計與細節見 [docs/PERSON_CHANGE_INDEX_DESIGN.md](docs/PERSON_CHANGE_INDEX_DESIGN.md)。

### CHGIS 地圖：Place Name 可點擊連結與浮出地圖
- `/basicinformation/{id}/addresses` 與 `/offices` 列表頁的 **Place Name**，對「有有效經緯度」的地點渲染為可點擊連結；無效座標（0,0、單軸為 0、超出底圖範圍、經緯反掉等）維持純文字。
- 點擊浮出以 `chgis_map.mbtiles` 為底圖的 Leaflet 地圖（無邊框、背景變暗模糊、Esc/遮罩/×關閉、手機近全螢幕、`prefers-reduced-motion`），標出該人物所有有效 addresses/offices 地點，當前點置中並以脈動標記突顯。
- 底圖不入版控，部署時由 `php artisan cbdb:fetch-chgis-map` 自 HuggingFace（`cbdb/chgis-map`）下載至 `storage/app/chgis/`；缺檔時亦於首次存取地圖時背景下載並顯示提示。
- 官職地點分組鍵改為 `(c_office_id, c_posting_id)`，前端點位 key 使用 `office:{office_id}:{posting_id}:{addr_id}`，避免 `c_posting_id` 非全域唯一時官名誤配與 key 碰撞。
- lazy 下載加入 `Cache::lock()` 互斥、`ttl > timeout` 與 `started_at` stale 自癒，避免大型底圖下載時永久卡在 `downloading`。
- 座標有效性判定集中於 `App\Support\CoordinateValidator`（設定見 `config/chgis_map.php`）。
- 設計與實作細節見 [docs/CHGIS_MAP_PLACE_LINK.md](docs/CHGIS_MAP_PLACE_LINK.md)。
- 前端新增 `leaflet` npm 依賴與 `resources/js/chgis-map` 入口（不使用 CDN）。

### 繁體中文 / 英文介面切換（i18n Phase 6）
- 全站 Blade 視圖完成繁體中文／英文雙語化（約 91 個檔案、3,450 行字串）。
- Navbar 新增語言切換按鈕（zh-TW ⇄ EN），使用者偏好儲存於 session。
- 系統預設語言維持繁體中文（`zh-TW`）；新增 `SetLocaleMiddleware` 處理 session / cookie / Accept-Language 偏好解析。
- 關鍵翻譯群組：`biogmains`（人物編輯表單）、`admin`、`auth`、`operations`、`person`、`common` 等均已對應 `en` 與 `zh-TW` 翻譯檔。
- 測試基礎設施：`tests/TestCase::setUp()` 覆寫 `HTTP_ACCEPT_LANGUAGE` 為 `zh-TW`，避免 Symfony 預設英文標頭干擾 CI。

## 2026-03

### Query Playground / Historical QA
- React/Inertia 版 Query Playground 已成為主要入口：`/app/query-playground`。
- 持續收斂自然語言問答、SQL Playground、QBE 設計器與共用後端接口。
- SSE 穩定性改善：
  - 補上 keep-alive comment 與 padding，降低代理與瀏覽器緩衝影響。
  - LLM 等待、重試與工具執行階段皆可送出 heartbeat。
  - 客戶端中斷連線後，可在更多執行階段提早停止。
- `WITH RECURSIVE` 查詢現已通過 Query Playground 與 MCP 唯讀 SQL allowlist 檢查。
- `SqlTableNameExtractor` 補強 fallback 與回歸測試，涵蓋：
  - recursive CTE
  - 逗號分隔 `FROM` 子句
  - comments / string literals
  - CTE alias 過濾

### Person Browser
- 12 個 tab 元件改用穩定複合主鍵作為 React key，不再使用陣列下標。
- `stableKey()` 改為 `JSON.stringify(pk)`，避免分隔符、`null`、空字串造成碰撞。
- `PersonBrowser` 的 `pk` 結構與 `CompositePrimaryKey::SCHEMAS` 之間新增更多回歸測試。

### 複合主鍵與子資源一致性
- 持續整理子資源 `pk`、URL 查詢參數模式與 mutation handler 的一致性。
- ALTNAME_DATA 主流程維持 3-key；舊格式僅保留相容層。
- `POSTED_TO_OFFICE_DATA` / `POSTED_TO_ADDR_DATA` 的主鍵、resource_id 與 operation log 行為持續收斂。

## 2026-02

### SQL / QBE / Schema 查詢
- Query Playground 新增 Query by Example（QBE）設計器。
- 新增 `query-playground/schema` API，供前端動態載入白名單資料表欄位資訊。
- 年號、地址與其他查詢 UI 的過濾與排序體驗持續改善。

### 資料與同步
- SQLite 匯出與每週同步流程持續穩定化。
- 多筆 migration 補強 MariaDB / SQLite 相容性。

## 2025-12

### 平台升級
- Laravel 升級至 12.x。
- PHP 最低需求提升至 8.2+。
- 前端完成 AdminLTE 3 + Vite 遷移。
- API 認證主線切換至 Sanctum。

### 重要功能落地
- Query Playground、自然語言轉 SQL、Historical QA、MCP 唯讀查詢能力落地。
- 多個 Basic Information 子頁面與提案 / 審核流程完成重構與擴充。

## 參考文檔
- [README.md](./README.md)
- [AGENTS.md](./AGENTS.md)
- [docs/UPGRADE.md](./docs/UPGRADE.md)
- [docs/APPROVAL_FLOWS.md](./docs/APPROVAL_FLOWS.md)
- [docs/VIEWS.md](./docs/VIEWS.md)
