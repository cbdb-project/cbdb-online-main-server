# Query Playground QA 模式多輪追問功能 — Work Plan

> 狀態：規劃中（僅文件，尚未實作）
> 本規劃文件僅提供繁體中文版本；不代表功能實作範圍排除英文 i18n——依 `AGENTS.md` 第 6 節規則，新增的 UI 字串（第 4.3 節）仍須比照專案既有慣例同步維護 `resources/lang/zh-TW/*.php` 與 `resources/lang/en/*.php` 兩份翻譯檔，不可只做繁中。
> 對應功能：`/app/query-playground?mode=qa`（歷史問答 QA 模式）
> 參考範例：Google 搜尋結果 AI Overview 的「Ask anything」追問輸入框——首次答案生成後，答案下方會持續顯示一個輸入框，並附上「如果你想，我可以說明：」建議追問清單，使用者可針對同一上下文繼續提問。

## 1. 背景與目標

目前 `HistoricalQaPanel.tsx` 是**單輪問答**：每次送出問題都會呼叫 `resetResults()` 清空畫面，後端 `NaturalLanguageQueryService::answerQuestion()` 每次都是全新的 `messages = [system, user]`，沒有任何先前輪次的記憶。使用者若想追問（例如「這個人的兒子是誰？」承接上一題的人物），必須重新打一次完整脈絡的問題，體驗與 Google AI Overview 改版前一致。

**目標**：讓使用者在得到第一次回答後，可以在同一個對話串中繼續追問，模型能理解先前輪次的上下文（指代消解、延伸提問），並比照 Google 的體驗，在答案下方持續顯示輸入框、可選擇性提供建議追問問題。

**非目標（本次不做）**：
- 不做跨裝置/跨瀏覽器的對話同步。
- 不做對話分享/匯出功能。
- 不改動 SQL 模式（`mode=sql`）、QBE 模式、NL→SQL 模式，僅針對 QA 模式。
- 不重新設計 SQL allowlist / CTE 驗證邏輯（沿用現有 `ReadOnlyTableQueryService`/`SqlTableNameExtractor`，每輪、每個 tool call 仍必須完整跑過驗證，不可因為「歷史紀錄」而跳過或快取驗證結果）。

## 2. 現況摘要（詳細調查見下方附錄 A）

- **前端**：`resources/js/inertia/components/QueryPlayground/HistoricalQaPanel.tsx` 是唯一的 QA UI，純單輪、無任何對話/聊天元件可重用；client state 全部是單一答案的欄位（`answerMarkdown`/`summary`/`sqlUsed`/`toolCalls`/`evidence`/`caveat`），沒有訊息陣列。
- **後端路由**：`POST /query-playground/answer-from-nl`（JSON）與 `POST /query-playground/answer-from-nl-stream`（SSE），皆由 `QueryPlaygroundController` 處理，僅接受 `{question, tables?, use_tools?}`，無 conversation/session 概念。
- **服務層**：`NaturalLanguageQueryService::answerQuestion()` 已經是 OpenAI 相容的 `messages[]` 陣列（`[system, user]`），是最自然的多輪擴充點——只要在送給 LLM 前，把先前輪次的 `{role:user}`/`{role:assistant}` 訊息插入陣列即可。工具調用迴圈（`executeToolCalls`）、SQL allowlist（`ReadOnlyTableQueryService`）皆是每輪獨立執行，不需改動邏輯本身。
- **持久化**：目前完全沒有對話/訊息儲存表，只有 `nl_query_logs`（稽核用途，非對話來源），沒有 `conversation_id`/`turn_index` 欄位。
- **測試**：`tests/Feature/HistoricalQaTest.php` 已對現有單輪 request/response contract 有明確斷言，多輪功能必須維持向後相容。
- **設定**：`nl_query_tools.max_tool_calls` 有本地預設值 20（QA 呼叫處）與全域設定預設值 40 不一致，屬既有小問題，可在本次一併修正並記錄。

## 3. 功能範圍與使用者流程

### 3.1 對話生命週期
1. 使用者在 QA 模式輸入第一個問題並送出（現有行為不變：勾選隱私同意、選填 `use_tools`/`use_streaming`）。
2. 後端建立一個新的「對話」（`conversation_id`），回傳答案的同時帶回 `conversation_id`。
3. 前端在答案下方顯示：
   - 一個常駐的「繼續提問」輸入框（比照 Google 的 "Ask anything"）。
   - 可選的建議追問問題清單（LLM 產生，點擊後帶入輸入框，**不自動送出**，見 4.1 節定案）。
4. 使用者輸入追問，前端把 `conversation_id` 一併送出；後端把該對話先前輪次的 `question`/`summary`（非完整 `answer_markdown`，見 7.3 節 context 截斷規則）組成訊息歷史，插入新一輪的 `messages[]`，再呼叫既有的工具調用迴圈。
5. 畫面以「訊息串」方式往下累加顯示（Q1/A1、Q2/A2 …），而不是現在的「送出即清空重繪」。
6. 使用者可以「開新對話」按鈕捨棄目前對話，回到全新單輪狀態（等同目前行為）。

### 3.2 與 Google 範例的對應關係
| Google AI Overview | 本功能對應設計 |
|---|---|
| 答案下方常駐 "Ask anything" 輸入框 | QA 面板答案卡片下方常駐追問輸入框（取代現有「送出後消失」的表單） |
| "If you'd like, I can explain: ..." 建議清單 | LLM 於回答 JSON 中額外輸出 `suggested_follow_ups: string[]`，前端渲染為可點擊 chip |
| 追問會延續同一個上下文 | `conversation_id` + 後端組裝歷史 `messages[]` |
| 對話僅存在當次瀏覽（重新搜尋才重置） | 本功能對應：對話存在於前端 state + 後端資料表，重新整理頁面是否保留見第 10 節「待決策事項」 |

