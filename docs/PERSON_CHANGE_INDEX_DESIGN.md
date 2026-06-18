# Person Change Index 設計文件

人物層級「建檔／最後修改」水位線,供 `/api/v2/persons` 輸出與下游增量同步使用。

## 背景與需求

下游需要在 `/api/v2/persons` 取得每個人物的:

- `c_created_date`:該人物的建檔時間(BIOG_MAIN 建立時間)。
- `c_modified_date`:該人物**任何**資訊被修改過的最後時間。「任何資訊」涵蓋人物本體(BIOG_MAIN)與其所有子資源(地址、別名、官職、親屬、事件、社會地位、入仕、著作、財產、社會機構、關係等)。

## 關鍵設計決策

### 1. 語意分離:不污染 BIOG_MAIN 既有的 `c_modified_date`

BIOG_MAIN 既有的 `c_modified_date` 語意是「**BIOG_MAIN 這一列本身**被改」。若把子資源的改動灌進這個欄位,會破壞它的語意,且任何依賴它的稽核/邏輯都會被誤導。

因此「人物聚合層級的 last-touched 水位線」是**獨立概念**,存於獨立的 sidecar 索引表,與 BIOG_MAIN 的欄位互不污染。這同時滿足 DDD 最佳實踐(聚合根在任一組成部分變動時更新自身時間戳)與「不污染既有欄位」的要求。

### 2. 儲存:獨立 sidecar 索引表 `person_change_index`

- **表名小寫** `person_change_index`,與 app 層級表(`audit_log`、`operations`)命名一致;CBDB 原生大寫表(`BIOG_MAIN` 等)是 canonical 資料,這張是衍生/反正規化索引,屬 app 層級。
- 不動 BIOG_MAIN 的 schema 與語意。
- `c_last_modified_date` 建索引,順便支援未來 `GET /api/v2/persons?modified_since=...` 的增量同步(下游「想知道誰改過」的真實需求)。
- 它是投影(projection),可隨時由來源資料重建。

Schema:

```
person_change_index
  c_personid            int        PRIMARY KEY      -- 對應 BIOG_MAIN.c_personid
  c_last_modified_date  datetime   nullable, INDEX  -- 人物層級 last-touched 水位線
  c_created_date        datetime   nullable         -- 鏡像 BIOG_MAIN.c_created_date,方便排序/同步
  updated_at            datetime   nullable         -- 本投影列自身的維護時間(除錯用)
```

> **欄位命名注意(review 修正)**:sidecar 欄位名是 `c_last_modified_date`,**不可命名為 `c_modified_date`**——後者是 BIOG_MAIN 既有欄位,命名衝突會破壞「語意分離」這個核心決策。API **輸出時**才把 `c_last_modified_date` 別名為 `c_modified_date`(見「API 變更」)。

> **`c_created_date` 鏡像同步政策(review 修正,原本未定義)**:`c_created_date` 是 BIOG_MAIN 建檔時間的鏡像。
> - **rebuild 命令**:每次都以 `c_created_date = BIOG_MAIN.c_created_date` **覆寫**(權威來源,修正任何漂移)。
> - **即時 upsert(audit_log 路徑)**:僅在 **INSERT(該 person 首次出現)** 時寫入 `c_created_date`;既有列的 `c_created_date` 不在即時路徑更新(人物建檔時間極少變動,且 BIOG_MAIN.c_created_date 一旦改動會由下次 rebuild 校正)。即時 upsert 的 `ON DUPLICATE KEY UPDATE` 子句**只**動 `c_last_modified_date`,不動 `c_created_date`。

相容性:用 Laravel Schema Builder,同時兼容 MariaDB/MySQL 與 SQLite;以 `is_mysql()` / `is_sqlite()` 分支,不寫 SQLite 不支援的語法(`COMMENT` / `ENGINE` / `USING BTREE`)。表名 `person_change_index` 全小寫;與 `BIOG_MAIN`(大寫)join 時一律用 query builder 明確寫出表名(`->join('person_change_index', 'BIOG_MAIN.c_personid', '=', 'person_change_index.c_personid')`),測試 schema 也須以**小寫**建立此表,避免跨 SQLite/MySQL 的表名大小寫差異(見「API 變更」與「風險」)。

### 3. 水位線定義(計算來源)

