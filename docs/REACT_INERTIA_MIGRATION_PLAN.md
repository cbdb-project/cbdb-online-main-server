# 以 React + Inertia 重寫全站 AdminLTE 頁面：遷移計畫

> 狀態：**規劃草案（待人工審核）**　·　方向：**漸進式 strangler，逐頁取代，非一次性重寫**
> 本文件只描述「做什麼、為何、依何順序」，不含實作程式碼。每個階段落地時請遵循專案的「小環節 → review → codex → 推進」節奏。

> 📍 **狀態與接手指引（活頁，每次迭代更新）** —— 接手的 AI 從這裡開始：
> - **目前進度**：Phase 0（F1–F6）、**Phase 1（P1-1…P1-6）、Phase 2（P2-1…P2-5）全部完成**（commit 於 `feat/phase0-f1-tailwind-tokens` 分支，逐項過 review agent + codex gate；flag 一律預設 old，待人切換）。write-path（store/update/destroy/proposal）採 perform* 單一來源抽取，舊 Blade byte-equivalent。F7（複合主鍵 write-path）仍 `todo`，**只由人翻**，為 Phase 4 硬前置。完整頁面狀態見 [REACT_MIGRATION_BACKLOG.md](./REACT_MIGRATION_BACKLOG.md)。
> - **下一步**：Phase 3 — 人物列表與檢視（P3-1 basicinformation/index、P3-2 show）。
> - **重要**：Phase 4 硬前置 F7 為人類關卡；agent 完成 Phase 3、Phase 5 後，Phase 4 須等人翻 F7 才可開工（否則標 blocked 升級）。
> - **執行順序**：F1→F4→F5→F2→F3→F6（依賴調整，見附錄 D.1）。
> - **最近心得/坑**：見附錄 D。
> - **執行規則**：見附錄 C（自主執行協定）。

## 〇、本文件與既有文件的關係

專案已有一份 [docs/ADMINLTE4_UPGRADE_FEASIBILITY.md](./ADMINLTE4_UPGRADE_FEASIBILITY.md)，評估的是**另一條路**：保留 Blade，把 AdminLTE 3 / Bootstrap 4 就地升級到 AdminLTE 4 / Bootstrap 5（純 Blade，不涉及 Inertia/React）。

本文件評估的是使用者選定的方向：**用既有的 React + Inertia 技術棧，逐步取代所有 AdminLTE Blade 頁面**，最終下架 AdminLTE / jQuery / Bootstrap。兩份文件互斥，採用本方案即不執行 AdminLTE 4 就地升級。

| | AdminLTE 4 就地升級 | **本方案：React + Inertia 重寫** |
|---|---|---|
| 前端框架 | Blade + Bootstrap 5 | React（Inertia），逐步淘汰 Blade |
| jQuery | 仍保留（Select2/DataTables） | 最終移除 |
| deprecation 警告 | 部分仍在（BS5 自身、外掛） | **全部消失**（移除 admin-lte 後） |
| 風險型態 | 一次性大改、全站回歸 | 每頁可獨立發布、可回退 |
| 與專案現行方向 | 與 AGENTS.md「新功能只做 React/Inertia」相反 | **與之一致、是其自然延伸** |

> 採用本方案後，請更新 `ADMINLTE.md`、`AGENTS.md`，並在 `ADMINLTE4_UPGRADE_FEASIBILITY.md` 標註「已由 React 方案取代，不執行」。

---

## ★ 設計保真度原則（總綱，凌駕各階段）

現行介面是十餘年依使用者工作習慣慢慢迭代出來的成果，承載大量肌肉記憶。**遷移的預設目標是「像素級／行為級高保真復刻」，而非「做出功能相近的新版」。**

**「像素級」的定義（避免過度承諾）**：指**日常使用下視覺上難以分辨**——透過共用設計 token（間距、字級、色彩、圓角、focus ring）達成，**而非逐位元 CSS 對拍**。Phase 7 移除 AdminLTE/Bootstrap 後，重建的像素必然在子像素層級有差異；保真的可驗證底線是**行為 + 版面 + 視覺 token 對齊**，而非 byte-for-byte。嚴格視覺差異比對（Playwright/Percy snapshot）若要做，屬 §八的 E2E 工具決策（Phase 0 待評估）。

**預設（default）：高保真復刻**
- 不只是「類似功能」，而是**版面、互動流程、欄位順序、預設值、空/載入/錯誤狀態、既有快捷鍵與互動細節**都要對齊。
- 實測：應用程式碼中**顯式**鍵盤處理極少（主要是 `chgis-map` 的焦點陷阱/Esc 關閉、以及少數 React 對話框 Esc、SQL 編輯器 Ctrl/Cmd+Enter），故保真重點在**版面與工作流細節**（Tab 焦點順序、Enter 送出、欄位預設與驗證時機）。
- ⚠️ **但大量鍵盤/互動行為並不在應用程式碼裡，而是 Select2 / DataTables 等第三方內建**（type-to-search、排序焦點、鍵盤導覽、分頁），這些在 Phase 7 移除舊框架時會一併消失。因此 fidelity spec 必須**主動盤點第三方提供的互動**並在新元件補回，不能只讀手寫 handler——否則「既有快捷鍵/互動逐一保留」會悄悄破功。

**例外一：以新特性為主。** 若某組件或特性在新技術棧下「很不一樣」，或新方案明顯更佳（如 TanStack 表格互動、現代日期選擇器），則**採用新特性**，不強行復刻舊行為。

