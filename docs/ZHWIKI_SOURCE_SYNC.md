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
```

結果寫入 `sync_zhwiki_result_*.csv`（含 http_status / result / operation_id / batch_id）。

## 驗證（只讀 MCP）

```sql
-- 整批筆數
SELECT COUNT(*) FROM BIOG_SOURCE_DATA WHERE c_textid=60795 AND c_notes='<batch note>';
-- 抽驗某人現況
SELECT c_personid, c_pages, c_notes FROM BIOG_SOURCE_DATA WHERE c_textid=60795 AND c_personid IN (...);
```

## 進度紀錄

- **2026-07-12 / batch `zhwiki-r3-67368e91`**：
  - report_zhwiki_changed 99 筆全部完成（95 筆更新到新標題並打 batch note；4 筆 CBDB 本就正確、保留原 note；2 筆遇 429 已重試）。
  - report_zhwiki_new：測試 5 筆已 create（+renote）；**剩 1965 筆待跑**（`--only new --offset 5`）。
  - report_zhwiki_removed 3 筆已手動處理。
