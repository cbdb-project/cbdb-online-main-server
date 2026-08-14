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
| 六、新增記錄 | `POST /api/v2/create` | 新增一列 |
| 七、修改記錄 | `POST /api/v2/mutate` | 修改一列（PATCH 語義） |
| 八、刪除記錄 | `POST /api/v2/delete` | 刪除一列 |
| 九、各資源欄位參考 | — | 逐資源的主鍵、可寫欄位白名單與特殊規則 |
| 十、批次寫入 | `POST /api/v2/batch_mutate` | 一個請求送多筆變更 |
| 十一、提案重新提交 | `POST /api/v2/proposals/{id}/resubmit` | 修改自己已送出的提案（站內限定） |
| 十二、社會關係與親屬的互逆鏡像 | `POST /api/v2/relationship/opposite-edges` | 雙向關係的連帶寫入、衝突與復原 |
| 十三、代碼表與複合實體寫入 | — | 代碼表更新／新增、office 與 social-institution 聚合 |
| 十四、其他開放端點 | 見該章 | 著作查詢、帳號與 token、代碼選單、AI 輔助、MCP、人物頁 API |

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

部分寫入（別名、人物主檔）在伺服器做過**異體字替換**時，會多一個頂層 `notices` 陣列說明替換內容。注意其他靜默改寫（拼音 `v→ü`、括號正規化、哨兵值正規化）**不會**產生 `notices`，只能從回應的 `result.pk` / `result.row` 看出來。

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

**注意：`mode` 省略就是 `direct`**。有直接寫入權限的帳號若漏帶 `mode`，資料會**立即寫入正式資料庫**而不是進入待審佇列。要提交提案請每一筆都顯式帶 `"mode": "proposal"`（`batch_mutate` 可在頂層設一次預設，見〈批次寫入〉）。

提案送出後：

- 回應的 `result.operation_id` 就是該提案在 `operations` 表的 id，請保存下來以便追蹤。（注意：`resource_data` 內另有一個 `__operation_id`，那是 direct 寫入用的 ULID，**與 `result.operation_id` 不是同一個東西**。）
- 提案內容存於該筆 operation 的 `resource_data`，其中系統欄位包括 `__review_status`（`pending` / `approved` / `rejected` / `cancelled`）、`__proposal_meta`（提案人、時間、`comment`、`resource_type`）、`__key_columns`，副表／鏡像資料則在 `__proposal_aux`。
- `resource_data` 存的是**套用後的完整列**（原列 merge 你送的 `changes`），不是只有差異；套用前的原列存在同一筆 operation 的 `resource_original`。
- `__proposal_meta.resource_type` 的值不完全等於你送的 `resource`：人物主檔是 `biogmain`，任官的**新增**提案是 `offices`（修改／刪除提案則是 `postings`）。若要依此篩選提案，請把這些別名都算進去。
- 追蹤自己的提案：`GET /api/v2/operations?proposals_only=true&editor=<user_id 或名稱>&status[]=pending`（見第三章）。自己的 `user_id` 可用 `GET /api/user`（帶 Bearer）取得。提案的 `op_type` 為 8（新增）、9（修改）、10（刪除）；**刪除提案（10）不會被 `proposals_only=true` 涵蓋**，追蹤方式見第三章的注意事項。

提案的「占位」規則（送出前務必理解，否則會被 409 卡住）：

| 動作 | 占位判定 | 會擋下後續提交的狀態 |
| ------ | ------ | ------ |
| create（op_type 8） | 同一資料表 + 同一 `resource_id` | `pending` **或 `rejected`** |
| update（op_type 9） | 同一資料表 + 同一 `resource_id` | 僅 `pending` |
| delete（op_type 10） | 同一資料表 + 同一 `resource_id` | 僅 `pending` |

- 占位**不分提案人**：別人針對同一列送出的待審提案，也會讓你收到 409 `pending_proposal_exists`。
- 占位是**逐動作獨立**的：待審的新增提案不會擋修改提案，反之亦然（三種動作各自只掃自己的 `op_type`）。
- 占位的比對鍵 `resource_id` 是主鍵的 `http_build_query()` 編碼（欄位順序＝主鍵定義順序、值經 URL 編碼、`null` 編成字串 `NULL`、空字串保留成 `c_pages=`），例如 `c_personid=1762&c_alt_name_chn=%E5%8D%8A%E5%B1%B1&c_alt_name_type_code=4`。**修改提案用的是「改鍵後」的新主鍵**，所以改鍵提案佔的是新主鍵的位子。
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
| meta.ai_fill_log_id | 數字 | — | 內部用：標記這筆寫入來自 AI 自動填表的某筆日誌。外部提交者不需要送 |

`meta` 內以雙下底線開頭的鍵（例如 `__approving_operation_id`）是**系統核准流程保留鍵，外部呼叫不得送**。

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
- **`target.pk` 只檢查「該有的欄位有沒有到」，多送的欄位一律靜默忽略**。欄名打錯（或照舊文件多送了一個已不屬於主鍵的欄，例如 `ALTNAME_DATA` 的 `c_sequence`）不會報錯，但那個值也不會生效。
- `update` 時如果 `changes` 內含主鍵欄位，等於「改鍵」。後端會檢查新主鍵是否已被其他列占用，衝突則回 409 `target.pk: conflict`。
- **例外：`c_personid` 永遠不能改鍵。** 它不在任何子資源的 `update` 白名單內，送進 `changes` 會讓整筆請求 422 `disallowed_fields: c_personid`（`sources` 例外，回的是 `changes.c_personid: mismatch` 或 `immutable`）。要把記錄換到別的人物，只能刪除後在新人物下重建。
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
| 501 | `resource` / `mode` / `operation` 組合不支援 | `resource`、`mode`、`operation`（此處的值是**字串**，不是字串陣列） |

**`errors` 值的型別不統一**，請寬鬆解析：多數是「欄位 → 錯誤代號字串陣列」，但

- `mirror_conflict` → 物件 `{ table, pk, fields }`（`pk` 是**對面那一列**的主鍵）
- `mirror_suspected` → 物件 `{ table, candidates, authoritative_code, count }`
- `mirror_delete_multiple` → 物件 `{ table, candidates, count }`
- 501 的 `resource` / `mode` / `operation` → 字串

其中 `pk` 與 `candidates` 是處理鏡像衝突時唯一能定位對面資料的資訊，請務必保留，詳見〈社會關係與親屬的互逆鏡像〉。另有少數 422（例如不合法的 `c_kinship_pair`）**只有 `message`、沒有 `errors`**。

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

（唯一已知例外：`events` 的「只改地點副表」direct 路徑不寫 `operations`／`audit_log`，見第七章的回應例外表。）

提案（proposal）路徑的落地與署名：

- 提案送出時**不動**資料表，只寫一筆 operation；核准是站內流程（沒有對外 API）。
- 核准時後端會以 `mode=direct` **重放同一個 handler 並重新驗證**。因此提案在送出當下合法、核准當下卻不合法（例如目標列已被他人改鍵或刪除、主鍵已被占用），核准會失敗並整筆回滾，提案維持待審。這也是提案送出後不宜久放的原因。
- **提案階段不做互逆鏡像偵測**：`direct` 會因對面分歧而 409 的情況，`proposal` 一律照收（200）。分歧要等核准重放時才會浮現，屆時核准會失敗。
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

## 六、新增記錄

### `POST /api/v2/create`

新增一列。`operation` 固定為 `create`（帶了別的值也會被忽略）。

### 輸入參數

| 參數名 | 參數類型 | 必填 | 說明 |
| ------ | ------ | ------ | ------ |
| resource | 字串 | ✔ | 見 4.5 |
| mode | 字串 | — | `direct`（預設）或 `proposal`；眾包帳號只能 `proposal` |
| person_id | 數字 | ✔ | 新列所屬人物 ID |
| target.pk | 物件 | ✔ | 新列的**完整**複合主鍵。主鍵由系統配發的資源（`postings`、`possessions`）送空物件 `{}` |
| changes | 物件 | — | 主鍵以外的欄位。可省略（此時只寫入主鍵欄） |
| meta.comment | 字串 | — | 提案說明／操作備註 |

行為要點：

- 後端會把 `target.pk` 與 `changes` 合併成完整一列，再以白名單過濾。**主鍵欄要放在 `target.pk`**；放在 `changes` 裡雖然多數資源也接受（白名單通常含主鍵欄），但 `target.pk` 才是主鍵完整性檢查的依據。
- 主鍵已存在 → 409 `target.pk: conflict`（訊息 `目標主鍵已存在`）；`sources` 例外，回的是 `target.pk: duplicate`。
- `direct` 模式會由系統蓋上 `c_created_by` / `c_created_date`。
- 未詳的主鍵欄必須送哨兵值，見 4.4。
- `associations`／`kinship` 的新增會同時建立對面的互逆鏡像列，因此也可能回 409 `mirror_conflict` / `mirror_suspected` 或 422 `mirror_integrity`，見〈社會關係與親屬的互逆鏡像〉。

### 輸入示例（眾包帳號提交新增提案：為王安石加一個別名）

```json
POST /api/v2/create
Content-Type: application/json
Accept: application/json
Authorization: Bearer <token>

{
  "resource": "altnames",
  "mode": "proposal",
  "person_id": 1762,
  "target": {
    "pk": {
      "c_personid": 1762,
      "c_alt_name_chn": "半山",
      "c_alt_name_type_code": 4
    }
  },
  "changes": {
    "c_alt_name": "Ban shan",
    "c_source": 7596,
    "c_pages": "31",
    "c_notes": "據宋史列傳"
  },
  "meta": { "comment": "補王安石別號，出處：宋史列傳卷 327" }
}
```

### 輸出格式（proposal）

```json
{
  "ok": true,
  "resource": "altnames",
  "mode": "proposal",
  "operation": "create",
  "result": {
    "pk": { "c_personid": 1762, "c_alt_name_chn": "半山", "c_alt_name_type_code": 4 },
    "status": "proposal_created",
    "operation_id": 351902
  }
}
```

### 輸出格式（direct）

```json
{
  "ok": true,
  "resource": "altnames",
  "mode": "direct",
  "operation": "create",
  "result": {
    "pk": { "c_personid": 1762, "c_alt_name_chn": "半山", "c_alt_name_type_code": 4 },
    "operation_id": 351903,
    "row": { "c_personid": 1762, "c_alt_name_chn": "半山", "c_alt_name_type_code": 4, "c_source": 7596, "c_pages": "31", "c_notes": "據宋史列傳", "c_created_by": "王小明", "c_created_date": "2026-08-14 11:02:35" }
  }
}
```

| 屬性名 | 屬性類型 | 說明 |
| ------ | ------ | ------ |
| result.pk | 物件 | **實際落庫的主鍵**（可能與送出的值不同，例如異體字被替換、或主鍵由系統配發），請以此為準 |
| result.status | 字串 | 只在 proposal 出現，值為 `proposal_created` |
| result.operation_id | 數字 | `operations` 表 id；proposal 模式即提案編號 |
| result.row | 物件 | 只在 direct 出現，為寫入後從資料庫回讀的完整列 |
| notices | 陣列 | 只在伺服器做過**異體字替換**時出現（拼音 `v→ü`、括號正規化等改寫是靜默的，不會有 `notices`） |