**例外二：修訂/合理化。** 若原設計本身不合理或有錯（不一致的互動、誤導的標籤、累贅流程），**可主動修訂或合理化**，不必盲目復刻錯誤。

**約束：在不帶來嚴重負擔的前提下。** pixel-perfect 不等於不計成本；當復刻成本過高而收益有限時，傾向例外一/二，並記錄取捨。

**落地機制：**
1. **保真規格（fidelity spec）**：每頁遷移前先做快照 —— 截圖 + 互動清單（含快捷鍵、焦點/Tab 順序、空/載入/錯誤狀態、欄位預設，**以及該頁仰賴的 Select2/DataTables 第三方互動**）。**此盤點本身是每頁/每階段工作量的一部分，需計入估時**，非免費。
2. **parity 比對**：遷移後由 review agent 對照保真規格逐項確認。比對底線為**行為 + 版面 + 視覺 token 對齊**（可由清單客觀判定 Tab 順序/Enter 送出/空·錯狀態等）；逐位元視覺比對需 snapshot 工具支援（§八，待評估）。
3. **偏離決策記錄**：每一處刻意偏離（採新特性 / 合理化原設計 / 因成本放棄復刻）登記理由，集中於本文件附錄或各 PR 描述。

**對決策的影響：** 此原則使 UI 元件庫選型（§二.2）**傾向「可完全掌控視覺」的方案**（Tailwind/headless 如 shadcn，或沿用 AdminLTE 的視覺 token），而非把自身視覺強加上來的 opinionated 套件（MUI/Mantine）—— 後者會讓像素級復刻變得困難。

---

## 一、現況基線（實測）

`resources/views/**` 共 **105 個 `.blade.php`**。三套渲染世界並存：

1. **AdminLTE 3 / Blade**（約 95%）：全部 `@extends('layouts.dashboard-v3')`。
2. **舊版 Bootstrap 3 Blade**：`layouts/app.blade.php`，目前**僅** `biogmains/basicinformation/show.blade.php` 仍在用。
3. **React / Inertia（已上線）**：Query Playground、Person Browser、Search-by-Entry、View Tables，皆走 `/app/*` 路由。

後端渲染比：`Inertia::render` 5 處（React，精確）對 `view()` 約 90 餘處（Blade，概估）。

### 1.1 既有 Inertia 基礎建設（地基已完整，但僅覆蓋「瀏覽/唯讀」）
- 入口 `resources/js/inertia/app.tsx`：`createInertiaApp` + `import.meta.glob('./Pages/**/*.tsx')` 自動解析頁面、`createRoot` 掛載。
- 根模板 `resources/views/inertia.blade.php`：`@vite('resources/js/inertia/app.tsx')` + `@inertia`，由 `HandleInertiaRequests::$rootView = 'inertia'` 指定。
- 共用 props：`HandleInertiaRequests::share()` 提供 `app.version`、`auth.user`（**目前僅 `id`/`name`，無角色/權限**）、`locale`、`locale_url`、`translations`（`common`/`nav`/`person`/`query`）。**頁面特定翻譯必須走獨立的 `page_translations` key**，否則被 inertia-laravel 淺合併覆蓋。
- `inertia` middleware 為**逐路由 opt-in**（`->middleware('inertia')`），非全域。
- i18n hook `hooks/useTranslation.ts`：`useTranslation(group)` 讀 `page_translations[group] ?? translations[group]`，支援 `:placeholder` 取代。
- 極簡 React 殼 `Layouts/AppShell.tsx`（header + footer + 語言切換 + dirty guard），**並非 AdminLTE 的移植**，沒有側邊欄/角色閘門。
- **SSR 關閉**（`config/inertia.php` → `ssr.enabled = false`），純客戶端渲染。

