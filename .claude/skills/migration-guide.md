# Laravel Migration 編寫指南

## 何時使用此技能

當你需要創建或修改數據庫結構時，使用此指南編寫兼容性良好的 Laravel Migration。

## 核心原則

### ✅ 要做的事情
- 使用 Laravel Schema Builder
- 使用標準 SQL 語法
- 使用 B-Tree 索引（默認）
- 保持數據庫無關性

### ❌ 避免的事情
- 數據庫專屬功能（ngram parser、專屬插件）
- 供應商特定語法（REGEXP、優化器提示）
- 直接執行原始 SQL（除非必要）
- 修改基線 migration 文件

## 創建新 Migration

### 使用 Artisan 命令

```bash
# 創建新表 migration
php artisan make:migration create_example_table

# 修改現有表 migration
php artisan make:migration add_column_to_example_table --table=example

# 查看 migration 狀態
php artisan migrate:status
```

## Migration 基本模板

### 創建新表

**Laravel 10 推薦格式**（使用匿名類）：

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::create('example', function (Blueprint $table) {
            // ✅ 主鍵
            $table->bigIncrements('id');

            // ✅ 基本字段
            $table->string('name', 255);
            $table->string('email', 255)->unique();
            $table->text('description')->nullable();
            $table->integer('status')->default(0);

            // ✅ 時間戳
            $table->timestamps();

            // ✅ 軟刪除（可選）
            // $table->softDeletes();

            // ✅ 標準索引
            $table->index('name');
            $table->index('status');

            // ✅ 複合索引
            $table->index(['name', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('example');
    }
};
```

**重要變化**（Laravel 8+ 新格式）：
- ✅ 使用 `return new class extends Migration` 代替命名類
- ✅ 方法使用 `: void` 返回類型聲明
- ✅ 文件結尾是 `};` 而不是 `}`

### 修改現有表

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('example', function (Blueprint $table) {
            // ✅ 添加新欄位
            $table->string('phone', 20)->nullable()->after('email');

            // ✅ 添加索引
            $table->index('phone');
        });
    }

    public function down(): void {
        Schema::table('example', function (Blueprint $table) {
            // ✅ 先刪除索引
            $table->dropIndex(['phone']);

            // ✅ 再刪除欄位
            $table->dropColumn('phone');
        });
    }
};
```

## 複合主鍵表的 Migration

**重要**：Laravel Eloquent 官方不支持複合主鍵，使用 Query Builder (`DB::table()`) 操作這些表。

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('altname_data', function (Blueprint $table) {
            // ✅ 定義字段
            $table->integer('c_personid');
            $table->integer('c_sequence');
            $table->string('c_alt_name_chn', 255);
            $table->string('c_alt_name_type_code', 10);
            $table->text('c_notes')->nullable();
            $table->timestamps();

            // ✅ 定義複合主鍵
            $table->primary([
                'c_personid',
                'c_sequence',
                'c_alt_name_chn',
                'c_alt_name_type_code'
            ], 'altname_pk'); // 指定主鍵名稱

            // ✅ 添加索引
            $table->index('c_personid');
            $table->index('c_alt_name_type_code');
        });
    }

    public function down(): void {
        Schema::dropIfExists('altname_data');
    }
};
```

**操作複合主鍵表**：
```php
// ✅ 使用 Query Builder
DB::table('altname_data')
    ->where('c_personid', $personId)
    ->where('c_sequence', $sequence)
    ->update(['c_alt_name_chn' => $name]);

// ❌ 不要為複合主鍵表創建 Eloquent 模型
// Eloquent 的 find()、delete() 等方法無法正確處理複合主鍵
```

## 常用字段類型

### 字符串類型
```php
$table->string('name', 255);           // VARCHAR(255)
$table->text('description');            // TEXT
$table->char('code', 10);              // CHAR(10)
$table->binary('data');                 // BLOB
```

### 數字類型
```php
$table->integer('count');               // INT
$table->bigInteger('big_count');        // BIGINT
$table->tinyInteger('is_active');       // TINYINT
$table->decimal('price', 10, 2);        // DECIMAL(10,2)
$table->float('rating', 8, 2);          // FLOAT
```

### 日期時間類型
```php
$table->timestamp('created_at');        // TIMESTAMP
$table->datetime('published_at');       // DATETIME
$table->date('birth_date');             // DATE
$table->time('start_time');             // TIME
$table->timestamps();                   // created_at, updated_at
```

### JSON 類型（謹慎使用）
```php
// ✅ 基本 JSON 存儲是安全的
$table->json('settings');