## 4. 前端設計

### 4.1 元件改造
- `HistoricalQaPanel.tsx` 由「單一答案欄位」改為「訊息陣列」狀態：
  ```ts
  interface QaTurn {
    id: string;            // 前端產生的 turn 識別（供 React key、串流更新用）
    question: string;
    status: 'pending' | 'streaming' | 'done' | 'error';
    answerMarkdown?: string;
    summary?: string;
    sqlUsed?: string[];
    toolCalls?: ToolCallTrace[];
    evidence?: Evidence[];
    caveat?: string;
    suggestedFollowUps?: string[];
    error?: string;
  }
  const [conversationId, setConversationId] = useState<string | null>(null);
  const [turns, setTurns] = useState<QaTurn[]>([]);
  ```
- 現有 `resetResults()` 只在「開新對話」時呼叫；一般送出追問改為 `appendTurn()`。
- SSE 事件處理（`handleStreamEvent`）需要知道「目前是在更新哪一個 turn」——用 `turns` 陣列最後一筆（狀態為 `pending`/`streaming`）作為目標，沿用現有的 `tool_execution_start`/`tool_execution_complete`/`complete`/`error` 事件語意，只是寫入位置從單一 state 改為 `turns[lastIndex]`。
- `ToolTracePanel` 需要能夠按 turn 分組顯示（目前是單一陣列平鋪），或每個 turn 各自渲染一個 `ToolTracePanel` 實例（較簡單，建議採用此法，改動面小）。**注意**：`tool_execution_start`/`tool_execution_complete` 這兩個 SSE 事件目前是直接對單一扁平陣列 `setToolCalls` 做 append/尋找更新；改成 `turns[]` 後，每次事件都要先定位「目前進行中的 turn」（例如 `turns` 中 `status==='streaming'` 的那一筆），再對該 turn 的 `toolCalls` 子陣列做不可變更新（immutable update），不是單純換一個陣列名稱而已，實作時要留意巢狀 state 更新寫法。
- Markdown 渲染沿用既有 `renderMarkdown()`（hand-rolled regex），不需更換。
- 輸入框行為：
  - 首次提問維持現有隱私同意勾選 + textarea + 送出鈕。
  - 有 `conversationId` 之後，隱私同意勾選只需顯示一次（第一輪），後續輪次輸入框精簡為「Ask anything」樣式（textarea + 送出鈕），不重複顯示同意勾選/`use_tools`/`use_streaming` 選項。**明確定案**：`use_tools` 由 `qa_conversations.use_tools`（第一輪決定）固定沿用整個對話；追問請求即使 body 帶了 `use_tools`，後端一律忽略、以 conversation 已存的值為準（前端追問請求乾脆不再送 `use_tools` 欄位，避免混淆）。`use_streaming` 純屬前端串流呈現方式的選擇，與後端無關，可每輪各自選擇不受此限制。
  - 追問輸入框需在「上一輪回答完成後」才可互動（避免同一對話並發兩個進行中的請求）。
- 建議追問 chips：LLM 回傳 `suggested_follow_ups`（0–4 筆），渲染為可點擊按鈕，點擊後直接帶入輸入框文字（不自動送出，讓使用者可修改，比照 Google 行為——Google 的建議點擊後也是先展開細節而非直接送出新查詢，故採用「帶入輸入框」較保守安全）。
- 「開新對話」按鈕：清空 `turns`、`conversationId`，回到初始畫面。
- 中斷/取消（`handleCancel`）需綁定到「目前進行中的 turn」，行為不變，只是作用目標改變。

### 4.2 對話狀態的生命週期（前端）
- 對話只存在於當前頁面的 React state（不使用 localStorage/sessionStorage），重新整理頁面即遺失，對應 Google 目前的行為（重新搜尋才會重置，但同一頁籤內的追問是延續的）。是否要加裝置端持久化留待第 10 節決策；若不做，實作範圍可縮小。

### 4.3 需要新增的翻譯字串（`resources/lang/zh-TW/query.php` 與 `resources/lang/en/query.php` 或既有 query 語系檔，兩份同步新增，依 `AGENTS.md` i18n 規則不可只維護其中一份）
- `qa_follow_up_placeholder`（追問輸入框提示文字）
- `qa_new_conversation`（開新對話按鈕）
- `qa_suggested_follow_ups_label`（建議追問標題）
- `qa_turn_of`（可選：第 N 輪 標示）
- 沿用現有 `qa_*` 系列鍵值（`qa_ask`/`qa_answering`/`qa_cancel`/`qa_error` 等）不需重複新增。

## 5. 資料持久化設計

### 5.1 新增資料表（需同時相容 MariaDB 與 SQLite，比照 `nl_query_logs` migration 風格，使用 Schema Builder，禁用 `COMMENT`/`ENGINE` 等原生語法）

**`qa_conversations`**
| 欄位 | 型別 | 說明 |
|---|---|---|
| `id` | `char(36)` / UUID primary key | 對話識別，前端與後端共用此 ID |
| `user_id` | `unsignedBigInteger`，外鍵 `users.id`，`onDelete('cascade')` | 對話擁有者；使用者刪除帳號時一併清除其對話與訊息（對話為個人化資料，非跨使用者共用資源，cascade 合理；若未來稽核需求要求保留，可改 `restrict` 並搭配帳號刪除流程另行處理，屬第 10 節待決策範圍） |
| `use_tools` | `boolean` | 第一輪的設定，固定沿用整個對話（見 4.1 節 precedence 說明） |
| `created_at` / `updated_at` | timestamps | |