**回應欄位的例外（請以「可能不存在」的方式讀取）**：

| 資源 | 例外 |
| ------ | ------ |
| `possessions`、`postings`、`basicinformation` | direct 的 `result` **沒有 `operation_id`**（只有 `pk` 與 `row`） |
| `possessions`、`postings` | proposal 的 `result` **沒有 `pk`**（主鍵尚未配發，要等核准），只有 `status` 與 `operation_id` |
| `sources` | direct 也有 `result.status`，值為 `created`（不是 `proposal_created`） |

---

## 七、修改記錄

### `POST /api/v2/mutate`

修改一列，**PATCH 語義**：只送要改的欄位，未送的欄位保持原值（詳見 4.3）。`operation` 預設 `update`；也可帶 `create` 或 `delete` 走對應流程（此時行為與第六、八章相同，但 `changes` 鍵仍必須存在）。

### 輸入參數

| 參數名 | 參數類型 | 必填 | 說明 |
| ------ | ------ | ------ | ------ |
| resource | 字串 | ✔ | 見 4.5 |
| mode | 字串 | — | `direct`（預設）或 `proposal` |
| operation | 字串 | — | 預設 `update` |
| person_id | 數字 | ✔ | 該列所屬人物 ID |
| target.pk | 物件 | ✔ | 目標列**現有**的完整複合主鍵 |
| changes | 物件 | ✔ | 要改的欄位；空物件回 422 `changes: empty` |
| meta.comment | 字串 | — | 提案說明／操作備註 |
| meta.force | 布林 | — | 僅用於鏡像衝突的二次確認（見〈社會關係與親屬的互逆鏡像〉） |

行為要點：

- **改鍵**：`changes` 內含主鍵欄位即為改鍵。後端會先檢查新主鍵是否已被占用（409 `target.pk: conflict`），`proposal` 模式則回 `目標主鍵已存在，無法建立提案`。
- **無變更即拒**：送出的值與現值完全相同（以字串比對）會回 422 `changes: no_effective_changes`，不會產生空操作記錄。
- **白名單**：多數資源對白名單外欄位整筆 422；少數靜默丟棄（見 4.6 的警告）。
- `direct` 模式會由系統蓋 `c_modified_by` / `c_modified_date`；回應的 `result.updated_fields` 只列使用者實際變更的欄位，不含這兩個稽核欄。

### 輸入示例（眾包帳號提交修改提案：補一筆地址的備註與出處頁碼）

```json
POST /api/v2/mutate
Content-Type: application/json
Accept: application/json
Authorization: Bearer <token>

{
  "resource": "addresses",
  "mode": "proposal",
  "operation": "update",
  "person_id": 1762,
  "target": {
    "pk": { "c_personid": 1762, "c_addr_id": 100513, "c_addr_type": 1, "c_sequence": 1 }
  },
  "changes": {
    "c_pages": "31-32",
    "c_notes": "據宋史列傳補正"
  },
  "meta": { "comment": "補出處頁碼" }
}
```

### 輸出格式（proposal）

```json
{
  "ok": true,
  "resource": "addresses",
  "mode": "proposal",
  "operation": "update",
  "result": {
    "pk": { "c_personid": 1762, "c_addr_id": 100513, "c_addr_type": 1, "c_sequence": 1 },
    "updated_fields": ["c_pages", "c_notes"],
    "status": "proposal_updated",
    "operation_id": 351904
  }
}
```

### 輸出格式（direct）

```json
{
  "ok": true,
  "resource": "addresses",
  "mode": "direct",
  "operation": "update",
  "result": {
    "pk": { "c_personid": 1762, "c_addr_id": 100513, "c_addr_type": 1, "c_sequence": 1 },
    "updated_fields": ["c_pages", "c_notes"],
    "operation_id": 351905,
    "row": { "c_personid": 1762, "c_addr_id": 100513, "c_addr_type": 1, "c_sequence": 1, "c_pages": "31-32", "c_notes": "據宋史列傳補正", "c_modified_by": "王小明", "c_modified_date": "2026-08-14 11:10:02" }
  }
}
```

| 屬性名 | 屬性類型 | 說明 |
| ------ | ------ | ------ |
| result.pk | 物件 | **改鍵後的新主鍵**（未改鍵時與送出的相同） |
| result.updated_fields | 陣列 | 本次變更的欄位名；direct 模式已排除自動蓋的 `c_modified_*`。**可能包含非資料表欄位**（`c_addr`、`c_addr_id`、`c_kinship_pair`、`c_assocship_pair`、`c_assoc_kinship_pair`），不要無條件當成資料表欄位處理 |
| result.status | 字串 | 只在 proposal 出現，值為 `proposal_updated` |
| result.row | 物件 | 只在 direct 出現，為更新後從資料庫回讀的完整列 |

**回應欄位的例外**：

| 情況 | 例外 |
| ------ | ------ |
| `sources` 的 update | direct 也有 `result.status`（值 `updated`），且**沒有 `updated_fields`** |
| 只改副表或只改互逆配對碼的 update（`changes` 內只有 `c_addr` / `c_addr_id` / `c_addr_cleared` / `c_assocship_pair` / `c_kinship_pair` / `c_assoc_kinship_pair`） | 走旁路，direct 的 `result` 只有 `pk` 與 `updated_fields`，**沒有 `operation_id`、沒有 `row`**。適用 `events`、`postings`、`possessions`、`associations`、`kinship` |
| `events` 的「只改地點」direct | 除了回應較精簡，這條路徑**不寫 `operations` 也不寫 `audit_log`**（是既有行為，與 4.7 的通則不同） |

---

## 八、刪除記錄

### `POST /api/v2/delete`

刪除一列。`operation` 固定為 `delete`。

### 輸入參數

| 參數名 | 參數類型 | 必填 | 說明 |
| ------ | ------ | ------ | ------ |
| resource | 字串 | ✔ | 見 4.5 |
| mode | 字串 | — | `direct`（預設）或 `proposal` |
| person_id | 數字 | ✔ | 該列所屬人物 ID |
| target.pk | 物件 | ✔ | 目標列的完整複合主鍵 |
| meta.comment | 字串 | — | 提案說明／操作備註 |
| meta.force | 布林 | — | 確認一併刪除對面多筆反向鏡像列；**只有 `kinship` 會讀這個旗標**（見〈社會關係與親屬的互逆鏡像〉） |
| changes | — | — | 不需要；帶了會被忽略 |

行為要點：

- 目標列不存在 → 404。
- **人物主檔（`basicinformation`）的刪除是軟刪除**：改名為 `<待删除>`，資料列仍在（見 4.5）。
- 資料庫層外鍵一律 `RESTRICT`（沒有 `ON DELETE CASCADE`），**不會由資料庫連鎖刪除**。若該列仍被其他資料引用，資料庫會擋下來（此時可能收到 500，請視為「不可刪除」而不是重試）。
- 但**應用層會顯式刪除該列自己的附屬子表**，且會逐列寫 operations／audit：
  - `possessions` → 一併刪 `POSSESSION_ADDR`
  - `postings` → 一併刪 `POSTED_TO_ADDR_DATA` 與 `POSTING_DATA`
  - `associations`／`kinship` → 一併處理對面的互逆鏡像列（見〈社會關係與親屬的互逆鏡像〉）
  刪除前請把這些連帶影響算進去。
- `associations`／`kinship` 的刪除會同時處理對面的互逆鏡像列，但兩者行為**不同**：
  - `kinship`：對面命中多筆時回 409 `mirror_delete_multiple`，須確認後帶 `meta.force` 重送；配對碼缺失時 fail-closed 回 422 `mirror_integrity`。
  - `associations`：不使用 `meta.force`；若無法定位對面鏡像列會**靜默跳過**（不報錯），有可能留下孤兒鏡像列。刪完社會關係建議另外確認對方人物那一側。
  詳見〈社會關係與親屬的互逆鏡像〉。

### 輸入示例

（注意：本例假設該別名列**已存在於資料表**。若第六章的新增是走 `proposal`，那筆資料在核准前並不存在，此時刪除會回 404。）

```json
POST /api/v2/delete
Content-Type: application/json
Accept: application/json
Authorization: Bearer <token>

{
  "resource": "altnames",
  "mode": "proposal",
  "person_id": 1762,
  "target": {
    "pk": { "c_personid": 1762, "c_alt_name_chn": "半山", "c_alt_name_type_code": 4 }
  },
  "meta": { "comment": "重複別名，建議刪除" }
}
```

### 輸出格式

```json
{
  "ok": true,
  "resource": "altnames",
  "mode": "proposal",
  "operation": "delete",
  "result": {
    "pk": { "c_personid": 1762, "c_alt_name_chn": "半山", "c_alt_name_type_code": 4 },
    "status": "proposal_deleted",
    "operation_id": 351906
  }
}
```

`direct` 模式的回應沒有 `status`，只有 `pk` 與 `operation_id`（刪除後無列可回讀，故無 `row`）。**例外**：`possessions` 與 `postings` 的 direct 刪除回應只有 `pk`，沒有 `operation_id`。

---

## 九、各資源欄位參考

本章逐資源列出 `target.pk` 欄位與可寫欄位白名單。**白名單以外的欄位不要送**（多數會整筆 422）。

通用約定：

- 「create 白名單」是 `target.pk` + `changes` 合併後允許保留的欄位；「update 白名單」是 `changes` 允許的欄位。
- 稽核欄（`c_created_by`／`c_created_date`／`c_modified_by`／`c_modified_date`）一律**不可送**，由系統蓋章。
- 標記為「哨兵欄」的碼／外鍵欄位，送 `null`／空字串／`-999` 都會被正規化為 `"0"`（未詳），不會寫入 null。**`create` 更進一步：連「不送這個欄位」也會落 `"0"`**（對齊舊表單「空欄送 0」的語義），所以不要期待省略就會留 `null`。
- 有三個資源另有「地點副表」，用 `changes` 內的專用鍵表達（不是普通純量欄位）：`events` 與 `possessions` 用 `c_addr_id`（陣列）、`postings` 用 `c_addr`（陣列）。`update` 時可用 `c_addr_cleared: "1"` 表示清空該副表。`proposal` 模式下這些內容存在提案的 `__proposal_aux`，核准時才寫入副表。

### 9.1 basicinformation（BIOG_MAIN，人物主檔）

