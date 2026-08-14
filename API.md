# API v2

v1 與 v2 並行提供，兩者目前同時可用。本文件分兩部分：**上半部**為 v2（`/api/v2/...`，回傳 `ok` + `data` 或 `ok` + `result` 結構）；**下半部**「舊版 API 文檔」為 v1 舊版端口（`/api/...`，如 `post_list`）。以下先介紹 v2。

v2 分成兩類端點：

- **讀取端點**：`GET /api/v2/persons`、`GET /api/v2/operations` 等，使用 `page` / `per_page` 分頁參數，回傳 `ok` + `data` + `pagination`。
- **寫入端點**：`POST /api/v2/create`、`/api/v2/mutate`、`/api/v2/delete`、`/api/v2/batch_mutate` 等，回傳 `ok` + `resource` + `mode` + `operation` + `result`。寫入一律需要認證，並依帳號角色決定是「直接寫入」還是「提交提案待審核」。

基底 URL：`https://input.cbdb.fas.harvard.edu`

### 章節導覽

| 章節 | 端點 | 用途 |
| ------ | ------ | ------ |
| 一、通用約定 | — | 認證、限流、CSRF、輸入正規化、回應與錯誤格式 |
| 二、人物清單 | `GET /api/v2/persons` | 全量／增量同步人物 ID 與修改時間 |
| 三、操作記錄清單 | `GET /api/v2/operations` | 查詢操作記錄與提案（含審核狀態） |
| 四、寫入 API 總覽 | — | direct／proposal 兩種模式、請求信封、資源一覽、錯誤碼 |
| 五、讀取單列 | `GET|POST /api/v2/get` | 依複合主鍵讀回單列，供編輯前取原值 |

---

## 一、通用約定

### 1.1 認證方式

公開（不需認證）的 v2 端點只有四個：`GET /api/v2/persons`、`GET /api/v2/operations`、`GET /api/v2/texts`、`GET /api/v2/texts/{textId}`。其餘 v2 端點都需要認證。支援兩種憑證：

| 方式 | 用法 | 適用對象 |
| ------ | ------ | ------ |
| Bearer Token | `Authorization: Bearer <token>` | 外部程式、腳本、其他伺服器 |
| Session Cookie | 瀏覽器登入後自動帶上 | 站內前端頁面 |

Bearer Token 的取得：登入網站後於個人資料頁的「API Token」區塊簽發（後端為 `POST /api-tokens`，該端點本身需要 session 登入，無法只靠 token 取得新 token）。Token 明文**只在建立當次回傳**，之後無法再查看。

關於 token 能力（abilities）：目前唯一可簽發的能力是 `mcp:read`（通配 `*` 已停用），而 v2 端點**不檢查 abilities**，因此任何有效且帳號啟用的 token 都能呼叫 v2 讀寫端點；abilities 只影響 `/api/mcp`。

Token 有效期：建立時可指定 `expires_in`（1～3650 天），未指定即**永不過期**。管理員停用帳號時會連帶撤銷該帳號所有 token，之後呼叫會收到 401（而非 403）。持有 token 者可用 `GET /api/user`（帶 Bearer）取得自己的帳號資料，其中的 `id` 就是追蹤提案時 `GET /api/v2/operations?editor=` 要用的 user_id。

認證失敗的回應：

| 情況 | HTTP | message |
| ------ | ------ | ------ |
| 未帶任何憑證就呼叫需登入端點 | 401 | `Unauthenticated.` |
| 帶了 Bearer 但 token 無效／已過期／已撤銷 | 401 | `Invalid API token.` |
| Token 有效但帳號未啟用或已停用 | 403 | `帳號未啟用或已停用。` |
| 帳號啟用但無此操作權限 | 403 | `該使用者沒有權限，請聯繫管理員` |

**注意（外部客戶端請勿帶 `Origin` / `Referer`）**：v2 端點掛的是「可選認證」中間件，它先用 Sanctum 的 `EnsureFrontendRequestsAreStateful::fromFrontend()` 判斷請求是否來自站內前端——若 `Origin` 或 `Referer` 命中 `SANCTUM_STATEFUL_DOMAINS`，就只認 Session Cookie 而**忽略 Bearer Token**，結果會是 401。伺服器端呼叫時不要偽造這兩個標頭即可。

**注意（瀏覽器跨域無法帶 Bearer）**：全站 CORS 回應的 `Access-Control-Allow-Headers` 未包含 `Authorization`，且 `Access-Control-Allow-Credentials` 為 `false`。因此跨網域的瀏覽器 JS 無法帶憑證呼叫本 API，請改由伺服器端程式呼叫。

### 1.2 CSRF

寫入端點掛在 `web` 中間件群組，因此會經過 CSRF 驗證。下列端點已列入豁免清單，外部程式可直接呼叫：

`/api/v2/create`、`/api/v2/mutate`、`/api/v2/delete`、`/api/v2/batch_mutate`、`/api/v2/get`

**未**列入豁免的寫入端點只有兩個——`/api/v2/proposals/{operation}/resubmit` 與 `/api/v2/relationship/opposite-edges`。它們需要 `X-CSRF-TOKEN`（或 `_token`）與對應 session，實務上只有站內前端能呼叫；外部 Bearer 客戶端會收到 **419**（`CSRF token mismatch`）。

這件事對外部提交者有實際後果：**「修改自己的提案」與「撤回自己的提案」目前都沒有 API**（撤回與核准都只有站內頁面流程）。特別是新增提案被駁回後，同一主鍵會被該筆 `rejected` 提案持續占用（見 4.2），此時重送 `create` 只會得到 409，唯一解法是請審核人在站內處理。**提交前請先確認內容正確**，並在 `meta.comment` 寫清楚依據，以降低被駁回的機率。

### 1.3 限流

| 端點群組 | 限流 |
| ------ | ------ |
| `api` 群組（`/api/v2/persons`、`/api/v2/operations`、`/api/v2/texts`、`POST /api/v1/user/login`、舊版 `/api/...`） | 600 次／分鐘，超過回 **429** |
| `web` 群組（`/api/v2/mutate`、`create`、`delete`、`batch_mutate`、`get` 等寫入端點） | 無應用層限流；請自行節流，大量寫入建議改用 `batch_mutate` |

### 1.4 請求格式與輸入正規化

- 寫入端點一律用 `POST`，請帶 `Content-Type: application/json`。後端先讀 JSON body，若 JSON body 為空才退回一般表單參數，因此 form-encoded 也可用，但巢狀結構（`target.pk`、`changes`）以 JSON 表達最不易出錯。
- **`Accept: application/json` 是必要標頭，不是建議**。401／403／419 等由中間件擋下的錯誤走 Laravel 預設錯誤處理；沒帶這個標頭時可能收到 HTML 錯誤頁，未認證甚至會拿到 302 轉向 `/login`，而不是 JSON。
- 全域中間件會對所有輸入做兩件事（**帶 `Content-Type: application/json` 時 JSON body 也會被遞歸處理**；form-encoded 則處理表單參數）：
  1. `TrimStrings`：字串前後空白一律去除（唯一例外是密碼欄）。
  2. `ConvertEmptyStringsToNull`：**空字串 `""` 一律轉成 `null`**。
- 因此「送空字串清空欄位」與「送 null」對後端是同一件事。碼／外鍵欄位若要表達「未詳」，請顯式送 `0`（部分資源也接受 `-999`，後端會正規化為 `"0"`），不要依賴空字串。
- 時間一律以伺服器時區 `+08:00` 解讀與輸出。

### 1.5 回應與錯誤格式

讀取端點（分頁）：

```json
{ "ok": true, "data": [], "pagination": {} }
```

寫入端點成功：

```json
{
  "ok": true,
  "resource": "altnames",
  "mode": "direct",
  "operation": "update",
  "result": { "pk": {}, "operation_id": 123456 }
}
```

部分寫入（別名、人物主檔）在伺服器對送出的值做過異體字或拼音正規化時，會多一個頂層 `notices` 陣列說明替換內容；其餘欄位不變。

由控制器／handler 判定的失敗（多數 4xx 與 5xx）：

```json
{
  "ok": false,
  "message": "人類可讀的錯誤說明（繁體中文或英文）",
  "errors": { "欄位或分類": ["機器可讀的錯誤代號"] }
}
```

`errors` 只在有欄位級細節時出現。**請以 HTTP 狀態碼 + `errors` 的鍵值判斷失敗原因，不要比對 `message` 字串**（訊息可能隨介面文案調整）。

**注意**：由框架／中間件層擋下的錯誤**沒有 `ok` 鍵**，只有 `{"message": "..."}`——包括 401 `Invalid API token.`、403 `帳號未啟用或已停用。`、419（CSRF）、429（限流）。客戶端請以 HTTP 狀態碼為第一判斷依據，不要假設 `ok` 一定存在。

---

## 二、人物清單

### `GET /api/v2/persons`

輸出 BIOG_MAIN 所有人物的 `c_personid` 與時間資訊，按主鍵升序，分頁輸出。**不需要登入。**

每筆人物附帶兩個時間欄位：
- `c_created_date`：該人物的建檔時間（取自 BIOG_MAIN）。
- `c_modified_date`：該人物**任何**資訊（本體 BIOG_MAIN 或其子資源：地址、別名、官職、親屬、事件、社會地位、入仕、著作、財產、社會機構、關係等）最後一次被修改的時間。此為人物聚合層級的水位線，與 BIOG_MAIN 本表的 `c_modified_date`（僅反映本列修改）語意不同。日常由系統即時維護，並可由 `php artisan cbdb:rebuild-person-change-index` 全量校正。

### 輸入參數

| 參數名 | 參數類型 | 預設值 | 說明 |
| ------ | ------ | ------ | ------ |
| per_page | 數字 | 100 | 每頁筆數，上限 1000 |
| page | 數字 | 1 | 頁碼 |
| modified_since | 日期時間字串 | 無 | 增量同步：只回傳 `c_modified_date` **大於等於**(含邊界)此時間的人物 |

`modified_since` 說明：

- 用於下游增量同步：先做一次完整抓取，之後帶上次取得的最大 `c_modified_date` 再次請求，即可只取「之後有變更」的人物。
- 接受格式：`YYYY-MM-DD`、`YYYY-MM-DD HH:MM:SS`、或帶時區的 ISO8601（如 `2026-06-15T00:00:00Z`、`2026-06-15T08:00:00+08:00`）。**不接受**相對／關鍵字（如 `now`、`+1 day`）。
- 時區：未帶時區後綴者以伺服器時區（`+08:00`）解讀；帶時區者會換算為伺服器時區後比較。
- 容錯：格式或日期無效時（含曆法非法如 `2026-02-31`、時間越界如 `24:00:00`）**忽略此參數並回傳全部**（寧可多回，不漏資料）。
- 尚未回填水位線（`c_modified_date` 為 null）的人物**不會**被 `modified_since` 命中。

### 輸入示例

`/api/v2/persons` 取第一頁（預設每頁 100 筆）  
`/api/v2/persons?per_page=500&page=2` 取第二頁，每頁 500 筆  
`/api/v2/persons?modified_since=2026-06-15%2008:00:00` 取 2026-06-15 08:00 以後有變更的人物  
`/api/v2/persons?modified_since=2026-06-15T00:00:00Z&per_page=500` 以 UTC 時間增量同步，每頁 500 筆

### 輸出格式

```json
{
  "ok": true,
  "data": [
    {"c_personid": 1, "c_created_date": "2007-05-01 00:00:00", "c_modified_date": "2026-03-12 09:21:00"},
    {"c_personid": 2, "c_created_date": "2008-01-01 00:00:00", "c_modified_date": null}
  ],
  "pagination": {
    "total": 680000,
    "per_page": 100,
    "current_page": 1,
    "last_page": 6800,
    "from": 1,
    "to": 100
  }
}
```

| 屬性名 | 屬性類型 | 說明 |
| ------ | ------ | ------ |
| ok | 布林 | 請求是否成功 |
| data | 陣列 | 人物列表 |
| data[i].c_personid | 數字 | 人物 ID |
| data[i].c_created_date | 字串/null | 人物建檔時間（`YYYY-MM-DD HH:MM:SS`），無則為 null |
| data[i].c_modified_date | 字串/null | 人物（含所有子資源）最後修改時間；尚未回填時為 null |
| pagination.total | 數字 | 資料總筆數 |
| pagination.per_page | 數字 | 每頁筆數 |
| pagination.current_page | 數字 | 當前頁碼 |
| pagination.last_page | 數字 | 最後一頁頁碼 |
| pagination.from | 數字 | 本頁第一筆的序號（空頁時為 0） |
| pagination.to | 數字 | 本頁最後一筆的序號（空頁時為 0） |

---

## 三、操作記錄清單

### `GET /api/v2/operations`

