# 數據庫完整指南

本文檔整合數據庫結構、兼容性原則和最佳實踐，為開發者提供完整的數據庫使用指南。

## 目錄
- [數據庫環境](#數據庫環境)
- [兼容性原則](#兼容性原則)
- [Schema 管理](#schema-管理)
- [最佳實踐](#最佳實踐)
- [具體場景指南](#具體場景指南)
- [Migration 指南](#migration-指南)
- [測試策略](#測試策略)
- [已知問題](#已知問題)
- [參考資源](#參考資源)

---

## 數據庫環境

### 生產環境
- **數據庫系統**：MariaDB 10.11.14 (Ubuntu 24.04；2026-08-03 於 prod 實測)
- **版本資訊**：`mysql Ver 15.1 Distrib 10.3.39-MariaDB, for debian-linux-gnu (x86_64) using readline 5.2`
- **字符集**：utf8mb4
- **排序規則**：utf8mb4_general_ci
- **時區設定**：目前 database 內部分表格欄位出現 datetime 與 timestamp 混用的情況。為避免日期衝突，統一使用 GMT+8 時區。

### 測試環境
- **CI/CD**：SQLite in-memory (`:memory:`)
- **本地開發**：建議使用 MariaDB 10.3+ 或 MySQL 5.7+

---

## 兼容性原則

### 為什麼要保持數據庫無關性？

1. **未來遷移靈活性**：可能需要遷移到 PostgreSQL、MySQL 8.x 或其他數據庫
2. **開發環境多樣性**：開發者可能使用不同的數據庫版本
3. **CI/CD 環境**：測試環境使用 SQLite in-memory 數據庫
4. **成本考量**：避免綁定特定數據庫供應商的商業功能

### 核心原則

#### ✅ 推薦做法

1. **標準 SQL 優先**
   ```sql
   -- ✅ 好：標準 SQL
   SELECT * FROM users WHERE name LIKE 'John%';

   -- ✅ 好：跨數據庫的索引
   CREATE INDEX idx_name ON users(name);
   ```

2. **使用 Laravel Query Builder**
   ```php
   // ✅ 好：Laravel 抽象層
   DB::table('users')
       ->where('name', 'like', 'John%')
       ->get();
   ```

3. **通用索引類型**
   - **B-Tree 索引**（默認）：所有數據庫都支持
   - **UNIQUE 索引**：標準功能
   - **複合索引**：通用支持

#### ❌ 避免做法

1. **數據庫專屬功能**
   ```sql
   -- ❌ 壞：MySQL 專屬的 ngram parser
   CREATE FULLTEXT INDEX idx_name
   ON table_name(column_name) WITH PARSER ngram;

   -- ❌ 壞：MariaDB 專屬插件
   INSTALL SONAME 'ha_spider';
   ```

2. **供應商特定語法**
   ```sql
   -- ❌ 壞：MySQL 特有的 REGEXP
   SELECT * FROM users WHERE name REGEXP '^[A-Z]';

   -- ✅ 好：使用應用層處理或標準 LIKE
   SELECT * FROM users WHERE name LIKE 'A%' OR name LIKE 'B%';
   ```

3. **全文搜索（需謹慎）**
   - MySQL FULLTEXT 與 PostgreSQL ts_vector 語法不同
   - 未來如需全文搜索，建議使用應用層方案

---

## Schema 管理

### 基線遷移

- **導入時間**：2025 年 10 月
- **Migration 文件**：`database/migrations/2025_01_01_000000_import_cbdb_schema.php`
- **內容**：內嵌 `cbdb_schema.sql` 原始內容
- **安全性**：只執行 `CREATE TABLE IF NOT EXISTS`，不包含破壞性語句
- **回滾**：由於是歷史基線，`down()` 無對應刪除邏輯

### 既有資料處理

**場景 1：資料庫已存在**
```bash
# 只需讓 Laravel 記錄遷移狀態
php artisan migrate
# Migration 會自動跳過已存在的資料表
```

**場景 2：新建資料庫**
```bash
# 初始化空 schema
mysql -u root -p -e "CREATE DATABASE cbdb CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"

# 執行 migration 取得完整表結構
php artisan migrate
```

### 增量更新原則

⚠️ **重要規則**：
- 任何附加的資料表調整請另外建立遷移檔
- **禁止**直接修改基線檔案 `2025_01_01_000000_import_cbdb_schema.php`
- 如需更新基線 schema，建議先匯出新的 SQL，再評估是否以增量方式處理

---

## 最佳實踐

> 💡 **詳細的索引策略、查詢優化和性能調優技巧**，請參考 [database-schema.md skill](./.claude/skills/database-schema.md)。

### 索引策略概述

#### B-Tree 索引（推薦）
- ✅ 所有數據庫都支持
- ✅ 前綴匹配高效（`LIKE 'prefix%'`）
- ✅ 排序和範圍查詢優秀

```php
Schema::table('users', function (Blueprint $table) {
    $table->index('name');                      // 單列索引
    $table->index(['last_name', 'first_name']); // 複合索引
    $table->unique('email');                    // 唯一索引
});
```

#### 全文索引（謹慎使用）
⚠️ 不同數據庫實現差異大，建議使用應用層全文搜索方案（Elasticsearch、Meilisearch）。

### 查詢優化概述

**索引友好的查詢**：
- ✅ 前綴匹配：`WHERE name LIKE 'John%'`
- ✅ 等值查詢：`WHERE name = 'John'`
- ❌ 中間匹配：`WHERE name LIKE '%John%'`（無法使用 B-Tree 索引）

**複合索引規則**：查詢必須包含索引的**前導列**才能有效使用索引。

### ORM 使用策略

#### Eloquent 與複合主鍵限制

**重要原則**：Laravel Eloquent ORM **官方不支持**複合主鍵（composite primary key）。雖然社群有第三方套件提供支援，但會增加維護上的不確定性（套件的長期維護狀態難以保證）。因此需根據表結構選擇合適的操作方式。

```php
// ✅ 好：單一主鍵的表使用 Eloquent
class User extends Model {
    protected $primaryKey = 'id';  // 單一主鍵
}

$user = User::find(1);
$user->update(['name' => 'New Name']);
$user->delete();

// ✅ 好：複合主鍵的表使用 Query Builder
// 例如：ALTNAME_DATA 有複合主鍵 (c_personid, c_sequence, c_alt_name_chn, c_alt_name_type_code)
DB::table('ALTNAME_DATA')
    ->where('c_personid', $personId)
    ->where('c_sequence', $sequence)
    ->update(['c_alt_name_chn' => $name]);

DB::table('ALTNAME_DATA')
    ->where('c_personid', $personId)
    ->where('c_sequence', $sequence)
    ->delete();

// ❌ 避免：為複合主鍵表建立 Eloquent 模型
// Eloquent 官方不支持複合主鍵，會導致以下問題：
// - delete() 方法無法正常運作（僅支援單一主鍵）
// - update() 無法正確檢測變更（getDirty() 判斷失效）
// - find() 等方法無法使用
// 雖然有第三方套件，但會增加維護負擔和不確定性
```

**本專案複合主鍵表示例**：
- `ALTNAME_DATA`：`c_personid + c_sequence + c_alt_name_chn + c_alt_name_type_code`
- `POSTED_TO_ADDR_DATA`：`c_personid + c_posting_id + c_office_id`

**處理副作用（如索引更新）**：
- 不要依賴 Eloquent Observer（Query Builder 不會觸發）
- 在 Repository 或 Service 層手動調用相關服務（如 `NameSearchIndexService`）
- 更加明確且易於測試

---

## 具體場景指南

### 場景 1：複雜查詢優化

**❌ 錯誤方案**：使用數據庫特定的查詢優化器提示
```sql
-- MySQL 特定
SELECT /*+ INDEX(users idx_name) */ * FROM users WHERE name = 'John';
```

**✅ 正確方案**：通過索引設計和查詢結構優化
```sql
-- 確保有適當的索引
CREATE INDEX idx_name ON users(name);

-- 編寫索引友好的查詢
SELECT * FROM users WHERE name = 'John';
```

### 場景 2：JSON 數據存儲

**⚠️ 謹慎使用**：JSON 功能在不同數據庫中差異較大

```php
// ✅ 基本 JSON 存儲是安全的
Schema::create('configs', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->json('settings');  // Laravel 會轉換為合適的類型
});

// ✅ 基本存取
DB::table('configs')->insert([
    'settings' => json_encode(['theme' => 'dark'])
]);

// ❌ 避免複雜的 JSON 查詢函數
// 不同數據庫的 JSON 查詢語法差異很大
// MySQL:  JSON_EXTRACT(settings, '$.theme')
// PostgreSQL: settings->>'theme'
```

**建議**：如需頻繁查詢 JSON 內部欄位，考慮拆分為獨立列。

---

## Migration 指南

> 💡 **詳細的 Migration 編寫指南、模板和檢查清單**，請參考 [migration-guide.md skill](./.claude/skills/migration-guide.md)。

### 核心原則

✅ **要做的事情**：
- 使用 Laravel Schema Builder
- 使用標準 SQL 語法
- 使用 B-Tree 索引（默認）
- `down()` 方法能正確回滾

❌ **避免的事情**：
- 數據庫專屬功能（ngram parser、專屬插件）
- 供應商特定語法（REGEXP、優化器提示）
- 直接執行原始 SQL（除非必要）
- 修改基線 migration 文件

### 快速示例

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('example', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 255);
            $table->timestamps();
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('example');
    }
};
```

### 常用命令

```bash
php artisan make:migration create_example_table  # 創建 migration
php artisan migrate                               # 執行 migration
php artisan migrate:status                        # 查看狀態
php artisan migrate:rollback                      # 回滾
```

---

## 測試策略

> 💡 **詳細的 PHPUnit 測試編寫指南、In-Memory 數據庫測試和最佳實踐**，請參考 [testing-guide.md skill](./.claude/skills/testing-guide.md)。

### 推薦方法：In-Memory SQLite 測試

**核心原則**：
1. ✅ **隔離性**：每個測試使用獨立的 in-memory 數據庫
2. ✅ **最小化**：只創建測試所需的表結構
3. ✅ **快速**：避免依賴完整的 schema migration
4. ✅ **可靠**：不依賴外部數據庫服務

**基本設置**：
```php
protected function setUp(): void {
    parent::setUp();

    // 配置 SQLite in-memory
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite.database', ':memory:');

    // 創建最小化表結構
    Schema::create('users', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('name');
        $table->timestamps();
    });
}
```

**參考範例**：
- `tests/Feature/CodesControllerTest.php`
- `tests/Feature/WikiMaintenanceControllerTest.php`
- `tests/Feature/UserIpLoggingTest.php`

---

## 已知問題

### 問題 1：全文搜索需求

**問題**：需要對中文姓名進行全文搜索
**限制**：MariaDB（10.3／10.11 皆然）不支持 ngram parser
**解決方案**：
1. ✅ **短期**：使用 B-Tree 索引 + 前綴匹配（已測試，性能優秀）
2. **長期**：考慮 Elasticsearch 或 Meilisearch 等搜索服務

### 問題 2：性能優化

**原則**：優先通過應用層優化，而非數據庫特定功能

**推薦方案**：
- 使用 Redis 緩存熱門查詢
- 優化查詢結構和索引設計
- 使用隊列處理耗時操作
- 數據分片和讀寫分離

---

## 參考資源

### 項目 Skills（操作指南）
- [database-schema.md](./.claude/skills/database-schema.md) - 數據庫 Schema 查詢、索引策略、查詢優化
- [migration-guide.md](./.claude/skills/migration-guide.md) - Migration 編寫完整指南
- [testing-guide.md](./.claude/skills/testing-guide.md) - PHPUnit 測試編寫指南
- [pre-commit-checks.md](./.claude/skills/pre-commit-checks.md) - 代碼提交前檢查規範

### 官方文檔
- [Laravel 10.x Database](https://laravel.com/docs/10.x/database)
- [Laravel 10.x Migrations](https://laravel.com/docs/10.x/migrations)
- [Laravel 10.x Eloquent](https://laravel.com/docs/10.x/eloquent)
- [MariaDB Documentation](https://mariadb.com/kb/en/)

### 兼容性指南
- [MariaDB vs MySQL Compatibility](https://mariadb.com/kb/en/mariadb-vs-mysql-compatibility/)
- [PostgreSQL Migration Guide](https://wiki.postgresql.org/wiki/Things_to_find_out_about_when_moving_from_MySQL_to_PostgreSQL)

### 最佳實踐
- [Twelve-Factor App - Backing Services](https://12factor.net/backing-services)
- [Database Refactoring Best Practices](https://www.martinfowler.com/articles/evodb.html)

### 相關項目文檔
- [AGENTS.md](./AGENTS.md) - AI 代理開發指南
- [README.md](./README.md) - 項目總覽

---

## 快速參考

### 常用命令

```bash
# 執行所有 migration
php artisan migrate

# 回滾最後一批 migration
php artisan migrate:rollback

# 查看 migration 狀態
php artisan migrate:status

# 創建新 migration
php artisan make:migration create_example_table
```

### 核心原則速查

✅ **要做**：
- 使用 Laravel Schema Builder
- 使用標準 SQL 語法
- 使用 B-Tree 索引
- 前綴匹配優化搜索

❌ **不要做**：
- 使用數據庫專屬功能（ngram、專屬插件）
- 使用供應商特定語法（REGEXP、優化器提示）
- 依賴複雜的 JSON 查詢
- 修改基線 migration 文件

---

**最後更新**：2025-11-09
**維護者**：CBDB 開發團隊
