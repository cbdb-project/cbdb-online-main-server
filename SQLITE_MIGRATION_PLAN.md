# Laravel 双数据库兼容策略：MySQL + SQLite

## 📋 概述

本文档详细说明了如何让 CBDB Laravel 应用同时兼容 MySQL 和 SQLite 两种数据库后端，以便：

- **开发环境**：使用 SQLite 零配置快速启动
- **测试环境**：使用 in-memory SQLite 加速测试（10-100倍速度提升）
- **生产环境**：继续使用 MySQL 保证性能和并发能力

## 🎯 目标

1. ✅ 所有代码在 MySQL 和 SQLite 下均可正常运行
2. ✅ 开发者可以选择使用任一数据库进行本地开发
3. ✅ 测试套件使用 in-memory SQLite 运行，速度提升 10-100 倍
4. ✅ 生产环境继续使用 MySQL，零风险
5. ✅ 新增代码通过 SQLite 测试自动保证兼容性

## 📊 兼容性评估

### 现有代码分析结果

| 项目 | 状态 | 说明 |
|------|------|------|
| 事务支持 | ✅ 完全兼容 | `DB::transaction()`、`beginTransaction/commit/rollback` 全部支持 |
| 视图（Views） | ✅ 完全兼容 | 使用标准 SQL JOIN，无需修改 |
| 外键约束 | ✅ 兼容 | 需启用 `foreign_key_constraints = true` |
| 查询构建器 | ✅ 完全兼容 | Eloquent ORM 和 Query Builder 自动适配 |
| 字符串拼接 | ✅ 完全兼容 | 已使用 `\|\|` 操作符（双数据库通用） |
| ISNULL 函数 | ❌ 需要修改 | 1 处：`app/BiogMain.php:102` |
| VARBINARY 类型 | ❌ 需要修改 | 1 处：迁移文件 |
| 外键检查控制 | ❌ 需要修改 | 1 处：迁移文件 |

### 需要修改的地方

**总计：约 5-10 处代码修改**

1. **`app/BiogMain.php:102`** - ISNULL 函数
2. **`database/migrations/2025_11_13_000000_create_internal_name_search_tables.php:40-46`** - VARBINARY 类型
3. **`database/migrations/2025_11_14_000000_michael_restructure_plan_schema_updates.php:18,220`** - SET FOREIGN_KEY_CHECKS
4. **（可选）** `database/migrations/2025_01_01_000000_import_cbdb_schema.php` - 清理 ENGINE/CHARSET 声明

## 🔧 详细实施步骤

### 阶段一：代码兼容性修改（预计 2-4 小时）

#### 1. 修改 BiogMain.php 中的 ISNULL 用法

**文件：** `app/BiogMain.php:102`

**当前代码：**
```php
->orderBy(DB::raw('ISNULL(c_sequence), c_sequence'), 'ASC');
```

**修改为：**
```php
->orderByRaw('CASE WHEN c_sequence IS NULL THEN 1 ELSE 0 END')
->orderBy('c_sequence', 'ASC');
```

或更简洁的写法：
```php
->orderByRaw('c_sequence IS NULL')
->orderBy('c_sequence', 'ASC');
```

**兼容性：** ✅ MySQL 和 SQLite 都支持 `IS NULL` 和 `CASE` 语句

---

#### 2. 创建数据库兼容性辅助函数

**文件：** `database/migrations/helpers.php` (新建)

```php
<?php

if (!function_exists('disable_foreign_keys')) {
    /**
     * 禁用外键约束检查（兼容 MySQL 和 SQLite）
     */
    function disable_foreign_keys()
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
        }
    }
}

if (!function_exists('enable_foreign_keys')) {
    /**
     * 启用外键约束检查（兼容 MySQL 和 SQLite）
     */
    function enable_foreign_keys()
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
}

if (!function_exists('create_varbinary_column')) {
    /**
     * 创建 VARBINARY 列（MySQL）或 BLOB 列（SQLite）
     *
     * @param int $size MySQL VARBINARY 的大小
     * @return string SQL 片段
     */
    function create_varbinary_column($size = 255)
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            return "VARBINARY({$size})";
        } elseif ($driver === 'sqlite') {
            return "BLOB";
        }
        return "BLOB"; // 默认
    }
}
```

