# Changelog

本檔案改為維護近階段的重要變更與產品方向，不再保留完整歷史流水帳。較舊的大型升級請參考 `docs/` 下專門文檔。

## 2026-07

### 修復 operations 表缺索引導致每筆 create 全表掃描（生產穩定性）
- **問題**：所有子資源 create 都會對 operations 做「pending 提案」預檢（`WHERE resource=? AND resource_id IN(?) AND op_type=?`，見 `AbstractPersonSubresourceCreateHandler` / `SourceMutationHandler` / `PostingCreateHandler`），但 operations 僅有 `PRIMARY(id)` 與 `KEY(c_personid)`，`resource`/`resource_id` 無索引 → 每寫一筆就**全表掃描一次** operations（該表隨每次 mutation 持續增長）。批次/並發寫入時大量並發全表掃描飽和 DB、堆積慢查詢、推爆 php-fpm（與 /codes 深分頁那次生產癱瘓同一模式）。
- **修復**：新增 migration 為 operations 補 `(resource, resource_id, op_type)` 複合索引（`2026_07_12_000000_add_resource_index_to_operations_table`），把預檢由全表掃描收斂為索引 seek。
- ⚠ **部署**：operations 表大，`ADD INDEX` 於 MariaDB 10.3 為 ONLINE（不長鎖表）但需建置時間，建議低峰執行。
- 後續可再收斂 `hasPendingCreateProposal` 系列由 `get()->contains()` 改為 `exists()` 語義（另議）。

### 中文維基連結改走 mutation API 增量維護（BIOG_SOURCE_DATA）
- 確立中文/英文維基與 Wikidata 連結在 CBDB prod 存於 `BIOG_SOURCE_DATA`（`c_textid` 60795/68943/68942，`c_pages` 存條目標題），維護一律走 `/api/v2/{mutate,create,delete}` 的 `sources` 資源（`direct`、Bearer PAT、CSRF 豁免，寫入靠 `canWriteDirectly()`），每筆有 `operation_id` 可回滾。
- **`WikiMaintenanceController`（全量刪除重灌）標記為僅限首次導入**，不得用於增量新增／修正；[docs/WIKI_TASK_MANAGEMENT.md](docs/WIKI_TASK_MANAGEMENT.md) 加說明橫幅。
- 批次追溯：direct 模式 `meta.comment` 不落庫，故批號寫入 `c_notes`（`日期 | 操作者 | batch_id`，batch_id = 來源報表內容 hash）。
- 新增技能 [.claude/skills/mutation-api-record-editing.md](.claude/skills/mutation-api-record-editing.md) 與流程文檔 [docs/ZHWIKI_SOURCE_SYNC.md](docs/ZHWIKI_SOURCE_SYNC.md)；執行腳本 `cbdb-dbs/d1_build_*/round3/sync_zhwiki_sources.py`（dry-run 預設、分批、429 退避）。

### `/codes` 排序／篩選功能加登入門檻（僅 React/Inertia 版）
- 背景：一次生產環境癱瘓事後分析發現，`/codes/{TABLE}?sort_by=...` 這類深分頁＋任意欄位排序／前導通配符 filter 查詢先於請求量異常變慢，推擠掉 php-fpm worker 拖垮全站。
- `app/codes/{table_name}`（`CodesController@appShow`）新增 `guardSortFilterRequiresAuth()`：請求帶 `sort_by` 或非空 `filters[...]` 時，未登入導向 `login`（記錄 intended URL）；已登入但未激活（`Auth::user()->isActive()` 為 false）改用 flash 訊息 + `redirect()->back()`（避免被 `login` 路由的 `guest` middleware 攔截）；已登入且已激活不受影響。無 sort/filter 的基礎瀏覽維持公開，不需登入。
- **Blade 版 `codes/{table_name}`（`CodesController@show`）本輪刻意不處理**，維持現況無門檻——若之後把 `codes` migration flag 切回 `old`，需重新評估。
- React 前端（`Codes/Show.tsx`）加對應 UX 提示（排序表頭/套用篩選按鈕在未激活時顯示提示與 disabled 樣式），純體驗加分，非防線。
- 設計、風險取捨、測試計劃詳見 [docs/CODES_SORT_FILTER_AUTH_GATE.md](docs/CODES_SORT_FILTER_AUTH_GATE.md)。