**`qa_messages`**
| 欄位 | 型別 | 說明 |
|---|---|---|
| `id` | `bigIncrements` | |
| `conversation_id` | `char(36)`，外鍵 `qa_conversations.id`，`onDelete('cascade')` | |
| `turn_index` | `unsignedInteger` | 第幾輪（**定案：0-based**，與前端 `turns` 陣列 index 直接對應，減少前後端換算） |
| `question` | `text` | |
| `answer_markdown` | `mediumText`，nullable | 完整答案，可能較長 |
| `summary` | `text`，nullable | |
| `sql_used` | `json`，nullable | |
| `evidence` | `json`，nullable | |
| `caveat` | `text`，nullable | |
| `suggested_follow_ups` | `json`，nullable | |
| `tool_calls` | `mediumText`（JSON 字串）或 `json`，nullable | **注意**：`executeToolCalls()` 目前組出的 `tool_calls` 內含完整 tool 執行結果（非只有精簡的 `result_summary`，參見 `NaturalLanguageQueryService.php` 內 `summarizeToolResult()` 前後的資料流），若原樣整包存入，單輪資料量可能明顯超過一般 `text`（約 64KB 上限）；因此欄位型別採 `mediumText`/`json`（依資料庫實際 JSON 欄位容量規劃），**且建議持久化時只保留前端需要顯示的精簡欄位**（`tool_call_id`/`name`/`status`/`arguments`/`result_summary`/`error`，即等同前端 `ToolCallTrace` 的形狀），不整包保存原始 tool 執行結果，同時降低儲存量與洩漏風險 |
| `success` | `boolean` | |
| `error_message` | `text`，nullable | |
| `created_at` / `updated_at` | timestamps | |

- 索引與約束：`qa_conversations(user_id, created_at)` 一般索引；`qa_messages` 需要 **`unique(conversation_id, turn_index)`**（而非僅一般索引）——避免同一對話並發送出兩個追問請求時，兩者搶到同一個 `turn_index` 導致訊息錯亂。
- **併發控制**：`unique(conversation_id, turn_index)` + transaction 內 `lockForUpdate()` 只能保證「兩個並發請求不會寫入相同 `turn_index`」，不能保證「兩者是基於彼此看得到的最新歷史回答」——若真的同時打進兩個追問，兩者可能都是基於同一份舊歷史組出回答，只是最後各自拿到不同（但正確遞增）的 `turn_index`，語意上會顯得奇怪（例如兩個回答互相沒看到對方）。因此除了 DB 層的鎖與唯一約束，**還需要在應用層序列化同一對話的請求**：controller 收到帶 `conversation_id` 的請求時，先以短暫的鎖（例如 Redis/cache lock，或直接複用第 5.1 節 DB row lock 涵蓋整個 `answerQuestion()` 呼叫期間，而不只是取號那一刻）確保同一 `conversation_id` 同時只有一個請求在處理中；若偵測到同一對話已有進行中的請求，新請求直接回傳明確錯誤（例如 409 Conflict），提示使用者稍後再試，而不是讓兩個請求都跑完再靠 `unique` 約束事後擋掉一筆。前端雖然會在 UI 上禁止「進行中時再送出追問」，但後端不能只依賴前端行為做併發保護。
- 兩表都要走 `is_mysql()`/`is_sqlite()` 判斷是否需要額外處理（例如 JSON 欄位型別在 SQLite 是走 `text` fallback，Laravel 的 `json()` column type 已經處理好跨資料庫相容性，一般不需要手動判斷，但要在 migration 測試中同時對 SQLite 與 MariaDB 跑過一次）。
- **與 `nl_query_logs` 的關係**：`nl_query_logs` 維持現況（每輪仍寫一筆稽核紀錄，`question` 欄位前綴 `[QA]`），額外新增 nullable 欄位 `conversation_id`（`char(36)`, nullable, index）以便日後關聯查詢/除錯，但**不**把 `nl_query_logs` 當作對話歷史的讀取來源（避免稽核表與業務表職責混淆）。

### 5.2 隱私與保留政策
- 現有 UI 已有「隱私同意」勾選與說明文字（`qa_privacy_body`），代表使用者被告知問題會被記錄。多輪對話等於把更多內容（含追問內容）存入資料庫，需要：
  - 確認隱私文案是否需要更新以提及「同一對話的後續問題」也會被記錄（文案調整，非本規劃文件的決定範圍，建議提交前與相關人員確認）。
  - 討論保留期限（是否需要排程清除超過 N 天的 `qa_conversations`/`qa_messages`？）——留待第 10 節待決策。
- 授權：所有對 `qa_conversations`/`qa_messages` 的存取都必須檢查 `conversation.user_id === Auth::id()`，避免使用者猜測他人 `conversation_id` 讀到別人的對話（IDOR 風險）。API 設計見第 6.3 節。

## 6. 後端 API 設計

### 6.1 Request/Response contract 相容性
現有 contract（`tests/Feature/HistoricalQaTest.php` 已斷言）：
- Request: `{question, tables?, use_tools?}`
- Response: `{success, answer_markdown, summary, sql_used, tool_calls, evidence, caveat, model}` 或 `{success:false, error}`

**擴充方式（向後相容）**：
- Request 新增 **選填** 欄位 `conversation_id`（string，nullable，UUID 格式驗證）。
- **明確定案（修正先前草稿的矛盾）**：只要呼叫 `answer-from-nl`/`answer-from-nl-stream`，後端一律會有一筆 `qa_conversations` 對應到這次呼叫——
  - 若 request 未帶 `conversation_id`：視為對話的第 1 輪，後端建立新的 `qa_conversations` 列並回傳新的 `conversation_id`。
  - 若 request 帶 `conversation_id`：視為既有對話的追問輪，不建立新對話，讀取歷史組裝 `messages[]`。
  - 也就是說「不帶 `conversation_id`」只代表「這是第一輪／要開新對話」，**不代表「不持久化」**——每一次呼叫都會寫入 `qa_conversations`/`qa_messages`（沿用現有 `nl_query_logs` 每輪都寫稽核紀錄的既有精神，行為一致）。這與既有單輪測試相容：既有測試不斷言「不寫入 DB」，只斷言 response 既有欄位存在，因此新增持久化不會破壞既有測試。