- `target.pk`：`c_personid`
- **create**（只支援 `direct`）可寫欄位：`c_personid`、`c_name_chn`、`c_name`、`c_name_proper`、`c_name_rm`、`c_surname_chn`、`c_mingzi_chn`、`c_surname`、`c_mingzi`、`c_surname_proper`、`c_mingzi_proper`、`c_surname_rm`、`c_mingzi_rm`、`c_female`、`c_index_year`、`c_index_year_type_code`、`c_index_year_source_id`、`c_index_addr_id`、`c_index_addr_type_code`、`c_dy`、`c_by_intercalary`、`c_by_nh_code`、`c_by_nh_year`、`c_by_range`、`c_by_yymm`、`c_by_yymm_day`、`c_by_day_gz`、`c_dy_intercalary`、`c_dy_nh_code`、`c_dy_nh_year`、`c_dy_range`、`c_dy_yymm`、`c_dy_yymm_day`、`c_dy_day_gz`、`c_death_age`、`c_death_age_range`、`c_fl_earliest_year`、`c_fl_ey_nh_code`、`c_fl_ey_nh_year`、`c_fl_ey_notes`、`c_fl_latest_year`、`c_fl_ly_nh_code`、`c_fl_ly_nh_year`、`c_fl_ly_notes`、`c_ethnicity_code`、`c_household_status_code`、`c_tribe`、`c_choronym_code`、`c_notes`、`c_self_bio`
- create 的 `c_personid` 由呼叫方指定（不是自動配發），且必須：非 0、尚未存在、且 `c_personid - 目前最大 c_personid ≤ 10000`，否則 422（`c_personid: required` / `exists` / `too_large`）。
- **update** 可寫欄位：BIOG_MAIN 除下列黑名單外的所有欄位。黑名單＝`c_personid`、`c_name_chn`、`c_name`、`c_name_proper`、`c_name_rm` 與四個稽核欄。
  - 姓名不能直接改：`c_name_chn` 等合併欄由 `c_surname_chn` + `c_mingzi_chn`（及對應拼音分欄）自動組出，請改分欄。
  - `c_mingzi_chn`／`c_mingzi` 若原值非空，**不可清空**（會 422）；原本為空者可維持為空。
  - 部分外鍵欄（`c_dy`、`c_by_nh_code`、`c_dy_nh_code`、`c_ethnicity_code`、`c_choronym_code`、`c_household_status_code` 等，共 13 欄）在收到空字串時會被寫成 `null`（不是 `0`）；其餘外鍵欄不在此清單內。
  - 另有兩條範圍驗證（direct 與 proposal 皆套用）：`c_index_year` 限 -3000～3000、`c_death_age` 限 0～200，超出即 422。
  - 未知欄位與黑名單欄位都會被靜默丟棄（見 4.6 警告）；但若 `changes` **只**含這類欄位、過濾後什麼都不剩，則回 422 `changes: no_supported_fields`。
  - `direct` 的 `update` 若送出的值與現值完全相同，回 422 `changes: no_effective_changes`（不會寫入、不會記 operation）；但 `proposal` 沒有這道守衛，同值也會成立一筆提案。
  - `result.updated_fields` 列的是「你送出且通過白名單的欄位」，不是「值真的變了的欄位」。
- **delete**（只支援 `direct`）：軟刪除，`c_name_chn` 改為 `<待删除>`。
- proposal：只有 `update` 支援；`create`／`delete` 回 501。

### 9.2 altnames（ALTNAME_DATA，別名）

- `target.pk`：`c_personid`、`c_alt_name_chn`、`c_alt_name_type_code`（3-key，**不含** `c_sequence`）
- **update** 白名單：`c_alt_name_chn`、`c_alt_name`、`c_alt_name_type_code`、`c_source`、`c_pages`、`c_notes`、`c_sequence`、`c_alt_name_pinyin`、`c_alt_name_pinyin2`、`c_alt_name_pinyin3`、`c_alt_name_role`
- **create** 白名單：同上再加 `c_personid`
- 哨兵欄：`c_source`（另 `c_alt_name_type_code` 的 `-999` 會轉 `0`）
- 特殊行為（都會靜默改寫送出的值）：
  - `c_alt_name_chn` 與 `c_alt_name` 都會做括號正規化（全角轉半角、括號前後補空格）。
  - `c_alt_name_chn` 另會做**異體字嚴格替換**；因為它同時是主鍵欄，替換後的值才是落庫主鍵——**請以回應的 `result.pk` 為準**。只有這一項會產生 `notices`。
  - `c_alt_name_pinyin`／`2`／`3` 的 `v` 會轉成 `ü`（靜默，無 `notices`）。
  - 若正規化後與同類型的既有別名撞主鍵，回 409（訊息會說明需先手動整理）。
  - 寫入成功後會同步重建姓名全文檢索索引（`CBDB__NAME_FTS`），這是預期的副作用。

### 9.3 addresses（BIOG_ADDR_DATA，地址）

- `target.pk`：`c_personid`、`c_addr_id`、`c_addr_type`、`c_sequence`
- **update** 白名單：`c_addr_id`、`c_addr_type`、`c_firstyear`、`c_lastyear`、`c_sequence`、`c_notes`、`c_source`、`c_pages`、`c_natal`、`c_fy_nh_code`、`c_fy_nh_year`、`c_fy_range`、`c_fy_intercalary`、`c_fy_month`、`c_fy_day`、`c_fy_day_gz`、`c_ly_nh_code`、`c_ly_nh_year`、`c_ly_range`、`c_ly_intercalary`、`c_ly_month`、`c_ly_day`、`c_ly_day_gz`
- **create** 白名單：同上再加 `c_personid`
- 哨兵欄：`c_addr_id`、`c_source`

### 9.4 entries（ENTRY_DATA，入仕）

- `target.pk`（10-key）：`c_personid`、`c_entry_code`、`c_sequence`、`c_kin_code`、`c_assoc_code`、`c_kin_id`、`c_year`、`c_assoc_id`、`c_inst_code`、`c_inst_name_code`
- **未詳的主鍵欄一律送 `0`**（10 個欄位都必須出現在 `target.pk`）。
- **update** 白名單：`c_entry_code`、`c_sequence`、`c_kin_code`、`c_assoc_code`、`c_kin_id`、`c_year`、`c_assoc_id`、`c_inst_code`、`c_inst_name_code`、`c_entry_addr_id`、`c_source`、`c_pages`、`c_notes`、`c_entry_nh_id`、`c_entry_nh_year`、`c_entry_range`、`c_exam_rank`、`c_attempt_count`、`c_exam_field`、`c_parental_status_code`、`c_age`、`c_posting_notes`
- **create** 白名單：同上再加 `c_personid`
- 哨兵欄：`c_source`、`c_entry_addr_id`

### 9.5 statuses（STATUS_DATA，社會區分）

- `target.pk`：`c_personid`、`c_sequence`、`c_status_code`
- **update** 白名單：`c_status_code`、`c_sequence`、`c_source`、`c_pages`、`c_notes`、`c_supplement`、`c_firstyear`、`c_fy_nh_code`、`c_fy_nh_year`、`c_fy_range`、`c_lastyear`、`c_ly_nh_code`、`c_ly_nh_year`、`c_ly_range`
- **create** 白名單：同上再加 `c_personid`
- 哨兵欄：`c_source`

### 9.6 events（EVENTS_DATA，事件）

- `target.pk`：`c_personid`、`c_sequence`、`c_event_code`
- **update** 白名單：`c_event_code`、`c_sequence`、`c_source`、`c_pages`、`c_notes`、`c_year`、`c_month`、`c_day`、`c_day_ganzhi`、`c_nh_code`、`c_nh_year`、`c_yr_range`、`c_intercalary`、`c_role`、`c_event`
- **create** 白名單：同上再加 `c_personid`
- 哨兵欄：`c_source`
- **地點副表**：`changes.c_addr_id` 可送**陣列**（事件地點 ID 列表），寫入 `EVENTS_ADDR`；`update` 可用 `c_addr_cleared: "1"` 清空。它刻意不在純量白名單內，但**送出不會 422**，會被當副表處理。`proposal` 模式存於 `__proposal_aux`，核准時才寫入。
- 注意：`changes` 內**只有**地點副表鍵的 direct 更新走旁路，不寫 `operations` 也不寫 `audit_log`（見第七章的回應例外表）。

### 9.7 associations（ASSOC_DATA，社會關係）

- `target.pk`（9-key）：`c_personid`、`c_assoc_code`、`c_assoc_id`、`c_kin_code`、`c_kin_id`、`c_assoc_kin_code`、`c_assoc_kin_id`、`c_text_title`、`c_assoc_first_year`
- **主鍵哨兵值**：數值欄未詳送 `0`；`c_text_title` 未詳送 `[n/a]`；`c_assoc_first_year` 未詳送 `-9999`。
- **update** 白名單：`c_assoc_code`、`c_assoc_id`、`c_kin_code`、`c_kin_id`、`c_assoc_kin_code`、`c_assoc_kin_id`、`c_text_title`、`c_assoc_first_year`、`c_assoc_last_year`、`c_assoc_fy_nh_code`、`c_assoc_fy_nh_year`、`c_assoc_fy_range`、`c_assoc_fy_intercalary`、`c_assoc_fy_month`、`c_assoc_fy_day`、`c_assoc_fy_day_gz`、`c_assoc_ly_nh_code`、`c_assoc_ly_nh_year`、`c_assoc_ly_range`、`c_assoc_ly_intercalary`、`c_assoc_ly_month`、`c_assoc_ly_day`、`c_assoc_ly_day_gz`、`c_source`、`c_pages`、`c_notes`、`c_sequence`、`c_assoc_count`、`c_topic_code`、`c_occasion_code`、`c_tertiary_personid`、`c_tertiary_type_notes`、`c_assoc_claimer_id`、`c_addr_id`、`c_inst_code`、`c_inst_name_code`
- **create** 白名單：同上再加 `c_personid`
- 哨兵欄：`c_source`
- **額外可送的非資料表欄位**（互逆鏡像用，見〈社會關係與親屬的互逆鏡像〉）：`c_assocship_pair`、`c_kinship_pair`、`c_assoc_kinship_pair`。未送時後端會以代碼表的權威反向碼（`ASSOC_CODES.c_assoc_pair`／`KINSHIP_CODES.c_kin_pair1`）自動補齊。**這三個欄位不做有效性驗證**（送不存在的碼會被靜默接受並寫進鏡像列），與 `kinship` 的 `c_kinship_pair` 會驗證的行為不同——請自行確認送的是合法配對碼。
- **這個資源的寫入會同時動到對方人物的那一列**（互逆鏡像），詳見〈社會關係與親屬的互逆鏡像〉。`update` 的「補建缺失鏡像」只在**顯式送了任一 pair 欄位**時才啟用；只改備註等欄位不會臆造鏡像。
- 兩個哨兵主鍵欄的 `update` 例外：送 `c_text_title: null` 或 `c_assoc_first_year: null` **不會清空**，會被還原成 `[n/a]`／`-9999`。

### 9.8 kinship（KIN_DATA，親屬關係）

- `target.pk`：`c_personid`、`c_kin_id`、`c_kin_code`
- **update** 白名單：`c_kin_code`、`c_kin_id`、`c_source`、`c_pages`、`c_notes`、`c_autogen_notes`
- **create** 白名單：同上再加 `c_personid`
- 哨兵欄：`c_source`
- **額外可送的非資料表欄位**：`c_kinship_pair`（指定反向親屬碼）。未送時以 `KINSHIP_CODES.c_kin_pair1` 推導；送了不合法的配對碼會回 422（此回應**只有 `message`、沒有 `errors`**），且因為檢查發生在寫入之前，資料完全未動。
- **這個資源的寫入會同時動到對方人物的那一列**（互逆鏡像），詳見〈社會關係與親屬的互逆鏡像〉。與 `associations` 不同，`kinship` 的 `update` **不會**補建缺失的鏡像列（只同步已存在的那一列）。

