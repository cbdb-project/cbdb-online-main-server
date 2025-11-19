# Laravel 7-8-9 升級路線圖

## 目錄
- [總體規劃](#總體規劃)
- [Laravel 6.0 → 7.x 升級分析](#laravel-60--7x-升級分析)
- [Laravel 7.x → 8.x 升級分析](#laravel-7x--8x-升級分析)
- [Laravel 8.x → 9.x 升級分析](#laravel-8x--9x-升級分析)
- [建議升級策略](#建議升級策略)
- [風險評估](#風險評估)

---

## 總體規劃

### 當前狀態
```
✅ Laravel 6.0.45 + Carbon 2.x + PHP 7.4
```

### 升級路徑選項

#### 選項 1：漸進式升級（推薦）
```
當前：Laravel 6.0 (LTS) + PHP 7.4
  ↓ (階段 1)
Laravel 7.x + PHP 7.4
  ↓ (階段 2)
Laravel 8.x (LTS) + PHP 7.4
  ↓ (階段 3 - 需要 PHP 升級)
PHP 8.0+ 升級
  ↓ (階段 4)
Laravel 9.x + PHP 8.0+
  ↓ (長期目標)
Laravel 10.x (LTS) + PHP 8.1+
Laravel 11.x (LTS) + PHP 8.2+
```

**優點**：
- 每個階段變化可控
- 可以在每個版本停留並測試
- 問題容易定位和解決
- 團隊學習曲線較平緩

**缺點**：
- 需要多次升級
- 總時間較長
- 需要維護多個升級分支

#### 選項 2：跳躍式升級
```
當前：Laravel 6.0 + PHP 7.4
  ↓ (一次性升級)
Laravel 8.x (LTS) + PHP 7.4
  ↓
PHP 8.0+ 升級
  ↓
Laravel 9.x + PHP 8.0+
```

**優點**：
- 減少升級次數
- 可以直接跳到 LTS 版本
- 總時間較短

**缺點**：
- 單次變化較大
- 問題定位較困難
- 可能遇到多個版本的累積問題
- 風險較高

#### 選項 3：激進式升級（不建議）
```
當前：Laravel 6.0 + PHP 7.4
  ↓ (同時升級 PHP)
Laravel 9.x + PHP 8.0+
```

**不建議原因**：
- 同時升級 Laravel 和 PHP 風險極高
- 問題難以定位（是 Laravel 問題還是 PHP 問題？）
- 可能遇到相容性地雷
- 回滾困難

### PHP 版本需求對照表

| Laravel 版本 | 最低 PHP 版本 | 建議 PHP 版本 | 說明 |
|-------------|--------------|--------------|------|
| 6.x (LTS)   | 7.2.0        | 7.4          | ✅ 當前版本 |
| 7.x         | 7.2.5        | 7.4          | ✅ 可用 PHP 7.4 |
| 8.x (LTS)   | 7.3.0        | 7.4 / 8.0    | ✅ 可用 PHP 7.4 |
| 9.x         | 8.0.2        | 8.0 / 8.1    | ⚠️ 需要 PHP 8.0+ |
| 10.x (LTS)  | 8.1.0        | 8.1 / 8.2    | ⚠️ 需要 PHP 8.1+ |
| 11.x (LTS)  | 8.2.0        | 8.2 / 8.3    | ⚠️ 需要 PHP 8.2+ |

### 版本支援時間表

| 版本 | 發布日期 | Bug Fixes Until | Security Fixes Until | 狀態 |
|------|---------|-----------------|---------------------|------|
| 6 (LTS) | 2019-09-03 | 2021-09-03 | 2022-09-03 | ⚠️ 已停止支援 |
| 7 | 2020-03-03 | 2020-09-08 | 2021-03-03 | ❌ 已停止支援 |
| 8 (LTS) | 2020-09-08 | 2022-07-26 | 2023-09-06 | ⚠️ 已停止支援 |
| 9 | 2022-02-08 | 2023-08-08 | 2024-02-08 | ⚠️ 已停止支援 |
| 10 (LTS) | 2023-02-14 | 2025-08-06 | 2026-02-04 | ✅ 支援中 |
| 11 (LTS) | 2024-03-12 | 2026-09-03 | 2027-03-12 | ✅ 支援中 |

**重要提醒**：
- Laravel 6、7、8、9 都已停止官方支援
- 建議至少升級到 Laravel 10 (LTS) 以獲得安全更新
- Laravel 11 是目前最新的 LTS 版本

---

## Laravel 6.0 → 7.x 升級分析

### 環境需求變更

#### PHP 版本
- **最低版本**：7.2.0 → 7.2.5
- **建議版本**：7.4
- **影響**：✅ 當前 PHP 7.4 完全相容

#### Composer 依賴更新
```json
{
  "require": {
    "laravel/framework": "^7.0",
    "laravel/ui": "^2.0"  // 新增
  },
  "require-dev": {
    "facade/ignition": "^2.0",
    "nunomaduro/collision": "^4.1",
    "phpunit/phpunit": "^8.5"
  }
}
```

### 主要 Breaking Changes

#### 1. Symfony 5.x 升級 ⚠️ **重大影響**

**變更**：Laravel 7 將底層 Symfony 組件從 4.x 升級到 5.x。

**影響範圍**：
- 所有使用 Symfony 組件的地方
- Console 命令
- HTTP 核心
- 路由系統

**修復建議**：
- 大部分應用無需修改
- 檢查自訂的 Console 命令
- 檢查自訂的中介層

#### 2. Exception Handling 改變 ⚠️ **中等影響**

**變更**：`App\Exceptions\Handler` 的 `report` 和 `render` 方法需要接受 `Throwable` 而非 `Exception`。

**修改檔案**：`app/Exceptions/Handler.php`

**修復方式**：
```php
// ❌ 舊版（Laravel 6）
public function report(Exception $exception)
{
    parent::report($exception);
}

public function render($request, Exception $exception)
{
    return parent::render($request, $exception);
}

// ✅ 新版（Laravel 7）
use Throwable;

public function report(Throwable $exception)
{
    parent::report($exception);
}

public function render($request, Throwable $exception)
{
    return parent::render($request, $exception);
}
```

#### 3. Mail 配置變更 ⚠️ **中等影響**

**變更**：
- 郵件配置檔案結構改變
- `MAIL_DRIVER` → `MAIL_MAILER`
- 新增 `mailers` 陣列配置

**修改檔案**：
- `config/mail.php` - 需要更新配置結構
- `.env` - 環境變數名稱變更

**修復方式**：
```env
# ❌ 舊版
MAIL_DRIVER=smtp

# ✅ 新版
MAIL_MAILER=smtp
```

#### 4. Markdown Mail 模板 ⚠️ **低影響**

**變更**：
- 移除未文件化的 `promotion` 組件
- Markdown 模板現在期望未縮排的 HTML

**影響範圍**：
- 使用 Markdown 郵件模板的地方
- 自訂郵件視圖

**檢查檔案**：
- `resources/views/emails/**/*.blade.php`

#### 5. Route Model Binding ✅ **無影響**

**變更**：改善了路由模型綁定的行為

**影響**：向後相容，無需修改

### 新增功能亮點

#### 1. Laravel Airlock (現 Sanctum)
SPA 和移動應用的 API 認證解決方案

#### 2. HTTP Client
基於 Guzzle 的流暢 HTTP 客戶端

```php
use Illuminate\Support\Facades\Http;

$response = Http::get('https://api.example.com/users');
```

#### 3. Route Caching 改進
路由快取速度提升 2 倍

#### 4. CORS 支援
內建 CORS 中介層

#### 5. Query Time Casts
自訂資料庫查詢時的型別轉換

### 預估工作量

| 項目 | 預估時間 | 難度 |
|------|---------|------|
| Composer 依賴更新 | 1 小時 | 低 |
| Exception Handler 修改 | 0.5 小時 | 低 |
| Mail 配置更新 | 1 小時 | 中 |
| 測試驗證 | 2-3 小時 | 中 |
| **總計** | **4.5-5.5 小時** | **中** |

### 升級檢查清單

- [ ] 更新 composer.json 依賴版本
- [ ] 修改 Exception Handler (Throwable)
- [ ] 更新 Mail 配置和環境變數
- [ ] 檢查 Markdown 郵件模板
- [ ] 執行 composer update
- [ ] 清除快取 (config, route, view)
- [ ] 執行測試套件
- [ ] 檢查 Console 命令
- [ ] 驗證郵件發送功能
- [ ] 更新 .env.example

---

## Laravel 7.x → 8.x 升級分析

### 環境需求變更

#### PHP 版本
- **最低版本**：7.2.5 → 7.3.0
- **建議版本**：7.4 / 8.0
- **影響**：✅ 當前 PHP 7.4 完全相容

#### Composer 依賴更新
```json
{
  "require": {
    "laravel/framework": "^8.0",
    "laravel/ui": "^3.0",
    "guzzlehttp/guzzle": "^7.0"
  },
  "require-dev": {
    "facade/ignition": "^2.3.6",
    "nunomaduro/collision": "^5.0",
    "phpunit/phpunit": "^9.0"
  }
}
```

### 主要 Breaking Changes

#### 1. Model Factories 完全重寫 ⚠️ **重大影響**

**變更**：Laravel 8 完全重寫了 Model Factories，使用類別而非陣列。

**影響範圍**：
- 所有 `database/factories/*.php` 檔案
- 測試中使用 `factory()` 的地方

**舊版 Factory (Laravel 7)**：
```php
// database/factories/UserFactory.php
$factory->define(App\User::class, function (Faker $faker) {
    return [
        'name' => $faker->name,
        'email' => $faker->unique()->safeEmail,
    ];
});

// 使用
$user = factory(App\User::class)->create();
```

**新版 Factory (Laravel 8)**：
```php
// database/factories/UserFactory.php
namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition()
    {
        return [
            'name' => $this->faker->name,
            'email' => $this->faker->unique()->safeEmail,
        ];
    }
}

// 在 Model 中添加 HasFactory trait
use Illuminate\Database\Eloquent\Factories\HasFactory;

class User extends Model
{
    use HasFactory;
}

// 使用
$user = User::factory()->create();
```

**遷移策略**：
- 可以使用 `laravel/legacy-factories` 套件暫時保持向後相容
- 建議逐步遷移到新的 Factory 系統

#### 2. Database Seeders 命名空間 ⚠️ **中等影響**

**變更**：Seeders 現在使用命名空間。

**修改檔案**：`database/seeds/*.php` → `database/seeders/*.php`

**修復方式**：
```php
// ❌ 舊版（Laravel 7）
// database/seeds/DatabaseSeeder.php
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // ...
}

// ✅ 新版（Laravel 8）
// database/seeders/DatabaseSeeder.php
namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // ...
}
```

#### 3. Models 目錄結構 ⚠️ **中等影響**

**變更**：預設的 Models 移到 `app/Models` 目錄。

**影響範圍**：
- 所有 Model 檔案
- 命名空間從 `App\` 變為 `App\Models\`

**遷移選項**：
1. **保持原樣**（推薦）：Laravel 8 仍然支援 `app/` 下的 Models
2. **遷移到新結構**：移動所有 Models 到 `app/Models/`

**如果選擇遷移**：
```bash
# 創建 app/Models 目錄
mkdir app/Models

# 移動 Model 檔案
mv app/User.php app/Models/User.php

# 更新命名空間
# 從 namespace App;
# 改為 namespace App\Models;
```

#### 4. Maintenance Mode 改進 ⚠️ **低影響**

**變更**：`public/index.php` 需要添加維護模式檢查。

**修改檔案**：`public/index.php`

**添加程式碼**：
```php
// 在 LARAVEL_START 常數定義後添加
define('LARAVEL_START', microtime(true));

// 新增以下程式碼
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}
```

#### 5. Testing 改變 ⚠️ **低影響**

**變更**：`assertExactJson` 現在要求陣列鍵的順序完全一致。

**影響範圍**：
- 使用 `assertExactJson` 的測試

**修復方式**：
- 確保測試中的陣列鍵順序與回應一致
- 或改用 `assertJson`（不要求順序）

#### 6. Validation 規則 ⚠️ **低影響**

**變更**：`unique` 和 `exists` 規則現在會尊重 Model 的連線名稱。

**影響範圍**：
- 多資料庫連線的應用
- 使用 `unique` 或 `exists` 驗證規則

### 新增功能亮點

#### 1. Laravel Jetstream
應用腳手架，取代 Laravel UI

#### 2. Job Batching
批次隊列作業處理

```php
use Illuminate\Support\Facades\Bus;

Bus::batch([
    new ProcessPodcast($podcast),
    new ReleasePodcast($podcast),
])->dispatch();
```

#### 3. Rate Limiting 改進
更靈活的速率限制

#### 4. Time Testing Helpers
測試時間操作輔助函數

```php
// 時間旅行
$this->travel(5)->days();
```

#### 5. Dynamic Blade Components
動態 Blade 組件

### 預估工作量

| 項目 | 預估時間 | 難度 |
|------|---------|------|
| Composer 依賴更新 | 1 小時 | 低 |
| Model Factories 重寫 | 4-8 小時 | 高 |
| Seeders 命名空間 | 1-2 小時 | 低 |
| Maintenance Mode 更新 | 0.5 小時 | 低 |
| Models 目錄遷移（可選） | 2-4 小時 | 中 |
| 測試修復 | 2-3 小時 | 中 |
| **總計** | **10.5-18.5 小時** | **中-高** |

**說明**：
- 如果使用 `laravel/legacy-factories` 套件，可減少 3-6 小時
- 如果不遷移 Models 目錄，可減少 2-4 小時
- 最低工作量：約 6-8 小時

### 升級檢查清單

- [ ] 更新 composer.json 依賴版本
- [ ] 決定 Factory 遷移策略（新版 or legacy）
- [ ] 更新 Seeders 命名空間
- [ ] 更新 public/index.php (maintenance mode)
- [ ] 決定是否遷移 Models 目錄
- [ ] 執行 composer update
- [ ] 遷移 Model Factories（如果不使用 legacy）
- [ ] 修復測試中的 assertExactJson
- [ ] 清除快取
- [ ] 執行測試套件
- [ ] 驗證所有功能

---

## Laravel 8.x → 9.x 升級分析

### 環境需求變更 ⚠️ **重大變更**

#### PHP 版本
- **最低版本**：7.3.0 → **8.0.2**
- **建議版本**：8.0 / 8.1
- **影響**：❌ **需要升級 PHP！當前 PHP 7.4 不支援**

**重要提醒**：
- 這是最大的障礙！
- 需要先將 PHP 升級到 8.0+
- PHP 8.0 有許多新特性和 Breaking Changes
- 建議先在獨立環境測試 PHP 8.0 相容性

#### Composer 依賴更新
```json
{
  "require": {
    "laravel/framework": "^9.0",
    "guzzlehttp/guzzle": "^7.2"
  },
  "require-dev": {
    "spatie/laravel-ignition": "^1.0",  // 取代 facade/ignition
    "nunomaduro/collision": "^6.0",
    "phpunit/phpunit": "^9.5"
  }
}
```

### 主要 Breaking Changes

#### 1. PHP 8.0 必要性 ⚠️ **重大影響**

**原因**：
- Laravel 9 使用 Symfony 6.x
- Symfony 6.x 要求 PHP 8.0+
- Laravel 9 使用 PHP 8.0 的新特性

**PHP 8.0 的主要變更**：

1. **Named Arguments**（命名參數）
```php
// PHP 8.0+
function test($a, $b, $c) {}
test(c: 'c', a: 'a', b: 'b');  // 可以跳過順序
```

2. **Union Types**（聯合型別）
```php
function foo(int|float $number) {}
```

3. **Nullsafe Operator**（空安全運算子）
```php
// 舊版
$country = null;
if ($session !== null) {
    $user = $session->user;
    if ($user !== null) {
        $address = $user->getAddress();
        if ($address !== null) {
            $country = $address->country;
        }
    }
}

// PHP 8.0+
$country = $session?->user?->getAddress()?->country;
```

4. **Match Expression**（配對表達式）
```php
// 舊版
switch ($type) {
    case 'A': return 1;
    case 'B': return 2;
    default: return 0;
}

// PHP 8.0+
return match($type) {
    'A' => 1,
    'B' => 2,
    default => 0,
};
```

5. **Constructor Property Promotion**（建構子屬性提升）
```php
// 舊版
class Point {
    public float $x;
    public float $y;

    public function __construct(float $x, float $y) {
        $this->x = $x;
        $this->y = $y;
    }
}

// PHP 8.0+
class Point {
    public function __construct(
        public float $x,
        public float $y,
    ) {}
}
```

**PHP 8.0 的 Breaking Changes**：
- 未定義的變數會拋出 Warning
- 除以零會拋出異常
- 字串和數字的比較更嚴格
- 許多函數參數不再接受 null
- 等等...

#### 2. Return Type Declarations ⚠️ **中等影響**

**變更**：Laravel 9 在許多方法上添加了返回型別宣告。

**影響範圍**：
- 繼承 Laravel 類別的自訂類別
- 實作 Laravel 介面的類別

**範例**：
```php
// Laravel 9 要求明確的返回型別
public function handle(): int
{
    return 0;
}
```

#### 3. Flysystem 3.x 升級 ⚠️ **中等影響**

**變更**：檔案系統從 Flysystem 1.x 升級到 3.x。

**影響範圍**：
- 檔案上傳/下載
- Storage facade 使用
- 自訂檔案系統驅動

**主要變更**：
```php
// 某些方法簽名改變
// 某些回傳值改變
// 某些異常類型改變
```

#### 4. Ignition 套件更換 ⚠️ **低影響**

**變更**：`facade/ignition` → `spatie/laravel-ignition`

**修復**：更新 composer.json 即可

#### 5. Trusted Proxies 中介層 ⚠️ **低影響**

**變更**：`TrustedProxies` 中介層命名空間改變。

**修復方式**：
```php
// ❌ 舊版
use Fideloper\Proxy\TrustProxies;

// ✅ 新版
use Illuminate\Http\Middleware\TrustProxies;
```

#### 6. Serializable Closure ⚠️ **低影響**

**變更**：`opis/closure` → `laravel/serializable-closure`

**影響**：自動處理，通常無需修改

### 新增功能亮點

#### 1. Anonymous Migration
匿名遷移，避免類別名稱衝突

```php
// 不再需要類別名稱
return new class extends Migration
{
    public function up() {}
};
```

#### 2. Controller Route Groups
控制器路由群組

```php
Route::controller(OrderController::class)->group(function () {
    Route::get('/orders/{id}', 'show');
    Route::post('/orders', 'store');
});
```

#### 3. Improved Eloquent Accessors/Mutators
改進的存取器和修改器

```php
use Illuminate\Database\Eloquent\Casts\Attribute;

protected function name(): Attribute
{
    return Attribute::make(
        get: fn ($value) => strtoupper($value),
        set: fn ($value) => strtolower($value),
    );
}
```

#### 4. Enum Casting
原生支援 PHP 8.1 Enum

```php
protected $casts = [
    'status' => Status::class,  // PHP 8.1 Enum
];
```

### 預估工作量

| 項目 | 預估時間 | 難度 |
|------|---------|------|
| **PHP 8.0 升級準備** | **8-16 小時** | **高** |
| PHP 8.0 相容性測試 | 4-8 小時 | 高 |
| 修復 PHP 8.0 相關問題 | 4-8 小時 | 高 |
| Composer 依賴更新 | 1 小時 | 低 |
| Return Type 添加 | 2-4 小時 | 中 |
| Flysystem 相容性檢查 | 1-2 小時 | 低 |
| Trusted Proxies 更新 | 0.5 小時 | 低 |
| 測試修復 | 2-3 小時 | 中 |
| **總計** | **18.5-34.5 小時** | **高** |

**重要說明**：
- PHP 8.0 升級是最大的挑戰
- 建議先在開發環境測試 PHP 8.0
- 可能需要更新第三方套件
- 某些套件可能不支援 PHP 8.0

### 升級檢查清單

#### 階段 1：PHP 8.0 準備（必須先完成）
- [ ] 在開發環境安裝 PHP 8.0
- [ ] 執行現有測試（PHP 8.0 環境）
- [ ] 修復 PHP 8.0 相容性問題
- [ ] 檢查所有第三方套件的 PHP 8.0 支援
- [ ] 更新不相容的套件

#### 階段 2：Laravel 9 升級
- [ ] 確認 PHP 8.0 完全相容
- [ ] 更新 composer.json 依賴版本
- [ ] 更新 Trusted Proxies 中介層
- [ ] 添加必要的 Return Type 宣告
- [ ] 執行 composer update
- [ ] 測試 Flysystem 相關功能
- [ ] 清除快取
- [ ] 執行測試套件
- [ ] 驗證所有功能

---

## 建議升級策略

### 策略 A：穩健漸進式（推薦）

**適合**：
- 大型生產系統
- 對穩定性要求高
- 有充足時間規劃

**步驟**：
```
第 1 季：Laravel 6 → 7
  - 工作量：~5 小時
  - 風險：低
  - PHP：保持 7.4

第 2 季：Laravel 7 → 8
  - 工作量：~15 小時
  - 風險：中
  - PHP：保持 7.4

第 3 季：PHP 7.4 → 8.0
  - 工作量：~15 小時
  - 風險：高
  - Laravel：保持 8

第 4 季：Laravel 8 → 9
  - 工作量：~10 小時
  - 風險：中
  - PHP：已升級 8.0

總計：~45 小時，分散 4 個季度
```

**優點**：
- 每個階段風險可控
- 問題容易定位
- 團隊學習時間充足
- 可以在每個版本穩定後再進行下一步

**缺點**：
- 總時間最長
- 需要維護多個版本

### 策略 B：跳躍式（平衡）

**適合**：
- 中型系統
- 希望快速到達 LTS 版本
- 有一定測試覆蓋率

**步驟**：
```
第 1 階段：Laravel 6 → 8
  - 工作量：~20 小時
  - 風險：中-高
  - PHP：保持 7.4
  - 說明：跳過 Laravel 7

第 2 階段：PHP 7.4 → 8.0
  - 工作量：~15 小時
  - 風險：高
  - Laravel：保持 8

第 3 階段：Laravel 8 → 9
  - 工作量：~10 小時
  - 風險：中
  - PHP：已升級 8.0

總計：~45 小時，分散 3 個階段
```

**優點**：
- 較快到達目標版本
- 減少升級次數
- 總工時相近

**缺點**：
- 單次變化較大
- 問題定位稍難

### 策略 C：直達目標（不建議用於 Laravel 9）

**不建議原因**：
- PHP 8.0 升級風險太高
- 同時升級 Laravel 和 PHP 會讓問題難以定位
- 如果遇到問題，不確定是 Laravel 問題還是 PHP 問題

**替代方案**：如果希望快速升級，建議：
1. 先升級到 Laravel 8 (PHP 7.4)
2. 停留並測試穩定
3. 再考慮 PHP 8.0 + Laravel 9/10

### 策略 D：直達 Laravel 10 LTS（長期規劃）

如果決定升級，建議直接規劃到 Laravel 10 (LTS)：

```
當前：Laravel 6 (LTS) + PHP 7.4
  ↓
階段 1：Laravel 6 → 8 (LTS)
  工作量：~20 小時
  PHP：保持 7.4

階段 2：PHP 7.4 → 8.1
  工作量：~20 小時
  Laravel：保持 8

階段 3：Laravel 8 → 10 (LTS)
  工作量：~15 小時
  PHP：已升級 8.1
  說明：跳過 Laravel 9

總計：~55 小時
優點：直達最新 LTS，長期支援到 2026
```

---

## 風險評估

### Laravel 6 → 7 升級風險

| 風險項目 | 等級 | 說明 | 緩解措施 |
|---------|------|------|---------|
| PHP 相容性 | 🟢 低 | PHP 7.4 完全支援 | 無需特別處理 |
| Exception Handling | 🟡 中 | 需要修改 Handler | 簡單修改，測試覆蓋 |
| Mail 配置 | 🟡 中 | 配置結構變更 | 參考文件，逐步遷移 |
| Symfony 升級 | 🟡 中 | 底層升級 | 主要影響自訂功能 |
| 向後相容性 | 🟢 低 | 大部分向後相容 | 執行完整測試 |
| **總體風險** | 🟡 **中** | **可控，建議優先執行** | - |

### Laravel 7 → 8 升級風險

| 風險項目 | 等級 | 說明 | 緩解措施 |
|---------|------|------|---------|
| PHP 相容性 | 🟢 低 | PHP 7.4 完全支援 | 無需特別處理 |
| Model Factories | 🔴 高 | 完全重寫，工作量大 | 可使用 legacy 套件過渡 |
| Seeders | 🟡 中 | 命名空間變更 | 批次處理，測試驗證 |
| Models 目錄 | 🟢 低 | 可選遷移 | 建議保持原狀 |
| Testing | 🟡 中 | 部分測試需修改 | 逐一修復 |
| **總體風險** | 🟡 **中-高** | **Factory 是主要挑戰** | **使用 legacy 套件** |

### Laravel 8 → 9 升級風險

| 風險項目 | 等級 | 說明 | 緩解措施 |
|---------|------|------|---------|
| **PHP 8.0 升級** | 🔴 **極高** | **必須升級 PHP** | **獨立測試環境，充分測試** |
| PHP 8.0 相容性 | 🔴 高 | 程式碼可能需大幅修改 | 提前在 PHP 8.0 環境測試 |
| 第三方套件 | 🔴 高 | 可能不支援 PHP 8.0 | 提前檢查，尋找替代方案 |
| Flysystem 升級 | 🟡 中 | API 變更 | 測試所有檔案操作 |
| Return Types | 🟡 中 | 需添加型別宣告 | 使用 IDE 輔助 |
| **總體風險** | 🔴 **高** | **PHP 8.0 是最大障礙** | **分階段，PHP 先行** |

### PHP 版本升級風險對照

| 升級路徑 | 風險等級 | 說明 |
|---------|---------|------|
| PHP 7.4 (當前) | - | 穩定版本 |
| PHP 7.4 → 8.0 | 🔴 極高 | 大版本升級，許多 BC breaks |
| PHP 8.0 → 8.1 | 🟡 中 | 小版本升級，相對平穩 |
| PHP 8.1 → 8.2 | 🟡 中 | 小版本升級，相對平穩 |

### 建議優先級

根據風險和效益分析：

#### 第一優先：Laravel 6 → 8 (PHP 7.4)
- ✅ 保持在支援的 PHP 版本
- ✅ 到達 LTS 版本
- ✅ 風險可控
- ✅ 效益高
- ⏱️ 工作量：~20-25 小時

#### 第二優先：評估 PHP 8.0 升級
- ⚠️ 需要獨立評估
- ⚠️ 風險較高
- ⚠️ 可能影響第三方套件
- ⏱️ 評估時間：~5-8 小時
- ⏱️ 升級時間：~15-20 小時

#### 第三優先：Laravel 8 → 9/10
- 前提：PHP 8.0 升級完成且穩定
- ✅ 到達現代 Laravel 版本
- ✅ 獲得長期支援
- ⏱️ 工作量：~15-20 小時

---

## 總結與建議

### 關鍵決策點

#### 1. 是否要升級到 Laravel 9+？

**YES（升級到 9+）**，如果：
- 需要長期官方支援（Laravel 10/11 LTS）
- 想使用 PHP 8+ 的新特性
- 有充足的測試覆蓋
- 有時間進行充分測試
- 團隊有 PHP 8 經驗

**NO（停留在 Laravel 8）**，如果：
- 必須使用 PHP 7.4
- 第三方套件不支援 PHP 8
- 時間和資源有限
- 系統非常穩定，不想冒險
- Laravel 8 已經足夠使用

#### 2. 選擇哪個升級策略？

**建議：分兩個大階段**

```
階段 1（近期，3-6 個月內）：
Laravel 6 → 8 (保持 PHP 7.4)
- 工作量：~20-25 小時
- 風險：中
- 效益：到達 LTS，穩定性提升

階段 2（中期，6-12 個月後）：
評估並執行 PHP 8 + Laravel 10 升級
- 前置評估：~8 小時
- PHP 8 升級：~15-20 小時
- Laravel 8→10：~15-20 小時
- 總工作量：~40-50 小時
- 風險：高
- 效益：現代化，長期支援到 2026
```

### 最小化風險的建議

#### 1. 測試環境
```
開發環境（本地）
  ↓ 測試通過
Staging 環境
  ↓ 測試通過
預生產環境
  ↓ 測試通過
生產環境
```

#### 2. 測試策略
- ✅ 自動化測試覆蓋率 > 70%
- ✅ 關鍵業務流程手動測試
- ✅ 效能測試
- ✅ 安全性測試
- ✅ 相容性測試

#### 3. 回滾計劃
- ✅ 資料庫備份
- ✅ 程式碼版本控制
- ✅ 快速回滾腳本
- ✅ 降級路徑文件

#### 4. 監控
- ✅ 錯誤日誌監控
- ✅ 效能監控
- ✅ 使用者回饋渠道

### 下一步行動

#### 立即執行（本週）：
1. 創建 Laravel 7 升級分支
2. 在開發環境測試 Laravel 7 升級
3. 評估升級工作量
4. 制定詳細時間表

#### 短期（1-2 個月）：
1. 完成 Laravel 6 → 7 升級
2. 開始 Laravel 7 → 8 準備
3. 評估 PHP 8 相容性

#### 中期（3-6 個月）：
1. 完成 Laravel 8 升級
2. 在 Laravel 8 穩定運行
3. 評估 PHP 8 升級可行性

#### 長期（6-12 個月）：
1. 執行 PHP 8 升級（如果可行）
2. 規劃 Laravel 10 升級
3. 實現完整的現代化堆疊

---

## 附錄

### 有用的工具

#### 1. Laravel Shift
- 自動化升級服務
- https://laravelshift.com/
- 費用：$9-29 每次升級
- 優點：自動化，節省時間
- 缺點：需要付費，可能需要手動調整

#### 2. Rector
- PHP 自動重構工具
- 可以幫助 PHP 版本升級
- https://github.com/rectorphp/rector

#### 3. PHPStan / Larastan
- 靜態分析工具
- 提前發現潛在問題
- https://github.com/nunomaduro/larastan

### 參考連結

- [Laravel 7.x 升級指南](https://laravel.com/docs/7.x/upgrade)
- [Laravel 8.x 升級指南](https://laravel.com/docs/8.x/upgrade)
- [Laravel 9.x 升級指南](https://laravel.com/docs/9.x/upgrade)
- [Laravel 10.x 升級指南](https://laravel.com/docs/10.x/upgrade)
- [PHP 8.0 遷移指南](https://www.php.net/manual/en/migration80.php)
- [PHP 8.1 遷移指南](https://www.php.net/manual/en/migration81.php)

---

**文檔版本**：1.0
**建立日期**：2025-11-19
**適用於**：Laravel 6.0.45 (當前版本)
**維護者**：CBDB 開發團隊