對每個 `c_personid`,以 **NULL-safe** 的方式取各來源最大值(NULL 視為「無貢獻」而非把結果打成 NULL,見下方警告):

```
c_created_date       = BIOG_MAIN.c_created_date
c_last_modified_date = 取下列各值中「非 NULL 的最大者」:
    MAX(具日期欄來源表的 c_modified_date),
    MAX(具日期欄來源表的 c_created_date),
    MAX(audit_log.occurred_at  其 row 可解析到此 c_personid)
```

- 為何同時納入「來源表自身時間欄」與「audit_log」:
  - **來源表時間欄**涵蓋歷史 CBDB 匯入資料與所有會寫自身時間戳的路徑。
  - **audit_log** 涵蓋 2026-02 之後經 mutation 的變更,**特別是目前子資源 update 路徑沒有回寫自身 `c_modified_date` 的缺口**(見「風險」)。
  - 兩者取「非 NULL 最大值」,使結果對任一缺口都穩健。

> ⚠ **NULL 處理(嚴重)**:**兩個資料庫的多參數 `max`/`GREATEST` 遇 NULL 都會回 NULL**——MySQL/MariaDB `GREATEST(x, NULL) = NULL`;SQLite scalar `max(x, NULL)` 同樣 **= NULL**(已實測 `SELECT max(NULL, '2025-01-01 00:00:00')` 回 NULL,**切勿誤信「SQLite max 忽略 NULL」**——那是「聚合 max(欄位)」的行為,scalar 多參數版不同)。因此**兩庫都**會把水位線打成 NULL,直接裸用 `GREATEST`/`max` 在兩庫皆錯。**所有** upsert(即時與重建)都必須用 NULL-safe 寫法,且不可讓「兩者皆 NULL」以外的情況產生 NULL:
> - MySQL/MariaDB:`c_last_modified_date = IF(VALUES(c_last_modified_date) IS NULL, c_last_modified_date, IF(c_last_modified_date IS NULL, VALUES(c_last_modified_date), GREATEST(c_last_modified_date, VALUES(c_last_modified_date))))`。
> - SQLite:`c_last_modified_date = CASE WHEN excluded.c_last_modified_date IS NULL THEN person_change_index.c_last_modified_date WHEN person_change_index.c_last_modified_date IS NULL THEN excluded.c_last_modified_date ELSE max(person_change_index.c_last_modified_date, excluded.c_last_modified_date) END`(`excluded.` 指涉新值)。
> - 兩庫等價語意:「任一邊為 NULL → 取另一邊;皆有值 → 取較大;皆 NULL → 維持 NULL」。必須補一條跨資料庫測試,斷言含 NULL 中間值時 MySQL 與 SQLite 結果一致,且既有水位線**不被打成 NULL**。

#### 來源表清單(已據實核對 schema:14 張人物相關表**全部**都有 datetime 的 `c_created_date`/`c_modified_date`,皆可走「MAX(自身日期欄)」)

`ALTNAME_DATA, ASSOC_DATA, BIOG_ADDR_DATA, BIOG_INST_DATA, BIOG_MAIN, BIOG_SOURCE_DATA, BIOG_TEXT_DATA, ENTRY_DATA, EVENTS_DATA, KIN_DATA, POSSESSION_DATA, POSTED_TO_ADDR_DATA, POSTED_TO_OFFICE_DATA, STATUS_DATA`

欄位來源拆兩批(都已是 datetime,無 varchar 殘留):
- **12 張**由 `2025_12_09`/`2025_12_22` 兩支 timestamp migration 從原 varchar `YYYYMMDD` 轉為 datetime:`ALTNAME_DATA, ASSOC_DATA, BIOG_ADDR_DATA, BIOG_INST_DATA, BIOG_MAIN, BIOG_TEXT_DATA, ENTRY_DATA, EVENTS_DATA, KIN_DATA, POSSESSION_DATA, POSTED_TO_OFFICE_DATA, STATUS_DATA`。
- **2 張**(`BIOG_SOURCE_DATA`、`POSTED_TO_ADDR_DATA`)baseline 原本沒有日期欄,由 `2025_11_14_000000_michael_restructure_plan_schema_updates.php`(:191、:211)**直接以 `datetime` 新增**(故不在 2025_12 轉換清單,但同樣是 datetime)。