## 2026-06

### React / Inertia 遷移正式上線（已列頁面 flag 全翻 new；機制 `default` 仍 old）
- 全站可遷移的互動頁面 feature flag 由 `old` 翻為 `new`（`config/migration_flags.php`）：人物列表/檢視/詳情中樞、**13 個 React 編輯器**（basic-info + 12 個複合主鍵子資源：altname / addresses / texts / sources / offices / assoc / kinship / events / statuses / entries / possession / socialinst）、Codes CRUD、operations / manage / crowdsourcing、admin 日誌與批次工具、認證頁 / welcome 等，現以 React/Inertia 為**線上預設**。
- 上線採 **gate-before-flip**：每頁先做新舊機器逐項對比（內容/欄位/說明文字/字體/導流/視覺）+ review agent + codex 雙閘，差異清單清空且使用者人工逐頁驗收後，才翻 `new`（見 [docs/REACT_MIGRATION_SIMULATION_TEST_PLAN.md](docs/REACT_MIGRATION_SIMULATION_TEST_PLAN.md) §0）。
- 人物詳情中樞（`/app/basicinformation/{id}`）改用 legacy 風格 PersonBanner + 子資源分頁；重建年號轉換 React 元件（EraTimeField）、CHGIS place-link；補齊版面/互動/必填/改鍵 parity，子資源存檔後導向新記錄 edit 頁供複查（#120）。
- **回退保證**：舊 Blade 視圖與路由**未刪除**，flag-gated 頁面回退只需把對應 flag 改回 `old`（可逆、不需改碼）。例外：Query Playground 無主頁 flag、`/query-playground` 硬導向 React 版，不走 flag 回退。AdminLTE 實體下架（Phase 7）尚未執行，故本階段「下線」指**下線為線上預設、舊版保留供回退**，非移除。
- 清理 legacy-parity 臨時測試組（#68，刪 18 個耦合舊路徑的 M 寫入等價測試）。

### 親屬／社會關係雙向鏡像「行內化」（編輯器內確認閘）
- 互逆鏡像的「單邊補建」與「一對多／多對多人工裁決」由專屬 admin 修復頁搬進一般編輯器的點擊/存檔場景，以「鏡像寫入前確認閘」（409 → 彈窗列出將影響的人物/列 → 確認後落庫）處理；專屬修復頁降級為「暫不公開」。
- 提案核准路徑補上 #66/#70 鍵碰撞/鏡像偵測（與 direct 對等，#77、#82、#117）。全情境對真實資料庫實測通過（見 [docs/RELATIONSHIP_MIRROR_INLINE_DESIGN.md](docs/RELATIONSHIP_MIRROR_INLINE_DESIGN.md) §11.1）。

### 人物搜尋 ü／v 互通（#85）
- CBDB 拼音以 `v` 儲存 `ü`、collation 視 `ü≈u`；查詢端統一規範化 `ü→v`，移除「ü→v 替代」提示，7 個搜尋入口一致。

### 編輯器一致化收尾（#116–#123）
- 全 13 編輯器版面/必填標註/碼欄改鍵/按鈕字號一致化；出處 source 編輯期開放改鍵（#116/#117）。
- i18n：補齊編輯器英文缺漏 key、修年號對話框換行、譯名對齊 CBDB 既有術語（#119）；欄位重排（類型/角色/次序）依使用者建議調整（#118/#121/#122/#123）。

