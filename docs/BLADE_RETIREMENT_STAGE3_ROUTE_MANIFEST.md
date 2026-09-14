# 環節 3 Route Manifest（A-1…A-17 封路清單）

> 建立日期：2026-09-14　·　[Blade 下架計畫](./BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md) 環節 3 的**第一個交付物**
>
> 計畫明文要求：**「環節 3 必須逐條列 route manifest，禁止按功能名稱或 path prefix 套規則。」**
> 理由不是形式主義——環節 2 的 review 已經證明，按 prefix 套規則會直接命中
> **React 正在呼叫的 action endpoint**（其中 `crowdsourcing/{id}/confirm` 還是個 GET 動詞的寫入端）。

## 產法

```bash
php artisan route:list --json
```

逐條比對三件事：
1. 該 `controller@method` 是否**同時**掛在某條 `app/*` 路由上（＝新舊共用，動它會一起壞）；
2. 該路由名或 URI 是否出現在**PHP 端產生給 React 的 payload** 裡（`grep -rn "route('<名>'" app/`）——這類 grep `resources/js` 抓不到；
3. 該 URI 是否被 React 硬編碼（`grep -rn "'/<uri>" resources/js/inertia`）。

## 分類與處置

| 處置 | 條數 | 說明 |
|---|---|---|
| 🟢 **GET → 302** | 22 | 純顯示頁，導向 `/app` 對應頁並保留 query string |
| 🟠 **非 GET → 410** | 11 | legacy 寫入端，無 React 對應 |
| ⚪ **空方法 → 410** | 2 | legacy 顯示路由但 controller 方法本來就是空的 |
| 🔴 **不動** | 19 | 新舊共用方法，或 React 正在呼叫的 action endpoint |
| ⚫ **不在本環節** | 11 | 同一批 controller 上但不屬 A-1…A-17 的頁面路由 |
| **合計** | **65** | 該批 controller 上的全部 legacy（非 `app/*`）路由 |

> 封路數 = 22 + 11 + 2 = **35**（與 `LegacyBladePageRetirementTest` 寫死的清單逐條吻合）。
> 35 + 19 + 11 = 65，與 `route:list` 實測總數相符——**讀者可以自行驗證有沒有漏**。

---

## 🔴 不動（19 條）

**動了就壞。** 這些不是「legacy 殘留」，是現行系統的一部分。

### A. 真的「動了就壞」（1 條）

| Method | URI | 為什麼 |
|---|---|---|
| PATCH | `codes/{table_name}/proposals/{operation}` | `resources/views/codes/proposal-edit.blade.php` 的表單 action 就是它，而**那個 Blade 頁是 React operations 頁的連結目標**（見下方 B 的 `codes.proposals.edit`）。一鎖三條 |

### B. 刻意留到環節 4（6 條）——封了不會壞，但收斂還沒做

這 6 條的 `app/*` 孿生路由**都存在**，React 打的是 `app/*` 那條，legacy 這條是另一個 route
object，封它不影響 React。之所以先留著：

| Method | URI | 為什麼先留 |
|---|---|---|
| POST | `admin/batch-load-book-titles` | `listRouteName()`（`AdminBatchLoadBookTitlesController:86`）依 `$request->is('app/*')` 回傳 redirect 目標；legacy POST 會走到 legacy 分支，而該 GET 已被封 ⇒ 多一跳、**匯入結果的 flash 被 session 老化掉**。要封它得先收斂 `listRouteName()`，屬環節 4b |
| POST | `admin/batch-load-book-titles/undo` | 同上 |
| POST | `admin/batch-load-book-titles/update-pinyin` | 同上 |
| POST | `admin/batch-load-offices` | 同上（`AdminBatchLoadOfficesController:58`） |
| POST | `admin/batch-load-social-institutes` | 同上 |
| DELETE | `codes/{table_name}/proposals/{operation}` | `app.codes.proposals.cancel` 存在；React ops 頁用的是 `operations/{op}/cancel`（另一個 controller），legacy 這條實際無人呼叫。與 PATCH 同 URI，一起留著避免半封半留造成混淆 |

