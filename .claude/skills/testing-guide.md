# PHPUnit 測試指南

## 何時使用此技能

當你需要編寫或運行測試時，使用此指南了解項目的測試策略和最佳實踐。

## PHPUnit 基本原則

### 測試環境配置

- **預設數據庫**：使用 SQLite 內存資料庫（若測試自行切換，記得還原）
- **CSRF 中間件**：Feature 測試已停用 CSRF middleware
- **測試隔離**：每個測試方法都應該獨立運行，不依賴其他測試

### 環境配置故障排查

#### SQLite 擴展缺失問題

如果運行測試時遇到以下錯誤：

```
could not find driver (Connection: sqlite, SQL: ...)
```

或

```
PDOException: could not find driver
```

這表示 PHP 缺少 SQLite 擴展。根據環境不同，有以下解決方案：

##### 方案 1：網絡環境下自動安裝（推薦）

如果環境支持網絡訪問，Session Start Hook（`.claude/hooks/session-start.md`）會自動檢測並安裝缺失的擴展。重新啟動會話即可。

##### 方案 2：網絡受限環境下手動安裝

本專案在 `.claude/php-extensions/` 目錄中提供了預編譯的 SQLite 擴展文件（支援 x86_64 和 aarch64 架構），可在網絡受限環境下使用。

**自動加載流程**：

Session Start Hook 會在每次會話啟動時：
1. 檢測當前系統架構（`uname -m`）
2. 檢查 `pdo_sqlite` 擴展是否已加載
3. 如果未加載且對應架構的擴展文件存在，自動軟鏈接到 PHP 擴展目錄
4. 驗證擴展是否成功加載

**手動驗證**：

```bash
# 檢查擴展文件是否存在
ls -lh .claude/php-extensions/$(uname -m)/

# 驗證擴展是否已加載
php -m | grep -i sqlite

# 應該看到：
# pdo_sqlite
# sqlite3
```

**獲取擴展文件**（如果目錄中沒有）：

如果 `.claude/php-extensions/` 目錄中沒有對應架構的擴展文件，可以在有網絡連接的環境中獲取：

```bash
# 檢查當前 PHP 版本和架構
php -v
uname -m

# x86_64 架構：下載並提取擴展
wget https://ppa.launchpadcontent.net/ondrej/php/ubuntu/pool/main/p/php8.4/php8.4-sqlite3_8.4.15-1+ubuntu24.04.1+deb.sury.org+1_amd64.deb
dpkg-deb -x php8.4-sqlite3_*.deb extracted/
cp extracted/usr/lib/php/20240924/pdo_sqlite.so .claude/php-extensions/x86_64/
cp extracted/usr/lib/php/20240924/sqlite3.so .claude/php-extensions/x86_64/

# aarch64 架構：使用 arm64.deb 替代 amd64.deb
```

**版本兼容性注意事項**：

- 擴展文件必須與當前 PHP 版本的 API 號匹配
- 檢查 PHP API 版本：`php -i | grep "PHP Extension"`
- 不同架構的擴展文件不可混用
- 本專案預設為 PHP 8.4.x（API 版本 20240924）

**常見錯誤**：

| 錯誤信息 | 原因 | 解決方案 |
|---------|------|---------|
| `Extension version does not match` | 擴展文件的 PHP API 版本與當前 PHP 不匹配 | 檢查 `php -i \| grep "PHP Extension"`，下載對應版本的擴展 |
| `Cannot load extension` | 架構不匹配或文件損壞 | 確認 `uname -m` 與擴展文件目錄一致，檢查文件完整性 |
| `Permission denied` | 文件權限不足 | 執行 `chmod 644 .claude/php-extensions/x86_64/*.so` |

詳細說明請參考 `.claude/php-extensions/README.md`。

### 運行測試

```bash
# 運行完整測試套件
./vendor/bin/phpunit

# 運行特定測試文件
./vendor/bin/phpunit tests/Feature/CodesControllerTest.php

# 運行特定測試方法
./vendor/bin/phpunit --filter testMethodName

# 運行測試並顯示詳細輸出
./vendor/bin/phpunit --verbose

# 運行測試並在第一次失敗時停止
./vendor/bin/phpunit --stop-on-failure
```

## In-Memory 數據庫測試模式（⭐ 推薦標準做法）

這是本項目推薦的測試方式，具有快速、可靠、不依賴外部數據庫的優勢。

### 基本設置

在測試類的 `setUp()` 方法中配置：

