# React/Inertia 遷移 — 真實互動模擬測試計畫

> 本計畫取代先前「靜態 parity 對比」版本。核心轉變：**不再只比靜態渲染的資料/結構**，而是由 AI 代理以 Playwright 驅動真實瀏覽器，在舊 Blade 頁與新 React/Inertia 頁上**執行相同的真實使用者操作**（打字檢索、填表送出、點按鈕、新增、修改、查詢、刪除），再**對比操作後渲染出來的結果**。

## 〇、最高紀律（不可違反；違反即作廢）

> 本節是與使用者明定的工作方法，**優先於本文件其餘所有內容**。AI 代理執行遷移驗證時，無論是否有 memory，一律照此執行。背景見 §0.4 案例。

### 0.1 gate-before-flip：對比通過「之後」才能翻 new
- 任何頁面的 migration flag **翻成 `new`（上線）的唯一依據，是該頁已通過本計畫的「逐頁內容/互動對比」**（對比由 **AI agent 判讀**執行，定義見 §0.3）。
- **禁止**以下列任何一項當作上線依據（它們都是輔助，不是關卡）：單元測試綠、PHPUnit 全綠、code review / codex 乾淨、弱煙測（HTTP 200 / 無 JS error）。
- 對比**未通過、未做、或仍有未補的缺漏** → flag 一律 `old`。順序永遠是「對比通過 → 才翻 new」，**絕不可**「先翻 new → 之後再補對比」。
- 通過本節閘門（§6.0 全流程：對比無缺漏 + review + codex 皆無嚴重 issue）後，**由 AI agent 執行翻 new**；重大頁面變更應在翻 new 時一併告知使用者。未過閘門一律維持 `old`。

### 0.2 不可遺漏舊頁任何內容（除非有「更合適的設計」並說明）
逐頁對比時，新頁**必須不遺漏舊頁呈現/提供的任何東西**，包括但不限於：
- **資料值**（含互動後：檢索後的結果列、送出後的新值、刪除後的消失）；
- **欄位**（每個 input/select/textarea，及其「是否可直接編輯」——舊頁可直接錄入的，新頁不可改成「需先開啟編輯」，除非經使用者同意）；
- **label / 欄位名**；
- **說明性文字**：help / 提示 / 警告 / 角色說明 / 輸入範圍（如農曆 1–12、1–30）/ 自動生成說明 / 危險操作警告；
- **按鈕**（含其可見文字與啟用條件）、**連結**（含 href 目標）、**分頁/導覽**（含分頁筆數）。

唯一例外：新頁採用了**明確更合適的設計**且**已向使用者說明並取得同意**；否則一律以「不漏舊頁任何東西」為基準。發現缺漏 → **補齊後**才算通過。

### 0.3 方法是「全量證據擷取 + AI agent 判讀對比」，不是自動 PASS/FAIL
- 對比腳本（見 `tests/e2e/compare-pages.mjs`）**只負責把證據抓全**——舊頁與新頁兩邊的：全部可見文字（mainText）、headings、所有 label、所有按鈕文字、所有連結（含 href）、所有表單欄位（`tag/type/id/name/value/placeholder/readonly/disabled`）、所有分頁（含 `<button>` 型分頁條，分頁筆數以徽章文字含在分頁文字內）、所有提示/警告文字、截圖——**dump 成 JSON，不判 PASS/FAIL**。
  - 此腳本**未直接輸出**的證據，須由 agent 推斷或另跑腳本取得：「欄位可否直接編輯」由 `readonly/disabled` + 進場是否即為可編輯欄位推斷；「列表總筆數/末頁」「互動後結果」「字體實際載入」「導流/落地」「computed style」分別靠互動腳本（A–H）與 I/J/K 流程（見 §6.0 第 3 步）。
- **判定交給 AI agent**：agent 親自讀兩邊證據、逐項比，像人類測試員那樣判斷，產出「舊頁有、新頁缺或變了」的**差異清單**。差異清單為空，才算該頁通過。
- **「人工 / 像人類測試員 / 目視覆核 / 複核 / 由人確認」等同義表述一律指 AI agent 親自判讀/執行**（本文件全文皆然，含 §一各小節的「人工複核/目視覆核」、§六的翻 new）——**不是要求真人逐行檢視或真人操作**。正因為證據擷取已自動化、判讀由 AI 執行，這套流程才可規模化逐頁跑；若改成真人逐行看，就失去自動化測試的意義。**唯一例外**：當 agent 自己也無法判定真偽（例如某格式差異是否語義等價、某設計取捨是否「更合適」），才升級給使用者裁示。
- 設計、內容、互動三個維度都要比，不能只比「agent 選擇抽取的那幾項資料」。

