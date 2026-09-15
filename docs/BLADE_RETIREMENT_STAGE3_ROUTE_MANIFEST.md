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

## 🔴 現況（2026-09-15，環節 4b-4a 之後）——讀本文件前先看這段

本文件記錄的是**環節 3 當時**的封路清單。此後有三次結構性變化，**表格內的「處置」欄已不完全成立**：

| 環節 | 變化 | 對本文件的影響 |
|---|---|---|
| 4a-3 | 9 條唯讀頁 Blade **實體刪除**，改 redirect closure | 那 9 條不再掛 `legacy.page`，**沒有 kill switch 回退** |
| 4b-1 | 3 條 `codes.proposals.*` 與 5 條 batch-load 寫入端收斂後補封 | 「不動」19 → 11 |
| **4b-4a** | **`codes` 全套（12 條）Blade 實體刪除**，改 closure | **那 12 條不再掛 `legacy.page`、沒有 kill switch 回退** |
| **4b-4b** | **其餘 21 條（manage／profile／admin）Blade 實體刪除**，改 closure | **`legacy.page` 歸零** |
| **4b-4c** | **封路機制整組移除**（middleware／config／Kernel 別名／env／測試 opt-out） | 回退鍵**不存在**了 |

⇒ 🔴 **目前仍掛 `legacy.page` 的路由是 0 條**（實測 `Route::getRoutes()`）。
**本文件自此是純歷史文件**——它記錄的是環節 3 當時的判斷過程與逐條理由，那些理由仍有參考價值
（特別是「不動」那 11 條的判準），但「處置」欄已全部被後續環節取代。
現況的權威來源是 `tests/Feature/LegacyBladePageRetirementTest.php`——**那份會紅，本文件不會**。
其中三條最該先看：`the_retirement_middleware_and_its_kill_switch_no_longer_exist()`（機制不存在）、
`no_route_declares_the_removed_legacy_page_middleware()`（沒有路由宣告它）、
`migration_flags_cannot_bring_legacy_pages_back()`（翻 flag 也叫不回來）。

🔴 **封路機制已於環節 4b-4c 整組移除**：`RetireLegacyBladePage`、
`config/legacy_page_retirement.php`、Kernel 的 `legacy.page` 別名、`.env` 的
`LEGACY_PAGE_RETIREMENT`、`TestCase::useLegacyBladePages()` 全部不存在了。
舊 runbook 裡「翻 kill switch 即可回退」那一步**已作廢**，要回到 Blade 只能 git revert
並重新部署。
⚠️ **不要把那個 middleware 加回來**：它有兩條 fail-open 路徑（導向目標不存在時放行、
開關關閉時放行），而 Blade 視圖全都刪了，落下去只會得到 500。要封路請直接寫 closure。

⚠️ **4b-4b 刻意保留、不可連坐刪除的**（它們**沒有 `app.` 雙胞胎**，React 頁面直接呼叫）：
- `admin/unidirectional-relationship-repair/{kinship,assoc}`
- `admin/cbdb-table-maintenance/{rebuild,progress}`

## 分類與處置

| 處置 | 條數 | 說明 |
|---|---|---|
| 🟢 **GET → 302** | 22 | 純顯示頁，導向 `/app` 對應頁並保留 query string |
| 🟠 **非 GET → 410** | 11 | legacy 寫入端，無 React 對應 |
| ⚪ **空方法 → 410** | 2 | legacy 顯示路由但 controller 方法本來就是空的 |
| 🔴 **不動** | 19 | 新舊共用方法，或 React 正在呼叫的 action endpoint |
| ⚫ **不在本環節** | 11 | 同一批 controller 上但不屬 A-1…A-17 的頁面路由 |
| **合計** | **65** | 該批 controller 上的全部 legacy（非 `app/*`）路由 |

