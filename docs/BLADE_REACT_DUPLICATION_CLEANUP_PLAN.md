# Blade / React 重複實作清查與下架計畫

> 建立日期：2026-09-14　·　狀態：**計畫（尚未執行）**
>
> 本文件回答三件事：
> 1. **哪些功能目前是 Blade 與 React 兩套並存的重複實作**（可清除）；
> 2. **哪些頁面／功能仍然只有 Blade**（不可清除，需評估取代的可能性與難度）；
> 3. **每一項的「清除方式」**——要刪哪些檔案、改哪些路由 / config / `.env` / `.env.example` / 文檔 / 測試 / 翻譯 / 前端資產 / npm 套件。
>
> 前置脈絡：[docs/REACT_INERTIA_MIGRATION_PLAN.md](./REACT_INERTIA_MIGRATION_PLAN.md)（遷移主計畫，本文件即其 **Phase 7 + P6-C1/C2** 的落地展開）、
> [docs/REACT_MIGRATION_BACKLOG.md](./REACT_MIGRATION_BACKLOG.md)（頁面帳本）、[docs/ADMINLTE.md](./ADMINLTE.md)（AdminLTE 現況）。

---

## 〇、TL;DR

- 全站 `config/migration_flags.php` 的頁面 flag **已全部為 `new`**，React/Inertia 是線上預設，已穩定運行一段時間。
- `resources/views/` 仍有 **105 個 blade 檔、約 16,809 行**；其中 **94 檔 / ~14,650 行**屬「與 React 重複」或「死碼」，可下架（**91 檔 A 類 + 3 檔 B 類死碼**，與 §七 統計表一致）。
- **但只有「人物編輯」那一段真的被閘門擋住**（`LegacyBladeFormGate`）。`codes`、`operations`、`manage`、`view`、`dashboard`、`profile`、`crowdsourcing`、`admin/*`、`merge-preview`、`auth/*`、`welcome` 的**舊 Blade 路由目前仍可直接用 URL 打到並正常渲染**——它們是真正「雙份維護中」的重複實作，也是本計畫的主體。
- 仍**只有 Blade、沒有 React 版**的只有 3 類：`maps/index`（歷史地圖殼）、`cbdbapi/person`（v1 API 回應樣板）、`inertia.blade.php` + `biogmains/_chgis_map_assets`（React 根模板與它 `@include` 的 partial）。另有 `saveas`／`Duplicate_Collateral_Info` 兩條 legacy 路由——**它們不是「React 缺的功能」，而是 React 現在正在呼叫的端點**（見 D-5a）。
- 🔴 **八個最容易踩的雷**（review agent × 2 ＋ codex，皆實測確認，細節見 §五）：
  1. `inertia.blade.php` 與 `operations/index.blade.php` 都 `@include` 了 `biogmains/` 底下的 partial，跟著目錄整批刪會 **500**；
  2. `_chgis_map_assets` 裡有 `route('basicinformation.index')`，**每個 React 頁都會執行**——該路由名與 URI 在整個計畫期間**凍結**；
  3. `codes/{table}/export` 是 React 匯出鈕的端點，**不能**跟著 `/codes/*` 一起導向；
  4. 刪 `basicinformation.*` flag key 會讓 `PersonBrowserController` 的 12 個 `*EditorIsNew` prop 全變 `false` ⇒ **13 個 React 編輯器靜默退回唯讀／退回指向 404 的 legacy 按鈕**，無 500、測試不會紅；
  5. 用 `Route::redirect`（= `any()`）封 codes 寫入端 ⇒ POST 被 redirect 降級成 GET、**body 靜默丟失**。環節 3 一律「顯示頁 GET→302、legacy 寫入端→410」；
  6. `login`／`register` 的**路由層不可碰**——`route('login')` 被 `Handler.php` 與 `Authenticate` middleware 依賴，名字一掉＝全站未登入請求 500；
  7. **有一批「長得像 legacy、其實是 React 正在用的 action endpoint」**：`crowdsourcing/{id}/confirm｜reject`（**GET 動詞的寫入端**，React 直接 `<a href>`）、`operations/{op}/restore｜approve｜reject｜cancel`、`codes.proposals.cancel`。按 prefix 套封路規則會直接命中它們 ⇒ **環節 3 必須逐條列 route manifest**；
  8. **觀察期不要用 301**——301 會被瀏覽器／CDN 長期快取，`git revert` 只還原伺服器，「完全可逆」在 301 之下不成立。觀察期用 302，確定永久下架才升 301；
  9. 🔴 **最隱蔽的一類**：有一批 legacy URL 是**由 PHP controller 組進 Inertia payload**、React 只是 `<a href={row.urls.x}>`——grep `resources/js` **完全抓不到**。已知 4 組（見 §三之二），其中 `OperationsController.php:1104` 的 `route('codes.proposals.edit')` **無 `Route::has()` 保護，刪路由＝`/app/operations` 整頁 500**。
- 清除的核心取捨：一旦刪除舊 Blade，`migration_flags` 承諾的「翻回 `old` 即時回退」就**永久消失**。因此本計畫採**兩段式**：先封路（可逆）→ 觀察 → 再刪碼（不可逆）。

---

## 一、盤點方法與證據

| 問題 | 查法 | 結果 |
|---|---|---|
| flag 現況 | 讀 `config/migration_flags.php` | 所有頁面 flag = `new`（含 `basicinformation.*` 全套） |
| Blade 檔清單 | `find resources/views -name '*.blade.php'` | 105 檔 / 16,809 行 |
| 哪些 Blade 真的被渲染 | `grep -rhoE "view\('...'"` over `app/ routes/ resources/views` | 見 §二；未出現且無 `@include` / `<x-...>` 者＝死碼候選 |
| 新舊路由對應 | `routes/web.php` 中 `xxx` 與 `app/xxx` 的成對路由 | 見 §二 A 表 |
| 控制器雙份實作 | `grep -rl "public function app[A-Z]" app/Http/Controllers/` | 22 個 controller 有 `app*` 變體 |
| 閘門覆蓋範圍 | 讀 `app/Http/Middleware/LegacyBladeFormGate.php` 與路由上的 `legacy.form:*` | **只掛在 `basicinformation` 人物層 + 12 個子資源 + proposal**；其餘 legacy 路由**無閘門**。⚠️ 人物層的 `destroy` 被閘門**明確放行**（`LegacyBladeFormGate.php:74`） |
| React 頁清單 | `resources/js/inertia/Pages/**`（注意目錄是大寫 `Pages`） | 65 個 `.tsx`（含表單子元件） |

> ⚠️ **跨命名空間 `@include` 是本次清理最大的陷阱**：`operations/index.blade.php` `@include('biogmains.defense')`、`inertia.blade.php` `@include('biogmains._chgis_map_assets')`、`components/diff-table.blade.php` `@include('components.posted-to-addr-diff')`。
> **每刪一個 blade 檔前，一律先跑 `grep -rn "<視圖點號名稱>" resources/views app/`，不要只看同目錄。**

**已逐項查證、確認無需改動的位置**（下一位 reviewer 不必重查）：`app/View/Components/**`（不存在）、`resources/views/vendor/**`（不存在）、`Blade::directive`／`Blade::component`（全庫零命中）、`app/Notifications`／`app/Mail`（不存在；密碼重設走框架內建 notification，不含專案視圖）、`database/seeders/`（只有 `DatabaseSeeder.php`）、`app/Console/Commands/**`（零 `route(`／零 `view(`）、`config/**`（除 `migration_flags.php` 外零路由名／視圖名引用）、`app/Exceptions/Handler.php`（只有 `route('login')`，見環節 3）、`.gitignore`／CodeQL／cs-fixer workflow（與 Blade/AdminLTE 無耦合）。

**盤點原則**：只依「程式碼可證的引用」判定死碼（`view()` 呼叫、`@include`、`<x-...>` 標籤、路由定義、測試引用），不依記憶或既有文檔陳述。執行每個環節時**必須重跑一次對應 grep**，因為本文件是快照。

---

## 二、分類台帳

分四類：

- **A — 重複實作，可下架**（React 版已上線且為預設，Blade 版僅作回退）
- **B — 死碼**（完全無引用）
- **C — 共用／非重複**（React 也依賴，或本來就不是互動頁）→ **不可刪**
- **D — 僅 Blade、無 React 版** → 不可刪，需評估取代

### A. 重複實作（可下架）

| # | 功能 | flag key | Blade 視圖 | Legacy 路由 → controller 方法 | React 對應 | 舊 URL 目前仍可直接打到？ |
|---|---|---|---|---|---|---|
| A-1 | Dashboard | `dashboard` | `dashboard/index` | `GET dashboard` → `DashboardController@index` | `app.dashboard` / `Dashboard/Index` | ✅ 是 |
| A-2 | 個人資料 | `profile` | `profile/edit`（897 行） | `GET/PATCH profile` → `UserProfileController@edit/@update` | `app.profile.edit` / `Profile/Edit` | ✅ 是 |
| A-3 | 代碼表全套 | `codes` | `codes/{index,show,create,edit,proposal-edit}`（1,017 行） | `codes.*` 共 13 條 → `CodesController@{index,show,create,edit,update,store,destroy,proposalStore,proposalEdit,proposalUpdate}`（**可刪 10 條**）；另 3 條**不可刪**：`export`（React 匯出端點）、`proposals.update`／`proposals.cancel`（新舊共用同一方法） | `app.codes.*` / `Codes/*` | ✅ 是（**含寫入端**） |
| A-4 | 操作紀錄 | `operations` | `operations/index`（752 行） | `GET operations` → `OperationsController@index` | `app.operations.index` / `Admin/Operations/Index` | ✅ 是 |
| A-5 | 使用者管理 | `manage` | `manage/{index,edit,_role-descriptions}` | `Route::resource('manage')` → `ManagementController@{index,edit,update,…}` | `app.manage.*` / `Admin/Manage/*` | ✅ 是 |
| A-6 | 合併預覽 | `merge-preview` | `manage/merge-preview` | `GET/POST merge-preview` → `MergePreviewController@index` | `app.merge-preview.index` | ✅ 是 |
| A-7 | 眾包審核 | `crowdsourcing` | `crowdsourcing/index` | `GET crowdsourcing` → `CrowdsourcingController@index` | `app.crowdsourcing.index` | ✅ 是 |
| A-8 | 檢視表 | `view` | `view/{index,list}` | `GET view`、`GET view/{key}` → `ViewTableController@index/@show` | `app.view.index/show` / `ViewTables/*` | ✅ 是 |
| A-9 | 稽核日誌 | `admin.audit-logs` | `admin/audit_logs/index` | `GET admin/audit-logs` → `AdminAuditLogController@index` | `app.admin.audit-logs` | ✅ 是 |
| A-10 | AI 填充日誌 | `admin.ai-fill-logs` | `admin/ai_fill_logs/index` | `GET admin/ai-fill-logs` → `AiFillLogController@index` | `app.admin.ai-fill-logs` | ✅ 是 |
| A-11 | EXPLAIN SQL | `admin.explain-sql` | `admin/explain_sql` | `GET/POST admin/explainsql` → `AdminExplainSqlController@show/@explain` | `app.admin.explainsql` | ✅ 是 |
| A-12 | 批次匯入書名 | `admin.batch-load-book-titles` | `admin/batch_load_book_titles` | `GET admin/batch-load-book-titles` → `@showForm` | `app.admin.batch-load-book-titles` | ✅ 是 |
| A-13 | 批次匯入官職 | `admin.batch-load-offices` | `admin/batch_load_offices` | `GET admin/batch-load-offices` → `@showForm` | `app.admin.batch-load-offices` | ✅ 是 |
| A-14 | 批次匯入社會機構 | `admin.batch-load-social-institutes` | `admin/batch_load_social_institutes` | `GET admin/batch-load-social-institutes` → `@showForm` | `app.admin.batch-load-social-institutes` | ✅ 是 |
| A-15 | CBDB 表維護 | `admin.cbdb-table-maintenance` | `admin/cbdb-table-maintenance` | `GET admin/cbdb-table-maintenance` → `@index` | `app.admin.cbdb-table-maintenance` | ✅ 是 |
| A-16 | 單向關係修復 | `admin.unidirectional-relationship-repair` | `admin/unidirectional-relationship-repair` | `GET admin/unidirectional-relationship-repair` → `@index` | `app.admin.unidirectional-relationship-repair` | ✅ 是 |
| A-17 | NL 查詢日誌 | `query-playground.nl-query-logs` | `query_playground/nl_query_logs` | `GET query-playground/nl-query-logs` → `@nlQueryLogs` | `app.query-playground.nl-query-logs` | ✅ 是 |
| A-18 | 認證頁（登入／註冊／忘記／重設） | `auth.login`、`auth.register`、`auth.passwords` | `auth/{login,register,passwords/email,passwords/reset}` | `Auth::routes()` → 4 個 Auth controller 的 `showXxxForm` | `Auth/{Login,Register,ForgotPassword,ResetPassword}` | ✅ 是（由 controller 內 flag 分歧） |
| A-19 | 入口頁 | `welcome` | `welcome` | `GET /` → `WelcomeController@index` | `Welcome` | ✅ 是 |
| A-20 | **人物編輯全套**（basicinformation + 12 子資源） | `basicinformation.*`（**15 個 key**） | `biogmains/**`（58 檔 / 6,810 行） | `Route::resource('basicinformation')` + 12 組子資源 resource + query-path `edit/update/destroy` + `proposalStore/proposalUpdate` | `app.basicinformation.*` / `BasicInformation/*` | ❌ 否（**`destroy`、`saveas`、`Duplicate_Collateral_Info` 除外**，見下） |

> **A-20 的特殊性**：這 58 個 blade 檔在 flag=`new` 下**已經不可達**（`LegacyBladeFormGate`：GET 302 導向 `/app`、寫入 410 Gone）。刪除**視圖**的線上行為風險趨近於零，是最安全的第一刀。
>
> ⚠️ **但路由層有三個缺口**：`LegacyBladeFormGate::handlePerson()` 對 `destroy` 以外的動作才套 flag，`default => null // destroy 等：放行`（`LegacyBladeFormGate.php:70-75`），而 `Route::resource('basicinformation')` 未 `except(['destroy'])` → **`DELETE /basicinformation/{id}` 直通 controller**；`saveas`、`Duplicate_Collateral_Info`（`routes/web.php:161-162`）**完全無 middleware**。這三條屬 D-5，**本計畫不刪**。
>
> **A-1…A-19 的特殊性**：這些**沒有閘門**，舊頁至今仍會正常渲染（包含 `codes` 的寫入端）。若有使用者書籤或外部連結指向舊 URL，刪除前**必須先補導向**（觀察期 302、永久下架後才升 301）。