### 0.4 反假綠紀律（這些是反覆踩過的坑）
- **全綠不是成功，是該起疑的信號**。報告「PASS / 全綠」前先自問：「打開這頁，使用者一眼會看到什麼我沒比到的不一致？」
- **腳本不可有逃生口**：`catch(()=>[])`、optional chaining 吞錯、選錯/抓不到 selector 就回空——這些會把「沒抓到」偽裝成「沒差異」。擷取失敗要**明顯報空 + 保留原文**，讓 agent 看得見。
- **必須打使用者真正訪問的那條 route**，不可寫死成另一個看起來相近的 URL（例：要驗 `/app/basicinformation/{id}`，不可改打 `/app/person-browser` 就宣稱測過）。
- **計數先行**：先看兩邊「分頁數 / 欄位數 / label 數 / 提示數 / 正文長度」的計數摘要，數量級差異（如舊 13 分頁→新 0）立即就是紅燈。
- **擷取失敗不得判通過**：若 JSON 任一側為 `null`、或該側有 `captureError`／無 `counts` 欄位（腳本擷取失敗時該側只有 `{url, captureError, jsErrors}`、不含 `counts`）、或 `counts` 全 0、或頂層 `out.error`，一律視同「該側根本沒抓到」（擷取失敗），**不得**據此判通過——須先修腳本/選擇器或排除環境問題，重抓到完整證據為止。
- **量測工具本身也要無盲點**：若某類元素（如以 `<button>` 渲染的分頁條）被選擇器漏掉而顯示 0，那是工具盲點、不是產品沒有——**修工具**使其能抓到，而非接受誤導性的 0。
- **案例（為何立此紀律）**：`/app/basicinformation/{id}` 曾被翻 new 上線，但實機打開：缺整排 12 個子資源分頁、缺進入各子資源編輯器的入口、基本資料由「直接可錄入」退化成「唯讀需另點編輯」、缺 27 條欄位提示——正文從舊頁 67k 字掉到 1.5k 字。**為何沒測到**：(1) 只跑了弱煙測（200 / 無 JS error），(2) 煙測打的是 `/app/person-browser` 而非使用者實際點進的 `/app/basicinformation/{id}`，(3) 在「逐頁對比之前」就翻了 new。三條全踩 §0.1/§0.3/§0.4。

### 0.5 本 session（2026-06-24）新增覆蓋——從實際漏抓的 bug 反推（人工目視先抓到、機器測該抓而未抓）
> 背景：詳情中樞/編輯器逐頁人工目視時，使用者抓到一批機器測「本該輕易抓到卻漏掉」的問題。以下五類補進必比維度，下次用本計畫重檢時一律涵蓋。根因多為「**確認存在而非逐條比內容**」與「**各頁各自實作 → 分歧**」。

1. **AI／工具面板要「逐條比文本內容」，不可只確認面板存在**（**最嚴重漏抓**）。案例：assoc 編輯器的「AI 智能識別」面板**整段使用須知（重要提示：數據收集與第三方服務 + 3 條同意條款 + 當前使用模型 gpt-x）全部缺失**，statuses 卻有；judging 卻把它判成「collapse→單行的格式差異」而放行——典型 §0.4 假綠。**規則**：凡 legacy 有 AI/工具面板（offices 任官自動填、assoc/statuses code-lookup），新頁必須逐條比對面板內**所有文字**：標題、隱私使用須知標題、每一條同意條款、當前模型行、結果統計行（matched/suggested/not_found/empty_count）、候選呈現。這些文字 compare-pages 已擷取在 mainText/hints，差異清單**必須逐條列**，缺一即缺漏。

2. **跨頁一致性（新 app 內部）——本計畫原本只比「新 vs 舊」，未比「新各頁之間」**。多個 bug 根因是**同類元件各編輯器各自實作而分歧**：3 個 AI 面板配色/結構不同、各編輯器 label 欄寬不一、按鈕大小不一。**規則**：凡應為共享的元件（AI 面板、表單列 label/input、動作按鈕列、PersonBanner、SubresourceTable），須抽出**單一共享元件**並驗證**各使用頁渲染一致**；任一頁與其他頁分歧即 bug。檢查法：同類頁兩兩截圖比對 + 確認共用同一元件（非各自 inline 複製）。