> 📌 **可達性低但非零**：legacy 表單頁已被 302，只有「已經開著舊頁的分頁」才送得出這些 POST。
> 這也是它們排在環節 4b、而不是現在硬封的理由——現在封掉只會讓那種情境從「flash 消失」
> 變成「410 錯誤頁」，對使用者更糟。

### C. React 正在呼叫的 action endpoint（12 條）——PHP payload 或前端硬編碼

| Method | URI | 誰在用 |
|---|---|---|
| GET | `codes/{table_name}/export` | `CodesController::appShow()` 硬編碼 `'/codes/'.$table.'/export'` → `Pages/Codes/Show.tsx` 的下載連結；**`app/codes/{table}/export` 路由不存在** |
| GET | `codes/{table_name}/proposals/{operation}/edit` | `OperationsController.php:1104` 的 `edit_proposal` payload → `Pages/Admin/Operations/Index.tsx` 的「修改提案」。**無 `Route::has()` 保護，刪掉會在產 payload 時拋 `RouteNotFoundException` ⇒ `/app/operations` 整頁 500**。<br>📌 **修法（環節 4 前置，一行）**：`app.codes.proposals.edit` → `CodesController@appProposalEdit`（React 版 `Codes/ProposalEdit`）**早就存在**；把 `OperationsController.php:1104` 改指它，就能同時解鎖本條與上方 A、B 的兩條 `codes/{t}/proposals/{op}` |
| GET | `crowdsourcing/{id}/confirm` | `CrowdsourcingController.php:183` 的 `confirm_url` → `Pages/Admin/Crowdsourcing/Index.tsx` 的 `<a href>`。🔴 **GET 動詞的寫入端**——按「GET 一律 302」的規則會直接命中它 |
| GET | `crowdsourcing/{id}/reject` | 同上（`:184` 的 `reject_url`） |
| POST | `operations/{operation}/approve` | `OperationsController.php:1045` 的 `urls.approve` |
| POST | `operations/{operation}/reject` | `:1046` 的 `urls.reject` |
| DELETE | `operations/{operation}/cancel` | `:1051` 的 `urls.cancel_proposal` |
| POST | `operations/{operation}/restore` | `:1044` 的 `urls.restore` |
| POST | `admin/cbdb-table-maintenance/rebuild` | `CbdbTableMaintenanceController::appIndex()` 的 `urls.rebuild` |
| GET | `admin/cbdb-table-maintenance/progress/{taskId}` | 同上的 `urls.progress_base`（前端自行接 taskId） |
| POST | `admin/unidirectional-relationship-repair/kinship` | `UnidirectionalRelationshipRepairController::appIndex()` 的 `urls.kinship` |
| POST | `admin/unidirectional-relationship-repair/assoc` | 同上的 `urls.assoc` |

---

## ⚫ 不在本環節（11 條）

同一批 controller 上、但不屬 A-1…A-17 那批頁面的 legacy 路由。列出來是為了讓 65 這個總數可被驗證——**不是遺漏**。

| Method | URI | 為什麼不在範圍 |
|---|---|---|
| POST | `api/operations/add` | v1 token API，仍在服役（AGENTS.md §1.3 點名的既存寫入路徑之一），不屬「Blade 頁面」 |
| POST | `api/operations/update` | 同上 |
| POST | `api/operations/delete` | 同上 |
| GET/POST | `api/operations/token` | 同上 |
| GET | `query-playground` | 本就無 flag、已硬導向 `/app/query-playground`（`QueryPlaygroundController@index` 內部 redirect），不需封路 |
| POST | `query-playground/run` | Query Playground 的**共用後端 API**，React 版直接呼叫（AGENTS.md §3） |
| POST | `query-playground/schema` | 同上 |
| POST | `query-playground/generate-from-nl` | 同上 |
| POST | `query-playground/generate-from-nl-stream` | 同上 |
| POST | `query-playground/answer-from-nl` | 同上 |
| POST | `query-playground/answer-from-nl-stream` | 同上 |

---

## 🟢 GET → 302 導向（22 條）

導向目標一律保留 query string。**觀察期用 302 不用 301**（301 會被瀏覽器／CDN 長期快取，`git revert` 只還原伺服器）。