#### A 連帶的 controller legacy 方法

| Controller | 可刪的 legacy 方法 | 必須保留 / 注意 |
|---|---|---|
| `DashboardController` | `index` | `buildStats()` 共用 |
| `UserProfileController` | `edit`, `update` | `rules()`／`applyProfileUpdate()`／`getAvailableAvatars()` 共用 |
| `CodesController` | `index, show, create, edit, update, store, destroy, proposalStore, proposalUpdate`；`proposalEdit` **需先改呼叫端才能刪**（見 §三之二 #3） | ⚠️ 寫入端一律走 `performUpdate/performStore/performDestroy/performProposalStore/performProposalUpdate` 單一來源，**只刪薄殼**。三項**不可刪**：① `proposalUpdateExisting`／`proposalCancel` 新舊**共用同一方法**（`web.php:317-320` 與 `:362-363` 指向 `CodesController.php:1672,1769`）；② **`export()` 與 `codes/{table_name}/export` 路由是 React `Codes/Show` 的唯一匯出端點**——`appShow()` 硬編碼 `'export' => '/codes/'.$table.'/export'`（`CodesController.php:522`），前端 `Pages/Codes/Show.tsx:288` 直接用它，**且 `app/codes/{table}/export` 路由不存在**。環節 3 若把 `/codes/*` 一律導向 `/app/*`，匯出鈕當場 404 |
| `OperationsController` | `index` | `buildOperationsListing`、`restore` 全套保留 |
| `ManagementController` | `index`, `edit`, `update`，以及 `create/store/show/destroy`（**有路由但方法體為空**：`ManagementController.php:147,157,167,416`，`Route::resource('manage')` 未 `except`） | `performUserUpdate`、`buildUserListing` 保留。⚠️ `appIndex()` 內的 `$editIsNew`（`ManagementController.php:59`）讀 `manage` flag，刪 flag 時要同步收斂 |
| `MergePreviewController` | `index` | 其餘皆為共用計算 |
| `CrowdsourcingController` | `index` | `confirm`／`reject` 為新舊共用寫入端 |
| `ViewTableController` | `index`, `show` | — |
| `AdminAuditLogController`、`AiFillLogController` | `index` | 共用 query builder 保留 |
| `AdminExplainSqlController` | `show`, `explain` | `runExplain` 共用 |
| `AdminBatchLoad{BookTitles,Offices,SocialInstitutes}Controller` | `showForm` | `store/undo/updatePinyin/checkRareChars` 共用；⚠️ **`listRouteName()` 的舊路由分支要一併收斂** |
| `CbdbTableMaintenanceController` | `index` | `rebuild`、`getNameFtsProgress` 保留 |
| `UnidirectionalRelationshipRepairController` | `index` | `repairKinship`／`repairAssoc` 保留 |
| `QueryPlaygroundController` | `nlQueryLogs`；`index`（已是硬導向，可改成路由層 `Route::redirect`） | 其餘 API 端點全保留 |
| `WikiMaintenanceController` | `index`（已是硬導向，可改成路由層 `Route::redirect`） | Blade 版早已刪除 |
| `WelcomeController` | flag 分歧中的 `return view('welcome')` 分支 | — |
| `Auth\{Login,Register,ForgotPassword,ResetPassword}Controller` | flag 分歧中的 Blade 分支 | — |
| `BasicInformationController` + 12 個子資源 controller | `index/show/create/store/edit/update` 的 Blade 分支、`editQuery/updateQuery/destroyQuery` | ⚠️ **`saveas()`、`Duplicate_Collateral_Info()`、`destroy()` 未被閘門擋、仍在服役**，**不可刪**（見 D-5） |
| `BasicInformationProposalController` | `proposalStore`, `proposalUpdate` | 兩者皆已回 410，可整檔刪除 |
| `PersonBrowserController` | **無可刪方法**（純 React） | 🔴 **本表最危險的一項**：`PersonBrowserController`（環節 2 前的 `:39-61`）用 12 個 `migration_flag_is_new('basicinformation.*')` 產生 `altnameEditorIsNew` 等 Inertia props。消費端 `Pages/PersonBrowser` 的 `TabContentLoader.tsx:88-100` 預設值全是 `= false`，`tabs/AltNamesTab.tsx:81` 為 `const useReactEditor = altnameEditorIsNew && …`。**刪掉 flag key → 全部 prop 變 `false` → 13 個 React 編輯器靜默退回唯讀分支**。不是 500，是**功能無聲消失**，測試也未必抓得到。見 §三第 3 欄 |

### B. 死碼（零引用，可直接刪）

| 檔案 | 行數 | 證據 | 備註 |
|---|---|---|---|
| `resources/views/home.blade.php` | 17 | 判定當時，`view('home')` 只存在於 `HomeController::index()` 內一行**被註解掉的程式**；`index()` 實際是 `redirect('/basicinformation')` | ✅ **已於環節 1 刪除**（Backlog P6-C1）。該註解一併移除、順手清掉同檔未被使用的 `BiogMainRepository` 注入。**`/home` 路由與 redirect 未動**（三個 Auth controller 的 `$redirectTo`、`RedirectIfAuthenticated`、React 端 AuthLayout／Profile Edit 都依賴它） |
| `resources/views/auth/register2.blade.php` | 12 | 全庫零引用（信件樣板孤兒，無 Mailable／`Mail::send` 送資料進去） | ✅ **已於環節 1 刪除**（Backlog P6-C2） |
| `resources/views/biogmains/basicinformation/show.blade.php` | 17 | `view('biogmains.basicinformation.show')` 零呼叫 | ✅ **已於環節 1 刪除**（Backlog P6-C3；原不在帳本，環節 1 新增） |

> ⚠️ `components/posted-to-addr-diff.blade.php`（141 行）**不是死碼**——它被 `components/diff-table.blade.php:6` `@include`，而 `diff-table` 又被三個**線上可達、無閘門**的頁面使用：`operations/index.blade.php:505,510`（A-4）、`admin/audit_logs/index.blade.php:292`（A-9）、`crowdsourcing/index.blade.php:88`（A-7）。已改列 D-6，隨環節 4a 一併刪。

### C. 共用／非重複（**不可刪**）

| 檔案 | 為何不可刪 |
|---|---|
| `resources/views/inertia.blade.php` | **React/Inertia 的根模板** |
| `resources/views/biogmains/_chgis_map_assets.blade.php` | ⚠️ **被 `inertia.blade.php:36` 直接 `@include`**——注入 CHGIS 地圖前端資源；誤刪會讓**所有 React 頁** 500。<br>🔴 **更隱蔽的地雷是路由名**：該檔 `:28` 有 `route('basicinformation.index', [], false)` 當 `pointsUrlBase`（`chgis-map/app.js:494` 用它組 `${base}/${id}/map-points`）。也就是說**每一個 React 頁都在執行一次 `route('basicinformation.index')`**——這個**路由名與 URI `/basicinformation` 在整個計畫期間都不得改變或移除**，否則 `RouteNotFoundException` 全站 500。環節 2 步驟 1 搬家時應順手拆掉這個依賴：正確的目標路由名是 **`basicinformation.map-points`**（`routes/web.php:61-63`，**不是** `chgis-map.person-points`，那個名稱不存在）。做法是先讓 `chgis-map/app.js` 接受完整 URL template，再改用 `route('basicinformation.map-points', ['id' => '__ID__'], false)`；或乾脆保留現行 `/basicinformation` base。**必須在改名／移除 `basicinformation.index` 之前完成，並補一個 render test。**<br>另：該檔用 `@push('scripts')`，搬家後新位置的 `@include` 必須仍在 `inertia.blade.php:37` 的 `@stack('scripts')` **之前** |

| ~~`resources/views/biogmains/_place_link.blade.php`~~ | ❌ **已移出 C 類**：它**只**被 `biogmains/addresses/index.blade.php:63` 與 `biogmains/offices/index.blade.php:55` 兩個 legacy Blade `@include`（`inertia.blade.php:36` 只 include `_chgis_map_assets`，不含它）。React 端的 place-link 由 `resources/js/chgis-map/` 自行渲染。**應隨 A-20 於環節 2 一併刪除，不要搬遷、不要列入最終保留** |
| `resources/views/biogmains/defense.blade.php` | 🔴 **被 `operations/index.blade.php:34` `@include`**——跨命名空間依賴。環節 2 若跟著 `biogmains/**` 一起刪，`/operations`（A-4，**無閘門、線上可達**）立刻 `View [biogmains.defense] not found` → 500。必須留到環節 4a 與 `operations/index` 同時刪，或先搬到 `partials/` |
| `resources/views/cbdbapi/person.blade.php`（1,121 行） | v1 公開 API 的回應樣板（`response()->view()`），**非互動頁**，主計畫已明確排除 |
| `resources/js/utils/{disableNumberInputWheel,sqlFormatter}.js` | ⚠️ 被 inertia 端 import（`inertia/app.tsx:7`、`QueryPlayground/SqlEditorPanel.tsx:2`）——**不可隨 `app.js` 一起刪**。<br>📌 `resources/js/utils/datetime.js` **僅** `app.js:31` 使用，可隨環節 5 一併刪（刪前重跑 grep）。<br>📌 inertia 內部另有 `resources/js/inertia/utils/`（pinyinUmlaut、markdown…），**與此為不同目錄**，不受影響 |
| `resources/js/chgis-map/`、`resources/js/historical-maps/` | 兩個獨立 Vite 入口，React 與 `/app/maps` 分別依賴 |
| `resources/lang/**` 的 `common`／`person`／`nav`／`chgis_map`／`views`／`office`／`social_institution`／`text_entity`／`auth` 等群組 | React 透過 `page_translations` 使用同一批 key |

### D. 僅 Blade、無 React 版（保留，評估取代）

| # | 項目 | 現況 | 取代可能性 | 難度 | 建議 |
|---|---|---|---|---|---|
| D-1 | `maps/index.blade.php`（103 行） | `GET app/maps` → `HistoricalMapsController@index`。**掛在 `/app/*` 但其實是 Blade**；為 `resources/js/historical-maps/app.js`（Leaflet 全螢幕）的宿主殼，**不套 AdminLTE、不依賴 jQuery/Bootstrap** | 高 | **低（S）** | ✅ **環節 7 結論：移出本計畫**（詳見 §環節 7「D 類結論」）。它不是 legacy 頁，而是 `app/maps` 現在服役的頁面；獨立 HTML、不套 AdminLTE，環節 5 不會波及。若要 React 化：改成 Inertia 頁並沿用同一支 JS 即可。注意它引用 Leaflet 的 CDN CSS |
| D-2 | `cbdbapi/person.blade.php` | v1 API 回應樣板 | — | — | **明確排除**，永久保留（改為純 Response 組裝屬另一議題） |
| D-3 | `biogmains/_chgis_map_assets`（**僅此一檔**） | 被 `inertia.blade.php:36` `@include`，React 根模板依賴 | 高 | **中**（非「低」） | 搬離 `biogmains/` 命名空間（→ `resources/views/partials/chgis-map-assets.blade.php`），避免刪 `biogmains/**` 時誤刪。這是環節 2 的第一步。<br>⚠️ **`_place_link.blade.php` 不屬於本列**——它只被兩個 legacy Blade `@include`，隨 A-20 刪除（見 §二 C）。<br>⚠️ 難度是**中**不是低：它含 `route('basicinformation.index')`、`@push`／`@stack` 配對、`ChgisMapManager` 容器解析、`@vite` 入口；而且 `chgis-map/app.js:494-495` **目前只接受 base URL**（`${base}/${id}/map-points`），改用 `basicinformation.map-points` 需**同時改 JS 讓它接受完整 URL template**，不是只換一個 route 名 |
| D-4 | `layouts/{app,dashboard-v3,header-v3,footer,sidebar-v3,partials/sidebar-node}`（837 行） | AdminLTE 殼 | 高 | **低**（A 全清後自動成孤兒） | 隨環節 5（Phase 7）一併刪。<br>📌 **`layouts/app.blade.php` 自環節 1 起已無任何消費端**（原唯一消費者 `biogmains/basicinformation/show.blade.php` 已刪）——但**仍不在環節 1/2 範圍**，維持排在環節 5 一併清，以免零散更動 layout |
| D-5a | `basicinformation/{id}/saveas`、`basicinformation/{id}/Duplicate_Collateral_Info` | 🔴 **React 正在主動呼叫**：`TabContentLoader.tsx` 與 `BasicInformationController@appEditV2` 的 payload 各有一組硬編碼 URL，餵給 `BasicInfoEditor` 的按鈕（`<a href>` 直接導航）。兩條路由**完全無 middleware** | — | **中（M）** | ⚠️ **任何環節都不得刪除或 redirect**。正確描述是「React 依賴的 legacy 端點」，**不是**「React 缺的功能」。<br>✅ **環節 7 結論：保留、移出本計畫**（詳見 §環節 7「D 類結論」）——它們是「沒有 React 版的活功能」而非重複的 Blade 頁，搬家是真的功能移植（GET→POST + 新端點 + 帶走兩個資料完整性守衛），另開任務 |
| D-5b | `Route::resource('basicinformation')` 的 `destroy` | 環節 2 刻意保留（`LegacyBladeFormGate` 已隨該環節刪除，這條現在是無 middleware 的裸路由）。**但 React 的刪除走的是 API v2**（`api.v2.delete.web`，見 `PersonBrowserController.php:33`、`BasicInformationController.php:1611`）——全庫查無 React 對 `basicinformation.destroy` 的呼叫 | 高 | **低—中** | 與 D-5a **不同性質**：它是「未被閘門擋下的 legacy route」，不是 React 依賴。**不要因為 D-5a 而順便永久保留它**。<br>**執行時機明確定為**：環節 2 **先保留**（步驟 3 不動它），盤點列入**環節 7**。<br>✅ **環節 7 結論：確認零呼叫者、可下架，排入環節 4b**（詳見 §環節 7「D 類結論」）。⚠️ 下架前要確認 v2 軟刪除涵蓋**眾包分支**——這條 legacy 方法對眾包用戶另走 `operations` op_type 4 |
| D-6 | `components/forms/{audit-fields,person-id-display}`、`components/{inline-time-fields,diff-table,posted-to-addr-diff,key-value-table,ai-fill-diff-table}`（7 檔 / 559 行） | legacy 表單／日誌頁元件 | — | 低 | ⚠️ **消費者跨多個環節**：`audit-fields`／`person-id-display`／`inline-time-fields` 只服務 A-20（環節 2 可刪）；`diff-table` → `posted-to-addr-diff` 鏈被 A-4／A-7／A-9 三頁使用（**環節 4a 才能刪**）；`key-value-table`／`ai-fill-diff-table` 屬 A-9／A-10（環節 4a）。**逐一 grep 確認零引用後才刪** |

