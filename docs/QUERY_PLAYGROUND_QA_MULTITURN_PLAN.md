# Query Playground QA 模式多輪追問功能 — Work Plan

> 狀態：規劃中（僅文件，尚未實作）
> 本規劃文件僅提供繁體中文版本；不代表功能實作範圍排除英文 i18n——依 `AGENTS.md` 第 6 節規則，新增的 UI 字串（第 4.3 節）仍須比照專案既有慣例同步維護 `resources/lang/zh-TW/*.php` 與 `resources/lang/en/*.php` 兩份翻譯檔，不可只做繁中。
> 對應功能：`/app/query-playground?mode=qa`（歷史問答 QA 模式）
> 參考範例：Google 搜尋結果 AI Overview 的「Ask anything」追問輸入框——首次答案生成後，答案下方會持續顯示一個輸入框，使用者可針對同一上下文繼續提問。
> **範圍收斂說明**：本版取代先前草案。先前草案把對話持久化到新資料表（`qa_conversations`/`qa_messages`），因此連帶需要 IDOR 防護、應用層併發鎖、409 衝突處理、資料保留政策等一整層工程。經檢視這個功能的實際使用場景——CBDB training session 演示、同事臨時查「什麼 code 在什麼表」——追問輪次少（硬上限 5 輪）、對話不需要跨裝置/跨瀏覽器同步、重新整理頁面本來就不保留，**這些前提代表對話歷史前端本來就完整持有，不需要後端持久化**。本版改為「歷史留在前端 state、每次追問請求把歷史一併送回後端」的無狀態設計，後端不新增任何資料表，大幅縮小實作範圍與風險面。

## 1. 背景與目標

目前 `HistoricalQaPanel.tsx` 是**單輪問答**：每次送出問題都會呼叫 `resetResults()` 清空畫面，後端 `NaturalLanguageQueryService::answerQuestion()` 每次都是全新的 `messages = [system, user]`，沒有任何先前輪次的記憶。使用者若想追問（例如「這個人的兒子是誰？」承接上一題的人物），必須重新打一次完整脈絡的問題。

**目標**：讓使用者在得到第一次回答後，可以在同一個對話串中繼續追問（最多 5 輪），模型能理解先前輪次的上下文（指代消解、延伸提問），比照 Google AI Overview 的體驗，在答案下方持續顯示輸入框。

**非目標（本次不做）**：
- 不做跨裝置/跨瀏覽器的對話同步，不做對話持久化、不做「重新整理頁面後還能看到對話」。
- 不做對話分享/匯出功能。
- 不改動 SQL 模式（`mode=sql`）、QBE 模式、NL→SQL 模式，僅針對 QA 模式。
- 不重新設計 SQL allowlist / CTE 驗證邏輯（沿用現有 `ReadOnlyTableQueryService`/`SqlTableNameExtractor`，每輪、每個 tool call 仍必須完整跑過驗證，不可因為「歷史紀錄」而跳過或快取驗證結果）。
- **建議追問清單（`suggested_follow_ups`，比照 Google「如果你想，我可以說明：」）本次不做，且暫不排入第二階段時程**（見第 10 節）——先把「能追問」這個核心能力做出來，範圍更可控；未來若有需求再另行評估、重新開範圍。
- 不新增任何資料表、不做對話持久化的授權/併發/保留政策設計（因為沒有伺服器端對話物件需要保護）。

## 2. 現況摘要（詳細調查見下方附錄 A）

- **前端**：`resources/js/inertia/components/QueryPlayground/HistoricalQaPanel.tsx` 是唯一的 QA UI，純單輪、無任何對話/聊天元件可重用；client state 全部是單一答案的欄位（`answerMarkdown`/`summary`/`sqlUsed`/`toolCalls`/`evidence`/`caveat`），沒有訊息陣列。
- **後端路由**：`POST /query-playground/answer-from-nl`（JSON）與 `POST /query-playground/answer-from-nl-stream`（SSE），皆由 `QueryPlaygroundController` 處理，僅接受 `{question, tables?, use_tools?}`，無 conversation 概念。
- **服務層**：`NaturalLanguageQueryService::answerQuestion()` 已經是 OpenAI 相容的 `messages[]` 陣列（`[system, user]`），是最自然的多輪擴充點——只要在送給 LLM 前，把前端送回的歷史 `{role:user}`/`{role:assistant}` 訊息插入陣列即可。工具調用迴圈（`executeToolCalls`）、SQL allowlist（`ReadOnlyTableQueryService`）皆是每輪獨立執行，不需改動邏輯本身。
- **持久化**：目前完全沒有對話/訊息儲存表，只有 `nl_query_logs`（稽核用途）。**本次規劃維持這個現況不變**——每一輪請求仍比照現行行為各自寫一筆 `nl_query_logs`（`question` 前綴 `[QA]`），不新增任何資料表、不新增欄位。
- **測試**：`tests/Feature/HistoricalQaTest.php` 已對現有單輪 request/response contract 有明確斷言，多輪功能必須維持向後相容。
- **設定**：`nl_query_tools.max_tool_calls` 有本地預設值 20（QA 呼叫處）與全域設定預設值 40 不一致，屬既有小問題，可在本次一併修正並記錄（見第 7.5 節）。
- **既有 rate limit 慣例可直接沿用**：`routes/ai.php` 的 MCP 路由已有 `throttle:{$rateLimit},1`（config 驅動、每分鐘請求數）先例，`routes/web.php` 的 `codes/{table_name}/export` 也用 `throttle:6,1`。本功能的 rate limiting 直接比照此既有 middleware 慣例，不需要自己刻限流邏輯。