> ⚠️ **重要修正（勿誤解）**：既有 `PersonBrowser/` tab 套件（12 個 tab，與 biogmains/* 子資源一一對應）**全部為唯讀展示卡片**。新增/編輯/刪除一律委派給 `shared/Legacy{Create,Edit,Delete}Button`，這些按鈕只是 `<a href>` 或 POST 到**舊版 Blade 路由**；tab 內**沒有任何 React 表單、驗證、Select2 替代、年號/曆法轉換或複合主鍵提交邏輯**。
> 因此這些元件只加速 **Phase 3 的瀏覽/唯讀面**；**Phase 4 的 12 個編輯表單是全新（greenfield）開發，不是「接線」**。這是本計畫風險與工作量的最大來源，務必照此認知排程。

### 1.2 必須由 React 重建的「殼」職責
目前的殼能力散在 `layouts/dashboard-v3`、`header-v3`、`sidebar-v3`、`footer`：
- 側邊欄導覽樹 + **角色閘門**（always-on / `isActive()` / `isSuperAdmin()`）+ active-state（目前靠中文標籤字串比對）+ 待審提案 badge（內嵌即時 `Operation` 查詢）。
- 導覽列：pushmenu、breadcrumbs、**深色模式切換**（`localStorage['darkMode']`）、**語言切換表單**（含未存變更 dirty 檢查）、訪客登入/註冊、登入者下拉/登出。
- 內容區：flash 訊息（`laracasts/flash`，目前為伺服器端 Blade partial）、`$page_title` / `$page_description` 內容標頭、管理員 SQL profiler 模態框。
- 全站依賴 jQuery / Bootstrap 4 / AdminLTE / Select2 / DataTables（由 Vite 打包）。

### 1.3 i18n 現況
- 14 個翻譯群組（`zh-TW` 與 `en` 同步）：`admin/auth/biogmains/chgis_map/codes/common/nav/operations/pagination/passwords/person/query/validation/views`。
- Locale 解析：`SetLocaleMiddleware`，優先序 session → cookie → Accept-Language → 預設 `zh-TW`；切換 POST 至 `locale.switch`。

### 1.4 認證頁
- 套件 **`laravel/ui` ^4.5**（非 Breeze/Fortify）。`Auth::routes()` + `app/Http/Controllers/Auth/*`。
- 登入/註冊/密碼重設視圖皆為**獨立 HTML 文件**（不 extend layout）。`auth/register2.blade.php` 經查**無任何引用，為孤兒死碼**（遷移前可直接刪）。

---

## 二、目標架構決策

> 標 ⚠️ 者為**需使用者拍板**的決策，建議已附；其餘為依現況的合理預設。

1. **延續 Inertia + React，不另建獨立 SPA/API。** 後端 `Inertia::render('Area/Page', props)`，Laravel 路由/中介層/授權/驗證全部保留。
2. ✅ **UI 元件庫：已決定採 Tailwind + shadcn/ui（headless）。** 理由見保真度原則總綱——headless（Radix）+ 自有 class 可完全掌控視覺、最利於像素級復刻與沿用 AdminLTE 視覺 token，a11y 內建；代價是基元元件（表格/表單/Modal）需自建，於 Phase 0 一次立起。以下保留選型過程供查：
   - **(A) Mantine / MUI 等成熟元件庫**：表格、表單、Modal、日期選擇器、a11y 開箱即用，開發最快；代價是新依賴面與視覺風格綁定。
   - **(B) Tailwind + shadcn/ui（headless + 可控樣式）**：彈性高、體積可控；需自建較多元件。
   - **(C) 維持手寫 + 擴充 `theme.ts`**：依賴最少；表格/表單/可及性成本最高，**不建議**用於全站。
   - **建議：依保真度原則（總綱）傾向 (B) headless（Tailwind + shadcn/ui）或沿用 AdminLTE 視覺 token**，以保留像素級外觀控制；(A) opinionated 套件會把自身風格強加上來、與高保真復刻衝突，除非團隊願意接受視覺改版。搭配 **TanStack Table（headless）** 取代 DataTables 的排序/篩選/分頁（伺服器端分頁透過 Inertia partial reload）。**注意：DataTables 現有的匯出/列印按鈕 TanStack 無內建，需自建匯出（CSV/列印）以達功能對等。**
3. **殼（AppShell）升級為正式版（見第三節 Phase 0 與第五節雙殼說明）**：在既有 `AppShell.tsx` 之上重建側邊欄（角色閘門 + active-state 改由**結構化導覽資料 + 目前路由**判斷，不再靠中文字串比對）、導覽列、breadcrumbs、深色模式、語言切換、flash。
4. **授權資料下放**：`HandleInertiaRequests::share()` **目前只給 `id`/`name`**；Phase 0 必須增補 `auth.user.roles` / `can`（或每頁序列化 Gate/Policy 結果），React 側邊欄與頁面才能做閘門。**前端閘門只是 UX，後端每條 mutation 路由仍須獨立授權（AGENTS.md §5）。**
5. **複合主鍵子資源**：URL 沿用 `docs/COMPOSITE_PRIMARY_KEY_URL_DESIGN.md` 的 query-string pk 約定。**注意該文件記載 write-path 仍在收斂中**（texts/addresses/altname/entries 等仍走舊 path-based resource 路由、query-path 的 store/update/destroy 測試未齊）。**因此「複合主鍵 write-path 收斂 + 對應 Feature 測試」是 Phase 4 的硬前置**，否則 React 表單會蓋在未定型的寫入層上。
6. ⚠️ **認證頁**：建議**最後處理或暫時保留 Blade 版**（登入/註冊互動簡單、收益低）。若要 React 化，改為 Inertia 頁即可，`laravel/ui` 後端不必換。
7. **SSR 維持關閉**：本站為登入後的後台工具，首屏 SEO 非需求；維持 CSR，靠既有 per-page code-split 控制 bundle。
8. **退場條件**：所有頁面遷移完成後，移除 `admin-lte`、`jquery`、`bootstrap`、`datatables.net-bs4`、Select2 主題、`layouts/dashboard-v3` 全套與 `resources/js/app.js` 的 Vue 掛載。**此時先前的 bootstrap deprecation 警告才真正消失** —— 它們是本工程的副產品，不是驅動因素。

---

## 三、Inertia 表單與跨切面關注點（每頁都要處理，非細節）

這些是 Laravel→Inertia 遷移的核心機制，必須在 Phase 0 沉澱為共用樣板，並進入每頁檢查清單：

- **表單與驗證**：用 Inertia `useForm` 提交；Laravel `ValidationException`（422）會以 `errors` prop 回傳 React，需在欄位旁渲染。取代 Blade 的伺服器端 `@error`/`old()` 機制（受控 React input 不再有 `old()` 語意）。
- **CSRF**：Inertia 走 `XSRF-TOKEN` cookie 自動帶 header；與目前 `LegacyDeleteButton` 手動讀 `meta[name=csrf-token]` 的方式並存期間要避免混用造成 419。
- **Flash 訊息橋接**：`laracasts/flash` 目前是伺服器端 partial，React 殼中不存在；必須改為在 `share()` 暴露 `flash` prop（success/error），由 AppShell 統一渲染 toast/alert。
- **檔案上傳**：Phase 5 的 batch loader 為檔案驅動，Inertia 需 `forceFormData`/multipart。
- **資產版本/快取破壞**：Inertia `version()` 的 409 asset-refresh 流程要設好，否則移除 AdminLTE 資產的過程中舊客戶端可能拿到過期 bundle。
- **partial reload**：表格刷新/分頁/篩選一律用 Inertia `only:` partial reload，而非整頁重載。
- **歷史/捲動/dirty guard**：Inertia 處理 back/forward，但捲動還原與既有 `useDirtyGuard`（離開未存表單前攔截）互動需明確測試。
- **deep-link**：複合主鍵編輯頁以 query-string pk 當 Inertia props 進入，需可直接分享連結。
- **可及性（a11y）**：用 `window.confirm` 刪除、手寫 inline-style 元件不足以對齊 AdminLTE 的元件基線；元件庫決策（決策 2）應把 a11y 納入考量。

---

## 四、漸進式遷移階段

原則：**每頁兩條路由可並存**（Blade 舊路由 + `/app/*` 新路由），側邊欄逐步切換指向；每頁獨立發布、獨立回退；先低風險、高價值，後高風險。**T-shirt 尺寸為相對工作量概估**，凸顯 Phase 4 遠大於其餘。

### Phase 0 — 地基與規範（**L**；無使用者可見變更）
- 定案 UI 元件庫（決策 2）與表格方案（TanStack Table + 自建匯出）。
- 升級 `AppShell`：側邊欄/導覽列/角色閘門/active-state/深色模式/flash/breadcrumbs。
- `share()` 增補角色/權限（決策 4）與殼所需翻譯群組（至少 `nav`/`common`/`auth`/`validation` 常駐）。
- 沉澱第三節跨切面樣板：`useForm` 表單範式、422 錯誤渲染、flash toast、`DataTable`（含匯出）、`Modal`、`ConfirmDialog`、分頁、partial reload。
- 撰寫「頁面遷移檢查清單」（見第六節）。

### Phase 1 — 唯讀葉節點（**S**；低風險、建立信心）
- `dashboard/index`、`profile/edit`、管理日誌檢視（`admin/ai_fill_logs`、`admin/audit_logs`、`admin/explain_sql`）、`query_playground/nl_query_logs`。

### Phase 2 — Codes 代碼表 CRUD（**M**）
- `codes/{index,show,create,edit}` + `codes/proposal-edit`。**這是第一個真正的 CRUD 表單頁**，用來把 Phase 0 的表單/驗證/flash 樣板跑通並沉澱可複用樣板。

### Phase 3 — 人物列表與檢視（**M**；高流量）
- `biogmains/basicinformation/index`（實質首頁）與 `show`（順手淘汰最後一個 `layouts/app` 依賴）。
- **可複用既有 PersonBrowser 唯讀 tab 元件**（這是它們真正能加速的地方）。
- 注意：`show` 為唯讀，其編輯/子資源入口在 Phase 4 前仍指向 Blade；應明確設計成「read-only 與編輯器解耦」，避免 React→Blade→React 來回跳。

### Phase 4 — 人物編輯器與 12 個複合主鍵子資源（**XL**；工程主體、最高風險）
- `basicinformation/{create,edit}` + 12 子資源（altname/addresses/texts/sources/offices/assoc/kinship/events/statuses/entries/possession/socialinst）。
- **硬前置（決策 5 / C2）**：複合主鍵 write-path 收斂 + query-path store/update/destroy 的 Feature 測試齊備，再開始建 React 表單。
- **全新開發**：12 個雙語表單，含自動完成（Select2 替代）、年號/曆法轉換、複合主鍵（含 NULL 段）提交，逐子資源獨立 review + 回歸測試（AGENTS.md §2 高風險區）。**此階段工作量約等於 Phase 1–3 總和或更多。**

### Phase 5 — 管理與營運工具（**L**）
- `operations/index`（+ restore/提案核可）、`manage/{index,edit,merge-preview}`、`crowdsourcing/index`、3 個 batch loader（檔案上傳）、`wiki-maintenance`、`cbdb-table-maintenance`、`unidirectional-relationship-repair`、`maps/index`。
- 含**輪詢/非同步**頁面：改用 React 狀態 + Inertia partial reload 或既有 SSE/輪詢端點。

### Phase 6 — 認證頁與入口（**S**）
- `auth/{login,register,passwords/email,passwords/reset}`、`welcome`。刪除已確認的死碼 `home.blade.php`、`auth/register2.blade.php`（遷移前再次確認無引用）。

### Phase 7 — 下架 AdminLTE（**M**）
- 移除 `admin-lte`/`jquery`/`bootstrap`/`datatables.net-bs4`/Select2 主題、`layouts/dashboard-v3` 全套、`resources/js/app.js` 的 Vue 掛載與相關 jQuery 程式。
- 同步更新 `AGENTS.md`、`ADMINLTE.md`、`README.md`、`CHANGELOG.md`。

> **明確排除**：`cbdbapi/person.blade.php` 是 XML/資料回應樣板（`response()->view()`），非互動頁面，不在「頁面重寫」範圍。

---

## 五、過渡期的「雙殼」現實（重要）

兩套殼會**長期並存到 Phase 7**，必須正視其成本，不可當作「Phase 0 重建一次就好」：

- Blade 頁在 `dashboard-v3` 殼（AdminLTE 側邊欄、`localStorage` 深色模式、字串比對 active-state）；Inertia 頁在 `inertia.blade.php` + React `AppShell`。兩者是**不同根模板、不同 DOM、不同側邊欄**。使用者每點到一個尚未遷移的連結，就會在兩套殼之間切換。
- 因此 React 側邊欄從 Phase 0 起就必須**同時連回約 100 個 Blade 路由**，並複製整棵導覽樹、角色閘門、待審提案 badge —— 這些在整個遷移期要與 Blade 側邊欄**雙份維護、保持同步**（深色模式狀態、語言、badge 數字）。
- 緩解：把導覽樹、角色閘門規則抽成**單一資料來源**（如後端產生一份 nav schema，Blade 與 React 各自渲染），降低雙份漂移。
- 替代架構（若雙殼成本不可接受）：保持 Blade 殼為權威，於過渡期把 Inertia 頁**嵌入** Blade 殼內 —— 但這改變架構選型，須另行評估。

---

## 五之二、並存、切換與回退機制（routing + feature flag）

新舊頁**完全隔離**，可長期並存（現成證明：`ViewTables` 的 Blade 版 `view.index`/`view.show` 與 React 版 `app.view.index`/`app.view.show` 此刻同時上線）。

- **路由慣例**：新 React 頁建在平行路由（既有慣例 `/app/*`，加 `->middleware('inertia')`），**不動舊路由**。新舊不同根模板（`inertia.blade.php` vs `dashboard-v3`）、不同 DOM，無掛載衝突。
- **切換 = 導覽指向**：以**每頁 feature flag**（`config/migration_flags.php` 或 DB 設定）決定側邊欄/連結指向新或舊頁。flip 一個值即上線新頁，改回即回退，**不需改碼、不需重新部署**（若用 config 快取，回退僅需 `config:cache`）。
- **回退保證**：舊 Blade 視圖與路由在該頁穩定（建議 1–2 週實際使用）前**一律不刪**；新頁出問題，把 flag 切回舊頁即時恢復。
- **清理時機**：穩定後才刪舊視圖/路由，並從 flag 表移除該頁項目。Phase 7 才整體下架 AdminLTE。
- **注意**：切換期間新頁在 React 殼、舊頁在 AdminLTE 殼（§五雙殼）；導覽資料建議單一來源，避免兩套側邊欄漂移。

## 六、每頁遷移檢查清單（落地時逐項勾）
0. **保真規格**：遷移前先做該頁的 fidelity spec 快照（截圖 + 互動/快捷鍵/Tab 順序/空·載入·錯誤狀態清單，**以及該頁仰賴的 Select2 / DataTables 第三方內建互動：type-to-search、鍵盤導覽、排序焦點、分頁、匯出/列印入口等**）；遷移後對照逐項確認 parity；刻意偏離（採新特性／合理化原設計）登記理由。
1. 後端：`view()` → `Inertia::render('Area/Page', props)`，路由加 `->middleware('inertia')`，props 契約明確（型別、null 行為）。
2. 前端：`Pages/Area/Page.tsx`，套 `AppShell`，複用基元元件。
3. **表單**：`useForm` 提交；422 `errors` 逐欄渲染；flash 由 share 的 `flash` prop 顯示；檔案頁設 `forceFormData`。
4. i18n：所需群組以 `page_translations` 傳入；`zh-TW`/`en` 同步；無硬編碼中文。
5. **授權**：後端每條（含 mutation）路由獨立授權，不依賴前端隱藏；側邊欄角色閘門對齊 `share()` 的 roles/can。
6. **測試**：用 `assertInertia(fn (Assert $page) => $page->component(...)->has(...)->where(...))` 取代原本斷言 Blade view name 的測試；複合主鍵頁補 query-path store/update/destroy + NULL 段邊界的 Feature 測試。
7. **導覽註冊（必做、每頁的共用面編輯）**：在**導覽單一來源**登記該頁 + 在 `config/migration_flags.php` 新增該頁 flag（預設指舊頁）；確認 **Blade 與 React 兩側側邊欄都正確顯示**該頁、active-state 與 badge 正常。新舊路由可並存；切換指向由人翻 flag（agent 不自動切）。
8. 清理：確認無引用後刪除對應 Blade 視圖與其 partial。
9. `npm run build` 通過、`./vendor/bin/phpunit` 受影響範圍綠燈。

---

## 七、風險登記
| 風險 | 說明 | 緩解 |
|---|---|---|
| **Phase 4 為全新開發（非接線）** | PersonBrowser tab 僅唯讀；12 編輯表單從零做 | 照 XL 排程；逐子資源 review + 回歸；先沉澱表單樣板於 Phase 2 |
| 複合主鍵 write-path 未收斂 | texts/addresses/altname/entries 仍走舊路由、測試未齊 | 列為 Phase 4 硬前置；先完成收斂 + Feature 測試 |
| 雙殼維護 | Blade 殼與 React 殼並存到 Phase 7 | nav schema 單一來源；明確接受雙份維護成本 |
| Inertia 表單機制 | 驗證/CSRF/flash/上傳/old() | Phase 0 沉澱共用樣板；進每頁檢查清單 |
| 授權下放 | share() 目前無 roles；前端閘門非授權 | Phase 0 增補 roles/can；後端逐路由授權 |
| DataTables 功能落差 | 排序/篩選/**匯出/列印**/分頁 | Phase 0 `DataTable`（TanStack）+ 自建匯出 + 伺服器端分頁 |
| 測試斷言改寫 | 多數 Feature 測試斷言 Blade view name | 每頁同批改用 `assertInertia`；補 E2E（Playwright/Cypress，待評估） |
| 角色閘門/active-state 回歸 | 目前靠中文字串比對 | 改結構化導覽資料 + 路由判斷 |
| 深色模式 / a11y | localStorage + 自訂 CSS；window.confirm | 殼層以 React 狀態 + CSS 變數重建；元件庫含 a11y |
| bundle 體積（無 SSR） | 頁面增多 | 維持 per-page code-split；必要時路由層 lazy |
| 保真度回歸 | 復刻遺漏既有互動/快捷鍵/版面細節，破壞肌肉記憶 | 每頁 fidelity spec 快照 + review parity 比對；**不得只檢查手寫 keyboard handler，需盤點 Select2/DataTables 等第三方內建互動**；偏離須登記理由（總綱原則） |
| 凍結期風險 | 大改若久不合併 | **嚴格逐頁發布**，禁止長壽大分支 |