> ⚠ 早期 review 曾誤判這 2 張「沒有日期欄、只能靠 audit_log」(只看了 baseline 與 2025_12,漏看 2025_11_14)。**已更正:這 2 張可正常走 MAX(自身日期欄)。** rebuild 對 `POSTED_TO_ADDR_DATA` 聚合時須 `WHERE c_personid IS NOT NULL`(其 c_personid 為 nullable)。即時路徑對這 2 張的 c_personid 反查仍見「單一寫者」規則。

> 子資源類別 → 表名對照(供 Step 0 盤點,避免實作者自由心證;14 張皆有 datetime 日期欄):別名=ALTNAME_DATA;地址=BIOG_ADDR_DATA;官職=POSTED_TO_OFFICE_DATA(+任職地址 POSTED_TO_ADDR_DATA,c_personid nullable);親屬=KIN_DATA;關係=ASSOC_DATA;事件=EVENTS_DATA;社會地位=STATUS_DATA;入仕=ENTRY_DATA;著作/文本=BIOG_TEXT_DATA;史料來源=BIOG_SOURCE_DATA;財產=POSSESSION_DATA;社會機構=BIOG_INST_DATA;人物本體=BIOG_MAIN。

### 4. 維護機制:單一寫者(audit_log 收斂點)+ 可重建命令

- **日常即時更新**:掛在 **`AuditLogService::logChange()`**(`app/Services/AuditLogService.php:38`),由 `table_name` + `row_pk` / `new_data` / `old_data` 解析 `c_personid`,對 `person_change_index` 做 **NULL-safe GREATEST upsert**(語意同「水位線定義」的 NULL 警告)。
  - **掛載點務必是 `logChange()`,不是 `write()`、也不是任一 handler**(已驗證):`write()` 一律轉呼 `logChange()`;子資源 create/update/delete、BIOG_MAIN 本體 update、**提案核准套用**(direct-workflow 表經 `BiogMainRepository` 各 store/update;其餘表經 `OperationsProposalController::writeAuditLogForApproval()`)最終全部進到 `logChange()`。掛這裡即一網打盡,新增 handler 也不會漏。
  - ⚠ **即時路徑也必須用 NULL-safe GREATEST,不能用 Laravel `upsert()` 的預設覆寫語意**(review 嚴重修正):否則一筆 `occurred_at` 早於現值的即時 mutation(時鐘漂移、或 occurred_at 取事務開始時間)會把水位線**回退**,「單調不回退」宣稱即破功。重建側與即時側共用**同一份** NULL-safe GREATEST 邏輯(集中於 `app/Services/PersonChangeIndexService.php`,命令與 logChange 皆委派之,避免兩處分歧)。
  - **交易外隔離:`DB::afterCommit`(review 修正,真正的隔離)**:`logChange()` 用 `DB::afterCommit()` 把水位線更新延到外層 mutation 交易**提交後**才執行(交易外、獨立語句),再包 `try/catch`(失敗僅記 `Log::warning`)。如此:
    - 若 mutation **回滾**,callback 不執行——不為未持久化的變更跳水位線,語意正確;
    - 若水位線 upsert **自身失敗(含死鎖)**,只影響它自己,**絕不回滾已提交的使用者資料**(這修掉了「同交易內吞例外救不回已被 DB 回滾的交易」的問題);失敗的缺口由 rebuild 命令(權威來源)補回;
    - 不在交易內呼叫時,`afterCommit` 會立即執行 callback。
    - 即時路徑不另做死鎖重試(交易外單句失敗即交給 rebuild 補);命令側才有 `DB::transaction(.., 3)` 重試。
  - **效能(review 修正)**:`PersonChangeIndexService` 註冊為 **container singleton**(`AppServiceProvider`),`tableExists()` **只快取 true**(常駐表確認存在後不再重查;不快取 false 以免長駐 worker 在 migration 前解析造成 stale-false,table 出現後自癒),避免每筆 audit 寫入都查 `information_schema`(批量核准會線性放大)。
  - **`c_personid` 反查規則**(review 嚴重修正——以下三表的 `row_pk` 不含 c_personid):
    - 多數表的 `row_pk` 已含 `c_personid`(`CompositePrimaryKey::SCHEMAS`),直接取用。
    - **`POSTED_TO_OFFICE_DATA`(PK: c_office_id, c_posting_id)、`POSSESSION_DATA`(PK: c_possession_record_id)、`POSTED_TO_ADDR_DATA`(PK: c_addr_id, c_office_id, c_posting_id)** 的 `row_pk` **不含 c_personid**。解析時依序檢查 `row_pk` → `new_data` → `old_data`,取第一個含 `c_personid` 者(rebuild 命令 `RebuildPersonChangeIndex::resolvePersonId()` 即此序):對這三表 `row_pk` 會落空,改由 `new_data`(CREATE/UPDATE)或 `old_data`(**DELETE**,`new_data` 為 null)的**全列快照**取得 c_personid。因 audit_log 的 old/new_data 是全列快照且這三表都有 c_personid 欄,通常不需要再回查來源表;若三者皆無(理論上罕見)則該筆略過。
    - `POSTED_TO_ADDR_DATA` 的 `c_personid` 為 nullable,可能為 NULL;落空時記一筆 warning 並略過該筆水位線更新(不可寫入 NULL c_personid)。