## 3. 功能範圍與使用者流程

### 3.1 對話生命週期（無後端狀態，純前端持有）
1. 使用者在 QA 模式輸入第一個問題並送出（現有行為不變：勾選隱私同意、選填 `use_tools`/`use_streaming`）。
2. 後端不建立任何對話物件，只是照現行單輪邏輯回答一次；前端把這一輪的 `question` + `summary` 記進本地 `turns` 陣列（`summary` 欄位是現有 response contract 既有欄位，本次不需新增）。
3. 前端在答案下方顯示一個常駐的「繼續提問」輸入框（比照 Google 的 "Ask anything"）。
4. 使用者輸入追問並送出時，前端把**目前為止所有輪次**的 `{question, summary}` 陣列（最多 4 筆先前輪次，見第 6.4 節上限）隨新的請求一併送到後端；後端把這份陣列組成 `messages[]` 的歷史區段，插入本輪新問題之前，再呼叫既有的工具調用迴圈。後端完全不查詢、不寫入任何對話資料表，歷史只存在於這次 HTTP request 的 body 裡。
5. 畫面以「訊息串」方式往下累加顯示（Q1/A1、Q2/A2 …），而不是現在的「送出即清空重繪」。
6. 累積滿 `qaMaxTurns`（定案預設 5，由後端 Inertia props 傳給前端，見第 4.1、10 節）輪（含首輪）後，輸入框停用並提示「已達單一對話上限，請開新對話」；使用者可按「開新對話」按鈕捨棄目前對話（清空前端 `turns` 陣列），回到全新單輪狀態。
7. 使用者重新整理頁面：對話直接遺失（前端 state 未做 localStorage 持久化），與現行單輪行為的差異僅在於「同一頁籤內可以連續追問」。

