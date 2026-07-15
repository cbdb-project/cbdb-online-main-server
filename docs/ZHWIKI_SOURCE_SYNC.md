# 中文維基條目增量維護（BIOG_SOURCE_DATA）

本文記錄「把 Wikidata P497 對照報表增量寫進 CBDB prod 的中文維基連結」的正確流程。
**此為現行做法；`WikiMaintenanceController`（全量刪除重灌）僅用於首次導入，不得用於增量維護**——見 [WIKI_TASK_MANAGEMENT.md](./WIKI_TASK_MANAGEMENT.md) 的說明橫幅。

操作型技能：[.claude/skills/mutation-api-record-editing.md](../.claude/skills/mutation-api-record-editing.md)。

## 資料位置

CBDB prod 的維基／維基數據關聯存在 **`BIOG_SOURCE_DATA`**（**不是** `wikidata_mapping`；後者是 Cloudflare D1 的下游，**不在本專案範圍、不改**）：

| c_textid | 來源 | c_pages 內容 |
|----------|------|--------------|
| 60795 | 中文維基百科 | 條目**標題**（非 URL） |
| 68942 | Wikidata | QID |
| 68943 | 英文維基百科 | 英文標題 |

- 顯示連結 = `TEXT_CODES.c_url_api`（zh = `https://zh.wikipedia.org/wiki/`）+ `c_pages`（含 CJK 則 `rawurlencode`）
- PK = (`c_personid`, `c_textid`, `c_pages`)；可改欄 = `c_notes`, `c_main_source`, `c_self_bio`

## 寫入通道

`POST /api/v2/mutate`（更新）／`/api/v2/create`（新增）／`/api/v2/delete`（刪除），`resource=sources`，`mode=direct`。

- 授權：`Authorization: Bearer <Sanctum PAT>`（可直接用 MCP token）。端點 CSRF 豁免；direct 寫入靠 `canWriteDirectly()`（活躍且非群眾外包），非 token ability。
- **不寫成跑在 prod 的 artisan**（並非所有操作員都有後台 access）。
- 每筆寫 `operations`（operation_id，可回滾）+ AuditLog。

## URL → c_pages 轉換（已對 prod 核實）

取 URL 中 `/wiki/` 之後片段 → `urldecode` → **把 `_` 換成空格**（prod 慣例：消歧義用空格，如 `李天錫 (北魏)`）。

```
https://zh.wikipedia.org/wiki/张茂宗_(唐朝)  ->  '张茂宗 (唐朝)'
```

## 操作對應

| 報表 | 操作 | target.pk.c_pages | changes |
|------|------|-------------------|---------|
| report_zhwiki_new | `create` | 新標題 | `c_textid`,`c_pages`,`c_notes` |
| report_zhwiki_changed | `update`（改鍵） | prod **舊**標題 | `c_pages`(新),`c_notes` |
| report_zhwiki_removed | `delete` | 舊標題 | — |

冪等與判讀：

- create 撞已存在 → `409`，視為已完成、跳過。
- update 找不到舊列 → `404`，**多半是 prod 早已是新標題**（外部報表快照落後於 CBDB），內容已對、跳過；少數才是真異常，用 MCP 查現況確認。
- `429` 限流 → 指數退避重試。

## 批次標記（c_notes）

direct 模式 `meta.comment` **不落庫**，故批次識別碼寫進 `c_notes`：

```
日期 | 操作者 | batch_id        例：2026-07-12 | Frank Lin | zhwiki-r3-67368e91
```

- `batch_id` = 來源報表檔內容 sha1 前 8 碼（穩定、跨分批共用）。
- 整批檢索：`SELECT ... FROM BIOG_SOURCE_DATA WHERE c_textid=60795 AND c_notes='<那串>'`。
- update 覆蓋 `c_notes` 前的舊值存於 operations before-image + AuditLog，可回溯。

## 執行腳本

> **新批次首選 `POST /api/v2/batch_mutate`**（一次多筆、少往返/限流；見 skill）。下面的 `sync_zhwiki_sources.py` 走**單筆**端點、寫於 batch 上線前，仍可用（dry-run/分批/幂等/429 退避俱全）；新腳本建議直接組 batch 請求。

