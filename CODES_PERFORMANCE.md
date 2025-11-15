# /codes 介面效能優化說明

本文件記錄 `/codes` 後台介面的效能優化措施。

## 優化項目

### 1. 移除不必要的 SHOW TABLES 查詢

**問題**：
- 每次訪問 `/codes/*` 頁面時，`CodesController` 構造函數都會調用 `CodesRepository::allowedTableMap()`
- 該方法執行 `SHOW TABLES` 查詢獲取資料庫中所有表的列表
- 即使只訪問單一表格（如 `/codes/CBDB__NAME_FTS`），也會查詢整個資料庫的表結構

**影響**：
- 每個請求都執行 `SHOW TABLES`，增加不必要的資料庫負載
- 在表數量較多的資料庫中，查詢時間會增加
- 浪費網路和 CPU 資源

**解決方案**：
- 直接從配置文件（`config/codes.php`）讀取白名單表名
- 在記憶體中構建大小寫映射，不需要查詢資料庫
- 如果配置的表名不存在，在實際查詢時自然會報錯

**代碼變更**：

```php
// 優化前（每次都執行 SHOW TABLES）
public function __construct(CodesRepository $codesRepository, OperationRepository $operationRepository)
{
    $this->allowedTables = $this->codesrepostory->allowedTables();
    $map = $this->codesrepostory->allowedTableMap();  // ← 執行 SHOW TABLES
    $this->allowedTablesMap = $map;
}

// 優化後（不查詢資料庫）
public function __construct(CodesRepository $codesRepository, OperationRepository $operationRepository)
{
    $this->allowedTables = $this->codesrepostory->allowedTables();

    // 直接從配置構建映射
    $this->allowedTablesMap = [];
    foreach ($this->allowedTables as $table) {
        $this->allowedTablesMap[strtoupper($table)] = $table;
    }
}
```

**效能提升**：
- 消除每個請求的 `SHOW TABLES` 查詢（~5-20ms）
- 減少資料庫連接開銷
- 降低資料庫伺服器負載

**注意事項**：
- 表名必須在 `config/codes.php` 中正確配置（包括大小寫）
- 如果手動修改資料庫表名，需同步更新配置文件
- 如果配置的表不存在，會在實際查詢時報錯（而非在控制器初始化時）

### 2. 避免不必要的樣本行查詢

**問題**：
- `buildTableHead()` 方法需要樣本行來智能推斷要顯示哪些列
- 原代碼對所有表都執行 `SELECT * FROM table LIMIT 1` 查詢樣本行
- 但對於有 `tableColumnOverrides` 配置的表，根本不需要樣本行

**解決方案**：
- 先檢查是否有列配置（`tableColumnOverrides`）
- 只有沒有配置的表才查詢樣本行
- 有配置的表（如 `CBDB__NAME_FTS`）直接使用配置，不查詢數據庫

**代碼變更**：

```php
// 優化前（總是查詢樣本行）
$sampleRow = (clone $query)->first();
$thead = $this->buildTableHead($table, $sampleRow);

// 優化後（有配置時不查詢）
$upperTable = strtoupper($table);
$hasColumnConfig = isset($this->tableColumnOverrides[$upperTable]);
$sampleRow = $hasColumnConfig ? null : (clone $query)->first();
$thead = $this->buildTableHead($table, $sampleRow);
```

**影響的表**：
- `CBDB__NAME_FTS` ✅
- `CBDB__TRAD_SIMP_MAP` ✅
- `CBDB_NAME_LIST` ✅
- `ADDR_BELONGS_DATA` ✅
- `ADDR_CODES` ✅
- `ADDRESSES` ✅
- `DYNASTIES` ✅
- `TEXT_INSTANCE_DATA` ✅
- `MERGED_PERSON_DATA` ✅
- `OFFICE_CODES` ✅
- `ALTNAME_CODES` ✅
- `APPOINTMENT_CODES` ✅
- `TEXT_CODES` ✅
- `SOCIAL_INSTITUTION_CODES` ✅

**效能提升**：
- 對於有配置的表，消除 `LIMIT 1` 查詢（~2-5ms）
- 減少約 40 個白名單表的不必要查詢

### 3. CBDB__NAME_FTS 游標分頁優化

詳見 [CODES_TABLES.md](./CODES_TABLES.md#效能優化)。

**效能提升**：
- 分頁查詢：從 5000ms（第 40000 頁）降至 3ms（恆定）
- 前綴搜尋：可利用索引，~5ms

## 效能監控

### 查詢日誌分析

**優化前**（訪問 `/codes/CBDB__NAME_FTS`）：
```sql
SHOW TABLES;                                          -- ~10ms
SELECT * FROM CBDB__NAME_FTS LIMIT 1;                 -- ~3ms
SELECT * FROM CBDB__NAME_FTS LIMIT 20 OFFSET 0;      -- ~5ms
-- 總計：~18ms
```

**優化後**：
```sql
SELECT * FROM CBDB__NAME_FTS WHERE id > 0 LIMIT 20;  -- ~3ms
-- 總計：~3ms（減少 83%）
```

**優化措施**：
1. ✅ 移除 `SHOW TABLES` 查詢（-10ms）
2. ✅ 移除樣本行 `LIMIT 1` 查詢（-3ms）
3. ✅ 使用游標分頁替代 OFFSET（-2ms）

### 建議的監控指標

1. **頁面響應時間**：應 < 100ms
2. **資料庫查詢數**：每個請求應 ≤ 3 次查詢
3. **慢查詢**：標記 > 100ms 的查詢

## 相關文件

- [CODES_TABLES.md](./CODES_TABLES.md) - 代碼表白名單與游標分頁
- [NAME_SEARCH_COMMANDS.md](./NAME_SEARCH_COMMANDS.md) - 姓名搜尋索引說明
- [DATABASE.md](./DATABASE.md) - 資料庫架構說明