---

## 三、清除方式總表（每一類位置都要動到什麼）

> 這是 §二 每一項在執行時的**逐位置檢查表**。**任何一個小環節都要走完這 17 欄**，不得跳過。

| # | 位置 | 動作 | 陷阱 / 備註 |
|---|---|---|---|
| 1 | `resources/views/**` | **直接刪除** blade 檔（含 `_form.blade.php` 等 partial） | 刪前對視圖名 + `@include` + `<x-…>` 三種引用形式各 grep 一次；**`biogmains/_chgis_map_assets` 例外**（見 D-3） |
| 2 | `routes/web.php` | **刪除舊路由定義**；對外可能有書籤的 URL 改為導向（觀察期 302、永久下架後 301） | ⚠️ ① `Route::resource(...)` 整行刪除前確認 `except()` 之外的動作（`destroy`、`create/store/show`）沒有別人在用；② `Route::redirect` **不保留 query string**，需保留時改用 controller 或 closure；③ **不可整批導向**——`codes/{table}/export` 是 React 在用的端點（見 §二 A CodesController 列）；④ 把 `QueryPlaygroundController@index`／`WikiMaintenanceController@index` 換成 `Route::redirect` 時會**丟掉 `Auth::user()->isActive()` 授權檢查**（`QueryPlaygroundController.php:37`），須改在路由 middleware 上補回 |
| 3 | `app/Http/Controllers/**` | 刪除 legacy 方法（見 §二 A 的 controller 表）。`appXxx` **改名收斂**回 `xxx` 屬可選，建議留到最後一個環節一次做，避免每個環節都在改呼叫點 | ⚠️ 寫入端一律只刪「呼叫 `perform*()` 的薄殼」；`CodesController::{proposalUpdateExisting, proposalCancel, export}` **不可刪** |
| 3b | **flag 派生的 Inertia props**（`PersonBrowserController.php:39-61` 的 12 個 `*EditorIsNew`、`ManagementController.php:59` 的 `$editIsNew`） | 在**刪 flag key 的同一 commit** 內：後端改成無條件 `true`（或移除該 prop），前端 `Pages/PersonBrowser/TabContentLoader.tsx:88-100` 的 `= false` 預設與 13 個 `tabs/*.tsx` 的 `xxxEditorIsNew &&` 條件一併移除 | 🔴 **漏掉這一欄的後果是「靜默功能消失」而非 500**：flag key 不存在 → `migration_flag()` 回退 `default='old'` → prop 全 `false` → React 編輯器退回唯讀分支。**驗收必須是人工開頁確認 13 個編輯器還在**，不能只看測試綠 |
| 4 | `app/Http/Middleware/LegacyBladeFormGate.php` + `app/Http/Kernel.php` | A-20 刪除後此 middleware 成孤兒 → 刪檔 **並**從 `$routeMiddleware` 移除 `legacy.form` 別名 | ⚠️ **必須與 A-20 同一個 commit**——路由若仍引用已不存在的 middleware 別名，整站 500 |
| 5 | `app/helpers.php` | `person_index_url`（`:82`）／`person_show_base_url`（`:93`）／`person_index_base_url`（`:102`）／`person_page_url`（`:117`）／`person_create_url`（`:141`）／`code_table_edit_url`（`:150`）共 **6 個** helper 的 flag 分歧**收斂成只回 `/app/*`**；`migration_flag()`／`migration_flag_is_new()` 待 flag 機制整體移除時刪除 | 這些 helper 被多處呼叫：**先收斂函式內部、保留簽名**，最後再評估是否移除函式本身 |
| 6 | `app/Support/Navigation.php` | `url($flagKey, $oldRoute, $newRoute)` 收斂為單一路由；移除 `codes`／`view` 節點的 flag 分支；**`active.pages` 與 `active.patterns` 兩者皆可移除** | ⚠️ 兩個 active 欄位**都只有 Blade 側邊欄在用**——React 端靠 href pathname 比對（見 `SidebarNode.tsx` 註解），對 `pages`／`patterns` 皆零消費。改動後跑 `tests/Feature/NavigationSchemaTest.php` |
| 7 | `app/Http/Middleware/HandleInertiaRequests.php` | `profileUrl()` 的 flag 分支收斂 | 跑 `tests/Feature/InertiaSharedPropsTest.php` |
| 8 | `app/Support/CompositePrimaryKey.php` | `APP_EDIT_ROUTE_MAP` 的 `migration_flag_is_new()` 判斷收斂為無條件使用 `/app` 路由 | ⚠️ 這條影響 operations 的「查閱」連結；跑 `tests/Unit/CompositePrimaryKeyTest.php`、`tests/Feature/OperationsProposalResourceLinkTest.php` |
| 9 | `config/migration_flags.php` | 逐項移除已下架頁面的 flag key；**全部下架後整檔刪除** | ⚠️ **刪 key 前必須確認沒有任何 `migration_flag_is_new('…')` 還在讀它**——否則會靜默回退到 `default`（`old`），接著 `route('已刪除的舊路由名')` 拋 `RouteNotFoundException` → **500** |
| 10 | **`.env`（各機器，非版控）** | 移除已失效的 `MIGRATION_FLAG_*`（完整清單見 §三之四）。**現況已有一條孤兒**：`MIGRATION_FLAG_WIKI_MAINTENANCE=new`，其對應 flag 早已從 config 移除 | ⚠️ `.env` 不在版控 → **必須在 `CHANGELOG.md` 與部署筆記明確列出「這批變數可刪」**，否則會永久殘留在各機器上誤導維護者。移除後需 `php artisan config:clear && php artisan config:cache` |
| 11 | **`.env.example`** | **現況：完全沒有 `MIGRATION_FLAG_*` 條目**（已確認）→ **本次無條目可刪**。但**需新增一段註解**，說明「migration flag 機制已於 YYYY-MM-DD 隨 Blade 下架移除，舊 `.env` 中的 `MIGRATION_FLAG_*` 可安全刪除」 | 這是新開發者唯一會看到的入口，不加註解等於沒交代 |
| 12 | `resources/lang/{zh-TW,en}/**` | **key-level** 清理。`biogmains` 群組（32 個 blade 在用、僅 1 處 PHP 引用）在 A-20 後大部分成孤兒；`nav` 群組部分 key 僅 Blade 側邊欄使用 | 🔴 **本欄是最容易出錯的一欄，策略刻意保守：只刪 `biogmains.*` 前綴，其餘一律保留。** 理由：React 透過 `page_translations` 取 key，而 `.tsx` 內存在**字串拼接的動態 key**，grep 根本抓不到；`nav`／`person`／`common` 是新舊共用。**留下孤兒 key 的成本 ≪ 刪錯 key 讓 React 顯示 raw key。** 其餘規則：絕不整檔刪除；逐 key 全庫 grep（含 `resources/js/inertia/**`）零命中才刪；`zh-TW` 與 `en` **必須同步**（AGENTS.md §6）；`views`／`office`／`social_institution`／`text_entity` 已是 React-only，**不可誤刪** |
| 13 | `resources/js/**`、`resources/css/**`、`vite.config.js` | 環節 5：刪 `resources/js/app.js`（1,283 行，AdminLTE + jQuery + Vue 掛載）、`jquery-global.js`、`datatables.js`、`components/Select.vue`、`css/{select2-overrides,mobile-responsive,ai-autofill}.css`；`vite.config.js` 的 `input` 移除對應入口、移除 `vue()` plugin 與 `vue` alias | ⚠️ ① `resources/js/utils/{disableNumberInputWheel,sqlFormatter}.js` 被 inertia import，**不可刪**（`datetime.js` 僅 `app.js` 用，可刪）；② `chgis-map/`、`historical-maps/` 兩個入口**必須保留**；③ `jquery-global.js` **不是 Vite entry**（是被 `app.js:12`／`datatables.js:8` import 的模組），不要去 `input` 裡找它 |
| 13b | **`resources/js/inertia/**` 的 legacy fallback 分支** | 與「刪 flag key／刪 legacy 路由」**同 commit** 移除：`components/PersonBrowser/shared/legacyEditUrl.ts`（`buildLegacyEditUrl/DeleteUrl/CreateUrl`）、`shared/Legacy{Edit,Create,Delete}Button.tsx`、14 個 `tabs/*Tab.tsx` 的 `xxxEditorIsNew` 參數與 false 分支、`TabContentLoader.tsx:88-100,235` 的 props 與 `BasicInfoView` 回退分支 | 🔴 React 的 `false` 分支**不是「什麼都不做」，是渲染指向 legacy 路由的按鈕**（`/basicinformation/{id}/{seg}/edit\|delete\|create`）。路由刪了、prop 又退成 `false` ⇒ 按鈕還在但全部 404 |
| 13c | **React 端硬編碼的 legacy URL** | 每個環節開始前重跑：<br>`grep -rnE "['\"\`]/(basicinformation\|codes\|operations\|manage\|view\|dashboard\|profile\|crowdsourcing\|admin\|welcome)" resources/js/inertia \| grep -v '/app/'` | 已知命中：`PersonEditorShared/PersonBanner.tsx:88` 的 `?? '/admin/audit-logs'` fallback（環節 4a 後失效）；`TabContentLoader.tsx:260-261` 的 `saveas`／`Duplicate_Collateral_Info`（**必須保留**，見 D-5）；`AuthLayout.tsx:29`、`Pages/Profile/Edit.tsx:121` 的 `/home`（安全，`/home` 路由不刪） |
| 14 | `package.json` + lockfile | 移除 `admin-lte`、`jquery`、`@ttskch/select2-bootstrap4-theme`、`datatables.net`、`datatables.net-bs4`、`@vitejs/plugin-vue`、`@vue/compiler-sfc`、`vue`，並視 grep 結果評估 `lodash`、`sass`。`npm install` 後提交 lockfile | 每個套件刪除前先 `grep -rn "<pkg>" resources/js` 確認 inertia 端零 import。⚠️ ① `app.js:22` `import select2 from 'select2'`，但 **`select2` 不在 `package.json`**（靠 `admin-lte` 的依賴樹解析）——刪完跑 `npm ls select2` 確認走乾淨；② **`@fortawesome/fontawesome-free` 不可刪**，`resources/css/inertia.css:23` 直接 `@import` 它 |
| 15 | `tests/**` | 刪除純 legacy 的 Feature 測試；含新舊對照者移除 legacy 斷言；移除 **`TestCase::useLegacyPersonForms()`**（`tests/TestCase.php:35-43`，把 `basicinformation.*` flag 壓成 `old`）——注意它**不在 `setUp()` 裡，是各 legacy 測試自行呼叫的 opt-in helper**，移除時要一併處理所有呼叫端 | ✅ **環節 1.5 已完成分流**：14 個 `useLegacyPersonForms()` 呼叫端全部標上 `#[Group('legacy-parity')]`，`./vendor/bin/phpunit --group legacy-parity` 可一鍵列出全部 199 個測試（即環節 2 的刪除清單）；兩個 needs-v2-first 的缺口已補齊 v2 等價覆蓋。<br>⚠️ 直接依賴 flag 的還有 `LegacyBladeFormGateTest`、`FlagAwareUrlHelpersTest`、`NavigationSchemaTest`、`AuthPagesInertiaTest`、`CodesIndexInertiaTest`、`CodesPersonPickerTest`、`InertiaSharedPropsTest`、`OperationsIndexLinksTest`、`OperationsProposalResourceLinkTest`、`CompositePrimaryKeyTest`，全部要改。<br>⚠️ ② **`useLegacyPersonForms()` 有 14 個呼叫端**（環節 1.5 實測；已全部標 `#[Group('legacy-parity')]`）（`BasicInformation{Addresses,Altnames,Sources,Texts}ControllerTest`、`BasicInformationPagesLoadTest`、`BasicInformationProposalTest`、`BiogMainBasicInfoNameMergeTest`、`BiogMainProposalTest`、`EventStatusWriteActionsTest`、`FormUrlEncodingTest`、`NameSearchIndexAutoSyncTest`、`OfficeStoreRedirectTest`、`ProposalNormalizationTest`、`UnknownPersonKinshipAssocBlockTest` …），直接刪 helper ⇒ 14 檔 `Call to undefined method`。<br>⚠️ ③ **`tests/Unit/VariantReplaceHookCoverageTest.php` 必定會紅**，見下方專節 |
| 16 | 文檔 | `AGENTS.md`（「舊版 Blade 仍實體保留／翻回 `old` 即回退」整段改寫）、~~`README.md`（`:64`／`:114` 的對外承諾「flag 改回 old 即可回退」）~~ ✅ **已於環節 3 改為 `LEGACY_PAGE_RETIREMENT=false`**，不需再動；`:65-66` 的入口清單（含 `app.js`／`jquery-global.js`）留到環節 5、`CHANGELOG.md`、`docs/ADMINLTE.md`（改為「已下架」歷史文件）、`docs/ADMINLTE4_UPGRADE_FEASIBILITY.md`（標註已被取代）、`docs/REACT_INERTIA_MIGRATION_PLAN.md`（§五雙殼／§五之二回退保證／附錄 C 禁止清單全部失效）——✅ 回退保證那組（開頭里程碑、§五之二兩條、§八）已於環節 6a 加上限縮前言；雙殼與附錄 C 留到環節 5、`docs/REACT_MIGRATION_BACKLOG.md`（P6-C1/C2、P7-1..3 → `retired`）、~~`docs/VIEWS.md`~~（實查：只是 `/view/{key}` 資料目錄，零 flag／回退陳述，**不需改**）、~~`docs/migration-specs/*.md`（22 份 fidelity spec 加「歷史存檔」抬頭）~~ ✅ **已於環節 6a 全數加上** | ✅ **`docs/CODES_SORT_FILTER_AUTH_GATE.md` 已於環節 6a 改寫**（連帶 `AGENTS.md` 高風險備忘、`CHANGELOG.md` 的歷史條目加註、`docs/REACT_INERTIA_MIGRATION_PLAN.md` 的回退保證）。原文記載「把 `codes` flag 切回 `old` 會重新暴露無門檻的深分頁排序查詢」——**環節 3 之後已不成立**（封路 middleware 不讀 flag），真正的條件變成 `LEGACY_PAGE_RETIREMENT=false`。環節 4 實體刪除 Blade 版 `show()` 後此風險才**消失**，屆時要再更新一次。📌 `docs/VIEWS.md` 實查後**不需改**：它只是 `/view/{key}` 的資料目錄，無任何回退／flag 陳述 |
| 17 | `API.md` / `docs/openapi/openapi.yaml` | **本計畫預設不動任何 API 路由／欄位／授權／錯誤碼**，故無需更新。**若某個環節實際動到了對外端點語義（例如把 legacy 端點改成導向／410），必須在同一 commit 同步 `API.md`**（AGENTS.md 文檔維護原則） | ✅ **實查結果：環節 3 無需更新 `API.md`**。`API.md` 收錄的是 v1／v2 的 API 端點，唯一與本次相關的是 `GET /codes/{table_name}/export`（`API.md:1686`）——而那條**沒有被封路**（React 匯出鈕正在用），敘述仍然正確。legacy web 表單端點從不在 `API.md` 範圍內。環節 4 實體刪除時再複查一次 |

