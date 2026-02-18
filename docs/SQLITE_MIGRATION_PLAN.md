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

## 💡 实施策略

### 核心思路：向前兼容，历史不动

**关键决策：**
- ❌ **不修改**历史 migration 文件（避免风险，减少工作量）
- ✅ **创建**一次性 MySQL → SQLite 导出脚本
- ✅ **确保**未来新增代码保持双数据库兼容

**工作量对比：**
- 修改历史 migrations 方案：4.5-6.5 小时
- **导出脚本方案：1-2 小时** ⭐（推荐）

## 📊 兼容性评估

### 现有代码分析结果

| 项目 | 状态 | 说明 |
|------|------|------|
| 事务支持 | ✅ 完全兼容 | `DB::transaction()`、`beginTransaction/commit/rollback` 全部支持 |
| 视图（Views） | ✅ 完全兼容 | 使用标准 SQL JOIN，无需修改 |
| 外键约束 | ✅ 兼容 | 需启用 `foreign_key_constraints = true` |
| 查询构建器 | ✅ 完全兼容 | Eloquent ORM 和 Query Builder 自动适配 |
| 字符串拼接 | ✅ 完全兼容 | 已使用 `||` 操作符（双数据库通用） |
| ISNULL 函数 | ⚠️ 需要修改 | 1 处：`app/BiogMain.php:102` |

### 需要修改的地方

**总计：仅 1 处代码修改 + 1 个导出脚本**

1. **`app/BiogMain.php:102`** - ISNULL 函数（未来代码兼容性）
2. **创建导出脚本** - 一次性从 MySQL 导出到 SQLite

## 🔧 详细实施步骤

### 阶段一：创建 MySQL → SQLite 导出脚本（预计 1 小时）

#### 1. 创建 Artisan 命令

使用 Laravel 自带的数据库连接，实现智能导出。

**文件：** `app/Console/Commands/ExportMysqlToSqlite.php`

**功能：**
- 自动读取 MySQL 的表结构和数据
- 转换数据类型（VARBINARY → BLOB 等）
- 处理外键约束
- 批量导入数据到 SQLite
- 显示进度和统计信息

**使用方式：**
```bash
# 基本用法
php artisan db:export-to-sqlite --limit-records=5000

# 指定输出文件
php artisan db:export-to-sqlite --output=database/production.sqlite --limit-records=5000

# 只导出结构，不导出数据
php artisan db:export-to-sqlite --schema-only

# 导出特定表
php artisan db:export-to-sqlite --tables=BIOG_MAIN,ALTNAME_DATA --limit-records=5000

# 调整性能参数（处理大表或磁盘空间受限时）
php artisan db:export-to-sqlite \
  --chunk-size=1000 \           # 减小分块大小，降低内存使用
  --min-free-space=2 \          # 要求至少 2GB 可用空间（默认 1GB）
  --limit-records=10000         # 限制每表最大行数

# 跳过磁盘空间检查（不推荐，仅在确认有足够空间时使用）
php artisan db:export-to-sqlite --skip-space-check
```

**重要提示：**
- 🔒 **数据完整性保证**：
  - 根据表结构自动选择最安全的导出策略，**绝对保证**不会跳过或重复行
  - **无主键表**（如 ADDRESSES, PLACE_CODES）：使用 `cursor()` 单次查询，避免 offset/limit 的不确定性
  - **单列主键表**（如 BIOG_MAIN）：使用高效的 `chunkById()`，基于 `WHERE id > lastValue` 分批读取
  - **复合主键表**（如 KIN_DATA）：按所有主键列排序（如 `ORDER BY c_kin_code, c_kin_id, c_personid`），确保稳定排序
- ⚡ **智能分块策略**：
  - 自动检测表是否有主键、主键类型（单列/复合）
  - 针对不同表结构选择最优的读取方法，平衡性能与数据安全
  - 无主键表使用 cursor() 虽稍慢但保证正确性