### 9.9 possessions（POSSESSION_DATA，財產）

- `target.pk`：`c_possession_record_id`（單一流水號主鍵）
- **create**：`c_possession_record_id` 由系統配發，`target.pk` 送空物件 `{}`；`person_id` 不可為 `0`（422 `person_id: invalid`）。
- **create／update** 白名單：`c_sequence`、`c_possession_act_code`、`c_possession_desc`、`c_possession_desc_chn`、`c_quantity`、`c_measure_code`、`c_possession_yr`、`c_possession_nh_code`、`c_possession_nh_yr`、`c_possession_yr_range`、`c_source`、`c_pages`、`c_notes`
- 哨兵欄：`c_source`、`c_measure_code`、`c_possession_act_code`
- **地址副表**：`changes.c_addr_id` 可送**陣列**（地點 ID 列表），寫入 `POSSESSION_ADDR`；`update` 可用 `c_addr_cleared: "1"` 清空；proposal 模式存於提案的 `__proposal_aux`，核准時才寫入。
- create 對白名單外欄位是**靜默丟棄**（不報錯）；`target.pk` 也**完全被忽略**——就算送了 `c_possession_record_id`，系統仍會配發新的 id。
- 新增提案沒有重複防呆，重送會產生多筆提案（見 4.2）。

### 9.10 texts（BIOG_TEXT_DATA，著述）

- `target.pk`：`c_personid`、`c_textid`、`c_role_id`
- **update** 白名單：`c_textid`、`c_role_id`、`c_source`、`c_pages`、`c_notes`、`c_supplement`、`c_text_year`
- **create** 白名單：同上再加 `c_personid`
- 哨兵欄：`c_textid`、`c_source`
- `c_textid` 對應 `TEXT_CODES`，可用 `GET /api/v2/texts?ids=...` 查詢（見〈其他開放端點〉）。

### 9.11 postings（POSTED_TO_OFFICE_DATA，任官）

- `target.pk`：`c_office_id`、`c_posting_id`（**不含** `c_personid`）
- **create**：`c_posting_id` 由系統配發，`target.pk` 送空物件 `{}`，但**必須在 `changes` 內帶 `c_office_id`**（否則 422 `changes: c_office_id required`）；`person_id` 不可為 `0`。
- **create／update** 白名單：`c_office_id`、`c_sequence`、`c_source`、`c_pages`、`c_notes`、`c_firstyear`、`c_fy_nh_code`、`c_fy_nh_year`、`c_fy_range`、`c_fy_intercalary`、`c_fy_month`、`c_fy_day`、`c_fy_day_gz`、`c_lastyear`、`c_ly_nh_code`、`c_ly_nh_year`、`c_ly_range`、`c_ly_intercalary`、`c_ly_month`、`c_ly_day`、`c_ly_day_gz`、`c_appt_code`、`c_assume_office_code`、`c_dy`、`c_inst_code`、`c_inst_name_code`、`c_office_category_id`
- 哨兵欄：`c_source`、`c_appt_code`
- **任官地址副表**：`changes.c_addr` 可送**陣列**（任官地點），寫入 `POSTED_TO_ADDR_DATA`；`update` 可用 `c_addr_cleared: "1"` 清空；proposal 模式存於 `__proposal_aux`，核准時才寫入。
- create 對白名單外欄位是**靜默丟棄**；`target.pk` 也**完全被忽略**（`c_posting_id` 一律由系統配發）。
- 新增提案的重複防呆只看 `c_office_id`：同一官職上任何人的待審新增提案都會互擋，錯誤鍵是 `changes: pending_proposal_exists`。

### 9.12 social_institutions（BIOG_INST_DATA，社會機構）

- `target.pk`：`c_personid`、`c_inst_code`、`c_inst_name_code`、`c_bi_role_code`
- **update** 白名單：`c_inst_code`、`c_inst_name_code`、`c_bi_role_code`、`c_source`、`c_pages`、`c_notes`、`c_bi_begin_year`、`c_bi_by_nh_code`、`c_bi_by_nh_year`、`c_bi_by_range`、`c_bi_end_year`、`c_bi_ey_nh_code`、`c_bi_ey_nh_year`、`c_bi_ey_range`
- **create** 白名單：同上再加 `c_personid`
- 哨兵欄：`c_source`
- 別名注意：`socialinst` 只有 `create`／`delete` 接受；`update` 請用 `social_institutions`、`social_institution` 或 `biog_inst_data`。

### 9.13 sources（BIOG_SOURCE_DATA，出處）

- `target.pk`：`c_personid`、`c_textid`、`c_pages`
- `c_pages` 是唯一允許為空的主鍵欄（寫入端會正規化為空字串）；但 `/api/v2/get` 不接受空值（見第五章注 2）。
- **可寫欄位**：`c_notes`、`c_main_source`、`c_self_bio`（後兩者是 0／1 布林旗標，`create` 未送時預設 `0`），另 `c_textid` 與 `c_pages` 可改鍵。
- `c_personid` **不可改鍵**（不能把出處搬到別的人物），送了不同值回 422 `changes.c_personid: immutable`。
- `update` 時 `target.pk` 必須含全部三個主鍵鍵名（缺少回 422 `target.pk.<欄名>: required`），且必須有實質變更（改鍵或可寫欄位之一），否則 422 `changes: no_supported_fields`；若送出的值與現值相同則回 422 `changes: no_effective_changes`。
- `c_textid` 必須存在於 `TEXT_CODES`，否則 422 `c_textid: invalid`。
- `resource` 別名不對稱：**`create`／`update` 只接受 `sources`**；`delete` 另接受 `source`、`biog_source_data`。
- `create` 時 `changes` 內的 `c_textid`／`c_pages` **優先於 `target.pk`**，兩者不一致會以 `changes` 落庫（與其他資源相反，請只在一處給值）。
- `c_pages` 會被 `trim()`；`c_main_source`／`c_self_bio` 以整數轉型收下，非數字字串會靜默變成 `0`。
- 人物不符的錯誤鍵是 `target.pk.c_personid: mismatch` 或 `changes.c_personid: mismatch`（不是其他資源的 `person_id: mismatch`）。
- 待審提案檢查在 `mode` 分支之前，因此「同主鍵已有待審新增提案」時連 `mode=direct` 的 `create` 也會 409；`update` 沒有這道檢查。
- 白名單外欄位是**靜默丟棄**。
- 回應形狀與其他資源不同：direct 也帶 `result.status`（`created`／`updated`），且 update 的 direct 回應**沒有 `updated_fields`**。

### 9.14 merged-person（MERGED_PERSON_DATA，合併人物記錄）

- `target.pk`：`c_personid`、`c_merged_from_personid`
- 只支援 `create` 與 `delete`（沒有 `update`），`direct`／`proposal` 皆可。
- **create** 白名單：`c_personid`、`c_merged_from_personid`、`c_notes`、`c_source`、`c_pages`
- `/api/v2/get` **不支援**此資源（寫得進去、讀不回來）。

---

## 十、批次寫入

### `POST /api/v2/batch_mutate`

一個請求送多筆變更，逐筆分派到與單筆端點相同的 handler（同樣的校驗、授權、`operations`／`audit_log`）。用途是減少 HTTP 往返，不是繞過任何檢查。

**與單筆端點的唯一差異**：批次對 `operation` 不是 `delete` 的每一筆都要求 `changes` 鍵存在，而單筆 `POST /api/v2/create` 允許省略 `changes`。把可用的 create 請求搬進 `items` 時，記得補上 `"changes": {}`。

### 輸入參數

| 參數名 | 參數類型 | 必填 | 說明 |
| ------ | ------ | ------ | ------ |
| items | 陣列 | ✔ | 每個元素是一筆變更，欄位與單筆端點相同（`resource`／`mode`／`operation`／`person_id`／`target.pk`／`changes`／`meta`）。空陣列回 422 `items: required`；**上限 500 筆**，超過回 422 `items: ["too_many", "max:500", "count:N"]` |
| atomic | 布林 | — | 預設 `false`。`true` 表示整批放在同一個交易，任一筆失敗即整批回滾 |
| resource | 字串 | — | 頂層預設值，逐筆可覆寫 |
| mode | 字串 | — | 同上（外部提交者建議在頂層設 `"proposal"`，避免漏帶而變成 direct） |
| operation | 字串 | — | 同上，未指定時逐筆預設 `update` |
| meta | 物件 | — | 同上 |

各筆的 `changes`：`operation` 不是 `delete` 時必填（該筆回 422 `changes: required`）；`delete` 可省略。

### 輸入示例（一次送兩筆提案）

```json
POST /api/v2/batch_mutate
Content-Type: application/json
Accept: application/json
Authorization: Bearer <token>

{
  "mode": "proposal",
  "meta": { "comment": "批次補正出處" },
  "items": [
    {
      "resource": "altnames",
      "operation": "update",
      "person_id": 1762,
      "target": { "pk": { "c_personid": 1762, "c_alt_name_chn": "半山", "c_alt_name_type_code": 4 } },
      "changes": { "c_pages": "31" }
    },
    {
      "resource": "addresses",
      "operation": "update",
      "person_id": 1762,
      "target": { "pk": { "c_personid": 1762, "c_addr_id": 100513, "c_addr_type": 1, "c_sequence": 1 } },
      "changes": { "c_notes": "據宋史列傳補正" }
    }
  ]
}
```

注意兩件事：

- 頂層的 `person_id` **不會**被當成逐筆預設（只有 `resource`／`mode`／`operation`／`meta` 會），每一筆都必須自己帶 `person_id`。
- 頂層 `meta` 與逐筆 `meta` 是**取代關係、不會合併**：某一筆自帶 `meta` 時，頂層 `meta` 對那一筆整包失效。

### 輸出格式（非原子模式）

```json
{
  "ok": true,
  "atomic": false,
  "summary": { "total": 2, "ok": 2, "failed": 0 },
  "results": [
    { "index": 0, "http_status": 200, "ok": true, "resource": "altnames", "mode": "proposal", "operation": "update", "result": { "...": "同單筆端點" } },
    { "index": 1, "http_status": 200, "ok": true, "resource": "addresses", "mode": "proposal", "operation": "update", "result": { "...": "同單筆端點" } }
  ]
}
```

| 屬性名 | 屬性類型 | 說明 |
| ------ | ------ | ------ |
| ok | 布林 | **是否全部成功**；有任一筆失敗即為 `false` |
| atomic | 布林 | 回填本次是否為原子模式 |
| summary.total / ok / failed | 數字 | 總筆數／成功數／失敗數 |
| results | 陣列 | 逐筆結果，順序與 `items` 相同 |
| results[i].index | 數字 | 對應 `items` 的索引 |
| results[i].http_status | 數字 | 該筆若單獨呼叫時的 HTTP 狀態碼 |
| results[i].ok | 布林 | 該筆是否成功；其餘欄位即單筆端點的回應內容（失敗時為 `message` 與 `errors`） |