- **完全重建/手動刷新**:獨立 artisan 命令(見下),因為有些歷史記錄不在 audit_log 裡,需要能從來源表完整重算。

## artisan 重建命令

```
php artisan cbdb:rebuild-person-change-index
    [--chunk=2000]            # keyset 分頁每批人數(低配機建議偏小)
    [--commit-interval=5000]  # 每處理多少筆 upsert 提交一次並開新交易
    [--since=DATETIME]        # 只重算這個時間後有變更的人物(部分刷新)
    [--id-from=ID] [--id-to=ID]  # 只重算某段 c_personid(可分段/續跑)
    [--person=ID]             # 只重算單一人物(除錯)
    [--prune]                 # 額外清除孤兒列(BIOG_MAIN 已不存在的 c_personid)
```

用途:刷新與完全生成 `person_change_index`;日常增量由 audit_log 即時維護(Step 4),此命令供初始化全量回填、定期校正、手動刷新。

> 「定期校正」由**部署層的 cron**(deployment scheduler)定時呼叫此命令(例如每日離峰 `--since` 增量校正),不在 `app/Console/Kernel.php::schedule()` 寫死頻率——頻率屬部署/ops 決策,且本專案 `schedule()` 慣例留空。注意:`--since` cron **只能校正 since 之後的漂移**,不能取代「部署後初次全量回填」,也不能取代偶發的「全量校正 / `--prune` 清孤兒」;建議排程仍保留週期性的全量(無 `--since`)校正。

> **audit_log 一輪的查詢形狀(實作對齊索引)**:rebuild 的 audit_log 一輪**逐 `table_name`** 處理,並以 `(occurred_at, id)` keyset 分頁(`WHERE table_name = ? [AND occurred_at >= ?] ORDER BY occurred_at, id`)。索引為 **`(table_name, occurred_at, id)`** 三欄(migration `2026_06_18_000001`),使 table_name 等值 + occurred_at 範圍 + `ORDER BY occurred_at, id` 完全由索引滿足、避免 filesort/temp;**不可用 `chunkById('id')`**——按 PK `id` 排序會使該索引失效、退化成全表掃描。occurred_at 為 NOT NULL,id 為唯一遞增 tie-breaker,keyset 穩定不漏不重。(此查詢形狀討論主要針對 MariaDB 10.3;SQLite 僅測試路徑。)

### 無鎖刷新策略(預設:分批 GREATEST upsert)

手動刷新時資料可能同時被改,**不鎖表**的最佳做法是讓水位線**單調不回退**:

- 逐來源表分批計算各 person 的 `MAX(c_modified_date/c_created_date)`,以 **NULL-safe GREATEST upsert** 進 `person_change_index`(MySQL/MariaDB 用 `IF(...)`、SQLite 用 `CASE WHEN ... END`,完整寫法見「水位線定義」的 NULL 警告;**切勿在 SQLite 裸用 `max(a, b)`——任一為 NULL 即回 NULL**)。
- 每批一個**短交易**;**不使用 `LOCK TABLES`、不開單一巨大交易**。
- **死鎖重試(review 補強)**:分批 upsert 在 MariaDB InnoDB(RR 隔離級)下,INSERT…ON DUPLICATE 對二級索引 `c_last_modified_date` 可能取 next-key/gap lock,與線上並發寫入偶發死鎖。每批包在具 deadlock retry 的 transaction(Laravel `DB::transaction($cb, $attempts)` 或捕捉 SQLSTATE 1213 重跑該批),避免低配機高並發下整批失敗。
- 因為兩側都是 **NULL-safe GREATEST** 合併(且合併時 DB 在該列鎖內讀「當下表內現值」而非命令啟動時的快照):即使某 person 在本命令讀取其來源快照「之後」又被即時 mutation 改新,`GREATEST(線上新值, 重建舊值) = 線上新值`(此處 GREATEST 一律指上述 NULL-safe 變體),**即時更新不會被舊快照覆蓋**;反之亦然。命令與線上流量可並行。
- 命令本身可重入/可續跑(idempotent),中斷後重跑結果一致。