> **環節 3 當時的算式**：封路數 = 22 + 11 + 2 = **35**（與 `LegacyBladePageRetirementTest`
> 寫死的清單逐條吻合）。下方「合計 65」那一行同屬當時的數字。
>
> ⚠️ **2026-09-14 更新（環節 4a-3）**：其中 **10 條已改成 closure、不再掛 `legacy.page`**
> ——9 條唯讀顯示頁是 **redirect** closure，`POST merge-preview` 是直接 **`abort(410)`** closure。
> 原因是它們的 Blade 視圖與 controller 方法已實體刪除：
> 留著那個 middleware 會讓它的兩條 fail-open 路徑（導向目標不存在時放行、kill switch 關閉時放行）
> 變成 500 而不是「看到舊頁」。
>
> **環節 4b-1 當時**：封路身分清單 = 35 − 10（環節 4a-3 改 closure）+ 8（4b-1 收斂後新封）= **33**
> （14 條 GET→302 + 19 條寫入端→410）。「不動」那組因此從 19 條降為 **11 條**。
>
> **現況（環節 4b-4a 之後）**：再減 12（codes 全套改 closure）= **21**
> （9 條 GET→302 + 12 條寫入端→410），與 `exactly_the_manifested_routes_are_gated()`
> 寫死的清單逐條吻合。
> **在正常封路設定下**（`LEGACY_PAGE_RETIREMENT=true`）對外可觀測的行為完全不變：那 10 條裡的
> **9 條唯讀 GET** 改由 closure 產生同樣的 302（並保留 query string），`POST /merge-preview`
> 則維持 410（只是從 `legacy.page:gone` 換成 closure 直接 `abort(410)`）。
> 302 的總數不變、410 的總數也不變。
>
> ⚠️ **kill switch 關閉時行為確實變了，而那正是重點**：以前關掉會看到 Blade 頁，
> 現在那 10 條照樣 302／410（頁面已不存在）。見下方 🔴。
>
> 🔴 **那 9 條唯讀頁自此沒有 kill switch 級回退**（`LEGACY_PAGE_RETIREMENT=false` 對它們無作用），
> 見 `LegacyBladePageRetirementTest::legacy_readonly_pages_redirect_without_the_kill_switch()`。
> 環節 3 當時：35 + 19 + 11 = 65，與 `route:list` 實測總數相符。
> **現況（環節 4a-3 + 4b-1 + 4b-4a 之後）**：**21** 封路 + **11** 不動
> + **22** closure（4a-3 的 10 條：9 redirect + 1 abort；4b-4a 的 12 條：5 redirect + 7 abort）
> + **11** 不在範圍 = 65，總數不變——**讀者可以自行驗證有沒有漏**。
> （第一版我只在文件頂部加了「現況 21」的段落，卻沒改這個算式，於是同一份文件裡
> 33 與 21 並存、而且這行還邀請讀者自行驗算——review 抓到。**改數字要把整份文件的
> 算式一起改完。**）

---

## 🔴 不動（**11 條**，原 19 條）

> 環節 4b-1 把其中 8 條封掉了（下方 A 類 1 條 + B 類 6 條 + 與 A 同 URI 的 GET proposal-edit）。

**動了就壞。** 這些不是「legacy 殘留」，是現行系統的一部分。

### A. 真的「動了就壞」（1 條）✅ **已於環節 4b-1 收斂並封路**

> `OperationsController` 產 payload 那一行已改指 `app.codes.proposals.edit`（React 版早就存在），
> 所以三條 `codes/{t}/proposals/{op}` 都封起來了：GET→302、PATCH／DELETE→410。


| Method | URI | 為什麼 |
|---|---|---|
| PATCH | `codes/{table_name}/proposals/{operation}` | 當時的理由：`codes/proposal-edit.blade.php` 的表單 action 就是它，而那個 Blade 頁是 React operations 頁的連結目標，**一鎖三條**。<br>✅ **環節 4b-1 已收斂**：`OperationsController` 產 payload 的兩行（`urls.edit_proposal` 與 `urls.cancel_proposal`）都改指 `app.codes.proposals.*`，所以這三條全部封起來了（GET→302、PATCH／DELETE→410）。**React 已不再消費任何 legacy `codes.proposals.*` 路由**，4b-4 可以直接刪 |