> **⚠️ 非原子模式的 HTTP 狀態碼永遠是 200**，即使每一筆都失敗。**必須檢查 body 的 `ok`／`summary.failed`／逐筆 `results[i].ok`**，只看狀態碼會把整批失敗當成成功。例如眾包帳號漏帶 `mode` 時，每一筆都會是 403，但整體仍回 200。

「永遠 200」只適用於**逐筆**結果。下列是**整批層級**的失敗，會直接回非 200 且沒有 `results`：未登入 401、帳號未啟用 403、缺 `items` 或超過 500 筆的 422。

### 輸出格式（原子模式失敗）

任一筆失敗時整批回滾，回 **409**：

```json
{
  "ok": false,
  "atomic": true,
  "message": "批次原子模式：某筆失敗，整批已回滾",
  "failed_index": 1,
  "failed": { "index": 1, "http_status": 422, "ok": false, "message": "...", "errors": {} },
  "results": [ "已執行（但已回滾）的逐筆結果" ]
}
```

原子模式失敗的回應**沒有 `summary`**，且 `results` 只包含「執行到失敗那一筆為止」的結果（後面的筆根本沒跑）。全部成功時，回應與非原子模式相同（`atomic` 為 `true`）。

### 其他注意事項

- 逐筆可能出現的其他狀態：
  - `http_status: 501` — 該筆的 `resource`／`mode`／`operation` 組合不支援；此時 `errors` 是**扁平字串映射** `{"resource":"...","mode":"...","operation":"..."}`，不是字串陣列。
  - `http_status: 422`、`errors.item = ["invalid"]` — 該筆不是物件。
  - `http_status: 500`、`errors.exception` — 該筆拋出未預期例外；非原子模式下不影響其他筆，原子模式下觸發整批回滾。
- 非原子模式下**成功的筆會即時落庫**，不會因為後面有筆失敗而回滾。
- 逐筆的提案占位檢查仍然生效：同一批次內若有兩筆針對同一主鍵的同種提案，第二筆會 409。
- 批次沒有降低授權要求：眾包帳號的每一筆都必須是 `mode=proposal`。

---

## 十一、提案重新提交

### `POST /api/v2/proposals/{operation}/resubmit`

修改一筆已送出的提案。實作方式是「撤回舊提案 ＋ 用完全相同的提交流程重發」，兩者在單一交易內完成。

> **外部 Bearer 客戶端無法呼叫此端點**（不在 CSRF 豁免清單內，會收到 419，見 1.2）。這裡記錄它是為了說明站內「修改提案」的語義，以及為什麼被駁回的新增提案需要站內協助才能解除占位。

### 路徑參數

| 參數名 | 說明 |
| ------ | ------ |
| operation | 舊提案在 `operations` 表的 id（即當初回應的 `result.operation_id`） |

### 請求主體

與 `/api/v2/mutate`／`/api/v2/create` 相同的信封（`resource`、`operation`、`person_id`、`target.pk`、`changes`、`meta`），但 **`mode` 一律被強制為 `proposal`**（送 `direct` 也不會生效）。

`target.pk`、`changes`、`person_id` **三者都必填**，缺一即 422——`changes` 在此是**無條件必填**，即使重發的是刪除提案也要帶（可為空物件）。

### 權限與狀態限制

| 條件 | 不符時 |
| ------ | ------ |
| 路徑上的提案 id 存在 | 404 |
| 已登入且帳號啟用 | 401／403 |
| 是提案人本人**或**具審核權者（啟用且非眾包帳號） | 403 `只有提案人或審核人可以修改提案` |
| 舊提案的 `op_type` 是 8（提案新增）或 9（提案修改） | 422 `operation: not_editable_proposal`（**刪除提案 op_type=10 不可重發**） |
| 舊提案的 `__review_status` 是 `pending` 或 `rejected` | 422 `operation: not_editable_status` |
| 送出的 `resource`／`operation` 有對應的 proposal handler | 501 `目前尚未支援此變更模式` |

### 行為

1. 舊提案標記為 `cancelled`，並在 `__proposal_meta` 記 `cancelled_at`／`cancelled_by`／`cancelled_by_id`／`cancel_reason`（固定為「已重新提交（修改提案）」，外部端可據此辨識這類 cancelled）。
2. 以 `mode=proposal` 重放對應 handler——與新提交走完全相同的驗證，因此「同主鍵已有待審提案」的護欄不會誤擋（舊提案已撤回）。
3. 新舊提案互相回鏈：舊提案記 `superseded_by`，新提案記 `resubmit_of`。
4. **任何一步失敗即整筆回滾**（舊提案回到原狀態），並把 handler 的欄位級錯誤原樣回傳。若新提案的資料表與舊提案不一致，也會整筆回滾並回 422（此回應只有 `message`、沒有 `errors`）。

兩個契約保證：

- 重發**新增**提案時，payload 不會帶稽核欄（`c_created_by`／`c_created_date`／`c_modified_by`／`c_modified_date`）——舊介面曾整包回寫 payload 而把系統欄位灌成 null，這條就是治本點。重發**修改**提案時，payload 是「原資料列 merge 你的 `changes`」，因此仍會含原列的稽核欄；那是審計快照，核准落庫前會由系統重新蓋章，不會原樣回寫。
- 新提案的 `comment` 取**本次** `meta.comment`，不繼承舊提案的說明。

成功時的回應就是重發那筆提案的回應（形狀同第六／七章的 proposal 回應）。回鏈只在 handler 有回傳 `result.operation_id` 時建立。

---

## 十二、社會關係與親屬的互逆鏡像

`ASSOC_DATA`（社會關係）與 `KIN_DATA`（親屬關係）在 CBDB 是**雙向**資料：A 對 B 有一條關係，B 對 A 就該有一條互逆關係。因此**寫這兩個資源時，後端會在同一交易內連帶建立／更新／刪除「對方人物那一列」**。這是外部提交者最容易誤解、也最容易踩到 409 的地方。

### 12.1 鏡像列是怎麼組出來的

以 `associations` 的新增為例（**這張表是 `associations` 的行為，不可外推到 `kinship`**——親屬的鏡像列不寫 `c_kin_id`，由後端在補建時填回本人），鏡像列在原列基礎上做這些改寫：

| 欄位 | 鏡像列的值 |
| ------ | ------ |
| `c_personid` | 原列的 `c_assoc_id`（對方） |
| `c_assoc_id` | 原列的 `c_personid`（本人） |
| `c_assoc_code` | **反向關係碼** |
| `c_kin_code`／`c_assoc_kin_code` | 對應的反向親屬配對碼 |
| `c_kin_id`／`c_assoc_kin_id` | 一律被寫成本人的 `person_id` |

反向碼的來源：

1. 你在 `changes` 顯式送的 `c_assocship_pair`（社會關係）／`c_kinship_pair`（親屬）／`c_assoc_kinship_pair`；
2. 未送時，以代碼表的權威反向碼推導（`ASSOC_CODES.c_assoc_pair`、`KINSHIP_CODES.c_kin_pair1`）。

驗證行為**不對稱**：`kinship` 的 `c_kinship_pair` 會驗證是否為合法配對，不合法即 422 並整筆回滾（該回應只有 `message`，沒有 `errors`）；`associations` 的三個 pair 欄位**不驗證**，送錯會被寫進鏡像列。

### 12.2 何時會補建、何時只同步

| 操作 | 行為 |
| ------ | ------ |
| `associations` create | 對面沒有互逆列時**補建**；有則同步 |
| `associations` update | **只有顯式送了任一 pair 欄位時才補建**缺失的鏡像；只改備註等欄位則不臆造鏡像 |
| `kinship` create | 對面沒有互逆列時**補建** |
| `kinship` update | 一般情況**不補建**，只同步已存在的那一列；例外是「只送 `c_kinship_pair`、沒有任何 `KIN_DATA` 欄變更」的修復路徑，那條會補建 |
| `kinship` delete | 一併刪除對面的互逆列；命中多筆需確認（`meta.force`） |
| `associations` delete | 一併刪除對面的互逆列；**定位不到、命中多筆、或代碼表無配對映射時都靜默跳過**（只留伺服器日誌，可能留下孤兒列） |

另有一個容易忽略的分支：若正向關係碼在代碼表中查不到權威反向碼，鏡像列仍會被建立，但**反向碼寫成哨兵 `0`（未詳）**且不做任何分歧偵測。也就是說對方名下會出現一條關係碼為「未詳」的列。送出前請確認關係碼有正確的配對定義。

### 12.3 三種 409 與一種 422，以及怎麼復原

| HTTP | `errors` 鍵 | 意義 | 復原方式 |
| ------ | ------ | ------ | ------ |
| 409 | `mirror_conflict` = `{ table, pk, fields }` | 對面已有對應的互逆列，但內容與本次寫入不一致（真分歧） | 先用 `pk`（**對面那一列**的主鍵）確認對面現況；確定要以本次內容覆寫，就帶 `meta.force: true` 重送 |
| 409 | `mirror_suspected` = `{ table, candidates, authoritative_code, count }` | 嚴格定位落空，但放寬後在對面找到 N 條「疑似同一關係、但關係碼已漂移」的列 | 檢視 `candidates`（**只含主鍵欄**，需要細節請自行回查）與 `authoritative_code`（權威反向碼）；確認要把它們收斂到權威碼，帶 `meta.force: true` 重送 |
| 409 | `mirror_delete_multiple` = `{ table, candidates, count }` | 刪除時對面命中多筆互逆列（只有 `kinship` 會回這個） | 檢視 `candidates`（含主鍵、`c_source` 與建立資訊），確認要一併刪除全部，帶 `meta.force: true` 重送 |
| 422 | `mirror_integrity: fail_closed` | 資料完整性不足以安全同步（代碼表缺配對碼、沒有可用的權威反向碼） | **不要用 `force` 硬推**；這代表代碼表資料有問題，請回報維護者 |

要點：

- 上述任一情況發生時，**整筆交易已回滾**——包含你原本要寫的那一列。不會出現「本人這側寫了、對方那側沒寫」的半套狀態。
- `meta.force` 只是「我已看過影響範圍並確認」的確認旗標，**不要預設帶上**。它會讓後端跳過分歧偵測直接覆寫／收斂／刪除對方人物的資料。
- `meta.force` 在 `associations` 與 `kinship` 的 **create／update** 都有效（用來解除 `mirror_conflict`／`mirror_suspected`）。**刪除時的「一併刪除多筆」確認旗標只有 `kinship` 會讀**；`associations` 的刪除不讀它。
- `meta.force` **不能**解除 `mirror_integrity`（422）：該檢查在偵測分歧之前就 fail-closed，硬帶 force 也繞不過。
- **`mode=proposal` 完全不做鏡像偵測**：direct 會 409 的情況，提案階段一律照收 200。之後分兩種結果：
  - 多數情況（create／update 的分歧、`kinship` update 對面缺鏡像）在核准重放時失敗，提案維持待審。**外部提交者送出的關係類提案遲遲未被核准，這是常見成因。**
  - **例外：親屬「刪除」提案的核准不會擋。** 核准端對刪除鏡像採用「一併刪除全部對應反向列」的語義，也就是 direct 會回 409 `mirror_delete_multiple` 要你確認的情況，**核准時會直接把對面所有候選列刪掉**。送刪除提案前請自行確認對面只有一條互逆列。