輸出操作記錄（對應 `/operations` 頁面），分頁輸出。**不需要登入。**

### 輸入參數

| 參數名 | 參數類型 | 預設值 | 說明 |
| ------ | ------ | ------ | ------ |
| per_page | 數字 | 20 | 每頁筆數，上限 100 |
| page | 數字 | 1 | 頁碼 |
| proposals_only | 布林 | false | `true` 只回傳提案（op_type 8/9）；`false` 只回傳一般操作 |
| editor | 字串 | — | 依修改者篩選；純數字視為 user_id 精確比對，否則模糊匹配使用者名稱 |
| op_type[] | 數字陣列 | — | 操作類型篩選（僅非提案模式有效）：1=新增、2=修改全部、3=修改部分、4=刪除 |
| status[] | 字串陣列 | — | 提案審核狀態（僅 `proposals_only=true` 有效）：`pending`、`approved`、`rejected`、`cancelled` |

排序固定為 `updated_at` 降冪（最近變動的在前）。只回傳 `crowdsourcing_status = 0` 的記錄。

**注（刪除提案的 op_type = 10）**：`proposals_only=true` 目前只涵蓋 op_type 8（提案新增）與 9（提案修改），**不含 10（提案刪除）**；而 `proposals_only=false` 只排除 8/9，因此 op_type=10 的刪除提案會出現在「一般操作」清單中。若要一併追蹤刪除提案，請以 `proposals_only=false`（不帶 `op_type[]`）取回後，在客戶端依 `op_type === 10` 與 `resource_data.__review_status` 自行過濾。

### 輸入示例

`/api/v2/operations` 取最近 20 筆一般操作記錄  
`/api/v2/operations?proposals_only=true&status[]=pending` 取待審核提案  
`/api/v2/operations?op_type[]=3&op_type[]=4` 只看修改與刪除操作  
`/api/v2/operations?editor=10` 取 user_id=10 的操作記錄

### 輸出格式

```json
{
  "ok": true,
  "data": [
    {
      "id": 12345,
      "user_id": 7,
      "c_personid": 10001,
      "op_type": 3,
      "resource": "BIOG_MAIN",
      "resource_id": "c_personid=10001",
      "resource_data": {"c_name_chn": "杜甫"},
      "resource_original": {"c_name_chn": "杜甫（舊）"},
      "crowdsourcing_status": 0,
      "created_at": "2026-05-30T08:00:00.000000Z",
      "updated_at": "2026-05-30T08:00:00.000000Z"
    }
  ],
  "pagination": {
    "total": 4200,
    "per_page": 20,
    "current_page": 1,
    "last_page": 210,
    "from": 1,
    "to": 20
  }
}
```

| 屬性名 | 屬性類型 | 說明 |
| ------ | ------ | ------ |
| ok | 布林 | 請求是否成功 |
| data | 陣列 | 操作記錄列表 |
| data[i].id | 數字 | 操作 ID |
| data[i].user_id | 數字 | 操作者的 user ID |
| data[i].c_personid | 數字 | 相關人物 ID |
| data[i].op_type | 數字 | 操作類型（1=新增、2=修改全部、3=修改部分、4=刪除、8=提案新增、9=提案修改、10=提案刪除） |
| data[i].resource | 字串 | 被操作的資料表名稱 |
| data[i].resource_id | 字串 | 被操作的資料列識別碼 |
| data[i].resource_data | 物件 | 操作後的資料內容（JSON 已解碼） |
| data[i].resource_original | 物件/null | 操作前的資料快照（JSON 已解碼；新增操作為 null） |
| data[i].crowdsourcing_status | 數字 | 眾包狀態（0=正式操作） |
| data[i].created_at | 字串 | 建立時間（ISO 8601） |
| data[i].updated_at | 字串 | 更新時間（ISO 8601） |
| pagination | 物件 | 分頁資訊（欄位同人物清單） |

---

## 四、寫入 API 總覽

### 4.1 端點一覽

| 方法 | 路徑 | 用途 | 外部 Bearer 可用 |
| ------ | ------ | ------ | ------ |
| POST | `/api/v2/create` | 新增一列 | ✔ |
| POST | `/api/v2/mutate` | 修改一列（部分欄位） | ✔ |
| POST | `/api/v2/delete` | 刪除一列 | ✔ |
| POST | `/api/v2/batch_mutate` | 一個請求內批次執行多筆 create／update／delete（單次上限 **500** 筆；可選 `atomic`） | ✔ |
| GET/POST | `/api/v2/get` | 依複合主鍵讀回單列 | ✔ |
| POST | `/api/v2/proposals/{operation}/resubmit` | 修改提案＝撤回舊提案並重發（提案人本人或審核人皆可） | ✘（需 CSRF，見 1.2） |
| POST | `/api/v2/relationship/opposite-edges` | 偵測社會關係／親屬的對面互逆鏡像現況 | ✘（需 CSRF，見 1.2） |

**注意**：`batch_mutate` 在非原子模式下**即使每一筆都失敗，HTTP 狀態碼仍是 200**（成敗看 body 的 `ok` 與 `summary`）。只看狀態碼的客戶端會把整批失敗誤判為成功，詳見〈批次寫入〉。

### 4.2 direct 與 proposal 兩種模式

所有寫入端點都以請求中的 `mode` 決定行為：

| mode | 行為 | 落地時機 |
| ------ | ------ | ------ |
| `direct`（預設） | 直接寫入資料表，同一交易內寫 `operations` 與 `audit_log` | 立即 |
| `proposal` | 不動資料表，只寫一筆待審核提案到 `operations` | 待審核人核准後才套用 |

權限矩陣（所有角色都必須帳號啟用 `is_active = 1`，未啟用一律 403）：

| 角色 | `mode=direct` | `mode=proposal` | 可審核他人提案 |
| ------ | ------ | ------ | ------ |
| 系統管理員 | ✔ | ✔ | ✔ |
| 專家 | ✔ | ✔ | ✔ |
| 一般用戶 | ✔ | ✔ | ✔ |
| **眾包用戶** | ✘（403） | ✔ | ✘ |

**眾包（crowdsourcing）帳號只能送 `mode=proposal`**；送 `mode=direct` 會得到 403 `該使用者沒有權限，請聯繫管理員`。外部協作者的帳號通常屬於此類，請一律帶 `"mode": "proposal"`。

提案送出後：

- 回應的 `result.operation_id` 就是該提案在 `operations` 表的 id，請保存下來以便追蹤。
- 提案內容存於該筆 operation 的 `resource_data`，其中系統欄位包括 `__review_status`（`pending` / `approved` / `rejected` / `cancelled`）、`__proposal_meta`（提案人、時間、`comment`）、`__key_columns`。
- 追蹤自己的提案：`GET /api/v2/operations?proposals_only=true&editor=<user_id 或名稱>&status[]=pending`（見第三章）。自己的 `user_id` 可用 `GET /api/user`（帶 Bearer）取得。提案的 `op_type` 為 8（新增）、9（修改）、10（刪除）；**刪除提案（10）不會被 `proposals_only=true` 涵蓋**，追蹤方式見第三章的注意事項。

提案的「占位」規則（送出前務必理解，否則會被 409 卡住）：

| 動作 | 占位判定 | 會擋下後續提交的狀態 |
| ------ | ------ | ------ |
| create（op_type 8） | 同一資料表 + 同一 `resource_id` | `pending` **或 `rejected`** |
| update（op_type 9） | 同一資料表 + 同一 `resource_id` | 僅 `pending` |
| delete（op_type 10） | 同一資料表 + 同一 `resource_id` | 僅 `pending` |

- 占位**不分提案人**：別人針對同一列送出的待審提案，也會讓你收到 409 `pending_proposal_exists`。
- **新增提案被駁回（`rejected`）後仍然占位**，重送同主鍵的 `create` 一律 409。因為撤回／修改提案沒有對外 API（見 1.2），此時只能請站內審核人處理。
- `possessions` 的新增提案沒有任何重複防呆（主鍵是系統配發的流水號），重複送出會產生多筆提案、核准多次就產生多列，請自行避免重送。
- `postings` 的新增提案只按 `c_office_id` 防呆（同一官職上任何人的待審提案會互擋），且錯誤鍵是 `changes: ["pending_proposal_exists"]` 而非 `target.pk`。

### 4.3 通用請求信封

所有寫入端點共用同一組頂層欄位：

```json
{
  "resource": "altnames",
  "mode": "proposal",
  "operation": "update",
  "person_id": 1762,
  "target": { "pk": { "c_personid": 1762, "c_alt_name_chn": "半山", "c_alt_name_type_code": 4 } },
  "changes": { "c_notes": "補充出處頁碼" },
  "meta": { "comment": "據宋史列傳補正" }
}
```

| 欄位名 | 參數類型 | 必填 | 說明 |
| ------ | ------ | ------ | ------ |
| resource | 字串 | ✔ | 資源名或別名（後端自動轉小寫，見 4.5） |
| mode | 字串 | — | `direct`（預設）或 `proposal` |
| operation | 字串 | — | `create` / `update` / `delete`。`/api/v2/mutate` 預設 `update`；`/api/v2/create` 與 `/api/v2/delete` 固定為對應動作，帶了也會被忽略 |
| person_id | 數字 | ✔ | 該列所屬人物 ID。**必須與 `target.pk` 內的人物欄一致，也必須與資料庫該列的人物欄一致**，否則 422 `person_id: mismatch`。`possessions` 與 `postings` 的主鍵不含 `c_personid`，此時只比對資料庫該列的 `c_personid`；這兩者的 `create` 另外拒絕 `person_id = 0`（422 `person_id: invalid`）。**即使是與人物無關的資源（代碼表、複合實體聚合），`person_id` 仍為必填**（未提供即 422），請填相關人物 ID 或任一非空值 |
| target.pk | 物件 | ✔ | 目標列的**完整**複合主鍵，缺任一欄即 422（見 4.4）。少數 create（如 `postings`、`possessions`）的主鍵由系統配發，此時送空物件 `{}` 即可 |
| changes | 物件 | **update 必填** | 要寫入的欄位。`update` 缺 `changes` 回 422 `changes: required`、空物件回 422 `changes: empty`；**`create` 的 `changes` 非必填**（只帶完整 `target.pk` 也可能成功）。`delete`：走 `/api/v2/delete` 或 `batch_mutate` 時可省略，但**走 `/api/v2/mutate` 並帶 `operation: "delete"` 時仍必須帶 `changes` 鍵**（可為空物件），否則 422 `changes: required`；內容會被忽略 |
| meta.comment | 字串 | — | 提案說明；`direct` 模式則寫入該筆 operation 的 `__note` |
| meta.force | 布林 | — | 對「鏡像衝突／對面多筆反向列」的二次確認（見〈社會關係與親屬的互逆鏡像〉）。首次提交請勿帶；只有在收到 409 `mirror_conflict` / `mirror_suspected` / `mirror_delete_multiple` 並確認過影響範圍後才帶 |

`update` 是 **PATCH 語義，不是整頁表單覆寫**：

- **只送要改的欄位**。沒出現在 `changes` 的欄位保持原值，不會被清空。
- 顯式送 `null`（含被中間件轉成 null 的空字串）**就是清空**該欄；但碼／外鍵欄位會被正規化成 `"0"`（哨兵值「未詳」），不會寫入 null。
- 送出的值可能被伺服器改寫後才落庫：異體字會依對照表做嚴格替換、拼音欄的 `v` 會歸一化為 `ü`。若被改寫的欄位剛好是主鍵欄（例如別名的 `c_alt_name_chn`），**回應 `result.pk` 才是真正落庫的主鍵**，請以回應為準，不要沿用送出的值。發生替換時回應會多一個頂層 `notices` 陣列。

### 4.4 `target.pk`：複合主鍵

CBDB 子資源表幾乎都是複合主鍵，且不使用 Eloquent 主鍵行為。`target.pk` 必須列出該表**全部**主鍵欄位，任一欄缺失或為 `null` 都會回 422：

```json
{ "ok": false, "message": "主鍵格式不正確", "errors": { "pk": ["缺少必要的複合主鍵參數：c_sequence"] } }
```

要點：

- 主鍵欄位定義的權威來源是 `app/Support/CompositePrimaryKey.php` 的 `SCHEMAS`；各資源的主鍵欄位見〈讀取單列〉一章的資源表，以及〈各資源欄位參考〉。
- `update` 時如果 `changes` 內含主鍵欄位，等於「改鍵」。後端會檢查新主鍵是否已被其他列占用，衝突則回 409 `target.pk: conflict`。
- 唯一允許主鍵欄為空的例外是**寫入端**的 `BIOG_SOURCE_DATA.c_pages`（`sources` 的 create／update／delete 會把它視為可空並正規化為空字串）；`/api/v2/get` **不**套用這個例外，詳見〈讀取單列〉一章的注意事項。
- 想先確認某列現值再送修改，可先呼叫 `/api/v2/get`（見〈讀取單列〉）。

