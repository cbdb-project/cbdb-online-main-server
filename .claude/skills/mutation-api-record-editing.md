---
name: 透過 Mutation API 修改記錄
description: 使用 /api/v2 mutation 端點對 CBDB 人物子資源與 code 表做 create／update／delete／get 的正確流程、payload 格式、授權模式、錯誤碼與批次改資料（dry-run → 小批 → 全量）的安全做法。含 zh-wiki（BIOG_SOURCE_DATA c_textid=60795）實戰範例。
---

# 透過 Mutation API 修改記錄

## 何時使用此技能

- 要對 CBDB 人物子資源（著述出處、別名、地址、任官、社會關係、親屬、事件、社會身份、著作、機構…）或 code 表做**程式化／批次**的新增、修改、刪除。
- 要把外部整理好的清單（如 `d1_build_*/round3/report_*.csv`）**增量**寫進 CBDB prod，而不是全量重灌。
- 需要每筆變更都可審計、可回滾（`operations` 表 + AuditLog）。

**不要**用 `WikiMaintenanceController`（現路徑 `/external-db-link`，原 `admin/wiki-maintenance`）做增量更新——寫入功能已移除，它現在只是唯讀的外部資料庫引用瀏覽器。維基／維基數據關聯的增量維護一律走本技能描述的 mutation API。

## 端點總覽

全部是 `web` 路由、`auth.optional` middleware，真正授權在 handler 內做。Controller：`app/Http/Controllers/Api/MutationController.php`。

| 操作 | 端點 | 說明 |
|------|------|------|
| 讀取 | `GET\|POST /api/v2/get` | 依 PK 取單列；需登入且 `isActive()` |
| 新增 | `POST /api/v2/create` | `operation` 固定 `create` |
| 修改 | `POST /api/v2/mutate` | `operation` 預設 `update`（也可傳 `create`） |
| 刪除 | `POST /api/v2/delete` | `operation` 固定 `delete` |
| **批次** | **`POST /api/v2/batch_mutate`** | **一個請求多筆——大批量首選**（見下節） |

> **大批量（幾十筆以上）一律優先用 `/api/v2/batch_mutate`**：一次請求多筆，大幅減少 HTTP 往返與每次請求的認證開銷、也避免製造並發連線，且每筆仍走同一套 handler／校驗，稽核行為與單筆端點一致（`direct` 寫資料列＋`operations`＋AuditLog；`proposal` 只寫一筆提案 `operations`）。**注意 DB 端的工作量並不會因為改用批次而變少**（內部就是逐筆呼叫同一套 handler），所以它不是「可以推更大總量」的許可；每批筆數建議見 [API.md](../../API.md) §1.3。單筆端點僅用於零星改動或需逐筆即時回應的場景。

### 批次端點 `/api/v2/batch_mutate`（大批量首選，已上線 #1156）

- Payload：`{ items: [ {resource, mode, operation, person_id, target:{pk}, changes, meta}, … ], atomic?, 頂層 resource/mode/operation/meta 預設（逐項可覆寫） }`；上限 `BATCH_MAX_ITEMS=500`（超過回 422，缺 items 回 422）。
- **逐筆分發到既有 `*MutationHandler`**：校驗／改鍵碰撞偵測／授權，以及各 `mode` 既有的 `operations`／AuditLog 行為，與單筆端點**完全一致**（`direct` 才寫 AuditLog，`proposal` 只寫提案 operation），無平行寫入路徑。
- `atomic=false`（**預設**）：逐筆獨立結算，回 200 + `results[]`（各含 `index/http_status/ok/result.operation_id`）+ `summary{total,ok,failed}`；`body.ok`＝是否全數成功。單筆失敗不影響其餘（例：create 撞 409 只該筆 failed）。
- `atomic=true`：整批單一交易，任一筆失敗整批回滾，回 409 + `failed_index`（handler 內層交易降為 savepoint）。
- 授權同單筆（Bearer PAT；direct 需 `canWriteDirectly()`）；已列入 CSRF 豁免。單筆未預期例外隔離為該筆 500，不拖垮整批。

## 授權與模式（mode）

handler 內 `authorizeDirect()` / `authorizeProposal()`（見 `AbstractMutationHandler`）：

- `mode=direct`：**立即寫入** prod。需 `Auth::user()->isActive()` **且** `canWriteDirectly()`。
- `mode=proposal`：寫入**審核佇列**（`operations` 內 pending 提案），需人工核准後才落地。只需 `isActive()`。

批次資料訂正若由操作員親自核對過來源，用 `direct`（立即寫入）；批量走 `proposal` 逐筆審批過於繁瑣，不採用。`direct` 需 `isActive()` 且 `canWriteDirectly()`（= 活躍且非群眾外包帳號）。

