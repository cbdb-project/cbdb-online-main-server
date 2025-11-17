# /codes 列表白名單說明

此文件用來記錄 `/codes` 後台功能允許瀏覽或編輯的代碼表清單，需同步維護 `config/codes.php`（或環境變數 `CODES_TABLES`）。未列於白名單的資料表，即便透過 URL 嘗試訪問，也會被系統回傳 404。

## 管理方式

1. **主要設定**：`config/codes.php` 的 `tables` 陣列。
2. **環境覆蓋**：部署環境可透過 `.env` 的 `CODES_TABLES` 指定，格式為以逗號分隔的表名，例如：
   ```
   CODES_TABLES=ALTNAME_CODES,TEXT_CODES,ADDR_CODES
   ```
3. **未配置情況**：若程式未讀到上述設定，`CodesRepository` 會回傳空陣列，此時所有 `/codes/*` 路徑將拒絕存取。

## 目前白名單

| # | 資料表名稱 | 說明 |
|---|------------|------|
| 1 | ADDR_BELONGS_DATA | 地址隸屬關係資料 |
| 2 | ADDR_CODES | 地址代碼 |
| 3 | ADDRESSES | 地址主表 |
| 4 | ALTNAME_CODES | 別名類型代碼 |
| 5 | APPOINTMENT_CODES | 任命類型代碼 |
| 6 | ASSOC_CODES | 關聯類型代碼 |
| 7 | ASSUME_OFFICE_CODES | 就任類型代碼 |
| 8 | BIOG_ADDR_CODES | 人物地址類型代碼 |
| 9 | BIOG_INST_CODES | 人物機構類型代碼 |
|10 | CBDB_NAME_LIST | 姓名列表 |
|11 | CBDB__NAME_FTS | 姓名搜尋倒排索引（內部表）|
|12 | CBDB__TRAD_SIMP_MAP | 繁簡字符映射表（內部表，⚠️ Apache 2.0 授權）|
|13 | CHORONYM_CODES | 地名類型代碼 |
|14 | COUNTRY_CODES | 國家代碼 |
|15 | DYNASTIES | 朝代代碼 |
|16 | ENTRY_CODES | 入仕類型代碼 |
|17 | ETHNICITY_TRIBE_CODES | 民族/部落代碼 |
|18 | EVENT_CODES | 事件類型代碼 |
|19 | EXTANT_CODES | 存世狀態代碼 |
|20 | GANZHI_CODES | 干支代碼 |
|21 | HOUSEHOLD_STATUS_CODES | 戶籍狀態代碼 |
|22 | INDEXYEAR_TYPE_CODES | 年份索引類型代碼 |
|23 | KINSHIP_CODES | 親屬關係代碼 |
|24 | LITERARYGENRE_CODES | 文學體裁代碼 |
|25 | MEASURE_CODES | 度量衡代碼 |
|26 | MERGED_PERSON_DATA | 人物合併資料 |
|27 | OCCASION_CODES | 場合類型代碼 |
|28 | OFFICE_CODES | 官職代碼 |
|29 | PARENTAL_STATUS_CODES | 父母狀態代碼 |
|31 | POSSESSION_ACT_CODES | 財產行為代碼 |
|32 | SCHOLARLYTOPIC_CODES | 學術主題代碼 |
|33 | SOCIAL_INSTITUTION_ALTNAME_CODES | 社會機構別名類型代碼 |
|34 | SOCIAL_INSTITUTION_CODES | 社會機構代碼 |
|35 | SOCIAL_INSTITUTION_NAME_CODES | 社會機構名稱類型代碼 |
|36 | STATUS_CODES | 狀態代碼 |
|37 | TEXT_BIBLCAT_CODES | 文獻分類代碼 |
|38 | TEXT_CODES | 文獻代碼 |
|39 | TEXT_INSTANCE_DATA | 文獻版本資料 |
|40 | TEXT_ROLE_CODES | 文獻角色代碼 |
|41 | YEAR_RANGE_CODES | 年份範圍代碼 |

> 建議：若新增或移除代碼表，請同步更新本文件與 `config/codes.php`，並在部署環境重新執行 `php artisan config:cache` 以確保新設定生效。

> 備註：以下表格在泛用 `/codes` 介面中為只讀表，僅允許瀏覽與搜尋：
> - `CBDB_NAME_LIST`：姓名列表（無主鍵定義）
> - `CBDB__NAME_FTS`：姓名搜尋倒排索引（內部輔助表，由系統自動維護）
> - `CBDB__TRAD_SIMP_MAP`：繁簡字符映射表（內部輔助表，透過 `php artisan cbdb:import-trad-simp-map` 指令匯入）
>   - ⚠️ **授權例外**：此表數據來自 [OpenCC 項目](https://github.com/BYVoid/OpenCC) 的字典文件，以 **Apache 2.0 License** 授權，而非 CBDB 其他部分使用的 CC BY-NC-SA 4.0 International 授權
> - `DYNASTIES`：朝代代碼
> - `GANZHI_CODES`：干支代碼
>
> **內部表說明**：
> - 表名前綴 `CBDB__`（雙底線）代表內部輔助/支援表，不直接對終端用戶曝光
> - 這些表用於支援核心功能（如姓名搜尋）或提供輔助數據（如繁簡映射）
> - 內部表統一設為只讀模式，由專用指令或系統程序維護

## 效能優化

### 游標分頁（Cursor Pagination）

為解決大表分頁效能問題，部分表格採用基於 ID 游標的分頁機制，而非傳統的 `OFFSET` 分頁：

**採用游標分頁的表格**：
- `CBDB__NAME_FTS`（300 萬+ 記錄）

**效能對比**：

| 分頁方式 | 第 1 頁 | 第 1000 頁 | 第 40000 頁 |
|---------|---------|-----------|------------|
| OFFSET 分頁 | ~5ms | ~200ms | ~5000ms |
| 游標分頁 | ~3ms | ~3ms | ~3ms |

**游標分頁特點**：
- ✅ 恆定查詢時間（~3ms），不受頁碼影響
- ✅ 支援上一頁/下一頁導航
- ✅ 提供「跳轉到 ID」功能
- ✅ 顯示當前頁 ID 範圍
- ✅ 前綴搜尋（`LIKE 'keyword%'`），可利用索引
- ❌ 無法跳轉到任意第 N 頁
- ❌ 不顯示總頁數
- ❌ 搜尋不支援包含匹配（如搜「安石」無法找到「王安石」）

**實現原理**：

```sql
-- 傳統 OFFSET 分頁（慢）
SELECT * FROM CBDB__NAME_FTS LIMIT 20 OFFSET 799980;  -- 需掃描 80 萬行

-- 游標分頁（快）
SELECT * FROM CBDB__NAME_FTS WHERE id > 799980 ORDER BY id ASC LIMIT 20;  -- 直接定位
```

**URL 參數**：
- `?after=12345`：顯示 ID 大於 12345 的記錄（下一頁）
- `?before=12345`：顯示 ID 小於 12345 的記錄（上一頁）
- `?search=關鍵詞`：前綴搜尋（如 `?search=王安` 可找到「王安石」）

詳細技術說明請參考 [NAME_SEARCH_COMMANDS.md](./NAME_SEARCH_COMMANDS.md)