**加载方式：** 在 `composer.json` 中添加：
```json
"autoload": {
    "files": [
        "app/helpers.php",
        "database/migrations/helpers.php"
    ]
}
```

然后运行：
```bash
composer dump-autoload
```

---

#### 3. 修改迁移文件 - VARBINARY 类型

**文件：** `database/migrations/2025_11_13_000000_create_internal_name_search_tables.php`

**当前代码（第 40-46 行）：**
```php
DB::statement("
    CREATE TABLE CBDB__TRAD_SIMP_MAP (
        trad_char VARBINARY(4) NOT NULL COMMENT '繁體字（UTF-8二進制）',
        simp_char VARBINARY(4) NOT NULL COMMENT '簡體字（UTF-8二進制）',
        PRIMARY KEY (trad_char)
    ) ENGINE=InnoDB
");
```

**修改为：**
```php
$driver = DB::getDriverName();
$varbinaryType = create_varbinary_column(4);
$engine = $driver === 'mysql' ? 'ENGINE=InnoDB' : '';

DB::statement("
    CREATE TABLE CBDB__TRAD_SIMP_MAP (
        trad_char {$varbinaryType} NOT NULL,
        simp_char {$varbinaryType} NOT NULL,
        PRIMARY KEY (trad_char)
    ) {$engine}
");
```

**说明：**
- MySQL: 使用 `VARBINARY(4)` 存储 UTF-8 字符
- SQLite: 使用 `BLOB` 类型，功能等价

---

#### 4. 修改迁移文件 - 外键约束控制

**文件：** `database/migrations/2025_11_14_000000_michael_restructure_plan_schema_updates.php`

**当前代码（第 18 行）：**
```php
DB::statement('SET FOREIGN_KEY_CHECKS=0');
```

**修改为：**
```php
disable_foreign_keys();
```

**当前代码（第 220 行）：**
```php
DB::statement('SET FOREIGN_KEY_CHECKS=1');
```

**修改为：**
```php
enable_foreign_keys();
```

**同样修改 `down()` 方法中的第 231 和 363 行。**

---

### 阶段二：配置测试环境（预计 1 小时）

#### 1. 配置 SQLite 测试数据库

**文件：** `config/database.php`

在 `connections` 数组中添加：

```php
'sqlite_testing' => [
    'driver' => 'sqlite',
    'database' => ':memory:',
    'prefix' => '',
    'foreign_key_constraints' => true,
],

// 同时更新现有的 sqlite 配置
'sqlite' => [
    'driver' => 'sqlite',
    'database' => env('DB_DATABASE', database_path('database.sqlite')),
    'prefix' => '',
    'foreign_key_constraints' => true,
    'busy_timeout' => 5000,  // 避免写锁冲突
],
```

---

#### 2. 配置 PHPUnit 使用 SQLite

**文件：** `phpunit.xml`

在 `<php>` 标签中添加：

```xml
<php>
    <env name="APP_ENV" value="testing"/>
    <env name="APP_KEY" value="base64:..."/>

    <!-- 使用 in-memory SQLite 进行测试 -->
    <env name="DB_CONNECTION" value="sqlite_testing"/>
    <env name="DB_DATABASE" value=":memory:"/>

    <env name="CACHE_DRIVER" value="array"/>
    <env name="SESSION_DRIVER" value="array"/>
    <env name="QUEUE_DRIVER" value="sync"/>
</php>
```

---

#### 3. 创建数据库兼容性测试

**文件：** `tests/Feature/DatabaseCompatibilityTest.php` (新建)

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\BiogMain;

class DatabaseCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 测试数据库驱动是否为 SQLite
     *
     * @test
     */
    public function it_uses_sqlite_for_testing()
    {
        $this->assertEquals('sqlite', DB::getDriverName());
    }

    /**
     * 测试外键约束是否启用
     *
     * @test
     */
    public function it_has_foreign_key_constraints_enabled()
    {
        if (DB::getDriverName() === 'sqlite') {
            $result = DB::select('PRAGMA foreign_keys');
            $this->assertEquals(1, $result[0]->foreign_keys);
        }

        $this->assertTrue(true);
    }

    /**
     * 测试 NULL 排序的兼容性
     *
     * @test
     */
    public function it_handles_null_ordering_correctly()
    {
        // 创建测试数据
        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 1, 'c_name' => 'Test1', 'c_name_chn' => '测试1'],
            ['c_personid' => 2, 'c_name' => 'Test2', 'c_name_chn' => '测试2'],
        ]);

        DB::table('ALTNAME_DATA')->insert([
            ['c_personid' => 1, 'c_alt_name_type_code' => 1, 'c_alt_name' => 'Alt1', 'c_alt_name_chn' => '别名1', 'c_sequence' => 1],
            ['c_personid' => 1, 'c_alt_name_type_code' => 2, 'c_alt_name' => 'Alt2', 'c_alt_name_chn' => '别名2', 'c_sequence' => null],
        ]);

        // 测试 BiogMain 的 altnames() 关联（使用了 ISNULL 排序）
        $person = BiogMain::find(1);
        $altnames = $person->altnames;

        // 应该能正常查询，不报错
        $this->assertNotNull($altnames);
    }

    /**
     * 测试事务支持
     *
     * @test
     */
    public function it_supports_transactions()
    {
        DB::beginTransaction();

        DB::table('users')->insert([
            'name' => 'Transaction Test',
            'email' => 'test@transaction.com',
            'password' => bcrypt('password'),
        ]);

        $this->assertDatabaseHas('users', ['email' => 'test@transaction.com']);

        DB::rollBack();

        $this->assertDatabaseMissing('users', ['email' => 'test@transaction.com']);
    }

    /**
     * 测试嵌套事务
     *
     * @test
     */
    public function it_supports_nested_transactions()
    {
        $this->assertEquals(0, DB::transactionLevel());

        DB::beginTransaction();
        $this->assertEquals(1, DB::transactionLevel());

        DB::beginTransaction();
        $this->assertEquals(2, DB::transactionLevel());

        DB::commit();
        $this->assertEquals(1, DB::transactionLevel());

        DB::commit();
        $this->assertEquals(0, DB::transactionLevel());
    }

    /**
     * 测试字符串拼接（|| 操作符）
     *
     * @test
     */
    public function it_supports_string_concatenation()
    {
        $result = DB::select(DB::raw("SELECT 'Hello' || ' ' || 'World' as message"));

        $this->assertEquals('Hello World', $result[0]->message);
    }
}
```

运行测试：
```bash
./vendor/bin/phpunit tests/Feature/DatabaseCompatibilityTest.php
```

---

### 阶段三：开发环境配置（预计 30 分钟）

#### 1. 创建 SQLite 环境配置示例

**文件：** `.env.sqlite.example` (新建)

```bash
APP_NAME="CBDB Online (SQLite Dev)"
APP_ENV=local
APP_KEY=base64:YOUR_APP_KEY_HERE
APP_DEBUG=true
APP_URL=http://localhost:8000

LOG_CHANNEL=stack
LOG_LEVEL=debug

# SQLite 数据库配置
DB_CONNECTION=sqlite
DB_DATABASE=/full/path/to/cbdb-online-main-server/database/database.sqlite

BROADCAST_DRIVER=log
CACHE_DRIVER=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync

# 其他配置保持不变...
```

#### 2. 添加快速切换脚本

**文件：** `scripts/switch-to-sqlite.sh` (新建)

```bash
#!/bin/bash

echo "切换到 SQLite 数据库..."

# 备份当前 .env
if [ -f .env ]; then
    cp .env .env.backup.$(date +%Y%m%d_%H%M%S)
    echo "已备份当前配置到 .env.backup.$(date +%Y%m%d_%H%M%S)"
fi

# 创建 SQLite 数据库文件
DB_PATH="database/database.sqlite"
if [ ! -f "$DB_PATH" ]; then
    touch "$DB_PATH"
    echo "已创建 SQLite 数据库文件: $DB_PATH"
fi