## 八、測試與回退
- 後端：`assertInertia` 斷言元件/props；複合主鍵頁補 query-path mutation + NULL 段邊界測試。
- 前端：建議引入 E2E（Playwright/Cypress）覆蓋關鍵流程（人物編輯、提案核可），目前專案無此設施，列為 Phase 0 待評估項。
- 每階段：`npm run build` + `./vendor/bin/phpunit`（受影響）+ 對應頁面 smoke（沿用 `ADMINLTE.md` 既有 smoke 清單路徑）。
- 回退：新舊路由並存期間，側邊欄指回 Blade 舊路由即可即時回退；Blade 視圖在該頁穩定前不刪除。

## 九、為何不一次性重寫
105 視圖、~30 頁面組、複合主鍵高風險區、大量資料表格，且 Phase 4 的 12 個編輯表單為全新開發 —— 一次性重寫＝多人週凍結期 + 全站回歸風險。漸進式 strangler 讓每步可發布、可回退、可測試，且與專案「每個小環節 review + codex」節奏一致；專案已走在這條路上（5 個 Inertia 頁面 + 完整 PersonBrowser 唯讀元件庫）。

---

## 附錄 A、Phase 0 地基任務（具體，先於/伴隨試點頁）

> 慣例：第一個試點頁會把這些樣板一次立起來，之後的頁套用即可。