3. **視覺對齊（label 欄寬一致 → input 左緣對齊）——compare-pages 的純文字比對抓不到**。案例：basic-info 名區 label 96px、單欄列 220px，導致 input 左緣參差；assoc 關係列 label 置頂 vs 其餘靠左也參差。legacy 靠 Bootstrap grid（col-sm-2 ≈ col-sm-4-in-col-sm-6 ≈ 180px）天然對齊。**規則**：K 視覺維度新增「一編輯器內所有 input 左緣是否對齊（label 欄寬是否一致）」；以 DOM 量測各 input 的 `getBoundingClientRect().left`（同類列應相等）或截圖目視，不能只靠文字 diff。

4. **動作按鈕一致性（大小/高度 + 排列）**。案例：basic-info 底部 直接儲存／提交提案／刪除／Duplicate×2 **大小不一**（`<button>` 與 `<a>` 混用 padding 不同）且**全堆左**，legacy 並非如此。**規則**：動作列所有按鈕**同一視覺尺寸**（統一 padding/height，`<a>` 須比照 button），**排列對齊 legacy**（分組/對齊方式，不全擠左）；K 維度比對按鈕的 computed height 與容器對齊。

5. **「可接受 delta」要再核：後端自動推導若移除了使用者的必要選擇，不算等價**。案例：kinship/assoc 的互逆配對碼由後端依 KINSHIP_CODES.c_kin_pair1 自動同步、編輯器移除手填——但反向關係常有**歧義需人選**（父→子或女、第幾子…），legacy 容許手選。**規則**：凡標為「後端自動／accepted delta」而移除了 legacy 的使用者輸入欄，須確認該欄是否承載**使用者才能決定的語義**（性別/排行/多值歧義）；若是，則為**功能缺口**而非等價 delta，須補回可選（預設用權威值、允許覆寫）。

6. **互逆關係「鏡像列資料正確性」必須在 create 與 edit 兩條路徑各自落庫驗證——不可只驗編輯器有選擇器，也不可只驗 edit 路徑**（2026-06-24 使用者於真實人物 1762 手動新增 assoc 觸發的**惡性 data-integrity bug**）。根因：assoc 反向配對碼補齊（依 ASSOC_CODES.c_assoc_pair 取權威反向碼）**只實作在 MutationHandler，CreateHandler 漏做**，且 AssocEditor 有一條**假註解**聲稱「後端已自動補齊故不送 pair」——實際 create 時鏡像列 `c_assoc_code` 被寫成哨兵 **0（未详）**，對方人物（如苏轼）平白多一條無意義成對關係。compare-pages 的純文字/欄位擷取**抓不到**這種「對方表裡多一筆錯列」。**規則**：(a) 凡有「後端雙向鏡像同步」的子資源（assoc / kinship），測試須在 **create 與 edit 兩條路徑**都斷言**對方鏡像列的關係碼 == 代碼表權威反向碼（或使用者手選值），而非 0/未详**；(b) 對「後端自動補齊」類 accepted-delta，須**逐 handler（create/mutation/proposal-approve）核對是否都實作了該補齊**，不可因一個 handler 有就假定全部有（§0.4 假設陷阱的鏡像版）；(c) 凡編輯器 docblock 聲稱「後端保證 X」，須**讀後端碼確認 X 為真**，否則即為假綠來源——本案的假註解正是讓 reviewer 略過的元兇；(d) 反向有歧義（一個正向碼在 ASSOC_CODES 有 c_assoc_pair 與 c_assoc_pair2 兩個合法反向）時，編輯器須提供手選（預設權威值、允許覆寫），且鏡像須落手選值。

## 一、方法論

### 1.1 真實互動，而非靜態快照
每個測試單元都是一段「使用者劇本」：定位輸入框 → 打字 → 點按鈕 → 等待結果重渲染 → 擷取結果 → 新舊對比。不接受「只開頁讀 props/DOM 就判 PASS」。

### 1.2 對比基準：語義內容對比（非原始 HTML）
舊版是 AdminLTE/Blade、新版是 React/Tailwind，外殼 HTML 標記本來就不同，逐字比對無意義。因此：

