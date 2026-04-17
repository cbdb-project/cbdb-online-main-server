---
name: 數據庫表格 Schema 查詢
description: 查詢和維護數據庫表格結構、字段類型、索引策略，包含內部輔助表（CBDB__ 前綴）的管理和繁簡映射表的匯入更新
---

# 數據庫表格 Schema 查詢指南

## 何時使用此技能

當你需要了解數據庫表格的結構（字段、類型、索引等）時，使用此指南。

## 查詢步驟

### 0. 快速瀏覽：`docs/DATABASE_SCHEMA.md`（可選，但推薦先看）

`docs/DATABASE_SCHEMA.md` 由 `php artisan cbdb:generate-schema-docs` 自動生成，包含 MySQL/MariaDB 與 SQLite 兩份彙整後的表結構與索引清單，適合作為第一手總覽參考：

- 想快速確認某欄位是否存在、型別、是否可空
- 想比對 MySQL 與 SQLite 的 Schema 差異
- 想了解目前所有表的索引策略

⚠️ **注意**：此文件是生成產物，可能略為落後於最新 migration。若涉及精確判斷（例如寫 migration、修改欄位型別），請以 migration 檔案為準。

### 1. 查找 Baseline Migration (主要來源)

數據庫表格的基礎結構定義在 **2025-01-01 的 baseline migration** 中：

```bash
# 查找 baseline migration 文件
ls -la database/migrations/2025_01_01_*
```

Baseline migration 文件包含了大部分表格的初始定義，包括：
- 字段名稱和數據類型
- 主鍵和索引
- 外鍵約束
- 默認值

### 2. 檢查後續的表格修改

表格可能在 baseline 之後有增量修改，需要使用 `grep` 確認：

```bash
# 搜索特定表格的後續修改
grep -r "table('TABLE_NAME'" database/migrations/

# 或搜索 Schema 建表語句
grep -r "Schema::create('TABLE_NAME'" database/migrations/
grep -r "Schema::table('TABLE_NAME'" database/migrations/
```

### 3. 使用 artisan tinker 驗證

使用 Laravel 的 tinker 命令可以：
- 讀取實際數據庫記錄
- 驗證 schema 定義是否與實際一致
- 了解數據的實際用法

```bash
# 啟動 tinker
php artisan tinker

# 在 tinker 中執行：
# 查看表格結構
DB::select("DESCRIBE TABLE_NAME");

# 或使用 Schema facade
Schema::getColumnListing('TABLE_NAME');

# 查看示例數據
DB::table('TABLE_NAME')->limit(5)->get();

# 退出 tinker
exit
```

## 複合主鍵的注意事項

根據 `AGENTS.md`，項目中的複合主鍵表格（如 `ALTNAME_DATA`、`POSTED_TO_ADDR_DATA`）：
- **不使用 Eloquent 模型**，僅使用 Query Builder (`DB::table()`)
- 在 migration 中會明確定義複合主鍵
- 需要特別注意主鍵字段的組合

## 內部表格標識

以 `CBDB__` 前綴開頭的表格為內部輔助表，例如：
- `CBDB__NAME_FTS` - 姓名搜索倒排索引，支援高效能後綴匹配查詢
- `CBDB__TRAD_SIMP_MAP` - 繁簡字符映射，基於 OpenCC 標準，使用 VARBINARY(4) 支援非BMP字符

這些表格通常在 `/codes` 頁面中為只讀模式。

## 內部表格維護

### 繁簡映射表（CBDB__TRAD_SIMP_MAP）

使用專用 Artisan 命令從 OpenCC 匯入最新繁簡對照：

```bash
# 匯入繁簡映射（清空舊數據後重新匯入）
php artisan cbdb:import-trad-simp-map --truncate

# 調整批次大小（預設 1000）
php artisan cbdb:import-trad-simp-map --truncate --batch=500
```