```php
use Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

class ExampleTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        // 配置 SQLite 內存數據庫
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // 創建測試所需的最小化表結構
        $this->createTestTables();

        // 預填充必要的測試數據
        $this->seedTestData();
    }

    private function createTestTables(): void {
        // 創建測試所需的表
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name');
            $table->string('password');
            $table->string('confirmation_token')->nullable();
            $table->tinyInteger('is_active')->default(0);
            $table->tinyInteger('is_admin')->default(0);
            $table->timestamps();
        });

        // 根據需要創建其他表...
    }

    private function seedTestData(): void {
        // 使用 DB::table()->insert() 預填充測試數據
        DB::table('users')->insert([
            'email' => 'test@example.com',
            'name' => 'Test User',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
```

### In-Memory 測試的優勢

1. ✅ **速度快**：內存數據庫性能優異
2. ✅ **可靠**：不依賴外部數據庫配置
3. ✅ **隔離性**：每個測試方法都有獨立的數據庫
4. ✅ **CI 友好**：在 CI 環境中無需配置數據庫
5. ✅ **簡潔**：只創建測試所需的最小化結構

### 遵循現有模式

參考項目中的標準範例：

- `tests/Feature/UserIpLoggingTest.php` - 標準 in-memory 測試範例
- `tests/Feature/WikiMaintenanceControllerTest.php` - In-memory SQLite 測試
- `tests/Feature/CodesControllerTest.php` - `/codes/*` 功能測試

## 避免複雜數據庫依賴

### ❌ 不要做的事情

1. **不要**依賴完整的 MySQL 數據庫遷移
   ```php
   // ❌ 錯誤：依賴完整 migration
   $this->artisan('migrate');
   ```

2. **不要**依賴複雜的外鍵約束和大型 schema
   ```php
   // ❌ 錯誤：創建不必要的複雜關聯
   Schema::create('complex_table', function (Blueprint $table) {
       $table->foreign('user_id')->references('id')->on('users')
           ->onDelete('cascade')->onUpdate('cascade');
       // ...大量複雜約束
   });
   ```

3. **不要**依賴真實的生產數據結構
   ```php
   // ❌ 錯誤：依賴完整表結構
   // 測試只需要驗證的字段即可
   ```

### ✅ 要做的事情

1. **要**創建測試所需的最小化表結構
   ```php
   // ✅ 正確：只創建測試需要的字段
   Schema::create('operations', function (Blueprint $table) {
       $table->id();
       $table->string('op_type');
       $table->text('resource_data');
       $table->timestamps();
   });
   ```

2. **要**模擬業務邏輯而非數據庫結構
   ```php
   // ✅ 正確：專注於業務邏輯測試
   $response = $this->actingAs($admin)
       ->post('/operations/restore/123');

   $this->assertDatabaseHas('operations', [
       'op_type' => '3', // 復原操作
   ]);
   ```

3. **要**使用 Factory 創建測試數據
   ```php
   // ✅ 正確：使用 Factory
   $user = User::factory()->active()->create();
   ```

## 測試編寫建議

### 測試覆蓋重點

1. **授權測試**：驗證權限控制
   ```php
   public function test_regular_user_cannot_restore_operations() {
       $user = User::factory()->create(); // 普通用戶

       $response = $this->actingAs($user)
           ->post('/operations/restore/123');

       $response->assertStatus(403);
   }
   ```

2. **Side Effect 測試**：驗證數據變動
   ```php
   public function test_restore_creates_new_operation_record() {
       $admin = User::factory()->active()->superAdmin()->create();

       $this->actingAs($admin)
           ->post('/operations/restore/123');

       $this->assertDatabaseHas('operations', [
           'op_type' => '3',
           'resource_id' => '123',
       ]);
   }
   ```

3. **例外情境測試**：測試資料缺失或查不到的情況
   ```php
   public function test_restore_non_existent_operation_returns_404() {
       $admin = User::factory()->active()->superAdmin()->create();

       $response = $this->actingAs($admin)
           ->post('/operations/restore/99999');

       $response->assertStatus(404);
   }
   ```

### Mock DB Transaction

如需 mock 數據庫事務：

```php
use Illuminate\Support\Facades\DB;
use Mockery;

public function test_transaction_rollback_on_error() {
    // 創建假的 DB connection
    $mockConnection = Mockery::mock('connection');
    $mockConnection->shouldReceive('beginTransaction')->once();
    $mockConnection->shouldReceive('rollBack')->once();

    DB::swap($mockConnection);

    // 測試代碼...
}
```

## 常見測試場景範例

### 1. Controller 授權測試