> 為何不用「影子表 + RENAME swap」當預設:swap 會把「重建視窗內落在舊表的即時更新」用舊快照蓋掉,需額外 catch-up(重放 `occurred_at >= 重建起點` 的 audit_log)才能補回,複雜且有時間窗風險。GREATEST upsert 天生免疫此問題。影子表 swap 僅在需要一次性清除大量孤兒列時才有優勢,改以 `--prune` 選項覆蓋該需求(upsert 全量後,`DELETE FROM person_change_index WHERE c_personid NOT IN (SELECT c_personid FROM BIOG_MAIN)`,同樣分批)。

### 低配伺服器的資源節約(CPU / 記憶體都小)

本機 CPU 與記憶體都偏低,重建命令**必須**沿用專案既有命令的省資源慣例(參考 `app/Console/Commands/RebuildNameSearchIndex.php` 與 `RebuildIndexYear.php`):

- **以 `c_personid` 範圍分段為預設(不要 OFFSET、不要對複合主鍵亂用 chunkById)**:多數來源表是**複合主鍵**,**沒有單一遞增欄可供 `chunkById` keyset**;且 `c_personid` 在子資源表非唯一、部分可為 NULL,`chunkById('c_personid')` 會在 chunk 邊界**漏行或重複**。證據:`CompositePrimaryKey::SCHEMAS` 與 baseline schema(`2025_01_01_000000_import_cbdb_schema.php`)——例如 `POSTED_TO_OFFICE_DATA` PK=`(c_office_id, c_posting_id)`、`POSTED_TO_ADDR_DATA` PK=`(c_addr_id, c_office_id, c_posting_id)`(其 `c_personid` 還 nullable)、`POSSESSION_DATA` 才是單鍵 `c_possession_record_id`。因此統一做法:
  - 對每張來源表 `SELECT c_personid, MAX(c_modified_date), MAX(c_created_date) ... WHERE c_personid BETWEEN ? AND ? GROUP BY c_personid`,以 **`c_personid` 範圍**(每段 `--chunk` 個 id)逐段推進(`c_personid` 在各表皆有索引)。`--id-from/--id-to` 直接作用在此範圍。
  - 僅對**確實有單一主鍵**的表(BIOG_MAIN 的 `c_personid`、POSSESSION_DATA 的 `c_possession_record_id`)才可選用 `chunkById(<該單一主鍵>)`;但為一致與省心,**預設一律用 c_personid 範圍分段**。
  - 大表(KIN_DATA、ASSOC_DATA 動輒百萬列)避免「整表一次 GROUP BY」造成 DB 端大臨時表/檔案排序的 CPU/記憶體峰值——用範圍分段把單次聚合規模壓在一個 `--chunk` 內。