**主鍵欄位的「未詳」必須用哨兵值顯式送出**：CBDB 的複合主鍵欄多為 NOT NULL，「這一欄未詳」在資料上是以哨兵值表達，而不是省略或送空值。又因為主鍵完整性檢查發生在後端正規化**之前**（且空字串已先被轉成 null），省略或送空值只會得到 422。常見哨兵值：

| 型別 | 哨兵值 | 例 |
| ------ | ------ | ------ |
| 數值碼／ID | `0` | `ENTRY_DATA` 10 個主鍵欄中未詳者一律送 `0`；`ASSOC_DATA` 的 `c_kin_code`／`c_kin_id`／`c_assoc_kin_code`／`c_assoc_kin_id` 同理 |
| 年份 | `-9999` | `ASSOC_DATA.c_assoc_first_year` |
| 文字（出處書名） | `[n/a]` | `ASSOC_DATA.c_text_title` |

（部分資源的主鍵欄也接受 `-999` 並在落庫前正規化為 `0`——例如 `ASSOC_DATA` 的 create——但這是逐資源的行為，並非通則。最保險的做法是一律直接送上表的哨兵值。）

### 4.5 資源一覽與支援的操作

`resource` 大小寫不敏感（後端一律轉小寫）。**別名逐操作獨立定義，並非全部一致**，下表的「別名」欄已標註差異；若無把握，一律使用「主名」最安全。

| 主名 | 別名 | 資料表 | create | update | delete |
| ------ | ------ | ------ | ------ | ------ | ------ |
| basicinformation | biogmain, biog_main | BIOG_MAIN | 僅 direct | direct + proposal | 僅 direct（軟刪除） |
| altnames | altname, altname_data | ALTNAME_DATA | ✔ | ✔ | ✔ |
| addresses | address, biog_addr_data | BIOG_ADDR_DATA | ✔ | ✔ | ✔ |
| entries | entry, entry_data | ENTRY_DATA | ✔ | ✔ | ✔ |
| statuses | status, status_data | STATUS_DATA | ✔ | ✔ | ✔ |
| events | event, events_data | EVENTS_DATA | ✔ | ✔ | ✔ |
| associations | association, assoc_data | ASSOC_DATA | ✔ | ✔ | ✔ |
| kinship | kin, kin_data | KIN_DATA | ✔ | ✔ | ✔ |
| possessions | possession, possession_data | POSSESSION_DATA | ✔ | ✔ | ✔ |
| texts | text, biog_text_data, text_data | BIOG_TEXT_DATA | ✔ | ✔ | ✔ |
| postings | posting, offices, posted_to_office_data | POSTED_TO_OFFICE_DATA | ✔ | ✔ | ✔ |
| social_institutions | social_institution, biog_inst_data（create／delete 另接受 socialinst） | BIOG_INST_DATA | ✔ | ✔ | ✔ |
| sources | **create／update 只接受 `sources`**；delete 另接受 source, biog_source_data | BIOG_SOURCE_DATA | ✔ | ✔ | ✔ |
| merged-person | merged_person, merged_person_data, mergedperson | MERGED_PERSON_DATA | ✔ | ✘ | ✔ |
| 可修改的代碼表（nianhao、office_codes、dynasties…） | 見〈代碼表與複合實體寫入〉 | 各代碼表 | ✘（501） | ✔ | ✘（501） |
| 可新增的代碼表（text-codes、char-variant-map） | 逐操作不同，見〈代碼表與複合實體寫入〉 | TEXT_CODES、char_variant_map | 僅 direct | ✔ | ✘（403，已停用） |
| office、social-institution（複合實體聚合） | 見〈代碼表與複合實體寫入〉 | 多表聚合 | ✔ | ✔ | ✔ |

- 表中 `✔` 表示 `direct` 與 `proposal` 兩種模式都支援。
- 人物主檔（basicinformation）的 `create` 與 `delete` **不支援 proposal**，會回 501 `mode: ["proposal_not_supported"]`；`update` 則兩種模式都支援。
- 人物主檔的 `delete` 是**軟刪除**：把 `c_name_chn` 改為 `<待删除>`（UPDATE，不是真的 DELETE，也不觸發外鍵連鎖），並記一筆 `op_type=4` 的 operation。
- 代碼表分兩組：**只能改**的一組（`config/code_table_mutations.php`，如 nianhao、office_codes、dynasties、choronym_codes、ganzhi_codes 等）送 `create`／`delete` 會回 501；**可新增**的一組只有 `TEXT_CODES` 與 `char_variant_map`（`config/code_table_writes.php`）。代碼表的 `delete` 一律不開放——受支援的兩張表回 **403**（防止級聯刪除人物資料），其餘代碼表回 501。
- 不支援的 `resource` / `mode` / `operation` 組合一律回 **501**：`{"ok":false,"message":"目前尚未支援此變更模式","errors":{"resource":"...","mode":"...","operation":"..."}}`。

各資源的欄位白名單、必填規則與範例見〈各資源欄位參考〉。

### 4.6 錯誤碼總表

| HTTP | 意義 | 常見 `errors` 鍵值 |
| ------ | ------ | ------ |
| 401 | 未認證或 token 無效 | — |
| 403 | 帳號未啟用；或眾包帳號使用 `mode=direct` | — |
| 404 | 目標列不存在（訊息形如 `ALTNAME_DATA 記錄不存在`） | — |
| 409 | 主鍵衝突或狀態衝突 | `target.pk: conflict` / `duplicate` / `pending_proposal_exists`、`changes: conflict`、`mirror_conflict`、`mirror_suspected`、`mirror_delete_multiple`、`mirror: conflict` |
| 419 | CSRF token 不符（只會發生在 `resubmit`、`opposite-edges`） | — |
| 422 | 參數校驗失敗 | `target.pk: required`、`person_id: required`、`pk`（主鍵缺欄位）、`person_id: mismatch`、`changes: required` / `empty` / `no_supported_fields` / `no_effective_changes` / `disallowed_fields: <欄位清單>`、各欄位級規則、`mirror_integrity: fail_closed` |
| 429 | 讀取端點超過限流（600 次／分鐘） | — |
| 500 | 未預期的伺服器錯誤 | — |
| 501 | `resource` / `mode` / `operation` 組合不支援 | `resource`、`mode`、`operation` |

較常踩到的 422 情境：

| `errors` | 原因 |
| ------ | ------ |
| `changes: ["empty"]` | `update` 的 `changes` 是空物件 |
| `changes: ["no_supported_fields"]` | `changes` 內沒有任何該資源可寫的欄位 |
| `changes: ["disallowed_fields: c_foo, c_bar"]` | 送了白名單外的欄位。多數人物子資源的 `update`／`create` 會**整筆拒絕**（見下方警告） |
| `person_id: ["invalid"]` | `postings`／`possessions` 的 `create` 收到 `person_id = 0` |
| `changes: ["no_effective_changes"]` | 送出的值與現值完全相同（後端以字串比對），沒有任何實際變更 |
| `person_id: ["mismatch"]` | `person_id` 與 `target.pk` 內人物欄不符，或與資料庫該列的人物欄不符 |
| `pk: ["缺少必要的複合主鍵參數：..."]` | `target.pk` 缺欄位 |

**警告（欄名打錯不一定會報錯）**：白名單外的欄位並非所有資源都會整筆拒絕。下列路徑是**靜默丟棄**未知欄位，會回 `200` / `ok: true` 但該欄根本沒寫進資料庫：

- `basicinformation` 的 `update`（採黑名單過濾，未知鍵直接丟棄）與 `create`（採白名單交集，未知鍵直接丟棄）
- `postings` 的 `create`、`possessions` 的 `create`
- `sources` 的 `create`／`update`（只讀固定欄位）

因此**寫入後請比對回應的 `result.row`（或另呼叫 `/api/v2/get`）確認欄位真的落庫**，不要只看 `ok: true`。

### 4.7 寫入的稽核與追溯

每一次成功的寫入（direct）都會在同一資料庫交易內：

1. 更新目標資料表，並由系統蓋上 `c_modified_by` / `c_modified_date`（`create` 則蓋 `c_created_by` / `c_created_date`）。**請勿在 `changes` 內自行送稽核欄位**——它們不在白名單內，會導致整筆 422，或被後端剔除。
2. 寫一筆 `operations`（`op_type`：1=新增、3=修改、4=刪除），回應的 `result.operation_id` 即其 id，可用 `GET /api/v2/operations` 查詢。
3. 寫一筆 `audit_log`。

失敗時整筆交易回滾，不會留下半套資料。

提案（proposal）路徑的落地與署名：

- 提案送出時**不動**資料表，只寫一筆 operation；核准是站內流程（沒有對外 API）。
- 核准時後端會以 `mode=direct` **重放同一個 handler 並重新驗證**。因此提案在送出當下合法、核准當下卻不合法（例如目標列已被他人改鍵或刪除、主鍵已被占用），核准會失敗並整筆回滾，提案維持待審。這也是提案送出後不宜久放的原因。
- 核准後 `c_modified_by` 記的是雙人名——形如「審核人 (Proposed by: 提案人)」；若提案人名稱缺失或與審核人相同，則只記審核人單名。提案人不會單獨署名。
- `c_modified_date` 記的是**核准落庫的時間**，不是提案送出時間。

---

## 五、讀取單列（依複合主鍵）

### `GET /api/v2/get`（也接受 `POST`）

依複合主鍵讀回單一列的完整內容，通常用於「修改前先取得現值」或「寫入後回讀驗證」。**需要登入且帳號啟用**（眾包帳號亦可）。此端點純讀取，不寫 operations／audit_log。

### 輸入參數

| 參數名 | 參數類型 | 必填 | 說明 |
| ------ | ------ | ------ | ------ |
| resource | 字串 | ✔ | 資源名或別名，見下表 |
| person_id | 數字 | ✔ | 該列所屬人物 ID；須與該列的 `c_personid` 一致 |
| target.pk | 物件 | ✔ | 完整複合主鍵 |

支援的資源與主鍵（此端點的別名清單與寫入端點**略有差異**，以本表為準）：

| resource | 別名 | 資料表 | 主鍵欄位 |
| ------ | ------ | ------ | ------ |
| basicinformation | biogmain, biog_main | BIOG_MAIN | c_personid |
| altnames | altname, altname_data | ALTNAME_DATA | c_personid, c_alt_name_chn, c_alt_name_type_code |
| addresses | address, biog_addr_data | BIOG_ADDR_DATA | c_personid, c_addr_id, c_addr_type, c_sequence |
| entries | entry, entry_data | ENTRY_DATA | c_personid, c_entry_code, c_sequence, c_kin_code, c_assoc_code, c_kin_id, c_year, c_assoc_id, c_inst_code, c_inst_name_code |
| statuses | status, status_data | STATUS_DATA | c_personid, c_sequence, c_status_code |
| events | event, events_data | EVENTS_DATA | c_personid, c_sequence, c_event_code |
| associations | association, assoc_data | ASSOC_DATA | c_personid, c_assoc_code, c_assoc_id, c_kin_code, c_kin_id, c_assoc_kin_code, c_assoc_kin_id, c_text_title, c_assoc_first_year |
| kinship | kin, kin_data | KIN_DATA | c_personid, c_kin_id, c_kin_code |
| possessions | possession, possession_data | POSSESSION_DATA | c_possession_record_id |
| texts | text, text_data, biog_text_data | BIOG_TEXT_DATA | c_personid, c_textid, c_role_id |
| postings | posting, offices, posted_to_office_data | POSTED_TO_OFFICE_DATA | c_office_id, c_posting_id |
| social_institutions | socialinstitution, social_institution, biog_inst_data | BIOG_INST_DATA | c_personid, c_inst_code, c_inst_name_code, c_bi_role_code |
| sources | source, biog_source_data | BIOG_SOURCE_DATA | c_personid, c_textid, c_pages |
| nianhao | nian_hao | NIAN_HAO | c_nianhao_id |

**注 1：`possessions` 與 `postings` 的主鍵不含 `c_personid`，但 `person_id` 仍為必填，且後端會比對該列的 `c_personid` 是否相符。`nianhao` 不是人物資料（無人物欄），`person_id` 只需給任一非空值即可。**

**注 2（`sources` 的空頁碼讀不回來）：`BIOG_SOURCE_DATA.c_pages` 在寫入端可為空，但本端點**不**套用該例外——空字串會先被中間件轉成 `null`，主鍵驗證即回 422 `缺少必要的複合主鍵參數：c_pages`。也就是「頁碼為空的出處列」可以用 `create`／`delete` 操作，卻無法用 `/api/v2/get` 讀回；這類列請改由人物詳情頁或 `GET /api/v2/operations` 取得內容。**