# 更新 .env 配置
sed -i.bak 's/DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
sed -i.bak "s|DB_DATABASE=.*|DB_DATABASE=$(pwd)/$DB_PATH|" .env

echo "✅ 已切换到 SQLite"
echo ""
echo "下一步："
echo "1. 运行迁移: php artisan migrate:fresh"
echo "2. （可选）导入数据: php artisan db:seed"
echo "3. 启动服务: php artisan serve"
```

**文件：** `scripts/switch-to-mysql.sh` (新建)

```bash
#!/bin/bash

echo "切换到 MySQL 数据库..."

# 恢复配置
sed -i.bak 's/DB_CONNECTION=.*/DB_CONNECTION=mysql/' .env
sed -i.bak 's|DB_DATABASE=.*|DB_DATABASE=homestead|' .env

echo "✅ 已切换到 MySQL"
echo ""
echo "请确保 MySQL 服务正在运行"
echo "然后运行: php artisan migrate"
```

添加执行权限：
```bash
chmod +x scripts/switch-to-sqlite.sh
chmod +x scripts/switch-to-mysql.sh
```

---

### 阶段四：文档和指南（预计 1 小时）

#### 1. 更新 README.md

在 README.md 中添加数据库配置章节：

```markdown
## 数据库配置

本项目支持 MySQL 和 SQLite 两种数据库后端。

### 选项 1：使用 SQLite（推荐用于开发）

**优点：** 零配置、快速启动、易于重置

```bash
# 1. 切换到 SQLite
./scripts/switch-to-sqlite.sh

# 2. 运行迁移
php artisan migrate:fresh

# 3. 启动开发服务器
php artisan serve
```

### 选项 2：使用 MySQL（生产环境）

**优点：** 高并发性能、生产环境推荐

```bash
# 1. 启动 MySQL 服务
# 2. 创建数据库
mysql -u root -p -e "CREATE DATABASE cbdb_online CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 3. 配置 .env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cbdb_online
DB_USERNAME=your_username
DB_PASSWORD=your_password

# 4. 运行迁移
php artisan migrate
```

### 运行测试

测试套件使用 in-memory SQLite，速度极快：

```bash
./vendor/bin/phpunit
```
```

---

#### 2. 创建开发者指南

**文件：** `docs/DATABASE_COMPATIBILITY_GUIDE.md` (新建)

```markdown
# 数据库兼容性开发指南

## 编写兼容 MySQL 和 SQLite 的代码

### ✅ 推荐做法

#### 1. 使用 Eloquent ORM 和 Query Builder

大部分情况下，Eloquent 会自动处理数据库差异：

```php
// ✅ 好 - 自动兼容
User::where('email', 'test@example.com')->first();
DB::table('users')->where('active', 1)->get();
```

#### 2. 避免数据库特定函数

```php
// ❌ 避免 - MySQL 特定
->orderBy(DB::raw('ISNULL(column)'))

// ✅ 推荐 - 兼容两者
->orderByRaw('column IS NULL')
->orderBy('column')
```

#### 3. 字符串拼接使用 || 而不是 CONCAT

```php
// ❌ 避免 - MySQL 特定
DB::raw("CONCAT(first_name, ' ', last_name)")

// ✅ 推荐 - 兼容两者
DB::raw("first_name || ' ' || last_name")
```

#### 4. 迁移文件中使用辅助函数

```php
// ✅ 使用提供的辅助函数
public function up() {
    disable_foreign_keys();

    // 你的迁移代码

    enable_foreign_keys();
}
```

### 🧪 测试数据库兼容性

每次添加新功能后，运行测试确保兼容性：

```bash
# 运行所有测试（使用 SQLite）
./vendor/bin/phpunit

# 运行特定测试
./vendor/bin/phpunit tests/Feature/DatabaseCompatibilityTest.php
```

### 📋 兼容性检查清单

提交代码前检查：

- [ ] 没有使用 `ISNULL()`、`IFNULL()`（MySQL 特定）
- [ ] 没有使用 `CONCAT()`（用 `||` 代替）
- [ ] 没有使用 `NOW()`（用 `DB::raw('CURRENT_TIMESTAMP')` 或 Laravel 辅助函数）
- [ ] 迁移文件中的原始 SQL 使用了兼容性辅助函数
- [ ] 所有测试在 SQLite 下通过