### 3.2 與 Google 範例的對應關係
| Google AI Overview | 本功能對應設計 |
|---|---|
| 答案下方常駐 "Ask anything" 輸入框 | QA 面板答案卡片下方常駐追問輸入框（取代現有「送出後消失」的表單） |
| "If you'd like, I can explain: ..." 建議清單 | 本次不做，暫不排入時程（見第 10 節） |
| 追問會延續同一個上下文 | 前端把先前輪次的 `{question, summary}` 隨每次追問請求送回後端，後端當場組裝 `messages[]`，不查資料庫 |
| 對話僅存在當次瀏覽（重新搜尋才重置） | 完全比照：對話只存在前端 state，重新整理頁面即遺失 |

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
    error?: string;
  }
  const [turns, setTurns] = useState<QaTurn[]>([]);
  ```
- **輪數上限來源（定案，見第 10 節）**：`Index.tsx`/`appIndex()` 既有的 Inertia props（`nlModel`/`answerFromNlEndpoint`/`answerFromNlEndpointStream` 等）新增 `qaMaxTurns`（由後端 `config('query_playground.qa_max_turns')` 直接傳入），並向下傳給 `HistoricalQaPanel`。前端一律讀取這個 prop 判斷何時停用輸入框，**不寫死 `5`**，避免前後端上限數字之後各自被改動而不同步。
- 現有 `resetResults()` 只在「開新對話」時呼叫；一般送出追問改為 `appendTurn()`。
- 送出追問時，前端組裝 `conversation_history`：取 `turns` 中已完成（`status==='done'`）的輪次，依序輸出 `[{question, summary}, ...]`（**只取 `summary`，不送完整 `answer_markdown`**——`summary` 本來就是模型自己在既有 response contract 中已經回傳的精簡摘要欄位，不需要新增欄位、也不需要前端自己做截斷/摘要）。首輪請求（`turns.length === 0`）不帶 `conversation_history` 欄位，與現行單輪 request 完全相同。
- SSE 事件處理（`handleStreamEvent`）需要知道「目前是在更新哪一個 turn」——用 `turns` 陣列最後一筆（狀態為 `pending`/`streaming`）作為目標，沿用現有的 `tool_execution_start`/`tool_execution_complete`/`complete`/`error` 事件語意，只是寫入位置從單一 state 改為 `turns[lastIndex]`。
- `ToolTracePanel` 需要能夠按 turn 分組顯示（目前是單一陣列平鋪），建議每個 turn 各自渲染一個 `ToolTracePanel` 實例（改動面小）。**注意**：`tool_execution_start`/`tool_execution_complete` 這兩個 SSE 事件目前是直接對單一扁平陣列 `setToolCalls` 做 append/尋找更新；改成 `turns[]` 後，每次事件都要先定位「目前進行中的 turn」（`turns` 中 `status==='streaming'` 的那一筆），再對該 turn 的 `toolCalls` 子陣列做不可變更新（immutable update），實作時要留意巢狀 state 更新寫法。
- Markdown 渲染沿用既有 `renderMarkdown()`（hand-rolled regex），不需更換。
- 輸入框行為：
  - 首次提問維持現有隱私同意勾選 + textarea + 送出鈕。
  - 第一輪送出後，追問輸入框精簡為「Ask anything」樣式（textarea + 送出鈕），不重複顯示同意勾選；`use_tools`/`use_streaming` 兩個選項是否每輪重新顯示交由實作時的 UI 判斷（後端不對 `use_tools` 做任何跨輪次的一致性檢查或記憶，純屬前端呈現選擇，沒有 precedence 問題需要解決）。
  - 追問輸入框需在「上一輪回答完成後」才可互動（避免同一對話並發兩個進行中的請求造成訊息串錯亂——這是前端 UX 層面的保護，不需要後端額外的併發鎖，因為後端本身無狀態、不寫入共享資料）。
  - 累積滿 `qaMaxTurns` 輪（`turns.length >= qaMaxTurns`）後停用輸入框並顯示提示文字，只留「開新對話」可用。
- 「開新對話」按鈕：清空 `turns`，回到初始畫面。
- 中斷/取消（`handleCancel`）需綁定到「目前進行中的 turn」，行為不變，只是作用目標改變。

### 4.2 對話狀態的生命週期（前端）
- 對話只存在於當前頁面的 React state（不使用 localStorage/sessionStorage），重新整理頁面即遺失。這是本次設計的既定行為，不再列為待決策事項。

### 4.3 需要新增的翻譯字串（`resources/lang/zh-TW/query.php` 與 `resources/lang/en/query.php` 或既有 query 語系檔，兩份同步新增，依 `AGENTS.md` i18n 規則不可只維護其中一份）
- `qa_follow_up_placeholder`（追問輸入框提示文字）
- `qa_new_conversation`（開新對話按鈕）
- `qa_turn_limit_reached`（達到輪數上限提示文字，例如「已達單一對話上限（{count} 輪），請開新對話」——文案需支援帶入 `qaMaxTurns` 變數，不寫死「5」，因為上限值來自後端 config，日後調整 config 不應該需要改動翻譯字串）
- 沿用現有 `qa_*` 系列鍵值（`qa_ask`/`qa_answering`/`qa_cancel`/`qa_error` 等）不需重複新增。

## 5. 無持久化設計（取代先前的資料表設計）

- 本功能**不新增任何資料表**。對話歷史完全由前端 React state 持有；追問請求把歷史（`question` + `summary` 對）放進 request body 一併送給後端，後端組完 `messages[]` 用完即丟，不寫入、不查詢任何對話專屬的資料結構。
- `nl_query_logs` 維持現況：每輪仍各自寫一筆稽核紀錄（`question` 欄位前綴 `[QA]`），**不新增任何欄位**（先前草案曾規劃在 `nl_query_logs` 加 `conversation_id` 欄位供關聯查詢，本次不做——若日後真的需要把多輪對話串起來稽核，屬於獨立的小需求，可另開範圍評估，不在本次多輪追問功能內）。
- 因為沒有伺服器端的對話物件，先前草案中因「持久化」而需要的整層工程全部不需要：
  - 不需要 IDOR 防護（沒有 `conversation_id` 可供他人猜測、查詢）。
  - 不需要應用層併發鎖、`unique` 約束、409 衝突處理（沒有共享寫入目標）。
  - 不需要資料保留政策/排程清除（沒有東西可保留）。
  - 不需要更新隱私同意文案（每一輪追問仍然只是比照現行單輪行為各自寫一筆 `nl_query_logs`，記錄範圍與現行單輪功能完全一致，沒有新增「被記錄的資料」）。
- 唯一需要後端做的「狀態驗證」是：檢查前端送回的 `conversation_history` 陣列長度是否超過上限（見第 6.4 節），這只是對 request body 做欄位驗證，不涉及查詢資料庫。

## 6. 後端 API 設計

### 6.1 Request/Response contract 相容性
現有 contract（`tests/Feature/HistoricalQaTest.php` 已斷言）：
- Request: `{question, tables?, use_tools?}`
- Response: `{success, answer_markdown, summary, sql_used, tool_calls, evidence, caveat, model}` 或 `{success:false, error}`

**擴充方式（向後相容，且不改動任何既有欄位）**：
- Request 新增**選填**欄位 `conversation_history`（陣列，每筆 `{question: string, summary: string}`）。不帶此欄位（或帶空陣列）與現行單輪行為完全一致，既有測試不需修改。
- Response **不新增任何欄位**——不需要 `conversation_id`、不需要 `turn_index`，因為沒有伺服器端對話物件可供之後的請求引用；前端自己知道目前是第幾輪、自己組裝下一次要送出的 `conversation_history`。

### 6.2 Endpoint 設計
沿用既有兩個 endpoint（`answer-from-nl`/`answer-from-nl-stream`），不新增路由。Controller 內新增邏輯：
- 驗證並讀取 `conversation_history`（若有）。
- 將其轉換為 `NaturalLanguageQueryService::answerQuestion()` 新增的 `$conversationHistory` 參數（見第 7.1 節），其餘邏輯不變。

不再有「選項 A / 選項 B」的取捨討論——因為沒有需要管理的伺服器端對話資源，選項 B（獨立對話管理 endpoint）失去存在理由。

### 6.3 授權與驗證
- 沿用 controller 既有的 `Auth::user()->isActive()` 檢查（每個方法內已有），不需新增其他授權邏輯（沒有跨使用者可存取的資料）。
- Validation 規則新增：
  ```php
  'conversation_history' => 'nullable|array|max:' . (int) (config('query_playground.qa_max_turns', 5) - 1),
  'conversation_history.*.question' => 'required_with:conversation_history|string|max:1000',
  'conversation_history.*.summary' => 'nullable|string|max:2000',
  ```
  超過上限（陣列筆數 > `qa_max_turns - 1`）由 Laravel 內建 `max` 規則直接回傳 422，前端在達到 5 輪（`qa_max_turns`）時應主動停用輸入框（見第 4.1 節），後端驗證是最後一道防線，不依賴前端行為。

### 6.4 對話輪數上限與 Rate Limiting（防濫用與成本控制）
- **硬性輪數上限**：單一對話最多 **5 輪**（含首輪），對應 config 鍵為 `qa_max_turns`（`config('query_playground.qa_max_turns', 5)`，直接對應 stakeholder 要求的「輪數上限」語意，避免用 `qa_max_history_turns` 這種「歷史筆數」命名讓實作者要多繞一層換算）；第 6.3 節的 validation 規則據此換算 `conversation_history` 陣列的筆數上限為 `qa_max_turns - 1`（即 4 筆）。這個數字由 validation 規則直接把關，不需要額外查詢任何地方來確認「目前是第幾輪」——前端送回的歷史陣列長度本身就代表「這是第幾輪」，後端只需要對這個陣列的筆數設驗證上限即可，不需要查 DB 計數。
- **按使用者 Rate Limiting**：每輪追問都會呼叫一次 LLM API，屬於有實際成本的操作，且追問越長 token 消耗越快。比照 `routes/ai.php` 既有的 `throttle:{$rateLimit},1` 慣例，在 `routes/web.php` 對 `query-playground/answer-from-nl` 與 `query-playground/answer-from-nl-stream` 兩條路由加上：
  ```php
  $qaRateLimit = (int) config('query_playground.qa_rate_limit_per_minute', 10);
  Route::post('query-playground/answer-from-nl', 'QueryPlaygroundController@answerFromNL')
      ->middleware("throttle:{$qaRateLimit},1")
      ->name('query-playground.answer-from-nl');
  Route::post('query-playground/answer-from-nl-stream', 'QueryPlaygroundController@answerFromNLStream')
      ->middleware("throttle:{$qaRateLimit},1")
      ->name('query-playground.answer-from-nl-stream');
  ```
  這兩條路由本來就在 `Route::middleware('auth')->group(...)` 內（見 `routes/web.php:379`），Laravel 內建 `throttle` middleware 在使用者已登入時會以使用者 ID 為 key（而非 IP），符合「按使用者」限流的需求，不需要自訂限流邏輯。超過限制時 Laravel 回傳標準 `429 Too Many Requests`，前端目前的錯誤處理路徑（`error` 欄位/`event: error`）需確認能正確顯示此情況（若目前錯誤處理只針對 JSON body 裡的 `error` 欄位，429 回應本身沒有這個欄位，需要前端額外處理 HTTP status code 層級的錯誤，見第 8 節測試）。

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
    array $conversationHistory = [],   // 新增：[['question' => ..., 'summary' => ...], ...]，由 controller 直接轉發 request 帶來的陣列，不經過任何資料庫查詢
): array
```
- `$conversationHistory` 由 controller 直接從 request 的 `conversation_history` 欄位轉發而來（只包含**先前輪次**，不含本次新問題），不做任何資料庫存取。
- 組裝方式：對每個歷史 turn，插入一組 `{role:user, content: $turn['question']}` + `{role:assistant, content: $turn['summary']}`。若某筆 `summary` 為空字串（理論上不應發生，前端只會送出已完成輪次的 `summary`，但需防禦），該筆歷史退化為只送 `{role:user, content: question}`（省略對應的 assistant 訊息，避免送出空字串內容給 LLM API）。
- **不要**把先前輪次完整的 tool-call/tool-result 訊息也塞回去（會讓 context 迅速膨脹且無必要，前端也從未送出這些資料）——歷史注入只需要「使用者問了什麼、模型答了什麼精簡摘要」，本輪若需要新的資料庫查詢會透過工具調用迴圈重新查詢一次（本身已具備冪等性、且能反映最新資料庫狀態，優於重播舊 SQL 結果）。
- `$messages` 組裝順序：
  ```php
  $messages = [
      ['role' => 'system', 'content' => $systemPrompt],
      ...$historyMessages,                          // 由 $conversationHistory 組裝出的 user/assistant 交錯訊息
      ['role' => 'user', 'content' => $question],    // 本輪新問題
  ];
  ```