| URI | → 導向目標 |
|---|---|
| `dashboard` | `app.dashboard` |
| `profile` | `app.profile.edit` |
| `codes` | `app.codes.index` |
| `codes/{table_name}` | `app.codes.show` |
| `codes/{table_name}/create` | `app.codes.create` |
| `codes/{table_name}/{id}/edit` | `app.codes.edit` |
| `operations` | `app.operations.index` |
| `manage` | `app.manage.index` |
| `manage/{manage}/edit` | `app.manage.edit` |
| `merge-preview`（**僅 GET**） | `app.merge-preview.index` |
| `crowdsourcing` | `app.crowdsourcing.index` |
| `view` | `app.view.index` |
| `view/{key}` | `app.view.show` |
| `admin/audit-logs` | `app.admin.audit-logs` |
| `admin/ai-fill-logs` | `app.admin.ai-fill-logs` |
| `admin/explainsql`（**僅 GET**） | `app.admin.explainsql` |
| `admin/batch-load-book-titles`（**僅 GET**） | `app.admin.batch-load-book-titles` |
| `admin/batch-load-offices`（**僅 GET**） | `app.admin.batch-load-offices` |
| `admin/batch-load-social-institutes`（**僅 GET**） | `app.admin.batch-load-social-institutes` |
| `admin/cbdb-table-maintenance`（**僅 GET**） | `app.admin.cbdb-table-maintenance` |
| `admin/unidirectional-relationship-repair`（**僅 GET**） | `app.admin.unidirectional-relationship-repair` |
| `query-playground/nl-query-logs` | `app.query-playground.nl-query-logs` |

> ⚠️ 標「僅 GET」的 URI **同時掛著 POST**（寫入端），而那些 POST 分別落在「不動」或「410」類。
> 這正是不能按 URI prefix 套規則的原因——**同一個 URI 的不同 method 處置不同**。

---

## 🟠 非 GET → 410 Gone（11 條）

legacy 寫入端，且 React 有各自的對應端點（`app/*` 或 `/api/v2/*`）。

| Method | URI | Action | React 對應 |
|---|---|---|---|
| POST | `codes/{table_name}` | `CodesController@store` | `app.codes.store` |
| PUT/PATCH | `codes/{table_name}/{id}` | `@update` | `app.codes.update` |
| DELETE | `codes/{table_name}/{id}` | `@destroy` | `app.codes.destroy` |
| POST | `codes/{table_name}/proposal` | `@proposalStore` | `app.codes.propose.store` |
| POST/PATCH | `codes/{table_name}/{id}/proposal` | `@proposalUpdate` | `app.codes.propose.update` |
| POST | `manage` | `ManagementController@store` | （無；方法體為空） |
| PUT/PATCH | `manage/{manage}` | `@update` | `app.manage.update` |
| DELETE | `manage/{manage}` | `@destroy` | （無；方法體為空） |
| PATCH | `profile` | `UserProfileController@update` | `app.profile.update` |
| POST | `admin/explainsql` | `AdminExplainSqlController@explain` | `app.admin.explainsql`（POST） |
| POST | `merge-preview` | `MergePreviewController@index`（GET/POST 同一方法的 POST 半邊） | `app.merge-preview.index` |

---

## ⚪ 空方法 → 410（2 條）

`ManagementController` 的 `create`／`show` 方法體本來就是空的（`//`），命中會回 200 空白頁。

| Method | URI | Action |
|---|---|---|
| GET | `manage/create` | `ManagementController@create`（空） |
| GET | `manage/{manage}` | `ManagementController@show`（空） |

---

## kill switch：環節 3「可逆」的實際兌現方式

封路由 `config/legacy_page_retirement.php` 的 `enabled` 總開關控制（env：`LEGACY_PAGE_RETIREMENT`，預設 `true`）。

觀察期間若發現某個 React 頁有問題：

```bash
# .env
LEGACY_PAGE_RETIREMENT=false
php artisan config:clear && php artisan config:cache
```

legacy 頁**立刻復活**——不需重新部署、不需 `git revert`。這正是環節 4 實體刪除之後就再也沒有的能力，所以它本身有測試守著（`LegacyBladePageRetirementTest::the_kill_switch_restores_the_legacy_pages` 驗 middleware 讓開，`InertiaViewTableTest::test_kill_switch_restores_the_legacy_view_page` 以完整 fixtures 驗**legacy 頁真的渲染**）。