`cbdb-dbs/d1_build_<date>/round3/sync_zhwiki_sources.py`

```bash
# 1) 只讀盤點 + dry-run（預設不寫）
python3 sync_zhwiki_sources.py --only changed --operator "Frank Lin" --dry-run

# 2) 小批寫入（direct），到 prod 讀回覆核
CBDB_MUTATE_TOKEN=<PAT> python3 sync_zhwiki_sources.py --execute --only changed --limit 5 --operator "Frank Lin"

# 3) 逐步擴大（--offset 續跑；幂等可重跑）
CBDB_MUTATE_TOKEN=<PAT> python3 sync_zhwiki_sources.py --execute --only changed --offset 5 --operator "Frank Lin"
CBDB_MUTATE_TOKEN=<PAT> python3 sync_zhwiki_sources.py --execute --only new     --offset 5 --operator "Frank Lin"

# 只回填 c_notes（不改 c_pages）
CBDB_MUTATE_TOKEN=<PAT> python3 sync_zhwiki_sources.py --execute --renote --only changed --operator "Frank Lin"

# 大批量放慢（operations 索引未部署前的止血）：每筆間隔 10 秒
CBDB_MUTATE_TOKEN=<PAT> python3 sync_zhwiki_sources.py --execute --only new --offset 5 --sleep 10 --operator "Frank Lin"
```

結果寫入 `sync_zhwiki_result_*.csv`（含 http_status / result / operation_id / batch_id）。

腳本行為：
- **429／暫時性錯誤**：指數退避重試（`post()` 內）。
- **`--sleep` 只在真正 `done`（有寫入）後生效**：409 already 於 create 在 `findByPk` 命中即短路返回（**早於**那條 operations 全表掃描），僅一次主鍵 SELECT、無掃描無寫入，故廉價、免限速；因此幂等續跑會**秒過**已建部分，只在新寫入時按 `--sleep` 節流。
- 冪等：`create` 撞已存在 → 409 略過；`update` 找不到舊列 → 404（多半 prod 早已是新標題）。

## 驗證（只讀 MCP）

```sql
-- 本批寫入/訂正的筆數（帶 batch note 者）
SELECT COUNT(*) FROM BIOG_SOURCE_DATA WHERE c_textid=60795 AND c_notes='<batch note>';
-- 抽驗某人現況
SELECT c_personid, c_pages, c_notes FROM BIOG_SOURCE_DATA WHERE c_textid=60795 AND c_personid IN (...);
```

**完成度權威核對**（不依賴 batch note，因已存在的列不帶本批 note）：把報表全部 person_id 切成 ~400 一塊的 `IN` 清單，逐塊 `SELECT COUNT(DISTINCT c_personid) ... WHERE c_textid=60795 AND c_personid IN (...)`，各塊相加應等於報表總數（本次 new：5 塊 400/400/400/400/370 = **1970/1970**）。

## 進度紀錄

- **2026-07-12 / batch `zhwiki-r3-67368e91`（全部完成）**：
  - report_zhwiki_changed **99/99**：95 筆更新到新標題並打 batch note；4 筆（83671/123971/202517/365932）CBDB 本就是新標題（外部報表快照落後於 CBDB）跳過、保留原 note；2 筆遇 429 已重試補上。
  - report_zhwiki_new **1970/1970**：分批寫入；最終以 MCP 分塊 `COUNT(DISTINCT c_personid)`（把 1970 個 id 切成 5 塊 IN 查詢，合計 1970）逐一核實，全部人物皆有 c_textid=60795 行。
  - report_zhwiki_removed 3 筆已手動處理。

### 事故與教訓：operations 全表掃描（重要）

寫入 new 途中 prod 一度整站不可達（先 429、後連根路徑都逾時）。事後定位：**每筆 create 都對 `operations` 表做一次全表掃描**——所有子資源 create 都會查 pending 提案（`WHERE resource=? AND resource_id IN(?) AND op_type=?`），但 `operations` 在 `resource`/`resource_id` 上無索引；該表隨每次 mutation 持續增長，於是連續/並發寫入時大量全表掃描飽和 DB、推爆 php-fpm（與 /codes 深分頁那次同一模式）。

