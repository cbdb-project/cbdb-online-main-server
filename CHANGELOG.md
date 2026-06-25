# Changelog

本檔案改為維護近階段的重要變更與產品方向，不再保留完整歷史流水帳。較舊的大型升級請參考 `docs/` 下專門文檔。

## 2026-06

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
