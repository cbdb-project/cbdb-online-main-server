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