- **根因修復（已合併）**：為 operations 補 `(resource, resource_id, op_type)` 索引（migration `2026_07_12_000000_add_resource_index_to_operations_table`，已入 develop）。修復後該預檢由全表掃描收斂為索引 seek，大批寫入不再製造掃描風暴。
- **本次緩解（索引前）**：中斷後改為 `--sleep 10`（10 秒/筆）續跑，避開並發掃描壓力，順利補齊。索引部署後不再需要如此保守。
- **恢復方式**：腳本幂等，`--offset` 從中斷點續跑；已建的回 409 略過。中斷時若客戶端收到 network error，記得**用 MCP 覆核該筆是否其實已落庫**（伺服器可能已寫入僅回應遺失）。

## Wikidata ID 同步（`c_textid=68942`，機制相同）

Wikidata 關聯（`c_textid=68942`、`c_pages`=QID）用同一套 `sources` mutation 通道維護。與 Wikidata 現況對帳用 QLever 抓 truthy P497（CBDB ID）：
```
PREFIX wdt: <http://www.wikidata.org/prop/direct/>
SELECT ?item ?cbdb WHERE { ?item wdt:P497 ?cbdb . }
```
（?cbdb 為零填充字串，需轉 int；QLever 端點 `https://qlever.cs.uni-freiburg.de/api/wikidata`，比 WDQS 快、能吐全量。）

**⚠ 不可全盤接收 Wikidata 當前版**：Wikidata 人人可編輯，當前狀態含 vandalism（**刪除 P497 是最常見的破壞**）。策略：
- **移除/降級類差異絕不自動套用**（不因 Wikidata 少了就刪 CBDB）；逐條查修訂史防破壞。
- **新增類風險低**（純追加、可回滾、一人多 QID 合法）——即便 QID 後來被歸併或與現有並存也不算錯數據；只保留輕量身份核對（姓名/年代明顯不符者單看）。
- 詳見記憶 `wikidata-sync-not-wholesale`。

**結構備忘**：一人可有多條 wikidata（PK 含 `c_pages`）；prod 現況約 134 人有 2~3 個 QID。故 create 不會因「已有一條」而 409（QID 不同即新行）。

### 進度紀錄（2026-07-13，本輪完成）

對帳基準：datadump `harvard_cbdb_20260711.db` 68942 vs 當前 Wikidata truthy P497（QLever）。初始差異：完全一致 ~417,457；待補（Wikidata有CBDB無）519；待清（CBDB有Wikidata無）662。

- **差異主因＝Wikidata 實體合併**：偵測法——查 CBDB 側 QID 的 redirect 狀態（`wbgetentities` 回 `redirects:{from,to}` 即已被合併）。**待補 519 中 514（99%）是合併；待清 662 中 526（79%）是被合併掉的舊 QID**。
- **已完成**（皆走 `POST /api/v2/batch_mutate`、direct、每筆 operation_id 可回滾）：
  - **19 條純新增**（note `wikidata-r3-add19`）：CBDB 完全缺 wikidata 的人物補上；MCP 核實 19/19。含 189181 查證為嘉王李運本人（「李嘉」＝姓+封號訛稱，與 Wikidata 同一實體，鏈接正確）。
  - **514 條合併遷移**（note `wikidata-r3-merge`）：把 CBDB 的舊 QID 就地改鍵為合併後的新 QID（一次 update 同消該人待補＋待清）。分批 100×5，MCP 核實 514/514。
- **Wikidata 側**：本輪對帳查出的「移除 P497」全屬 vandalism，已在 Wikidata 全部恢復（含德壽 Q45671126）；QLever 重索引後對帳的移除類差異隨之消失。
- **剩餘小尾巴（暫緩）**：~5 條非合併變更（P497 被改掛他 item，需人工核）；~137 條「一人多 QID」多餘項（去重擇優，需先定規則）；少數 deprecated。移除/vandalism 類一律不自動刪 CBDB。