| 18 | `app/Providers/AppServiceProvider.php` | 環節 5 移除 `View::composer('layouts.dashboard-v3', …)`（`:81`）與 `Paginator::useBootstrap()`（`:53`） | ⚠️ 該 composer 是 `shouldRetainQueryDetails()`（`:113-160`，QueryProfile 明細保留機制）的**唯一消費者**。刪 layout 後它不會 500、只是永遠不觸發 ⇒ 一整段帶安全註解的邏輯變成看不見的死碼。**必須明確決定：廢除，還是改接 React（`HandleInertiaRequests` 目前沒有分享它）** |
| 19 | `composer.json` | **本計畫不移除任何 composer 套件** | ⚠️ 兩個看似 Blade 遺物的套件**不可刪**：`laravel/ui`（`Auth::routes()` macro 的唯一來源）、`laracasts/flash`（雖然 `@include('flash::message')` 只在兩個待刪 layout 裡，但 `HandleInertiaRequests.php:80,192` 把它橋接成 React toast，**仍在服役**） |

### 三之二、🔴 由 **PHP controller 動態產生**、React 直接消費的 legacy URL（grep `resources/js` 抓不到）

這是本計畫**最隱蔽的一類依賴**：URL 不是寫在 `.tsx` 裡，而是由 controller 組進 Inertia payload，前端只是 `<a href={row.urls.x}>`。因此 §三第 13c 欄的 JS grep **抓不到它們**——必須另外掃 **PHP 端的 `route(`／`url(`**。

| # | legacy 端點 | PHP 產生處 | React 消費處 | 刪除後的後果 | 處置 |
|---|---|---|---|---|---|
| 1 | `crowdsourcing/{id}/confirm`、`/reject`（**GET 動詞的寫入端**） | `CrowdsourcingController.php:183-184`（`url()` **字串拼接**，route name grep 抓不到） | `Pages/Admin/Crowdsourcing/Index.tsx:225,231` | 審核按鈕全壞 | 環節 3 排除；環節 4a 前先遷到 `/app` 或 POST 化 |
| 2 | `operations/{op}/restore`、`/approve`、`/reject`、`/cancel` | `OperationsController.php:1043-1052` | `Pages/Admin/Operations/Index.tsx` | 還原／審核按鈕全壞 | 排除，**不得 redirect／刪除** |
| 3 | **`codes.proposals.edit`** | `OperationsController.php:1104`（`return route('codes.proposals.edit', …)`，**無 `Route::has()` 保護**） | `Pages/Admin/Operations/Index.tsx:414-418` 的「修改提案」按鈕 | 🔴 **`RouteNotFoundException` → `/app/operations` 整頁 500**（不是壞連結，是產 payload 時就炸） | ⚠️ §二 A 原把 `proposalEdit` 列為可刪——**錯**。環節 4b 刪 `codes.proposals.edit` 前，**必須先**把這行改成 `app.codes.proposals.edit`（該路由已存在，`routes/web.php:315-316`） |
| 4 | `basicinformation.assoc.index`／`statuses.index`／`offices.index` | `AiFillLogController.php:152-158`（`prepareLog()` 的 `$personRoute`） | `Pages/Admin/AiFillLogs/Index.tsx:188` 的 `person_url` | 有 `Route::has()` 保護 ⇒ **不會 500，但 `person_url` 靜默變 `null`、連結消失**（又一個「無聲退化」） | 🔴 **環節 2 必須同步**把這三個 route name 改成 `/app` 對應頁（`app.basicinformation.show` + `tab` 參數） |

**因此 §三第 13c 欄的 grep 必須配一條 PHP 端的**：

```bash
grep -rnE "route\('(basicinformation|codes|operations|manage|view|dashboard|profile|crowdsourcing|admin|welcome)[^']*'|url\('(basicinformation|codes|crowdsourcing|operations)" app/
```

### 三之三、必定會紅的機械化把關測試：`VariantReplaceHookCoverageTest`

環節 2 會讓 `tests/Unit/VariantReplaceHookCoverageTest.php` **兩處同時紅**，這是預期行為，必須在同一 PR 內同步下調清冊並寫明理由（該測試的失敗訊息本身就這樣要求）：

1. **`NON_HANDLER_HOOK_SITES`**（`:178`）登記 `app/Http/Controllers/BasicInformationProposalController.php => ['hooks' => 3]`。環節 2 步驟 5 刪掉該 controller ⇒ 檔案不存在 ⇒ 記進 `$broken` ⇒ 紅。**動作**：刪掉這一筆登記。
2. **`EXEMPT_DELEGATES`**（`:104-110`）要求 `app/Repositories/BiogMainRepository.php` **原本至少 8 處**掛鉤，其中 2 處在 `altnameStoreById()`／`altnameUpdateById()`，而這兩個方法的唯一呼叫者是 `BasicInformationAltnamesController` 的 Blade 方法。若連帶清掉這兩個 repository 方法，掛鉤數 8→6，`BiogMainCreateHandler` 與 `BiogMainMutationHandler` 兩筆同時紅。**動作**：確認這兩個 repository 方法確實無其他呼叫者後，把 8 改成 6。✅ **環節 7b 已執行**（連 `altnameById`／`altnameDeleteById`／`parseAltnameId` 一併刪除，三處記數與 `why` 字串都已改為 6，見 §環節 7）。⚠️ 順帶查明：該測試的斷言是 `$actual < $expected`（**floor 語義**），所以記數設得**太低**抓不到——改成 7 會紅、改成 5 不會。加減掛鉤時記數必須跟著改成精確值，不能只求「不紅」。

✅ **相對地，環節 4b 是安全的**：`CodesController` 的 6 處掛鉤全在共用 `perform*`（`:1360,1547,1681,1850,1960` ＋ `:2467` 本體），「只刪薄殼」不會動到記數。

**每個環節都要跑**：`./vendor/bin/phpunit --filter VariantReplaceHookCoverage`

### 三之四、`.env` 需移除的變數清單（現況 33 條）

```text
# 已可移除（對應 flag 將隨 Blade 下架一併從 config 刪除）
MIGRATION_FLAG_DASHBOARD              MIGRATION_FLAG_BASICINFO_KINSHIP
MIGRATION_FLAG_CODES                  MIGRATION_FLAG_BASICINFO_ASSOC
MIGRATION_FLAG_MANAGE                 MIGRATION_FLAG_BASICINFO_ADDRESSES
MIGRATION_FLAG_PROFILE                MIGRATION_FLAG_BASICINFO_ALTNAME
MIGRATION_FLAG_VIEW                   MIGRATION_FLAG_BASICINFO_TEXTS
MIGRATION_FLAG_OPERATIONS             MIGRATION_FLAG_BASICINFO_SOURCES
MIGRATION_FLAG_MERGE_PREVIEW          MIGRATION_FLAG_BASICINFO_OFFICES
MIGRATION_FLAG_CROWDSOURCING          MIGRATION_FLAG_BASICINFO_EVENTS
MIGRATION_FLAG_BASICINFO_INDEX        MIGRATION_FLAG_BASICINFO_ENTRIES
MIGRATION_FLAG_ADMIN_AUDIT_LOGS       MIGRATION_FLAG_BASICINFO_STATUSES
MIGRATION_FLAG_ADMIN_AI_FILL_LOGS     MIGRATION_FLAG_BASICINFO_POSSESSION
MIGRATION_FLAG_ADMIN_EXPLAIN_SQL      MIGRATION_FLAG_BASICINFO_SOCIALINST
MIGRATION_FLAG_NL_QUERY_LOGS          MIGRATION_FLAG_BASICINFO_SHOW
MIGRATION_FLAG_BATCH_BOOKS            MIGRATION_FLAG_BASICINFO_EDITOR
MIGRATION_FLAG_BATCH_OFFICES          MIGRATION_FLAG_TABLE_MAINTENANCE
MIGRATION_FLAG_BATCH_SOCIAL           MIGRATION_FLAG_UNIDIRECTIONAL_REPAIR

# 已是孤兒（config 中對應 flag 早已移除，現在就可刪）
MIGRATION_FLAG_WIKI_MAINTENANCE
```

另：`MIGRATION_FLAG_DEFAULT`、`MIGRATION_FLAG_AUTH_LOGIN`、`MIGRATION_FLAG_AUTH_REGISTER`、`MIGRATION_FLAG_AUTH_PASSWORDS`、`MIGRATION_FLAG_WELCOME` 在 `config/migration_flags.php` 有定義但目前 `.env` 未設定；整檔刪除 config 時一併失效，各機器若自行加過需一併清除。

---

## 四、執行計畫（小環節 → review agent → codex → 下一環節）

> 每個環節結束後的固定流程：
> ① `./vendor/bin/php-cs-fixer fix`（push 前先清 cache，並用 dist config 跑 `--dry-run --diff` 複驗，避免本機誤報 clean 而 CI cs-check 紅）
> ② 受影響測試綠；改動面大則跑全量 `./vendor/bin/phpunit`
> ③ 有前端改動則 `npm run build` + `npx vitest run`
> ④ **派 review agent 讀碼 + 讀 diff，直到無嚴重 issue**
> ⑤ **`codex exec --dangerously-bypass-approvals-and-sandbox` 非互動 review，直到無嚴重 issue**
> ⑥ 才進下一環節。

### 環節 0 — 本計畫文件
產出本文件。**不動任何程式碼。**

### 環節 1 — 死碼清除（風險：極低）
- 刪 §二 B 的 **3** 個檔案（`home.blade.php`、`auth/register2.blade.php`、`biogmains/basicinformation/show.blade.php`）。
- ⚠️ **不要碰 `components/posted-to-addr-diff.blade.php`**——它被 `diff-table` `@include`，而 `diff-table` 服務三個線上可達的頁面，屬環節 4a。
- `docs/REACT_MIGRATION_BACKLOG.md` 的 P6-C1／P6-C2 標為 `retired`。
- **驗收**：全量 phpunit 綠；grep 證明零引用。

### 環節 1.5 — 測試分流 ✅ **已完成（2026-09-14）**

> 測試是本計畫**最大的單一工作量**，原本被壓縮成 §三的一欄，實際獨立成環節。

**做法**：先把 `useLegacyPersonForms()` 暫時改成 no-op 跑一遍全部呼叫端，用**實測**而非閱讀來判定哪些測試真的依賴 legacy 路由。結果：14 個檔共 **199 個測試，其中 148 個在 flag=new 下會紅**（其餘 51 個不依賴 flag）。

**已完成的動作**：

1. 14 個檔全部標上 `#[Group('legacy-parity')]` 並在 class docblock 寫明分流結論。此前全庫 `Group(` **零命中**，現在 `./vendor/bin/phpunit --group legacy-parity` 可一鍵圈出全部 199 個測試。
   🔴 **但 group 是「候選清單」，不是「刪除清單」**：199 個裡有 **51 個在 flag=new 下實測仍綠**，代表它們**不依賴 legacy 路由**，整檔刪除會連帶失去覆蓋。逐檔的保留清單見下表，且已寫進各檔 class docblock。
2. 逐檔判定並補齊缺口（見下表）。