**重要說明**：
- `--truncate` 選項會先清空表格再匯入，確保數據最新
- 映射數據基於 [OpenCC](https://github.com/BYVoid/OpenCC) 標準
- 使用 `VARBINARY(4)` 類型以支援非 BMP（Basic Multilingual Plane）字符

### 檢視內部表

可以透過 `/codes` 頁面檢視內部表內容（只讀模式）：

- 訪問 `/codes/CBDB__NAME_FTS` - 查看姓名搜索倒排索引
- 訪問 `/codes/CBDB__TRAD_SIMP_MAP` - 查看繁簡字符映射

**注意**：內部表在 `/codes` 頁面中為只讀模式，僅供查詢不可編輯。

### 项目未用表格

以下表格在當前系統中未使用，僅供特定目的保留：

- **ADDRESSES** - 僅供 CBDB Public API 使用
- **CBDB_NAME_SEARCH** - 僅供舊版 CBDB API 姓名搜索功能使用，其功能現已被 `CBDB__NAME_FTS` 替代

## 索引策略

### B-Tree 索引（推薦）

B-Tree 是所有數據庫都支持的標準索引類型，適用於大多數查詢場景。

**優勢**：
- ✅ 所有數據庫都支持（MySQL、MariaDB、PostgreSQL、SQLite）
- ✅ 前綴匹配高效（`LIKE 'prefix%'`）
- ✅ 排序和範圍查詢優秀
- ✅ 等值查詢性能優異

**創建索引**：
```php
Schema::table('users', function (Blueprint $table) {
    // 單列索引
    $table->index('name');

    // 複合索引
    $table->index(['last_name', 'first_name']);

    // 唯一索引
    $table->unique('email');
});
```

### 複合索引使用規則

**重要原則**：查詢必須包含索引的**前導列**才能使用索引。

假設有複合索引：`['last_name', 'first_name']`

**✅ 可以使用索引的查詢**：
```php
// 使用前導列 last_name
DB::table('users')
    ->where('last_name', 'Smith')
    ->get();

// 使用全部索引列
DB::table('users')
    ->where('last_name', 'Smith')
    ->where('first_name', 'John')
    ->get();
```

**❌ 無法使用索引的查詢**：
```php
// 跳過前導列 last_name，直接使用 first_name
DB::table('users')
    ->where('first_name', 'John')
    ->get();
```

**索引順序設計建議**：
- 將**最常用於篩選**的列放在前面
- 將**選擇性高**（不重複值多）的列放在前面
- 考慮實際查詢模式

### 全文索引（謹慎使用）

**⚠️ 警告**：全文索引在不同數據庫中實現差異很大，不建議使用。

**問題**：
- MySQL/MariaDB 使用 `FULLTEXT` 索引
- PostgreSQL 使用 `GIN` 索引和 `tsvector`
- SQLite 需要 FTS 擴展
- 語法完全不兼容

**建議的替代方案**：
1. **應用層全文搜索**（推薦）：
   - Elasticsearch
   - Meilisearch
   - Algolia

2. **B-Tree 索引 + 前綴匹配**（適用於簡單場景）：
   ```php
   DB::table('users')
       ->where('name', 'like', 'John%')
       ->get();
   ```

3. **自定義倒排索引表**（本專案方案）：
   - `CBDB__NAME_FTS` - 姓名搜索倒排索引
   - 支援高效能後綴匹配查詢

## 查詢優化

### 索引友好的查詢

**✅ 好的查詢（可使用索引）**：
```php
// 前綴匹配 - B-Tree 索引高效
DB::table('users')
    ->where('name', 'like', 'John%')
    ->get();

// 等值查詢 - B-Tree 索引高效
DB::table('users')
    ->where('name', 'John')
    ->get();

// 範圍查詢 - B-Tree 索引高效
DB::table('users')
    ->where('created_at', '>=', '2025-01-01')
    ->get();
```

**❌ 不佳的查詢（無法有效使用索引）**：
```php
// 中間匹配或後綴匹配 - 無法使用 B-Tree 索引
DB::table('users')
    ->where('name', 'like', '%John%')
    ->get();

DB::table('users')
    ->where('name', 'like', '%son')
    ->get();

// 函數處理後的欄位 - 無法使用索引
DB::table('users')
    ->whereRaw('LOWER(name) = ?', ['john'])
    ->get();
```

**優化建議**：
- 如需中間/後綴匹配，考慮全文搜索方案或自定義倒排索引
- 避免在 WHERE 條件中對索引欄位使用函數
- 如需不區分大小寫查詢，考慮添加專門的小寫欄位並建立索引

### 使用 EXPLAIN 分析查詢

**查看查詢執行計劃**：
```php
// 在 tinker 中
php artisan tinker
>>> DB::table('users')->where('name', 'John')->toSql();
>>> DB::select('EXPLAIN ' . DB::table('users')->where('name', 'John')->toSql());
```

**使用專案提供的 SQL 分析工具**：
- 訪問 `/admin/explainsql`（僅限活躍管理員）
- 輸入 SELECT 語句並查看 MySQL `EXPLAIN` 計畫
- 用於調校索引或查詢效能

### 避免 N+1 查詢問題

**❌ 不佳：N+1 查詢**：
```php
$users = User::all(); // 1 次查詢

foreach ($users as $user) {
    echo $user->posts->count(); // N 次查詢
}
```

**✅ 好的：預加載**：
```php
$users = User::with('posts')->get(); // 2 次查詢（users + posts）

foreach ($users as $user) {
    echo $user->posts->count(); // 不產生額外查詢
}
```

### 分頁大數據集

**✅ 使用分頁而非 get()**：
```php
// 好：分頁加載
$users = DB::table('users')
    ->orderBy('id')
    ->paginate(50);

// 不佳：一次加載所有數據
$users = DB::table('users')->get(); // 可能有數百萬條記錄
```

### 選擇必要的欄位

**✅ 只選擇需要的欄位**：
```php
// 好：只選擇需要的欄位
DB::table('users')
    ->select('id', 'name', 'email')
    ->get();

// 不佳：選擇所有欄位（包括大型 TEXT/BLOB 欄位）
DB::table('users')->get();
```

## 性能調優技巧

### 1. 使用查詢緩存（Redis）

```php
use Illuminate\Support\Facades\Cache;

// 緩存查詢結果
$users = Cache::remember('users_active', 3600, function () {
    return DB::table('users')
        ->where('is_active', 1)
        ->get();
});
```

### 2. 批量操作

```php
// ✅ 好：批量插入
DB::table('users')->insert([
    ['name' => 'John', 'email' => 'john@example.com'],
    ['name' => 'Jane', 'email' => 'jane@example.com'],
    // ... 更多記錄
]);

// ❌ 不佳：循環插入
foreach ($data as $item) {
    DB::table('users')->insert($item); // N 次數據庫操作
}
```

### 3. 使用索引提示（謹慎）

**⚠️ 警告**：索引提示是數據庫特定功能，影響可移植性。

```php
// MySQL 特定 - 不推薦
DB::select('SELECT /*+ INDEX(users idx_name) */ * FROM users WHERE name = ?', ['John']);

// ✅ 推薦：優化索引設計和查詢結構
```

### 4. 監控慢查詢

**Laravel 查詢日誌**：
```php
// 在 AppServiceProvider.php 中
use Illuminate\Support\Facades\DB;

public function boot() {
    DB::listen(function ($query) {
        if ($query->time > 1000) { // 超過 1 秒的查詢
            \Log::warning('Slow query', [
                'sql' => $query->sql,
                'bindings' => $query->bindings,
                'time' => $query->time,
            ]);
        }
    });
}
```

## 示例工作流程

```bash
# 1. 查找 baseline migration
ls database/migrations/2025_01_01_*

# 2. 讀取 migration 文件了解表格結構
cat database/migrations/2025_01_01_000000_baseline_schema.php

# 3. 搜索該表格的後續修改
grep -r "POSTED_TO_OFFICE_DATA" database/migrations/

# 4. 使用 tinker 驗證和查看示例數據
php artisan tinker
>>> DB::table('POSTED_TO_OFFICE_DATA')->limit(3)->get();
>>> exit
```

## 參考資料

- `AGENTS.md` - 項目的數據庫使用規範
- `database/migrations/` - 所有數據庫結構定義
- `docs/DATABASE_SCHEMA.md` - 由 `php artisan cbdb:generate-schema-docs` 自動生成的 schema 總覽（MySQL + SQLite）
- `docs/SCHEMA_DOCS_GENERATION.md` - schema 文件生成指令的完整使用說明