```php
use Tests\TestCase;
use App\Models\User;

class CodesControllerTest extends TestCase {
    public function test_guest_cannot_access_codes() {
        $response = $this->get('/codes');
        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_access_codes() {
        $user = User::factory()->active()->create();

        $response = $this->actingAs($user)->get('/codes');
        $response->assertStatus(200);
    }
}
```

### 2. Repository 測試

```php
use Tests\TestCase;
use App\Repositories\OperationRepository;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class OperationRepositoryTest extends TestCase {
    private $repository;

    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        Schema::create('operations', function (Blueprint $table) {
            $table->id();
            $table->string('op_type');
            $table->string('resource_id');
            $table->text('resource_data');
            $table->timestamps();
        });

        $this->repository = new OperationRepository();
    }

    public function test_store_creates_operation_record() {
        $data = [
            'op_type' => '1',
            'resource_id' => 'test-123',
            'resource_data' => json_encode(['test' => 'data']),
        ];

        $operation = $this->repository->store($data);

        $this->assertDatabaseHas('operations', [
            'op_type' => '1',
            'resource_id' => 'test-123',
        ]);
    }
}
```

### 3. 測試時提供用戶信息

某些功能需要登入用戶資訊（如 `ToolsRepository::timestamp()`）：

```php
public function test_office_update_requires_authenticated_user() {
    $user = User::factory()->active()->create([
        'name' => '測試用戶',
    ]);

    // 使用 actingAs() 提供用戶資訊
    $this->actingAs($user);

    // 執行需要用戶資訊的操作
    // ToolsRepository::timestamp() 將能取得登入者姓名
}
```

## 測試數據庫常見陷阱

### 1. 遺忘字段約束

```php
// ❌ 錯誤：遺忘 NOT NULL 字段
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('email');
    $table->string('confirmation_token'); // NOT NULL，但測試中可能為空
});

// ✅ 正確：設為 nullable 或提供預設值
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('email');
    $table->string('confirmation_token')->nullable();
});
```

### 2. 遺忘主鍵

```php
// ❌ 錯誤：手動創建表時遺忘主鍵
Schema::create('custom_table', function (Blueprint $table) {
    $table->integer('id'); // 沒有設為主鍵
    $table->string('name');
});

// ✅ 正確：設定主鍵
Schema::create('custom_table', function (Blueprint $table) {
    $table->id(); // 自動設為主鍵
    $table->string('name');
});
```

### 3. 遺忘 timestamps

```php
// ❌ 錯誤：Model 期望有 timestamps，但表沒有
Schema::create('operations', function (Blueprint $table) {
    $table->id();
    $table->string('op_type');
    // 沒有 timestamps
});

// ✅ 正確：加入 timestamps
Schema::create('operations', function (Blueprint $table) {
    $table->id();
    $table->string('op_type');
    $table->timestamps();
});
```

## PHPUnit 版本兼容性

本項目使用 **PHPUnit 10.1**，注意使用相容的斷言方法：

```php
// ✅ PHPUnit 10 正確用法
$this->assertStringContainsString('needle', $haystack);

// ❌ 舊版用法（已棄用）
$this->assertContains('needle', $haystack);
```

## 常用斷言方法

```php
// HTTP 響應斷言
$response->assertStatus(200);
$response->assertRedirect('/login');
$response->assertJson(['key' => 'value']);
$response->assertViewHas('variable', $value);

// 數據庫斷言
$this->assertDatabaseHas('users', ['email' => 'test@example.com']);
$this->assertDatabaseMissing('users', ['email' => 'deleted@example.com']);
$this->assertDatabaseCount('users', 5);

// 通用斷言
$this->assertTrue($condition);
$this->assertEquals($expected, $actual);
$this->assertStringContainsString('substring', $string);
$this->assertNull($value);
$this->assertInstanceOf(User::class, $object);
```

## 測試清單

編寫測試時，確保覆蓋：

- [ ] ✅ 授權：不同角色的權限測試
- [ ] ✅ 正常流程：功能正常運作
- [ ] ✅ 例外情況：資料不存在、無效輸入
- [ ] ✅ Side effects：數據庫變更、事件觸發
- [ ] ✅ 邊界條件：空值、極端值
- [ ] ✅ 錯誤處理：異常捕獲和回滾

## 參考資料

- `tests/Feature/CodesControllerTest.php` - `/codes/*` 功能測試範例
- `tests/Feature/OperationsRestoreAuthorizeTest.php` - 操作復原授權測試
- `tests/Feature/WikiMaintenanceControllerTest.php` - In-memory SQLite 標準範例
- `tests/Feature/UserIpLoggingTest.php` - In-memory 測試範例
- `phpunit.xml` - 測試環境設定
- `AGENTS.md` - 項目開發規範