| 測試檔 | 測試數（legacy 相依） | 分流 | 依據 |
|---|---|---|---|
| `BasicInformationAddressesControllerTest` | 4（4） | legacy-only | v2 覆蓋見 `ApiV2MutateAddressTest`／`ApiV2DeleteAddressTest` |
| `BasicInformationAltnamesControllerTest` | 23（23） | legacy-only ✅ **補齊後成立** | 異體字替換與改鍵衝突確實已被 `ApiV2CreateAltnameTest`／`ApiV2MutateAltnameTest`／`ApiV2MutateVariantReplacementTest` 覆蓋；但**全形括號正規化原本零覆蓋**（v2 測資從未用過全形括號，`BracketNormalizerTest` 只測 service 不測接線），已補，見「補齊的 v2 等價覆蓋」#4 |
| `BasicInformationPagesLoadTest` | 32（29） | legacy-only；**保留 3** | 純 Blade 渲染。保留：2 個 `/app/*` Inertia 案例 + `test_basicinformation_create_page_loads` |
| `BasicInformationProposalTest` | 37（18） | legacy-only；**保留 19** | 提案「建立」走 legacy 表單；19 個 `testApprove*`／`testReject*` 不依賴 flag，**保留** |
| `BasicInformationSourcesControllerTest` | 9（4） | legacy-only；**保留 5** | 稽核欄語義（§1.2）由**共用基底** `AbstractPersonSubresourceMutationHandler` 覆蓋，實際斷言在 `ApiV2MutateAddrIntegrityTest`／`ApiV2MutateTextEntityTest`（不在 `ApiV2MutateSourceTest`）。保留的 5 個不打路由，是直接呼叫 repository 的測試 |
| `BasicInformationTextsControllerTest` | 5（5） | legacy-only | v2 覆蓋見 `ApiV2MutateTextTest`／`ApiV2DeleteTextTest` |
| **`BiogMainBasicInfoNameMergeTest`** | 8（6） | **needs-v2-first ✅ 已補**；保留 2 | 見下方「補齊的 v2 等價覆蓋」#2。保留：`testGuestCannotUpdateNames`、`testInactiveUserCannotUpdateNames` |
| `BiogMainProposalTest` | 13（7） | legacy-only；**保留 6** | 🔴 原判「核准端是共用碼已有覆蓋」**不成立**——`OperationsProposalControllerTest` 內 `BIOG_MAIN` **零命中**，BIOG_MAIN 的核准語義（拒絕清空名、軟刪除而非實刪、建立提案撞 id）**全庫只有本檔這 6 個 `testApproveBiogMain*` 在守**。所幸它們不依賴 flag，**保留即可**，不需另寫 |
| `EventStatusWriteActionsTest` | 8（8） | legacy-only | 寫入與稽核欄語義由共用基底覆蓋（同上，斷言在 `ApiV2MutateAddrIntegrityTest`），`ApiV2MutateEventTest`／`ApiV2MutateStatusTest` 本身無稽核欄斷言 |
| `FormUrlEncodingTest` | 21（15） | legacy-only；**保留 6** | 測的是 legacy path-param 的 URL 編碼（斜線／問號／減號）——**v2 以 JSON 物件傳 PK，此問題類別不存在**；唯一的領域不變量 `-999→0` 已有 v2 覆蓋。保留的 6 個是 `CompositePrimaryKey` 編解碼單元測試，與路由無關 |
| **`NameSearchIndexAutoSyncTest`** | 13（5） | **needs-v2-first ✅ 已補**；保留 8 | 見下方「補齊的 v2 等價覆蓋」#1。保留：8 個 BIOG_MAIN Observer 案例 |
| `OfficeStoreRedirectTest` | 3（3） | legacy-only | 純 legacy 表單 redirect 的 query 參數，v2 無對應概念 |
| `ProposalNormalizationTest` | 5（5） | legacy-only | 合併機構 ID（`"123-4"`）是 legacy 表單欄位格式，v2 分欄傳送；`[n/a]` 哨兵與 `-999→0` 已由 `ApiV2MutateAssociationTest`／`CompositePrimaryKeyTest` 覆蓋；`__proposal_comment` 對應 v2 的 `meta.comment`，已有 `ApiV2MutateEntryTest` 覆蓋。<br>📌 待辦：`AssociationCreateHandler:280` 的 **create 側** `emptyToSentinel(c_text_title,'[n/a]')` 仍無 v2 斷言（現有的只覆蓋 update），成本很低，建議環節 2 前補上 |
| **`UnknownPersonKinshipAssocBlockTest`** | 18（16） | **needs-v2-first ✅ 已補（含修 v2 缺口）**；保留 2 | 🔴 原判「v2 已覆蓋」**完全錯誤**——見下方「補齊的 v2 等價覆蓋」#3。保留：`kinship_store_allows_non_unknown_person`、`assoc_store_allows_non_unknown_person` |

#### 補齊的 v2 等價覆蓋（**每一條都先證明過「會紅」**）

1. **`tests/Feature/ApiV2AltnameNameIndexSyncTest.php`（新檔，5 個測試）**——`CBDB__NAME_FTS` 索引同步的 v2 等價：create／update／delete 各一，外加兩個異體字案例（索引必須以**落地替換後**的字形建立，不得殘留替換前的輸入）。v2 的 `AltnameCreateHandler`／`AltnameMutationHandler`／`AltnameDeleteHandler` 本來就有同步邏輯，但**完全沒有測試**。驗證方式：把三個 handler 的 `syncAltnameIndexAfter*()` 短路後，5 個測試全紅。

2. **`ApiV2MutateTest` 新增 3 個測試**——姓名合併語序與 trim：
   - `testDirectBiogMainUpdateMergesChineseSurnameFirstAndLatinGivenNameFirst`
   - `testProposalBiogMainUpdateMergesLatinGivenNameFirst`
   - `testDirectBiogMainUpdateTrimsWhitespaceOnlyNameParts`

   另一個實測發現：`c_name_proper`／`c_name_rm` 的**空白清理有兩道**——
   `BiogMainRepository::updateById()` 合併時的 `trim()`，以及其後
   `BracketNormalizer::normalizePinyinField()` 結尾的 `trim()`（兩欄都在
   `BIOG_MAIN_PINYIN_FIELDS` 內）。**任一道單獨拿掉都不會讓測試變紅**，兩道都拿掉才會。
   該測試因此刻意寫成端到端不變量（「使用者看到的姓名欄不得有前後空白」），而不是綁死
   某一行實作。

   🔴 **另一個原本沒被記錄的事實：姓名合併有兩份各自獨立的實作**——direct 走 `BiogMainRepository::updateById()`（`:289-290`），proposal 走 `BiogMainMutationHandler::prepareProposalPayload()`（`:246-247`），兩邊各把「拉丁文名在前、中文姓在前」寫死一次。把**其中一份**的語序改反，另一份的測試**不會紅**。所以兩條路徑各有自己的斷言，缺一不可。驗證方式：分別反轉兩份實作，各自對應的測試確實變紅、另一個不動。

3. 🔴 **修補 v2 的一個實質行為缺口**（見下方 #3）。這是分流最大的收穫：原本以為只是「搬測試」，實際上挖出 v2 從來沒有的守衛。

#### 環節 2 的精確刪除規則

`--group legacy-parity` 的 199 個是**候選**。實際動作分兩類：

| 檔案 | 刪 | 保留（搬走） |
|---|---|---|
| `BasicInformationAddressesControllerTest` | 整檔（4） | — |
| `BasicInformationAltnamesControllerTest` | 整檔（23） | — |
| `BasicInformationTextsControllerTest` | 整檔（5） | — |
| `EventStatusWriteActionsTest` | 整檔（8） | — |
| `OfficeStoreRedirectTest` | 整檔（3） | — |
| `ProposalNormalizationTest` | 整檔（5） | — |
| `BasicInformationPagesLoadTest` | 29 | **3** |
| `BasicInformationProposalTest` | 18 | **19**（`testApprove*`／`testReject*` 全套） |
| `BasicInformationSourcesControllerTest` | 4 | **5**（直接呼叫 repository、不打路由） |
| `BiogMainBasicInfoNameMergeTest` | 6 | **2**（授權檢查） |
| `BiogMainProposalTest` | 7 | **6**（`testApproveBiogMain*`，全庫唯一的 BIOG_MAIN 核准覆蓋） |
| `FormUrlEncodingTest` | 15 | **6**（`CompositePrimaryKey` 編解碼，與路由無關） |
| `NameSearchIndexAutoSyncTest` | 5 | **8**（BIOG_MAIN Observer） |
| `UnknownPersonKinshipAssocBlockTest` | 16 | **2**（正向案例） |
| **合計** | **148** | **51** |

逐測試清單已寫進各檔 class docblock，不需回頭查本文件。

3. **`tests/Feature/ApiV2Create{Kinship,Association}Test` 與 `ApiV2Mutate{Kinship,Association}Test` 新增 11 個測試，並補上 v2 缺失的守衛。**

   🔴 **這是分流最大的收穫，也是一個真實的線上行為缺口**：legacy controller 從一開始就擋「對『未詳』人物（personid 0）建關係」與「把『未詳』人物當成關係對象」（兩個 controller 各 4 道攔截），但 **v2 的 kinship／association handler 完全沒有這道守衛**——同族的 `PossessionCreateHandler:89`／`PostingCreateHandler:106` 反而有，可見是逐一掛上時漏了這兩個。也就是說**現況下走 React 編輯器本來就擋不住**，而 `UnknownPersonKinshipAssocBlockTest` 的 18 個測試全部只打 legacy 路由——一旦隨環節 2 刪除，這條不變量在全庫將沒有任何憑證。

   處置：新增 `App\Services\Mutations\Concerns\BlocksUnknownPersonRelations` trait，掛到 kin／assoc 的 create 與 mutation 四個 handler，語義與 legacy 對齊（含訊息文字），改以 422 JSON 回應。測試涵蓋：擁有者為 0、對象為 0、對象為 -999 哨兵（正規化前就要擋，否則送 -999 即可繞過）、proposal 模式同樣要擋、改鍵把對象改成 0、pair-only 兩條路徑、以及一個**反例**（ASSOC_DATA 的 `c_kin_id`／`c_assoc_kin_id` 以 0 為合法哨兵，不得誤攔）。驗證方式：把 trait 的兩道判斷短路後絕大多數變紅、反例正確地維持綠；拿掉 pair-only 的守衛則 pair-only 回歸測試變紅。

   涵蓋的掛點共 **6 處**：kin／assoc 的 create、mutation，以及兩條 pair-only 鏡像修復路徑。新增 14 個測試（含 3 個 pair-only 回歸與 1 個防過度攔截的反例）。

   📌 **三筆殘留待辦，全部列入環節 7**（都是**既存狀態、非本次造成的回歸**，且牽涉需人決定的政策）：
   1. **提案核准不經 handler**：KIN_DATA／ASSOC_DATA 的核准走 `OperationsProposalController::applyKinshipProposal()`／`applyAssocProposal()` → `BiogMainRepository::kinshipStoreById()` 等 legacy repository 方法，**legacy 與 v2 共用這條路徑、兩邊一樣沒擋**。影響僅限「守衛上線前已存在的 pending proposal」——新提案在提交時（direct 與 proposal 兩種 mode）都已被擋。要不要在核准分支補守衛，取決於「核准被擋時審核者該看到什麼」這個政策決定。
   2. **`Duplicate_Collateral_Info()`** 直接複製 KIN_DATA／ASSOC_DATA 列，來源若有歷史 0 髒列會一併複製。該端點無 `legacy.form` 閘門、仍在服役（D-5a）。要跳過該列並告警、還是整批拒絕，同樣是政策決定。
   3. **`PossessionMutationHandler`／`PostingMutationHandler` 的 update 路徑**同樣沒有這道守衛（legacy 有；它們的 create 有）。本次只對齊 kin／assoc，避免環節 1.5 無限擴張。

4. **`ApiV2CreateAltnameTest` 與 `ApiV2MutateAltnameTest` 新增 4 個括號正規化測試**（create 正規化、create 撞鍵、update 正規化、update 撞鍵）。

   `BracketNormalizer` 的**接線**（`AltnameCreateHandler:58`、`AltnameMutationHandler:59`）在 v2 一行測試都沒有——`tests/Unit/BracketNormalizerTest` 只測 service 本身，把 handler 那一行呼叫刪掉全庫仍然綠。新測試涵蓋兩套刻意不同的規則（中文欄只轉半形不加空格、拼音欄轉半形並補空格），以及「括號正規化後才撞鍵」必須被擋。兩個撞鍵測試精確斷言 **409 + `errors['target.pk'] = ['conflict']`**，不用寬鬆的「409 或 422」——後者會被無關的驗證錯誤矇混過去。驗證方式：拿掉兩處接線，正規化測試變紅。

**驗收**：`./vendor/bin/phpunit --group legacy-parity` → OK (199 tests)；新增的 v2 測試全綠且**每一條都先證明過會紅**。

### 環節 2 — A-20 人物編輯 Blade 下架（風險：**中**，非「低」）
> ⚠️ **原評估「風險低——已被閘門擋成不可達」是錯的**。閘門只擋 **HTTP 入口**，擋不住：
> ① React props 退化（`PersonBrowserController` 的 12 個 `*EditorIsNew`，見 §三第 3b 欄）；
> ② React 主動呼叫 legacy 端點（D-5）；
> ③ `_chgis_map_assets` 對 `basicinformation.index` 路由名的依賴（§二 C）。
> **「Blade 不可達」≠「legacy 路由無人使用」。**
>
> **commit 切分（不要留給執行者自行拆）**：
> - **commit 1** = 步驟 1（搬 partial + 拆 `basicinformation.index` 依賴）
> - **commit 2** = 步驟 2–5 ＋ §三第 13b 欄的 React fallback 清理 ＋ §三第 3b 欄的 `PersonBrowserController` props 收斂（**必須同一個 commit**，否則中間態是「按鈕還在但 404」）
> - **commit 3** = 步驟 6–8

