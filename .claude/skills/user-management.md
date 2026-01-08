---
name: 用戶管理操作
description: 創建和更新用戶帳號的完整指南，包含 cbdb:manage-user 命令使用、User Factory 測試、角色權限管理
---

# 用戶管理指南

## 何時使用此技能

當你需要創建、更新用戶帳號，或在測試中使用 User Factory 創建測試用戶時，使用此指南。

## 管理用戶命令

使用 `php artisan cbdb:manage-user` 創建或更新用戶。適用於環境初始設置和日常用戶管理。

### 交互式模式

直接運行命令，系統會逐步詢問所需信息：

```bash
php artisan cbdb:manage-user
```

系統會提示輸入：
- Email（必填）
- 姓名（必填）
- 密碼（創建新用戶時必填）
- 激活狀態
- 角色

### 命令行模式（適合腳本自動化）

使用選項直接指定參數：

```bash
# 創建系統管理員
php artisan cbdb:manage-user \
  --email=admin@example.com \
  --name="系統管理員" \
  --password=secret123 \
  --active=1 \
  --role=super-admin

# 更新現有用戶角色
php artisan cbdb:manage-user --email=user@example.com --role=expert

# 列出所有用戶
php artisan cbdb:manage-user --list
```

### 支持的角色

| 角色代碼 | 說明 | 權限級別 |
|---------|------|---------|
| `regular` | 一般用戶 | 基本查詢和編輯 |
| `expert` | 專家用戶 | 擴展編輯權限 |
| `crowdsourcing` | 眾包用戶 | 眾包錄入功能 |
| `super-admin` | 系統管理員 | 完整管理權限 |

### 激活狀態

| 狀態值 | 說明 |
|-------|------|
| `0` | 未激活 |
| `1` | 激活 |
| `2` | 預留 |

## User Factory（測試和開發用）

在測試代碼和內部工具中，可使用 `User::factory()` 創建測試用戶。

### 基本用法

```php
// 創建普通用戶
$user = User::factory()->create();

// 創建活躍的系統管理員
$admin = User::factory()->active()->superAdmin()->create();

// 批量創建用戶
$users = User::factory()->count(5)->create();

// 創建但不保存到數據庫
$user = User::factory()->make();
```

### 自定義屬性

```php
// 自定義特定字段
$user = User::factory()->create([
    'email' => 'custom@example.com',
    'name' => '自定義用戶',
]);

// 使用狀態方法
$activeExpert = User::factory()
    ->active()
    ->state(['role' => 'expert'])
    ->create();
```

### 常用組合

```php
// 測試：創建活躍管理員
$admin = User::factory()->active()->superAdmin()->create();

// 測試：創建普通用戶
$user = User::factory()->create();

// 測試：創建多個眾包用戶
$crowdsourcingUsers = User::factory()
    ->count(3)
    ->state(['role' => 'crowdsourcing'])
    ->create();
```

### 向後兼容語法

舊版 Laravel 語法仍然支持：

```php
// 舊語法（仍可用）
$user = factory(User::class)->create();

// 新語法（推薦）
$user = User::factory()->create();
```

## 測試中的用戶管理

### Feature 測試中的用戶認證

```php
use Tests\TestCase;
use App\Models\User;

class ExampleTest extends TestCase {
    public function test_admin_can_access_page() {
        // 創建並登入管理員
        $admin = User::factory()->active()->superAdmin()->create();

        $response = $this->actingAs($admin)
            ->get('/admin/dashboard');

        $response->assertStatus(200);
    }

    public function test_regular_user_cannot_access_admin_page() {
        // 創建普通用戶
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('/admin/dashboard');

        $response->assertStatus(403);
    }
}
```

### 設置測試數據庫中的用戶表

如果使用 in-memory SQLite 測試：

```php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

protected function setUp(): void {
    parent::setUp();

    // 配置 SQLite
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    // 創建 users 表
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('email')->unique();
        $table->string('name');
        $table->string('password');
        $table->string('confirmation_token')->nullable();
        $table->tinyInteger('is_active')->default(0);
        $table->tinyInteger('is_admin')->default(0);
        $table->string('role')->default('regular');
        $table->timestamps();
    });
}
```

**注意**：記得為 `confirmation_token` 字段提供值或設為 nullable，避免 NOT NULL 約束錯誤。

## 常見場景

### 1. 環境初始設置

```bash
# 創建第一個系統管理員
php artisan cbdb:manage-user \
  --email=admin@cbdb.example.com \
  --name="CBDB 系統管理員" \
  --password=secure_password_here \
  --active=1 \
  --role=super-admin
```

### 2. 批量創建測試用戶（開發環境）

```php
// 在 seeder 或 tinker 中執行
User::factory()->count(10)->create();

// 創建不同角色的用戶
User::factory()->state(['role' => 'expert'])->count(3)->create();
User::factory()->state(['role' => 'crowdsourcing'])->count(5)->create();
```

### 3. 更新用戶角色

```bash
# 將用戶提升為專家
php artisan cbdb:manage-user --email=user@example.com --role=expert

# 激活用戶
php artisan cbdb:manage-user --email=user@example.com --active=1
```

### 4. 列出所有用戶

```bash
php artisan cbdb:manage-user --list
```

## 注意事項

1. **密碼安全**：生產環境中使用強密碼，避免在命令歷史中暴露密碼
2. **Email 唯一性**：Email 必須唯一，重複的 Email 會導致錯誤
3. **角色權限**：確保理解不同角色的權限差異，避免過度授權
4. **測試隔離**：測試中使用 Factory 創建的用戶會在測試後自動清理（使用 RefreshDatabase trait）
5. **confirmation_token**：在測試中創建用戶表時，記得處理此字段（nullable 或提供值）

## 參考資料

- `app/Console/Commands/ManageUser.php` - 用戶管理命令實現
- `database/factories/UserFactory.php` - User Factory 定義
- `app/Models/User.php` - User 模型
- `AGENTS.md` - 項目開發規範