- **分批 upsert + 分段提交**:每累積 `--commit-interval`(**以「已處理的 c_personid 筆數」為單位**)就 `commit` 並重開交易。**不開單一巨大交易、不用 `LOCK TABLES`**(同 RebuildIndexYear「逐條規則各自提交,避免單一大 transaction 鎖表」)。
- **關閉查詢日誌**:迴圈前 `DB::connection()->disableQueryLog()`,避免 query log 累積吃光記憶體。
- **主動回收**:每段提交後 `gc_collect_cycles()`;`array_push($buf, ...$rows)` 取代 `array_merge` 以免複製;快取單一 `$timestamp = now()` 不在迴圈內重建物件。
- **逐來源表累進**:14 張來源表**一張一張**處理(每張依上法聚合後 NULL-safe GREATEST upsert 進 `person_change_index`),不把多表 join 成巨大查詢——降低單次查詢峰值,每張處理完即釋放。另對 audit_log 也做一輪(涵蓋「子資源 update 未回寫自身 `c_modified_date`」這個既有缺口)。
- **audit_log 一輪的成本與索引**:audit_log **沒有 `c_personid` 欄位**(它在 `row_pk`/`new_data` 的 JSON 內),所以「每 person 的 `MAX(occurred_at)`」**無法靠索引消除**,必須掃描 + 解析 JSON。為把掃描收斂到「相關表 + 時間窗」並支撐 keyset,已補 `audit_log (table_name, occurred_at, id)` 三欄複合索引(migration `2026_06_18_000001`):rebuild 的 audit_log 一輪**逐 `table_name`** 以 `WHERE table_name = ? [AND occurred_at >= ?] ORDER BY occurred_at, id` keyset 分頁,完全走此索引(避免 filesort),再在 PHP 端解析 JSON 取 c_personid 聚合;`--since` 也靠此時間窗。**注意:`?modified_since=` API 增量同步查的是 `person_change_index.c_last_modified_date` 索引,不掃 audit_log。**
- **防併發**:用 MySQL named lock `GET_LOCK('cbdb:rebuild-person-change-index', 0)` / `RELEASE_LOCK`(同 RebuildIndexYear),避免兩個重建同時跑互相加壓;取不到鎖直接退出。
- **進度與可觀測**:progress bar + 每段印出 `memory_get_usage(true)`,低配機上便於即時發現記憶體異常。
- **可選降載**:提供 `--sleep=ms`(每段提交後 `usleep`)讓出 CPU,避免重建期間影響線上請求(視實測需要再加)。
- **SQLite(測試)分支**:`INSERT ... ON CONFLICT(c_personid) DO UPDATE SET c_last_modified_date = <NULL-safe CASE>`(CASE 寫法見「水位線定義」NULL 警告,**不可裸用 `max(a,b)`**);測試資料量小,不需 named lock。

## API 變更

- `app/Http/Controllers/Api/PersonListController.php@index`(現況只有 `BiogMain::select(['c_personid'])->orderBy(...)->paginate()`,**無 c_created_date、無 join**,故這是新增輸出而非微調):
  - select 明確列出欄位:`BIOG_MAIN.c_personid`、`BIOG_MAIN.c_created_date`(輸出為 `c_created_date`)、`person_change_index.c_last_modified_date as c_modified_date`。
  - ⚠ **別名衝突(review 修正)**:BIOG_MAIN 本身也有 `c_modified_date` 欄位;**select 不可用 `*` 或一併帶出 `BIOG_MAIN.c_modified_date`**,否則與 sidecar 別名的 `c_modified_date` 衝突。只輸出 sidecar 來的那個。
  - 用 query builder 明確 join,表名大小寫照寫:`->leftJoin('person_change_index', 'BIOG_MAIN.c_personid', '=', 'person_change_index.c_personid')`(避免 SQLite/MySQL 表名大小寫差異)。
  - 對沒有 sidecar 列的人物,`c_modified_date` 會是 NULL(部署後須先跑一次 rebuild,見「文件變更/部署」)。
  - 單次 indexed left join,效能可控;讀取端零跨表彙整。
- (可選,建議一併評估)新增 `?modified_since=` 增量同步參數,利用 `c_last_modified_date` 索引。
- 輸出範例:
  ```json
  { "c_personid": 10, "c_created_date": "2007-05-01 00:00:00", "c_modified_date": "2026-03-12 09:21:00" }
  ```

## 文件變更

- `API.md`:
  - 標題 `# API v2（現行版本）` → `# API v2`(v1 / v2 並行,移除「現行版本」字樣;視需要補一句 v1/v2 並行說明)。
  - `GET /api/v2/persons` 輸出補上 `c_created_date` / `c_modified_date` 欄位說明(若做了 `modified_since` 一併寫)。
- `CHANGELOG.md`:記錄本次新增。
- 本設計文件 `docs/PERSON_CHANGE_INDEX_DESIGN.md`。
- ⚠ **部署註記(review 嚴重修正)**:migration **只建表不回填**。部署到任何環境(staging/prod)後**必須手動執行一次** `php artisan cbdb:rebuild-person-change-index` 做初始全量回填,否則 `person_change_index` 全空、API 的 `c_modified_date` 全為 NULL。此步要寫進 README/部署清單與 CHANGELOG。

## 測試計畫