### B. 刻意留到環節 4（6 條）✅ **已於環節 4b-1 收斂並封路**

> 3 個 batch-load controller 的 `listRouteName()` 已收斂成**一律回 `app.admin.*`**，
> 所以那 5 條 POST 不再有「多一跳 302 把 flash 吃掉」的問題，已全部封成 410；
> `DELETE codes/{t}/proposals/{op}` 隨 A 類一起封。
> ⚠️ 連帶的行為變化：legacy POST 完成後**落在 React 列表**（不再回 Blade 列表）。
> 三個 legacy 測試檔的 `assertRedirect()` 期望已同步改成 `app.admin.*`——那是收斂**刻意**
> 造成的，不是回歸；它們 `$this->get(route('admin.batch-load-*'))` 打 Blade 頁的部分**沒有動**。


這 6 條的 `app/*` 孿生路由**都存在**，React 打的是 `app/*` 那條，legacy 這條是另一個 route
object，封它不影響 React。之所以先留著：

| Method | URI | 為什麼先留 |
|---|---|---|
| POST | `admin/batch-load-book-titles` | `listRouteName()`（`AdminBatchLoadBookTitlesController:86`）依 `$request->is('app/*')` 回傳 redirect 目標；legacy POST 會走到 legacy 分支，而該 GET 已被封 ⇒ 多一跳、**匯入結果的 flash 被 session 老化掉**。要封它得先收斂 `listRouteName()`，屬環節 4b |
| POST | `admin/batch-load-book-titles/undo` | 同上 |
| POST | `admin/batch-load-book-titles/update-pinyin` | 同上 |
| POST | `admin/batch-load-offices` | 同上（`AdminBatchLoadOfficesController:58`） |
| POST | `admin/batch-load-social-institutes` | 同上 |
| DELETE | `codes/{table_name}/proposals/{operation}` | 🔴 **這一格原本的理由是錯的**：它寫「React ops 頁用的是 `operations/{op}/cancel`，legacy 這條實際無人呼叫」——那**只對實體（entity）提案成立**。`serializeOperationRow()` 的 `urls.cancel_proposal` 是三元式，**else 分支就是 codes 表提案**，走的正是這條 legacy DELETE。環節 4b-1 照抄這句話把它封掉，結果 codes 表提案的提案人無法從 `/app/operations` 撤回自己的提案（410），而且當時**全套測試皆綠**。<br>✅ 已改指 `app.codes.proposals.cancel` 並補上回歸測試 |

> 📌 **可達性低但非零**：legacy 表單頁已被 302，只有「已經開著舊頁的分頁」才送得出這些 POST。
> 這也是它們排在環節 4b、而不是現在硬封的理由——現在封掉只會讓那種情境從「flash 消失」
> 變成「410 錯誤頁」，對使用者更糟。

### C. React 正在呼叫的 action endpoint（**11 條**，原 12 條）——PHP payload 或前端硬編碼

> `GET codes/{table_name}/proposals/{operation}/edit` 那一列已於環節 4b-1 完成收斂並封路，已從本表移除。

| Method | URI | 誰在用 |
|---|---|---|
| GET | `codes/{table_name}/export` | `CodesController::appShow()` 硬編碼 `'/codes/'.$table.'/export'` → `Pages/Codes/Show.tsx` 的下載連結；**`app/codes/{table}/export` 路由不存在** |
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

## kill switch（🔴 已失效，保留作為歷史）