**授權方式：Bearer Token（Sanctum PAT），不是 artisan。** `OptionalAuthentication` middleware 同時支援 Session Cookie 與 Bearer Token；`/api/v2/{mutate,create,delete,get}` 皆列於 `VerifyCsrfToken::$except`（CSRF 豁免）。因此正確的批次通道是：

1. 操作員在網頁 **API Token 管理**（`/api-tokens`）建立 Personal Access Token（該帳號需活躍、非群眾外包）。
2. 腳本以 `Authorization: Bearer <token>` + `Content-Type: application/json` 直接 `POST` 各端點。

**不要寫成跑在 prod 的 artisan 命令**——不是所有操作員都有後台（shell）access；一律走上述 HTTP + Token 通道。腳本端自行實作 dry-run 與分批。

## Payload 格式

```jsonc
{
  "resource":  "sources",          // 資源別名，見下表
  "mode":      "direct",           // direct | proposal
  "operation": "update",           // create | update | delete（依端點）
  "person_id": 35370,              // 該記錄所屬人物 c_personid
  "target": { "pk": { /* 該表完整複合主鍵 */ } },
  "changes": { /* 欲寫入的欄位；delete 不需要 */ },
  "meta":    { "comment": "批次訂正 zh-wiki 條目標題" }
}
```

- `person_id` 必填，且必須等於 `target.pk` 內的人物欄（`validateMutation` 會擋 mismatch）。
- `target.pk` 必須是該表**完整**複合主鍵，欄位定義見 `app/Support/CompositePrimaryKey.php` 的 `SCHEMAS`。
- **改鍵**（更動主鍵欄位）：`target.pk` 放**舊**值、`changes` 放**新**值。handler 會偵測新主鍵碰撞（→ 409）。人物欄 `c_personid` 一律不可改鍵。

## 支援的 resource

由 `MutationHandlerRegistry` 註冊，每個 handler 的 `supports()` 決定 resource／mode／operation 是否成立。人物子資源：`basic-info`(BIOG_MAIN)、`altname`、`address`、`entry`、`status`、`event`、`association`(assoc)、`kinship`(kin)、`possession`、`text`、`posting`、`social-institution`、`sources`。另有 code 表（`ConfigCodeTableMutationHandler`）。resource 名稱有別名正規化（如 `kin`/`kinship`/`kin_data`）。不支援的組合回 501。

## 錯誤碼

- `422` 參數／主鍵格式錯、person_id mismatch、update 無實質變更（`no_effective_changes`）。
- `404` update／delete 目標列不存在。
- `409` create 主鍵已存在，或改鍵後新主鍵撞到既有列，或已有相同主鍵的 pending 提案。
- `403/401` 未登入／非活躍／無 `canWriteDirectly()`。

成功回應含 `result.operation_id`（對應 `operations` 表，可經 Operations／Restore 流程回滾）與 `result.row`（direct 時的落地列）。

## 實戰範例：批次維護中文維基（BIOG_SOURCE_DATA）

CBDB prod 的維基／維基數據關聯存在 **`BIOG_SOURCE_DATA`**（不是 `wikidata_mapping`，後者是 Cloudflare D1、不在本專案範圍）：

- `c_textid = 60795` → 中文維基百科；`c_pages` = 條目**標題**（非 URL）
- `c_textid = 68942` → Wikidata；`c_pages` = QID
- `c_textid = 68943` → 英文維基百科；`c_pages` = 英文標題
- 顯示連結 = `TEXT_CODES.c_url_api` + `c_pages`（含 CJK 則 `rawurlencode`）+ `c_url_api_coda`
- PK = (`c_personid`, `c_textid`, `c_pages`)；可改欄 = `c_notes`, `c_main_source`, `c_self_bio`

報表（`report_zhwiki_new/changed/removed.csv`）的 URL 需先轉成 `c_pages` 標題：取 `/wiki/` 之後片段並 `urldecode`（例：`https://zh.wikipedia.org/wiki/隋恭帝` → `隋恭帝`）。**寫入前務必先用只讀 MCP 查 prod 現況核對標題格式（底線 vs 空白、CJK 解碼）**，別直接信報表。

**c_pages 慣例（已核實）**：消歧義用**空格**非底線（prod 存「李天錫 (北魏)」），故轉換規則 = 取 `/wiki/` 後片段 → `urldecode` → `_` 換空格。

對應操作：

- **新增條目**（report_zhwiki_new）→ `create`
  `target.pk = {c_personid, c_textid:60795, c_pages:<新標題>}`，`changes` 至少含 `c_textid`/`c_pages`。已存在回 409（視為已完成、跳過即可）。