// ❌ 避免複雜的 JSON 查詢（不同數據庫語法差異大）
// 如需頻繁查詢 JSON 內部欄位，考慮拆分為獨立列
```

## 索引策略

### B-Tree 索引（推薦）

```php
Schema::table('users', function (Blueprint $table) {
    // ✅ 單列索引
    $table->index('name');

    // ✅ 複合索引（注意順序）
    $table->index(['last_name', 'first_name']);

    // ✅ 唯一索引
    $table->unique('email');

    // ✅ 指定索引名稱
    $table->index('status', 'idx_users_status');
});
```

**複合索引使用規則**：
- 查詢必須包含索引的**前導列**才能使用索引
- 索引 `['last_name', 'first_name']`：
  - ✅ 可用：`WHERE last_name = 'Smith'`
  - ✅ 可用：`WHERE last_name = 'Smith' AND first_name = 'John'`
  - ❌ 不可用：`WHERE first_name = 'John'`（跳過前導列）

### 刪除索引

```php
Schema::table('users', function (Blueprint $table) {
    // 刪除普通索引
    $table->dropIndex(['name']); // 使用列名
    $table->dropIndex('idx_users_status'); // 使用索引名稱

    // 刪除唯一索引
    $table->dropUnique(['email']);
});
```

## 避免的模式

### ❌ 不要：使用數據庫專屬功能

```php
public function up()
{
    // ❌ 壞：MySQL 專屬的 ngram parser
    DB::statement('
        CREATE FULLTEXT INDEX idx_name
        ON table_name(name) WITH PARSER ngram
    ');

    // ❌ 壞：MariaDB 專屬插件
    DB::statement("INSTALL SONAME 'ha_spider'");

    // ❌ 壞：MySQL 特定的 ENGINE
    DB::statement('
        CREATE TABLE logs (
            id INT PRIMARY KEY
        ) ENGINE=ARCHIVE
    ');
}
```

### ❌ 不要：使用數據庫特定函數

```php
public function up()
{
    // ❌ 壞：MySQL 特定的生成列
    DB::statement('
        ALTER TABLE users
        ADD COLUMN full_name VARCHAR(255)
        GENERATED ALWAYS AS (CONCAT(first_name, " ", last_name))
    ');

    // ✅ 好：在應用層處理
    // 使用 Eloquent Accessor 或在查詢時拼接
}
```

### ❌ 不要：依賴複雜的 JSON 查詢

```php
public function up()
{
    // ❌ 壞：不同數據庫的 JSON 查詢語法差異很大
    // MySQL:  JSON_EXTRACT(settings, '$.theme')
    // PostgreSQL: settings->>'theme'

    // ✅ 好：如需頻繁查詢 JSON 內部欄位，拆分為獨立列
    Schema::table('configs', function (Blueprint $table) {
        $table->string('theme', 50)->nullable();
    });
}
```

### ❌ 不要：修改基線 Migration

```php
// ❌ 壞：直接修改已執行的基線 migration
// 文件：2025_01_01_000000_import_cbdb_schema.php

// ✅ 好：創建新的增量 migration
php artisan make:migration add_column_to_example_table
```

## Migration 檢查清單

在執行 `php artisan migrate` 之前，請檢查：

### 基本檢查
- [ ] 是否使用了 Laravel Schema Builder？
- [ ] 是否避免了數據庫專屬語法？
- [ ] 欄位類型是否合適？
- [ ] 是否正確處理了 nullable 和 default 值？

### 索引檢查
- [ ] 索引是否使用標準類型（B-Tree、UNIQUE）？
- [ ] 複合索引的順序是否合理？
- [ ] 是否避免了過多的索引（影響寫入性能）？

### 回滾檢查
- [ ] `down()` 方法是否能正確回滾？
- [ ] 刪除索引時是否在刪除欄位之前？
- [ ] 是否處理了外鍵約束？

### 兼容性檢查
- [ ] 是否在 SQLite 測試環境中測試過？
- [ ] 功能是否在 MySQL 5.7+ 和 MariaDB 10.3+ 上都能運行？
- [ ] 是否避免了 PostgreSQL 不支持的語法？

### 安全檢查
- [ ] 是否避免了修改基線 migration 文件？
- [ ] Migration 是否冪等（可重複執行）？
- [ ] 是否考慮了生產環境的數據遷移？

## 常見場景範例

> **注意**：以下示例為簡化版本，僅展示 `up()` 和 `down()` 方法內部邏輯。完整的 migration 文件應使用 `return new class extends Migration` 格式（參考上方的基本模板）。

### 場景 1：添加可空欄位

```php
public function up(): void {
    Schema::table('users', function (Blueprint $table) {
        // ✅ 新欄位設為 nullable，避免現有數據報錯
        $table->string('phone', 20)->nullable()->after('email');
    });
}

public function down(): void {
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('phone');
    });
}
```

### 場景 2：修改欄位（需要 doctrine/dbal）

```bash
# 安裝依賴
composer require doctrine/dbal
```

```php
public function up(): void {
    Schema::table('users', function (Blueprint $table) {
        // ✅ 修改欄位長度
        $table->string('name', 500)->change();

        // ✅ 修改欄位為 nullable
        $table->string('phone', 20)->nullable()->change();
    });
}

public function down(): void {
    Schema::table('users', function (Blueprint $table) {
        $table->string('name', 255)->change();
        $table->string('phone', 20)->nullable(false)->change();
    });
}
```

### 場景 3：重命名欄位/表

```php
// 重命名欄位
public function up(): void {
    Schema::table('users', function (Blueprint $table) {
        $table->renameColumn('name', 'full_name');
    });
}

// 重命名表
public function up(): void {
    Schema::rename('old_table', 'new_table');
}
```

### 場景 4：添加外鍵（謹慎使用）

```php
public function up(): void {
    Schema::table('posts', function (Blueprint $table) {
        // ✅ 添加外鍵
        $table->unsignedBigInteger('user_id');
        $table->foreign('user_id')
              ->references('id')
              ->on('users')
              ->onDelete('cascade');
    });
}

public function down(): void {
    Schema::table('posts', function (Blueprint $table) {
        // ✅ 先刪除外鍵約束
        $table->dropForeign(['user_id']);
        // ✅ 再刪除欄位
        $table->dropColumn('user_id');
    });
}
```

## 運行 Migration

```bash
# 執行所有待處理的 migration
php artisan migrate

# 查看 migration 狀態
php artisan migrate:status

# 回滾最後一批 migration
php artisan migrate:rollback

# 回滾所有 migration
php artisan migrate:reset

# 回滾並重新運行所有 migration
php artisan migrate:refresh

# 刪除所有表並重新運行 migration（危險！）
php artisan migrate:fresh
```

## 測試 Migration

### 在測試環境測試

```bash
# 1. 使用 SQLite 測試
php artisan migrate --database=sqlite

# 2. 回滾測試
php artisan migrate:rollback --database=sqlite

# 3. 重新運行測試
php artisan migrate --database=sqlite
```

### 在 PHPUnit 測試中

```php
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MigrationTest extends TestCase {
    use RefreshDatabase;

    public function test_migration_creates_table() {
        // RefreshDatabase trait 會自動運行 migration
        $this->assertTrue(
            Schema::hasTable('example')
        );
    }

    public function test_migration_creates_columns() {
        $this->assertTrue(
            Schema::hasColumn('example', 'name')
        );
    }
}
```

## 常見錯誤和解決方案

### 錯誤 1：索引名稱太長

```php
// ❌ 錯誤：自動生成的索引名稱可能太長
$table->index(['very_long_column_name', 'another_long_column']);

// ✅ 解決：手動指定較短的索引名稱
$table->index(
    ['very_long_column_name', 'another_long_column'],
    'idx_short_name'
);
```

### 錯誤 2：刪除欄位前未刪除索引

```php
// ❌ 錯誤順序
public function down() {
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('email'); // 錯誤：email 上有 unique 索引
    });
}

// ✅ 正確順序
public function down() {
    Schema::table('users', function (Blueprint $table) {
        $table->dropUnique(['email']); // 先刪除索引
        $table->dropColumn('email');    // 再刪除欄位
    });
}
```

### 錯誤 3：Migration 順序問題

```bash
# 如果 migration B 依賴 migration A
# 確保 A 的時間戳早於 B

# 正確：
2025_01_01_000001_create_users_table.php
2025_01_01_000002_create_posts_table.php  # 依賴 users

# 錯誤：
2025_01_02_000001_create_posts_table.php  # 依賴 users
2025_01_01_000001_create_users_table.php
```

## 參考資料

- [Laravel 10.x Migrations 文檔](https://laravel.com/docs/10.x/migrations)
- [Laravel Schema Builder 文檔](https://laravel.com/docs/10.x/migrations#tables)
- `DATABASE.md` - 項目數據庫完整指南
- `database-schema.md` - Schema 查詢指南
- `AGENTS.md` - AI 代理開發指南