- Response 新增欄位：
  - `conversation_id`（string）：本輪所屬對話 ID（第一輪時是後端新建立的 ID，供前端後續追問使用）。
  - `turn_index`（int）
  - `suggested_follow_ups`（string[]，可為空陣列）

此設計讓既有測試（斷言原本欄位存在）不需修改即可通過，只需新增測試涵蓋新欄位、持久化行為與多輪情境。

### 6.2 Endpoint 設計選項

比較兩種做法：

**選項 A（建議）：沿用既有 endpoint，新增 `conversation_id` 參數**
- `POST /query-playground/answer-from-nl` 與 `.../answer-from-nl-stream` 維持不變的路由，controller 內新增邏輯：
  - 若 request 無 `conversation_id`：建立新 `qa_conversations` 列。
  - 若有 `conversation_id`：驗證屬於目前登入使用者（不屬於或不存在一律回 404，見 6.3 節），讀取該對話所有既有 `qa_messages` 依 `turn_index` 排序組成歷史。
- 優點：不需新增路由/前端呼叫端點切換邏輯，改動面小，風險低，最貼近「擴充現有功能」而非「新增平行功能」。
- 缺點：controller/service 的參數增加，需要小心不要讓單輪呼叫者（若有其他呼叫方，目前查無其他呼叫方）意外受影響。

**選項 B：新增獨立對話管理 endpoint**（`POST /query-playground/qa-conversations`、`POST /query-playground/qa-conversations/{id}/messages`）
- 更符合 REST 資源語意，但改動面較大（新路由、新前端呼叫邏輯、需要額外處理「取得對話歷史」的 GET endpoint）。
- 若之後有「頁面重整後還能看到對話」需求（見第 10 節），選項 B 的資源化設計較容易擴充（例如加一個 `GET /query-playground/qa-conversations/{id}` 取回歷史）。

**建議**：先採**選項 A**（改動最小、風險最低、最符合「擴充現有單輪功能」的漸進式做法），但資料表設計（第 5 節）保持選項 B 也能沿用的形狀，若未來需要「取回歷史」的 GET endpoint 可以低成本補上。

### 6.3 授權與驗證
- 沿用 controller 既有的 `Auth::user()->isActive()` 檢查（每個方法內已有）。
- 新增：若帶 `conversation_id`，須手動查詢 `qa_conversations` 表確認存在且 `user_id === Auth::id()`；「不存在」與「存在但不屬於自己」兩種情況一律回傳同樣的 `404`，不做區分（避免洩漏對話是否存在）。
- Validation 規則新增：`conversation_id` => `nullable|uuid`（**不要**加 Laravel 的 `exists:qa_conversations,id` 規則——該規則若沒命中會讓 request 直接以 `422` 失敗，等於讓「不存在」與「存在但不屬於自己」變成可被外部區分的兩種狀態碼，違背上一點「統一回 404」的目的）。改由 controller 內手動查詢，找不到或不屬於自己都走同一個 404 回應路徑。

### 6.4 對話輪數/長度上限（防濫用與成本控制）
- 需要設定「單一對話最大輪數」（例如 20 輪，可設定於 `config/query_playground.php`，如 `qa_max_turns_per_conversation`），超過上限時後端拒絕新增輪次並提示使用者開新對話。
- 需要設定「歷史注入 LLM 時的長度上限」（避免 context 隨對話輪數線性增長导致 token 暴增/超出模型上限），見第 7.3 節的截斷/摘要策略。

## 7. Service 層與 Prompt 設計

### 7.1 `NaturalLanguageQueryService::answerQuestion()` 簽名擴充
```php
public function answerQuestion(
    string $question,
    ?array $tableNames = null,
    ?callable $progressCallback = null,
    ?bool $useToolsOverride = null,
    ?callable $heartbeatCallback = null,
    ?callable $abortCheck = null,
    array $conversationHistory = [],   // 新增：[['role'=>'user','content'=>...], ['role'=>'assistant','content'=>...], ...]
): array
```
- `$conversationHistory` 由 controller 從 `qa_messages` 組裝後傳入（只包含**先前輪次**，不含本次新問題）。
- 組裝方式：對每個歷史 turn，插入一組 `{role:user, content: question}` + `{role:assistant, content: <前一輪的 answer_markdown 或精簡摘要>}`。**不要**把先前輪次完整的 tool-call/tool-result 訊息也塞回去（會讓 context 迅速膨脹且無必要）——歷史注入只需要「使用者問了什麼、模型答了什麼」，本輪若需要新的資料庫查詢會透過工具調用迴圈重新查詢一次（本身已具備冪等性、且能反映最新資料庫狀態，優於重播舊 SQL 結果）。
- `$messages` 組裝順序調整為：
  ```php
  $messages = [
      ['role' => 'system', 'content' => $systemPrompt],
      ...$conversationHistory,             // 歷史輪次（user/assistant 交錯）
      ['role' => 'user', 'content' => $question],  // 本輪新問題
  ];
  ```