1. **先**把 `_chgis_map_assets.blade.php` 搬到 `resources/views/partials/`，更新 `inertia.blade.php:36` 的 `@include`（仍須在 `@stack('scripts')` 之前），並拆掉它對 `basicinformation.index` 的依賴（改用 `basicinformation.map-points`，見 §二 C）。**獨立 commit，先開一個 React 人物頁點 place-link 驗證**。⚠️ **`_place_link.blade.php` 不搬**——它只被兩個 legacy Blade 使用，隨步驟 2 一併刪。
2. 刪 `resources/views/biogmains/**` 其餘 **55 檔**。⚠️ **`defense.blade.php` 不在此列**——它被 `operations/index.blade.php:34` `@include`，`/operations` 此時仍活著且無閘門，一刪即 500。做法二選一：(a) 留到環節 4a 與 `operations/index` 同時刪；(b) 一併搬到 `resources/views/partials/` 並更新 `operations/index` 的 `@include`（建議，與步驟 1 同批處理）。
3. `routes/web.php`：刪 12 組子資源 `Route::resource` + query-path `edit/update/destroy` + `proposalStore/proposalUpdate`；`Route::resource('basicinformation')` 的 `index/show/create/edit` 改 302 導向 `/app/*`（觀察期語義，與環節 3 一致）；**保留 `saveas`、`Duplicate_Collateral_Info`、`destroy`**（D-5）。
4. 刪 `LegacyBladeFormGate` + `Kernel.php` 的 `legacy.form` 別名 + `tests/Feature/LegacyBladeFormGateTest.php`（**同一 commit**）。
5. 刪 12 個子資源 controller 的 Blade 方法；刪 `BasicInformationProposalController`。
5b. 🔴 **同步改 `AiFillLogController.php:152-158`**：`prepareLog()` 的 `$personRoute` 仍指向 `basicinformation.{assoc,statuses,offices}.index`，改成 `/app` 對應頁（`app.basicinformation.show` + `tab`）。它有 `Route::has()` 保護 ⇒ 不會 500，但 `person_url` 會**靜默變 null、連結消失**（`Pages/Admin/AiFillLogs/Index.tsx:188`）——又一個測試抓不到的無聲退化。
6. `config/migration_flags.php` 移除 `basicinformation.*` 全部 **15 個 key**（`index`／`show`／`editor` ＋ 12 個子資源；`config/migration_flags.php:45-63` 與 `tests/TestCase.php:36-41` 都列得出完整清單——**用清單比對，不要人工數**）。**同一 commit 內**一併收斂：`app/helpers.php` 的 6 個 helper、`CompositePrimaryKey.php:731-733`、**`Navigation.php:71-74` 的人物節點**（`self::url('basicinformation.index', …)`——若留到環節 4d 才改，側邊欄「人物編輯」會在環節 2 之後指向已被 **302** 導向的 legacy URL，每次點擊多一跳且 active-state 對不上）、以及 `PersonBrowserController` 的 12 個 props。
7. 移除 `TestCase::useLegacyPersonForms()` 與環節 1.5 判定為「純 legacy」的測試檔；**同步下調 `VariantReplaceHookCoverageTest` 的清冊**（見 §三之三，兩處必紅）。
8. ~~清理 `biogmains.*` 前綴的翻譯 key~~ → **改列為獨立項目 7-T1，本環節不做**。

   實測結果：`resources/lang/zh-TW/biogmains.php` 共 **414 個 key**，以最寬鬆的比對（`biogmains.x` 字面 ＋ `t('x')`／`tb('x')`／`tBio('x')` 任一變數名）仍有 **301 個查無引用**。但這個數字**不能直接當刪除清單**：
   - 「最寬鬆」意味著它把 `t('notes')` 這種**其他群組**的同名 key 也算成有引用，所以真正的孤兒數只會**更多**——方向是安全的；
   - 但反過來，它**抓不到動態組出來的 key**（字串拼接、以變數當 key），那才是刪錯會出事的部分；
   - React 仍大量使用這個群組（28 個元件綁 `tb`、6 個綁 `t`、2 個綁 `tBio`），它不是一個「整體死掉」的群組。

   孤兒翻譯 key **沒有執行期影響**（不會 500、不會顯示 raw key），而刪錯會讓畫面出現 raw key。依本計畫 §三第 12 欄自訂的保守策略（「留下孤兒 key 的成本 ≪ 刪錯 key」），把它獨立成一個**只動翻譯檔**的 commit，配自己的 review，比夾在這個已經 -22,000 行的環節裡安全得多。
- **驗收**：`PersonBrowserTest`、`ApiV2Mutate*Test`、`CompositePrimaryKeyTest`、`OperationsProposalResourceLinkTest`、`VariantReplaceHookCoverage` 全綠；🔴 **必須人工開頁**確認 `/app/basicinformation/{id}` 的 13 個 React 編輯器**都還在**（props 退化不會讓任何測試變紅）、`/app/person-browser` 各分頁正常、CHGIS 浮出地圖正常。

#### 環節 2 review 揪出的三件事（已修，留作後續環節的檢查項）

1. 🔴 **302 shim 造成無限導向**。`appCreate()` 對眾包用戶 `redirect()->route('basicinformation.create')`——而那條路由在環節 2 之後只是一個 302 導回 `/app/basicinformation/create` 的 shim，於是 `/app → /legacy → /app → …` 無限迴圈。**而且既有測試把這個迴圈寫死成期望值**（`assertRedirect(route('basicinformation.create'))`），所以不會示警。
   **教訓**：把 legacy 路由改成 shim 之後，**所有 `redirect()->route('<legacy 名>')` 都要一併改指 `/app`**——不只是為了避免迴圈，也因為 `laracasts/flash` 的訊息會在中間那一跳被 session 老化掉，成功／失敗提示靜默消失。環節 2 另外四處（`saveas` ×2、`destroy` ×2）同樣修掉。**環節 3 把 A-1…A-17 改成 shim 時要重跑這個檢查**：`grep -rn "redirect()->route('<該頁的 legacy 路由名>" app/`。
2. 🔴 **子字串斷言造成假綠**。`assertStringContainsString('/basicinformation/12345/offices/edit', $url)` 會被 `/app/basicinformation/12345/offices/edit-v2` **照樣命中**（`/app` 是前綴、`-v2` 是後綴），於是同一個斷言同時接受 legacy 與 React 兩種形狀、失去鑑別力。已全部改成 `assertStringStartsWith('/app/…edit-v2')`。**環節 3／4 改任何 URL 斷言時一律用 StartsWith 或精確比對。**
3. 🟡 **「塞回 flag 當護欄」要設 `'old'` 才有意義**。有幾個測試把 flag 設成 `'new'` 再斷言 React 行為——在 flag 已移除、行為本來就無條件的情況下那證明不了任何事。護欄的正確寫法是設 `'old'`。

### 環節 3 — 先封路，不刪碼 ✅ **已完成（2026-09-14）**，風險：低，**完全可逆**

> 交付物：[docs/BLADE_RETIREMENT_STAGE3_ROUTE_MANIFEST.md](./BLADE_RETIREMENT_STAGE3_ROUTE_MANIFEST.md)（逐條 route manifest）
> ＋ `app/Http/Middleware/RetireLegacyBladePage`（逐條掛，35 條）
> ＋ `config/legacy_page_retirement.php`（kill switch）
> ＋ `tests/Feature/LegacyBladePageRetirementTest`（59 個測試，含逐條身分斷言）
>
> **實測分類**：`php artisan route:list --json` 取出該批 controller 上的 **65 條** legacy（非 `app/*`）路由，逐條判定後
> **35 條封路**（22 條 GET→302、13 條→410）、**19 條不動**、**11 條不在本環節範圍**（v1 token API 與 Query Playground 共用後端）。不動的理由分三級：
> 只有 1 條真的「動了就壞」、6 條是「封了不會壞但 `listRouteName()` 收斂還沒做」、12 條是 React 正在呼叫的
> action endpoint——其中 `crowdsourcing/{id}/confirm|reject` 是 **GET 動詞的寫入端**，按 prefix
> 套規則會直接命中）。測試逐條驗證「該封的封了、該留的一條都沒被誤掛」，並鎖住總數 35。
>
> 🔴 **執行中發現並修正的設計缺陷**：原本以為「保留 Blade 視圖與 controller 不動」就足以可逆。
> 實作後全量測試 **186 個失敗、橫跨 22 個測試類**——因為**測試是打路由的**。那些測試不該刪
> （頁面還在、還能叫回來），正解是讓它們局部關閉封路（`TestCase::useLegacyBladePages()`），
> 並把封路做成 **config kill switch**：翻 `LEGACY_PAGE_RETIREMENT=false` 即可讓 legacy 頁復活，
> **不需重新部署、不需 git revert**。這反而讓環節 3 的「可逆」比原計畫更名實相符。

針對 A-1…A-17，**只**改路由行為、**保留** blade 檔與 controller 方法不動。

🔴 **必須逐條列 route manifest，禁止按功能名稱或 path prefix 套規則。** 只封「純顯示頁」（index／show／表單顯示），語義比照已驗證的 `LegacyBladeFormGate`：

- **顯示頁 GET → 302 導向 `/app/*`**（保留 query string、**保留原本的 auth／superadmin middleware**）
- **legacy 寫入端（POST/PUT/PATCH/DELETE）→ 410 Gone**

⚠️ **觀察期用 302／307，不要用 301**。301 會被瀏覽器與 CDN／crawler 長期快取——`git revert` 只還原伺服器，已經收到 301 的 client 未必會再請求舊 URL，所以「完全可逆」在 301 之下**不成立**。等觀察期結束、確定永久下架後，才在環節 4 升級成 301。

🔴 **不可以用 `Route::redirect` 一把梭**：它底層是 `Route::any()`，會連寫入端一起接管。A-3 codes 含 `store/update/destroy/proposalStore/proposalUpdate`，把它們一起導向的後果是——有 CSRF token 的舊表單被瀏覽器把 POST 降級成 GET、**body 整包丟掉**，使用者看到列表頁但資料沒存（**靜默資料遺失**）；沒 token 的外部客戶端則先被 `VerifyCsrfToken` 擋成 419。兩種都是「壞掉但不報錯」，不是「可逆的封路」。實作請用 closure 或一支小 middleware。
（附註：`Route::redirect` **會**綁定路徑參數，`codes/{table_name} → /app/codes/{table_name}` 可行；它不保留的只有 query string。）

**明確排除三項**：
- **A-18／A-19（認證頁與入口）**：它們**根本沒有獨立的 legacy 路由**——`WelcomeController::index()` 與 4 個 Auth controller 的 `showXxxForm()` 是**同一條路由內的 flag 分支**。這兩項**跳過觀察期**，只能在環節 4c 一次做完（刪 `return view(...)` 的 else 分支）。風險由「動的只是 else 分支、React 分支完全沒碰」本身承擔。
  🔴 **並且禁止對 `login`／`register`／`password.*` 動路由層**：`Auth::routes()` 來自 `laravel/ui`；URI 匹配「先註冊先贏」而 `route()` 名稱查表「後註冊覆蓋」，事後補一條同名 redirect 會造成**行為與連結不一致且不報錯**。更要命的是 `route('login')` 有兩個框架級消費者——`app/Exceptions/Handler.php:97` 的 `redirect()->guest(route('login'))` 與 `Illuminate\Auth\Middleware\Authenticate`——名字一掉就是全站未登入請求 500。
- **`codes/{table_name}/export`**：React 匯出鈕的端點（見 §二 A），**不改**。
- **D-5 的 `saveas`／`Duplicate_Collateral_Info`**：React 主動呼叫中（`TabContentLoader.tsx:260-261`），**不改**。

🔴 **另外三組「看起來是 legacy、其實是 React 正在用的 action endpoint」，同樣必須排除**（漏掉就是 React 按鈕全壞）：

| 端點 | 路由 | React 取得處 | React 消費處 |
|---|---|---|---|
| `crowdsourcing/{id}/confirm`、`/reject` | `routes/web.php:435-436`（**GET mutation**） | `CrowdsourcingController.php:183-184` 產 `confirm_url`／`reject_url` | `Pages/Admin/Crowdsourcing/Index.tsx:225,231` 直接 `<a href>` |
| `operations/{operation}/restore`、`/approve`、`/reject`、`/cancel` | `routes/web.php:370-373, 399` | `OperationsController.php:1043-1052` 的 `urls` payload | `Pages/Admin/Operations/Index.tsx` |
| `codes.proposals.cancel` | `routes/web.php:363` | `OperationsController.php:1052`（非 entity proposal 走這條） | 同上 |

> ⚠️ 特別注意 crowdsourcing 的 confirm／reject 是 **GET 動詞的寫入端**——按「GET 一律導向」的規則會直接命中它。這正是「不能按 prefix 套規則」的理由。
>
> 🔴 **環節 3 的第一個交付物必須是一份逐條 route manifest**（不是原則，是表格），在動任何路由之前先產出並過 review：
>
> | 路由（method + URI） | 路由名 | 分類 | 處置 |
> |---|---|---|---|
> | … | … | 顯示頁 GET ／ legacy 寫入端 ／ **React 仍在用的 action endpoint** ／ 共用端點 | 302 ／ 410 ／ **不動** |
>
> 產法：`php artisan route:list --json`，逐條對照 §三之二 的 PHP 端 grep 與 §三第 13c 欄的 JS 端 grep，**每一條都要標明分類與理由**。「不得 redirect／不得刪除」的條目要同時列出 *路由定義 + payload 製造點 + 前端消費點* 三者。
>
> 沒有這份 manifest，executor 只會按 prefix 套規則——而 §三之二 已經證明那會直接命中 React 正在用的端點。

其他：
- 同步在 `API.md` 註記受影響的 legacy URL（§三第 17 欄）。
- 出事只需 `git revert` 這一個 commit。
- **驗收**：手動走過每一條舊 URL，確認 GET 落點正確、query string 保留、非 GET 回 410。
- **觀察期：建議 1–2 週實際使用後才進環節 4。**