### v2 子資源 mutation 資料安全：雙向鏡像同步 + sentinel 完全幂等
- **雙向鏡像衝突偵測（#66）**：社會關係（ASSOC）／親屬（KIN）改動會同步對面互逆鏡像列；若對面對應欄已被獨立改成不同內容，改為**警告 + 可點連結跳對面 + 強制覆寫（meta.force）**，不再靜默覆寫（409 `errors.mirror_conflict`）。
- **鏡像「疑似匹配」（#70）**：嚴格定位（碼∈合法反向集）落空、但放寬查到對面有「碼漂移（∉ 合法代碼表）」的疑似同關係列時，不再靜默 backfill 補出重複鏡像，改為 409 `errors.mirror_suspected`（候選 PK + 權威反向碼）→ 前端跳對面 + 強制就地收斂。**Option 2 安全**：碼∈合法 code 的列視為他段合法關係**絕不覆寫**，只就地收斂純漂移垃圾列。UPDATE 與 **CREATE（#72）** 兩路徑皆覆蓋；子資源「edit 一條對面不存在的鏡像」改為優雅降級頁（取代硬 404）。
- **sentinel 完全幂等（#71）**：legacy 哨兵 0=Unknown 的碼/FK 欄（c_source 等），`null / '' / -999 / 0 /（CREATE 缺鍵）` 落庫一律為 0、**永不寫 null/''**，達成新舊前端寫入完全一致。修正 possession create 缺 c_source 時 legacy `possessionStoreById` 的 undefined-index（direct 與 proposal-核准兩路徑）。
- 互逆鏡像反向關係碼一律以代碼表權威配對碼（ASSOC_CODES / KINSHIP_CODES）補齊，不再洗成哨兵 0（「未详」）污染對方人物關係。
- 以「M 寫入等價」維度（舊版寫入為 ground truth）系統性回歸；全量 phpunit 1918 綠。

### `/api/v2/persons` 新增 c_created_date / c_modified_date（人物層級修改水位線）
- `/api/v2/persons` 每筆人物新增輸出 `c_created_date`（建檔時間，取自 BIOG_MAIN）與 `c_modified_date`（人物**任何**資訊——本體或子資源——最後修改時間）。
- `/api/v2/persons` 新增 `modified_since` 查詢參數供增量同步（只回傳 `c_modified_date >= modified_since` 的人物，含邊界）；嚴格格式守衛 + 時區正規化，無法辨識則忽略（回全部）；命令 `--since` 共用同套規則。水位線納入建檔時間，確保「只有建檔時間、從未被改」的人物不被漏抓。
- `c_modified_date` 為人物聚合層級水位線，存於新 sidecar 表 `person_change_index`，與 BIOG_MAIN 本表 `c_modified_date`（僅本列語意）分離互不污染。
- 日常由 `AuditLogService::logChange()` 收斂點即時維護（`DB::afterCommit` 交易外 best-effort，失敗由 rebuild 補回）；新增 `php artisan cbdb:rebuild-person-change-index` 供初始全量回填、定期校正、手動刷新（NULL-safe GREATEST upsert、c_personid 範圍分段、named lock、省資源；支援 `--since/--id-from/--id-to/--person/--prune/--chunk/--commit-interval`）。
- ⚠ **部署注意**：migration 只建表不回填，部署後須**手動執行一次** `php artisan cbdb:rebuild-person-change-index`，否則 `c_modified_date` 全為 null。
- 為 audit_log 補 `(table_name, occurred_at, id)` 複合索引以支撐 rebuild 的 keyset 掃描。
- 設計與細節見 [docs/PERSON_CHANGE_INDEX_DESIGN.md](docs/PERSON_CHANGE_INDEX_DESIGN.md)。

### CHGIS 地圖：Place Name 可點擊連結與浮出地圖
- `/basicinformation/{id}/addresses` 與 `/offices` 列表頁的 **Place Name**，對「有有效經緯度」的地點渲染為可點擊連結；無效座標（0,0、單軸為 0、超出底圖範圍、經緯反掉等）維持純文字。
- 點擊浮出以 `chgis_map.mbtiles` 為底圖的 Leaflet 地圖（無邊框、背景變暗模糊、Esc/遮罩/×關閉、手機近全螢幕、`prefers-reduced-motion`），標出該人物所有有效 addresses/offices 地點，當前點置中並以脈動標記突顯。
- 底圖不入版控，部署時由 `php artisan cbdb:fetch-chgis-map` 自 HuggingFace（`cbdb/chgis-map`）下載至 `storage/app/chgis/`；缺檔時亦於首次存取地圖時背景下載並顯示提示。
- 官職地點分組鍵改為 `(c_office_id, c_posting_id)`，前端點位 key 使用 `office:{office_id}:{posting_id}:{addr_id}`，避免 `c_posting_id` 非全域唯一時官名誤配與 key 碰撞。
- lazy 下載加入 `Cache::lock()` 互斥、`ttl > timeout` 與 `started_at` stale 自癒，避免大型底圖下載時永久卡在 `downloading`。
- 座標有效性判定集中於 `App\Support\CoordinateValidator`（設定見 `config/chgis_map.php`）。
- 設計與實作細節見 [docs/CHGIS_MAP_PLACE_LINK.md](docs/CHGIS_MAP_PLACE_LINK.md)。
- 前端新增 `leaflet` npm 依賴與 `resources/js/chgis-map` 入口（不使用 CDN）。