**注 3：`merged-person` 可以寫入（create／delete），但本端點不支援讀取。**

### 輸入示例

以 POST（推薦）：

```json
POST /api/v2/get
Content-Type: application/json
Authorization: Bearer <token>

{
  "resource": "kinship",
  "person_id": 1762,
  "target": { "pk": { "c_personid": 1762, "c_kin_id": 1760, "c_kin_code": 180 } }
}
```

以 GET（巢狀參數用陣列語法，中文需 URL 編碼）：

```
GET /api/v2/get?resource=kinship&person_id=1762&target[pk][c_personid]=1762&target[pk][c_kin_id]=1760&target[pk][c_kin_code]=180
```

### 輸出格式

```json
{
  "ok": true,
  "resource": "kinship",
  "mode": "direct",
  "operation": "get",
  "result": {
    "pk": { "c_personid": 1762, "c_kin_id": 1760, "c_kin_code": 180 },
    "row": {
      "c_personid": 1762,
      "c_kin_id": 1760,
      "c_kin_code": 180,
      "c_source": 7596,
      "c_pages": "31",
      "c_notes": null,
      "c_autogen_notes": null,
      "c_created_by": null,
      "c_created_date": null,
      "c_modified_by": "系統匯入",
      "c_modified_date": "2024-11-03 10:22:41"
    }
  }
}
```

| 屬性名 | 屬性類型 | 說明 |
| ------ | ------ | ------ |
| ok | 布林 | 請求是否成功 |
| resource | 字串 | 解析後的資源主名 |
| mode | 字串 | 固定為 `direct` |
| operation | 字串 | 固定為 `get` |
| result.pk | 物件 | 回填請求的複合主鍵 |
| result.row | 物件 | 該列所有欄位的原值（欄位視資料表而定） |

### 錯誤

| HTTP | 情況 |
| ------ | ------ |
| 401 | 未登入 |
| 403 | 帳號未啟用或已停用 |
| 404 | 該主鍵沒有對應的列（`<表名> 記錄不存在`） |
| 422 | `target.pk` 缺欄位、缺 `person_id`、或 `person_id` 與該列不符 |
| 501 | `resource` 不在上表（`目前尚未支援此取得模式`） |

---

# 舊版 API 文檔

## 使用方法
將下文輸入示例中 /api... 前接 input.cbdb.fas.harvard.edu

