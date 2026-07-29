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

> **大批量（幾十筆以上）一律優先用 `/api/v2/batch_mutate`**：一次請求多筆，大幅減少 HTTP 往返與限流（429）壓力，且每筆仍走同一套 handler／校驗／`operations`＋AuditLog。單筆端點僅用於零星改動或需逐筆即時回應的場景。

### 批次端點 `/api/v2/batch_mutate`（大批量首選，已上線 #1156）

- Payload：`{ items: [ {resource, mode, operation, person_id, target:{pk}, changes, meta}, … ], atomic?, 頂層 resource/mode/operation/meta 預設（逐項可覆寫） }`；上限 `BATCH_MAX_ITEMS=500`（超過回 422，缺 items 回 422）。
- **逐筆分發到既有 `*MutationHandler`**：校驗／改鍵碰撞偵測／授權／`operations`＋AuditLog 與單筆端點**完全一致**，無平行寫入路徑。
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
5. **限流與網路**：`/api/v2/*` 會回 `429`（限流）；批次腳本要對 429／暫時性錯誤做**指數退避重試**，並在每筆間 `sleep`（0.2s 起）。
6. **生產負載**：每筆 `create` 會對 `operations` 表做 pending 提案預檢。此查詢**曾因缺索引拖垮過 prod**（大批寫入全表掃描 → 飽和 DB／php-fpm）；已補 `(resource, resource_id, op_type)` 索引修復（migration `2026_07_12_000000_add_resource_index_to_operations_table`，已入 develop）。**歷史教訓**：任何走 mutation 的大批寫入，先確認該類慢查詢已有索引，否則放慢節奏。優先用 **batch 端點**（一次請求、不製造並發掃描風暴）進一步降載。註：`409 already` 於 create 在 `findByPk` 命中即短路、不觸發該掃描，故廉價。
7. **可回滾／可追溯**：每筆都有 `operation_id` + `c_notes` batch id；出錯用 Operations／Restore（`OperationRepository`）回退。
8. 授權用 **Bearer PAT**（可直接用 MCP token；寫入靠 `canWriteDirectly()` 而非 token ability），**不寫成跑在 prod 的 artisan**（操作員未必有後台），全程**不改 Cloudflare（D1）**。

參考實作：`cbdb-dbs/d1_build_*/round3/sync_zhwiki_sources.py`（dry-run 預設、`--only`/`--limit`/`--offset` 分批、`--operator`、`--renote`、429 退避、結果 CSV 存 operation_id）。流程總覽見 `docs/ZHWIKI_SOURCE_SYNC.md`。

## 相關檔案

- `app/Http/Controllers/Api/MutationController.php`（store／create／delete／get）
- `app/Services/Mutations/*`（各資源 handler、`MutationHandlerRegistry`、`AbstractMutationHandler`）
- `app/Repositories/BiogSourceRepository.php`（sources 的 create/update/proposal/direct 實作）
- `app/Support/CompositePrimaryKey.php`（各表 PK `SCHEMAS`）
- `tests/Feature/ApiV2Mutate*Test.php`（各資源 mutation 回歸測試，改動後必跑）