1. **建置工具鏈**：導入 Tailwind（`tailwind.config` + PostCSS，掃描 `resources/js/inertia/**`）；安裝 shadcn/ui（Radix 基元）。把 AdminLTE 的視覺 token（主色、間距、字級、圓角、focus ring）抽進 Tailwind theme，作為保真錨點。
2. **`AppShell` 正式化**：側邊欄（角色閘門 + active-state 由**結構化導覽資料 + 目前路由**判斷，不靠中文字串）、導覽列、breadcrumbs、深色模式（React 狀態 + CSS 變數，沿用 `localStorage['darkMode']` 鍵以相容）、語言切換（既有）、flash。
3. **共用基元**（shadcn 之上）：`DataTable`（TanStack Table，支援伺服器端分頁/排序/篩選、URL 同步、CSV/列印匯出）、表單欄位、`Modal`、`ConfirmDialog`、分頁列。
4. **後端共用**：`HandleInertiaRequests::share()` 增補 `auth.user.roles`/`can` 與 `flash`（橋接 `laracasts/flash`）；殼所需翻譯群組（`nav`/`common`/`auth`/`validation`）常駐。
5. **並存設施**：建立 feature-flag 機制（§五之二）與導覽單一來源。
6. **測試範式**：`assertInertia` 樣板；評估 E2E（Playwright）。