- `tests/Feature/ApiV2PersonListTest.php`:in-memory SQLite schema 補上 BIOG_MAIN 的 `c_created_date` 欄位與**小寫** `person_change_index` 表,驗證輸出含 `c_created_date` / `c_modified_date` 兩欄,且 `c_modified_date` 來自 sidecar(非 BIOG_MAIN 同名欄)。
- 新增回歸測試:
  - BIOG_MAIN update / 子資源 create / update / delete 後,該 person 的 `c_last_modified_date` 正確前進。
  - **非 c_personid 主鍵表的 delete**(如 ASSOC_DATA、POSTED_TO_OFFICE_DATA、POSSESSION_DATA)後水位線仍前進——驗證 DELETE 時從 `old_data` 反查 c_personid 的路徑。
  - **提案核准套用後**水位線也前進(非提交提案時)。註:proposal **delete** 的核准目前未實作(回 501),此案僅測 create/update 提案核准。
  - **單調不回退(雙向)**:① 較舊的重建批次不蓋較新的即時值;② `occurred_at` 早於現值的即時 mutation 不使水位線回退(防線上路徑誤用覆寫語意)。
  - **NULL-safe 跨庫一致**:含 NULL 中間值時,MySQL 與 SQLite 的 upsert 結果一致,且不把已有水位線打成 NULL。
  - rebuild 命令:全量、`--since`、`--id-from/--id-to`(分段續跑)、`--person`、`--prune` 各模式;重入一致性。

## 風險與注意事項

1. **audit_log 覆蓋面(已於 review 驗證 ✓)**:子資源 create/update/delete、BIOG_MAIN 本體 update、提案核准套用(direct-workflow 經 `BiogMainRepository`;其餘經 `OperationsProposalController::writeAuditLogForApproval()`)最終都進 `AuditLogService::write()`→`logChange()`。掛載點固定在 `logChange()` 即全覆蓋。proposal delete 核准目前未實作(501),非繞過路徑。
2. **NULL 處理(嚴重,已於設計修正)**:**兩庫的多參數 `GREATEST`/scalar `max` 遇 NULL 都回 NULL**(SQLite scalar `max(x,NULL)` 也回 NULL,只有聚合 `max(欄位)` 才忽略 NULL)。所有 upsert 必須 NULL-safe(MySQL 用 `IF`、SQLite 用 `CASE`),並補跨庫一致性測試。見「水位線定義」NULL 警告。
3. **`c_personid` 反查(嚴重,已於設計修正)**:`POSTED_TO_OFFICE_DATA`、`POSSESSION_DATA`、`POSTED_TO_ADDR_DATA` 的 `row_pk` **不含 c_personid**,須從 `new_data`/`old_data` 取(DELETE 走 `old_data`),`POSTED_TO_ADDR_DATA` 的 c_personid 可能為 NULL 須略過。見「單一寫者」反查規則。
4. **來源表日期欄(已據 schema 核實)**:14 張人物相關表**全部**都有 datetime 的 `c_created_date`/`c_modified_date`(12 張由 2025_12 轉換、`BIOG_SOURCE_DATA`/`POSTED_TO_ADDR_DATA` 由 `2025_11_14` 直接新增為 datetime),皆可走 MAX(自身日期欄);`POSTED_TO_ADDR_DATA` 聚合時須 `WHERE c_personid IS NOT NULL`。見「來源表清單」。
5. **線上路徑必須 GREATEST(嚴重,已於設計修正)**:即時 upsert 不可用覆寫語意,否則單調性破功。
6. **部署回填(嚴重,已於設計修正)**:部署後須手動跑一次 rebuild,否則 API 全 NULL。見「文件變更/部署註記」。
7. **`c_created_date` 鏡像同步(已於設計修正)**:rebuild 覆寫、即時僅 INSERT 時寫。見 Schema 區塊政策。
8. **子資源 update 不回寫自身 `c_modified_date` 的既有缺口**:故重建公式必須同時納入 audit_log.occurred_at;此缺口是否一併修復,列為可選項(不阻擋本需求)。
9. **時區**:寫入用 `Carbon::now()`;留意 `DB_TIMEZONE` 與 `APP_TIMEZONE` 對齊(數字偏移如 `+08:00`),參見 `ToolsRepository` 註解。
10. **相容性**:migration、upsert、join 同時兼容 MariaDB/MySQL 與 SQLite;語法依驅動分支;表名大小寫用 query builder 明確處理。
11. **死鎖**:分批 upsert 需 deadlock retry。見「無鎖刷新策略」。
12. BIOG_MAIN 既有 `c_modified_date` / `BiogMainMutationHandler::BLOCKED_FIELDS` 機制**不動**。