- **判定依據**（此為 §0.3 中 **AI agent 判讀時的比對準則**，非腳本自動裁決；腳本只擷取證據）：從兩邊渲染後的 DOM 抽出「有意義的結果」並逐項相等比對——
  - 檢索結果列的**儲存格值序列**（**動態**排除動作欄：動作欄隨 `can_edit` = 登入且非唯讀才出現，故兩側須在**相同登入狀態**下擷取，且以「該欄是否含連結/按鈕」動態判斷並剔除，不可寫死「最後一欄」）；
  - 詳情/編輯表單回填的**欄位值**：**以欄名為鍵**逐欄比對，**不可用位置序列**——`appEdit`(回填) 用 `orderAuditFieldsForDisplay`、`appCreate`(新增) 用 `orderColumnsForCreate`(PK 優先)，兩者欄序不同；
  - 操作後的 **flash 訊息語義**：**逐流程定義規則**，先**剝除尾端 `@ <timestamp>`**（後端 flash 如 `'Store success @ '.now()`）再比；且注意**codes flash 為英文**（Store/Update/Delete success）、**manage flash 為中文**——跨流程文案不同，只比「成功/失敗 + 影響語義」，不可跨流程逐字；
  - 列表**總筆數 / 末頁**；
  - 刪除後**該列是否消失**、新增後**該列是否出現且值正確**。
- **同時存證**：每個對比點把兩邊結果區塊的 `innerText`（及必要時 `innerHTML`）一併寫入報告，供 agent 目視覆核（即 AI agent，見 §0.3；非真人）。語義對比是閘門，HTML 存證是佐證。
  - 註：`compare-pages.mjs` 現成輸出只含 `mainText`（無 `innerHTML`），且 `counts` 不含「列表總筆數/末頁」；上述 `innerHTML`、列表總筆數/末頁屬「方法要求」，須由互動腳本（A–H）或額外擷取補上，不是 `compare-pages` 已提供的證據。

### 1.3 合成資料生命週期（自建即用即刪）
真實的「新增/修改/刪除」**只動測試自建的合成資料，永不碰真實 CBDB 資料**：

1. **建**：測試開始時，於目標表插入一筆帶有明顯測試標記（如 `c_...code = 9_999_xxx`、名稱含 `E2E-TEST-<run>` 前綴）的合成列；或使用既有的拋棄式測試使用者。
2. **用**：對這筆合成列做「改 / 查 / 刪」並雙邊對比。
3. **刪/復原**：測試結束（含失敗路徑）一律於 `finally` 清理，確保可重複執行、不殘留：
   - codes 目標表合成列：用主鍵精準刪除。
   - **連動稽核列**：codes `performStore/performUpdate/performDestroy` 每次都呼叫 `recordOperation` 寫一筆 `operations` 列；CRUD 生命週期會殘留數筆。清理時**須一併刪除對應 `operations` 列**（用 resource/操作標記精準定位），否則違反「不殘留」。
   - **manage 軟刪不可逆**：`performUserUpdate` 軟刪會覆寫 `email→email-<ts>`、`password='-'`、`confirmation_token='-'`、`remember_token='-'`，**原雜湊無法還原**。故 manage 軟刪**只能對拋棄式帳號**做，且軟刪後該帳號報廢、不可復原、不可重跑（重跑需先 reseed 新拋棄帳號）。改 is_active/is_admin（流程 F）是可逆的（測畢改回原值即可）；軟刪（流程 G）是不可逆的終結動作。
4. 合成列的主鍵值固定可預測，清理時用主鍵精準刪除，不誤刪真實資料。

### 1.4 唯讀防護與刻意寫入面
- 預設 context 攔截所有非 GET/HEAD 請求（abort + 記錄），`serviceWorkers:'block'` 防繞過。
- **刻意寫入的單元**才在該操作前後暫時放行對應的寫入端點（白名單到具體 method+URL），操作完立即收回；其餘請求仍攔截。任何非預期寫入 → 該單元 FAIL。

### 1.5 對抗性紀律與「資料以外」的維度（本版新增，補先前盲點）
> 教訓：先前只比「我選擇抽取的 DOM 文字資料」，且**用寫死 `/app` URL 直接開頁**，導致**呈現層**（字體/CSS/實際載入資源）與**進入路徑**（側邊欄連結、登入落地）完全在斷言之外——使用者一眼能看到的真問題（如 `/app` 宣告 `Source Sans Pro`/`Noto Sans TC` 卻沒實際載入字體）卻測不到。修正三原則：

1. **對抗式而非確認式**：每個單元都要先自問「**什麼差異是我目前的斷言看不到的？**」，再補一條檢查。全綠不是成功，是該起疑的信號。
2. **走使用者真正的路徑**：能用點側邊欄/連結抵達就**不要寫死 URL**；驗證「怎麼到這頁」與「這頁長怎樣」，不只是「這頁的資料」。
3. **呈現層也是 parity 的一部分**：字體、實際載入的 @font-face、關鍵 computed style、視覺，都要納入斷言。「宣告了字體名」≠「載入了字體」。

