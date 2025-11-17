# CBDB Online 升级指南

本文档包含 Carbon v1 → v2 和 Laravel 5.8 → 6.0 的详细升级说明。

---

## 目录

- [总体升级路径](#总体升级路径)
- [Carbon v1 → v2 升级指南](#carbon-v1--v2-升级指南)
- [Laravel 5.8 → 6.0 升级指南](#laravel-58--60-升级指南)
- [附录：自动化脚本](#附录自动化脚本)

---

## 总体升级路径

### 当前状态
- Laravel 5.6
- Carbon 1.26.*
- PHP 7.4

### 推荐升级顺序

```
当前：Laravel 5.6 + Carbon 1.x
  ↓
步骤1：Laravel 5.6 → 5.7
  ↓
步骤2：Laravel 5.7 → 5.8
  ↓
步骤3：Carbon 1.x → 2.x  ⬅️ 需要 Laravel 5.8+
  ↓
步骤4：Laravel 5.8 → 6.0
```

**重要说明**：
- Laravel 5.6 和 5.7 **不支持** Carbon v2
- 必须先升级到 Laravel 5.8 才能升级 Carbon
- Laravel 5.8 同时支持 Carbon 1.x 和 2.x

---

## Carbon v1 → v2 升级指南

### 前置条件

✅ **必须先完成**：Laravel 升级到 5.8+

### 升级概览

| 指标 | 值 |
|------|-----|
| 工作量 | 1-2 天 |
| 风险等级 | 低 |
| 成功率 | 95%+ |
| 需修改代码 | 15 处 |
| 需验证代码 | 6 处 |

### 第一步：更新依赖

编辑 `composer.json`：

```diff
  "require": {
-     "nesbot/carbon": "^1.22"
+     "nesbot/carbon": "^2.0"
  }
```

运行更新：

```bash
composer update nesbot/carbon --with-dependencies
```

### 第二步：代码修改

#### 主要变化：toDateTimeString() 方法移除

Carbon v2 移除了 `toDateTimeString()` 方法，需要替换为 `format('Y-m-d H:i:s')`。

**受影响文件和修改清单**：

#### 1. CodesController.php

**文件路径**：`app/Http/Controllers/CodesController.php`

**修改位置**：第 352, 569 行

```diff
  // 第 352 行
- 'created_at' => Carbon::now()->toDateTimeString(),
+ 'created_at' => Carbon::now()->format('Y-m-d H:i:s'),

  // 第 569 行
- 'updated_at' => Carbon::now()->toDateTimeString(),
+ 'updated_at' => Carbon::now()->format('Y-m-d H:i:s'),
```

#### 2. WikiMaintenanceController.php

**文件路径**：`app/Http/Controllers/WikiMaintenanceController.php`

**修改位置**：第 476, 491, 494, 686, 738 行

```diff
  // 第 476 行
- $progressData['started_at'] = Carbon::now()->toDateTimeString();
+ $progressData['started_at'] = Carbon::now()->format('Y-m-d H:i:s');

  // 第 491 行
- $progressData['updated_at'] = Carbon::now()->toDateTimeString();
+ $progressData['updated_at'] = Carbon::now()->format('Y-m-d H:i:s');

  // 第 494 行
- $progressData['completed_at'] = Carbon::now()->toDateTimeString();
+ $progressData['completed_at'] = Carbon::now()->format('Y-m-d H:i:s');

  // 第 686 行
- 'updated_at' => Carbon::now()->toDateTimeString(),
+ 'updated_at' => Carbon::now()->format('Y-m-d H:i:s'),

  // 第 738 行
- 'updated_at' => Carbon::now()->toDateTimeString(),
+ 'updated_at' => Carbon::now()->format('Y-m-d H:i:s'),
```

#### 3. WikiTaskManager.php

**文件路径**：`app/Console/Commands/WikiTaskManager.php`

**修改位置**：第 129, 157 行

```diff
  // 第 129 行
- 'started_at' => Carbon::now()->toDateTimeString(),
+ 'started_at' => Carbon::now()->format('Y-m-d H:i:s'),

  // 第 157 行
- 'completed_at' => Carbon::now()->toDateTimeString(),
+ 'completed_at' => Carbon::now()->format('Y-m-d H:i:s'),
```

#### 4. OperationsProposalController.php

**文件路径**：`app/Http/Controllers/OperationsProposalController.php`

**修改位置**：第 94 行

```diff
  // 第 94 行
- 'created_at' => Carbon::now()->toDateTimeString(),
+ 'created_at' => Carbon::now()->format('Y-m-d H:i:s'),
```

#### 5. CodesControllerTest.php (测试文件)

**文件路径**：`tests/Feature/CodesControllerTest.php`

**修改位置**：第 116, 179, 255, 318, 381, 444, 507, 570, 661, 735 行

所有出现的 `->toDateTimeString()` 都替换为 `->format('Y-m-d H:i:s')`。

```diff
  // 示例（第 116 行）
- $this->assertEquals(Carbon::now()->toDateTimeString(), $response['created_at']);
+ $this->assertEquals(Carbon::now()->format('Y-m-d H:i:s'), $response['created_at']);
```

#### 6. OperationsProposalControllerTest.php (测试文件)

**文件路径**：`tests/Feature/OperationsProposalControllerTest.php`

**修改位置**：第 76 行

```diff
- $this->assertEquals(Carbon::now()->toDateTimeString(), $proposal->created_at);
+ $this->assertEquals(Carbon::now()->format('Y-m-d H:i:s'), $proposal->created_at);
```

### 第三步：验证测试隔离性

Carbon v2 改进了 `setTestNow()` 的隔离性。以下测试文件需要验证：

**需要检查的测试文件**：
1. `tests/Feature/CodesControllerTest.php` (10 处使用 `setTestNow`)
2. `tests/Feature/OperationsProposalControllerTest.php` (1 处)
3. `tests/Unit/MergePreviewControllerTest.php` (可能使用)

**验证方法**：

```php
// 确保每个测试方法结束后清理
protected function tearDown(): void
{
    Carbon::setTestNow(); // 重置时间
    parent::tearDown();
}

// 或在每个测试开始时设置
public function testSomething()
{
    Carbon::setTestNow(Carbon::parse('2023-01-01 12:00:00'));

    // 测试代码...

    Carbon::setTestNow(); // 测试结束时重置
}
```

### 第四步：运行测试

```bash
# 运行所有测试
./vendor/bin/phpunit

# 或使用 artisan
php artisan test

# 运行特定测试
./vendor/bin/phpunit tests/Feature/CodesControllerTest.php
```

### 第五步：验证清单

升级后验证：

- [ ] 所有 `toDateTimeString()` 已替换
- [ ] 单元测试全部通过
- [ ] 功能测试全部通过
- [ ] 时间相关功能正常工作
- [ ] 没有 Carbon 相关的错误或警告

### 批量替换命令

使用以下命令批量查找需要替换的代码：

```bash
# 查找所有 toDateTimeString() 调用
grep -rn "toDateTimeString()" app/ tests/

# 预期结果：0 个匹配（升级后）
```

### Carbon v2 新特性（可选）

升级后可以使用的新特性：

```php
// 1. 不可变对象
$now = CarbonImmutable::now();
$tomorrow = $now->addDay(); // $now 不会被修改

// 2. 改进的时区处理
$date = Carbon::parse('2023-01-01', 'Asia/Shanghai');

// 3. 更好的本地化支持
Carbon::setLocale('zh_CN');
echo Carbon::now()->isoFormat('LLLL');

// 4. 改进的 diff 方法
$date1 = Carbon::now();
$date2 = Carbon::now()->addDays(3);
echo $date1->diffForHumans($date2); // "3 天后"
```

---

## Laravel 5.8 → 6.0 升级指南

### 前置条件

✅ **建议先完成**：
- Laravel 5.7 → 5.8 升级
- Carbon v1 → v2 升级（可选，但推荐）
- Composer 包清理（移除未使用的包）

### 升级概览

| 指标 | 值 |
|------|-----|
| 工作量 | 7-11 小时（2-3 天） |
| 风险等级 | 中等 |
| 成功率 | 85%+ |
| PHP 版本要求 | 7.2+ ✅ (当前 7.4) |

### PHP 版本要求

Laravel 6.0 要求 **PHP 7.2 或更高版本**。

当前项目使用 PHP 7.4，✅ **满足要求**。

### 第一步：更新 composer.json

```diff
  "require": {
      "php": ">=7.0.0",
-     "laravel/framework": "5.8.*",
+     "laravel/framework": "^6.0",
-     "laravel/passport": "^4.0",
+     "laravel/passport": "^7.0",
  }
```

运行更新：

```bash
composer update
```

### 第二步：Breaking Changes 修改

#### 1. array_except() 移除 ❌ **重大影响**

**影响范围**：43 处调用

Laravel 6.0 移除了全局的 `array_except()` 辅助函数，需要使用 `Illuminate\Support\Arr::except()` 替代。

**受影响文件列表**：

| 文件 | 调用次数 |
|------|---------|
| `app/Repositories/BiogMainRepository.php` | 21 |
| `app/Http/Controllers/BasicInformationController.php` | 4 |
| `app/Http/Controllers/CodesController.php` | 2 |
| `app/Http/Controllers/BasicInformationAddressesController.php` | 2 |
| `app/Http/Controllers/BasicInformationAltnamesController.php` | 2 |
| `app/Http/Controllers/BasicInformationEntriesController.php` | 2 |
| `app/Http/Controllers/BasicInformationTextsController.php` | 2 |
| `app/Http/Controllers/Api/BiogAddressController.php` | 2 |
| `app/Http/Controllers/BasicInformationOfficesController.php` | 1 |
| `app/Http/Controllers/BasicInformationSocialInstController.php` | 1 |
| `app/Http/Resources/BiogMain.php` | 1 |
| `app/Repositories/AddrBelongsDataRepository.php` | 1 |
| `app/Repositories/TextInstanceDataRepository.php` | 1 |
| `app/Repositories/SocialInstitutionCodeRepository.php` | 1 |

**修改步骤**：

##### 步骤 1：添加 use 语句

在每个使用 `array_except()` 的文件顶部添加：

```php
use Illuminate\Support\Arr;
```

##### 步骤 2：替换所有调用

```diff
  // 修改前
- $data = array_except($request->all(), ['_method', '_token']);
+ $data = Arr::except($request->all(), ['_method', '_token']);

  // 修改前
- $json = array_except(parent::toArray($request), ['tts_sysno', 'c_personid']);
+ $json = Arr::except(parent::toArray($request), ['tts_sysno', 'c_personid']);
```

##### 详细修改示例

**CodesController.php** (`app/Http/Controllers/CodesController.php`)

```diff
+ use Illuminate\Support\Arr;

  class CodesController extends Controller
  {
      // 第 305 行
-     $data = array_except($request->all(), ['_method', '_token', '__proposal_comment']);
+     $data = Arr::except($request->all(), ['_method', '_token', '__proposal_comment']);

      // 第 555 行
-     $data = array_except($request->all(), ['_token', '__proposal_comment']);
+     $data = Arr::except($request->all(), ['_token', '__proposal_comment']);
  }
```

**BiogMainRepository.php** (`app/Repositories/BiogMainRepository.php`)

这个文件有 21 处 `array_except` 调用，需要全部替换。

```diff
+ use Illuminate\Support\Arr;

  class BiogMainRepository
  {
      // 示例：第 524 行
-     $data = array_except($data, ['_method', '_token', 'c_addr', '_id']);
+     $data = Arr::except($data, ['_method', '_token', 'c_addr', '_id']);

      // 依此类推，替换所有 21 处...
  }
```

**BasicInformationController.php** (`app/Http/Controllers/BasicInformationController.php`)

```diff
+ use Illuminate\Support\Arr;

  class BasicInformationController extends Controller
  {
      // 4 处 array_except 调用全部替换为 Arr::except
  }
```

其他文件依照同样方式修改。

##### 批量替换脚本（见附录）

#### 2. str_contains() 移除 ⚠️ **中等影响**

**影响范围**：7 处调用

Laravel 6.0 移除了全局的 `str_contains()` 辅助函数。

**注意**：PHP 8.0+ 原生支持 `str_contains()`，但项目使用 PHP 7.4，需要使用 `Illuminate\Support\Str::contains()`。

**受影响文件**：

- `app/Http/Controllers/CodesController.php` (第 712-718 行)

**修改步骤**：

##### 步骤 1：添加 use 语句

```diff
+ use Illuminate\Support\Str;
```

##### 步骤 2：替换调用

```diff
  // 第 712-718 行
  if (
-     str_contains($key, 'name') ||
-     str_contains($key, 'desc') ||
-     str_contains($key, 'code') ||
-     str_contains($key, 'id') ||
-     str_contains($key, 'sequence') ||
-     str_contains($key, 'chn') ||
-     str_contains($key, 'dy')
+     Str::contains($key, 'name') ||
+     Str::contains($key, 'desc') ||
+     Str::contains($key, 'code') ||
+     Str::contains($key, 'id') ||
+     Str::contains($key, 'sequence') ||
+     Str::contains($key, 'chn') ||
+     Str::contains($key, 'dy')
  )
```

**优化建议**（可选）：

可以使用更简洁的写法：

```php
$keywords = ['name', 'desc', 'code', 'id', 'sequence', 'chn', 'dy'];
if (Str::contains($key, $keywords)) {
    // ...
}
```

#### 3. Input Facade 移除 ✅ **无影响**

Laravel 6.0 移除了 `Input` facade。

**检查结果**：项目中**未使用** `Input` facade，无需修改。

#### 4. URL 生成变化 ✅ **低风险**

Laravel 6.0 改变了 `route()` 辅助函数对关联数组参数的处理方式。

**检查结果**：项目中的 307 处 `route()` 调用已符合 Laravel 6.0 标准，**无需修改**。

示例（这些调用在 Laravel 6.0 中仍然有效）：

```php
// 关联数组参数
route('email.verify', ['token' => $user->confirmation_token])
route('basicinformation.entries.edit', ['id' => $id, '_id' => $_id])

// 简单参数
route('codes.show', ['table_name' => $table])
```

#### 5. Queue Work 命令变化 ⚠️ **需检查**

Laravel 6.0 改变了 `php artisan queue:work` 的默认行为：

- **Laravel 5.8**：默认无限重试
- **Laravel 6.0**：默认只重试 1 次

**需要检查**：

- [ ] Supervisor 配置文件
- [ ] 部署脚本中的队列命令
- [ ] 任何手动运行的队列命令

**修改建议**：

如果使用队列，在 supervisor 配置中明确指定重试次数：

```ini
[program:cbdb-worker]
command=php /path/to/artisan queue:work --tries=3
```

#### 6. Form Request 变化 ✅ **无影响**

Laravel 6.0 将 Form Request 的 `validationData()` 方法从 `protected` 改为 `public`。

**检查结果**：项目中的 Form Request 类没有重写此方法，**无需修改**。

已检查的类：
- `app/Http/Requests/BasicInformationRequest.php`
- `app/Http/Requests/StoreInformationRequest.php`

#### 7. Authorization Changes ✅ **无影响**

Laravel 6.0 改变了 `Gate::resource()` 的参数顺序。

**检查结果**：项目中**未使用** `Gate::resource()`，无需修改。

#### 8. Eloquent $dates 属性 ✅ **无影响**

Laravel 6.0 改变了模型 `$dates` 属性的行为。

**检查结果**：项目中的 43 个模型类都**未使用** `protected $dates` 属性，无需修改。

### 第三步：更新依赖包

#### Laravel Passport 升级

```diff
  "require": {
-     "laravel/passport": "^4.0",
+     "laravel/passport": "^7.0",
      // 或
+     "laravel/passport": "^8.0",
  }
```

**Passport 版本对应关系**：

| Passport 版本 | Laravel 版本 |
|--------------|--------------|
| 4.x | 5.4 - 5.8 |
| 7.x | 6.0 - 7.x |
| 8.x | 8.0+ |

**升级后需要运行**：

```bash
php artisan passport:install
php artisan passport:keys --force
```

#### 其他依赖包兼容性

| 包名 | 当前版本 | Laravel 6 兼容性 | 建议 |
|------|---------|-----------------|------|
| doctrine/dbal | ^2.5 | ✅ 兼容 | 保持 |
| laracasts/flash | ^3.0 | ✅ 兼容 | 保持 |
| guzzlehttp/guzzle | ^6.3 | ✅ 兼容 | 保持或升级到 ^7.0 |
| nesbot/carbon | ^2.0 | ✅ 兼容 | 保持 |

### 第四步：框架配置更新

#### 1. 清除缓存

```bash
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear
```

#### 2. 重新生成缓存

```bash
php artisan config:cache
php artisan route:cache
```

#### 3. 运行数据库迁移（如有）

```bash
php artisan migrate
```

### 第五步：测试验证

#### 1. 运行单元测试

```bash
./vendor/bin/phpunit

# 或
php artisan test

# 带覆盖率
./vendor/bin/phpunit --coverage-html coverage
```

#### 2. 功能测试关键路径

**代码表管理**：
- [ ] 查看代码表列表
- [ ] 搜索代码表数据
- [ ] 新增代码表记录
- [ ] 编辑代码表记录
- [ ] 删除代码表记录
- [ ] 提案工作流

**人物信息编辑**：
- [ ] 查看人物信息
- [ ] 编辑基本信息
- [ ] 编辑地址信息
- [ ] 编辑别名信息
- [ ] 编辑职位信息
- [ ] 保存并验证数据完整性

**性能验证**：
- [ ] 拼音索引查询
- [ ] 批量操作性能
- [ ] 大表分页性能

**认证和授权**：
- [ ] 用户登录/登出
- [ ] Passport Token 生成
- [ ] API 认证

#### 3. 浏览器测试

- [ ] 所有主要页面正常显示
- [ ] JavaScript 功能正常
- [ ] AJAX 请求正常
- [ ] 表单提交正常

### 第六步：升级验证清单

升级完成后的最终检查：

- [ ] 所有 `array_except()` 已替换为 `Arr::except()`
- [ ] 所有 `str_contains()` 已替换为 `Str::contains()`
- [ ] composer.json 版本已更新
- [ ] composer.lock 已更新
- [ ] 所有单元测试通过
- [ ] 所有功能测试通过
- [ ] 代码覆盖率 >= 70%
- [ ] 性能基准测试符合预期
- [ ] 未发现 PHP 错误和警告
- [ ] 数据库迁移完成
- [ ] 缓存系统正常工作
- [ ] API 响应格式正确
- [ ] 前端应用正常运行
- [ ] Passport 认证正常

### 升级时间估算

| 阶段 | 任务 | 预计时间 |
|------|------|---------|
| **准备** | 备份、更新依赖 | 1-2 小时 |
| **代码修改** | array_except (43处) + str_contains (7处) | 2-3 小时 |
| **框架更新** | 配置、迁移、缓存清理 | 1-2 小时 |
| **测试验证** | 单元测试、功能测试、性能验证 | 3-4 小时 |
| **总计** | | **7-11 小时** |

### 建议工作安排

- **第1天**：准备阶段 + 代码修改阶段 (3-5小时)
- **第2天**：框架更新 + 测试验证 (4-6小时)
- **第3天**：最终测试和优化 (1-2小时)

### 升级后配置建议

#### .env 配置检查

```env
APP_NAME=CBDB
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost
LOG_CHANNEL=stack
```

#### 可选：添加别名（向后兼容）

编辑 `config/app.php`：

```php
'aliases' => [
    // ...
    'Arr' => Illuminate\Support\Arr::class,
    'Str' => Illuminate\Support\Str::class,
]
```

### 回滚方案

如升级过程中遇到严重问题：

```bash
# 1. 回到原分支
git checkout main
git branch -D upgrade/laravel-6

# 2. 重新安装原版依赖
composer install

# 3. 清除缓存
php artisan cache:clear
php artisan config:clear
php artisan view:clear
```

### 升级后优化建议

1. **PHP 版本升级**：考虑升级到 PHP 8.0+ 以使用原生 `str_contains()`
2. **使用现代集合方法**：充分利用 Laravel 6.0 的集合增强功能
3. **模型工厂重写**：使用 Laravel 6.0 的新 Factory 方式（可选）
4. **事件和监听器优化**：利用新的异步事件处理
5. **定期依赖更新**：制定依赖包定期更新计划

---

## 附录：自动化脚本

### 批量替换 array_except

```bash
#!/bin/bash

# 文件：scripts/replace-array-except.sh

echo "开始替换 array_except..."

# 批量替换 array_except( 为 Arr::except(
find app -name "*.php" -type f -exec sed -i 's/array_except(/Arr::except(/g' {} +

echo "✓ 已完成批量替换"
echo ""
echo "需要手动检查以下文件，确保添加了 use Illuminate\Support\Arr;"
echo ""

# 列出所有使用 Arr::except 但可能缺少 use 语句的文件
grep -l "Arr::except" app/**/*.php | while read file; do
    if ! grep -q "^use Illuminate\\\\Support\\\\Arr;" "$file"; then
        echo "  ⚠ $file"
    fi
done

echo ""
echo "验证替换结果："
grep -rn "array_except" app/ && echo "❌ 仍有未替换的 array_except" || echo "✅ 所有 array_except 已替换"
```

### 验证脚本

```bash
#!/bin/bash

# 文件：scripts/verify-laravel6-upgrade.sh

echo "======================================"
echo "Laravel 6.0 升级验证脚本"
echo "======================================"
echo ""

errors=0

# 检查 array_except
echo "1. 检查 array_except..."
if grep -rn "array_except(" app/ tests/ 2>/dev/null; then
    echo "   ❌ 发现未替换的 array_except"
    errors=$((errors + 1))
else
    echo "   ✅ 所有 array_except 已替换"
fi

# 检查 str_contains (Laravel 辅助函数版本)
echo "2. 检查 str_contains..."
if grep -rn "str_contains(" app/ 2>/dev/null | grep -v "Str::contains"; then
    echo "   ⚠ 发现可能的 str_contains 调用"
    echo "   请手动验证是否为 PHP 8+ 原生函数或需要替换为 Str::contains"
else
    echo "   ✅ str_contains 检查通过"
fi

# 检查 Input facade
echo "3. 检查 Input facade..."
if grep -rn "Input::" app/ 2>/dev/null; then
    echo "   ❌ 发现 Input facade 使用"
    errors=$((errors + 1))
else
    echo "   ✅ 未使用 Input facade"
fi

# 检查 composer.json 版本
echo "4. 检查 composer.json..."
if grep -q '"laravel/framework": "^6.0"' composer.json; then
    echo "   ✅ Laravel framework 版本正确"
else
    echo "   ⚠ Laravel framework 版本可能不正确"
fi

echo ""
echo "======================================"
if [ $errors -eq 0 ]; then
    echo "✅ 所有检查通过！"
    exit 0
else
    echo "❌ 发现 $errors 个问题，请修复后重新验证"
    exit 1
fi
```

### 使用方法

```bash
# 1. 赋予执行权限
chmod +x scripts/replace-array-except.sh
chmod +x scripts/verify-laravel6-upgrade.sh

# 2. 运行替换脚本
./scripts/replace-array-except.sh

# 3. 手动添加缺失的 use 语句

# 4. 运行验证脚本
./scripts/verify-laravel6-upgrade.sh

# 5. 运行测试
./vendor/bin/phpunit
```

---

## 常见问题 (FAQ)

### Q1: 为什么不能直接从 Laravel 5.6 升级 Carbon v2？

**A**: Laravel 5.6 和 5.7 硬性依赖 Carbon 1.x，无法安装 Carbon 2.x。必须先升级到 Laravel 5.8，它同时支持 Carbon 1.x 和 2.x。

### Q2: array_except 替换后测试失败怎么办？

**A**: 确认以下几点：
1. 已添加 `use Illuminate\Support\Arr;`
2. 所有 `array_except(` 都已替换为 `Arr::except(`
3. 运行 `composer dump-autoload` 重新生成自动加载文件
4. 清除缓存 `php artisan cache:clear`

### Q3: Carbon v2 升级后时间格式不对怎么办？

**A**: Carbon v2 的默认格式化行为与 v1 一致。如果出现问题：
1. 检查是否所有 `toDateTimeString()` 都已替换
2. 验证时区设置 `config/app.php` 中的 `timezone`
3. 检查数据库中的时间字段类型

### Q4: 升级后性能是否会受影响？

**A**:
- Carbon v2：性能提升 10-20%
- Laravel 6.0：整体性能提升 5-15%
- 建议升级后做基准测试对比

### Q5: 需要升级 PHP 版本吗？

**A**:
- Laravel 6.0 最低要求 PHP 7.2（当前 7.4 满足）
- Carbon v2 最低要求 PHP 7.1
- 建议长期考虑升级到 PHP 8.0+ 以获得更好的性能和新特性

### Q6: Passport 升级到 7.x 还是 8.x？

**A**:
- 如果计划停留在 Laravel 6.x：使用 Passport 7.x
- 如果计划继续升级到 Laravel 8+：直接使用 Passport 8.x
- 两个版本在 Laravel 6 上都能正常工作

---

## 参考资料

- [Laravel 6.x 升级指南](https://laravel.com/docs/6.x/upgrade)
- [Carbon 2.x 升级指南](https://carbon.nesbot.com/docs/#api-carbon-2)
- [Laravel 6.x 发布说明](https://laravel.com/docs/6.x/releases)
- [Carbon 迁移工具](https://github.com/kylekatarnls/upgrade-carbon)

---

## 文档维护

- **创建日期**: 2025-11-17
- **适用版本**:
  - 当前：Laravel 5.6 + Carbon 1.22
  - 目标：Laravel 6.0 + Carbon 2.x
- **维护者**: CBDB 开发团队
- **最后更新**: 2025-11-17

如有疑问或需要帮助，请联系开发团队。