## 附錄 B、試點頁規格：`admin/audit-logs`（唯讀，低風險）

選它因為：唯讀但完整覆蓋**伺服器端分頁 + 多重篩選 + URL 狀態 + 授權**，正好立起 `DataTable` 樣板。

- **現況（實測 `AdminAuditLogController@index`）**：路由 `admin/audit-logs`（name `admin.audit-logs`）；授權 `Auth::user()->canViewAuditLogs()`（非活躍管理員 403）；`audit_log` 表不存在則 404；篩選 `search`/`table_name`/`operation`/`actor_type`/`actor_id` + `history_context`（檢視某人物歷史時）；`paginate(20)->withQueryString()`；另回傳 distinct `table_names`/`actor_types` 當篩選選項。
- **新頁步驟**：
  1. 後端：新增 `app.admin.audit-logs`（`->middleware('inertia')`），沿用同一查詢/授權邏輯，改回 `Inertia::render('Admin/AuditLogs/Index', [...])`，props 含 `logs`（分頁結構）、篩選選項、目前 filters、`history_context`、`page_translations('admin')`。**後端授權 `canViewAuditLogs()` 必須保留**（前端閘門僅 UX）。
  2. 前端：`Pages/Admin/AuditLogs/Index.tsx`，套 `AppShell` + `DataTable`；篩選列（關鍵字/表/操作/actor）對齊舊頁欄位與順序；分頁與篩選走 Inertia `only:` partial reload + URL 同步（保留 `withQueryString` 的可分享連結語意）。
  3. **fidelity spec**：對照舊頁截圖與互動（篩選欄順序、Enter 送出、分頁、空/載入狀態），含 DataTables 既有互動的盤點與取捨。
  4. 測試：`assertInertia` 斷言 component/props + 授權（403/404）案例。
  5. 切換：feature flag 預設仍指舊頁；驗證無誤後 flip 指向新頁；保留舊頁可回退。

---

## 附錄 C、自主執行協定（給 24/7 接手的 AI）

> 設計哲學：**「怎麼做」凍結、「做什麼」列全、「每個怎麼做」延後生成。** 標準穩定、scope 完整、細節 just-in-time。執行對象的單一真實來源是 [REACT_MIGRATION_BACKLOG.md](./REACT_MIGRATION_BACKLOG.md)。