### 繁體中文 / 英文介面切換（i18n Phase 6）
- 全站 Blade 視圖完成繁體中文／英文雙語化（約 91 個檔案、3,450 行字串）。
- Navbar 新增語言切換按鈕（zh-TW ⇄ EN），使用者偏好儲存於 session。
- 系統預設語言維持繁體中文（`zh-TW`）；新增 `SetLocaleMiddleware` 處理 session / cookie / Accept-Language 偏好解析。
- 關鍵翻譯群組：`biogmains`（人物編輯表單）、`admin`、`auth`、`operations`、`person`、`common` 等均已對應 `en` 與 `zh-TW` 翻譯檔。
- 測試基礎設施：`tests/TestCase::setUp()` 覆寫 `HTTP_ACCEPT_LANGUAGE` 為 `zh-TW`，避免 Symfony 預設英文標頭干擾 CI。

## 2026-03

### Query Playground / Historical QA
- React/Inertia 版 Query Playground 已成為主要入口：`/app/query-playground`。
- 持續收斂自然語言問答、SQL Playground、QBE 設計器與共用後端接口。
- SSE 穩定性改善：
  - 補上 keep-alive comment 與 padding，降低代理與瀏覽器緩衝影響。
  - LLM 等待、重試與工具執行階段皆可送出 heartbeat。
  - 客戶端中斷連線後，可在更多執行階段提早停止。
- `WITH RECURSIVE` 查詢現已通過 Query Playground 與 MCP 唯讀 SQL allowlist 檢查。
- `SqlTableNameExtractor` 補強 fallback 與回歸測試，涵蓋：
  - recursive CTE
  - 逗號分隔 `FROM` 子句
  - comments / string literals
  - CTE alias 過濾

### Person Browser
- 12 個 tab 元件改用穩定複合主鍵作為 React key，不再使用陣列下標。
- `stableKey()` 改為 `JSON.stringify(pk)`，避免分隔符、`null`、空字串造成碰撞。
- `PersonBrowser` 的 `pk` 結構與 `CompositePrimaryKey::SCHEMAS` 之間新增更多回歸測試。

### 複合主鍵與子資源一致性
- 持續整理子資源 `pk`、URL 查詢參數模式與 mutation handler 的一致性。
- ALTNAME_DATA 主流程維持 3-key；舊格式僅保留相容層。
- `POSTED_TO_OFFICE_DATA` / `POSTED_TO_ADDR_DATA` 的主鍵、resource_id 與 operation log 行為持續收斂。

## 2026-02

### SQL / QBE / Schema 查詢
- Query Playground 新增 Query by Example（QBE）設計器。
- 新增 `query-playground/schema` API，供前端動態載入白名單資料表欄位資訊。
- 年號、地址與其他查詢 UI 的過濾與排序體驗持續改善。

### 資料與同步
- SQLite 匯出與每週同步流程持續穩定化。
- 多筆 migration 補強 MariaDB / SQLite 相容性。

## 2025-12

### 平台升級
- Laravel 升級至 12.x。
- PHP 最低需求提升至 8.2+。
- 前端完成 AdminLTE 3 + Vite 遷移。
- API 認證主線切換至 Sanctum。

### 重要功能落地
- Query Playground、自然語言轉 SQL、Historical QA、MCP 唯讀查詢能力落地。
- 多個 Basic Information 子頁面與提案 / 審核流程完成重構與擴充。

## 參考文檔
- [README.md](./README.md)
- [AGENTS.md](./AGENTS.md)
- [docs/UPGRADE.md](./docs/UPGRADE.md)
- [docs/APPROVAL_FLOWS.md](./docs/APPROVAL_FLOWS.md)
- [docs/VIEWS.md](./docs/VIEWS.md)
