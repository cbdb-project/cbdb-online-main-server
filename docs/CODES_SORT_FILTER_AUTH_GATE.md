# /codes 排序／篩選功能登入門檻 — 實作計劃

分支：`feature/codes-sort-filter-auth-gate`

## 1. 背景

2026-07 一次系統癱瘓事後分析指出：崩潰前，請求量並無異常，但 `/codes/{TABLE}?page=N&sort_by=...` 這類深分頁＋排序查詢率先變慢（≥3 秒佔比 23.1%），推擠掉 php-fpm 的 50 個 worker，進而拖垮所有端點（含 `/cbdbapi/person`、`/login` 等原本健康的路由）。

技術根因：`CodesController::buildShowPayload()` 對絕大多數代碼表使用標準 Laravel `paginate()`（`COUNT(*)` + `ORDER BY <任意欄位> LIMIT n OFFSET m`），`sort_by` 可指定任意欄位（不限有索引欄位），`filters` 用前導通配符 `LIKE '%value%'`。頁碼越深、排序/篩選欄位越沒索引，代價越高，且不隨"請求量"顯現，只隨"個體請求耗時"顯現。系統目前已有游標分頁機制，但僅對 `CBDB__NAME_FTS` 一表生效、且要求 `sort_by` 為空、無 filter，未覆蓋這個風險模式。

本輪決策（已與 repo owner 確認）：**不重寫分頁機制**，改為收斂「誰能觸發這個昂貴查詢組合」——只有帳號由 owner 手動激活、可信任的登入使用者才能使用 `sort_by` / `filters` 參數；未登入者仍可正常瀏覽代碼表（預設排序、無 filter，代價可控）。索引白名單、游標分頁擴大等治本方案留待後續獨立評估，不在本輪範圍。

## 2. 現況調查結果