- 🎯 **自动磁盘检查**：导出前会检查输出目录和 `/tmp` 的可用空间
- 💾 **内存优化**：所有策略均分批写入 SQLite，定期释放内存（每 10000 行），适合大表导出
- ⚠️ **临时文件**：大表导出时可能在 `/tmp` 产生临时文件，请确保有足够空间

---

#### 2. 创建数据库兼容性辅助函数

**文件：** `database/migrations/helpers.php` (新建)

这些辅助函数供**未来的 migrations** 使用，确保新迁移文件兼容双数据库。

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

### 阶段二：修改现有兼容性问题（预计 30 分钟）

#### 1. 修改 BiogMain.php 中的 ISNULL 用法

**文件：** `app/BiogMain.php:102`

**当前代码：**
```php
->orderBy(DB::raw('ISNULL(c_sequence), c_sequence'), 'ASC');
```

**修改为：**
```php
->orderByRaw('c_sequence IS NULL')
->orderBy('c_sequence', 'ASC');
```

**兼容性：** ✅ MySQL 和 SQLite 都支持 `IS NULL` 表达式

---

### 阶段三：配置测试环境（预计 30 分钟）

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
use App\Models\BiogMain;
use PHPUnit\Framework\Attributes\Test;

class DatabaseCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_uses_sqlite_for_testing()
    {
        $this->assertEquals('sqlite', DB::getDriverName());
    }

    #[Test]
    public function it_has_foreign_key_constraints_enabled()
    {
        if (DB::getDriverName() === 'sqlite') {
            $result = DB::select('PRAGMA foreign_keys');
            $this->assertEquals(1, $result[0]->foreign_keys);
        }

        $this->assertTrue(true);
    }

    #[Test]
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

    #[Test]
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

### 阶段四：文档和工具（预计 30 分钟）

#### 1. 创建快速切换脚本

**文件：** `scripts/use-sqlite.sh` (新建)

```bash
#!/bin/bash

echo "🔄 切换到 SQLite 数据库..."

# 备份当前 .env
if [ -f .env ]; then
    cp .env .env.backup.$(date +%Y%m%d_%H%M%S)
    echo "✅ 已备份当前配置"
fi

# 创建 SQLite 数据库文件（如果不存在）
DB_PATH="database/database.sqlite"
if [ ! -f "$DB_PATH" ]; then
    touch "$DB_PATH"
    echo "✅ 已创建 SQLite 数据库文件"
fi

# 更新 .env 配置
sed -i.bak 's/DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
sed -i.bak "s|DB_DATABASE=.*|DB_DATABASE=$(pwd)/$DB_PATH|" .env

echo ""
echo "✅ 已切换到 SQLite！"
echo ""
echo "📋 下一步："
echo "   1. 从 MySQL 导出数据: php artisan db:export-to-sqlite --limit-records=5000"
echo "   2. 或者运行全新迁移: php artisan migrate:fresh"
echo "   3. 启动服务: php artisan serve"
```

**文件：** `scripts/use-mysql.sh` (新建)

```bash
#!/bin/bash

echo "🔄 切换到 MySQL 数据库..."

# 恢复配置
sed -i.bak 's/DB_CONNECTION=.*/DB_CONNECTION=mysql/' .env
sed -i.bak 's|DB_DATABASE=.*|DB_DATABASE=homestead|' .env

echo "✅ 已切换到 MySQL"
echo ""
echo "⚠️  请确保 MySQL 服务正在运行"
```

添加执行权限：
```bash
chmod +x scripts/use-sqlite.sh scripts/use-mysql.sh
```

---

#### 2. 更新 README.md

在 README.md 中添加数据库配置章节：

```markdown
## 数据库配置

本项目支持 MySQL 和 SQLite 两种数据库后端。

### 选项 1：使用 SQLite（推荐用于开发）

**优点：** 零配置、快速启动、易于重置

```bash
# 1. 切换到 SQLite
./scripts/use-sqlite.sh