### 為什麼需要這個開關（執行中發現的）

原本以為「保留 Blade 視圖與 controller 不動」就足以讓環節 3 可逆。實作後全量測試出現 **186 個失敗、橫跨 22 個測試類**——因為**測試是打路由的**，封了路由，那些驗 legacy Blade 行為的測試就一起斷。

這些測試不該被刪：環節 3 並沒有刪掉那些頁面，它們仍然部署著、仍然能被叫回來，覆蓋在觀察期內依然有意義。真正該做的是讓它們**局部關閉封路**——`TestCase::useLegacyBladePages()`（比照已下架的 `useLegacyPersonForms()` 慣例），22 個測試類已加上。

封路本身的行為由 `LegacyBladePageRetirementTest` 驗證，該檔**不** opt-out。

> 📌 環節 4 實體刪除 legacy 頁時，這個開關、`useLegacyBladePages()` 以及 22 個 opt-out 都要一併移除——屆時沒有可以復活的對象，留著只會讓人誤以為還能回退。同時那 22 個測試類要做環節 1.5 那樣的逐測試分流。

## 實作方式

**不用 `Route::redirect()`**：它底層是 `Route::any()`，會把同 URI 的所有 method 一起接管——而本 manifest 已經證明同一 URI 的不同 method 處置不同。改以逐條 `Route::get(...)` 閉包 + 專用 middleware。

**不得整批套用**：每一條都按上表逐條處置。

### 三個行為 caveat

1. **controller middleware 排在 route middleware 之後**，所以封路先跑：未登入的 legacy 非 GET
   請求現在拿到 **410 而不是 302 導向 `/login`**（例 `PUT /manage/1`）。不是安全問題
   （410 不洩漏任何資訊），但與封路前不同。
2. **`VerifyCsrfToken` 屬 `web` group、跑在封路之前**，所以真實世界未帶 token 的 legacy POST
   會先拿到 **419** 而不是 410（測試環境跳過 CSRF 才看得到 410）。與已移除的
   `LegacyBladeFormGate` 行為一致，非退化。
3. **fail-open 但不靜默**：導向目標路由不存在時放行原 legacy 頁（過渡期讓使用者看到舊頁優於
   500），但會記一筆 `Log::warning`——否則日後誰改了 `app.*` 路由名，production 就會無聲
   復活一個 Blade 頁。

## 環節 4 的前置

### 測試分流（比照環節 1.5）

`./vendor/bin/phpunit --group legacy-parity` 目前圈出 **309 個測試**（22 個檔，其中 19 個 class 級、3 個 method 級 opt-out）。環節 4 實體刪除 legacy 頁時，這批要做環節 1.5 那樣的逐測試分流：哪些改測 React 版、哪些直接刪。

分流時**已知的覆蓋缺口**（現在只靠 legacy 測試守，刪掉就沒了）：

| 行為 | 現在誰在守 | React 端有嗎 |
|---|---|---|
| `PATCH /profile` 的授權（未登入／未啟用） | `UserProfileTest`（opt-out） | ❌ `PATCH /app/profile` 無等價授權回歸 |
| `PUT /manage/{id}` 的授權 | `SecurityAuditLogTest`／`InactiveAccountAccessTest`（opt-out） | ❌ `PUT /app/manage/{id}` 無等價授權回歸 |
| legacy `codes` 的 store／update／destroy 稽核與異體字 | `CodesControllerTest`／`CodesVariantReplacementTest`（opt-out） | 部分有（`ApiV2MutateCodeTables*`），需逐項對照 |

> 這三項**不是**環節 3 造成的——它們一直只有 legacy 覆蓋。列在這裡是為了讓環節 4 不會在
> 「刪掉 opt-out」時無聲失去它們（環節 2 就踩過一次同型的坑）。

### 其餘前置

觀察期（建議 1–2 週）結束、確認無書籤／外部連結損失後，才進環節 4 的實體刪除。屆時：
- 302 可升級為 301；
- 「不動」那 19 條要**逐條處理**：共用方法的 legacy 路由可刪（保留 `app/*` 那條）；React 正在呼叫的要先把 payload 改指 `app/*` 端點（或新建）才能刪。