形如: [https://input.cbdb.fas.harvard.edu/api/post_list?id=06&start=0&list=100](https://input.cbdb.fas.harvard.edu/api/post_list?id=06&start=0&list=100)

## 一、根據官職類別代碼獲取其下屬官職列表
### 輸入參數:
| 參數名| 參數類型 | 說明 |
| ------ | ------ | ------ |
| id | 數字 | 官職類別代碼|
| start | 數字 | 開始筆數 |
| list | 數字 | 列表長度 |
### 輸入示例: 
`/api/post_list?id=06&start=0&list=100`獲取【唐朝】下的前100個官職(開始筆數 =0，結束筆數=99, ⻑度=100）  
`/api/post_list?id=06&start=100&list=100` 獲取【唐朝】下的第100-200個官職 (開始筆數=100，結束筆數=199, ⻑度=100)  
`/api/post_list?id=0601` 獲取【唐朝-帝后制度類】下的全部官職  

### 輸出格式:  
數據類型：`物件`  
```json
{
    "total":100,
    "start":1,
    "end":2,
    "data":[
        {"pId":"27","pName":"shang shu sheng gong bu shang shu","pNameChn":"尚書省工部尚書","pNameChnAlt":"工部尚書;尚書;工部一書;工書;冬卿;冬卿常伯"},
        {"pId":"28","pName":"shang shu sheng gong bu shi lang","pNameChn":"尚書省工部侍郎","pNameChnAlt":"工部侍郎;工侍;小司空;司平少常伯;冬官之貳;共工之貳"}        
    ]
}
```
| 屬性名| 屬性類型 | 說明 |
| ------ | ------ | ------ |
| total |  數字 | 數據總筆數 |
| start | 數字 | 當前數據開始筆數 |
| end | 數字 | 當前數據結束筆數 |
| data | 陣列 | 官職列表 |
| data[`i`].pId | 數字 | office_id |
| data[`i`].pName | 字符串 | 官職名，英文 |
| data[`i`].pNameChn | 字符串 | 官職名，中文 | 
| data[`i`].pNameChnAlt | 字符串 | 官職別名，中文 [OFFICE_CODES].[c_office_chn_alt] |  

## 二、根據入仕途徑類別代碼獲取其下屬的入仕途徑
### 輸入參數:
| 參數名| 參數類型 | 說明 |
| ------ | ------ | ------ |
| id | 數字 | 入仕途徑代碼 |
| start | 數字 | 開始筆數 |
| list | 數字 | 列表長度 |
### 輸入示例: 
`/api/entry_list?id=04` 獲取【科舉門】下的所有入仕途徑  
`/api/entry_list?id=0403` 獲取【科舉門-制舉】下的所有入仕途徑    

### 預期輸出:  
數據類型：`物件`  
```json
{
    "total":100,
    "start":1,
    "end":2,
    "data":[
        {"eId":"47","eName":"school: licentiate ","eNameChn":"學校：生員（庠生）"},
        {"eId":"173","eName":"county school student","eNameChn":"縣學生員"}        
    ]
}
```
| 屬性名| 屬性類型 | 說明 |
| ------ | ------ | ------ |
| total |  數字 | 數據總筆數 |
| start | 數字 | 當前數據開始筆數 |
| end | 數字 | 當前數據結束筆數 |
| data | 陣列 | 入仕途徑列表 |
| data[`i`].eId | 數字 | entry_code |
| data[`i`].eName | 字符串 | 入仕途徑名，英文 |
| data[`i`].eNameChn | 字符串 | 入仕途徑名，中文 |

## 三、根據名稱、存在時間等條件獲取地點
| 參數名| 參數類型 | 說明 |
| ------ | ------ | ------ |
| name | 字符串 | 地點名稱，中文或英文(必須) |
| startTime | 數字 | 起始時間(可選) |
| endTime | 數字 | 結束時間(可選) |
| accurate| 數字 | 是否精確匹配，若精確匹配為`0`，若部分匹配，為`1` ~~是的你沒看錯，真的是這樣~~ |
| start | 數字 | 開始筆數|
| list | 數字 | 列表長度 |

### 輸入示例: 
`/api/place_list?name=%E6%99%AE%E6%B4%B2%E9%81%93&accurate=1` 模糊匹配地名為“普洱道”的所有地點  
`/api/place_list?name=Puer&accurate=0` 精確匹配名稱為“Puer”的地點

### 輸出格式:  
數據類型：`物件`  
```json
{
    "total":100,
    "start":1,
    "end":2,
    "data":[
            {"pId":9099,
            "pName":"Guangzhou Shi",
            "pNameChn":"廣州市",
            "pStartTime":1912,
            "pEndTime":1949,
            "pBName":"Guangdong sheng",
            "pBNameChn":"廣東省"
            },
            {"pId":9102,
            "pName":"Guangzhou Shi",
            "pNameChn":"廣州市",
            "pStartTime":1912,
            "pEndTime":1949,
            "pBName":"Guangxi sheng",
            "pBNameChn":"廣西省"}
            ]    
}
```
| 屬性名| 屬性類型 | 說明 |
| ------ | ------ | ------ |
| total |  數字 | 數據總筆數 |
| start | 數字 | 當前數據開始筆數 |
| end | 數字 | 當前數據結束筆數 |
| data | 陣列 | 入仕途徑列表 |
| data[`i`].pId | 數字 | 地點代碼 |
| data[`i`].pName | 字符串 | 地點名稱，英文 |
| data[`i`].pNameChn | 字符串 | 地點名稱，中文 |
| data[`i`].pStartTime | 數字 | 地點起始時間|
| data[`i`].pEndTime | 數字 | 地點結束時間 |
| data[`i`].pBName | 字符串 | 上一級地點名稱，英文 |
| data[`i`].pBNameChn | 字符串 | 上一級地點名稱，中文 |

## 四、搜尋該地點下的所有地點
| 參數名| 參數類型 | 說明 |
| ------ | ------ | ------ |
| id | 數字 | 地點代碼 |
| start | 數字 |開始筆數 |
| list | 數字 | 列表⻑度 |

### 輸入示例: 
`/api/place_belongs_to?id=16773` 搜索屬於“安⻄都護府”的所有地點  

### 輸出格式:  
數據類型：`物件`  
```json
{
    "total":100,
    "start":1,
    "end":2,
    "data":[
            {"pId":9099,
            "pName":"Guangzhou Shi",
            "pNameChn":"廣州市",
            "pStartTime":1912,
            "pEndTime":1949,
            "pBName":"Guangdong sheng",
            "pBNameChn":"廣東省"
            },
            {"pId":9102,
            "pName":"Guangzhou Shi",
            "pNameChn":"廣州市",
            "pStartTime":1912,
            "pEndTime":1949,
            "pBName":"Guangxi sheng",
            "pBNameChn":"廣西省"}
            ]    
}
```
| 屬性名| 屬性類型 | 說明 |
| ------ | ------ | ------ |
| total |  數字 | 數據總筆數 |
| start | 數字 | 當前數據開始筆數 |
| end | 數字 | 當前數據結束筆數 |
| data | 陣列 | 入仕途徑列表 |
| data[`i`].pId | 數字 | 地點代碼 |
| data[`i`].pName | 字符串 | 地點名稱，英文 |
| data[`i`].pNameChn | 字符串 | 地點名稱，中文 |
| data[`i`].pStartTime | 數字 | 地點起始時間|
| data[`i`].pEndTime | 數字 | 地點結束時間 |
| data[`i`].pBName | 字符串 | 上一級地點名稱，英文 |
| data[`i`].pBNameChn | 字符串 | 上一級地點名稱，中文 |

**注：**
*  在ACCESS系統中，不論一個地點下面是否有其他地點屬於它，結果中都會返回這一地點本身。
此處亦應遵守此原則。
* 為了保證返回數據的一致性，API返回查詢結果之前應該按照相同的標準進行排序。用輸入參數中id所對應的當前表格中的id進行排序(此處為地點的id)

## 五、根據官職中英文名獲取官職列表
### 輸入參數:

| 參數名| 參數類型 | 說明 |
| ------ | ------ | ------ |
| pName | 字符串 | 官職名稱，中文或英文，經過轉碼 |
| start | 數字 | 開始筆數 |
| list | 數字 | 列表長度 |
| accurate|數字|是否採用精確匹配，是=1，否=0|

### 輸入示例: 
`/api/office_list_by_name?pName=%E5%B0%9A%E6%9B%B8%E7%9C%81%E5%B7%A5%E9%83%A8&start=1&list=2&accurate=0`搜尋所有名稱含有**尚書省工部**的官職，從第1筆開始，至多2筆結果  
`/api/office_list_by_name?pName=%E5%B0%9A%E6%9B%B8%E7%9C%81%E5%B7%A5%E9%83%A8%E4%BE%8D%E9%83%8E&start=1&list=2&accurate=1`精確匹配名稱為**尚書省工部侍郎**的官職，從第1筆開始，至多返回2筆結果

### 輸出格式示例:    
數據類型：`物件` 
```json
{
    "total":100,
    "start":1,
    "end":2,
    "data":[
        {"pId":"27","pName":"shang shu sheng gong bu shang shu","pNameChn":"尚書省工部尚書","pNameChnAlt":"工部尚書;尚書;工部一書;工書;冬卿;冬卿常伯"},
        {"pId":"28","pName":"shang shu sheng gong bu shi lang","pNameChn":"尚書省工部侍郎","pNameChnAlt":"工部侍郎;工侍;小司空;司平少常伯;冬官之貳;共工之貳"}        
    ]
}
```
| 屬性名| 屬性類型 | 說明 |
| ------ | ------ | ------ |
| total |  數字 | 數據總筆數 |
| start | 數字 | 當前數據開始筆數 |
| end | 數字 | 當前數據結束筆數 |
| data | 陣列 | 官職列表 |
| data[`i`].pId | 數字 | office_id |
| data[`i`].pName | 字符串 | 官職名，英文 |
| data[`i`].pNameChn| 字符串 | 官職名，中文 |  
| data[`i`].pNameChnAlt | 字符串 | 官職別名，中文 [OFFICE_CODES].[c_office_chn_alt] |  

## 六、根據入仕途徑中英文名獲取入仕途徑列表
### 輸入參數:
| 參數名| 參數類型 | 說明 |
| ------ | ------ | ------ |
| eName | 字符串 | 入仕途徑名稱，中文或英文，經過轉碼 |
| start | 數字 | 開始筆數 |
| list | 數字 | 列表長度 |
| accurate|數字|是否採用精確匹配，是=1，否=0|

### 輸入示例: 
`/api/entry_list_by_name?eName=%%E7%94%9F%E5%93%A1&start=1&list=2&accurate=0`搜尋所有名稱含有**生員**的入仕途徑,從第1筆開始，至多2筆結果    
`/api/entry_list_by_name?eName=%%E7%B8%A3%E5%AD%B8%E7%94%9F%E5%93%A1&start=1&list=2&accurate=1`精確匹配名稱為**縣學生員**的官職，從第1筆開始，至多2筆結果  

### 輸出格式:    
數據類型：`物件` 
示例：
```json
{
    "total":100,
    "start":0,
    "end":2,
    "data":[
        {"eId":"47","eName":"school: licentiate ","eNameChn":"學校：生員（庠生）"},
        {"eId":"173","eName":"county school student","eNameChn":"縣學生員"}        
    ]
}
```
| 屬性名| 屬性類型 | 說明 |
| ------ | ------ | ------ |
| total |  數字 | 數據總筆數 |
| start | 數字 | 當前數據開始筆數 |
| end | 數字 | 當前數據結束筆數 |
| data | 陣列 | 入仕途徑列表 |
| data[`i`].eId | 數字 | entry_code |
| data[`i`].eName | 字符串 | 入仕途徑名，英文 |
| data[`i`].eNameChn | 字符串 | 入仕途徑名，中文 |

## 七、查詢除授記錄（Office Postings）

### 輸入參數:

| 參數名         | 參數類型 | 說明                                            |
| -------------- | -------- | ----------------------------------------------- |
| office         | 陣列     | 要查詢的職官 ID 列表                            |
| useOfficePlace | 數字     | 是否啟用與職官相關的地點這一條件。是=1，否=0    |
| officePlace    | 陣列     | 與職官相關的地點列表                            |
| usePeoplePlace | 數字     | 是否啟用與人物相關的地點這一條件。是=1，否=0    |
| peoplePlace    | 陣列     | 與人物相關的地點列表                            |
| useDate        | 數字     | 是否採用日期這一條件，是=1，否=0                |
| dateType       | 字串     | 採用指數年抑或是朝代。指數年=index 朝代=dynasty |
| indexStartTime | 數字     | 指數年開始日期                                  |
| indexEndTime   | 數字     | 指數年結束日期                                  |
| dynStart       | 數字     | 開始朝代                                        |
| dynEnd         | 數字     | 結束朝代                                        |
| useXy          | 數字     | 是否使用 xy 座標，是=1，否=0                    |
| start          | 數字     | 結果開始筆數                                    |
| list           | 數字     | 列表長度                                        |

**注：`useOfficePlace` `usePeoplePlace` `useDate` 的優先級高於`officePlace` `peoplePlace` `indexYearStartTime` `indexYearEndTime` `dynStart` `dynEnd`，即若以`use`開頭的三個變數取值為 0，就不使用相應的條件（不論其陣列是否為空）**
**注：如果使用朝代作為條件，返回的結果中應包括`dynStart` `dynEnd`中指定的朝代**

### 輸入示例:

**注：採用 POST 方法，Content-Type: application/json**
`/api/query_office_postings`

```json
RequestPayload:{
    "office":[920,1022,1023],
    "useOfficePlace":0,
    "officePlace":[],
    "usePeoplePlace":1,
    "peoplePlace":[2928,10522,12553,13947,13949],
    "useDate":1,
    "dateType":"index",
    "indexStartTime":960,
    "indexEndTime":1250,
    "dynStart":null,
    "dynEnd":null,
    "useXy":1,
    "start":11,
    "list":10
}
```

#### 查詢示例 (by POST)

```
https://input.cbdb.fas.harvard.edu/api/query_office_postings?RequestPayload={"office":[920,1022,1023],"useOfficePlace":0,"officePlace":[],"usePeoplePlace":0,"peoplePlace":[],"useDate":0,"dateType":"index","indexStartTime":960,"indexEndTime":1250,"dynStart":null,"dynEnd":null,"useXy":0,"start":0,"list":65535}
```

說明：查找所有曾擔任宰相、左丞相、右丞相（宋朝），且人物地點為興化/興化軍，指數年介於 960 和 1250 年間的人的任官記錄。返回結果的第 11 筆到第 20 筆。

### 輸出格式:

數據類型：`物件`
示例

```json
{
    "total":100,
    "start":11,
    "end":20,
    "data":[
        {"PersonID":332,"Name": "Zhang Yong", "NameChn": "張詠", "Sex": "M", "IndexYear": 1005, "AddrID": 11273, "AddrType":"Basic Affiliation","AddrTypeChn":"籍貫（基本地址）","AddrName": "Juancheng", "AddrChn": "鄄城", "X": 115.5583, "Y": 35.61019, "OfficeCode":2383,"OfficeName":"Left Assistant Director of the Department of Affairs(Hucker)","OfficeNameChn":"尚書省左丞","FirstYear":1010,"LastYear":0,"Dynasty":"未詳","OfficeAddrID":0,"OfficeAddrName":"[Unknown]","OfficeAddrChn":"[未詳]","OfficeX":"","OfficeY":"","office_xy_count": " 6", "PostingID":63598,"ApptType":"","ApptTypeChn":"","AssumedOffice":"","AssumedOfficeChn":"","Notes":"LZL MasterFileLineID12259"}
        ...
        ]
}
```

| 屬性名                        | 屬性類型 | 說明             |
| ----------------------------- | -------- | ---------------- |
| total                         | 數字     | 數據總筆數       |
| start                         | 數字     | 當前數據開始筆數 |
| end                           | 數字     | 當前數據結束筆數 |
| data                          | 陣列     | 除授記錄列表     |
| data[`i`].PersonID            | 數字     | 人物 ID          |
| data[`i`].Name                | 字符串   | 人物名，英文     |
| data[`i`].NameChn             | 字符串   | 人物名，中文     |
| data[`i`].Sex                 | 字符串   | 人物性別         |
| data[`i`].IndexYear           | 數字     | 人物指數年       |
| data[`i`].AddrID              | 數字     | 人物地點 ID      |
| data[`i`].AddrType            | 字符串   | 地點類型，英文   |
| data[`i`].AddrTypeChn         | 字符串   | 地點類型，中文   |
| data[`i`].AddrName            | 字符串   | 人物地點，英文   |
| data[`i`].AddrChn             | 字符串   | 人物地點，中文   |
| data[`i`].X                   | 數字     | 人物地點經度座標 |
| data[`i`].Y                   | 數字     | 人物地點緯度座標 |
| data[`i`].OfficeCode          | 數字     | 官職 ID          |
| data[`i`].OfficeName          | 字符串   | 官職名，英文     |
| data[`i`].OfficeNameChn       | 字符串   | 官職名，中文     |
| data[`i`].FirstYear           | 數字     | 任官開始年       |
| data[`i`].LastYear            | 數字     | 任官結束年       |
| data[`i`].Dynasty             | 字符串   | 朝代             |
| data[`i`].OfficeAddrID        | 數字     | 官職地點 ID      |
| data[`i`].OfficeAddrName      | 字符串   | 官職地點名，英文 |
| data[`i`].OfficeAddrChn       | 字符串   | 官職地點名，中文 |
| data[`i`].OfficeX             | 數字     | 官職地點經度座標 |
| data[`i`].OfficeY             | 數字     | 官職地點緯度座標 |
| data[`i`].office_xy_count     | 數字     | 職官地址數       |
| data[`i`].PostingID           | 數字     | 除授記錄         |
| data[`i`].ApptType            | 字符串   | 除授類型，英文   |
| data[`i`].ApptTypeChn         | 字符串   | 除授類型，中文   |
| data[`i`].AssumptionOffice    | 字符串   | 赴任情況，英文   |
| data[`i`].AssumptionOfficeChn | 字符串   | 赴任情況，中文   |
| data[`i`].Notes               | 字符串   | 備註             |

#### 補充說明：

若要忽略地址只檢索官職，示例如下：

宋代所有地區的知州(office_id = 950)

```/api/query_office_postings?RequestPayload={"office":[950],"useOfficePlace":0,"officePlace":[],"usePeoplePlace":0,"peoplePlace":[],"useDate":0,"dateType":"index","indexStartTime":960,"indexEndTime":1250,"dynStart":null,"dynEnd":null,"useXy":0,"start":0,"list":65535}```

若要忽略官職檢索本地區的所有任官者，可使用「通過地區查詢」API 進行檢索。（「查詢除授記錄（Office Postings）」API 中官職 ID 是必填項。）

## 八、通過入仕途徑查詢人物

### 輸入參數:

| 參數名         | 參數類型 | 說明                                                                                       |
| -------------- | -------- | ------------------------------------------------------------------------------------------ |
| entry          | 陣列     | 要查詢的入仕途徑 ID 列表                                                                   |
| usePeoplePlace | 數字     | 是否啟用與人物相關的地點這一條件。是=1，否=0                                               |
| peoplePlace    | 陣列     | 與人物相關的地點列表                                                                       |
| locationType   | 字符串   | 與人物相關的地點的類型 pAddr 為僅查找人物地點 eAddr 為僅查找入仕地點 peAddr 為同時查找兩者 |
| useDate        | 數字     | 是否採用年份這一條件，是=1，否=0                                                           |
| dateType       | 字符串   | 年份類型 entry 為入仕年 index 為指數年 dynasty 為朝代                                      |
| dateStartTime  | 數字     | 年份開始日期                                                                               |
| dateEndTime    | 數字     | 年份結束日期                                                                               |
| dynStart       | 數字     | 朝代開始                                                                                   |
| dynEnd         | 數字     | 朝代結束                                                                                   |
| useXy          | 數字     | 是否使用 xy 座標，是=1，否=0                                                               |
| start          | 數字     | 結果開始筆數                                                                               |
| list           | 數字     | 列表長度                                                                                   |

**注：`usePeoplePlace` `useDate` 的優先級高於`peoplePlace` `locationType` `dateType` `dateStartTime` `dateEndTime`，即若以`use`開頭的 2 個變數取值為 0，就不使用後面的條件（不論陣列是否為空）**
**注：當 `dateType` 取值為 `entry` 或 `index` 時，僅考慮 `dateStartTime` 與 `dateEndTime` 兩個字段的值，不考慮 `dynStart` 與 `dynEnd` 的取值。反之， 當 `dateType` 取值為 `dynasty` 時，僅考慮 `dynStart` 與 `dynEnd` 的取值，不考慮 `dateStartTime` 與 `dateEndTime` 兩個字段的值**

### 輸入示例:

**注：採用 POST 方法，Content-Type: application/json**
`/api/query_entry_postings`

```json
RequestPayload:{
    "entry": [36],
    "usePeoplePlace": 0,
    "peoplePlace":[],
    "locationType": "peAddr",
    "useDate": 1,
    "dateType": "entry",
    "dateStartTime": 1368,
    "dynStart": null,
    "dynEnd": null,
    "dateEndTime": 1644,
    "useXy": 1,
    "start": 1,
    "list": 10
}
```

#### 查詢示例 (by POST)

```https://input.cbdb.fas.harvard.edu/api/query_entry_postings?RequestPayload={"entry":[36],"usePeoplePlace":0,"peoplePlace":[],"locationType":"peAddr","useDate":1,"dateType":"entry","dateStartTime":1368,"dynStart":null,"dynEnd":null,"dateEndTime":1644,"useXy":1,"start":1,"list":10}```

說明：查找入仕途徑為科舉：進士（籠統）且入仕年介於 1368-1644 的所有人物。返回第 1-10 筆結果

```json
RequestPayload:{
    "entry":[36],
    "usePeoplePlace":0,
    "peoplePlace":[],
    "locationType":"peAddr",
    "useDate":1,
    "dateType":"entry",
    "dateStartTime":null,
    "dateEndTime":null,
    "dynStart": 17,
    "dynEnd": 20,
    "useXy":1,
    "start":1,
    "list":10
}
```

#### 查詢示例 (by POST)

```
https://input.cbdb.fas.harvard.edu/api/query_entry_postings?RequestPayload={"entry":[36],"usePeoplePlace":0,"peoplePlace":[],"locationType":"peAddr","useDate":1,"dateType":"entry","dateStartTime":1115,"dynStart":17,"dynEnd":20,"dateEndTime":1644,"useXy":1,"start":1,"list":10}
```

說明：查找入仕途徑為科舉：進士（籠統）且入仕年介於 金朝 到 清朝 的所有人物。返回第 1-10 筆結果

### 輸出格式:

數據類型：`物件`
示例

```json
{
    "total":100,
    "start":1,
    "end":10,
    "data":[
        {"PersonID":26219,"Name": "Sheng Yingyang", "NameChn": "盛應陽", "Sex": "M", "IndexYear": 1553, "EntryDesc":"examination: jinshi (general)","EntryChn":"科舉: 進士(籠統)","EntryYear":1526,"EntryRank":0,"KinType":"U","KinName":"Wei Xiang","KinChn":"未詳","Association":"[Undefined]","AssocName":"Wei Xiang","AssocChn":"未詳","AddrType":"Basic Affiliation","AddrTypeChn":"籍貫（基本地址）","AddrName": "Wu Xian", "AddrChn": "吳縣", "X": 120.61862, "Y": 31.31271,"xy_count":17,"ParentState":"[Unknown]","ParentStateChn":"未詳","EntryPlace":"","EntryPlaceChn":"","EntryX":"","EntryY":"","entry_xy_count":""}
        ...
        ]
}
```

| 屬性名| 屬性類型 | 說明 |
| ------ | ------ | ------ |
| total |  數字 | 數據總筆數 |
| start | 數字 | 當前數據開始筆數 |
| end | 數字 | 當前數據結束筆數 |
| data | 陣列 | 除授記錄列表 |
| data[`i`].PersonID | 數字 | 人物ID |
| data[`i`].Name | 字符串 | 人物名，英文 |
| data[`i`].NameChn | 字符串 | 人物名，中文 |
| data[`i`].Sex | 字符串 | 人物性別 |
| data[`i`].IndexYear | 數字 | 人物指數年 |
| data[`i`].EntryDesc | 字符串 | 入仕途徑，英文 |
| data[`i`].EntryChn | 字符串 | 入仕途徑，中文 |
| data[`i`].EntryYear | 數字 | 入仕年 |
| data[`i`].EntryRank | 數字 | 考試排名 |
| data[`i`].KinType | 字符串 | 親屬關係 |
| data[`i`].KinName | 字符串 | 親屬姓名，英文 |
| data[`i`].KinChn | 字符串 | 親屬姓名，中文 |
| data[`i`].Association | 字符串 | 社會關係 |
| data[`i`].AssocName | 字符串 | 社會關係人姓名，英文 |
| data[`i`].AssocChn | 字符串 | 社會關係人姓名，中文 |
| data[`i`].AddrID | 數字 | 人物地點ID |
| data[`i`].AddrName | 字符串 | 人物地點，英文 |
| data[`i`].AddrChn | 字符串 | 人物地點，中文 |
| data[`i`].X | 數字 | 人物地點經度座標 |
| data[`i`].Y | 數字 | 人物地點緯度座標 |
| data[`i`].xy_count | 數字 | 結果同一人物地點的人物數 |
| data[`i`].ParentState | 字符串 | 父母情況，英文 |
| data[`i`].ParentStateChn | 字符串 | 父母情況，中文 |
| data[`i`].EntryPlace | 字符串 | 入仕地點，英文 |
| data[`i`].EntryPlaceChn | 字符串 | 入仕地點，中文 |
| data[`i`].EntryX | 數字 | 入仕地點經度座標 |
| data[`i`].EntryY | 數字 | 入仕地點緯度座標 |
| data[`i`].dynasty | 數字 | 朝代 英文 |
| data[`i`].dynastyChn| 數字 | 朝代 中文 |
| data[`i`].entry_xy_count | 數字 | 結果同一入仕地點的人物數 |


## 九、根據給定人物陣列查詢人物親屬
### 輸入參數:
數據類型：`物件`
| 參數名| 參數類型 | 說明 |
| ------ | ------ | ------ |
| people | 陣列 | 要查詢的人物ID列表 |
| mCircle|數字|是否採用五服查詢，是=1，否=0|
| MAncGen | 數字 | 最大祖先距離 |
| MDecGen | 數字 | 最大後代距離 |
| MColLink | 數字 | 最大同輩距離 |
| MMarLink | 數字 | 最大姻親距離 |
| MLoop | 數字 | 最大循環次數 |

**注：`mCircle` 的優先級高於`MAncGen` `MDecGen` `MColLink` `MMarLink` `MLoop `，即若以`mCircle`開頭的變數取值為0，則查詢列表人物的五服親屬，不論`MAncGen` `MDecGen` `MColLink` `MMarLink` `MLoop `取值為何**
### 輸入示例: 
**注：採用POST方法，Content-Type: application/json**
`/api/query_relatives_2`
```json
RequestPayload:{
    "people":[1762],
    "mCircle":0,
    "MAncGen":1,
    "MDecGen":1,
    "MColLink":1,
    "MMarLink":1,
    "MLoop":2
}
```

#### 查詢示例 (by POST)

```
https://input.cbdb.fas.harvard.edu/api/query_relatives_2?RequestPayload={"people":[1762],"mCircle":0,"MAncGen":1,"MDecGen":1,"MColLink":1,"MMarLink":1,"MLoop":2}
```

說明：查找王安石的親屬，採用自定義參數查找。最大向上1層，最大向下1層，最大同輩關係為1層，最大婚姻關係為1層   

### 預期輸出示例:    
數據類型：`物件` 
```json
{
    "total":100,
    "start":1,
    "end":2,
    "data":[
        {"rId":"1762","rName":"Wang Anshi","rNameChn":"王安石","pId":"1762","pName":"Wang Anshi","pNameChn":"王安石","pAddrID":"100513","pAddrType":"Basic Affiliation","pAddrTypeChn":"籍貫（基本地址）","pAddrName":"Linchuan","pAddrNameChn":"臨川","pX":"116.351341","pY":"27.984781","Id":"7404","Name":"Ye Tao","NameChn":"葉濤","IndexYear":"1080","sex":"M","pkinship":"DH","rKinship":"DH","up":0,"down":1,"col":0,"mar":1,"AddrID":"100650","AddrType":"Basic Affiliation","AddrTypeChn":"籍貫（基本地址）","AddrName":"Longquan","AddrNameChn":"龍泉","X":"119.12091","Y":"28.082565","xy_count":1,"pDistance":"272.04077877","rDistance":"272.04077877","KinRelCal":"","Notes":"據宋史列傳CBDB宋史分傳#1055"},
        {"rId":"1762","rName":"Wang Anshi","rNameChn":"王安石","pId":"1760","pName":"Wang Anli","pNameChn":"王安禮","pAddrID":"100513","pAddrType":"Basic Affiliation","pAddrTypeChn":"籍貫（基本地址）","pAddrName":"Linchuan","pAddrNameChn":"臨川","pX":"116.351341","pY":"27.984781","Id":"20583","Name":"Wang Jian","NameChn":"王瑊","IndexYear":"1093","sex":"M","pkinship":"SSS","rKinship":"BSSS","up":0,"down":3,"col":1,"mar":0,"AddrID":"100513","AddrType":"Basic Affiliation","AddrTypeChn":"籍貫（基本地址）","AddrName":"Linchuan","AddrNameChn":"臨川","X":"116.351341","Y":"27.984781","xy_count":17,"pDistance":"0","rDistance":"0","KinRelCal":"","Notes":""}       
    ]
}
```
| 屬性名| 屬性類型 | 說明 |
| ------ | ------ | ------ |
| total |  數字 | 數據總筆數 |
| start | 數字 | 當前數據開始筆數 |
| end | 數字 | 當前數據結束筆數 |
| data | 陣列 | 結果列表 |
| data[`i`].rId | 數字 | 中心人物ID |
| data[`i`].rName | 字符串 | 中心人物名，英文 |
| data[`i`].rNameChn | 字符串 | 中心人物名，中文 ||
| data[`i`].Id | 數字 | 親屬關係目標人物ID |
| data[`i`].Name | 字符串 | 親屬關係目標人物名，英文 |
| data[`i`].NameChn | 字符串 | 親屬關係目標人物名，中文 |
| data[`i`].Sex | 字符串 | 親屬關係目標人物性別 |
| data[`i`].IndexYear | 數字 | 親屬關係目標人物指數年 |
| data[`i`].rKinship | 字符串 | 與中心人物的親屬關係 |
| data[`i`].up | 數字 | 向上查找的距離 |
| data[`i`].down | 數字 | 向下查找的距離 |
| data[`i`].col | 數字 | 同輩關係查找的距離 |
| data[`i`].mar | 數字 | 姻親關係查找的距離 |
| data[`i`].AddrID | 數字 | 親屬關係目標人物地點ID |
| data[`i`].AddrType | 字符串 | 親屬關係目標人物地點類型，英文 |
| data[`i`].AddrTypeChn | 字符串 | 親屬關係目標人物地點類型，中文 |
| data[`i`].AddrName | 字符串 |親屬關係目標人物地點名，英文 |
| data[`i`].AddrNameChn | 字符串 | 親屬關係目標人物地點名，中文 |
| data[`i`].X | 數字 | 親屬關係目標人物地點經度座標 |
| data[`i`].Y | 數字 | 親屬關係目標人物地點緯度座標 |
| data[`i`].rDistance | 數字 | 親屬關係目標人物所的點與中心人物的地點之距離 |
| data[`i`].xy_count | 數字 | 結果中親屬關係目標人物所在地點的總人物數 |
| data[`i`].Notes  | 字符串 | 備註 |

**注：返回中心人物記錄時`rKinship`取值為`'ego'`，以p開頭的變數返回`''`（空字符串）即可**

## 十、根據社會關係類型代碼獲取社會關係
### 輸入參數:
| 參數名| 參數類型 | 說明 |
| ------ | ------ | ------ |
| aType| 數字 | 社會關係類型代碼 |

### 輸入示例: 
**注：採用GET方法**
`/api/get_assoc?aType=0406`
說明：獲取薦舉保任下的所有社會關係   
  
### 預期輸出示例:    
數據類型：`物件` 
```json
{
    "total":10,
    "start":1,
    "end":2,
    "data":[
        {"aId":"351","aName":"Posthumous titular office prosposed for","aNameChn":"提議封贈Y"},       
    ]
}
```
| 屬性名| 屬性類型 | 說明 |
| ------ | ------ | ------ |
| total |  數字 | 數據總筆數 |
| start | 數字 | 當前數據開始筆數 |
| end | 數字 | 當前數據結束筆數 |
| data | 陣列 | 結果列表 |
| data[`i`].aId | 數字 | 社會關係code |
| data[`i`].aName | 字符串 | 社會關係名，英文 |
| data[`i`].aNameChn | 字符串 | 社會關係名，中文 |

## 十一、查找社會關係
### 輸入參數:
| 參數名| 參數類型 | 說明 |
| ------ | ------ | ------ |
| aName| 字符串 | 社會關係名，中文或英文 |

### 輸入示例: 
**注：採用GET方法**
**注：採用模糊匹配**
`/api/find_assoc?aName=%E9%96%80%E4%BA%BA`
說明：匹配所有含有“門人”的記錄
  
### 預期輸出示例:    
數據類型：`物件` 
```json
{
    "total":10,
    "start":1,
    "end":2,
    "data":[
        {"aId":351,
        "aName":"Posthumous titular office prosposed for","aNameChn":"提議封贈Y"
        },       
    ]
}
```
| 屬性名| 屬性類型 | 說明 |
| ------ | ------ | ------ |
| total |  數字 | 數據總筆數 |
| start | 數字 | 當前數據開始筆數 |
| end | 數字 | 當前數據結束筆數 |
| data | 陣列 | 結果列表 |
| data[`i`].aId | 數字 | 社會關係code |
| data[`i`].aName | 字符串 | 社會關係名，英文 |
| data[`i`].aNameChn | 字符串 | 社會關係名，中文 |

## 十二、查詢社會關係
### 輸入參數:
數據類型：`物件`
| 參數名| 參數類型 | 說明 |
| ------ | ------ | ------ |
| association | 陣列 | 要查詢的社會關係列表 |
| place|陣列|人物地點列表|
| usePeoplePlace | 數字 | 是否啟用人物地點列表，是=1，否=0 |
| useXy|數字|是否使用xy座標，是=1，否=0|
| indexYear|數字|是否採用指數年，是=1，否=0|
| indexStartTime|數字|指數年開始日期|
| indexEndTime|數字|指數年結束日期|
| broad|數字|行政區域範圍是廣義的還是狹義的。廣義=1，狹義=0。廣義 `+/- 0.06` 狹義 `+/- 0.03`|

**注：`usePeoplePlace` 的優先級高於`place`，即若以`usePeoplePlace`開頭的變數取值為0，則不採用人物地點列表，不論其取值是否為空**
### 輸入示例: 
**注：採用POST方法，Content-Type: application/json**
`/api/query_associates`
```json
RequestPayload:{
    "association":[22],
    "place":[101125],
    "usePeoplePlace":1,
    "useXy":1,
    "indexYear":1,
    "indexStartTime":960,
    "indexEndTime":1250,
    "broad":0
}
```

#### 查詢示例 (by POST)

```
https://input.cbdb.fas.harvard.edu/api/query_associates?RequestPayload={"association":[22],"place":[101125],"usePeoplePlace":1,"useXy":1,"indexYear":1,"indexStartTime":960,"indexEndTime":1250,"broad":0}
```

說明：查找滿足下列關係的Y：所有指數年在960年至1250年間，地點在“建州”的人物（X），其有“為Y之學生”關係。換句話說就是：查找960年至1250年間且地點在“建州”的人物（X）的老師（Y）  

`/api/query_associates`
```json
RequestPayload:{
    "association":[23],
    "place":[101125],
    "usePeoplePlace":1
}
```

#### 查詢示例 (by POST)

```
https://input.cbdb.fas.harvard.edu/api/query_associates?RequestPayload={"association":[23],"place":[101125],"usePeoplePlace":1,"useXy":1,"indexYear":0,"indexStartTime":null,"indexEndTime":null,"broad":0}
```

說明：查找滿足下列關係的Y：所有地點在“建州”的人物（X），其有“為Y之老師”關係。換句話說就是要查找地點在“建州”的人物（X）的學生（Y）  

### 預期輸出示例:    
數據類型：`物件` 
```json
{
    "total":100,
    "start":1,
    "end":2,
    "data":[
        {"pId":"46398","pName":"Liang Zhuan","pNameChn":"梁瑑","aId":"3257","aName":"Zhu Xi","aNameChn":"朱熹","pIndexYear":"","pSex":"M","aIndexYear":"1189","aSex":"M","pAddrID":"100577","pAddrName":"Shaowu","pAddrNameChn":"邵武","pX":"114.483398","pY":"27.337692","aAddrID":"101125","aAddrName":"Jianzhou","aAddrNameChn":"建州","aX":"118.32378387","aY":"27.038864136","pKinshipRelation":"U","pKinshipRelationChn":"未詳","pKinName":"Wei Xiang","pKinNameChn":"未詳","aKinshipRelation":"U","aKinshipRelationChn":"未詳","aKinName":"Wei Xiang","aKinNameChn":"未詳","distance":"89.516896410","p_xy_count":17,"a_xy_count":7,"KinRelCal":""},       
    ]
}
```
| 屬性名| 屬性類型 | 說明 |
| ------ | ------ | ------ |
| total |  數字 | 數據總筆數 |
| start | 數字 | 當前數據開始筆數 |
| end | 數字 | 當前數據結束筆數 |
| data | 陣列 | 結果列表 |
| data[`i`].pId | 數字 | 中心人物ID |
| data[`i`].pName | 字符串 | 中心人物名，英文 |
| data[`i`].pNameChn | 字符串 | 中心人物名，中文 |
| data[`i`].pSex | 字符串 | 中心人物性別 |
| data[`i`].pIndexYear | 數字 | 中心人物指數年 |
| data[`i`].pAddrID | 數字 | 中心人物的地點ID |
| data[`i`].pAddrName | 字符串 | 中心人物的地點名，英文 |
| data[`i`].pAddrNameChn | 字符串 | 中心人物的地點名，中文 |
| data[`i`].pX | 數字 | 中心人物的地點經度座標 |
| data[`i`].pY | 數字 | 中心人物的地點緯度座標 |
| data[`i`].p_xy_count | 數字 | 結果中中心人物所在地點的總人物數| 
| data[`i`].pKinshipRelation | 字符串 | 中心人物的親屬關係名，英文 |
| data[`i`].pKinshipRelationChn | 字符串 | 中心人物的親屬關係名，中文 |
| data[`i`].pKinName | 字符串 | 中心人物的親屬姓名，英文 |
| data[`i`].pKinNameChn | 字符串 | 中心人物的親姓名，中文 |
| data[`i`].pAddrNameChn | 字符串 | 中心人物的地點名，中文 |
| data[`i`].aId | 數字 | 社會關係人ID |
| data[`i`].aName | 字符串 | 社會關係人物名，英文 |
| data[`i`].aNameChn | 字符串 | 社會關係人物名，中文 |
| data[`i`].aSex | 字符串 | 社會關係人物性別 |
| data[`i`].aIndexYear | 數字 | 社會關係人物指數年 |
| data[`i`].aAddrID | 數字 | 社會關係人地點ID |
| data[`i`].aAddrName | 字符串 |社會關係人地點名，英文 |
| data[`i`].aAddrNameChn | 字符串 | 社會關係人地點名，中文 |
| data[`i`].aX | 數字 | 社會關係人地點經度座標 |
| data[`i`].aY | 數字 | 社會關係人地點緯度座標 |
| data[`i`].a_xy_count | 數字 |結果中社會關係人所在地點的總人物數 人物數 |
| data[`i`].aKinshipRelation | 字符串 | 社會關係人的親屬關係名，英文 |
| data[`i`].aKinshipRelationChn | 字符串 | 社會關係人的親屬關係名，中文 |
| data[`i`].aKinName | 字符串 | 社會關係人的親屬姓名，英文 |
| data[`i`].aKinNameChn | 字符串 | 社會關係人的親姓名，中文 |
| data[`i`].distance | 數字 | 中心人物與社會關係人之間的距離|

## 十三、查詢社會關係網路

### 輸入參數:

數據類型：`物件`

| 參數名| 參數類型 | 說明 |
| ------ | ------ | ------ |
| people | 陣列 | 要查詢的人物ID列表 |
| assocCode | 陣列 | 要查詢的社會關係代碼 |
| assocType | 陣列 | 要查詢的社會關係大類 |
| maxNodeDist | 數字 | 最大N度分隔步數。取值為 0, 1, 2. 默認為 1 |
| place | 陣列 | 人物地點列表|
| usePeoplePlace | 數字 | 是否啟用人物地點列表，是=1，否=0. 默認為 0 |
| useXy | 數字 | 是否使用xy座標，是=1，否=0. 默認為 0 |
| broad |數字 | 行政區域範圍是廣義的還是狹義的。廣義=1，狹義=0. 廣義 `+/- 0.06` 狹義 `+/- 0.03`. 默認為 0 |
| indexYear | 數字 | 是否採用指數年，是=1，否=0. 默認為 0 |
| indexStartTime | 數字 | 指數年開始日期 |
| indexEndTime | 數字 | 指數年結束日期 |
| useDy | 數字 | 是否採用朝代，是=1，否=0. 默認為 0 |
| dynStart | 數字 | 開始朝代 |
| dynEnd | 數字 | 結束朝代 |
| includeMale | 數字 | 是否包含男性，是=1，否=0. 默認為 1 |
| includeFemale | 數字 | 是否包含女性，是=1，否=0. 默認為 1 |
| start | 數字 | 開始筆數 |
| list | 數字 | 列表長度 |

#### 輸入示例:

注：採用POST方法，Content-Type: application/json ```/api/query_assoc_network```

```
RequestPayload:{
    "people":[1762, 3767],
    "assocCode": [429]
    "assocType":[02],
    "maxNodeDist":1,
    "place":[13305],
    "usePeoplePlace":1,
    "broad":0,
    "useDy":1,
    "dynStart":15,
    "dynEnd":15,
    "includeMale",1,
    "includeFemale",1,
}
```

#### 查詢示例 (by POST)

```
https://input.cbdb.fas.harvard.edu/api/query_assoc_network?RequestPayload={"people":[1762,3767],"assocCode":[429],"assocType":["02"],"maxNodeDist":1,"place":[13305],"usePeoplePlace":1,"broad":0,"useDy":1,"dynStart":15,"dynEnd":15,"includeMale":1,"includeFemale":1, "start":0, "list":10}
```

說明：查找王安石（1762）和蘇軾（3767）的社會網路，查詢條件是所有和他們之間有直接（單步關係 "usePeoplePlace":1）的社會關係為：致書（ "assocCode": [429]）和學術關係（"assocType":[02]）的宋代（"dynStart":15,"dynEnd":15,）眉山（"place":[13305]）附近（"broad":0）的人物。並且查詢出這些人物之間的關係。例如，蘇軾致書的對象弟弟蘇轍 1493，以及蘇軾的老師劉巨 26417. 這兩個人物自己其實也有關係（劉巨也是蘇轍的老師）。在蘇軾的 maxNodeDist = 1 查詢中也希望呈現劉巨和蘇轍的關係。

##### 查詢細節：

##### 在 ASSOC_DATA 表中透過以下多條件(and 關係)進行查詢（以 maxNodeDist = 1 為例）

- c_personid 欄位的為值 1762 與 3767。（王安石和蘇軾）

- c_assoc_code 欄位為 429; 以及符合 assocType 為 02 的所有 c_assoc_code, 查詢方法如下：

```
SELECT ASSOC_CODE_TYPE_REL.c_assoc_code
FROM ASSOC_CODE_TYPE_REL
WHERE ASSOC_CODE_TYPE_REL.c_assoc_type_id in
(SELECT ASSOC_TYPES.c_assoc_type_id FROM ASSOC_TYPES WHERE ASSOC_TYPES.c_assoc_type_parent_id = '02')
```

- 使用 ASSOC_DATA.c_assoc_id join 到 BIOG_MAIN 的 c_personid, 查詢所有 c_dy 為 15 的記錄（c_personid 和 c_assoc_id 必須都為 15）

- 使用 ASSOC_DATA.c_personid, ASSOC_DATA.c_assoc_id join 到 BIOG_MAIN 的 c_index_addr_id, 查詢所有 c_index_addr_id 為 13305 的記錄。broad 的演算法與「查詢社會關係網路」相同：使用 c_index_addr_id join 到 ADDR_CODES.c_addr_id, 找到 x_coord, y_coord. 透過廣義 `+/- 0.06` 狹義 `+/- 0.03` 從 ADDR_CODES 獲取範圍內的 c_addr_id. 再使用獲得的 c_addr_id 作為條件，過濾 BIOG_MAIN 的 c_index_addr_id 對應 personid 的 ASSOC_DATA.c_assoc_id 記錄。

- 將查詢到的所有人物置入 ASSOC_DATA 查詢所有人物之間的關係。

  ##### 過濾條件為 OR 連接的三個條件：
    
  - 若此人物 ASSOC_DATA.c_personid 在 URL 的 "people":[...] 中，且此人在 ASSOC_DATA 中有符合條件之記錄，那麼
      
      - 若其關係人 ASSOC_DATA.c_assoc_id 不在 "people":[...] 中，其關係人 ASSOC_DATA.c_assoc_id 之 c_addr_id 需為 13305.
      
      - 若其關係人 ASSOC_DATA.c_assoc_id 亦在 "people":[...] 中，則此條 ASSOC_DATA 記錄需要保留。（此狀況中，關係中雙方 c_addr_id 可均非 13305）.
        
  - 若此關係人 ASSOC_DATA.c_assoc_id 在 URL 的 "people":[...] 中，且此人在 ASSOC_DATA 中有符合條件之記錄，那麼
      
      - 若其 ASSOC_DATA.c_personid 不在 "people":[...] 中，此 ASSOC_DATA.c_person 之 c_addr_id 需為 13305.
      
      - 若其 ASSOC_DATA.c_personid 亦在 "people":[...] 中，則此條 ASSOC_DATA 記錄需要保留。（此狀況中，關係中雙方 c_addr_id 可均非 13305）.
        
  - ASSOC_DATA.c_personid, ASSOC_DATA.c_assoc_id 的 c_addr_id 都需要為 13305
    
##### 關於 maxNodeDist 的設定

- 當 maxNodeDist 為 0 時，查詢 people（本例中為 "people":[1762, 3767]）中相互的社會網路關係，即 ASSOC_DATA 中 c_personid 与 c_assoc_id 均为 people（本例中為 "people":[1762, 3767]）陣列裡的 id. 來自使用者的地址、時間、性別等查詢條件均有效。其地址限制條件與[過濾條件為 OR 連接的三個條件](https://github.com/cbdb-project/cbdb-online-main-server/blob/develop/API.md#%E9%81%8E%E6%BF%BE%E6%A2%9D%E4%BB%B6%E7%82%BA-or-%E9%80%A3%E6%8E%A5%E7%9A%84%E4%B8%89%E5%80%8B%E6%A2%9D%E4%BB%B6)一致

- 當 maxNodeDist 為 2 時，首先查詢方式和 maxNodeDist = 1 相同。在獲得的 maxNodeDist = 1 查詢結果中，將 ASSOC_DATA.c_assoc_id 作為 ASSOC_DATA.personid, 再進行一次 maxNodeDist = 1 查詢。（一定當心不要重覆查回前一次查詢的結果。即避免 a>>b, b>>a）將兩次查詢的結果合併，以 c_personid, c_assoc_id, c_assoc_code, c_text_title 去重。其地址限制條件與[過濾條件為 OR 連接的三個條件](https://github.com/cbdb-project/cbdb-online-main-server/blob/develop/API.md#%E9%81%8E%E6%BF%BE%E6%A2%9D%E4%BB%B6%E7%82%BA-or-%E9%80%A3%E6%8E%A5%E7%9A%84%E4%B8%89%E5%80%8B%E6%A2%9D%E4%BB%B6)一致

- 當 maxNodeDist 大於 2 時，返回：「API 暫不支援 maxNodeDist 大於 2 之查詢」。考慮到 maxNodeDist 帶來的運算量以及返回資料的數量，線上 API 暫時忽略 maxNodeDist > 2 的查詢請求

注：在查詢結果中，希望包含被查詢出來所有人之間的關係。譬如，蘇軾的老師是劉巨，蘇軾曾經致書蘇轍，***蘇轍的老師也是劉巨***。我們在用蘇軾作為查詢對象查 maxNodeDist >= 1 的時候，不僅希望能看到蘇軾的老師是劉巨，蘇軾曾經致書蘇轍，也希望能查到蘇轍的老師也是劉巨。

### 預期輸出示例:  

數據類型：`物件` 

```json
{
    "total":100,
    "start":1,
    "end":2,
    "data":[
        {"pId":"1493","pName":"Su Zhe","pNameChn":"蘇轍","aId":"3257","aName":"Liu Ju(2)","aNameChn":"劉巨","pIndexYear":"1039","pSex":"M","aIndexYear":"","aSex":"M","pAddrID":"13305","pAddrName":"Meishan","pAddrNameChn":"眉山","pX":"103.831459","pY":"30.050497","aAddrID":"13305","aAddrName":"Meishan","aAddrNameChn":"眉山","aX":"103.831459","aY":"30.050497","pAssocRelationId":"22", "pAssocRelation":"Student of","pAssocRelationChn":"為Y之學生","distance":"0","count":1},       
    ]
}
```

| 屬性名                        | 屬性類型 | 說明                   |  對應欄位  |
| ---------------------------- | -------- | ---------------------- | --------- |
| total                        | 數字     | 數據總筆數              |           |
| start                        | 數字     | 當前數據開始筆數         |           |
| end                          | 數字     | 當前數據結束筆數         |           |
| data                         | 陣列     | 除授記錄列表            |           |
| data[`i`].pId                | 數字     | 人物 ID                 | ASSOC_DATA.c_personid  |
| data[`i`].pName              | 字串     | 人物名，英文            | ASSOC_DATA.c_personid join BIOG_MAIN.c_personid to get BIOG_MAIN.c_name |
| data[`i`].pNameChn           | 字串     | 人物名，中文           | ASSOC_DATA.c_personid join BIOG_MAIN.c_personid to get BIOG_MAIN.c_name_chn |
| data[`i`].aId                | 數字     | 社會關係人 ID          | ASSOC_DATA.c_assoc_id |
| data[`i`].aName              | 字串     | 社會關係人名，英文      | ASSOC_DATA.c_assoc_id join BIOG_MAIN.c_personid to get BIOG_MAIN.c_name |
| data[`i`].aNameChn           | 字串     | 社會關係人名，中文      | ASSOC_DATA.c_assoc_id join BIOG_MAIN.c_personid to get BIOG_MAIN.c_name_chn |
| data[`i`].pIndexYear         | 數字     | 人物指數年            | ASSOC_DATA.c_personid join BIOG_MAIN.c_personid to get BIOG_MAIN.c_index_year |
| data[`i`].pSex               | 數字     | 人物性別              | ASSOC_DATA.c_personid join BIOG_MAIN.c_personid to BIOG_MAIN.get c_female |
| data[`i`].aIndexYear         | 數字     | 社會關係人指數年       | ASSOC_DATA.c_assoc_id join BIOG_MAIN.c_personid to get BIOG_MAIN.c_index_year |
| data[`i`].aSex               | 數字     | 社會關係人性別         | ASSOC_DATA.c_assoc_id join BIOG_MAIN.c_personid to BIOG_MAIN.get c_female |
| data[`i`].pAddrID            | 數字     | 人物指數地址 ID        | ASSOC_DATA.c_personid join BIOG_MAIN.c_personid to get BIOG_MAIN.c_index_addr_id |
| data[`i`].pAddrName          | 字串     | 人物指數地址，英文      | Base on data[`i`].pAddrID join ADDR_CODES to get ADDR_CODES.c_name |
| data[`i`].pAddrNameChn       | 字串     | 人物指數地址，中文      | Base on data[`i`].pAddrID join ADDR_CODES to get ADDR_CODES.c_name_chn |
| data[`i`].pX                 | 數字     | 人物指數地址經度       | Base on data[`i`].pAddrID join ADDR_CODES to get ADDR_CODES.x_coord |
| data[`i`].pY                 | 數字     | 人物指數地址緯度       | Base on data[`i`].pAddrID join ADDR_CODES to get ADDR_CODES.y_coord |
| data[`i`].aAddrID            | 數字     | 社會關係人指數地址 ID  | ASSOC_DATA.c_assoc_id join BIOG_MAIN.c_personid to get BIOG_MAIN.c_index_addr_id |
| data[`i`].aAddrName          | 字串     | 社會關係人指數地址，英文| Base on data[`i`].aAddrID join ADDR_CODES to get ADDR_CODES.c_name | 
| data[`i`].aAddrNameChn       | 字串     | 社會關係人指數地址，中文| Base on data[`i`].aAddrID join ADDR_CODES to get ADDR_CODES.c_name_chn | 
| data[`i`].aX                 | 數字     | 社會關係人指數地址經度  | Base on data[`i`].aAddrID join ADDR_CODES to get ADDR_CODES.x_coord |
| data[`i`].aY                 | 數字     | 社會關係人指數地址緯度  | Base on data[`i`].aAddrID join ADDR_CODES to get ADDR_CODES.y_coord |
| data[`i`].pAssocRelationId   | 數字     | 社會關係類型 ID        | ASSOC_DATA.c_assoc_code |
| data[`i`].pAssocRelation     | 字串     | 社會關係類型，英文     | ASSOC_DATA.c_assoc_code join ASSOC_CODES to get ASSOC_CODES.c_assoc_desc |
| data[`i`].pAssocRelationChn  | 字串     | 社會關係類型，中文     | ASSOC_DATA.c_assoc_code join ASSOC_CODES to get ASSOC_CODES.c_assoc_desc_chn |
| data[`i`].distance           | 數字     | 人物與社會關係人之間距離 | The distance between (data[`i`].pX, data[`i`].pY) and (data[`i`].aX, data[`i`].aY) |
| data[`i`].count              | 數字     | 社會關係發生次數       | ASSOC_DATA.c_assoc_count |



## 十四、通過地區查詢

### 輸入參數:

| 參數名        | 參數類型 | 說明                                                                                                                                                                      |
| ------------- | -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| peoplePlace   | 陣列     | 要查詢的地點 ID 的陣列                                                                                                                                                    |
| placeType     | 陣列     | 地點類型的陣列。取值包括：`individual`人 `entry`入仕 `association` 社會關係`officePosting`職官 `institutional`社交機構 `kinship`親屬 `associate` 社會關係的人             |
| useDate       | 數字     | 是否啟用“時間”條件。1 代表啟用，0 代表不啟用。這一變數的優先級高於下面的 `dateType` `dateStartTime` ` dateEndTime` `dynStart` `dynEnd` 。如果其取值為 0，則無視上述參數。 |
| dateType      | 字串     | 時間條件的類型（指數年：`index`，朝代：`dynasty`）                                                                                                                        |
| dateStartTime | 數字     | 指數年開始日期期                                                                                                                                                          |
| dateEndTime   | 數字     | 指數年結束日期期                                                                                                                                                          |
| dynStart      | 數字     | 朝代開始                                                                                                                                                                  |
| dynEnd        | 數字     | 朝代結束                                                                                                                                                                  |
| useXy         | 數字     | 是否使用 xy 座標，是=1，否=0                                                                                                                                              |
| start         | 數字     | 結果開始筆數                                                                                                                                                              |
| list          | 數字     | 列表長度                                                                                                                                                                  |

**注：`useDate` 的優先級高於 `dateType` `dateStartTime` `dateEndTime` `dynStart` `dynEnd`，即若變數取值為 0，就不使用後面的條件（不論陣列是否為空）**
**注：當 `dateType` 取值為 `entry` 或 `index` 時，僅考慮 `dateStartTime` 與 `dateEndTime` 兩個字段的值，不考慮 `dynStart` 與 `dynEnd` 的取值。反之， 當 `dateType` 取值為 `dynasty` 時，僅考慮 `dynStart` 與 `dynEnd` 的取值，不考慮 `dateStartTime` 與 `dateEndTime` 兩個字段的值**

### 輸入示例:

**注：採用 POST 方法，Content-Type: application/json**
`/api/query_place`

```json
RequestPayload:{
    "peoplePlace":[2928,10522,12553,13947,13949],
    "placeType": ["individual","entry","officePosting"],
    "useDate": 1,
    "dateType": "index",
    "dateStartTime": 1200,
    "dateEndTime": 1644,
    "dynStart": null,
    "dynEnd": null,
    "useXy": 1,
    "start": 1,
    "list": 10
}
```

#### 查詢示例 (by POST)

```
https://input.cbdb.fas.harvard.edu/api/query_place?RequestPayload={"peoplePlace":[2928,10522,12553,13947,13949],"placeType":["individual","entry","officePosting"],"useDate":1,"dateType":"index","dateStartTime":1200,"dateEndTime":1644,"dynStart":null,"dynEnd":null,"useXy":1,"start":1,"list":10}
```

說明：查找人物地點為`2928` `10522` `12553` `13947` `13949`，地點類型為“人”“入仕”“職官”，指數年年介於 1200-1644 的所有人物。返回第 1-10 筆結果

```json
RequestPayload:{
    "peoplePlace":[2928,10522,12553,13947,13949],
    "placeType": ["individual","entry","officePosting"],
    "useDate": 1,
    "dateType": "dynasty",
    "dateStartTime": null,
    "dateEndTime": null,
    "dynStart": 17,
    "dynEnd": 22,
    "useXy": 1,
    "start": 1,
    "list": 10
}
```

#### 查詢示例 (by POST)

```
https://input.cbdb.fas.harvard.edu/api/query_place?RequestPayload={"peoplePlace":[2928,10522,12553,13947,13949],"placeType":["individual","entry","officePosting"],"useDate":1,"dateType":"dynasty","dateStartTime":null,"dateEndTime":null,"dynStart":17,"dynEnd":22,"useXy":1,"start":1,"list":10}
```

說明：查找人物地點為`2928` `10522` `12553` `13947` `13949`，地點類型為“人”“入仕”“職官”，朝代介於 金朝 到 清朝 的所有人物。返回第 1-10 筆結果

### 輸出格式:

數據類型：`物件`
示例

```json
{
    "total":100,
    "start":1,
    "end":10,
    "data":[
        {},
        ...
        ]
}
```

| 屬性名                       | 屬性類型 | 說明                   |
| ---------------------------- | -------- | ---------------------- |
| total                        | 數字     | 數據總筆數             |
| start                        | 數字     | 當前數據開始筆數       |
| end                          | 數字     | 當前數據結束筆數       |
| data                         | 陣列     | 除授記錄列表           |
| data[`i`].PersonID           | 數字     | 人物 ID                |
| data[`i`].Name               | 字串     | 人物名，英文           |
| data[`i`].NameChn            | 字串     | 人物名，中文           |
| data[`i`].Sex                | 字串     | 人物性別               |
| data[`i`].IndexYear          | 數字     | 人物指數年             |
| data[`i`].IndexYearType      | 字串     | 指數年類型，英文       |
| data[`i`].IndexYearTypeChn   | 字串     | 指數年類型，中文       |
| data[`i`].IndexYearCode      | 字串     | 指數年類型代碼         |
| data[`i`].PlaceName          | 字串     | 地址名稱，英文         |
| data[`i`].PlaceNameChn       | 字串     | 地址名稱，中文         |
| data[`i`].PlaceAssocName     | 字串     | 地區關係人姓名，英文   |
| data[`i`].PlaceAssocChn      | 字串     | 地區關係人姓名，中文   |
| data[`i`].PlaceAssocStart    | 數字     | 地區關係開始年        |
| data[`i`].PlaceAssocEnd      | 數字     | 地區關係結束年        |
| data[`i`].PlaceType          | 字串     | 地址關係類別           |
| data[`i`].PlaceTypeDetail    | 字串     | 地址關係詳細類別，英文 |
| data[`i`].PlaceTypeDetailChn | 字串     | 地址關係詳細類別，中文 |
| data[`i`].X                  | 數字     | 人物地點經度座標       |
| data[`i`].Y                  | 數字     | 人物地點緯度座標       |