### 7.2 System prompt 調整（`buildQaSystemPrompt()`）
- 新增段落告知模型：「使用者可能會針對先前的問答內容進行追問，請利用對話歷史進行指代消解（例如『他』『這個人』『那個機構』可能指向先前輪次提到的實體），若無法確定所指對象，請在回答中禮貌詢問澄清，而非臆測。」
- **輸出 contract 本次不擴充**——`parseQaResponse()` 期待的既有 JSON 結構（`answer_markdown`/`summary`/`sql_used`/`evidence`/`caveat`）維持不變，不新增 `suggested_follow_ups`（暫不排入時程，見第 10 節）。
- **既有語言缺口（本次多輪功能不修，僅記錄）**：`buildQaSystemPrompt()` 是整段寫死繁體中文，未依 `App::getLocale()` 切換回答語言，屬既有問題，本規劃不處理。

### 7.3 Context 大小（因硬上限與視窗一致，不需要額外截斷規則）
- 因為第 6.4 節已將單一對話硬上限訂為 5 輪（`conversation_history` 最多 4 筆），這個上限本身就等於「送給 LLM 的 context 視窗」，**不存在「硬上限 > 視窗」的落差**，因此不需要先前草案第 7.3 節那套「只留最近 5 輪、超過視窗的舊輪次如何處理」的額外截斷邏輯——`conversation_history` 陣列裡有幾筆就全部注入，validation 已經保證這個陣列不會超過 4 筆。
- 保留一道輕量保險：組裝完 `$conversationHistory` 對應的訊息後，加總其字元數（PHP `mb_strlen`，非精確 token 計算），若總字元數超過設定門檻（`config('query_playground.qa_history_char_limit', 6000)`），視為異常輸入（正常情況下 4 筆 `summary` 不可能超過此門檻，除非 request 被竄改）。**此檢查須放在 controller 的 validation 階段**（與 6.3 節的 `conversation_history.*.summary` 長度規則同一批驗證，例如自訂 `Rule::closure` 或 `after` validator 加總所有 `summary` 長度），直接回傳 `422`，而不是讓 request 進入 service 層執行到一半才用 `success:false`/`400` 收尾——因為此時已經不是「對話太長」的正常情境，而是可能繞過前端限制的異常請求，應該在最早的驗證關卡擋下。