### 12.4 只修配對碼（pair-only）

`associations` 與 `kinship` 的 `update` 支援一種特殊請求：`changes` 內**只**送互逆配對碼（`c_assocship_pair`／`c_kinship_pair`／`c_assoc_kinship_pair`），不動任何真實資料表欄位。這種請求不會被「`changes` 為空」擋下，而是走專門的鏡像修復路徑：

- 用途是修正既有鏡像列的反向碼、或補建單邊缺失的鏡像（`kinship` 的這條路徑會補建，一般 update 不會）。
- 回應的 `result.updated_fields` 就是你送的那些 pair 欄位名，且**沒有 `operation_id` 也沒有 `row`**（見第七章的回應例外表）。
- 這是外部端修正單邊關係資料最乾淨的入口。

另外兩點對「多筆漂移」的處理限制：

- 帶 `meta.force` 收斂時，**只收斂第一條**候選，其餘仍留待人工處理。
- 對面那條列的關係碼若本身是合法代碼（代表它是「另一段真實關係」而不是漂移），**永不覆寫**——連 `meta.force` 也不會動它。

### 12.5 鏡像寫入的稽核足跡

外部端在比對稽核紀錄時要知道：

- 一次關係寫入只產生 **一筆 `operations`**（正向那一筆）；鏡像列不會有自己的 operation。
- `audit_log` 則是「每一列實際變更各一筆」，且**都掛在同一個 operation id 上**。典型情況是正向＋鏡像共兩筆，但也可能只有一筆（對面沒有鏡像且本次不補建）或更多筆（同步／收斂命中多列）。
- 鏡像列的稽核欄由系統蓋章：更新時蓋 `c_modified_by`／`c_modified_date`，補建時另蓋 `c_created_by`／`c_created_date`。
- 因此**對方人物那一列的最後修改者會變成你**（或核准提案時的「審核人 (Proposed by: 提案人)」雙人名）。這是預期行為，不是資料被誤改。

### 12.6 `POST /api/v2/relationship/opposite-edges`（偵測對面現況）

純讀取端點，供編輯介面在載入某一列關係時先看看對面長什麼樣。**不在 CSRF 豁免清單內，外部 Bearer 客戶端無法呼叫**（419）。

請求：

| 參數名 | 參數類型 | 必填 | 說明 |
| ------ | ------ | ------ | ------ |
| resource | 字串 | ✔ | `kinship`／`kin`／`kin_data`，或 `associations`／`association`／`assoc`／`assoc_data`；其他值回 422 |
| person_id | 數字 | ✔ | 本人 |
| forward.opposite_id | 數字 | ✔ | 對方人物 ID（非數字回 422） |
| forward.forward_code | 數字 | ✔ | 正向關係碼／親屬碼（非數字回 422） |
| forward.text_title | 字串 | — | 僅社會關係用，預設空字串。**這是精確比對條件**，漏送等於用 `''` 去比，對面會被判成 `missing` |
| forward.first_year | 數字 | — | 僅社會關係用，預設 `-9999`。同樣是精確比對條件 |
| forward.autogen_notes | 字串 | — | 僅親屬用（實際定位不採用此欄，見下） |

回應：

```json
{
  "ok": true,
  "detection": true,
  "resource": "kinship",
  "count": 1,
  "status": "single",
  "edges": [
    { "c_personid": 1760, "c_kin_id": 1762, "c_kin_code": 181, "c_source": 7596, "c_created_by": null, "c_created_date": null }
  ]
}
```

| 屬性名 | 說明 |
| ------ | ------ |
| detection | 是否實際執行偵測。**沒有直接寫入權限者（例如眾包帳號）一律回 `{"ok":true,"detection":false}`**，不做偵測 |
| count / status | 命中數與判定：`0`／`missing`（對面缺邊）、`1`／`single`（正常）、`>1`／`multiple`（一對多，需人工裁決） |
| edges | 命中的對面列摘要。兩種資源的欄位組**不同**：親屬為 `c_personid`／`c_kin_id`／`c_kin_code`／`c_source`／建立資訊；社會關係為 `c_personid`／`c_assoc_id`／`c_assoc_code`／`c_text_title`／`c_source`／建立資訊（沒有 `c_kin_id`） |

其他行為：

- 回應的 `resource` 是正規化後的值：親屬為 `kinship`，社會關係為 **`association`（單數）**。
- 未登入 401、帳號未啟用 403。
- 定位邏輯：以 `(對面 = 對方人物, 指向 = 本人, 關係碼 ∈ 定位碼集)` 判定。親屬的定位碼集是「指向我」∪「我指向」的**聯集**（涵蓋排行碼與非對稱配對），不只是單一反向碼；`c_autogen_notes` **不列入**比對條件（它在鏡像兩側天生不對稱，納入會誤判成缺邊）。

---

## 十三、代碼表與複合實體寫入

除人物資料外，`/api/v2` 也開放少量代碼表與兩個「複合實體聚合」的寫入。這些資源同樣走第四章的請求信封，**`person_id` 仍為必填**（代碼表是全域資料，慣例填 `0`）。

### 13.1 可修改的代碼表（只支援 `update`）

`direct` 與 `proposal` 皆可。每張表只開放少數欄位（多為拼音／羅馬字或名稱欄）：

| resource | 別名 | 資料表 | 主鍵 | 可寫欄位 |
| ------ | ------ | ------ | ------ | ------ |
| nianhao | nian_hao | NIAN_HAO | c_nianhao_id | c_nianhao_pin |
| office_codes | office_code | OFFICE_CODES | c_office_id | c_office_pinyin, c_office_pinyin_alt |
| dynasties | dynasty | DYNASTIES | c_dy | c_dynasty |
| choronym_codes | choronym_code, choronym | CHORONYM_CODES | c_choronym_code | c_choronym_desc |
| ethnicity_tribe_codes | ethnicity, ethnicity_tribe | ETHNICITY_TRIBE_CODES | c_ethnicity_code | c_name, c_romanized, c_surname |
| text_codes | — | TEXT_CODES | c_textid | c_title |
| text_instance_data | text_instance | TEXT_INSTANCE_DATA | c_textid, c_text_edition_id, c_text_instance_id | c_instance_title, c_pub_year, c_publisher |
| text_biblcat_codes | text_biblcat | TEXT_BIBLCAT_CODES | c_text_cat_code | c_text_cat_pinyin |
| ganzhi_codes | ganzhi | GANZHI_CODES | c_ganzhi_code | c_ganzhi_py |
| social_institution_name_codes | — | SOCIAL_INSTITUTION_NAME_CODES | c_inst_name_code | c_inst_name_py |
| social_institution_types | — | SOCIAL_INSTITUTION_TYPES | c_inst_type_code | c_inst_type_py |
| admin_cat_codes | admin_cat | ADMIN_CAT_CODES | c_admin_cat_code | c_admin_cat_py |
| addr_codes | — | ADDR_CODES | c_addr_id | c_name |
| char_variant_map | char-variant-map, charvariantmap | char_variant_map | id | c_variant_char, c_reference_char, c_strict_excluded, c_notes |

規則：

- 值必須是**字串或 `null`**，且長度 ≤ 255 字元，否則 422（訊息形如 `<欄名> 必須為字串或 null`／`<欄名> 長度不可超過 255 字元`）。唯二可送整數的欄位是 `TEXT_INSTANCE_DATA.c_pub_year` 與 `char_variant_map.c_strict_excluded`。
- 純拼音欄位在保存時會**靜默**把 `v` 正規化為 `ü`（只轉「`l`／`n` 之後、且後面不接 `a`／`i`／`o`／`u`」的 `v`，例如 `lv`→`lü`、`nv`→`nü`）。可能含西文的混合欄不做這個轉換——注意這是**逐表逐欄**登記的，同一個欄名在不同表可能不同：`ETHNICITY_TRIBE_CODES.c_name` 與 `TEXT_CODES.c_title` 會轉，`ADDR_CODES.c_name`、`DYNASTIES.c_dynasty`、`CHORONYM_CODES.c_choronym_desc`、`ETHNICITY_TRIBE_CODES.c_romanized` 不轉。
- **`v→ü` 正規化發生在「有沒有變更」的判斷之前**：若庫內已是 `lü` 而你送 `lv`，兩者正規化後相同，會得到 422 `changes: no_effective_changes`。這不是 bug。
- 改後的值與其他列的唯一鍵衝突 → 409 `changes: conflict`。
- 其餘錯誤與人物子資源一致：白名單外欄位 422 `disallowed_fields`、`changes` 整包為空 422 `changes: empty`、有送但值相同 422 `changes: no_effective_changes`、找不到列 404。
- 回應 `result` 含 `pk`／`updated_fields`／`operation_id`／`row`；proposal 的 payload 另含 `__key_columns` 與 `__proposal_meta`。
- **代碼表 `update` 寫入 `operations` 時，`c_personid` 一律被記成 `0`**（不論你送什麼 `person_id`）。13.2 的 `create` 則是原樣記錄你送的 `person_id`——兩者不一致，追蹤時請注意。

### 13.2 可新增的代碼表（只支援 `create`、只支援 `direct`）

只有兩張表開放新增：

| resource | 別名 | 資料表 | 主鍵 | 可寫欄位 |
| ------ | ------ | ------ | ------ | ------ |
| text-codes | text_codes, textcodes | TEXT_CODES | c_textid（可自動配發） | c_title_chn, c_title, c_title_trans, c_text_type_id, c_text_year, c_text_nh_code, c_text_nh_year, c_text_range_code, c_bibl_cat_code, c_extant, c_text_country, c_text_dy, c_source, c_pages, c_url_api, c_url_api_coda, c_url_homepage, c_notes, c_title_alt_chn |
| char-variant-map | char_variant_map, charvariantmap | char_variant_map | id（可自動配發） | c_variant_char, c_reference_char, c_strict_excluded, c_notes |

- 主鍵可放在 `target.pk` 或 `changes`；**兩處都沒給值時由伺服器以 `max(主鍵)+1` 自動配發**。但 `target` 這個鍵本身仍必須存在——請送 `"target": {"pk": {}}`，完全省略會被控制器層 422 擋下。
- 顯式指定且已存在 → 409 `target.pk: conflict`；並發撞號 → 409（訊息會提示重試）。**非主鍵的唯一鍵撞值時也回 409**，而 `errors` 仍是 `target.pk: conflict`（例如 `char_variant_map.c_variant_char` 重複）。
- 白名單外欄位 → 422，`errors.changes` 是單一字串 `"disallowed_fields: c_xxx"`。
- 只支援 `mode=direct`；送 `mode=proposal` 會得到 **501**（找不到 handler），不是 403。
- 回應：`result.pk`、`result.status: "created"`、`result.operation_id`、`result.row`；系統會蓋 `c_created_by`／`c_created_date`。
- 可以放進 `batch_mutate` 一起送。
- `TEXT_CODES` 的新增與修改是**兩份不同的定義**，別名清單不對稱：`create` 接受 `text-codes`／`text_codes`／`textcodes`，而 `update` **只接受 `text_codes`**（送 `text-codes` 做 update 會 501）。最保險是 create 用 `text-codes`、update 用 `text_codes`。