# 2. 从生产 MySQL 导出数据（如果有）
php artisan db:export-to-sqlite --limit-records=5000

# 3. 或运行全新迁移
php artisan migrate:fresh --seed

# 4. 启动开发服务器
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

## 📝 编写兼容代码指南

### ✅ 推荐做法

#### 1. 使用 Eloquent ORM 和 Query Builder

大部分情况下，Eloquent 会自动处理数据库差异：

```php
// ✅ 好 - 单一主键的表使用 Eloquent
User::where('email', 'test@example.com')->first();

// ✅ 好 - 复合主键的表使用 Query Builder
DB::table('users')->where('active', 1)->get();
```

**重要限制：**
- **单一主键的表**：可以使用 Eloquent 模型
- **复合主键的表**（如 `ALTNAME_DATA`、`POSTED_TO_ADDR_DATA`）：必须使用 Query Builder（`DB::table()`），因为 **Eloquent 官方不支持复合主键**。虽然有第三方套件提供支持，但会增加维护上的不确定性，因此本项目决定在复合主键表上直接使用 Query Builder

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

#### 4. 未来 Migrations 使用辅助函数

```php
// ✅ 使用提供的辅助函数
public function up() {
    disable_foreign_keys();

    // 你的迁移代码

    enable_foreign_keys();
}
```

### 📋 代码审查检查清单

提交新代码前检查：

- [ ] 没有使用 `ISNULL()`、`IFNULL()`（MySQL 特定）
- [ ] 没有使用 `CONCAT()`（用 `||` 代替）
- [ ] 没有使用 `NOW()`（用 `DB::raw('CURRENT_TIMESTAMP')` 或 Laravel 函数）
- [ ] 新 migration 文件使用了兼容性辅助函数
- [ ] 所有测试在 SQLite 下通过

---

## 🗓️ 实施时间表

| 阶段 | 任务 | 预计时间 | 负责人 |
|------|------|---------|--------|
| 1 | 创建 MySQL → SQLite 导出脚本 | 1 小时 | 开发团队 |
| 2 | 修改现有兼容性问题 | 30 分钟 | 开发团队 |
| 3 | 配置测试环境 | 30 分钟 | 开发团队 |
| 4 | 文档和工具 | 30 分钟 | 开发团队 |
| **总计** | | **2.5 小时** | |

## ✅ 验收标准

1. ✅ 导出脚本能成功将 MySQL 数据导入 SQLite
2. ✅ 所有现有测试在 SQLite 下通过
3. ✅ 新建的 `DatabaseCompatibilityTest` 全部通过
4. ✅ 可以使用 `./scripts/use-sqlite.sh` 切换到 SQLite 并成功运行
5. ✅ 可以使用 `./scripts/use-mysql.sh` 切换回 MySQL 并成功运行
6. ✅ 文档完整，新成员可以按照文档完成环境配置

## 📈 预期收益

### 开发体验改善

- ⚡ 测试速度提升 **10-100 倍**（从分钟级到秒级）
- 🚀 新成员上手时间从 1-2 小时降到 **5 分钟**（无需配置 MySQL）
- 🔄 数据库重置从手动操作变成 `php artisan migrate:fresh`
- 📦 可以将 SQLite 数据库文件提交到版本控制（seed 数据）

### 成本节约

- 💰 CI/CD 运行时间减少 **80%+**（节省 GitHub Actions 额度）
- 🖥️ 本地开发机器资源占用降低（无需运行 MySQL 服务）

### 代码质量

- 🛡️ 自动检测数据库特定语法，减少生产环境 bug
- 📊 可以频繁运行全量测试，提高代码覆盖率

## 🔄 日常使用流程

### 新成员入职