### 7.4 工具調用迴圈與 allowlist（維持不變）
- `executeToolCalls()`/`NlQueryToolsService::executeTool()`/`ReadOnlyTableQueryService::inspectReadOnlySql()` 完全不需修改：每輪、每個 tool call 依然各自重新執行（含 SQL allowlist、CTE 解析），多輪只是讓「這是第幾輪對話」的迴圈外層多了一層，內層工具調用迴圈邏輯不變。
- **需要保持的既有回歸測試**：`callLLMForQa()` 在有 tools 的最終輪不送 `response_format` 的行為（`HistoricalQaTest.php` 已有明確斷言），多輪擴充不可破壞此行為——`$conversationHistory` 只影響 `$messages` 陣列內容，不影響 `callLLMForQa()` 判斷是否要送 `tools`/`response_format` 的邏輯。

### 7.5 順帶修正
- `max_tool_calls` 本地預設值（QA 呼叫處寫死 `20`）與 `config/nl_query_tools.php` 全域預設值（`40`）不一致，建議統一改為只讀取 config 值、不在呼叫處重複寫入不同的預設值，避免未來維護者混淆（此為獨立小修正，可在「後端 API 設計」實作階段一併處理並附上獨立 commit/測試）。

## 8. 測試計畫

### 8.1 新增/修改測試（`tests/Feature/HistoricalQaTest.php`）
- 既有單輪測試維持不動（驗證向後相容：不帶 `conversation_history` 時行為與現行完全一致，response 不新增任何欄位）。**實作時需注意**：`answerQuestion()` 簽名新增第 7 個參數 `$conversationHistory` 後，若既有測試（例如 `answer_from_nl_works_with_use_tools_false()`）是用 mock 對 `answerQuestion()` 的呼叫參數做精確位置斷言（`with($question, $tables, ..., $useTools)`），需要同步更新該斷言以涵蓋新增的第 7 個參數（首輪呼叫應傳入空陣列 `[]`），否則 mock 的參數期望會對不上而失敗——這不是設計問題，只是實作時容易漏掉的既有測試調整點。
- 新增案例：
  1. 帶 `conversation_history`（1–2 筆）送出追問 → 驗證 controller 正確組裝並傳入 `answerQuestion()` 的 `$conversationHistory` 參數（可用 mock 斷言收到的內容與 request 送入的一致，且組裝進 `$messages[]` 的是 `summary` 而非其他欄位）。
  2. `conversation_history` 筆數超過 `qa_max_turns - 1`（預設上限 5 輪，對應歷史筆數 4）→ 回應 422。
  3. `conversation_history.*.question` 缺漏或型別錯誤 → 回應 422。
  4. `conversation_history` 某筆 `summary` 為空字串 → 該筆歷史仍能正常組裝（只送 user 訊息，不送空的 assistant 訊息），請求成功完成，不因此報錯。
  5. SSE 版本的對應多輪情境（`event: complete`，request 帶 `conversation_history`，驗證行為與 JSON 版一致）。
  6. 沿用既有「QA 最終輪不送 `response_format`」回歸測試，確認多輪情境下依然成立。
  7. **Rate limiting**：對 `answer-from-nl` 在測試中快速發送超過 `qa_rate_limit_per_minute` 設定值的請求次數，驗證超過後回傳 `429`（可用 `config(['query_playground.qa_rate_limit_per_minute' => 2])` 之類的方式在測試中把上限調低以方便觸發）。
  8. **輪數上限的前後端一致性**：`qa_max_turns`（定案預設 5，見第 10 節）由 Inertia props 傳給前端（見第 4.1、9 節），測試需涵蓋：(a) `appIndex()` 的 Inertia props 斷言中包含 `qaMaxTurns`（或同等命名）且數值等於 `config('query_playground.qa_max_turns')`；(b) 後端 validation 上限（`qa_max_turns - 1`）與 config 值一致。前端不得寫死 `5`，一律讀取 props。