### 7.2 System prompt 調整（`buildQaSystemPrompt()`）
- 新增段落告知模型：「使用者可能會針對先前的問答內容進行追問，請利用對話歷史進行指代消解（例如『他』『這個人』『那個機構』可能指向先前輪次提到的實體），若無法確定所指對象，請在回答中禮貌詢問澄清，而非臆測。」
- **輸出 contract 擴充**：現有 `parseQaResponse()` 期待的 JSON 結構（`answer_markdown`/`summary`/`sql_used`/`evidence`/`caveat`）需新增 `suggested_follow_ups`（string 陣列，0–4 個，根據本輪回答內容產生的延伸提問建議）。System prompt 需明確要求模型輸出此欄位，且是「選填、可為空陣列」（避免模型硬湊建議）。
- **既有語言缺口（本次多輪功能不修，但需記錄）**：實際檢視 `buildQaSystemPrompt()` 後發現，現有 system prompt 是**整段寫死繁體中文**（工具說明、策略步驟等），完全沒有依 `App::getLocale()`/使用者介面語言切換回答語言的邏輯——也就是說，即使使用者已把介面切到英文，QA 模式目前回答內容仍是中文 system prompt 產出的結果。這是**既有問題**，不是本次多輪功能引入的缺陷，本規劃不處理；但新增的 `suggested_follow_ups` 欄位既然是同一顆 LLM 依同一個 system prompt 產出，語言表現會與現有 `answer_markdown` 一致（即現況如何、`suggested_follow_ups` 就如何），不會讓既有語言缺口變得更嚴重，也不會讓它變好。若日後要修正 QA 模式的回答語言隨介面切換，需另開範圍處理 `buildQaSystemPrompt()` 本身，不在本次多輪功能規劃內。
- `parseQaResponse()` 需要對應更新以解析並驗證 `suggested_follow_ups`（型別檢查：非陣列或非字串元素時忽略該欄位，回傳空陣列，避免因為格式錯誤讓整個回答解析失敗）。

### 7.3 Context 增長控制

**具體規則（定案，取代先前「門檻/估算方式未定義」的模糊描述）**：
- 組裝 `$conversationHistory` 時，每個歷史輪次一律只用該輪的 **`summary`** 欄位作為 assistant 訊息內容，**不使用完整的 `answer_markdown`**（`summary` 本來就是模型自己在 `parseQaResponse()` 中產出的精簡摘要，用它取代逐字答案可大幅壓低 context 成長速度，且不需要額外一次摘要用的 LLM 呼叫）。若某輪 `summary` 為空字串（理論上不應發生，但需防禦），退而使用 `answer_markdown` 前 300 字（char，非 token）並附加 `…`。
- 只保留**最近 5 輪**歷史（第 6.4 節「單一對話最大輪數」上限另訂為 20 輪，兩者是不同層面的限制：20 輪是「對話還能不能繼續」的硬上限，5 輪是「送給 LLM 的 context 視窗」）；超過視窗的舊輪次仍完整保存在 `qa_messages`（供之後若要做「載入歷史」或摘要優化使用），只是不注入本次 prompt。
- 加上一道保險：組裝完 `$conversationHistory` 後，累加其字元數（PHP `mb_strlen`，非精確 token 計算，但足夠當作粗略上限保護），若總字元數超過設定門檻（建議 `config('query_playground.qa_history_char_limit', 6000)`），從最舊的歷史輪次開始捨棄，直到低於門檻為止（而不是讓 LLM API 呼叫因為 context 過長直接報錯）。
- 「摘要壓縮舊輪次」（對超出視窗的輪次額外呼叫 LLM 做濃縮摘要，而非直接捨棄）列為未來優化選項，本次不做（記錄於第 10 節）。

### 7.4 工具調用迴圈與 allowlist（維持不變）
- `executeToolCalls()`/`NlQueryToolsService::executeTool()`/`ReadOnlyTableQueryService::inspectReadOnlySql()` 完全不需修改：每輪、每個 tool call 依然各自重新執行（含 SQL allowlist、CTE 解析），多輪只是讓「這是第幾輪對話」的迴圈外層多了一層（對話輪次），內層工具調用迴圈邏輯不變。
- **需要保持的既有回歸測試**：`callLLMForQa()` 在有 tools 的最終輪不送 `response_format` 的行為（`HistoricalQaTest.php` 已有明確斷言），多輪擴充不可破壞此行為——`$conversationHistory` 只影響 `$messages` 陣列內容，不影響 `callLLMForQa()` 判斷是否要送 `tools`/`response_format` 的邏輯。

### 7.5 順帶修正
- `max_tool_calls` 本地預設值（QA 呼叫處寫死 `20`）與 `config/nl_query_tools.php` 全域預設值（`40`）不一致，建議統一改為只讀取 config 值、不在呼叫處重複寫入不同的預設值，避免未來維護者混淆（此為獨立小修正，可在「後端 API 設計」實作階段一併處理並附上獨立 commit/測試）。

## 8. 測試計畫

