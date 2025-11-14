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
- **數據庫系統**：MariaDB 10.3.39 (Debian)
- **版本資訊**：`mysql Ver 15.1 Distrib 10.3.39-MariaDB, for debian-linux-gnu (x86_64) using readline 5.2`
- **字符集**：utf8mb4
- **排序規則**：utf8mb4_general_ci

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

### 索引策略

#### B-Tree 索引（推薦）

```php
Schema::table('users', function (Blueprint $table) {
    // ✅ 單列索引
    $table->index('name');

    // ✅ 複合索引
    $table->index(['last_name', 'first_name']);

    // ✅ 唯一索引
    $table->unique('email');
});
```

**優勢**：
- 所有數據庫都支持
- 前綴匹配高效（`LIKE 'prefix%'`）
- 排序和範圍查詢優秀

#### 全文索引（謹慎使用）

```php
// ⚠️ 謹慎：不同數據庫實現差異大
Schema::table('articles', function (Blueprint $table) {
    // MySQL/MariaDB 特定
    DB::statement('ALTER TABLE articles ADD FULLTEXT(content)');

    // PostgreSQL 需要完全不同的語法
    // DB::statement('CREATE INDEX ... USING GIN(to_tsvector(...))');
});
```

**建議**：未來如需全文搜索，使用應用層方案

### 查詢優化

#### 索引友好的查詢

```php
// ✅ 好：前綴匹配，可使用索引
DB::table('users')
    ->where('name', 'like', 'John%')
    ->get();

// ❌ 壞：中間匹配，無法使用索引
DB::table('users')
    ->where('name', 'like', '%John%')
    ->get();
```

#### 複合索引使用

```php
// 假設有複合索引：['last_name', 'first_name']

// ✅ 好：使用索引前導列
$users = DB::table('users')
    ->where('last_name', 'Smith')
    ->get();

// ✅ 好：使用索引全部列
$users = DB::table('users')
    ->where('last_name', 'Smith')
    ->where('first_name', 'John')
    ->get();

// ❌ 壞：跳過索引前導列，無法使用索引
$users = DB::table('users')
    ->where('first_name', 'John')
    ->get();
```

---

## 具體場景指南

### 場景 1：中文姓名搜索優化

**需求**：加速 CBDB_NAME_LIST 表的姓名搜索

**❌ 錯誤方案**：使用 MySQL ngram 全文索引
```sql
-- 問題：MariaDB 10.3 不支持 ngram parser
ALTER TABLE CBDB_NAME_LIST
ADD FULLTEXT INDEX idx_name_fulltext (name) WITH PARSER ngram;
```

**✅ 正確方案**：使用標準 B-Tree 索引 + 前綴匹配
```php
// 現有的 B-Tree 索引 (idx_name) 已足夠
// 性能測試結果：
// - 單字查詢 "張%"：88ms
// - 2字查詢 "張三%"：3ms ⭐ 極快
// - 全名查詢 "蘇軾%"：2ms ⭐ 極快

// 根據查詢長度選擇策略
if (mb_strlen($query) >= 2) {
    // 2+ 字符：使用前綴匹配（毫秒級）
    $personIds = DB::table('CBDB_NAME_LIST')
        ->where('name', 'like', $query . '%')
        ->distinct()
        ->limit(500)
        ->pluck('c_personid');
} else {
    // 單字查詢：使用 BIOG_MAIN（避免過多結果）
    $results = DB::table('BIOG_MAIN')
        ->where('c_name_chn', 'like', '%' . $query . '%')
        ->limit(20)
        ->get();
}
```

### 場景 2：複雜查詢優化

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

### 場景 3：JSON 數據存儲

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

### 基本模板

```php
<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateExampleTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('example', function (Blueprint $table) {
            // ✅ 使用 Laravel Schema Builder
            $table->bigIncrements('id');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->timestamps();

            // ✅ 標準索引
            $table->index('name');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('example');
    }
}
```

### 避免的模式

```php
public function up()
{
    // ❌ 壞：直接執行數據庫特定 SQL
    DB::statement('
        CREATE FULLTEXT INDEX idx_name
        ON table_name(name) WITH PARSER ngram
    ');

    // ❌ 壞：使用數據庫特定函數
    DB::statement('
        ALTER TABLE users
        ADD COLUMN full_name VARCHAR(255)
        GENERATED ALWAYS AS (CONCAT(first_name, " ", last_name))
    ');

    // ❌ 壞：使用 MySQL 特定的 ENGINE
    DB::statement('
        CREATE TABLE logs (
            id INT PRIMARY KEY
        ) ENGINE=ARCHIVE
    ');
}
```

### Migration 檢查清單

在執行 `php artisan migrate` 之前，請檢查：

- [ ] 是否使用了 Laravel Schema Builder？
- [ ] 是否避免了數據庫專屬語法？
- [ ] 索引是否使用標準類型（B-Tree、UNIQUE）？
- [ ] `down()` 方法是否能正確回滾？
- [ ] 是否在 SQLite 測試環境中測試過？
- [ ] 功能是否在 MySQL 5.7+ 和 MariaDB 10.3+ 上都能運行？

---

## 測試策略

### In-Memory SQLite 測試（推薦）

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class ExampleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 配置 SQLite in-memory
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        // 創建測試所需的最小化表結構
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });

        // 插入測試數據
        DB::table('users')->insert([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }

    public function test_can_query_users()
    {
        $user = DB::table('users')->first();
        $this->assertEquals('Test User', $user->name);
    }
}
```

### 多數據庫兼容性測試

```php
// phpunit.xml 配置
<php>
    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>
</php>

// 如需測試真實數據庫
public function test_works_on_mariadb()
{
    if (env('DB_CONNECTION') !== 'mysql') {
        $this->markTestSkipped('MariaDB test');
    }

    // 測試代碼...
}
```

### 測試原則

1. **隔離性**：每個測試使用獨立的 in-memory 數據庫
2. **最小化**：只創建測試所需的表結構
3. **快速**：避免依賴完整的 schema migration
4. **可靠**：不依賴外部數據庫服務

參考範例：
- `tests/Feature/CodesControllerTest.php`
- `tests/Feature/WikiMaintenanceControllerTest.php`
- `tests/Feature/UserIpLoggingTest.php`

---

## 已知問題

### 問題 1：全文搜索需求

**問題**：需要對中文姓名進行全文搜索
**限制**：MariaDB 10.3 不支持 ngram parser
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

### 問題 3：Laravel 5.5 Cache 清理錯誤

**現象**：測試完成後出現 "Class cache does not exist" 錯誤（exit code 255）
**原因**：Laravel 5.5 deferred service provider 清理機制的框架級問題
**影響**：不影響測試結果
**解決**：CI 配置已設定忽略此錯誤碼；或升級到 Laravel 5.6+

---

## 參考資源

### 官方文檔
- [Laravel 5.5 Database](https://laravel.com/docs/5.5/database)
- [Laravel 5.5 Migrations](https://laravel.com/docs/5.5/migrations)
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