### 環節 4 — A-1…A-19 Blade 實體刪除（風險：中，**不可逆**）
拆成 4 個子環節，各自跑完整雙 gate：
- **4a 唯讀頁**：dashboard、operations、view、admin/audit-logs、admin/ai-fill-logs、nl-query-logs、crowdsourcing、merge-preview。
  拆成三個子環節，因為風險不在刪碼（9 個 Blade action 全是薄殼或被 React 嚴格涵蓋的重複實作），
  而在測試：legacy 側 3923 行 vs React 側 920 行，4:1。
  - **4a-1 ✅ 已完成（2026-09-14）**：`SecurityAuditLogTest`（16 條全 C，且 legacy-parity 標記是**誤標**——
    整檔零 HTML 斷言，純 URL 抽換即可）、`AdminAuditLogHistoryFilterTest`、`AiFillLogTest`（8→1）。
    `--group legacy-parity` 309 → 284。
  - **4a-2 ✅ 已完成（2026-09-14）**：`OperationsIndex{Diff,Filter,Links,Resilience}Test` 四檔 59 → 58 條，
    全部改打 `/app/operations` 並讀 Inertia props。`--group legacy-parity` 284 → **226**。
    🔴 **最大的陷阱是 K 類**：`OperationsIndexLinksTest` 的 17 條**本來就是 React 測試**
    （透過 `firstResourceLink()`／`firstRow()` 打 `/app/operations`），只是住在 legacy 檔、被 setUp 的
    opt-out 連坐——整檔刪掉會毀掉它們。
    ⚠️ **轉換時差點把斷言轉弱**：`diff_source` 是 `resource_diff ?? resource_original`，
    fixture 的 `resource_original` 非空，所以 `assertNotNull($diff)` 在 resource_id 解析失敗時也會過。
    改成斷言每個 diff row 的 `current` 不是 `(未取得)`、且至少一欄 `matches_current`，並加**誘餌列**
    （否則只插一列時「WHERE 整組拿掉」也會撈到那唯一一列）才真的有鑑別力。
    順帶揭露一條原本就過寬的 legacy 斷言：`assertSee('...&c_assoc_id=202', false)` 只是**前綴**比對，
    實際 URL 帶完整 9 欄 PK。
    唯一保留的 legacy 耦合是 `test_legacy_codes_edit_page_resolves_the_right_composite_row`
    （要開 legacy codes 編輯頁確認 id 解析，該頁屬 4b），已單獨掛 `#[Group('legacy-parity')]`。
  - **4a-3 ✅ 已完成（2026-09-14）**：實體刪除 **16 個 Blade 檔**（9 個視圖 + D-6 的 4 個元件
    + 環節 2 遺留的 3 個孤兒元件）與 **9 個 Blade controller 方法**，共 **-3894 行**。
    - **9 條路由改成 redirect closure**，不再掛 `legacy.page`。理由：那個 middleware 有兩條
      fail-open 路徑（導向目標不存在時放行、kill switch 關閉時放行），視圖被刪之後那兩條
      會變成 500 而不是「看到舊頁」。改成 closure 就沒有可掉下去的 controller。
      **route name 全部保留**（書籤／外部連結繼續可用並保留 query string，`route('x')`
      呼叫端與 `NavigationSchemaTest` 的舊 nav 斷言都不必改）。
    - 🔴 **這 9 條自此沒有任何 kill switch 級回退**：`LEGACY_PAGE_RETIREMENT=false` 對它們
      已無作用，要回到 Blade 只能 git revert 並重新部署。新增
      `LegacyBladePageRetirementTest::legacy_readonly_pages_redirect_without_the_kill_switch()`
      把這一點寫死——**光看 302 的狀態碼分辨不出來**，若日後有人照舊 runbook 操作，
      那條測試是唯一寫死「不能」的地方。封路身分清單 35 → **25**（35 − 10）。
    - `dashboard`／`view` 兩條的 `auth` middleware 一併移除（redirect 不需授權）。
      副作用：未登入打舊 URL 變成 302 → `/app/dashboard` → 302 → `/login`（多一跳），
      但 Laravel 記下的 intended URL 變成 `/app/dashboard`，登入後直接落在 React 頁。
      `InactiveAccountAccessTest` 與 `LoginRedirectTest` 原本拿 `/dashboard` 當「受保護頁面」
      的代表，已改指 `/app/dashboard`——它們從守 shim 變成守實際頁面。
    - 🔴 **`RouteActionsExistTest` 抓到一條真的漏刪**：`POST merge-preview`
      （legacy 表單用 POST 回同一頁顯示結果）也指向被刪的 `MergePreviewController@index`，
      而它掛的是 `legacy.page:gone`——kill switch 一關就 500。已改成 closure 直接 410。
      **這就是為什麼那條護欄測試值得存在。**
    - 翻譯鍵不逐鍵清：4a 視圖用到的 7 個群組全部也被對應的 React controller 當
      `page_translations` 傳下去（`common`／`nav`／`person`／`query`／`biogmains` 另由
      `HandleInertiaRequests` 全站共享），**沒有群組變孤兒**，所以不會壞、也不違反 §6 的
      zh-TW／en 同步。
      📌 **但個別孤兒鍵已實數過：252 個**（`admin` 109、`operations` 61、`query` 41、
      `common` 20、`biogmains` 18、`person` 3）。留給環節 5 之後整批處理（理由同 7-T1：
      動態組鍵 grep 不到，逐鍵猜的收益為零）。
    - 📌 **環節 5 的清理清單新增兩筆**（4a-3 之後才確定成為死碼）：
      ① `resources/js/app.js:387-530` 整段 jQuery 邏輯以 `.person-id-display-component`
      為錨，該元件已於本環節刪除；② `app/Support/Navigation.php` 各節點的
      `['pages' => ['Crowdsourcing'|'審計日誌'|'AI 填充日誌'|'NL Query Logs']]` 對應的是
      Blade 的 `page_title_key`，現在沒有任何 Blade 頁會送出這些值（`NavigationSchemaTest` 仍綠）。
    - 📌 **4b 的一個注意點**：`resources/views/codes/proposal-edit.blade.php:54` 的
      `route('operations.index', ['proposals_only' => 1])` 現在會把使用者從 Blade codes 流程
      一鍵跳進 React `/app/operations`。kill switch 關閉時這是「半 Blade 半 React」的體驗；
      若 4b 要保持「關掉就是純 Blade」需另行處理。
**同時刪**環節 2 保留下來的 `biogmains/defense.blade.php`（若走 (a) 方案）與 D-6 中的 `components/{diff-table,posted-to-addr-diff,key-value-table,ai-fill-diff-table}`（它們的最後消費者就在這一批）
- **4b 表單／寫入頁**：codes 全套、manage、profile、admin/explainsql、3 個 batch-load、cbdb-table-maintenance、unidirectional-repair（⚠️ 只刪薄殼，`perform*` 全留）。**每刪一條 route 前，用三個方向各掃一次** `app/`、`resources/js/`、`tests/`：① **route name**（`route('x')`）、② **URI prefix**（`url('crowdsourcing/…')`、字串拼接——`CrowdsourcingController.php:183-184` 就是這型，route name grep 抓不到）、③ **controller action**。並把結果列進該 commit 的刪除清單。另外 `grep -rn "RouteName\|routeName" app/Http/Controllers` 找 `listRouteName()` 這類**回傳路由名字串**的分支
- **4c 認證與入口**：auth 4 頁、welcome（同時移除 4 個 Auth controller 與 `WelcomeController` 的 flag 分支）
- **4d flag 機制收尾**：刪 `config/migration_flags.php`、`migration_flag()`／`migration_flag_is_new()`、`Navigation::url()` 的 flag 參數與 `active.pages`／`active.patterns`、`HandleInertiaRequests::profileUrl()` 分支；改寫 §三第 15 欄列出的全部測試。
  ⚠️ **刪 config 前先掃「未知 key fallback」**：`config/migration_flags.php:37-104` 的每個已知頁面都有明文預設 `new`，所以 CI（`cp .env.example .env`，`.env.example` 無 `MIGRATION_FLAG_*`）**跑的就是 new 路徑**——`'default' => 'old'` 只影響**不在 config 裡的 key**。真正要找的是「`migration_flag_is_new('某個 config 沒列的 key')` 因而永遠回 false」的呼叫點：`grep -roE "migration_flag(_is_new)?\('[^']+'\)" app/ resources/` 取出所有 key，逐一比對 `config/migration_flags.php` 是否列出，對不上的先處理。
- 每個子環節都要同步做 §三 的第 12、15、16 欄（翻譯 key、測試、文檔）。

### 環節 5 — AdminLTE 實體下架（Phase 7）
- 刪 `resources/views/layouts/**`（6 檔）與 `resources/views/components/**` 中的 legacy 元件（逐一確認零引用）。
- 刪 `resources/js/{app.js,jquery-global.js,datatables.js,components/Select.vue}` + 3 支 legacy CSS；**保留 `resources/js/utils/*`、`chgis-map/`、`historical-maps/`**。
- `vite.config.js` 移除對應 input、`vue()` plugin、`vue` alias。
- `package.json` 移除 §三第 14 欄套件，`npm install` + `npm run build`。
- 改寫 `docs/ADMINLTE.md`、標註 `docs/ADMINLTE4_UPGRADE_FEASIBILITY.md` 已被取代。

### 環節 6 — 文檔與 env 收尾

#### 6a — 回退鍵陳述修正 ✅ **已完成（2026-09-14，與環節 4 無依賴，故先做）**

環節 3 之後「把 `MIGRATION_FLAG_*` 切回 `old` 就能回到 Blade」對已封路頁面**已不成立**，但多份文檔仍這樣寫。其中 `AGENTS.md` 與 `docs/CODES_SORT_FILTER_AUTH_GATE.md` 的版本是**安全陳述**——它們說「翻 flag 會重新暴露無門檻的深分頁排序查詢」，而真正會暴露的是 `LEGACY_PAGE_RETIREMENT=false`。任何人照舊文檔動用 kill switch 回退，就是在不知情的情況下打開那個查詢。已修：

- **權威來源**：`config/migration_flags.php` 的「不變量」段與 `view` 那一列（下游文檔都指過來這裡，不改上游等於沒改完）。
- `AGENTS.md` 高風險備忘與專案現況段；`docs/CODES_SORT_FILTER_AUTH_GATE.md` §2／§6／§7。
- `docs/REACT_INERTIA_MIGRATION_PLAN.md`：限縮前言管住「切換 = 導覽指向」與「回退保證」兩條（原本註解掛在第二條下面，讀者先撞到第一條），另修 §八與開頭里程碑。
- 🔴 **`docs/PERSON_PROPOSAL_PATHS.md` 與 `docs/APPROVAL_FLOWS.md`**（AGENTS.md 標為必讀）仍描述「flag=old 完整放行」這條**已隨環節 2 實體刪除**的路徑——最容易讓人照錯資訊做安全評估。
- `CHANGELOG.md` 兩條歷史條目加 `📌 後續` 註記（**加註不改寫**，避免舊條目說謊）。
- 測試護欄 `LegacyBladePageRetirementTest::migration_flags_no_longer_reopen_gated_legacy_pages()`：把 flag 遞迴翻成 `old`，斷言 7 條顯示頁仍 302、2 條寫入端仍 410，並以 `assertViewIs()` 證明kill switch 才是真的鑰匙。**否則那些文檔只是另一句會再過時的話。**

⚠️ **刻意沒有一併宣稱「flag 已全面失效」**：`auth.*` 與 `welcome` 的 flag 分支在 controller 內部、路由**未**封路，翻 flag 仍然會渲染 Blade（`tests/Feature/AuthPagesInertiaTest.php` 有四個綠測釘住）。第一版的措辭寫成全站級斷言，被 review 用那幾個測試證偽。

#### 6b — 其餘收尾（依賴環節 4／5）
- 更新 `AGENTS.md`、`README.md`、`CHANGELOG.md`、`docs/REACT_INERTIA_MIGRATION_PLAN.md`、`docs/REACT_MIGRATION_BACKLOG.md`、`docs/VIEWS.md`、`docs/CODES_SORT_FILTER_AUTH_GATE.md`。
- `.env.example` 加入 §三第 11 欄的註解段落。
- `CHANGELOG.md` 完整列出 §三之四 的 `.env` 清理清單（部署者依此在各機器手動清除）並註明需 `config:clear && config:cache`。
- ~~`docs/migration-specs/**` 22 份加「歷史存檔」抬頭~~ ✅ 已於 6a 完成。

### 環節 7（獨立，不阻塞前六個環節）— D 類缺口評估 ✅ **已完成（2026-09-14）**

分兩個 commit 執行：**7a**（三筆未詳人物守衛，`e9e8ba1f` / PR #1305）與 **7b**（孤兒清理 + 本節結論）。

#### 7a — 三筆「未詳人物守衛」缺口 ✅ 已修

| # | 缺口 | 結論與修法 |
|---|---|---|
| 7-U1 | 提案核准不經 mutation handler，繞過守衛 | ✅ 新增 `OperationsProposalController::blockUnknownPersonProposal()`，丟專屬 `UnknownPersonProposalException` 中止核准。**決定：不自動退回**——提案維持 pending、資料完全未動、理由 flash 給審核者。自動退回會替審核者做掉一個不可逆的決定，而這批提案數量有限。專屬例外類的存在理由是走 `Log::warning` 而非 `Log::error`：正常的業務拒絕不該污染告警通道 |
| 7-U2 | `Duplicate_Collateral_Info()` 複製歷史 0 髒列 | ✅ 新增 `shouldSkipUnknownPersonRelationRow()`。**決定：跳過並記 warning，不整批拒絕**——與同函式的異體字去重器一致；複製是便利功能，少複製一條本來就壞掉的列比整個功能永久失敗好，原始髒列不動（D6）。四個迴圈各查「沒有被改寫成新 id 的那一側」。另在入口擋掉「複製未詳人物本身」（`$id = 0` 會讓兩個鏡像迴圈撈出**全庫**髒邊） |
| 7-U3 | Possession／Posting 的 update 路徑無擁有者守衛 | ✅ 補在 3 條入口（覆寫 `handleAfterVariantReset()` + 兩條「僅改地址」快捷路徑）。**順帶修正本計畫的事實錯誤**：原文寫「legacy 有」，實查 legacy `BasicInformationPossessionController`／`OfficesController` **兩側都沒擋**（grep 已刪版本的「未詳」得 0 筆）——這不是遷移漏搬，是 v2 自己補到一半 |

順帶收斂：判定抽成 `App\Support\UnknownPerson::isUnknown()`（原本四份拷貝）；`PossessionCreateHandler`／`PostingCreateHandler` 原本只擋 `0`、`-999` 可直接落庫成 `c_personid = -999`，一併修掉；修掉 `applyKinshipProposal`／`applyAssocProposal` 的 `0 ?? x` 陷阱。

⚠️ **`isUnknown()` 的判定必須與「寫入時實際發生的轉型」一致**，不是與「型別看起來對不對」一致。中途改成「只接受嚴格整數語義」反而**放行**了 `'0e10'`／`'0.0'`／`'-999.0'`——它們寫進 INTEGER 欄落地就是 `0` 和 `-999`（已實測）。教訓鎖在 `tests/Unit/UnknownPersonTest.php` 的 24 案例行為表。

#### 7b — 孤兒清理 ✅ 已做

| # | 項目 | 結論 |
|---|---|---|
| 7-O1 | `BiogMainRepository` 的別名方法成為孤兒 | ✅ **刪除**。實查 `altnameById`／`altnameStoreById`／`altnameUpdateById`／`altnameDeleteById` 零非測試呼叫者（無動態派發；ALTNAME_DATA 的提案核准走 mutation handler，`applyProposal()` 只有 KIN_DATA／ASSOC_DATA 兩條特例分支）。連帶刪除只被這四個方法呼叫的 `parseAltnameId()`。`VariantReplaceHookCoverageTest` 的 `BiogMainRepository` 記數 **8 → 6**（三處）。<br>**計畫原文的筆誤**：`altnameDestroyById` 實名為 `altnameDeleteById`。<br>順帶清掉 `FormUrlEncodingTest`／`NameSearchIndexAutoSyncTest` 裡已成為死重的 `char_variant_map` fixture——它們的註解指名的消費者（`BasicInformationAltnamesController` 與這批 repository 方法）都已不存在，且經驗證移除後兩檔仍全綠 |
| 7-T1 | `biogmains` 翻譯群組孤兒 key | 🟡 **維持延後**。約 301／414 個查無引用，但**動態組鍵 grep 不到**，逐 key 人工確認成本高、收益為零（孤兒 key 無執行期影響）。**改為留到環節 5 之後**：AdminLTE 實體下架完成、`biogmains` 群組確定沒有任何消費者時**一次整組刪除**（zh-TW／en 同步），而不是現在逐 key 猜 |