### 8.1 新增/修改測試（`tests/Feature/HistoricalQaTest.php`）
- 既有單輪測試維持不動（驗證向後相容：不帶 `conversation_id` 時行為不變，除了 response 新增欄位）。**注意**：新增 `qa_conversations.user_id` 外鍵後，測試中原本用 `$this->be(new User([...]))`（未寫入 DB 的記憶體物件）登入的寫法會因外鍵約束失敗，相關測試需改為 `User::factory()->create()` 等實際寫入 DB 的 persisted user。
- 新增案例：
  1. 第一次提問（不帶 `conversation_id`）→ 回應包含新的 `conversation_id`、`turn_index=0`，且 DB 內 `qa_conversations`/`qa_messages` 各新增一筆對應紀錄。
  2. 帶著上一步回傳的 `conversation_id` 送出追問 → 驗證 controller 正確組裝歷史並傳入 service（可用 mock 斷言 `answerQuestion()` 收到的 `$conversationHistory` 內容，且歷史訊息用的是上一輪的 `summary` 而非完整 `answer_markdown`，見 7.3 節規則），`turn_index` 遞增為 1。
  3. 帶著**別人**的 `conversation_id` → 回應 404。
  4. 帶著不存在的 `conversation_id` → 回應同樣的 404（與案例 3 狀態碼一致，驗證兩種情況無法被外部區分）。
  5. 超過 `qa_max_turns_per_conversation` 上限 → 回應明確錯誤訊息，前端可據此提示「請開新對話」。
  6. SSE 版本的對應多輪情境（`event: complete` 內含 `conversation_id`/`turn_index`/`suggested_follow_ups`）。
  7. `suggested_follow_ups` 欄位格式錯誤時（例如 LLM 回傳非陣列）不應導致整個請求失敗，應忽略該欄位並回空陣列。
  8. 沿用既有「QA 最終輪不送 `response_format`」回歸測試，確認多輪情境下依然成立。
  9. **併發/唯一性**：對同一 `conversation_id` 併發送出兩筆追問，驗證後到的請求被應用層鎖擋下、回傳 409（而非兩者都執行完畢、只在寫入時靠 `unique` 約束事後擋掉一筆導致 500）；`turn_index` 不重複。
  10. **失敗時的持久化行為（已定案）**：`answerQuestion()` 回傳 `success:false` 時，仍建立一筆 `qa_messages`（`success=false`、`error_message` 有值、`answer_markdown`/`summary` 等留空），與第 6.1 節「每次呼叫都寫入」的語意保持一致，避免失敗輪次在 UI 顯示與 DB 紀錄之間對不上；測試需鎖定此行為（斷言失敗時仍新增一筆 `qa_messages` 列且 `turn_index` 正確遞增）。
  11. **`use_tools` precedence**：追問請求帶了與第一輪不同的 `use_tools` 值，驗證後端實際執行時仍使用 `qa_conversations.use_tools`（第一輪的值），忽略追問請求帶的值。
  12. **Feature flag 關閉**：`qa_multiturn_enabled=false`（見第 10 節）時，`conversation_id` 相關邏輯完全不觸發，行為與修改前的單輪版本一致。
- Migration 測試：新表在 SQLite（測試環境）與需要時於本機 MariaDB 手動驗證均可正確建立、外鍵約束與 `unique(conversation_id, turn_index)` 正確生效。

### 8.2 前端（若之後有前端測試框架/手動驗收）
- 手動驗收腳本（因為現有前端似乎沒有 e2e 測試框架，需另外確認）：第一輪提問 → 出現追問輸入框與建議 chips → 追問後訊息串正確累加 → 開新對話後狀態清空 → 取消/中斷追問時不影響已完成的前幾輪顯示。

## 9. 分階段實作與 Review 檢查點（依 AGENTS.md／使用者要求的流程）

依照使用者指定流程，每個小環節完成後：先派 review agent（讀程式碼＋讀修改內容）檢查到無嚴重 issue，再用 `codex exec --dangerously-bypass-approvals-and-sandbox` review 到無嚴重 issue，才推進下一步。建議的實作切分：

1. **Migration + Model**：新增 `qa_conversations`/`qa_messages` migration（含 SQLite/MariaDB 相容性）與必要的輕量資料存取類別（是否用 Eloquent Model 或延續專案慣例用 `DB::table()` 直接操作，需視兩表是否為單一主鍵——`qa_conversations.id`/`qa_messages.id` 皆為單一主鍵，可用 Eloquent Model，不受複合主鍵限制）。
2. **Controller + Validation**：`answer-from-nl`/`answer-from-nl-stream` 新增 `conversation_id` 參數處理、ownership 檢查、輪數上限檢查、`nl_query_logs.conversation_id` 欄位串接。
3. **Service 層**：`answerQuestion()` 簽名擴充、system prompt 調整、`parseQaResponse()` 支援 `suggested_follow_ups`、context 視窗截斷邏輯、`max_tool_calls` 設定修正。
4. **前端**：`HistoricalQaPanel.tsx` 改為多輪訊息串 UI、追問輸入框、建議 chips、開新對話按鈕、新增翻譯字串。
5. **測試**：第 8 節所列全部測試補齊，確保 `./vendor/bin/phpunit` 全綠。
6. **前端建置與手動驗收**：`npm run build`，並用 `run` skill 實際操作頁面驗證多輪流程與邊界情況（輪數上限、跨使用者隔離、串流中斷）。

每個環節之間維持文件開頭要求的 review 節奏；本規劃文件本身也已依此流程分段進行 review（見文末「本文件審閱紀錄」）。

## 10. 待決策事項（需要使用者拍板）

1. **頁面重整後是否保留對話？** 目前規劃預設「否」（沿用 Google 目前『重新搜尋才重置』的精神，前端 state 不做 localStorage 持久化）。若要「是」，需要额外設計「載入歷史對話」的 GET endpoint 與前端初始化邏輯，改動面會增加。
2. **對話輪數上限與過期策略**：建議預設值（例如 20 輪、對話 24 小時後自動視為過期不可再追問）是否合理？是否需要排程清除舊對話資料（cron）？
3. **建議追問（`suggested_follow_ups`）是否為必要功能，或可作為第二階段再做？** 若時間有限，第一階段可以只做「追問輸入框」（核心多輪能力），建議 chips 列為快速跟進的第二階段，降低第一階段風險與範圍。
4. ~~`use_tools`/`use_streaming` 設定是否可在對話中途更改？~~ **已定案**（見 4.1 節）：`use_tools` 固定沿用第一輪設定，追問請求忽略此欄位；`use_streaming` 是純前端呈現選項，可每輪各自選擇，不受此限制。
5. **隱私同意文案是否需要更新**以明確告知「同一對話的後續追問內容也會被記錄」——此為文案／法遵層面決定，非工程決定，建議提前與相關負責人確認。
6. ~~`turn_index` 採 0-based 或 1-based？~~ **已定案**（見 5.1 節）：採 0-based，與前端 `turns` 陣列 index 對應。
7. **多輪功能是否需要 feature flag 保護？** 由於 `app/query-playground` 目前無 migration flag（硬遷移），若多輪功能上線後發現問題，目前無「一鍵回退」機制。建議在 `config/query_playground.php` 新增 `qa_multiturn_enabled`（預設 `true`，可用 env 快速關閉退回純單輪行為），作為輕量保護，而非完整的 migration flag 機制。