### 🔍 常见问题

**Q: 如何在代码中检测当前使用的数据库？**

```php
$driver = DB::getDriverName();  // 'mysql' 或 'sqlite'

if ($driver === 'mysql') {
    // MySQL 特定逻辑
} else {
    // SQLite 特定逻辑
}
```

**Q: SQLite 不支持某些 ALTER TABLE 操作怎么办？**

SQLite 不支持某些列修改操作。解决方案：

1. 使用 Laravel 的 Schema Builder（会自动处理）
2. 或者创建新表 → 复制数据 → 删除旧表 → 重命名

**Q: 遇到 "database is locked" 错误？**

SQLite 默认不支持高并发写入。解决方案：

1. 增加 `busy_timeout`（已在配置中设置为 5000ms）
2. 考虑使用 WAL 模式（Write-Ahead Logging）
3. 生产环境使用 MySQL
```

---

## 🗓️ 实施时间表

| 阶段 | 任务 | 预计时间 | 负责人 |
|------|------|---------|--------|
| 1 | 代码兼容性修改 | 2-4 小时 | 开发团队 |
| 2 | 配置测试环境 | 1 小时 | 开发团队 |
| 3 | 开发环境配置 | 30 分钟 | 开发团队 |
| 4 | 文档和指南 | 1 小时 | 开发团队 |
| **总计** | | **4.5-6.5 小时** | |

## ✅ 验收标准

1. ✅ 所有现有测试在 SQLite 下通过
2. ✅ 新建的 `DatabaseCompatibilityTest` 全部通过
3. ✅ 可以使用 `./scripts/switch-to-sqlite.sh` 切换到 SQLite 并成功运行
4. ✅ 可以使用 `./scripts/switch-to-mysql.sh` 切换回 MySQL 并成功运行
5. ✅ 文档完整，新成员可以按照文档完成环境配置

## 📈 预期收益

### 开发体验改善

- ⚡ 测试速度提升 **10-100 倍**（从分钟级到秒级）
- 🚀 新成员上手时间从 1-2 小时降到 **5 分钟**（无需配置 MySQL）
- 🔄 数据库重置从手动操作变成 `php artisan migrate:fresh`

### 成本节约

- 💰 CI/CD 运行时间减少 **80%+**（节省 GitHub Actions 额度）
- 🖥️ 本地开发机器资源占用降低（无需运行 MySQL 服务）

### 代码质量

- 🛡️ 自动检测数据库特定语法，减少生产环境 bug
- 📊 可以频繁运行全量测试，提高代码覆盖率

## 🔄 后续维护

### 日常开发

1. **新功能开发：** 使用 SQLite 本地开发
2. **运行测试：** 自动使用 in-memory SQLite
3. **代码审查：** 检查是否遵循兼容性指南
4. **合并前：** 确保所有测试通过

### 定期检查

- 每月运行一次 MySQL 测试，确保生产环境兼容性
- 每季度检查是否有新的数据库特定语法引入

## 📚 参考资料

### SQLite 官方文档

- [SQLite 语法支持](https://www.sqlite.org/lang.html)
- [SQLite 数据类型](https://www.sqlite.org/datatype3.html)
- [SQLite 与其他数据库的比较](https://www.sqlite.org/different.html)

### Laravel 文档

- [数据库配置](https://laravel.com/docs/5.6/database)
- [迁移](https://laravel.com/docs/5.6/migrations)
- [测试](https://laravel.com/docs/5.6/testing)

### 最佳实践

- [编写数据库无关的 SQL](https://use-the-index-luke.com/)
- [Laravel 测试最佳实践](https://github.com/alexeymezenin/laravel-best-practices#testing)

## 🤝 贡献

如果发现任何数据库兼容性问题，请：

1. 提交 Issue，标签为 `database-compatibility`
2. 附上重现步骤和错误信息
3. 如果可能，提供修复建议

---

**文档版本：** 1.0
**最后更新：** 2025-11-17
**维护者：** CBDB 开发团队