### 13.3 代碼表刪除：一律不開放

代碼表被大量人物資料以外鍵引用，刪一列可能影響數萬筆記錄且難以乾淨復原，因此刪除**已停用**：

- 上述兩張支援寫入的表、且 `mode=direct` → **403**（`代碼表刪除已停用（防止級聯刪除人物資料）`）
- 其他代碼表，或 `mode=proposal` → **501**（找不到對應 handler）

### 13.4 複合實體聚合：office 與 social-institution

這兩個「實體」各自跨多張表（官職涵蓋 `OFFICE_CODES` + `OFFICE_CODE_TYPE_REL`；社會機構涵蓋 `SOCIAL_INSTITUTION_CODES` + `SOCIAL_INSTITUTION_NAME_CODES` + `SOCIAL_INSTITUTION_ADDR`），由聚合服務統一寫入。**新增、刪除，以及會牽動多表一致性的結構性欄位，一律要走這裡的聚合資源**，不要自己拼底層表。（13.1 開放的那幾個底層代碼表欄位——例如 `OFFICE_CODES.c_office_pinyin`、`SOCIAL_INSTITUTION_NAME_CODES.c_inst_name_py`——是單欄拼音修正，走 13.1 的 `update` 是可以的。）

| resource | 別名 | 主鍵欄 | 支援操作 |
| ------ | ------ | ------ | ------ |
| office | offices, office-load | c_office_id | create／update／delete |
| social-institution | social-institutions, social-institution-load, socialinst-load | c_inst_code | create／update／delete |

- `direct` 與 `proposal` **都支援**（眾包帳號可以送這兩個聚合的提案）。
- `target.pk` 接受帶前綴或不帶前綴的鍵名：`{"c_office_id": 123}` 或 `{"office_id": 123}` 皆可。負數或非數字視為未提供（422）；`0` 會被當成合法 id 去查，因此得到的是 404（找不到官職／社會機構）而不是 422。
- **`offices` 這個別名同時是任官子資源（`POSTED_TO_OFFICE_DATA`）的別名，且任官會先被匹配到。**要寫官職實體請用 `office`。
- **`target.pk` 這個鍵必填**（新增時送空物件 `{}`），與 13.2 同理。
- 輸入欄位用**語義短名**，也接受對應的資料表欄名：
  - 官職（create／update 共用）**必填**：`name`（或 `c_office_chn`）、`type_ids`（陣列；也接受 `type_id`／`c_office_tree_id` 單值）、`source_id`（或 `c_source`，須存在於 `TEXT_CODES`）、`dynasty_code`（或 `c_dy`；也可送 `dynasty_label` 由後端查碼）。選填：`translation`、`name_alt`、`translation_alt`、`pinyin`、`pinyin_alt`、`pages`、`notes`。未給 `pinyin` 時會依名稱自動派生。
  - 社會機構 **create** 必填：`name`（或 `c_inst_name_hz`）、`type_code`（或 `c_inst_type_code`／`type_label`）、`dynasty_code`（或 `c_inst_begin_dy`／`dynasty_label`）、`addr_id`（或 `c_inst_addr_id`）、`source_id`（或 `c_source`）。
  - 社會機構 **update** 的地址改用 **`addresses` 陣列**（不是 `addr_id`），且**至少要有一列**，每列需含 `addr_id`。缺少即 422 `addresses: required`／`addresses.N.addr_id: required_integer`。
- **聚合的 `update` 是「全欄覆寫」，不是第七章的 PATCH 語義**：沒帶到的選填欄會被寫成 `null`（例如漏帶 `name_alt`／`pages` 就會清掉既有值）。更新前請先讀出現值、補齊整份 payload。
- 校驗錯誤是語義鍵而非資料表欄名，例如 `name: required`、`type_ids: required` / `not_found_in_office_type_tree`、`type: invalid`、`type_label: not_found`、`source_id: required_integer` / `not_found_in_text_codes`、`dynasty: invalid`、`dynasty_label: not_found`、`addr_id: required_integer` / `not_found_in_addr_codes`、`addresses: required`、`addresses.N.addr_id: required_integer`，以及各選填整數欄的 `integer`、`floruit_dy`／`end_dy: invalid`、`by_nianhao_code`／`ey_nianhao_code: not_found_in_nian_hao`、`by_year_range`／`ey_year_range: not_found_in_year_range_codes`。
- 引用護欄：
  - 官職 `delete`：仍被人物任官引用時回 **409** `c_office_id: referenced_by_postings`，並附 `reference_count`。
  - 社會機構 `delete`：仍被人物資料引用時回 **409** `c_inst_code: referenced_by_person_data`。
  - 社會機構 `update`：機構名稱仍被引用時不得改名，回 **409** `name: rename_blocked_while_referenced`。
- 回應 `result` 除了 `pk`／`status`（`created`／`updated`／`deleted`）／`operation_id` 外，還有實體專屬欄位：
  - 官職：create／update 帶 `row`（含 `type_ids`），update 另帶 `types_added`／`types_removed`，delete 帶 `rel_deleted`。
  - 社會機構：create 帶 `name_created`，update 帶 `name_changed`／`addr_added`／`addr_removed`，delete 帶 `addr_deleted`。
- 社會機構的 `create` 回應 `result.pk` 有**兩個鍵**（`c_inst_code` + `c_inst_name_code`），與表格所列的單一主鍵欄不同——請以回應為準。
- `proposal` 模式存的是「聚合意圖」（`__entity_aggregate`、`__entity_resource`、`__entity_operation`、`__entity_pk` 與原始 `changes`），核准時以 `direct` 重放；因此 create 提案的 `result.pk` 為 `null`（主鍵尚未配發）。
- 聚合提案的 `operations.resource` 存的是**聚合名**（`office`／`social-institution`），不是資料表名——用 `GET /api/v2/operations` 追蹤時要以此篩選。
- `delete` 的引用護欄在**提案提交當下**就會擋（回 409，不會留下提案），不是等到核准才發現。
- 稽核足跡：聚合寫入會對**每一個實際變更的下層資料列各寫一筆 `operations` 與 `audit_log`**（官職的每一筆類型關聯、社會機構的每一筆地址增刪都各算一筆，筆數隨關聯列數增加），但回應只回主表那一筆的 `operation_id`。
- 社會機構改名時，若目標名稱已存在於名稱代碼表，會**複用既有名碼**而不新增；舊名碼不會回收。
- 眾包帳號在這裡與代碼表不同：**聚合的 `proposal` 是真的支援**（`direct` 403、`proposal` 200）；而代碼表的 `create`／`delete` 只有 `direct`，送 `proposal` 會 501。

權威定義：`config/entity_aggregates.php` 與 `app/Services/Mutations/EntityAggregate/*AggregateDefinition.php`。

---

## 十四、其他開放端點

本章收錄前面章節之外、目前仍開放的端點。多數是站內介面在用的輔助接口，一併記錄以便外部整合時知道有什麼可用、以及哪些不該用。

### 14.1 著作／文獻查詢（公開）

`GET /api/v2/texts?ids=...` — 批次依 `c_textid` 取 `TEXT_CODES`，用於把出處 id 換成書名。**不需認證。**

| 參數名 | 參數類型 | 必填 | 說明 |
| ------ | ------ | ------ | ------ |
| ids | 字串或陣列 | ✔ | 以逗號或空白分隔的 id 清單（也可用 `ids[]=` 陣列形式）。非數字片段會被忽略、重複值會去重。解析後為空即 422 `ids: required` |

回應：

```json
{
  "ok": true,
  "data": [
    { "c_textid": 7596, "c_title_chn": "宋史", "c_title": "Song shi", "c_source": 0, "c_source_title_chn": null, "c_source_title": null, "c_url_api": null, "c_url_api_coda": null, "c_url_homepage": null }
  ],
  "meta": { "requested_ids": [7596, 99999999], "found_count": 1, "missing_ids": [99999999] }
}
```

- `data` 依 `ids` 的順序回傳（查無的 id 不佔位，改列在 `meta.missing_ids`）。
- 每筆除 `TEXT_CODES` 全欄外，另附該文獻之來源書目資訊（`c_source_title_chn`／`c_source_title`／`c_source_url_*`，來自 `c_source` 自關聯）。

`GET /api/v2/texts/{textId}` — 單筆版本，回 `{ "ok": true, "data": {...} }`；查無回 **404** `TEXT_CODES 記錄不存在`。

### 14.2 帳號與 API Token

| 方法 | 路徑 | 認證 | 說明 |
| ------ | ------ | ------ | ------ |
| GET | `/api/user` | Bearer（`auth:sanctum`） | 回傳目前 token 對應的帳號資料（欄位見下）。追蹤提案時 `GET /api/v2/operations?editor=` 要用的就是這裡的 `id`。帳號未啟用時回 403 |
| GET | `/api-tokens` | Session | 列出自己的 token（不含明文） |
| POST | `/api-tokens` | Session | 簽發新 token。可帶 `name`（必填）、`abilities`（預設 `["mcp:read"]`，通配 `*` 已停用）、`expires_in`（1～3650 天，未帶＝永不過期）。**明文只在此回傳一次** |
| DELETE | `/api-tokens/{tokenId}` | Session | 撤銷指定 token |
| DELETE | `/api-tokens` | Session | 撤銷自己全部 token |

`/api-tokens` 系列掛在 `web` 群組且需要登入 session，**無法只憑既有 token 換發新 token**；請在網站個人資料頁操作。

`GET /api/user` 回傳固定的白名單欄位：

```json
{
  "id": 42,
  "name": "王小明",
  "email": "u@example.com",
  "institution": "Harvard",
  "avatar": "avatar0.png",
  "is_admin": 0,
  "is_active": 1,
  "created_at": "2026-03-01T02:11:04.000000Z",
  "updated_at": "2026-08-14T03:22:41.000000Z"
}
```

| 屬性名 | 屬性類型 | 說明 |
| ------ | ------ | ------ |
| id | 數字 | 帳號 ID，即 `GET /api/v2/operations?editor=` 要用的值 |
| name | 字串 | 顯示名稱（也是稽核欄 `c_created_by`／`c_modified_by` 會蓋的署名） |
| email | 字串 | 帳號 email |
| institution | 字串/null | 所屬機構 |
| avatar | 字串 | 頭像檔名，資料庫預設 `avatar0.png`（NOT NULL，實務上不會是 null） |
| is_admin | 數字 | 角色：0=一般、1=專家、2=眾包、3=系統管理員 |
| is_active | 數字 | 帳號啟用狀態。此端點在帳號未啟用時直接回 403，因此**成功回應中恆為 `1`** |
| created_at | 字串 | 建立時間（ISO 8601，UTC） |
| updated_at | 字串 | 更新時間（ISO 8601，UTC） |

**不回傳**任何憑證欄位（`password`、`remember_token`、`confirmation_token`），也不回傳使用者偏好 `settings`。若日後需要新欄位，請在 `Api\UserController::show()` 顯式加入並同步本節。