- 不需要 migration 測試（沒有新增資料表）。

### 8.2 前端（若之後有前端測試框架/手動驗收）
- 手動驗收腳本（因為現有前端似乎沒有 e2e 測試框架，需另外確認）：第一輪提問 → 出現追問輸入框 → 追問後訊息串正確累加 → 連續追問至第 5 輪後輸入框停用並顯示提示 → 開新對話後狀態清空 → 取消/中斷追問時不影響已完成的前幾輪顯示 → 快速連續送出多輪追問觸發 429 時前端能顯示合理錯誤訊息（而非白屏或 unhandled rejection）。

## 9. 分階段實作與 Review 檢查點（依 AGENTS.md／使用者要求的流程）

依照使用者指定流程，每個小環節完成後：先派 review agent（讀程式碼＋讀修改內容）檢查到無嚴重 issue，再用 `codex exec --dangerously-bypass-approvals-and-sandbox` review 到無嚴重 issue，才推進下一步。建議的實作切分：

1. **Controller + Validation + Rate Limiting**：`answer-from-nl`/`answer-from-nl-stream` 新增 `conversation_history` 參數驗證（含輪數上限），路由加上 `throttle` middleware（第 6.3、6.4 節）；`QueryPlaygroundController@appIndex` 的 Inertia props 新增 `qaMaxTurns`（第 4.1、10 節）。**本階段無需 migration**（沒有新增資料表）。
2. **Service 層**：`answerQuestion()` 簽名擴充、system prompt 調整、context 組裝邏輯（第 7 節）、`max_tool_calls` 設定修正（第 7.5 節）。
3. **前端**：`HistoricalQaPanel.tsx` 改為多輪訊息串 UI、追問輸入框、讀取 `qaMaxTurns` prop 控制輪數上限提示、開新對話按鈕、新增翻譯字串（第 4 節）。
4. **測試**：第 8 節所列全部測試補齊，確保 `./vendor/bin/phpunit` 全綠。
5. **前端建置與手動驗收**：`npm run build`，並用 `run` skill 實際操作頁面驗證多輪流程與邊界情況（輪數上限、rate limit、串流中斷）。

每個環節之間維持文件開頭要求的 review 節奏。

## 10. 已拍板事項

1. **硬性輪數上限**：`qa_max_turns` 預設值定案為 **5**（含首輪）。
2. **Rate limit 數值**：`qa_rate_limit_per_minute` 預設值定案為 **10**（每分鐘 10 次）。
3. **建議追問（`suggested_follow_ups`）**：暫不排入第二階段，僅維持第 1 節「非目標」的紀錄，待未來有需求時再另行評估、重新開範圍；本規劃文件不再為其保留待辦時程。
4. **前端輪數上限與後端 config 的同步方式**：定案為**後端透過 Inertia props 把 `qa_max_turns` 傳給前端**，前端一律讀取 props 提供的數值，不寫死 `5`（見第 4.1、9 節）。