對應新增三個維度流程（I/J/K，見矩陣）。

## 二、可雙邊對比的流程矩陣

| # | 流程 | 操作類型 | 舊路由 | 新路由 | 對比點 |
|---|---|---|---|---|---|
| A | **codes 單表 搜尋** | 檢索（讀） | `GET /codes/{t}` `name=search` 送出 | `GET /app/codes/{t}` 受控搜尋送出 | 結果列儲存格值序列 + 總筆數 |
| B | **codes 單表 欄位過濾** | 檢索（讀） | `filters[{col}]` + 套用鈕 | 受控 filters + apply | 過濾後列值序列 + 筆數 |
| C | **codes 單表 新增** | 新增（寫） | `GET /create`→`POST /codes/{t}` 直接存 | `appCreate`→`appStore` | 送出後列表/詳情出現該合成列且欄值正確 + flash |
| D | **codes 單表 修改** | 修改（寫） | `GET /{id}/edit`→`PUT/PATCH` | `appEdit`→`appUpdate` | 改後回填值 + 列表該列新值 + flash |
| E | **codes 單表 刪除** | 刪除（寫） | 列上 `btn-danger`→`DELETE` | ConfirmDialog→`router.delete` | 刪後該列消失 + flash + 筆數 −1 |
| F | **manage 使用者 改啟用/角色** | 修改（寫，**可逆**） | `GET /manage/{id}/edit`→**PUT** | `appEdit`→`appUpdate`(**PATCH**) | 列表該列狀態/角色 badge 變化 + flash；測畢改回原值 |
| G | **manage 使用者 軟刪除** | 刪除（寫，**不可逆**） | 勾 `delete_user=1`→**PUT** | destructive→ConfirmDialog→**PATCH**`{delete_user:1}` | 軟刪後該列消失 + flash；**僅對拋棄帳號、不可復原** |
| H | **ExplainSQL 送出** | 查詢（讀寫表單） | `GET/POST /admin/explainsql` | `app.admin.explainsql(.explain)` | 同一 SQL 的 EXPLAIN 結果列語義一致 |
| **I** | **資源載入 / 字體 parity** | 呈現（讀） | 任一舊頁 | 對應 `/app` 頁 | **鎖定目標家族** Source Sans Pro + Noto Sans TC（不把 Font Awesome 算進比對，避免噪音）。**雙重判準**：(1) 等 `document.fonts.ready` 後比對實際註冊的 @font-face families；(2) **網路層佐證**——`page.on('response')` 過濾 `resourceType==='font'`（或 URL 命中 `.woff2`/fontsource chunk），斷言新頁實際**下載**了目標字體檔。新頁須實際載入舊頁所載入的字體，不可只在 CSS 宣告字體名。**`document.fonts.check` 受測試機系統字體影響會假 PASS，不可單用；`document.fonts` 列舉的 FontFace 可能 `status:'unloaded'`，故須 fonts.ready + 網路層雙重佐證。** |
| **J** | **導航完整性（flag 連結目標）** | 進入路徑 | 側邊欄 | 側邊欄 | **以點側邊欄連結抵達**（不寫死 URL）：斷言只對**經 `Navigation::url(flag, old, new)` 解析的節點**生效——對 `migration_flag_is_new()=true` 者 href→`/app/*` 且點擊落 React 頁、`=false` 者→舊 Blade。**必須排除「不經 `url()`、直接 `routeUrl('app.*')` 的恆新項目」**：`query-playground`、`person-browser`、`search-by-entry`、`maps`、`views-overview-new`（這些永遠指 /app，與 flag 無關，與 §三「新獨有」一致），對它們套 flag 斷言會假 FAIL。另：(a) 須在**正確登入身分**下擷取側邊欄（superadmin-only 子樹對他人不渲染）；(b) `routeUrl()` 對未註冊路由回 `null`，須區分「路由未註冊」與「flag 指向錯」，不可把 null 當失敗。涵蓋**登入後落地頁**是否符合 flag。 |
| **L** | **表單/詳情頁欄位集合完整性 parity** | 內容（讀） | 任一舊編輯/詳情頁 | 對應 `/app` 頁 | **掃描**每個已遷移的編輯/詳情頁，收集舊頁顯示的**欄位/值集合**（label 文字、readonly 欄位值、顯示的資料值如 created_at/updated_at 時間戳），斷言新頁**未缺少**舊頁呈現的任一資訊。**重點抓「新頁少了舊頁有的欄位」**（如 manage 編輯頁新版缺 `註冊時間 created_at`/`最後更新時間 updated_at`——連 `appEdit` 的 props.user 都沒帶這兩欄）。比對以「值集合包含關係」為主（label 文字新舊可能不同，故以**舊頁顯示的資料值是否出現在新頁**為準），既抓缺漏也報多出。**流程 D（codes 編輯）亦須升級為比對完整欄位集合，而非只比所改的單一值。** **實作要點**：(a) 新頁「呈現值」須含 `<main>` innerText **加上表單欄位值**（input/select/textarea），否則以 input 渲染的欄位值不在 innerText 內會假 FAIL；(b) 取文限縮 `<main>`（排除側邊欄）避免短值在側欄假命中（此限縮指 **L 專用擷取腳本**；`compare-pages.mjs` 的 `pickMain` 取 `.content-wrapper`/`main`/`#app` 首個命中者、範圍可能更大，不等同 L(b) 的 `<main>` 限縮，故 L 須自行擷取勿直接沿用 compare-pages 的 mainText）；(c) `select` 至少同時採集**當前 option text 與 option value**，新頁若以原始 value 呈現、舊頁以 select2 標籤文字呈現，應優先視為**格式差異**而非硬 FAIL；(d) 若仍有字串格式不一致（例如時間戳格式化、人物 select2 標籤更豐富）而落入 DIFF，須人工複核分辨「真缺漏」與「格式差異」；(e) codes 用含稽核欄的 `_DATA` 表、rowId 由 show 頁動態解析；(f) **不只比「欄位值」，也須比「說明性文字內容」**（help/警告/提示/角色說明等）——manage 編輯頁新版曾缺「角色說明 4 條、未激活提示、危險操作標題、刪除警告」，這些是純文字（非欄位值），只比欄位值的版本測不到。新頁須包含舊頁呈現的關鍵說明文字片段（以 curated 文字片段或舊頁 p/li/heading 文字存在性比對；注意格式差異需人工複核）。 |
| **K** | **視覺 parity（關鍵 computed style）** | 呈現（讀） | 任一舊頁 | 對應 `/app` 頁 | 比對主要容器關鍵 computed style + 截圖留存供人工目視。**警告：computed `font-family` 字串新舊幾乎相等（皆解析出 'Source Sans Pro','Noto Sans TC',…），但字串相等≠字體已載入——對本案字體 bug，K 的 font-family 字串比對會假 PASS。** 故 K 僅輔助，字體載入的紅燈一律以 **I（載入清單 + 網路層）** 為準，視覺差異以截圖/像素為準。 |