**`POST /api/v1/user/login` 已無實際用途**：它是 OAuth 時代的遺留，帳密驗證通過後會轉發到早已不存在的 `oauth/token` 路由，因此最終回 **404**、拿不到任何憑證。要注意兩個副作用：它實際用的是 session guard（不是 token guard），所以在 session 有被啟動的情境下，**驗證成功會先留下一個已登入的 session cookie 再回 404**；且此路由掛「訪客專用」中間件（見舊版章節的說明）。要程式化存取請改用上表簽發的 Bearer token。

### 14.3 眾包舊通道（不建議使用）

v2 提案流程上線前的舊機制，仍在線但**建議一律改用 `/api/v2/*` 的 `mode=proposal`**：

| 方法 | 路徑 | 參數 | 說明 |
| ------ | ------ | ------ | ------ |
| GET/POST | `/api/operations/token` | `q`＝email、`p`＝密碼 | 換取長期 token（成功時回傳的 body 就是 token 字串）。**僅眾包身分且帳號啟用者可取得**；其他情形回傳中文說明字串而非 token |
| POST | `/api/operations/add` | `token`、`resource`（表名）、`json`（整包資料） | 新增一筆待處理記錄（`op_type=1`） |
| POST | `/api/operations/update` | `token`、`resource`、`json`，另依表別帶 `c_personid`（`BIOG_MAIN`）或 `pId`（`OFFICE_CODES`／`OFFICE_TYPE_TREE`；`OFFICE_CODE_TYPE_REL` 的 `pId` 格式是 `c_office_id-c_office_tree_id`） | 修改（`op_type=3`） |
| POST | `/api/operations/delete` | `token`、`resource`，另依表別帶 `c_personid` 或 `pId`；**不讀 `json`** | 刪除（`op_type=4`） |

差異與風險：

- 這條通道寫入的是 `operations` 表且 `crowdsourcing_status = 2`，**與 v2 的提案（`op_type` 8／9／10、`__review_status`）是兩套不同的機制**，也不會出現在 `GET /api/v2/operations`（該端點只回 `crowdsourcing_status = 0`）。此處的 `op_type` 1／3／4 與第三章「一般操作」的編碼相同，但語義是「待處理的眾包投稿」，不是已落庫的操作。
- 它不做欄位白名單、不驗證複合主鍵，寫入 `add`／`update`／`delete` 時也不寫 `audit_log`（只有 `token` 端點會記安全審計）；`json` 是整包字串。
- token 是**長期有效且無到期機制**的憑證（帳號的 `confirmation_token`），與 14.2 的 Sanctum token 不同，請勿外流。
- 回應形狀不一致且**狀態碼不可靠**：`add` 成功回 JSON `{"status_code":200,"message":"..."}`，`update`／`delete` 成功回裸字串 `'200'`；權限不足回 `'403'`（HTTP 403），但**參數缺漏回裸字串 `'500'` 而 HTTP 狀態碼其實是 200**——只看狀態碼會把失敗當成功。

### 14.4 代碼選單與自動完成（公開）

站內編輯介面的下拉選單與自動完成資料來源，目前**都不需認證**。

- 整表取回（`GET`，回傳**裸資料列陣列**）：`/api/select/{dynasty,nianhao,ganzhi,ethnicity,choronym,household,appttype,assumeoffice,officecate,parentstatus,measure,possact,birole,topic,occasion,role,range,altcode,biogaddr}`
- 關鍵字查詢（`GET`，多接受 `q` 或 `search` 等查詢參數，逐端點略有差異）：`/api/select/search/{addr,assoccode,assocpair,biog,entry,event,kincode,kinpair,office,officetype,pinyin,socialinst,socialinstaddr,socialinstcode,status,text,textauthor,textperson}`
- 其他：`/api/code/addr`（地址代碼查詢，`GET`）、`/api/name`（依條件查人名，接受 `GET` 或 `POST`）

**回應形狀不統一**，接前請先確認：

| 類型 | 回應 |
| ------ | ------ |
| 整表取回、`search/kinpair`、`search/assocpair` | 裸資料列陣列 |
| 其餘 `search/*`、`/api/code/addr`、`/api/name` | **Laravel 分頁物件**（`{current_page, data: [...], total, ...}`）——它也有 `data` 鍵，但不是 v2 的 `ok`／`data`／`pagination` 信封 |
| `search/pinyin` | **純文字**（拼音字串；查無親屬對應時另帶 `X-Pinyin-Kinship-Unmatched` 標頭） |

**`/api/select/codes` 這條路由雖然註冊了，但對應的控制器方法不存在，呼叫必然 500——請勿使用。**

權威定義：`app/Http/Controllers/ApiController.php`（`/api/select/*`、`/api/code/addr`）與 `app/Http/Controllers/Api/NameController.php`。這些端點主要為站內 UI 服務，**回應格式不保證穩定**，外部整合請優先用 v2 端點。

### 14.5 AI 輔助（需登入）

| 方法 | 路徑 | 說明 |
| ------ | ------ | ------ |
| POST | `/api/ai/code-lookup/suggest` | 由自然語言描述推薦代碼。參數：`query`（必填，≤500 字）、`table`（必填，只允許 `ASSOC_CODES` 或 `STATUS_CODES`）、`person_id`／`route_name`／`route_url`（選填） |
| POST | `/api/ai/posting/extract` | 從史料文字抽取任官資訊。參數：`source_text`（必填，≤5000 字）、`person_id`（必填）、`route_name`／`route_url`（選填） |

兩者都掛 `web` + `auth`（Session），且會再檢查帳號啟用（未啟用回 403，body 為 `{"success": false, "error": "..."}`）。因為在 `web` 群組且**不在 CSRF 豁免清單內**，實務上只有站內前端能呼叫。

### 14.6 MCP（Model Context Protocol）

`POST /api/mcp` — 供 AI 客戶端以 MCP 協定唯讀查詢 CBDB。需要 **Bearer token 且具備 `mcp:read` 能力**（見 14.2），另有獨立限流 120 次／分鐘。這是全站唯一會檢查 token abilities 的端點。

同一路徑的 `GET` 只是協定要求的佔位，**恆回 405 且不做任何認證**，不要拿它當健康檢查。

### 14.7 人物頁 API

`GET /cbdbapi/person`（等同舊路徑 `/cbdbapi/person.php`）——依 `id` 或 `name` 取單一人物資料。**完全公開，不需認證，也沒有額外限流。**

| 參數名 | 說明 |
| ------ | ------ |
| id | 人物 ID，1～7 位數字且 ≥ 1 |
| name | 人名（≤255 字） |
| mode（或 `o`） | 輸出格式：`json` 或 `xml` 走 API 分支；**其他任何值（含省略或拼錯）都會得到 HTML 頁面** |

`id` 與 `name` 至少要給一個，否則 422（`json`／`xml` 模式回 `{"error": {"code": 422, "message": "Validation failed.", "details": [...]}}`，`html` 模式回錯誤頁）。

### 14.8 機器可讀規格

`GET /openapi.yaml` — 回傳 `docs/openapi/openapi.yaml`（`Content-Type: application/yaml; charset=UTF-8`）。**注意該檔目前只涵蓋四個端點**（`/api/v2/create`、`/api/v2/mutate`、`/api/v2/delete`、`/api/v2/get`），缺 `batch_mutate`、`proposals/{id}/resubmit`、`relationship/opposite-edges`，以及所有讀取端點（`persons`／`operations`／`texts`）。有衝突時**以本文件為準**。

### 14.9 其他公開的 JSON／檔案端點

這些不是「API」設計出來的對外介面，而是站內頁面用的資料來源，但目前**都不需認證**，一併記錄以免誤以為受保護：

| 方法 | 路徑 | 說明 |
| ------ | ------ | ------ |
| GET | `/app/codes/text-title/{textId}` | 依 `c_textid` 取書名（限流 60 次／分）。功能與 14.1 的 `/api/v2/texts` 重疊，外部整合請優先用 14.1 |
| GET | `/codes/{table_name}/export` | 代碼表全量 CSV 匯出（限流 6 次／分） |
| GET | `/app/basicinformation/{id}/summary` | 人物摘要 JSON |
| GET | `/app/basicinformation/{id}/tabs/{tabKey}` | 人物詳情分頁資料 JSON |
| GET | `/basicinformation/{id}/map-points` | 人物地點座標 JSON |
| GET | `/chgis-map/status`、`/chgis-map/tiles/{z}/{x}/{y}` | CHGIS 底圖狀態與圖磚 |
| GET | `/metrics` | Prometheus 指標 |
| GET | `/sanctum/csrf-cookie` | 取得 CSRF cookie。**只有 cookie-based（session）客戶端需要**；用 Bearer token 呼叫 `/api/v2/*` 不需要它 |

這些端點的回應格式隨頁面需求調整，**不提供穩定性保證**。另外 Query Playground 的 JSON／SSE 端點（`/query-playground/*`）雖然也是 JSON 介面，但需要登入 session、且設計上專供站內頁面使用，不在本文件的對外 API 範圍內。

---

# 舊版 API 文檔

## 使用方法
將下文輸入示例中 /api... 前接 input.cbdb.fas.harvard.edu

形如: [https://input.cbdb.fas.harvard.edu/api/post_list?id=06&start=0&list=100](https://input.cbdb.fas.harvard.edu/api/post_list?id=06&start=0&list=100)

### 現況與注意事項（2026-08 校對）

下文記錄的 14 個查詢端點目前**全部仍然可用**，但有幾件事必須先知道：

- **這些端點掛的是「訪客專用」中間件**：已登入且帳號啟用者會被 302 轉向 `/home` 而不是拿到資料。實際會不會踩到，取決於 session 有沒有被啟動——`api` 群組本身不啟動 session，只有當請求帶著命中站內網域的 `Origin`／`Referer` 標頭時（Sanctum 判定為「來自前端」）才會。因此**瀏覽器或同源 fetch 帶著 cookie 呼叫會被導開**，而伺服器端不帶這兩個標頭的呼叫即使帶了 cookie 也照樣拿到資料。最省事的做法是別帶站內 cookie。Bearer token 對它們沒有作用，也不需要。
- 限流與其他 `api` 群組端點相同：600 次／分鐘，超過回 429。
- 回應是舊格式（`total`／`start`／`end`／`data`），與 v2 的 `ok`／`data`／`pagination` 不同。
- 仍存在但**未收錄於本文件**的舊端點：`/api/query_relatives` 與 `/api/query_relatives_1`（第九節 `query_relatives_2` 的早期版本，請優先用 `_2`）、`/api/OFFICE_CODES`、`/api/OFFICE_CODE_TYPE_REL`、`/api/OFFICE_TYPE_TREE`。
- `/api/v1/` 底下的舊 CRUD 端點（`searchC_presonid`／`addC_presonid`／`updateC_presonid`／`deleteC_presonid`／`userC_presonid`）**已整組下架**（下架前它們全部已是回 500 的死碼），寫入請改用 v2（第四～十三章）；`POST /api/v1/user/login` 雖仍在路由表上，但已無實際用途（見 14.2）。
- 新的整合工作請優先使用 v2；舊端點只維持相容、不再擴充。

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