> 🔴🔴 **2026-09-15（環節 4b-4b）起，下面這整套操作步驟已經沒有作用對象。**
>
> 所有 legacy Blade 頁面都已**實體刪除**，`legacy.page` middleware **一條路由都沒掛**。
> 把 `LEGACY_PAGE_RETIREMENT` 設成 `false` **不會叫回任何 Blade 頁**——照著做只會什麼都沒發生。
> 要回到 Blade 只能 **git revert 並重新部署**。
>
> 護欄：`LegacyBladePageRetirementTest::the_retirement_middleware_and_its_kill_switch_no_longer_exist()`、
> `no_route_declares_the_removed_legacy_page_middleware()` 與
> `migration_flags_cannot_bring_legacy_pages_back()`。
>
> **本節保留的唯一理由**是：舊的部署 runbook 可能還抄著這幾行，讀到這裡的人需要知道它為什麼
> 不再有效。以下內容一律視為歷史。

<details>
<summary>環節 3 當時的回退步驟（已失效）</summary>


封路由 `config/legacy_page_retirement.php` 的 `enabled` 總開關控制（env：`LEGACY_PAGE_RETIREMENT`，預設 `true`）。

觀察期間若發現某個 React 頁有問題：

```bash
# .env
LEGACY_PAGE_RETIREMENT=false
php artisan config:clear && php artisan config:cache
```

legacy 頁**立刻復活**——不需重新部署、不需 `git revert`。

🔴 **但這只適用於「碼還在」的那批**：環節 4a-3 已實體刪除 9 條唯讀頁的視圖與 controller 方法，本開關對它們**無作用**（它們已不掛封路 middleware，改成純 redirect closure）。現在 kill switch 能叫回的只剩**表單／寫入頁**（`codes` 全套／`manage`／`profile`／`admin.explainsql`／3 個 batch-load／`cbdb-table-maintenance`／`unidirectional-repair`），也就是環節 4b 的範圍。

這個能力本身有測試守著：`LegacyBladePageRetirementTest::the_kill_switch_restores_the_legacy_pages()` 用 `/admin/explainsql` 斷言 `assertOk()`（該頁在精簡 schema 下也能真的渲染），`migration_flags_no_longer_reopen_gated_legacy_pages()` 末尾再以 `assertViewIs('admin.explain_sql')` 證明回到的是 **Blade 版**；反面則由 `legacy_readonly_pages_redirect_without_the_kill_switch()` 釘住「那 9 條叫不回來」。

### 為什麼需要這個開關（執行中發現的）

原本以為「保留 Blade 視圖與 controller 不動」就足以讓環節 3 可逆。實作後全量測試出現 **186 個失敗、橫跨 22 個測試類**——因為**測試是打路由的**，封了路由，那些驗 legacy Blade 行為的測試就一起斷。

這些測試不該被刪：環節 3 並沒有刪掉那些頁面，它們仍然部署著、仍然能被叫回來，覆蓋在觀察期內依然有意義。真正該做的是讓它們**局部關閉封路**——`TestCase::useLegacyBladePages()`（比照已下架的 `useLegacyPersonForms()` 慣例），22 個測試類已加上。

封路本身的行為由 `LegacyBladePageRetirementTest` 驗證，該檔**不** opt-out。

> 📌 環節 4 實體刪除 legacy 頁時，這個開關、`useLegacyBladePages()` 以及 22 個 opt-out 都要一併移除——屆時沒有可以復活的對象，留著只會讓人誤以為還能回退。同時那 22 個測試類要做環節 1.5 那樣的逐測試分流。

</details>

## 實作方式

**不用 `Route::redirect()`**：它底層是 `Route::any()`，會把同 URI 的所有 method 一起接管——而本 manifest 已經證明同一 URI 的不同 method 處置不同。改以逐條 `Route::get(...)` 閉包 + 專用 middleware。

**不得整批套用**：每一條都按上表逐條處置。

### 三個行為 caveat

> 🔴 **以下三點描述的是環節 3 當時的 middleware 行為（已於環節 4b-4c 移除），純歷史。**
> 特別是第 1 點：**它的結論在環節 4b-4b 被量測推翻**——實測未登入者打 `PUT /manage/1`
> 在封路期拿到的是 **302 → `/login`**（`ManagementController` 建構式的 `auth` 先跑），
> 不是 410。4b-4b 改成 closure 時因此弄丟了那道 `auth`，已用
> `legacy_manage_routes_still_bounce_guests_to_login()` 補回並釘住。
> **教訓：middleware 的實際順序要量，不要照清單推。**