> **I/J/K 是「資料以外」維度**，補先前盲點（見 §1.5）。I 直接驗證字體實際載入；J 強制走側邊欄而非寫死 URL，抓出「flag 未翻/側邊欄仍指舊頁/登入落地錯」這類進入路徑問題。
> **flag 生效快取陷阱**：Laravel 12 的 `php artisan serve`（`ServeCommand`）會**監看 `.env`，變更時自動重啟** server（除非加 `--no-reload`），故 `.env` 的 flag 改動**會即時生效，不需手動重啟**。真正的陷阱只有一個：若曾跑過 `php artisan config:cache`，`config()` 改讀 `bootstrap/cache/config.php` 而**完全忽略 `.env`**，此時改 `.env`（含重啟 serve）都無效，必須先 `php artisan config:clear`。流程 J 實作時仍以**瀏覽器實際看到的側邊欄連結**為準（而非 CLI `migration_flag_is_new`），若兩者不一致，優先懷疑殘留的 config cache。
> A–E 在同一張可寫 codes 目標表上串成一條完整 CRUD 生命週期（建→查→改→刪），合成列即用即刪。
> **直接存 vs 提案是兩個獨立按鈕/端點，不是角色分支**：`performStore/Update/Destroy` 只檢查 `Auth::check() && isActive()`，**任何 active 使用者點「直接儲存」(save_direct) 都直接寫目標表**；「提案」(submit_proposal) 是另一條路由（`app.codes.propose.*`），寫的是 `operations` 表**而非目標表**。本計畫 C–E 一律**點 save_direct**，**絕不可點 submit_proposal**（否則沒寫到目標表、卻污染 operations，是假測）。`can_propose`/`can_edit` 只決定按鈕是否出現，與 superadmin 身分無關。
> manage 需 `canManageUsers()`。