#### D 類結論

| # | 項目 | 結論 |
|---|---|---|
| D-5a | `saveas`／`Duplicate_Collateral_Info` | 🔵 **保留，移出本計畫**。兩者都是 **GET 端點卻執行寫入**，由 React `BasicInfoEditor` 用 `<a href>` 直接導航（`BasicInformationController.php:317-318` 產 payload → `EditV2.tsx:62-63`；`TabContentLoader.tsx:233-234` 另有一組硬編碼 URL）。搬進 v2 要同時：① 改成 POST（前端從 `<a>` 換成帶 CSRF 的請求）；② 新增回傳新 personid 的 `/app` 端點（現行靠 `redirect()->route('app.basicinformation.edit')`）；③ 帶走 `Duplicate_Collateral_Info()` 的 8 張子表複製邏輯與**兩個守衛**（異體字歸一去重、未詳人物髒列跳過），否則是資料完整性退化。**它們不是「重複的 Blade 頁」，是「沒有 React 版的活功能」**——難度中，另開任務 |
| D-5b | `basicinformation.destroy` | 🟡 **可下架但有前置，排入環節 4b**。<br>**route name 零呼叫者**（`grep` 只命中 `LegacyPersonRouteRetirementTest.php:158` 的可路由性斷言），但 🔴 **`resources/js/inertia/components/PersonBrowser/BasicInfoView.tsx:192` 還留著一個休眠的 `POST /basicinformation/${personId}` + `_method=DELETE` 表單**，由 `handleDelete()` submit。它是用**模板字串拼出來的 URL，route name grep 抓不到**——正是 §環節 4b「三個方向各掃一次」的方向 ②。<br>**目前不可達**：三個使用點裡 `Show.tsx` 傳 `canEdit={false}`、`Edit.tsx` 傳 `hideDelete`，唯一沒傳 `hideDelete` 的 `TabContentLoader.tsx:248` 只在 `personId == null` 時才走到，而 `handleDelete()` 對 `!personId` 會 early return。**但它是潛伏的**：任何人日後在有 personId 的情境用 `canEdit` 且不傳 `hideDelete`，它就活了。<br>**環節 4b 前置**：先移除那個表單與按鈕（或改接 `api.v2.delete.web`）＋ `npm run build`，**之後**才能刪路由與 controller method。<br>React 的實際刪除走 `api.v2.delete.web`。`API.md` 描述的「人物主檔軟刪除」指的是 v2 那條，不是這條。⚠️ 也要確認 v2 軟刪除涵蓋**眾包分支**——這條 legacy 方法對眾包用戶另走 `operations` op_type 4，語義與 v2 不完全相同 |
| D-1 | `maps/index.blade.php` | 🔵 **不是 legacy 頁，移出本計畫**。它正是 `app/maps`（`app.maps.index` → `HistoricalMapsController@index`）**現在服役**的頁面，所有 `maps/*` 舊 URL 早就由 `legacyRedirect()` 導向它。它是**獨立 HTML**（自帶 `<!DOCTYPE html>`、不 `@extends` 任何 AdminLTE layout），也是 `resources/js/historical-maps/` 的唯一消費者 ⇒ **環節 5 刪 AdminLTE 不會波及它**。「是否 React 化」是純前端重寫題（Leaflet + 自訂控制面板），降級為一般 backlog |

#### 附帶發現 → 環節 4 前置清單

- 🔴 `resources/views/codes/edit.blade.php:197` 的 JS 產 `/basicinformation/${id}/texts`，該路由已在**環節 2 實體刪除** ⇒ **現在是死連結**（只在 `LEGACY_PAGE_RETIREMENT=false` 回退時可達）。環節 4b 刪 codes Blade 時別把它當成「還能用的連結」。
- `resources/views/manage/merge-preview.blade.php:75,120,382,404` 與 `resources/views/layouts/header-v3.blade.php:9` 也指向 legacy `basicinformation/*`（現為 302 shim，非死連結，但同屬環節 4 的清理範圍）。
- 🔴 **方向 ② 的教訓（本環節再次踩到）**：`basicinformation.destroy` 一度被我與 review agent 雙雙判定為「全庫零呼叫者」，因為兩邊都只查了 route name；實際上 `BasicInfoView.tsx:192` 有一個用**模板字串**拼出 URL 的休眠 DELETE 表單（由 codex 查出）。**環節 4b 的「三個方向各掃一次」不是形式**——刪任何 legacy 路由前，方向 ②（URI 字串／`url()`／模板字串拼接）必須在 `resources/js/**` 也掃一遍，不是只掃 `app/`。
- **React 元件的 legacy URL 預設值（prop fallback）也要掃**：它們在現行呼叫點都被覆寫、所以不會出事，但刪路由時同樣會變死連結。已知三處：`BasicInfoEditor.tsx:71` 的 `indexUrl = '/basicinformation'`、`PersonBanner.tsx:88` 的 `/admin/audit-logs`、以及 `chgis-map/app.js:497` 的 `/basicinformation/{id}/map-points`（**後者是刻意保留的 CHGIS 端點、有測試保護，不要誤刪**）。

---

## 四之一、難度重估（review 後修正）

| 項目 | 原評估 | 修正後 | 理由 |
|---|---|---|---|
| 環節 2（A-20 下架） | 低 | **中高** | 閘門只擋 HTTP 入口，擋不住 React props 退化、React 主動呼叫 legacy 端點、`basicinformation.index` 路由名依賴三件事 |
| D-3 `_chgis_map_assets` 搬家 | 低（S） | **中** | 它不是純 partial：含 `route('basicinformation.index')`、`@push`／`@stack` 配對、`ChgisMapManager` 容器解析、`@vite` 入口。搬完**必須真的開一個 React 人物頁點 place-link 驗證**，不能只看 build 綠 |
| §三第 12 欄（翻譯 key） | 未標（語氣像機械作業） | **中高，且最易出錯** | 動態 key grep 不到；策略已改為「只刪 `biogmains.*` 前綴」 |
| 環節 4b（只刪薄殼） | 未標 | **中** | 難的不是刪薄殼，是 `listRouteName()` 這類**回傳路由名字串**的分支 grep 不到 |
| §三第 15 欄（測試） | 一欄 | **高——全計畫最大工作量** | 10 個 flag 相依檔 ＋ 15 個 `useLegacyPersonForms()` 檔 ＋ 掛鉤記數清冊 ＋ 4 個需先移植的完整性測試。已獨立成**環節 1.5** |
| 環節 5（AdminLTE 下架） | 低—中 | **維持低—中** | 這部分原評估正確；補充 `select2` 與 `@fortawesome` 兩個細節即可 |

---

## 五、風險與回退

| 風險 | 影響 | 緩解 |
|---|---|---|
| **刪除後失去 flag 回退能力** | React 頁若出事無法即時切回 | 環節 3 的觀察期；環節 4 拆成 4 個小 commit，各自可獨立 revert |
| **刪了 flag key 但仍有程式碼在讀** | 靜默回退 `default='old'` → `route('已刪的舊路由')` → **500** | 每個環節動 config 前先 `grep -rn "migration_flag" app/ resources/ routes/ config/` 清零 |
| **誤刪共用 partial `_chgis_map_assets`** | 所有 React 頁 `@include` 失敗 → 500 | 環節 2 **第一步**就先搬遷並改 `inertia.blade.php`，獨立 commit 驗證 |
| **誤刪 `resources/js/utils/*`** | React 端 build 失敗 | 環節 5 前先 `grep -rn "utils/" resources/js/inertia` |
| **整檔刪翻譯群組** | React 頁出現 raw translation key | 一律 key-level grep（含 `resources/js/inertia/**`），`zh-TW`／`en` 同步 |
| **`.env` 殘留孤兒變數** | 無功能影響，但永久誤導維護者 | CHANGELOG 列清單 + `.env.example` 加註解 |
| **外部書籤／爬蟲指向舊 URL** | 404 | 環節 3 先上 **302**（觀察期），確定永久下架後才在環節 4 升 301；**不要直接刪路由** |
| **CodesController 誤刪 `perform*` 或共用 proposal 方法** | 代碼表寫入端整組壞掉 | 只刪「呼叫 `perform*()` 的薄殼」；`proposalUpdateExisting`／`proposalCancel`／`export` **不可刪** |
| 🔴 **flag 退化的「無聲版本」**：flag key 刪了，讀它的地方不是 `route()` 而是 Inertia prop | **不會 500、測試不會紅**——React 的 13 個編輯器悄悄退回唯讀／退回指向 404 的 legacy 按鈕 | §三第 3b＋13b 欄：後端 props 與前端 fallback 分支**同 commit** 移除；驗收一律加「人工開頁確認編輯器還在」 |
| 🔴 **用 `Route::redirect` 封寫入端** | `Route::redirect` 是 `any()`；POST 被 redirect 降級成 GET、**body 靜默丟失**（使用者以為存了，其實沒存） | 環節 3 改成「顯示頁 GET→302、legacy 寫入端→410」，用 closure／小 middleware 實作，不用 `Route::redirect` |
| 🔴 **`route('basicinformation.index')` 被每個 React 頁執行** | `_chgis_map_assets:28` 用它當 `pointsUrlBase`；改名或移除 ⇒ 全站 React 500 | 計畫期間該路由名與 URI 凍結；環節 2 commit 1 先拆掉這個依賴 |
| **動 `login`／`register` 路由層** | `route('login')` 被 `Handler.php:97` 與 `Authenticate` middleware 依賴，名字一掉＝全站未登入請求 500 | A-18 只改 controller 內的 `return view(...)` 分支，**路由層一律不碰** |
| 🔴 **按 path prefix 套封路規則** | 誤封 `crowdsourcing/{id}/confirm｜reject`（GET 寫入端）、operations action URLs ⇒ React 按鈕全壞 | 環節 3 逐條列 route manifest；產出「不得 redirect／不得刪除」清單，每條含 *路由定義＋payload 製造點＋前端消費點* |
| 🔴 **觀察期用 301** | 301 被 client／CDN 永久快取，revert 後仍有人被導走 ⇒ 「完全可逆」不成立 | 觀察期一律 302／307；確定永久下架後才在環節 4 升 301 |
| **刪 `useLegacyPersonForms()` 直接下手** | 15 個測試檔 `Call to undefined method`；其中 4 個是資料完整性憑證 | 環節 1.5 先分流、先補 v2 等價測試 |
| **AdminBatchLoad*Controller 的 `listRouteName()` 指向已刪路由** | 匯入完成後 redirect 500 | 環節 4b 同步收斂該方法 |

**回退方式**：每個環節一個獨立 commit，`git revert` 即可。環節 4 之後若要回到 Blade，只能靠 revert，**不再有 flag 級即時回退**——這是本計畫最重要的取捨，需在環節 3 觀察期滿後由人明確確認再推進。

---

## 六、驗收標準（全部環節完成後）

- [ ] `find resources/views -name '*.blade.php' | wc -l` ≈ **4**（`inertia`、`cbdbapi/person`、`maps/index`、`_chgis_map_assets`——`_place_link` 隨 A-20 刪除，不在保留之列）。React/Inertia **不消費任何 blade component**，`components/` 應可清空
- [ ] `grep -rn "migration_flag" app/ config/ routes/ resources/ tests/` 零命中
- [ ] `config/migration_flags.php` 不存在；`.env.example` 已加說明註解
- [ ] `package.json` 已無 `admin-lte`／`jquery`／`vue`／`datatables.net*`／Select2 主題
- [ ] `./vendor/bin/phpunit` 全綠（含 `--filter VariantReplaceHookCoverage`）、`npm run build` 綠、`npx vitest run` 綠
- [ ] **CI 實際跑的是 `npm run prod`（= `vite build`）與 `npm run test`（= `vitest run`）**——環節 5 動過 `vite.config.js` 後要確認這兩條路徑也綠
- [ ] `npm ls select2` 確認 `select2` 已隨 `admin-lte` 一起移除；`@fortawesome/fontawesome-free` **仍在**（`inertia.css:23` 需要）
- [ ] `grep -rnE "['\"\`]/(basicinformation|codes|operations|manage|view|dashboard|profile|crowdsourcing|admin|welcome)" resources/js/inertia | grep -v '/app/'` 只剩 `/home` 與 D-5 三條
- [ ] `./vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php` clean（**先清 cache**）
- [ ] 手動 smoke：`/`、`/login`、`/app/dashboard`、`/app/codes`、`/app/basicinformation/{id}` 各分頁與 13 個編輯器、`/app/operations`、`/app/manage`、`/app/query-playground`、`/app/maps`、CHGIS 浮出地圖
- [ ] 舊 URL 導向落點正確（抽查 10 條，含 query string）；觀察期內應為 **302**，永久下架後才是 301
- [ ] `AGENTS.md` 不再宣稱「翻回 `old` 即可回退」；`docs/CODES_SORT_FILTER_AUTH_GATE.md` 結論已更新

---

## 七、統計摘要

| 分類 | 檔數 | 行數（約） |
|---|---|---|
| A 重複實作（可下架，含 D-6 的 7 個元件與 `_place_link`） | 91 | ~14,600 |
| B 死碼（可直接刪） | 3 | 46 |
| C 共用／非重複（保留） | 4 | ~1,250 |
| D 僅 Blade（保留，另議：layouts 6 檔 + maps/index） | 7 | 940 |
| **合計** | **105** | **16,809** |

前端另可移除：`resources/js/app.js`（1,283 行）、`jquery-global.js`、`datatables.js`、`components/Select.vue`、3 支 legacy CSS，以及 8–10 個 npm 套件。

> 註：A／B／C／D 的檔數含 partial 與元件，個別檔案可能同時被多個頁面引用（例如 D-2／D-3 已計入 C、D-6 的 7 個元件計入 A）；四列相加為 105。執行時一律以**每個環節重跑的 grep 結果**為準，本表僅供規模評估。