- `codes/{table_name}`（Blade，`CodesController@show`）與 `app/codes/{table_name}`（React/Inertia，`CodesController@appShow`）**目前皆未掛 `auth` middleware**，任何人（含匿名爬蟲）都能直接帶 `sort_by`、`filters[...]` 打深分頁。
- 兩條路由共用同一個 `buildShowPayload()`，該方法就是排序/filter/分頁邏輯的唯一入口。
- 對照組：`view/{key}`（`ViewTableController`，另一個查詢瀏覽功能）**已經**掛 `->middleware('auth')`，證明「瀏覽類功能要求登入」在本 repo 是既有慣例，非新發明。
- **API 表面確認**（回答 owner 的疑問）：`routes/api.php` 的 `select` 前綴群組（`auth.optional` middleware）裡有一條 `Route::get('codes', 'ApiController@codes')`，實際路徑是 `/api/select/codes`，解析到 `App\Http\Controllers\ApiController`（非 `Api\ApiController`——這條路由沒有走 `Api\` 子命名空間）。**該類別目前根本沒有 `codes()` 方法**，這條路由現況是打不通的（method not found）；`CodesRepository::codes()`（回傳表名+說明清單）實際只被 `CodesController::index()`/`appIndex()` 呼叫，跟 `routes/api.php` 那條無關。無論如何，`routes/api.php` 裡沒有任何 `table_name` 相關路由，代表**不存在**任何暴露 per-table sort/filter/分頁資料的 API 路徑。既有的 Sanctum token（`routes/ai.php`、`api.php`）服務的是別的功能，跟這裡無關。
  - **結論不變：不需要 token 機制**——`sort_by`/`filters` 這個能力只存在於 web 路由（`app.codes.show`），完全走 session，只做 session 登入門檻即可覆蓋全部觸發面。
- `migration_flags.php` 中 `codes` 目前為 `'new'`，Blade 版 `show()` 理論上仍是 flag 回退路徑，但 **owner 明確決定本輪不處理 Blade 版**，只收斂 React/Inertia 版（`appShow()`）。已知取捨：若之後把 `codes` flag 切回 `'old'`，Blade 版 `show()` 會回到目前的無門檻狀態，此風險已知且暫時接受，記錄在第 7 節「風險與回退」。
- `codes/{table_name}/export` 走全欄白名單匯出、已有 `throttle:6,1`、不吃 `sort_by`/`filters`，不在本輪範圍。

## 3. 範圍界定

**In scope：**
- `app/Http/Controllers/CodesController.php`：**只動 `appShow()`**（及其呼叫的 `buildShowPayload()` 若需要傳入登入狀態）。
- React 元件（`resources/js/inertia/**/Codes/Show*`）：在 UI 層對未登入使用者做提示/降級（sort/filter 控制項提示需登入），純屬體驗加分，**後端門檻才是唯一有效防線**。
- Feature 測試：guest 帶 `sort_by`/`filters` 打 `app/codes/{table}` 應被擋，guest 不帶參數應正常 200，已登入使用者不受影響。
- i18n：若新增提示文案，`resources/lang/zh-TW/*.php` 與 `resources/lang/en/*.php` 同步。

**Out of scope（本輪不做）：**
- **Blade 版 `codes/{table_name}`（`CodesController@show`）——owner 明確表示「blade 版不用管」，本輪不修改、不補測試，維持現況（無門檻）。**
- Token / Sanctum 機制（無對應 API 表面，不需要）。
- `guard.py` / 基礎設施層調整（依 owner 指示，本輪只在 repo 範圍內處理)。
- 游標分頁擴大、`sort_by` 索引白名單、深分頁上限、`COUNT(*)` 優化（治本方案，另案評估）。
- `codes/{table_name}/export`（不吃 sort/filter，且已有 throttle）。

## 4. 設計方案

### 4.1 判定條件：什麼算「帶 sort_by 或 filter」
- `sort_by`：`$request->filled('sort_by')`（非空字串）。
- `filters`：`filters[...]` 為關聯陣列，需檢查**至少一個欄位的值非空字串**。
- `search`（全文搜尋框）：**不列入**門檻條件——它是最基本的瀏覽功能，且不像任意欄位排序那樣容易產生無索引全表掃描（多數表的 `search` 欄位有限且固定）。若後續觀察到 `search` 也被濫用，再獨立評估。
- `sort_dir` 單獨出現、`sort_by` 為空時不算「有排序」（`sanitizeSortParameters()` 現有邏輯本就要求 `sort_by` 非空才生效）。
- **重要限制（gate 是簡化判定，不是 `sanitizeColumnFilters()`/`sanitizeSortParameters()` 的完整重現）**：gate 方法在 `buildShowPayload()` 之前執行，這時候還沒查出該表的實際欄位清單（`$thead` 是 `buildShowPayload()` 內部才算出來的），所以**無法**驗證 `sort_by`/`filters[key]` 指的是不是真實欄位。結果是：即使帶的是不存在的欄位名（例如 `?sort_by=not_a_real_col`），`buildShowPayload()` 原本會靜默忽略、照樣跑便宜的預設查詢，但 gate 仍會判定「有 sort/filter」而要求登入——這是刻意選擇的「寧可誤擋、不可漏放」（fail-safe over-block），因為誤擋的代價（多一次登入）遠低於漏放的代價（重演這次事故）。實作與測試都要以此為準，不要嘗試在 gate 裡重新做欄位白名單驗證（那需要提前查表，等於把 `buildShowPayload()` 的一部分工作往前搬，增加複雜度但收益有限）。

### 4.2 Gate 實作位置
新增一個 `protected` 方法（暫名 `guardSortFilterRequiresAuth(Request $request): ?RedirectResponse`，比照本 controller 既有的 `ensureEditableAccess()` 一律用 `protected` 而非 `private`），放在 `CodesController` 內，**只在 `appShow()` 入口**、呼叫 `buildShowPayload()` **之前**呼叫（Blade 版 `show()` 不動、不呼叫此方法）：
- 若無 sort/filter：回傳 `null`，`appShow()` 照原邏輯繼續。
- 若有 sort/filter 且 `!Auth::check()`（未登入）：
  - 回傳一個導向登入頁的 redirect，用 Laravel 標準的 `redirect()->guest(route('login'))`（會自動記錄 intended URL，登入後導回原本帶 sort/filter 的頁面）。
  - `appShow()` 收到非 null 回傳值時直接 `return`。
- 若有 sort/filter、已登入、但 `!Auth::user()->isActive()`（**owner 澄清「可信任」指的是他手動激活的帳號，不是單純登入**）：
  - **不可**導向 `route('login')`——`LoginController` 建構子掛了 `$this->middleware('guest')->except('logout')`，對已登入使用者訪問 `login` 會被 `guest` middleware 攔截彈到別處，訊息會消失，使用者搞不清楚為什麼被擋。
  - 改用跟本 controller 既有的未激活擋寫入邏輯（見 `ensureEditableAccess()`）同款模式：`flash('該用戶沒有權限使用排序／篩選功能，請聯絡管理員 @ '.Carbon::now(), 'error')` + `redirect()->back()`。
- 若已登入且已激活：回傳 `null`，`appShow()` 照原邏輯繼續。

**背景**：本 repo 的 `User` 模型有 `is_active` 欄位／`isActive()` 方法，且 `CodesController` 既有的 `ensureEditableAccess()`（把關 create/update/destroy）就是用 `Auth::check()` 與 `Auth::user()->isActive()` 兩層分開判定，未激活使用者即使能登入也會被擋、並有專屬提示訊息。owner 在討論中明確講「用戶都是我手動激活的，可以信任」，對應的就是這個既有的 `isActive()` 語意，不是泛用的「已登入」——因此 gate 必須沿用同一套判定，不能只查 `Auth::check()`。

選擇「私有方法＋單一入口呼叫」而非「路由 middleware」，因為判定條件依賴 query string 內容（不是單純路徑），且只需接一個入口。**明確排除的做法：不對 `app/codes/{table_name}` 這條路由掛整條路由層級的 `auth` middleware**——路由本身永遠保持公開、允許匿名瀏覽（無 sort/filter 時）。若實作階段想把判定邏輯抽成自訂 middleware（例如 `RequireAuthForCodesQuery`），該 middleware 也必須是「檢查本次請求是否帶 sort_by/filters，沒帶就直接放行、不檢查登入狀態」的條件式邏輯，效果與方法呼叫等價，**不得變成對整條路由套用登入限制**。

### 4.3 未登入使用者的體驗
- 後端：redirect 到 `login`，登入後彈回原網址（含 `sort_by`/`filters` 參數，體驗等同「先登入再看到我要的排序結果」）。
- 前端（加分項，非防線，僅限 React `app/codes/{table_name}`）：在 sort/filter UI 上加提示；若能在載入時透過現有的 `usePage().props` 拿到目前登入狀態（多數 Inertia 頁面應該已經有 shared prop，例如 `auth.user`），可在點擊前提示「需登入才能排序/篩選」，或直接把 sort/filter 控制項在未登入時顯示為 disabled + tooltip。Blade 版不動，不加提示。

### 4.4 為什麼不做「整條路由鎖登入」
- Codes 是公開學術資料庫的一部分，預設瀏覽（無 sort/filter）查詢便宜、且可能被外部引用/書籤，鎖整條路由影響過大。
- 本輪要打的是「排序＋filter＋深 OFFSET」這個具體風險組合，precisely gate 在觸發條件上，對匿名使用者影響最小。

## 5. 測試計劃

新增／擴充 Feature 測試（位置：`tests/Feature/CodesTest.php` 或既有對應檔案，實作時確認實際檔名；**全部針對 `app/codes/{table}`，Blade 版 `codes/{table}` 不補測試**）：
1. Guest 訪問 `app/codes/{table}`（無 `sort_by`/`filters`）→ 200，資料正常。
2. Guest 訪問 `app/codes/{table}?sort_by=xxx` → 302 導向 `login`，且 session 記錄 intended URL。
3. Guest 訪問 `app/codes/{table}?filters[col]=val` → 302 導向 `login`。
4. Guest 訪問 `app/codes/{table}?filters[col]=`（空值）→ 200（不誤判）。
5. Guest 訪問 `app/codes/{table}?sort_by=not_a_real_column`（欄位不存在）→ 302 導向 `login`——驗證 4.1 講的「gate 是簡化判定，即使欄位不存在、`buildShowPayload()` 本來會忽略，gate 仍會擋」這個刻意行為，不要因為之後改動誤把它「修好」成不擋。
6. 已登入且已激活（`is_active = 1`）使用者帶 `sort_by`/`filters` → 200，功能正常不受影響。
7. **已登入但未激活**（`is_active = 0`）使用者帶 `sort_by`/`filters` → 非 302 導向 `login`（避免被 `guest` middleware 攔截），應是 `redirect()->back()` + session 有 flash error 訊息；斷言時用 `assertSessionHas('flash_notification')`（或本 repo `flash()` 實際寫入的 session key，實作時確認）而非只斷言狀態碼，避免誤判成功。
8. Guest 帶 `X-Inertia: true` header 訪問 `app/codes/{table}?sort_by=xxx` → 驗證 Inertia 請求下 `redirect()->guest(route('login'))` 的實際回應（Inertia 對 XHR 導向有專屬慣例，需確認前端能正確跟隨到登入頁，不要只測非 Inertia 的一般 302）。
9. `codes/{table}/export` 不受影響（既有測試應仍通過）。
10. 若前端加了提示 UI，補一個簡單的元件/互動測試（視實作方式決定要不要用 Playwright 或現有前端測試框架）。

## 6. 執行里程碑與 Review 流程

每個里程碑都遵循同一個收斂流程，**不得跳步**：

1. 實作該里程碑的最小變更。
2. 派出一組 review agent（讀程式碼＋讀 diff）進行檢查；有嚴重 issue 就修正，重新送審，直到這組 agent 沒有回報嚴重 issue。
3. 呼叫 `codex exec` 對同一份變更做第二輪獨立檢查，直到沒有嚴重 issue：
   ```powershell
   $env:HTTPS_PROXY = "http://127.0.0.1:7890"; $env:HTTP_PROXY = "http://127.0.0.1:7890"
   Write-Output "<review 用的 prompt，說明本次變更範圍與要檢查的重點>" | codex exec --dangerously-bypass-approvals-and-sandbox
   ```
4. 兩輪都乾淨後，才進入下一個里程碑。

### 里程碑清單

- **M1｜Gate 邏輯**：在 `CodesController` 新增判定＋導向方法（4.1、4.2），先不接線，搭配單元測試涵蓋判定邏輯（空 filter、有 filter、有 sort_by、皆無）。
- **M2｜接線 React/Inertia 路徑**：`appShow()` 接上 gate，補 Feature 測試（測試計劃 1-5）。
- **M3｜迴歸驗證**（已完成）：`./vendor/bin/phpunit --filter Codes`（141 個測試）僅 3 個失敗，皆為 `OfficeCodesExportTest`（`/codes/{table}/export` 節流狀態在同進程內互相污染，`export()` 未被本輪改動）。用 `git stash` 切回乾淨 `develop` 重跑同一指令，失敗**同樣只出現在 `OfficeCodesExportTest.php`**，但失敗數不是精確的 3 個（因為本分支多了兩個新測試檔，改變了 PHPUnit 依字母序探索/執行的順序，連帶改變多少既有請求先耗掉同一個 throttle 快取視窗）——不是「完全一致」，但兩邊指向同一個既有缺陷（節流狀態未在測試間重置），且單獨跑 `OfficeCodesExportTest.php` 這一個檔案時，兩邊（改動前後）失敗數與案例名稱**確實逐一相同**（9/11，見下方獨立驗證）。屬既有缺陷、跟本輪無關，不在本輪修。`tests/Feature/CodesControllerTest.php`（Blade 版 `show()`／`store()`／`update()` 等，61 個測試）單獨跑全過，確認 Blade 版行為零變化。（`tests/Feature/CodesShowInertiaTest.php` 在 M2 已因新 gate 補上 `actingAs`，屬 M2 範圍內的必要修正，非 M3 新增。）
- **M4｜前端 UX 提示（可選加分項）**：React 側加提示文案／disabled 狀態，i18n 雙語同步，若有前端測試補上。
- **M5｜文件收尾**：更新 `CHANGELOG.md`（本輪變更摘要），視需要在 `AGENTS.md`「高風險區域備忘」補一筆（`app/codes/{table}` sort/filter 現在需要登入；Blade 版 `codes/{table}` 未同步處理，若之後 flag 回退成 `old` 需重新評估）。

## 7. 風險與回退

- **已知取捨（owner 已確認接受）**：Blade 版 `codes/{table_name}`（`show()`）本輪不處理。只要 `migration_flags.php` 的 `codes` 維持 `'new'`，使用者走的是 `appShow()`，門檻有效；但若之後把 `codes` flag 切回 `'old'`，流量會改走未受保護的 Blade 版 `show()`，這個風險組合會重新出現。**若未來要切回 `old` flag，需要先重新評估是否要補做 Blade 版的門檻。**
- ~~風險：intended-URL 導回機制若跟現有 `login` 流程互動有例外~~ **（M2 已驗證，非風險）**：`redirect()->guest(route('login'))` 對帶 `X-Inertia: true` 的請求，實測仍是單純 302（非 409/`X-Inertia-Location`）。原因：`AuthInertiaRedirectTest.php` 裡的 409 是 `LoginController::sendLoginResponse()` **主動**判斷「登入成功後的目的地是 Blade（dashboard）」才手動呼叫 `Inertia::location()`；那是逐個呼叫點自行決定，不是 Inertia middleware 對所有 redirect 的通用行為。`route('login')` 本身走 `auth.login = 'new'`，`showLoginForm` 有掛 `inertia` middleware，是 Inertia 渲染的頁面，所以我們的導向目的地是 Inertia-aware 的，plain redirect 對 Inertia XHR client 而言可以正常走完（同源導向，`X-Inertia`/`X-Inertia-Version` header 會在瀏覽器層級跟著轉送）。已用 `tests/Feature/CodesSortFilterAuthGateTest.php::testGuestWithSortByAndInertiaHeaderStillGetsPlainRedirectToLogin` 鎖住。**踩雷記錄**：驗證時發現 Inertia 的 `Middleware::handle()` 對 GET 請求會比對 `X-Inertia-Version` 頭與伺服器端版本雜湊（見 `vendor/inertiajs/inertia-laravel/src/Middleware.php:133,169`），版本不符會觸發完全不相關的 409/`onVersionChange()`（導回原網址、跟登入門檻無關），測試時務必固定 `app.asset_url` 讓版本可預期，否則會被這個雜訊誤導。
- 回退：門檻邏輯集中在 `CodesController::appShow()` 呼叫的一個方法內，若上線後發現誤擋合法使用者，移除該方法呼叫即可秒回退，不影響其他既有邏輯（不像 migration flag 那種需要環境變數重啟）。
- 本輪變更不改資料庫結構、不改既有 API 契約（`api.php` 的 `codes` 端點不受影響）。