**前置（交接前必須先由人完成並合併）：**
- Phase 0 地基（附錄 A）已實作合併。
- 試點頁 `admin/audit-logs`（附錄 B）已實作、過 gate、合併，作為**參考實作**（agent 複製真實樣板，不只讀散文）。

**真實來源（truth sources）：** 頁面狀態以 backlog 為準；**但 agent 對「自己的進度」要以 git 分支 + PR + CI 狀態為硬真實**（帳本可能與現實不符，見步驟 0）；設計文件 = 凍結標準 + 活頁記錄（見下「強制記錄」）。

**並發模型（不變量）：** **同一時間只有一個 executor、嚴格序列、一次一頁**，禁止平行迭代搶同一帳本。認領格式：`in-progress (iter-id, 起 ISO8601)`。

**單頁執行迴圈（每次迭代一頁）：**
0. **同步 + 開機對帳（resume）**：**先 `git fetch --prune origin` 並刷新 PR/CI 狀態（失敗即視為基礎設施故障 → `blocked` 停機，勿用過期本地 refs 對帳）**；再掃 backlog 中所有 `in-progress`/`in-review`，對照（已刷新的）git 分支/開啟中的 PR/CI：能續則續、已完成則補標、無 PR 且逾 N 小時的孤兒 `in-progress` 標回 `todo` 或 `blocked`。**帳本與 git 不一致時以 git 為準。**
1. 依「依賴就緒 → phase 順序 → **表內由上而下嚴格順序**」挑下一個 `todo`，標 `in-progress (iter-id, time)`。**若無可挑（全 blocked/done）→ 乾淨停機並回報，勿空轉。**
2. 確認前置已滿足（F1–F5 皆 `done`；Phase 4 項需 backlog 的「write-path 前置」gate 為 `done`，該 gate 只由人翻）；不滿足則標 `blocked` 並升級。
3. **just-in-time 細化**：讀該頁 controller / view / 路由 / 既有測試，產出該頁 fidelity spec（含 Select2/DataTables 第三方互動盤點）+ props 契約。**fidelity spec 存於該 PR 分支（PR 描述或 `docs/migration-specs/<page>.md`），不寫進設計文件。**
4. **實作**：後端 `Inertia::render` + 平行路由 + `->middleware('inertia')`；前端 `Pages/*.tsx` 套 `AppShell` + 基元；逐項套 §六檢查清單（**含必做的導覽單一來源 + flag 註冊步驟**）。
5. **驗收 gate（不可跳過、不可放寬）**：
   a. 派一組 review agent（讀碼 + 讀 diff + 對照 fidelity spec）→ 修到**無嚴重 issue**。
   b. 呼叫 codex（terminal，非 agent）審查 → 修到**無嚴重 issue**。
   c. `npm run build` + 受影響範圍 `./vendor/bin/phpunit` 綠燈。
   d. **review/codex 給出的嚴重度判定為權威，executor 不得自行下修**；嚴重度標準見下「嚴重度判準」。parity 檢查清單須附在 PR 作為證據（不可只口頭聲稱）。
6. **合併模型**：開 PR → `git fetch origin` → **rebase 到最新 `origin/develop` → rebase 後重跑 gate（5a–c）→ 合併前再確認分支仍基於當下最新 `origin/develop` SHA；若 `origin/develop` 已前進則重新 rebase 並重跑 gate，不得直接 merge** → 再合併（逐頁短分支、禁長壽分支）。**feature flag 預設仍指舊頁**（agent 不自動切換上線）。共用面（導覽單一來源 / `share()` / `config/migration_flags.php` / 翻譯檔）有非顯然衝突 → 標 `blocked` 升級。
7. **強制記錄**（每段完成都要做）：① backlog 標 `done` + PR 連結 + fidelity 偏離理由；② 把本頁踩到的坑/可複用心得寫入設計文件附錄 D「經驗與踩坑」；③ 若實作過程發現計畫需修訂（且經人核可），更新對應段落並記到附錄 D「決策變更記錄」；④ 更新頂部「狀態與接手指引」指標。回到 0/1。

**嚴重度判準（防止自評放水）：** 嚴重（blocking，必須修）= 行為/版面 parity 破壞、授權缺口、資料寫入錯誤、複合主鍵錯置、i18n 缺漏、建置/測試紅燈。輕微（可記錄後過）= 視覺 token 細微偏差、非關鍵文案。**executor 不得把嚴重降級為輕微來放行**；review/codex 的判定為準。

**升級/停止規則（遇到即停、標 `blocked`、交人決定，不得硬推）：**
*內容/決策類：*
- 命中文件標 ⚠️ 的未定決策；fidelity 取捨有歧義（無法判定該復刻、採新、或合理化）。
- 複合主鍵 write-path 前置 gate 未 `done` 卻排到 Phase 4 項。
*操作類（unattended 必備）：*
- **基礎設施紅燈**：build/test/codex/review-agent/GitHub 因環境/網路/工具（非本 diff）而失敗 → **絕不可改動頁面去迎合壞掉的檢查**，標 `blocked` 升級。區分「diff 造成的紅」與「infra 紅」。
- **合併衝突**：共用面（導覽單一來源 / `share()` / `migration_flags` / 翻譯檔）非顯然衝突 → `blocked`。
- **品質卡關**：同一頁累計 3 輪 gate 仍有嚴重 issue（計數**單調遞增、不因表面修補重置**）→ `blocked`。
- **全域預算護欄**：單次 run 超過上限（PR 數 / 迭代數 / wall-clock）→ 停機回報。
- **無可挑項**：全部 `todo` 皆 `blocked` → 停機回報。
- **參考實作失效**：參考頁（audit-logs/AppShell）已無法通過現行 gate/lint → 先升級，勿沿用過時樣板。