---

## 附錄 A：現況調查詳細記錄

### A.1 前端
- 頁面殼層：`resources/js/inertia/Pages/QueryPlayground/Index.tsx`，`mode=qa` 時渲染 `HistoricalQaPanel`，傳入 `nlModel`/`answerFromNlEndpoint`/`answerFromNlStreamEndpoint` 三個 Inertia props；mode 狀態僅存在於 `useState` + `window.history.replaceState`，不涉及伺服器持久化。
- `HistoricalQaPanel.tsx`：所有狀態皆為單一答案（`question`/`consent`/`useTools`/`useStreaming`/`loading`/`error`/`answerMarkdown`/`summary`/`sqlUsed`/`toolCalls`/`evidence`/`caveat`/`showDetails`），無訊息陣列；`handleSubmit()` 每次呼叫先 `resetResults()` 再送出 `{question, use_tools}`，無 `conversation_id` 概念；串流走手刻 SSE 行解析（`processLine`/`flushEvent`），事件種類 `status`/`tool_execution_start`/`tool_execution_complete`/`complete`/`error`；Markdown 用手刻 regex renderer 渲染並以 `dangerouslySetInnerHTML` 插入。
- 全 repo 搜尋 `chat|conversation|thread|follow-?up`（`.tsx`，不分大小寫）**零命中**——本功能將是此專案第一個聊天式 UI，`HistoricalQaPanel`/`ToolTracePanel` 是最接近可參考的既有模式（textarea 輸入、SSE 消費、Markdown 渲染、tool trace 清單渲染）。

### A.2 後端 Controller
`app/Http/Controllers/QueryPlaygroundController.php`：
- `answerFromNL()`：驗證 `question`(必填,≤1000)/`tables`(選填陣列)/`use_tools`(選填布林)，呼叫 `answerQuestion()`，成功回 JSON，失敗回 400。
- `answerFromNLStream()`：同樣驗證，包在 `streamSseResponse()` 內，成功發 `event: complete`，失敗發 `event: error`。
- `streamSseResponse()`：`set_time_limit(300)`、`ignore_user_abort(true)`、依 `config('query_playground.sse_heartbeat_seconds')`（預設 10 秒）送心跳註解、送 padding 註解避免 proxy 緩衝、以 `connection_aborted()` 偵測斷線——此機制可直接沿用於多輪的每一次獨立請求，不需要長連線跨輪次維持。
- 所有方法都各自檢查 `Auth::user()->isActive()`（非路由層 middleware）。

### A.3 Service 層
- `app/Services/NaturalLanguageQueryService.php::answerQuestion()`（現行簽名見上方調查記錄第 443-450 行）：組裝 `$messages=[system,user]`（無歷史），跑工具調用迴圈（`while ($round < $maxRounds)`，每輪視需要呼叫 `executeToolCalls()`），最終輪拿掉 `tools`/`response_format`（Gemini 若 system prompt 提及工具、即使沒帶 `tools` 陣列仍可能嘗試呼叫，故最終輪換用不含工具描述的 `$noToolSystemPrompt`）;`parseQaResponse()` 解析模型輸出的嚴格 JSON（`answer_markdown`/`summary`/`sql_used`/`evidence`/`caveat`）；每次呼叫都寫一筆 `nl_query_logs`（`question` 前綴 `[QA]`)。
- `executeToolCalls()` 呼叫 `NlQueryToolsService::executeTool()`，其中 `query_read_only_sql` 委派給 `ReadOnlyTableQueryService::queryReadOnlySql()`。
- `SqlTableNameExtractor`：AST（`PhpMyAdmin\SqlParser`，含 CTE 別名排除）→ regex fallback → `EXPLAIN` fallback 三層策略取得 table 名稱清單。
- `ReadOnlyTableQueryService::inspectReadOnlySql()`：只允許單一 `SELECT`/`WITH` 陳述式、禁止危險關鍵字、對照 `config('mcp.cbdb.allowed_tables')` 白名單；`queryReadOnlySql()` 另處理分頁（CTE 情況改寫既有 `LIMIT`/`OFFSET`，一般 `SELECT` 用 subquery wrapper）。此驗證流程**每次執行都重新跑一次**，多輪情境下必須維持此特性（不可快取/略過驗證）。

### A.4 路由
```
POST query-playground/answer-from-nl          → answerFromNL          （JSON，QA）
POST query-playground/answer-from-nl-stream   → answerFromNLStream    （SSE，QA）
GET  app/query-playground                     → appIndex              （React 頁面，無 migration flag，硬遷移）
```
其餘 SQL/QBE/NL→SQL 相關路由不受本功能影響。

### A.5 測試現況
`tests/Feature/HistoricalQaTest.php` 涵蓋：Inertia props 斷言、JSON/SSE 兩種模式的 request/response 基本 contract、validation 失敗（422）、以 partial mock 驗證 controller 正確轉發 service 回傳欄位、以及一則明確保護「QA 最終輪不送 `response_format`」行為的回歸測試——多輪擴充必須維持此測試綠燈或有意識地更新它。

