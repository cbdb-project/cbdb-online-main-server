# 姓名搜尋系統維護指令

本文件說明姓名搜尋相關的 Artisan 指令，用於維護 `CBDB__TRAD_SIMP_MAP`（繁簡映射表）和 `CBDB__NAME_FTS`（姓名倒排索引表）兩個內部輔助表。

## 目錄

- [繁簡映射表匯入](#繁簡映射表匯入)
- [姓名倒排索引重建](#姓名倒排索引重建)
- [完整工作流程](#完整工作流程)
- [故障排除](#故障排除)

---

## 繁簡映射表匯入

### 指令

```bash
php artisan cbdb:import-trad-simp-map
```

### 用途

從 OpenCC 專案下載並匯入繁體字 ↔ 簡體字映射資料，用於支援簡體字姓名搜尋。

### 參數

| 參數 | 類型 | 預設值 | 說明 |
|------|------|--------|------|
| `--url` | 選填 | OpenCC GitHub | 繁簡對照檔案的 URL |
| `--truncate` | 選填 | false | 匯入前清空現有資料 |
| `--skip-non-bmp` | 選填 | false | 跳過非 BMP 字符（不建議） |
| `--batch` | 選填 | 1000 | 每批插入記錄數 |

### 使用範例

**標準匯入**（推薦）
```bash
php artisan cbdb:import-trad-simp-map --truncate
```

**自訂批次大小**
```bash
php artisan cbdb:import-trad-simp-map --truncate --batch=500
```

**使用自訂字典檔案**
```bash
php artisan cbdb:import-trad-simp-map \
  --url=https://example.com/custom-dict.txt \
  --truncate
```

### 執行輸出

```
Loading dictionary from URL...
Parsing mapping file…
Including non-BMP mapping at line 1 (㑮 -> 𫝈); ensure utf8mb4 is configured end-to-end.
...
Parsed 4113 mappings (skipped 0 invalid, non-BMP seen 1149, skipped 0)
Truncating CBDB__TRAD_SIMP_MAP before import.
Starting batch insert (batch size: 1000)…
Inserted 4113 / 4113 mappings (5 batches)…
Batch insert completed: 4113 mappings in 5 batches.
Imported 4113 mappings into CBDB__TRAD_SIMP_MAP.
```

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

### 映射規則

當一個繁體字對應多個簡體字時（如 `乾	干 乾`），**只保留第一個簡體字**（`干`），其他變體被忽略。這是簡化的處理策略，優先保留最常用的簡體字。

### 技術細節

**資料表結構**
```sql
CREATE TABLE CBDB__TRAD_SIMP_MAP (
    trad_char VARBINARY(4) NOT NULL COMMENT '繁體字（UTF-8二進制）',
    simp_char VARBINARY(4) NOT NULL COMMENT '簡體字（UTF-8二進制）',
    PRIMARY KEY (trad_char)
) ENGINE=InnoDB;
```

**非 BMP 字符支援**
- 使用 `VARBINARY(4)` 繞過 MySQL 8.0 對 utf8mb4 非 BMP 字符主鍵索引的 bug
- 支援 4 字節 UTF-8 字符（如 𫝈、𠌥 等）
- 批量插入提升效能 50-100 倍

### 預期耗時

- **資料量**：約 4,113 個映射
- **耗時**：1-2 秒（批次大小 1000）
- **資料庫大小**：約 100 KB

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

使用 `CBDB__TRAD_SIMP_MAP` 轉換：
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

假設 70 萬人物、220 萬姓名、平均每個姓名 2.5 個後綴：

| 項目 | 數值 |
|------|------|
| 倒排記錄數 | 約 550 萬條 |
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

確保 `CBDB__TRAD_SIMP_MAP` 和 `CBDB__NAME_FTS` 表已建立。

**步驟 2：匯入繁簡映射**
```bash
php artisan cbdb:import-trad-simp-map --truncate
```

預計耗時：1-2 秒

**步驟 3：重建姓名索引**
```bash
php artisan cbdb:rebuild-name-search --truncate
```

預計耗時：10-30 分鐘（依資料量而定）

**步驟 4：驗證結果**
```bash
# 檢查繁簡映射表
php artisan tinker
>>> DB::table('CBDB__TRAD_SIMP_MAP')->count();
=> 4113

# 檢查倒排索引表
>>> DB::table('CBDB__NAME_FTS')->count();
=> 5500000 (示例值)

# 測試搜尋
>>> DB::table('CBDB__NAME_FTS')->where('search_term', 'LIKE', '石%')->limit(5)->get();
```

### 定期維護

**更新繁簡映射**（當 OpenCC 字典更新時）
```bash
php artisan cbdb:import-trad-simp-map --truncate
```

**重建索引**（當人物姓名資料有大量變更時）
```bash
php artisan cbdb:rebuild-name-search --truncate
```

**增量更新**（未來版本將支援）
目前暫不支援增量更新，建議使用 `--truncate` 完整重建。

---

## 故障排除

### 問題 1：繁簡映射匯入失敗

**錯誤訊息**
```
Failed to insert mapping (...): Duplicate entry '?' for key 'PRIMARY'
```

**原因**
- 資料庫連接未使用 utf8mb4 字符集
- Migration 未正確執行

**解決方案**
1. 確認 `config/database.php` 已設定 PDO 選項：
   ```php
   'options' => extension_loaded('pdo_mysql') ? [
       PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'",
   ] : [],
   ```

2. 重新執行 migration：
   ```bash
   php artisan migrate:rollback --step=1
   php artisan migrate
   ```

3. 清除配置快取：
   ```bash
   php artisan config:clear
   ```

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

### 問題 4：繁簡映射表不存在

**錯誤訊息**
```
CBDB__TRAD_SIMP_MAP 表不存在，將跳過繁簡轉換
```

**影響**
- 姓名索引仍會建立
- 但只有繁體版本（`is_simplified=0`）
- 簡體字搜尋無法使用

**解決方案**
```bash
# 先匯入繁簡映射
php artisan cbdb:import-trad-simp-map --truncate

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

**3. 執行匯入**
```bash
php artisan cbdb:import-trad-simp-map \
  --url=https://example.com/custom-dict.txt \
  --truncate
```

### 效能監控

**查詢索引大小**
```sql
SELECT
    table_name,
    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
FROM information_schema.TABLES
WHERE table_schema = DATABASE()
  AND table_name IN ('CBDB__TRAD_SIMP_MAP', 'CBDB__NAME_FTS');
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

## 相關文件

- [NAME_SEARCH_PERFORMANCE_IMPROVEMENT.md](NAME_SEARCH_PERFORMANCE_IMPROVEMENT.md) - 姓名搜尋效能改進方案詳細說明
- [AGENTS.md](AGENTS.md) - 內部表維護章節
- [CODES_TABLES.md](CODES_TABLES.md) - 透過 `/codes` 介面檢視內部表
- [DATABASE.md](DATABASE.md) - 資料庫架構說明

## 授權與貢獻

繁簡映射資料來源於 [OpenCC](https://github.com/BYVoid/OpenCC) 專案，遵循 Apache License 2.0。