```bash
# 1. 克隆项目
git clone <repository>

# 2. 安装依赖
composer install

# 3. 配置环境（自动使用 SQLite）
cp .env.example .env
php artisan key:generate

# 4. 创建并导入数据
touch database/database.sqlite
php artisan db:export-to-sqlite --limit-records=5000  # 从生产环境导出
# 或
php artisan migrate:fresh --seed  # 使用测试数据

# 5. 开始开发
php artisan serve
```

**总耗时：5 分钟** 🚀

### 日常开发

```bash
# 开发新功能（使用 SQLite）
php artisan make:migration create_something_table

# 运行测试（自动使用 in-memory SQLite）
./vendor/bin/phpunit

# 重置数据库
php artisan migrate:fresh --seed
```

### 部署前验证

```bash
# 切换到 MySQL 测试
./scripts/use-mysql.sh
php artisan migrate

# 确认没有问题后部署
git push
```

---

## 🔍 常见问题

**Q: 导出时遇到 "No space left on device" 错误？**

A: 这个问题通常是因为 `/tmp` 目录空间不足（大表排序时会产生临时文件）。解决方案：
1. 清理 `/tmp` 目录：`sudo rm -rf /tmp/MY* /tmp/ib*`
2. 调小分块大小：`--chunk-size=1000`
3. 限制导出数据量：`--limit-records=10000`
4. 增加 `/tmp` 目录的可用空间（挂载更大的分区或清理其他文件）

**Q: 导出脚本会导出所有数据吗？**

A: 默认会导出所有表的结构和数据。可以使用 `--schema-only` 只导出结构，或使用 `--tables` 指定特定表。

**Q: 导出的数据顺序会和 MySQL 中一样吗？**

A: 数据按主键或索引列的顺序导出。脚本总是使用排序（通过 `chunkById()` 或 `orderBy()`）以确保数据完整性，不会出现跳过或重复行的情况。

**Q: SQLite 数据库文件应该提交到 git 吗？**

A: 建议：
- 开发环境的 seed 数据库：✅ 可以提交
- 生产数据：❌ 不要提交（添加到 .gitignore）

**Q: 如何处理大数据集？**

A: SQLite 适合中小型数据集（< 100GB）。CBDB 数据量适中，完全没问题。如果数据量很大，建议：
- 开发环境：使用导出脚本创建的 SQLite 数据库
- 生产环境：继续使用 MySQL

**Q: 遇到 "database is locked" 错误？**

A: SQLite 不支持高并发写入。解决方案：
1. 已配置 `busy_timeout = 5000ms`
2. 开发环境单用户通常不会遇到此问题
3. 生产环境使用 MySQL

**Q: 如何在代码中检测当前使用的数据库？**

```php
$driver = DB::getDriverName();  // 'mysql' 或 'sqlite'

if ($driver === 'mysql') {
    // MySQL 特定逻辑
} else {
    // SQLite 特定逻辑
}
```

---

## 📚 参考资料

### SQLite 官方文档

- [SQLite 语法支持](https://www.sqlite.org/lang.html)
- [SQLite 数据类型](https://www.sqlite.org/datatype3.html)
- [SQLite 与其他数据库的比较](https://www.sqlite.org/different.html)

### Laravel 文档

- [数据库配置](https://laravel.com/docs/10.x/database)
- [迁移](https://laravel.com/docs/10.x/migrations)
- [测试](https://laravel.com/docs/10.x/testing)

### 最佳实践

- [编写数据库无关的 SQL](https://use-the-index-luke.com/)
- [Laravel 测试最佳实践](https://github.com/alexeymezenin/laravel-best-practices#testing)

## 🤝 贡献

如果发现任何数据库兼容性问题，请：

1. 提交 Issue，标签为 `database-compatibility`
2. 附上重现步骤和错误信息
3. 如果可能，提供修复建议

---

**文档版本：** 2.0
**最后更新：** 2025-11-17
**维护者：** CBDB 开发团队