---

## 附錄 A：現況調查詳細記錄

### A.1 前端
- 頁面殼層：`resources/js/inertia/Pages/QueryPlayground/Index.tsx`，`mode=qa` 時渲染 `HistoricalQaPanel`，傳入 `nlModel`/`answerFromNlEndpoint`/`answerFromNlStreamEndpoint` 三個 Inertia props；mode 狀態僅存在於 `useState` + `window.history.replaceState`，不涉及伺服器持久化。
- `HistoricalQaPanel.tsx`：所有狀態皆為單一答案（`question`/`consent`/`useTools`/`useStreaming`/`loading`/`error`/`answerMarkdown`/`summary`/`sqlUsed`/`toolCalls`/`evidence`/`caveat`/`showDetails`），無訊息陣列；`handleSubmit()` 每次呼叫先 `resetResults()` 再送出 `{question, use_tools}`，無對話概念；串流走手刻 SSE 行解析（`processLine`/`flushEvent`），事件種類 `status`/`tool_execution_start`/`tool_execution_complete`/`complete`/`error`；Markdown 用手刻 regex renderer 渲染並以 `dangerouslySetInnerHTML` 插入。
- 全 repo 搜尋 `chat|conversation|thread|follow-?up`（`.tsx`，不分大小寫）**零命中**——本功能將是此專案第一個聊天式 UI，`HistoricalQaPanel`/`ToolTracePanel` 是最接近可參考的既有模式（textarea 輸入、SSE 消費、Markdown 渲染、tool trace 清單渲染）。

### A.2 後端 Controller
`app/Http/Controllers/QueryPlaygroundController.php`：
- `answerFromNL()`：驗證 `question`(必填,≤1000)/`tables`(選填陣列)/`use_tools`(選填布林)，呼叫 `answerQuestion()`，成功回 JSON，失敗回 400。
- `answerFromNLStream()`：同樣驗證，包在 `streamSseResponse()` 內，成功發 `event: complete`，失敗發 `event: error`。
- `streamSseResponse()`：`set_time_limit(300)`、`ignore_user_abort(true)`、依 `config('query_playground.sse_heartbeat_seconds')`（預設 10 秒）送心跳註解、送 padding 註解避免 proxy 緩衝、以 `connection_aborted()` 偵測斷線——此機制可直接沿用於多輪的每一次獨立請求，不需要長連線跨輪次維持。
- 所有方法都各自檢查 `Auth::user()->isActive()`（非路由層 middleware）。
- 兩條路由目前都在 `Route::middleware('auth')->group(...)` 內（`routes/web.php:379`），加上 `throttle` middleware 時會以登入使用者 ID 為限流 key。

### A.3 Service 層
- `app/Services/NaturalLanguageQueryService.php::answerQuestion()`（現行簽名見 `app/Services/NaturalLanguageQueryService.php:443`）：組裝 `$messages=[system,user]`（無歷史），跑工具調用迴圈（`while ($round < $maxRounds)`，每輪視需要呼叫 `executeToolCalls()`），最終輪拿掉 `tools`/`response_format`（Gemini 若 system prompt 提及工具、即使沒帶 `tools` 陣列仍可能嘗試呼叫，故最終輪換用不含工具描述的 `$noToolSystemPrompt`）；`parseQaResponse()` 解析模型輸出的嚴格 JSON（`answer_markdown`/`summary`/`sql_used`/`evidence`/`caveat`）；每次呼叫都寫一筆 `nl_query_logs`（`question` 前綴 `[QA]`）。
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
全 repo migration 搜尋 `conversation|chat|message|session|qa_history` 除 `nl_query_logs` 外無命中；`nl_query_logs` 為稽核 log（`user_id`/`question`/`generated_sql`/`explanation`/`llm_prompt`/`llm_response`/`success`/`error_message`/`execution_time_ms`），無 `conversation_id`/`turn_index`，且未被讀回任何 prompt 組裝流程。**本規劃確認維持此現況**：多輪追問功能不需要、也不新增任何對話持久化資料結構。