**不變量 — agent 的寫入允許/禁止清單（依動作，非依媒介）：**
- ✅ 允許：建立新 React 頁/路由、共用基元、`Pages/*.tsx`、對應測試；更新 backlog 帳本；更新設計文件的**活頁區**（頂部狀態指標、附錄 D 經驗與決策記錄）；開/rebase/合併**自己這頁**的 PR。
- ⛔ 禁止（必由人）：**切換 feature flag 上線**；**刪除任何舊 Blade 頁/路由/套件**（含 P6-C1/C2 死碼清理與 Phase 7 全套下架）；修改設計文件的**凍結區**（保真度原則/recipe/驗收 gate/白名單/各 phase 策略）；放寬 SQL 白名單或任何安全邊界；翻 write-path 前置 gate。
- 建議以 CI/hook 把上述禁止項做成**基礎設施強制**（凡 PR 改到 `migration_flags.php`、刪 `resources/views/**`、或動設計文件凍結區即 fail），不靠自律。

**交接狀態機（agent 不可跨越人類關卡）：** `todo → in-progress → in-review → done（已合併、flag 仍指舊頁）` ⟶ **【人】翻 flag** ⟶ `live（觀察 1–2 週）` ⟶ **【人】退役** ⟶ `retired`。agent 的權責止於 `done`。

---

## 附錄 D、決策變更記錄 與 經驗踩坑（活頁，agent 必須持續累積）

> 此區為**活頁**，agent 可（且必須）更新；其餘設計文件章節為**凍結區**，改動需人類核可。目的：讓接手者立即知道「為何這樣做」與「別再踩同一個坑」。

### D.1 決策變更記錄（implementation 推翻/修訂計畫時記這裡）
| 日期 | 變更 | 原因 | 核可人 |
|---|---|---|---|
| （示例）2026-06-18 | UI 元件庫鎖定 Tailwind+shadcn | 保真度原則：需可完全掌控視覺 | 使用者 |
| 2026-06-18 | Phase 0 執行順序調整為 F1→F4→F5→F2→F3→F6（非表序 F1..F6） | 依賴：F2(AppShell 側邊欄) 消費 F4(roles) 與 F5(nav schema)，故後端基礎先行 | 使用者（/goal 授權自走） |
| 2026-06-18 | F5「導覽單一來源」採「真正單一來源」：Blade sidebar 與 React 共用 App\Support\Navigation；Blade 改以遞迴 partial 渲染 schema | 降低雙殼期側邊欄漂移（§五緩解）；active-state 仍沿用既有 $page_title 字串以相容未遷移頁面，僅 React 端改用 route pattern | 使用者（/goal 授權自走） |
| 2026-06-18 | F2：正式殼建為**新元件 DashboardLayout**，不改既有精簡 AppShell（5 個已上線頁續用） | 避免對線上工具造成非預期版面變動；新遷移頁改用 DashboardLayout，舊頁日後折入 | 使用者（/goal 授權自走） |
| 2026-06-18 | nav 的 label 由 Navigation 於後端以 __() 解析為顯示字串（Blade 與 React 直接輸出，不再前端翻譯） | codes/views/admin 翻譯群組未在 shared translations；伺服器解析最簡且 locale 正確（切換為伺服器往返） | 使用者（/goal 授權自走） |
| 2026-06-18 | React 側邊欄 active 改以 **href 路徑比對**（精確+祖先前綴），非 route 名稱 glob | React 無 Laravel route 名稱對照表，無法評估 active.patterns；patterns 仍供 Blade routeIs 使用 | 使用者（/goal 授權自走） |
| 2026-06-18 | F6：assertInertia 範式沿用既有（已 5 檔使用）；新增 share() 契約測試守護 roles/can/flash/nav/shell。**Playwright E2E 延後**至 Phase 3/4（首個複雜互動流＝人物編輯器）再導入 | Phase 0–2 為唯讀/簡單 CRUD，後端 assertInertia + parity review 已足；先不增 E2E 基礎設施負擔 | 使用者（/goal 授權自走） |
| 2026-06-18 | P5-12 maps/index 列為 shell 遷移範圍外 | 獨立全螢幕 Leaflet 地圖應用（自有 entry），非 AdminLTE dashboard 頁；包進 DashboardLayout 破壞 UX。已在 /app/maps、superadmin。 | 使用者（/goal 授權自走） |
| 2026-06-18 | **P3-2 標 blocked，待人決策**：`basicinformation/show` 路由實際 render `edit.blade.php`（533 行編輯器）的 readonly 模式；`show.blade.php`（唯一 layouts.app 使用者）為孤兒死碼（無 controller render）。忠實復刻 P3-2 = 建 Phase 4 編輯器的唯讀 React 版（受 F7 硬前置）。**需人類拍板**：(A) 等 F7／Phase 4 一併做編輯器唯讀版；或 (B) 核可以 PersonBrowser 風格的解耦唯讀視圖「合理化」取代（plan §四 Phase 3 建議方向），並確認 show.blade.php 可刪。 | 待人類 | — |

### D.2 經驗與踩坑（每頁完成後沉澱可複用心得）
| 日期 | 頁面/範圍 | 坑 / 心得 | 後續頁如何套用 |
|---|---|---|---|
| （尚無，待第一頁實作後填入） | | | |

---

**文件版本**：草案 v0.7（v0.6 + codex：對帳/合併前強制 `git fetch --prune origin` 與 `origin/develop` SHA 再確認，避免過期 refs 漂移）　·　**狀態**：待人工審核
