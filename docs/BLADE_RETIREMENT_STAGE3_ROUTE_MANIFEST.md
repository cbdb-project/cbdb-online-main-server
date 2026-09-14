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
| 🔴 **不動** | 19 | 新舊共用方法，或 React 正在呼叫的 action endpoint |
| 🟢 **GET → 302** | 22 | 純顯示頁，導向 `/app` 對應頁並保留 query string |
| 🟠 **非 GET → 410** | 11 | legacy 寫入端，無 React 對應 |
| ⚪ **空方法 → 410** | 2 | legacy 顯示路由但 controller 方法本來就是空的 |

---

## 🔴 不動（19 條）

**動了就壞。** 這些不是「legacy 殘留」，是現行系統的一部分。

### 新舊共用同一個 controller method（`app/*` 路由指向同一方法）

| Method | URI | Action |
|---|---|---|
| POST | `admin/batch-load-book-titles` | `AdminBatchLoadBookTitlesController@store` |
| POST | `admin/batch-load-book-titles/undo` | `@undo` |
| POST | `admin/batch-load-book-titles/update-pinyin` | `@updatePinyin` |
| POST | `admin/batch-load-offices` | `AdminBatchLoadOfficesController@store` |
| POST | `admin/batch-load-social-institutes` | `AdminBatchLoadSocialInstitutesController@store` |
| PATCH | `codes/{table_name}/proposals/{operation}` | `CodesController@proposalUpdateExisting` |
| DELETE | `codes/{table_name}/proposals/{operation}` | `CodesController@proposalCancel` |

> ⚠️ 這幾個 batch-load 的 `store`／`undo`／`updatePinyin` 還會用 `listRouteName()` 依
> `$request->is('app/*')` 決定 redirect 目標——**封掉 legacy GET 之後那個分支仍會被 legacy POST
> 走到**，屬環節 4b 的收斂項，不在本環節。

### React 正在呼叫的 action endpoint（PHP payload 或前端硬編碼）

| Method | URI | 誰在用 |
|---|---|---|
| GET | `codes/{table_name}/export` | `CodesController::appShow()` 硬編碼 `'/codes/'.$table.'/export'` → `Pages/Codes/Show.tsx` 的下載連結；**`app/codes/{table}/export` 路由不存在** |
| GET | `codes/{table_name}/proposals/{operation}/edit` | `OperationsController.php:1104` 的 `edit_proposal` payload → `Pages/Admin/Operations/Index.tsx` 的「修改提案」。**無 `Route::has()` 保護，刪掉會在產 payload 時拋 `RouteNotFoundException` ⇒ `/app/operations` 整頁 500** |
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

legacy 頁**立刻復活**——不需重新部署、不需 `git revert`。這正是環節 4 實體刪除之後就再也沒有的能力，所以它本身有測試守著（`LegacyBladePageRetirementTest::the_kill_switch_restores_the_legacy_pages`）。

### 為什麼需要這個開關（執行中發現的）

原本以為「保留 Blade 視圖與 controller 不動」就足以讓環節 3 可逆。實作後全量測試出現 **186 個失敗、橫跨 22 個測試類**——因為**測試是打路由的**，封了路由，那些驗 legacy Blade 行為的測試就一起斷。

這些測試不該被刪：環節 3 並沒有刪掉那些頁面，它們仍然部署著、仍然能被叫回來，覆蓋在觀察期內依然有意義。真正該做的是讓它們**局部關閉封路**——`TestCase::useLegacyBladePages()`（比照已下架的 `useLegacyPersonForms()` 慣例），22 個測試類已加上。

封路本身的行為由 `LegacyBladePageRetirementTest` 驗證，該檔**不** opt-out。

> 📌 環節 4 實體刪除 legacy 頁時，這個開關、`useLegacyBladePages()` 以及 22 個 opt-out 都要一併移除——屆時沒有可以復活的對象，留著只會讓人誤以為還能回退。同時那 22 個測試類要做環節 1.5 那樣的逐測試分流。

## 實作方式

**不用 `Route::redirect()`**：它底層是 `Route::any()`，會把同 URI 的所有 method 一起接管——而本 manifest 已經證明同一 URI 的不同 method 處置不同。改以逐條 `Route::get(...)` 閉包 + 專用 middleware。

**不得整批套用**：每一條都按上表逐條處置。

## 環節 4 的前置

觀察期（建議 1–2 週）結束、確認無書籤／外部連結損失後，才進環節 4 的實體刪除。屆時：
- 302 可升級為 301；
- 「不動」那 19 條要**逐條處理**：共用方法的 legacy 路由可刪（保留 `app/*` 那條）；React 正在呼叫的要先把 payload 改指 `app/*` 端點（或新建）才能刪。
