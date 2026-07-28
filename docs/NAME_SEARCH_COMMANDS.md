# 姓名搜尋系統維護指令

本文件說明姓名搜尋相關的 Artisan 指令。姓名搜尋依賴兩份資料：繁簡對照（vendored 進版控的 OpenCC 原始
字典檔 `third_party/opencc/TSCharacters.txt`）與 `CBDB__NAME_FTS`（姓名倒排索引表，內部輔助表）。

## 目錄

- [繁簡對照資料（vendored）](#繁簡對照資料vendored)
- [姓名倒排索引重建](#姓名倒排索引重建)
- [完整工作流程](#完整工作流程)
- [故障排除](#故障排除)

---

## 繁簡對照資料（vendored）

繁簡對照**不是資料庫表**——原 `CBDB__TRAD_SIMP_MAP` 已於 2026-07 移除，改為原封不動 vendor 進版控的
OpenCC 原始字典檔 `third_party/opencc/TSCharacters.txt`，由 `App\Support\TradSimpMap` 在**讀取當下直接
解析**（行程內快取一次），**刻意不另外產生、提交任何衍生檔案**（例如預先編譯好的 PHP 陣列）。這個決定
的理由：

- 若額外維護一份衍生檔，每次更新 vendored 原始檔後都得記得手動重新產生衍生檔，兩者容易忘記同步；直接
  解析原始檔可以讓「更新 vendored 檔案」變成唯一需要做的事
- 三個消費點（`TradSimpMap` 本身、`NameSearchIndexService`、`RebuildNameSearchIndex`）都只是「解析一次、
  整表讀進 PHP 關聯陣列」，解析成本可忽略（幾千行純文字），DB 索引查詢能力完全用不上
- 更新後用 `git diff` 就能直接看到 OpenCC 上游實際變化了什麼字，比對比資料庫兩個時間點的快照更直接
- 不再需要後台管理員按鈕即時觸發「下載並寫入資料庫」——這類操作在容器化／不可變部署下通常不被允許

### 更新 vendored 檔案

```bash
php artisan cbdb:sync-opencc-trad-simp
```

**這是開發環境／CI 執行的操作，不在生產環境執行**。這個指令**只下載並覆蓋 `TSCharacters.txt` 這一件
事**，不解析、不產生任何衍生檔——覆蓋後直接 `git diff third_party/opencc/TSCharacters.txt` 審查變化，
提交後隨一般部署流程上線，下次任何程式碼讀取都會自動反映新內容，不需要額外的「重新產生」步驟。

### 參數

| 參數 | 類型 | 預設值 | 說明 |
|------|------|--------|------|
| `--url` | 選填 | OpenCC GitHub | 來源檔案的 URL |
| `--output` | 選填 | `third_party/opencc/TSCharacters.txt` | 輸出檔案路徑（測試用） |

### 使用範例

**標準更新**
```bash
php artisan cbdb:sync-opencc-trad-simp
```

**使用自訂字典檔案**
```bash
php artisan cbdb:sync-opencc-trad-simp --url=https://example.com/custom-dict.txt
```

### 執行輸出

```
Downloading https://raw.githubusercontent.com/... ...
Wrote /path/to/third_party/opencc/TSCharacters.txt (104516 bytes). Parses to 3222 trad->simp mappings (identity mappings excluded).
請用 git diff 審查變化並提交，不需要任何額外的「重新產生」步驟。
```

指令內部會用 `App\Support\TradSimpMap::parseFile()` 驗證寫入後的檔案至少能解析出映射，解析不到任何
結果時視為來源格式有誤，不會留下無效檔案（回傳非 0 exit code，但已寫入的內容仍在——請用 `git diff`／
`git checkout` 決定是否還原）。

### 資料來源

- **預設來源**：OpenCC 專案的 `TSCharacters.txt`
- **URL**：https://raw.githubusercontent.com/BYVoid/OpenCC/refs/heads/master/data/dictionary/TSCharacters.txt
- **格式**：每行一個映射，以 tab 分隔
- **範例**：
  ```
  乾	干 乾
  儘	尽 侭
  於	于 於
  ```

### 解析規則（App\Support\TradSimpMap）

- 當一個繁體字對應多個簡體字時（如 `乾	干 乾`），**只保留第一個簡體字**（`干`），其他變體被忽略。
- **同形映射（trad === simp）一律排除**：OpenCC 對「罕見簡化字可能無對應字型（tofu risk）」的字會保留
  原字作為第一候選，或對某些字給出「原字本身也是合法簡體」的候選（如 `沈	沈 沉`）。這類映射對繁簡轉換
  無實際作用（未命中時本就 fallback 回原字），解析時一律排除。

### 人工補充映射

OpenCC 未收錄、但人名資料中常見的異體/訛寫字（例如 `栢`→`柏`），獨立存放在
`config/trad_simp_manual_overrides.php`，由 `App\Support\TradSimpManualOverrides` 讀取，經
`App\Support\TradSimpMap::full()` 疊加套用在 vendored 基礎資料之上——**不寫入**
`third_party/opencc/TSCharacters.txt`，更新 vendored 檔案完全不影響人工映射。

### 預期耗時

- **資料量**：約 3,222 個映射（排除約 926 個同形映射後）
- **下載耗時**：1-2 秒
- **vendored 檔案大小**：約 100 KB（純文字，git diff 友善）

---

## 姓名倒排索引重建

### 指令

```bash
php artisan cbdb:rebuild-name-search
```

### 用途

重建姓名搜尋倒排索引表，支援高效能的中文姓名後綴搜尋（如搜尋「石」可找到「王安石」）。

### 參數

| 參數 | 類型 | 預設值 | 說明 |
|------|------|--------|------|
| `--truncate` | 選填 | false | 重建前清空現有索引 |
| `--batch` | 選填 | 500 | 每批插入記錄數 |
| `--commit-interval` | 選填 | 5000 | 每個事務提交的記錄數 |
| `--id-from` | 選填 | null | 起始 c_personid（包含） |
| `--id-to` | 選填 | null | 結束 c_personid（包含） |

### 使用範例

**完整重建**（推薦）
```bash
php artisan cbdb:rebuild-name-search --truncate
```

**增量重建**（保留現有資料）
```bash
php artisan cbdb:rebuild-name-search
```

**調整事務大小**（伺服器記憶體較小時）
```bash
php artisan cbdb:rebuild-name-search --truncate --commit-interval=2000
```

**大記憶體伺服器優化**
```bash
php artisan cbdb:rebuild-name-search --truncate --commit-interval=20000
```

**自訂批次大小**
```bash
php artisan cbdb:rebuild-name-search --truncate --batch=1000
```

**分批處理**（避免記憶體累積，可並行處理）
```bash
# 處理 ID 1-100000
php artisan cbdb:rebuild-name-search --id-from=1 --id-to=100000

# 處理 ID 100001-200000
php artisan cbdb:rebuild-name-search --id-from=100001 --id-to=200000

# 處理 ID 200001 以後
php artisan cbdb:rebuild-name-search --id-from=200001
```

**並行處理**（多終端機同時執行，加速重建）
```bash
# 終端機 1
php artisan cbdb:rebuild-name-search --id-from=1 --id-to=100000 &

# 終端機 2
php artisan cbdb:rebuild-name-search --id-from=100001 --id-to=200000 &

# 終端機 3
php artisan cbdb:rebuild-name-search --id-from=200001 &
```

### 執行輸出

```
開始重建姓名搜尋倒排索引...
載入繁簡映射表...
載入 4113 個繁簡映射
收集姓名資料...
  從 BIOG_MAIN 收集本名...
  從 ALTNAME_DATA 收集別名...
收集到 2,234,567 個姓名
生成倒排索引...
 2234567/2234567 [============================] 100%
生成 5,500,000 條倒排記錄
批量插入（批次大小：500）...
 11000/11000 [============================] 100%
成功插入 5,500,000 條記錄
索引重建完成！

=== 索引統計 ===
+--------+------+------------+
| 類型   | 字體 | 記錄數     |
+--------+------+------------+
| 本名   | 繁體 | 2,100,000  |
| 本名   | 簡體 | 1,800,000  |
| 字     | 繁體 | 800,000    |
| 字     | 簡體 | 600,000    |
| 號     | 繁體 | 200,000    |
+--------+------+------------+
總計：5,500,000 條倒排記錄
涵蓋：700,000 個人物
```

### 資料來源

**1. 本名（BIOG_MAIN）**
- 來源欄位：`c_name_chn`
- 類型標記：`name_type_code = NULL`
- 描述：`name_type_desc_chn = '本名'`

**2. 別名（ALTNAME_DATA）**
- 來源欄位：`c_alt_name_chn`
- 類型標記：`name_type_code` 對應 `ALTNAME_CODES.c_name_type_code`
- 常見類型：
  - `4` = 字
  - `5` = 號
  - 其他依 `ALTNAME_CODES` 定義

### 索引生成邏輯

**1. 名字規範化**

移除末尾括號註釋：
```
王安石(Wang Anshi)  → 王安石
王安石（王介甫）    → 王安石
蘇軾(Su Shi)       → 蘇軾
```

**2. 後綴生成**

為每個姓名生成所有可能的後綴：
```
王安石  → [王安石, 安石, 石]
蘇軾    → [蘇軾, 軾]
司馬相如 → [司馬相如, 相如, 如]
```

**3. 繁簡雙版本**

使用 `App\Support\TradSimpMap::full()` 轉換：
```
繁體：王安石 → [王安石, 安石, 石] (is_simplified=0)
簡體：王安石 → [王安石, 安石, 石] (is_simplified=1)
```

如果轉換後與原文相同則跳過簡體版本。

**4. 過濾規則**

排除無效搜尋詞：
- 空白字串
- 以括號開頭的詞：`(`, `)`, `（`, `）`

**5. 去重邏輯**

按以下鍵值去重：
- `c_personid` + `name_type_code` + `full_name`

同一人物的相同姓名（來源不同）只保留一筆。

### 資料表結構

```sql
CREATE TABLE CBDB__NAME_FTS (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    c_personid INT NOT NULL,
    name_type_code SMALLINT UNSIGNED NULL,
    name_type_desc VARCHAR(32) NOT NULL,
    name_type_desc_chn VARCHAR(32) NOT NULL,
    search_term VARCHAR(100) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    source VARCHAR(32) NOT NULL,
    source_key VARCHAR(255) NULL,
    is_simplified TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    INDEX idx_cbdb_name_search_term (search_term, c_personid),
    INDEX idx_cbdb_name_person (c_personid),
    INDEX idx_cbdb_name_type (name_type_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 搜尋範例

**索引前（慢查詢）**
```sql
-- 全表掃描，1500ms
SELECT * FROM BIOG_MAIN
WHERE c_name_chn LIKE '%石%';
```

**索引後（快速查詢）**
```sql
-- 使用索引，3-5ms (提升 300-500 倍)
SELECT DISTINCT c_personid, full_name
FROM CBDB__NAME_FTS
WHERE search_term LIKE '石%'
ORDER BY LENGTH(search_term) ASC;
```

### 預期耗時與資源

假設 70 萬人物、100 萬姓名、平均每個姓名 3 個後綴：

| 項目 | 數值 |
|------|------|
| 倒排記錄數 | 約 300 萬條 |
| 執行時間 | 10-30 分鐘 |
| 記憶體需求 | 512 MB - 1 GB |
| 資料庫大小 | 350-500 MB |

**效能調校建議**
- 批次大小 500-1000 較佳（預設 500）
- 執行期間資料庫寫入負載較高
- 建議在低峰時段執行

---

## 完整工作流程

### 初次設定

**步驟 1：執行 Migration**
```bash
php artisan migrate
```

確保 `CBDB__NAME_FTS` 表已建立。繁簡對照資料（`third_party/opencc/TSCharacters.txt`）已隨程式碼 vendored
提交，不需要額外的 migration 或匯入步驟。

**步驟 2：重建姓名索引**
```bash
php artisan cbdb:rebuild-name-search --truncate
```

預計耗時：10-30 分鐘（依資料量而定）

**步驟 3：驗證結果**
```bash
php artisan tinker

# 檢查倒排索引表
>>> DB::table('CBDB__NAME_FTS')->count();
=> 5500000 (示例值)

# 測試搜尋
>>> DB::table('CBDB__NAME_FTS')->where('search_term', 'LIKE', '石%')->limit(5)->get();

# 檢查繁簡對照資料（不是 DB 表，直接讀資料檔）
>>> count(\App\Support\TradSimpMap::full());
=> 3222 (基礎資料) + 人工補充映射筆數
```

### 定期維護

**更新繁簡對照**（當 OpenCC 字典更新時，本地/CI 執行，需 git 提交）
```bash
php artisan cbdb:sync-opencc-trad-simp
git diff third_party/opencc/TSCharacters.txt
git add third_party/opencc/TSCharacters.txt
git commit -m "..."
# 隨一般部署流程上線，不需要任何額外的「重新產生」步驟
```

**重建索引**（當人物姓名資料有大量變更時）
```bash
php artisan cbdb:rebuild-name-search --truncate
```

**增量更新**（未來版本將支援）
目前暫不支援增量更新，建議使用 `--truncate` 完整重建。

---

## 故障排除

### 問題 1：繁簡對照資料檔重新產生失敗

**錯誤訊息**
```
無法下載 OpenCC 對照檔。
```

**原因**
- 目標主機無法連線到 GitHub（例如公司網路限制）
- `--url` 指向的來源檔案格式不符預期

**解決方案**
1. 確認能連線到 `raw.githubusercontent.com`，或改用 `--url` 指向鏡像/本地檔案（支援 `file://` 路徑）
2. 檢查來源檔案格式：每行一個映射，以 tab 分隔（見上方「資料來源」）

### 問題 2：姓名索引重建記憶體不足

**錯誤訊息**
```
PHP Fatal error: Allowed memory size exhausted
```

**解決方案**

調整批次大小並增加記憶體限制：
```bash
php -d memory_limit=1G artisan cbdb:rebuild-name-search --truncate --batch=200
```

### 問題 3：索引重建速度過慢

**解決方案**

1. **增加批次大小**
   ```bash
   php artisan cbdb:rebuild-name-search --truncate --batch=1000
   ```

2. **暫時停用索引**（進階）
   ```sql
   -- 執行前
   ALTER TABLE CBDB__NAME_FTS DROP INDEX idx_cbdb_name_search_term;
   ALTER TABLE CBDB__NAME_FTS DROP INDEX idx_cbdb_name_person;
   ALTER TABLE CBDB__NAME_FTS DROP INDEX idx_cbdb_name_type;

   -- 執行重建...

   -- 執行後重建索引
   CREATE INDEX idx_cbdb_name_search_term ON CBDB__NAME_FTS(search_term, c_personid);
   CREATE INDEX idx_cbdb_name_person ON CBDB__NAME_FTS(c_personid);
   CREATE INDEX idx_cbdb_name_type ON CBDB__NAME_FTS(name_type_code);
   ```

### 問題 4：繁簡對照 vendored 檔案遺失或損壞

**症狀**
- `third_party/opencc/TSCharacters.txt` 不存在或格式無法解析
- `App\Support\TradSimpMap::baseMap()` 回傳空陣列

**影響**
- 姓名索引仍會建立（`TradSimpMap::full()` 只剩人工補充映射，或完全沒有映射）
- 但繁簡雙寫效果大幅退化，簡體字搜尋可能無法使用

**解決方案**
```bash
# 重新從上游下載，覆蓋 vendored 檔案
php artisan cbdb:sync-opencc-trad-simp

# 確認檔案存在且能正確解析
php artisan tinker
>>> count(\App\Support\TradSimpMap::baseMap());

# 再重建索引
php artisan cbdb:rebuild-name-search --truncate
```

### 問題 5：查看日誌

**查看執行日誌**
```bash
tail -f storage/logs/laravel.log | grep cbdb
```

**查看資料庫查詢日誌**（需要在 `.env` 啟用）
```env
DB_LOG_QUERIES=true
```

---

## 進階主題

### 自訂繁簡字典

如果需要使用自訂的繁簡對照表：

**1. 準備字典檔案**

格式：每行一個映射，tab 分隔
```
繁體字	簡體字
乾	干
於	于
```

**2. 上傳到可存取的 URL**

**3. 執行更新**
```bash
php artisan cbdb:sync-opencc-trad-simp --url=https://example.com/custom-dict.txt
```

### 效能監控

**查詢索引大小**
```sql
SELECT
    table_name,
    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
FROM information_schema.TABLES
WHERE table_schema = DATABASE()
  AND table_name IN ('CBDB__NAME_FTS');
```

**查詢索引統計**
```sql
SELECT
    name_type_desc_chn,
    is_simplified,
    COUNT(*) as count
FROM CBDB__NAME_FTS
GROUP BY name_type_desc_chn, is_simplified
ORDER BY name_type_desc_chn, is_simplified;
```

**查詢搜尋詞分佈**
```sql
SELECT
    LENGTH(search_term) as term_length,
    COUNT(*) as count
FROM CBDB__NAME_FTS
GROUP BY LENGTH(search_term)
ORDER BY term_length;
```

---

## `/codes` 介面檢視

`CBDB__NAME_FTS` 表可透過 `/codes/CBDB__NAME_FTS` 介面檢視，該介面採用**游標分頁**以優化大表查詢效能。

### 游標分頁特性

**URL 參數**：
```
/codes/CBDB__NAME_FTS                    # 首頁
/codes/CBDB__NAME_FTS?after=123456       # 下一頁（ID > 123456）
/codes/CBDB__NAME_FTS?before=123456      # 上一頁（ID < 123456）
/codes/CBDB__NAME_FTS?search=王安        # 前綴搜尋（支援游標分頁）
```

**搜尋特性**：
- 使用**前綴搜尋**（`LIKE '王安%'`）而非包含搜尋（`LIKE '%王安%'`）
- 可利用 B-Tree 索引，搜尋速度快（~5ms）
- 適合搜尋姓名開頭部分（如「王安」可找到「王安石」）
- 搜尋欄位：`search_term`、`full_name`、`name_type_desc_chn` 等

**效能優勢**：

| 操作 | 傳統分頁 | 游標分頁 | 提升 |
|------|---------|---------|------|
| 首頁 | ~5ms | ~3ms | 1.7x |
| 第 1000 頁 | ~200ms | ~3ms | **67x** |
| 第 40000 頁 | ~5000ms | ~3ms | **1667x** |
| 跳轉到指定 ID | N/A | ~3ms | - |

**實現原理**：

```sql
-- 傳統 OFFSET 分頁（隨頁碼增長而變慢）
SELECT * FROM CBDB__NAME_FTS LIMIT 20 OFFSET 799980;
-- 執行計畫：掃描並跳過 799,980 行 → 返回 20 行

-- 游標分頁（恆定速度）
SELECT * FROM CBDB__NAME_FTS WHERE id > 799980 ORDER BY id ASC LIMIT 20;
-- 執行計畫：使用主鍵索引定位 → 直接返回 20 行
```

**界面功能**：
- 上一頁/下一頁按鈕
- 顯示當前頁 ID 範圍（如「ID: 123,456 - 123,475」）
- 「跳轉到 ID」輸入框，可直接定位到特定 ID 附近的記錄
- 每頁顯示記錄數（預設 20 條）

**限制**：
- ❌ 無法跳轉到任意第 N 頁（如「第 100 頁」）
- ❌ 不顯示總頁數
- ✅ 但可透過「跳轉到 ID」功能達到類似效果

詳細技術說明請參考 [CODES_TABLES.md](CODES_TABLES.md#效能優化)。

---

## 相關文件

- [NAME_SEARCH_PERFORMANCE_IMPROVEMENT.md](NAME_SEARCH_PERFORMANCE_IMPROVEMENT.md) - 姓名搜尋效能改進方案詳細說明
- [AGENTS.md](../AGENTS.md) - 內部表維護章節
- [CODES_TABLES.md](CODES_TABLES.md) - 透過 `/codes` 介面檢視內部表（包含游標分頁說明）
- [DATABASE.md](../DATABASE.md) - 資料庫架構說明

## 授權與貢獻

繁簡映射資料來源於 [OpenCC](https://github.com/BYVoid/OpenCC) 專案，遵循 Apache License 2.0。
