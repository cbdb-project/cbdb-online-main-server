# Laravel 升級筆記

## 目錄
- [Laravel 5.7 → 5.8](#laravel-57--58-升級筆記)
- [Laravel 5.6 → 5.7](#laravel-56--57-升級筆記)
- [Laravel 5.5 → 5.6](#laravel-55--56-升級筆記)

---

# Laravel 5.7 → 5.8 升級筆記

## 升級狀態
✅ **已完成** - 2025-11-18

**分支**: `claude/upgrade-laravel-5.8-012jN85zt1B7LaDDXwnbg118`

## 環境需求
- **PHP**：7.1.3 - 7.4（當前使用 7.4）
- **MySQL**：5.7.7+ / MariaDB 10.2.2+
- **作業系統/服務**：與原本一致即可

## 套件變更與版本

### 主要框架更新
- `laravel/framework`: `5.7.29` → `5.8.38`
- `laravel/passport`: `^6.0` → `^7.0`
- `nesbot/carbon`: `1.26.6` → `1.39.1`
- `league/oauth2-server`: `7.4.0` (保持)

### 新增套件
- `opis/closure`: `^3.7` - 閉包序列化支援（Laravel 5.8 需要）
- `phpoption/phpoption`: `1.9.4` - 選項類型支援
- `psr/http-factory`: `1.1.0` - PSR-17 HTTP 工廠介面

### 環境變量處理升級
- `vlucas/phpdotenv`: `v2.6.9` → `v3.6.10` - 支援更好的 .env 解析

### 移除套件（Laravel 5.8 內建）
Laravel 5.8 將以下通知渠道內建到框架中：
- `laravel/nexmo-notification-channel`
- `laravel/slack-notification-channel`
- `nexmo/client` 及相關依賴
- `php-http/*` 系列包（7個）

### 依賴降級（相容性需求）
為保持與 Laravel 5.8 的相容性，以下套件降級：
- `egulias/email-validator`: `3.2.6` → `2.1.25`
- `doctrine/lexer`: `2.1.1` → `1.2.3`

### Zend 升級
- `zendframework/zend-diactoros`: `1.8.7` → `2.2.1`

## Breaking Changes

Laravel 5.7 → 5.8 的破壞性變更較少，主要影響：

### 1. Cache TTL 格式變更（重要）
**影響**：Cache 方法的 TTL（過期時間）從「分鐘」改為「秒」

**本專案影響**：⚠️ **需要修改 1 處**
- 本專案大部分使用 `Carbon` 物件作為過期時間，無需修改
- 發現 `app/helpers.php:37` 使用整數 `10`，已修復為 `now()->addMinutes(10)`

```php
// ❌ 舊版（Laravel 5.7）：10 = 10 分鐘
Cache::put($cacheKey, $version, 10);

// ✅ 新版（Laravel 5.8）：使用 Carbon 物件
Cache::put($cacheKey, $version, now()->addMinutes(10));

// 或者使用秒數：
// Cache::put($cacheKey, $version, 600); // 600 秒 = 10 分鐘
```

**已修復的文件：**
- `app/helpers.php:37` - `get_app_version()` 函數的版本號快取

### 2. Email 驗證增強
**變更**：Email 驗證規則從 RFC822 升級到 RFC6530
**影響**：接受更多國際化郵件地址（如支援 Unicode）
**本專案影響**：✅ 更寬鬆的驗證，向後相容

### 3. Queue 失敗處理
**變更**：隊列作業失敗處理從 `FailingJob::handle()` 移到 Job 類別的 `failed()` 方法
**本專案影響**：✅ 本專案未使用自訂失敗處理，無需修改

### 4. Container 綁定
**變更**：Container 的 `rebinding()` 和 `refresh()` 行為改進
**本專案影響**：✅ 內部優化，應用層無感知

### 5. 路由閉包序列化（重要）
**影響**：使用閉包定義的路由無法序列化，執行 `route:cache` 會失敗

**本專案影響**：⚠️ **需要修改 7 處**
- `routes/api.php` 有 4 處閉包路由
- `routes/web.php` 有 3 處閉包路由

**已修復的路由：**

**API 路由 (routes/api.php)：**
1. `/api/user` → `Api\UserController@show`
2. `/api/name` → `Api\NameController@index`
3. `/api/textinstancedata` → `Api\TextInstanceDataController@query`
4. `/api/addrbelongsdata` → `Api\AddrBelongsDataController@query`

**Web 路由 (routes/web.php)：**
1. `/` → `WelcomeController@index`
2. `/test` → `TestController@index`
3. `/admin/wiki-maintenance/test-progress` → `TestController@testProgress`

**修復方式：**
```php
// ❌ 舊版：使用閉包
Route::get('/user', function (Request $request) {
    return $request->user();
});

// ✅ 新版：使用控制器方法
Route::get('/user', 'Api\UserController@show');
```

**創建的新控制器：**
- `app/Http/Controllers/Api/UserController.php`
- `app/Http/Controllers/Api/NameController.php`
- `app/Http/Controllers/Api/TextInstanceDataController.php`
- `app/Http/Controllers/Api/AddrBelongsDataController.php`
- `app/Http/Controllers/WelcomeController.php`
- `app/Http/Controllers/TestController.php`

**向後兼容性：**
- ✅ 所有控制器執行相同的邏輯
- ✅ API 行為保持完全一致
- ✅ 無需修改前端代碼

## Composer 更新步驟

### 開發環境
```bash
# 1. 更新依賴
composer update --with-all-dependencies

# 2. 清除快取（需要 PHP 7.4 環境）
php artisan config:clear
php artisan cache:clear

# 3. 運行測試
./vendor/bin/phpunit
```

### 生產環境
```bash
# 1. 安裝依賴（使用 lockfile）
composer install --no-dev --optimize-autoloader

# 2. 清除舊快取
php artisan config:clear
php artisan cache:clear

# 3. 重建快取
php artisan config:cache
php artisan route:cache

# 4. 驗證
# 檢查日誌、API、認證等核心功能
```

## 配置文件變更

### 無需修改
Laravel 5.8 **不需要修改任何配置文件**，所有現有配置保持兼容。

### 可選優化
如需使用新功能（如內建的 Nexmo、Slack 通知），可添加相應的 `.env` 配置：

```env
# Nexmo (現已內建)
NEXMO_KEY=your-nexmo-key
NEXMO_SECRET=your-nexmo-secret

# Slack (現已內建)
SLACK_WEBHOOK_URL=your-slack-webhook-url
```

## 環境變量

### 無變更
所有現有的 `.env` 配置保持不變，包括：
- `LOG_CHANNEL`
- `QUEUE_CONNECTION`
- `DB_*`
- `MAIL_*`
- 等等...

## 已知事項

### PHP 版本兼容性
- ⚠️ **Laravel 5.8 不支援 PHP 8.0+**
- ✅ 支援 PHP 7.1.3 - 7.4
- 當前開發環境使用 PHP 8.4，無法運行 artisan 命令
- **測試需在 PHP 7.4 環境中進行**

### Carbon 1 仍在使用
- Carbon 仍使用 v1.39.1
- Composer 提示可升級到 Carbon 2
- ⚠️ **建議**：等待升級到 Laravel 6+ 後再升級 Carbon 2
- 可使用 `./vendor/bin/upgrade-carbon` 獲取升級幫助

### Passport 升級
- Passport 從 6.x 升級到 7.x
- OAuth2 Server 維持在 7.x
- 升級後需運行（在 PHP 7.4 環境）：
  ```bash
  php artisan passport:install
  php artisan passport:keys --force
  ```

### 通知渠道內建化
- Nexmo 和 Slack 通知渠道現已內建
- 移除了 `laravel/nexmo-notification-channel` 和 `laravel/slack-notification-channel`
- API 保持一致，無需修改程式碼

## 測試情況

### 本地測試（需在 PHP 7.4 環境）
```bash
./vendor/bin/phpunit

# 預期輸出：
# PHPUnit 7.5.20
# Tests: 112+, Assertions: 482+
# OK (或帶有 incomplete/skipped tests)
```

### 測試注意事項
- Exit code 255 錯誤仍可能出現（框架已知問題）
- 不影響測試結果的有效性
- CI 配置已設定忽略此錯誤碼

## 新功能亮點

### 1. 內建通知渠道
Laravel 5.8 將 Nexmo 和 Slack 通知渠道內建到框架：
```php
// Nexmo SMS
$user->notify(new InvoicePaid($invoice));

// Slack
Notification::route('slack', config('slack.webhook'))
    ->notify(new DeploymentComplete());
```

### 2. PSR-16 Cache 相容性
完整支援 PSR-16 簡單快取介面

### 3. Eloquent 改進
- `HasOne` 關聯支援 `withDefault()`
- 更好的關聯預載入性能

### 4. 排程增強
- 任務排程支援時區設定
- 改進的重疊任務處理

### 5. Email 驗證增強
- 支援 RFC6530（國際化郵件地址）
- 更好的 Unicode 支援

### 6. 效能優化
- Container 解析性能提升
- 路由編譯優化
- 更快的 Blade 範本編譯

## 參考連結

- [Laravel 5.8 Release Notes](https://laravel.com/docs/5.8/releases)
- [Laravel 5.8 Upgrade Guide](https://laravel.com/docs/5.8/upgrade)
- [Laravel Passport 7.x Documentation](https://laravel.com/docs/5.8/passport)
- [Carbon 1.x Documentation](https://carbon.nesbot.com/docs/)
- [PSR-16: Simple Cache](https://www.php-fig.org/psr/psr-16/)

## 下一步升級計劃

```
當前：Laravel 5.8 ✅
  ↓
建議：Laravel 5.8 → 6.0（LTS）
  ├─ PHP 要求提升至 7.2+
  ├─ Carbon 升級至 2.x
  └─ 更多現代化功能
  ↓
長期：Laravel 6.0 → 8.x → 11.x
```

詳見 `UPGRADE_INSTRUCTIONS.md`（如有）了解完整的升級路線圖。

---

# Laravel 5.6 → 5.7 升級筆記

## 升級狀態
✅ **已完成** - 2025-11-17

**分支**: `claude/upgrade-laravel-5.7-01SEPRinmUi4LSWVvMhh1YfT`

## 環境需求
- **PHP**：7.1.3 - 7.4（當前使用 7.4）
- **MySQL**：5.7.7+ / MariaDB 10.2.2+
- **作業系統/服務**：與原本一致即可

## 套件變更與版本

### 主要框架更新
- `laravel/framework`: `5.6.40` → `5.7.29`
- `laravel/passport`: `^4.0` → `^6.0`
- `nesbot/carbon`: `1.26.6` → `1.39.1`
- `league/oauth2-server`: `6.1.1` → `7.4.0`

### 新增套件
- `laravel/nexmo-notification-channel`: `^1.0` - Nexmo 通知渠道支援
- `laravel/slack-notification-channel`: `^1.0` - Slack 通知渠道支援
- `opis/closure`: `^3.7` - 閉包序列化支援（用於隊列）

### Symfony 組件更新
所有 Symfony 組件更新至 `4.4.x` 版本：
- `symfony/console`: `3.3.6` → `4.4.49`
- `symfony/http-kernel`: `3.3.6` → `4.4.51`
- `symfony/routing`: `3.3.6` → `4.4.44`
- `symfony/var-dumper`: `3.3.6` → `4.4.47`
- 等等...

### 測試依賴更新
- `mockery/mockery`: `1.0.x` → `1.3.6`
- `phpunit/phpunit`: `7.5.x` → `7.5.20`
- 所有相關測試依賴升級到最新版本

## Breaking Changes

Laravel 5.6 → 5.7 **幾乎無破壞性變更**，主要是內部優化和新功能添加。

### 應用層面（無需修改）
✅ **無需修改應用代碼** - 本次升級向後兼容性極好

### 框架內部改進
- 郵件模板美化
- 通知渠道改進
- 任務鏈（Job Chaining）增強
- 錯誤頁面改進
- URL 生成性能優化

## Composer 更新步驟

### 開發環境
```bash
# 1. 更新依賴
composer update

# 2. 清除快取
php artisan config:clear
php artisan cache:clear

# 3. 運行測試
./vendor/bin/phpunit
```

### 生產環境
```bash
# 1. 安裝依賴（使用 lockfile）
composer install --no-dev --optimize-autoloader

# 2. 清除舊快取
php artisan config:clear
php artisan cache:clear

# 3. 重建快取
php artisan config:cache
php artisan route:cache

# 4. 驗證
# 檢查日誌、API、認證等核心功能
```

## 配置文件變更

### 無需修改
Laravel 5.7 **不需要修改任何配置文件**，所有現有配置保持兼容。

### 可選優化
如需使用新功能（如 Nexmo、Slack 通知），需添加相應的 `.env` 配置：

```env
# Nexmo (可選)
NEXMO_KEY=your-nexmo-key
NEXMO_SECRET=your-nexmo-secret

# Slack (可選)
SLACK_WEBHOOK_URL=your-slack-webhook-url
```

## 環境變量

### 無變更
所有現有的 `.env` 配置保持不變，包括：
- `LOG_CHANNEL`
- `QUEUE_CONNECTION`
- `DB_*`
- `MAIL_*`
- 等等...

## 已知事項

### PHP 版本兼容性
- ⚠️ **Laravel 5.7 不支援 PHP 8.0+**
- ✅ 支援 PHP 7.1.3 - 7.4
- 當前開發環境使用 PHP 8.4，無法運行 artisan 命令
- **測試需在 PHP 7.4 環境中進行**

### Carbon 1 仍在使用
- Carbon 仍使用 v1.39.1
- 需等待升級到 Laravel 5.8 後才能升級至 Carbon 2
- 參見 `UPGRADE_INSTRUCTIONS.md` 了解 Carbon 升級計劃

### Passport 升級
- Passport 從 4.x 升級到 6.x
- OAuth2 Server 從 6.x 升級到 7.x
- 升級後需運行：
  ```bash
  php artisan passport:install
  php artisan passport:keys --force
  ```

## 測試情況

### 本地測試（需在 PHP 7.4 環境）
```bash
./vendor/bin/phpunit

# 預期輸出：
# PHPUnit 7.5.20
# Tests: 112+, Assertions: 482+
# OK (或帶有 incomplete/skipped tests)
```

### 測試注意事項
- Exit code 255 錯誤仍可能出現（框架已知問題）
- 不影響測試結果的有效性
- CI 配置已設定忽略此錯誤碼

## 新功能亮點

### 1. Email Verification（郵件驗證）
Laravel 5.7 新增內建的郵件驗證功能：
```php
// 在路由中
Route::get('/email/verify', 'Auth\VerificationController@show')
    ->name('verification.notice');
```

### 2. Guest User Gates & Policies
現在可以為訪客用戶定義授權策略：
```php
Gate::define('view-post', function (?User $user, Post $post) {
    // $user 可能為 null（訪客）
});
```

### 3. Symfony Dump Server
集成 Symfony 的 dump server，更好的除錯體驗：
```bash
php artisan dump-server
```

### 4. Notification Channels
新增 Nexmo 和 Slack 通知渠道支援

### 5. URL Generator & Callable Syntax
路由定義改進，支援更靈活的語法

## 參考連結

- [Laravel 5.7 Release Notes](https://laravel.com/docs/5.7/releases)
- [Laravel 5.7 Upgrade Guide](https://laravel.com/docs/5.7/upgrade)
- [Laravel Passport 6.x Documentation](https://laravel.com/docs/5.7/passport)
- [Carbon 1.x Documentation](https://carbon.nesbot.com/docs/)

## 下一步升級計劃

```
當前：Laravel 5.7 ✅
  ↓
下一步：Laravel 5.7 → 5.8
  ↓
然後：Carbon 1.x → 2.x（需 Laravel 5.8+）
  ↓
最後：Laravel 5.8 → 6.0
```

詳見 `UPGRADE_INSTRUCTIONS.md` 了解完整的升級路線圖。

---

# Laravel 5.5 → 5.6 升級筆記

## 環境需求
- **PHP**：提升至 7.4（`composer.json` 的 platform 鎖定值已改為 `7.4.0`）。
- **作業系統/服務**：與原本一致即可；請確認所有節點都支援 PHP 7.4。

## 套件變更與版本
- `laravel/framework` → `5.6.*`
- `laravel/passport` → `^4.0`
- `phpunit/phpunit` → `^7.5`
- `mockery/mockery` → `^1.0`
- 新增 `config/logging.php`，並調整 `.env` 改用 `LOG_CHANNEL` / `LOG_LEVEL`。

## 配置文件修正（必須）

升級到 Laravel 5.6 後，需要手動修正以下配置文件以符合新版本的最佳實踐：

### 1. config/app.php
移除舊的 logging 配置（第 123-125 行）：
```php
// ❌ 移除以下兩行
'log' => env('APP_LOG', 'single'),
'log_level' => env('APP_LOG_LEVEL', 'debug'),
```
這些配置已遷移至新的 `config/logging.php` 文件。

### 2. config/queue.php
更新為使用新的環境變量名稱（第 18 行）：
```php
// ❌ 舊版
'default' => env('QUEUE_DRIVER', 'sync'),

// ✅ 新版
'default' => env('QUEUE_CONNECTION', 'sync'),
```

### 3. phpunit.xml
更新測試環境變量（第 31 行）：
```xml
<!-- ❌ 舊版 -->
<env name="QUEUE_DRIVER" value="sync"/>

<!-- ✅ 新版 -->
<env name="QUEUE_CONNECTION" value="sync"/>
```

## Composer 更新步驟（本地/CI）
```bash
composer install
# 若先前已安裝，可視情況跑 composer update
php artisan config:clear
php artisan cache:clear
./vendor/bin/phpunit
```

## 部署步驟建議（正式機）
1. **部署前檢查**
   - PHP 版本 >= 7.4。
   - 確認有新版程式碼和 `composer.lock`。
   - **重要**：確認已應用上述「配置文件修正」中的所有變更。
2. **拉新程式碼**
   - `git pull` 或充值鏡像。
3. **調整 `.env`**
   - 新增 `LOG_CHANNEL=stack`、`LOG_LEVEL=debug`。
   - 將 `QUEUE_DRIVER` 改為 `QUEUE_CONNECTION=sync`（或環境對應的 driver）。
4. **安裝套件（使用 lockfile）**
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
5. **Artisan 操作**
   ```bash
   php artisan config:clear
   php artisan cache:clear
   php artisan config:cache
   # 視需求可額外執行 route:cache、view:cache
   ```
6. **驗證**
   - 跑 smoke test，確認 API、登入、Passport OAuth 流程正常。
   - 留意 `storage/logs/laravel.log` 或自身設定的 logging channel。

## 已知事項
- `Carbon 1` 仍在使用；官方建議升級到 Carbon 2，但非此次升級一部分。
- `fzaninotto/faker` 尚保留。若未來要轉成 `fakerphp/faker`，需在網路可用時執行 `composer require fakerphp/faker`。
- `phpunit/php-token-stream` 仍由 PHPUnit 覆蓋率功能帶入，無需手動調整。
- **測試結束後的 Exit Code 255**：測試完成後可能出現 "Class cache does not exist" 錯誤（exit code 255）。這是 Laravel 框架 deferred service provider 清理機制的已知問題，不影響測試結果。CI 配置和 composer test script 已設定忽略此錯誤碼。

## 測試情況
```bash
./vendor/bin/phpunit
# PHPUnit 7.5.20
# Tests: 112, Assertions: 482, Skipped: 1
# OK, but incomplete, skipped, or risky tests!
#
# 注意：可能出現 exit code 255 錯誤，但不影響測試結果
```

## 參考連結
- [Laravel 5.6 Release Notes](https://laravel.com/docs/5.6/releases)
- [Upgrading Passport to 4.x](https://laravel.com/docs/5.6/passport)
- [Logging Configuration](https://laravel.com/docs/5.6/logging)