### A.6 資料持久化現況
全 repo migration 搜尋 `conversation|chat|message|session|qa_history` 除 `nl_query_logs` 外無命中；`nl_query_logs` 為稽核 log（`user_id`/`question`/`generated_sql`/`explanation`/`llm_prompt`/`llm_response`/`success`/`error_message`/`execution_time_ms`），無 `conversation_id`/`turn_index`，且未被讀回任何 prompt 組裝流程——確認目前**完全沒有**可支援多輪對話的既有資料結構。

### A.7 設定
- `config/migration_flags.php`：`query-playground` 無頁面層級 flag，僅 `nl-query-logs`（管理員稽核頁）有 flag，與本功能無關。
- `config/query_playground.php`：僅 SSE 相關（`sse_heartbeat_seconds`/`sse_padding_bytes`）。
- `config/nl_query_tools.php`：`enabled`/`tools[]`/`max_tool_calls`(預設 40，QA 呼叫處另寫死預設 20，見第 7.5 節)/`timeout`（似乎未實際使用）。
- `config/services.php`：`gemini`（`api_key`/`api_endpoint`/`model`預設`gemini-3-flash-preview`/`max_completion_tokens`預設 8192）與 `gemini_fallback`（透過 `LlmFallbackTrait` 切換）。
- `config/mcp.php`：`cbdb.allowed_tables`（預設取 `codes.tables` 鍵值，可用 `MCP_ALLOWED_TABLES` 覆寫）、`cbdb.max_limit`、`cbdb.rate_limit_per_minute` 等，與 `query_read_only_sql` 工具共用。

---

## 本文件審閱紀錄

**第一輪：general-purpose review agent**（讀程式碼＋核對文件描述）
- 核對前端（`HistoricalQaPanel.tsx`）、後端 controller、`NaturalLanguageQueryService`、`HistoricalQaTest.php`、migration 現況等描述，確認皆與現行程式碼相符。
- 發現 1 項需修正：`conversation_id` 授權設計中，若對 `conversation_id` 加上 Laravel `exists:qa_conversations,id` validation 規則，會讓「不存在」以 422 回應、與「存在但不屬於自己」的 404 產生可區分的狀態碼，違反「統一回 404、避免洩漏對話是否存在」的 IDOR 防護目的。**→ 已修正**：改為不加 `exists` 規則，一律由 controller 手動查詢後統一回 404（見第 6.3 節、第 8.1 節測試案例 3/4）。
- 提出兩項次要備註：(a) 多輪 `toolCalls` 狀態更新需要「先定位目前進行中的 turn 再做巢狀不可變更新」，非單純換陣列名稱；(b) `turn_index` 0-based/1-based 需在實作前定案。**→ 已修正**：分別在第 4.1 節補充巢狀 state 更新說明、第 5.1 節定案為 0-based。

**第二輪：`codex exec --dangerously-bypass-approvals-and-sandbox`**（獨立第二意見）
- 確認現況調查大方向正確，無錯誤。
- 發現 1 項**嚴重**問題：文件先前版本自相矛盾——一方面說「不帶 `conversation_id` = 不建立任何持久化資料」，另一方面又說「若未帶則後端自動建立新對話」。**→ 已修正**：第 6.1 節明確定案「只要呼叫此 API 就一定會有一筆 `qa_conversations` 對應；不帶 `conversation_id` 代表『這是第一輪／開新對話』，不代表『不寫入 DB』」。
- 另指出 6 項風險/缺口，皆已於本次修訂納入：
  1. 授權錯誤碼前後不一致（403 vs 404）→ 統一為 404（第 6.3 節）。
  2. 缺少後端併發控制與唯一約束 → 新增 `unique(conversation_id, turn_index)` + transaction 內 `lockForUpdate()` 取號設計（第 5.1 節），並補測試案例 9（第 8.1 節）。
  3. `use_tools` precedence 未定義 → 定案「固定沿用第一輪，追問請求忽略此欄位」（第 4.1 節），並補測試案例 11。
  4. `qa_messages` 欄位容量疑慮（`tool_calls` 可能超過 `text` 上限）→ 改用 `mediumText`/`json`，且建議只持久化精簡的 trace 形狀而非完整 tool 執行結果（第 5.1 節）。
  5. Context 截斷規則不夠具體 → 定案「歷史輪次一律用 `summary` 而非完整 `answer_markdown`、只留最近 5 輪、加上字元數保險上限」（第 7.3 節）。
  6. FK delete policy 未定 → 定案 `onDelete('cascade')`（第 5.1 節），並在測試計畫補充 persisted user 的必要性、失敗時持久化行為（測試案例 10）與 feature flag 關閉情境（測試案例 12）。

兩輪 review 找到的問題皆已在文件中修正完畢，目前無已知嚴重問題。

**第三輪：`codex exec` 複查修正結果**
- 明確回覆「無嚴重問題」，確認上述七項問題都已妥善納入。
- 額外指出 4 項文件內部殘留的小不一致（非嚴重）：(a) 第 3.1 節流程描述仍寫「`answer_markdown`」而非已定案的 `summary`；(b) 第 3.1 節建議追問行為描述與第 4.1 節「不自動送出」的定案不一致；(c) 失敗時是否持久化 `qa_messages` 仍寫「兩者皆可，待定案」，與第 6.1 節「每次呼叫都寫入」的語意有落差；(d) 併發鎖只保證 `turn_index` 不重複，未保證兩個並發請求不會基於同一份舊歷史各自作答。**→ 已全部修正**：第 3.1 節同步改為 `summary`／「不自動送出」；第 8.1 節測試案例 10 定案為「失敗也寫入 `qa_messages`」；第 5.1 節併發控制段落補充「需在應用層序列化同一對話的請求，後到者回 409」，測試案例 9 同步更新。

三輪 review（review agent → codex → codex 複查）皆已處理完畢，目前文件無已知嚴重問題或內部矛盾。