- **標題變更**（report_zhwiki_changed）→ `update`（改鍵）
  `target.pk = {…, c_pages:<prod 現有舊標題>}`，`changes = {c_pages:<新標題>}`。撞新標題回 409。
  **404「舊列不存在」多半是 prod 早已是新標題**（外部報表快照落後於 CBDB），內容已對、跳過即可；少數才是真異常，用 MCP 查現況確認。
- **條目移除**（report_zhwiki_removed）→ `delete`。

**批次標記（c_notes，可追溯）**：direct 模式 `meta.comment` **不落庫**（只有 proposal 用），故批次識別碼要寫進 `c_notes`（屬 `$data`、會持久化）。慣例格式：`日期 | 操作者 | batch_id`（batch_id 建議用來源檔內容 hash，穩定、跨分批共用）。以後查整批：`WHERE c_textid=60795 AND c_notes='<那串>'`。update 覆蓋 c_notes 前的舊值會存進 `operations` before-image + AuditLog，可回溯；若只想補標記不動 `c_pages`，對「現況列」發一筆只帶 `c_notes` 的 update 即可（不改鍵）。

## 批次改資料的安全流程（務必遵守）

1. **只讀盤點**：用 CBDB MCP（`cbdb-http-prod`，唯讀）把每筆的 prod 現況查出來，判定每筆該 create／update／skip，並核對 `c_pages` 格式。
2. **Dry-run**：腳本先跑 `--dry-run`（預設），只印「將對哪個 PK 做什麼、舊值→新值」與 `c_notes`，不連網、不寫 DB。人工抽查。
3. **小批寫入**：先寫 5~10 筆（`direct`），到 prod 覆核（用 `/api/v2/get` 或 MCP 讀回），確認連結正確、`c_notes` batch id 有寫、`operation_id` 有記錄。
4. **逐步擴大**：確認無誤後逐步加大批次，直到清單全數處理。
5. **限流與網路**：`/api/v2` 的 create／mutate／delete／batch_mutate／get／resubmit／relationship/opposite-edges 都在 `web` 群組，**應用程式路由層沒有配置任何 throttle**（600 次／分鐘只套用在 `api` 群組，例如 `persons`／`operations`／`texts`／`select/*`／`POST /api/v1/user/login` 與舊版 `/api/...`；`/api/mcp` 雖在該群組但已排除那條、改用專屬的 120 次／分鐘）。因此節流責任全在腳本：**送出後等回應再發下一個（序列化、不並發），寫入請求每秒不超過 1 次**。仍要對 429（反向代理／WAF 等應用層以外的來源仍可能回）與暫時性錯誤、5xx 做**指數退避重試**。對外契約與每批筆數建議見 [API.md](../../API.md) §1.3。
6. **生產負載**：每筆 `create` 會對 `operations` 表做 pending 提案預檢。此查詢**曾因缺索引拖垮過 prod**（大批寫入全表掃描 → 飽和 DB／php-fpm）；已補 `(resource, resource_id, op_type)` 索引修復（migration `2026_07_12_000000_add_resource_index_to_operations_table`，已入 develop）。**歷史教訓**：任何走 mutation 的大批寫入，先確認該類慢查詢已有索引，否則放慢節奏。優先用 **batch 端點**（一次請求、不製造並發掃描風暴）進一步降載。註：`409 already` 於 create 在 `findByPk` 命中即短路、不觸發該掃描，故廉價。
7. **可回滾／可追溯**：每筆都有 `operation_id` + `c_notes` batch id；出錯用 Operations／Restore（`OperationRepository`）回退。
8. 授權用 **Bearer PAT**（可直接用 MCP token；寫入靠 `canWriteDirectly()` 而非 token ability），**不寫成跑在 prod 的 artisan**（操作員未必有後台），全程**不改 Cloudflare（D1）**。

參考實作：`cbdb-dbs/d1_build_*/round3/sync_zhwiki_sources.py`（dry-run 預設、`--only`/`--limit`/`--offset` 分批、`--operator`、`--renote`、429 退避、結果 CSV 存 operation_id）。流程總覽見 `docs/ZHWIKI_SOURCE_SYNC.md`。

## 新增／修改寫入路徑：異體字落地替換（必掛）

見 AGENTS.md §1.3。這裡是**實作步驟**與踩過的坑。

### 掛哪個 hook