### 目標表選定（流程 A–E）
- 選一張**結構單純、主鍵明確、可安全插入合成列**的 codes 表（候選由實作前以 `CompositePrimaryKey::SCHEMAS` 與欄位數最少者中挑選並記錄於報告）。
- 合成列主鍵採高位保留值（如 code 欄 `9999xxx`），名稱欄含 `E2E-TEST-<runtag>`，確保不與真實資料碰撞、清理可精準定位。

## 三、不可雙邊對比者（明確標註，不混入 parity 結論）

| 流程 | 狀況 | 處置 |
|---|---|---|
| Person Browser | 新獨有，無舊 Blade | 僅新版**功能煙測**（搜尋→出結果→無 JS 例外），不宣稱 parity |
| Query Playground | 舊頁已 302 轉新頁 | 僅新版功能煙測（打 SQL→執行→結果表/錯誤訊息） |
| search-by/entry | 新獨有 | 僅新版功能煙測 |
| BasicInformation 子資源 新增/改/刪 | 舊有完整 Blade UI、新只有唯讀 index（mutation 走 API 非 Inertia 表單頁） | 不做 UI 雙邊對比；如需驗新側 mutation，另以 API 層測試（非本計畫 UI 範圍） |
| codes 提案（propose store/update）+ proposal-edit 審核 | 舊新皆有完整對應（`app.codes.propose.*`、`proposalEdit`/`appProposalEdit`） | **本計畫暫不對比**（提案寫 `operations` 流程，與直接存 CRUD 正交；列為後續可擴充，避免與流程 C–E 的 save_direct 路徑混淆） |

## 四、各流程互動規格（selector 重點）

- **舊頁**：可用 `input[name=...]`、`button[type=submit]`、`form[action=...]`、固定 id（`#is_active`、`#is_admin`、`#delete_user`、`#__proposal_comment`、`#delete-form-{id}`）。原生 `alert/confirm` 需 `page.on('dialog', d=>d.accept())`。
- **新頁**：多為受控元件、**無 name 屬性**；改用 `id`（codes 欄位 `id={col}`、`#is_active`、`#is_admin`、`#__proposal_comment`）、按鈕可見文字（i18n：`save_changes`/`save_direct`/`search`/`apply_filters`/`delete`/`run_query`）、`placeholder`、`aria-label`。刪除走 `ConfirmDialog`（先點 destructive 鈕開 dialog，再點確認鈕），非原生 confirm。
- **提交方法**（唯讀防護白名單須按各側實際 method 放行，否則假 FAIL）：
  - manage update **route 兩側都接受 PUT|PATCH**，但 **UI 實際送出**：舊 Blade = **PUT**（`manage/edit.blade.php` `@method('PUT')`）、新 React = **PATCH**（`form.patch`）。白名單按「實際送出」對舊側放行 **PUT**、新側放行 **PATCH**。
  - codes update：舊 `@method('PATCH')`／新 `form.patch` → 兩側 **PATCH**；codes store = **POST**；codes destroy = **DELETE**（舊隱藏 form `@method('DELETE')`／新 `router.delete`）。
  - 白名單只放行「當前刻意操作的那一條 method+URL」，操作後立即收回。
- **結果擷取**：
  - codes 列表：舊 `div.table-responsive > table.table-bordered > tbody`；新 `div.overflow-x-auto > table > tbody`。逐列抽資料欄（排除動作欄），值序列比對。
  - manage 列表：舊 `table.table tbody`；新對應 `table tbody`。比狀態/角色 badge 文字。
  - flash：操作後讀渲染後 DOM 的 flash 容器（新版 `[role=status]`），不可讀 `#app[data-page]`（client-side 導航後陳舊）。

## 五、安全 / 共識
- 目標 DB：`cbdb_data`（開發 / 可丟棄資料）。
- 合成資料即用即刪；失敗路徑亦於 `finally` 清理。
- 拋棄式管理帳號（superadmin 登入用、regular 軟刪目標）測畢可刪。
- AI 功能不深測（控成本）：query-playground 煙測僅打一句安全 SELECT；不觸發 NL/SSE 生成。
- 所有 e2e 腳本、報告、截圖一律本地（`.git/info/exclude` 排除），不進版控。

## 六、執行與過閘規範