### A.7 設定
- `config/migration_flags.php`：`query-playground` 無頁面層級 flag，僅 `nl-query-logs`（管理員稽核頁）有 flag，與本功能無關。
- `config/query_playground.php`：目前僅 SSE 相關（`sse_heartbeat_seconds`/`sse_padding_bytes`）；本次規劃新增 `qa_max_turns`（**定案預設 5**，對話總輪數硬上限；validation 換算歷史筆數上限為 `qa_max_turns - 1`，並透過 Inertia props 傳給前端，見第 10 節）、`qa_rate_limit_per_minute`（**定案預設 10**）、`qa_history_char_limit`（預設 6000，防禦性保險，於 controller validation 階段檢查並回 422）。
- `config/nl_query_tools.php`：`enabled`/`tools[]`/`max_tool_calls`(預設 40，QA 呼叫處另寫死預設 20，見第 7.5 節)/`timeout`（似乎未實際使用）。
- `config/services.php`：`gemini`（`api_key`/`api_endpoint`/`model`預設`gemini-3-flash-preview`/`max_completion_tokens`預設 8192）與 `gemini_fallback`（透過 `LlmFallbackTrait` 切換）。
- `config/mcp.php`：`cbdb.allowed_tables`（預設取 `codes.tables` 鍵值，可用 `MCP_ALLOWED_TABLES` 覆寫）、`cbdb.max_limit`、`cbdb.rate_limit_per_minute` 等，與 `query_read_only_sql` 工具共用；`routes/ai.php` 的 `throttle:{$rateLimit},1` 慣例即取自此設定，本次 `qa_rate_limit_per_minute` 沿用同樣的命名與使用方式。

---

## 本文件審閱紀錄

**第一、二、三輪**（先前草案：review agent → codex exec → codex exec 複查）已針對「對話持久化到 `qa_conversations`/`qa_messages`」的設計修正過矛盾與缺口（授權碼一致性、併發鎖、`use_tools` precedence、欄位容量、context 截斷規則、FK delete policy 等）。**第四輪**收到產品/架構面回饋後，判斷這個功能的真實使用場景（demo、短促問答、五輪內結束）不需要後端持久化，因此改為「歷史留在前端、每次請求帶回、後端不落庫」的無狀態設計，第一至三輪處理的持久化相關問題（IDOR、併發鎖、409、保留政策）因為設計改變而**不再適用**，非「未解決」。

**第五輪：general-purpose review agent**（讀程式碼＋核對本次重寫是否忠實反映 stakeholder email）
- 逐點核對 email 反饋（無持久化、5 輪硬上限、per-user rate limiting、`suggested_follow_ups` 延至第二階段、7.3 節截斷規則簡化）皆已在文件中正確反映，並驗證 `routes/web.php`、`routes/ai.php`、`QueryPlaygroundController.php`、`NaturalLanguageQueryService.php`（`answerQuestion()` 簽名）、`config/query_playground.php`、`HistoricalQaTest.php` 等程式碼現況描述與實際程式碼相符。
- 檢查全文殘留的 `qa_conversations`/`qa_messages`/`conversation_id`/IDOR/409/併發鎖/保留政策/migration 字樣，確認皆出現在「此設計已不需要」的說明脈絡下，無殘留的舊設計矛盾。
- 結論：無嚴重問題。

**第六輪：`codex exec --dangerously-bypass-approvals-and-sandbox`**（獨立第二意見，同樣核對忠實度＋程式碼現況）
- 獨立核對程式碼（route 分組、`throttle` 慣例、controller 驗證風格、service 簽名、config 現況、既有測試斷言）與文件描述一致，未發現與 email 反饋走樣或矛盾之處。
- 結論：無嚴重問題。提出 4 項非阻塞的可讀性建議，**已全部採納並修正**：
  1. config 鍵由 `qa_max_history_turns`（歷史筆數）改名為 `qa_max_turns`（對話總輪數，直接對應 stakeholder 講的「輪數上限」語意），validation 上限改為 `qa_max_turns - 1`（第 6.3、6.4、8.1、10、A.7 節同步更新）。
  2. 第 7.3 節 `qa_history_char_limit` 超標時明確定案為 controller validation 階段回 `422`（而非 service 層 `success:false`/`400`）。
  3. 第 8.1 節新增提醒：`answerQuestion()` 新增第 7 個參數後，既有測試若對呼叫參數做精確位置斷言（例如 `answer_from_nl_works_with_use_tools_false()`），需同步更新斷言涵蓋新參數，避免實作時漏掉導致既有測試意外失敗。
  4. 前後端輪數上限同步方式維持列為第 10 節待決策事項（非設計缺陷，屬合理留待實作階段拍板的細節）。

**第七輪：使用者拍板**（原第 10 節四項待決策事項）
- 硬性輪數上限：定案 `qa_max_turns = 5`。
- Rate limit：定案 `qa_rate_limit_per_minute = 10`。
- `suggested_follow_ups`：暫不排入第二階段時程。
- 前後端輪數上限同步方式：定案由後端透過 Inertia props 傳遞 `qaMaxTurns` 給前端，前端不寫死數字（第 6 輪 codex 建議 4 的具體拍板結果）。
- 第 10 節已由「待決策事項」改為「已拍板事項」，並同步更新第 3.1、3.2、4.1、4.3、6.4、8.1、A.7 節相關描述，反映 Inertia props 傳遞機制與確定數值；文件目前無已知待決策事項。

第五、六輪皆確認本版設計（無狀態、前端攜帶歷史）本身無已知嚴重問題或內部矛盾；上述 4 項可讀性修正已直接套用，未觸發額外一輪 review。