1. ~~**controller middleware 排在 route middleware 之後**，所以封路先跑：未登入的 legacy 非 GET
   請求現在拿到 **410 而不是 302 導向 `/login`**（例 `PUT /manage/1`）~~ **——已被實測推翻，見上。**
2. **`VerifyCsrfToken` 屬 `web` group、跑在封路之前**，所以真實世界未帶 token 的 legacy POST
   會先拿到 **419** 而不是 410（測試環境跳過 CSRF 才看得到 410）。與已移除的
   `LegacyBladeFormGate` 行為一致，非退化。
3. **fail-open 但不靜默**：導向目標路由不存在時放行原 legacy 頁（過渡期讓使用者看到舊頁優於
   500），但會記一筆 `Log::warning`——否則日後誰改了 `app.*` 路由名，production 就會無聲
   復活一個 Blade 頁。
   🔴 **正是這條 fail-open 讓那個 middleware 在今天變得危險**：Blade 視圖全都刪了，
   「放行原 legacy 頁」等於 500。所以它被整組移除，而且**不要加回來**。

## 環節 4 的前置

### 測試分流（比照環節 1.5）

`./vendor/bin/phpunit --group legacy-parity` 原本圈出 **309 個測試**（22 個檔）。環節 4 實體刪除 legacy 頁時，這批要做環節 1.5 那樣的逐測試分流：哪些改測 React 版、哪些直接刪。

**分流進度**：環節 4a-1（2026-09-14）處理了 4 個檔／28 條 ⇒ 現在是 **284 個測試**（19 個檔，16 個 class 級 + 3 個 method 級：`InertiaViewTableTest` 3 條、`OfficeCodesExportTest` 3 條、`UnidirectionalRelationshipRepairControllerTest` 2 條）。
⚠️ 統計時**不要只 grep `Group('legacy-parity')`**——有幾個檔的 docblock 只是「提到」這個群組名，會被誤計；請用 `--group legacy-parity --list-tests` 的實測值。

分流時**已知的覆蓋缺口**（現在只靠 legacy 測試守，刪掉就沒了）：

| 行為 | 現在誰在守 | React 端有嗎 |
|---|---|---|
| `PATCH /profile` 的授權（未登入／未啟用） | `UserProfileTest`（opt-out） | ❌ `PATCH /app/profile` 無等價授權回歸 |
| `PUT /manage/{id}` 的授權 | `InactiveAccountAccessTest`（opt-out） | 🟡 **部分已補**：`SecurityAuditLogTest` 已於環節 4a-1 改打 `app.manage.update`（2 條，驗停用帳號與軟刪除的稽核脈絡）；**純授權**（未登入／未啟用被擋）仍只有 legacy 側的 `InactiveAccountAccessTest` |
| legacy `codes` 的 store／update／destroy 稽核與異體字 | `CodesControllerTest`／`CodesVariantReplacementTest`（opt-out） | 部分有（`ApiV2MutateCodeTables*`），需逐項對照 |

> 這三項**不是**環節 3 造成的——它們一直只有 legacy 覆蓋。列在這裡是為了讓環節 4 不會在
> 「刪掉 opt-out」時無聲失去它們（環節 2 就踩過一次同型的坑）。

### 其餘前置

觀察期（建議 1–2 週）結束、確認無書籤／外部連結損失後，才進環節 4 的實體刪除。屆時：
- 302 可升級為 301；
- 「不動」那組要**逐條處理**：共用方法的 legacy 路由可刪（保留 `app/*` 那條）；React 正在呼叫的要先把 payload 改指 `app/*` 端點（或新建）才能刪。
  **環節 4b-1 已處理 8 條**（原 19 → 現 **11**）：3 條 `codes/{t}/proposals/{op}` 與 5 條 batch-load 寫入端。剩下 11 條的消費者仍在（見上方 C 表）。