### 6.0 每頁的標準流程（gate-before-flip，見 §0.1）
對每一個「已遷移／待上線」頁面：
1. **確認入口**：確定使用者實際訪問的舊/新 route（不可寫死相近 URL）。
2. **全量證據擷取（內容/結構）**：`node tests/e2e/compare-pages.mjs <pairLabel> <oldUrl> <newUrl> [role]`，產出 `storage/app/test-artifacts/compare/<pairLabel>.json` + 兩邊截圖。腳本只出證據、不判 PASS（見 §0.3）。**注意：此腳本只涵蓋「內容/結構」一個面向，不足以單獨判通過**——它不擷取「互動後結果、字體實際載入、側欄/flag 導流、computed style、列表總筆數」等；那些須靠下面第 3 步的對應流程/腳本。
3. **逐項判讀 diff，並涵蓋下列「全部」必比維度**（缺任一維度都不算完成；由 AI agent 判讀，非真人逐行，見 §0.3）：
   - **內容/結構**：讀 compare JSON（先看 counts 摘要抓數量級紅燈，再逐項讀 headings/labels/buttons/links/fields/tabs/hints/mainText）；
   - **互動（流程 A–H，適用時）**：跑對應互動腳本（檢索/過濾/新增/改/刪/查詢），擷取**互動後**渲染結果再比（合成資料即用即刪，見 §1.3）；
   - **L 完整欄位集合 + 說明性文字**：逐欄比對新頁未缺舊頁的欄位/值，且未缺 help/提示/警告/角色說明/輸入範圍等**純文字**（見 §二 L）；
   - **I 字體/資源實際載入**：跑 `interact-resource-fonts`（或等效），以 `fonts.ready` + 網路層佐證新頁確實**下載**了目標字體，不只宣告字體名（見 §二 I）；
   - **J 導航完整性/flag 導流/登入落地**：跑 `interact-nav-integrity`（或等效），以**點側欄連結抵達**驗證 flag 導向與落地頁（見 §二 J）；
   - **K 視覺/computed style**：比對主要容器關鍵 computed style + 截圖留存供 agent 目視（字體載入紅燈一律以 I 為準，見 §二 K）。
   產出單一「舊頁有、新頁缺或變」的差異清單（涵蓋以上所有維度）。
4. **補齊缺漏**：對每條差異，要嘛在新頁補回（缺 label/提示/按鈕/連結/分頁/欄位/直接編輯能力/字體/導流/樣式），要嘛確認是「更合適的設計且經使用者同意」並記錄。差異清單清空為止。
5. **過閘覆核（補強，非上線依據本身）**：上線依據是第 2–4 步的對比已無缺漏（§0.1）；以下兩道只是**額外覆核**，確認對比做得紮實、補齊到位、程式碼無新問題——不可用它們替代對比：
   - 先派 **review agent**（讀腳本 + 讀相關前後端碼 + 讀差異清單）檢查方法論健全性、防假 PASS、清理是否確實、缺漏是否真的補齊，直到無嚴重 issue；
   - 再以 **codex**（`codex exec --dangerously-bypass-approvals-and-sandbox`，`Write-Output "<prompt>" | codex ...`，PowerShell 用 `powershell.exe`）覆核，直到無嚴重 issue。
6. **才翻 new**：第 2–4 步對比無缺漏、且第 5 步覆核無嚴重 issue 後，該頁 flag 才由 **AI agent 執行翻 new**（與 §0.1 一致）；重大頁面變更於翻 new 時一併告知使用者。未過一律維持 `old`。

### 6.1 其它
1. 互動寫入流程（A–H）一律用合成資料即用即刪，失敗路徑亦於 `finally` 清理（見 §一之 1.3）。
2. 全部頁面跑完後輸出一份合併報告（每頁差異清單、補齊結果、互動對比點、HTML/截圖存證、清理結果、最終 flag 狀態）。
3. 文檔本身（本計畫）之新增/重寫亦走 review 規範後才提交。

## 七、重跑
```powershell
# 前提：dev server 已起；npm run build；Playwright 已裝
node tests/e2e/login.mjs superadmin        # 刷新登入 state（session 會過期）

# 逐頁全量證據擷取（§6.0 第 2 步；只出證據、不判 PASS）：
node tests/e2e/compare-pages.mjs <pairLabel> <oldUrl> <newUrl> [role]
# → storage/app/test-artifacts/compare/<pairLabel>.json + <pairLabel>-old.png / -new.png
#   讀 JSON 後由 AI agent 逐項判讀 diff（即 AI agent，非真人；見 §0.3）。

# 互動流程腳本：interact-codes-crud.mjs / interact-codes-search.mjs /
#               interact-manage.mjs / interact-explainsql.mjs / smoke-new-only.mjs
# 報告與截圖輸出於 storage/app/test-artifacts/
```