| 情況 | 做法 |
| ------ | ------ |
| 繼承 `AbstractPersonSubresourceCreateHandler`／`AbstractPersonSubresourceMutationHandler`／`AbstractCodeTableMutationHandler` | **什麼都不用做**，基底的 `handle()` 已掛 `Concerns\AppliesVariantReplacement` |
| 不繼承上述基底（例如自己組 payload 的聚合 handler、repository 方法、controller） | 自己 `use Concerns\AppliesVariantReplacement`，在**落庫前**呼叫 `$data = $this->applyVariantReplacement($data)`；沒有 `tableName()` 的類別要顯式傳表名 `applyVariantReplacement($data, $table)`（省略會 fallback 到不存在的方法而 **runtime fatal**，不是靜態錯誤） |
| 不是 handler（controller／service／repository） | 直接 `CharVariantMapService::replaceRow($data, $table)['data']`；單值用 `replaceFor($table, $column, $value)['text']`（`$input` 的鍵是「輸入欄名」而不是資料表欄名時，`replaceRow()` 會全部判成非文本欄而跳過——這種情況一定要用 `replaceFor()` 逐欄呼叫） |

### 掛鉤位置（順序錯了就是 bug）

必須早於：**PK 計算 → 查重／去重鍵查詢 → 拼音派生 → `operations.resource_id`／`audit_log.row_pk` 組裝**。

- 早於 PK 計算：`ALTNAME_DATA.c_alt_name_chn`、`ASSOC_DATA.c_text_title`、`BIOG_SOURCE_DATA.c_pages`
  都是**文本型 PK 成員**，替換等於改鍵。
- 早於變更偵測：否則「送變體形、現值已是參考形」會被判成有變更而寫一次無意義的 UPDATE。
- 早於拼音派生：拼音逐字查 `pinyin.c_chn`（**該欄**在 `EXCLUDED_COLUMNS`——不是整張 `pinyin` 表——所以異體字保有自己的讀音），先替換才會拿到
  參考字的讀音，中文欄與拼音欄才不會各說各話。
- 早於 `resource_id`／`row_pk`：否則同一筆稽核的 id 與內容字形矛盾，還原時會重建一列從未存在
  過的資料。

### 通知（notices）怎麼接

- handler 內：`replaced` 由 trait 收在 `$this->variantReplaced`，回應用 `withVariantNotices($response)`
  掛上（**成功、409、422 都要掛**——被擋下來時使用者才知道「我輸入的字被正規化了」）。
- 非 handler：`CharVariantMapService::buildNotices($replaced)` 取字串陣列；批次頁用
  `flattenReplaced()` 取 `[{from,to}]`。
- ⚠️ **不要自己 implode `replaced`**：衝突時值會是 list（同一變體在 strict 與 lenient 欄的閉包
  終點可以不同），直接字串串接會拋 "Array to string conversion"。
- ⚠️ **子類不要再呼叫一次 `replace*()`**：通用掛鉤先跑過、值已是參考形，再呼叫一次的 `replaced`
  恆為 `[]`；若又 assign 回 `$this->variantReplaced`，**通知會靜默消失**（S3 修掉的失效模式）。
  基底一律用 `mergeReplaced()` 而非 assign。
- 累積器記得重置：同一個 service／handler 實例在批次中逐列被呼叫，不重置會把上一列的通知帶到
  下一列（S4 修過一次）。

### 測試該斷言什麼

1. **落庫值**是參考形（不只看回應）。
2. **回應回落庫值**，而不是使用者原輸入（S4 抓到的缺陷：DB 寫參考形、回應回變體形，
   前端畫面與資料庫不一致）。
3. **notices 存在**；失敗回應（409／422）也要有。
4. **同一列 strict／lenient 並存**：姓名欄的 `峯` 不替換、同列 `c_notes` 的 `峯`→`峰`。
   （種子只放 `淸→清` 是測不出模式分流的——它的 `c_strict_excluded = 0`。）
5. **兩形並存查重**：既有列是變體形、新增參考形 ⇒ 409 而不是鑄出第二列；改鍵同理；
   而「只改非 PK 欄」在歷史上就已兩形並存時**不可**被誤擋。
6. **提案 payload** 存的是替換後的值，且核准落庫與 payload 一致。
7. 負向：`restore`／「只刪不寫」的快照**不替換**（並在同一支測試裡加一句正向對照，
   例如 `assertSame('清', CharVariantMapService::replaceFor($t, $c, '淸')['text'])`，
   否則機制整體死掉時負向斷言會假綠）。
8. 鑑別力：把掛鉤中和掉（改成 `return $data;`）跑一次，確認**真的會紅**。

## 相關檔案

- `app/Http/Controllers/Api/MutationController.php`（store／create／delete／get）
- `app/Services/Mutations/*`（各資源 handler、`MutationHandlerRegistry`、`AbstractMutationHandler`）
- `app/Repositories/BiogSourceRepository.php`（sources 的 create/update/proposal/direct 實作）
- `app/Support/CompositePrimaryKey.php`（各表 PK `SCHEMAS`）
- `tests/Feature/ApiV2Mutate*Test.php`（各資源 mutation 回歸測試，改動後必跑）