## 每環節品質閘門(review gate)

本計畫採「小環節推進」。**每完成一個小環節,必須通過兩道 review 閘門才能推進下一個環節**:

### 第一道:review agent(讀代碼 + 讀修改)

派出一組 review agent,讀本環節改動的程式碼與 `git diff`,檢查:正確性 bug、MariaDB/SQLite 相容性、效能與記憶體(低配機)、是否鎖表、語意污染(勿動 BIOG_MAIN 既有 `c_modified_date`)、GREATEST 單調性、`c_personid` 反查正確性、測試是否覆蓋。

**反覆修正,直到 review agent 回報「無嚴重 issues」為止。**

### 第二道:codex 終端命令(非我方 agent)

第一道通過後,呼叫 `codex` CLI(終端命令)再做一次獨立 review。Windows PowerShell 呼叫方式:

```powershell
# 1. 讓 Node.js 走代理(Windows Registry 的代理設定 Node.js 不讀,必須顯式設環境變數)
$env:HTTPS_PROXY = "http://127.0.0.1:7890"; $env:HTTP_PROXY = "http://127.0.0.1:7890"

# 2. 用 Write-Output 以管道傳 prompt(繞過 stdin 等待;否則 codex exec 會卡在等手動輸入)
# 3. codex exec --dangerously-bypass-approvals-and-sandbox:非交互模式 + 跳過沙盒審批
Write-Output "請 review 本環節的改動(git diff 與相關檔案),聚焦嚴重問題:正確性 bug、MariaDB/SQLite 相容性、效能/記憶體、鎖表風險、語意污染、測試缺口。只回報嚴重 issues。" | codex exec --dangerously-bypass-approvals-and-sandbox
```

**依 codex 回報修正,反覆執行直到無嚴重 issues,才推進下一個小環節。**

> 兩道閘門順序固定:先 review agent 收斂,再 codex 收斂。任何一道發現嚴重 issue 都回到修正,不可跳關。

## 實作步驟(供 goal 執行)

> 下列每個 step 視為一個「小環節」,完成後都要走一遍上方「每環節品質閘門」(review agent → codex,各自到無嚴重 issues)再推進下一個 step。

0. **先建立分支**做這件事(例如 `feature/person-change-index`),勿直接在 `develop` 上開發。
1. **前提驗證**(多數已於本文件 review 階段完成,結論見「風險」1–8):實作前再快速複核「14 張來源表的 datetime 日期欄」與「三張無 c_personid 主鍵表的反查來源(含 DELETE old_data)」在當前 schema 仍成立。
2. **migration**:建立**小寫** `person_change_index`(schema 如上),`c_last_modified_date` 建索引;兼容雙資料庫;**只建表不回填**。
3. **artisan 命令** `cbdb:rebuild-person-change-index`:NULL-safe GREATEST upsert + 死鎖重試 + 省資源慣例 + `--chunk/--commit-interval/--since/--id-from/--id-to/--person/--prune`。先用它做初始全量回填。
4. **單一寫者**:在 `AuditLogService::logChange()` 加入 `c_personid` 解析(含三張特殊表與 DELETE old_data)+ **NULL-safe GREATEST** upsert person_change_index(嚴禁覆寫語意)。
5. **API**:`PersonListController@index` 明確 select + leftJoin 輸出 `c_created_date` / `c_modified_date`(避免 BIOG_MAIN.c_modified_date 同名衝突;評估 `?modified_since=`)。
6. **測試**:依「測試計畫」補齊(含雙向單調性、NULL 跨庫一致、非 c_personid 主鍵 delete、`--id-from/--id-to`);跑 `./vendor/bin/phpunit --filter ApiV2PersonList` 及相關 mutation 測試。
7. **文件**:更新 `API.md`(移除「現行版本」)、`CHANGELOG.md`(含部署後須跑 rebuild 的提醒)、README 部署清單。
8. **提交前**:`./vendor/bin/php-cs-fixer fix`;`git diff` 核對;commit message 用繁體中文。
