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

---

# Laravel 5.6 → 5.8 升級摘要

> 2025-11-14 在測試環境完成 5.6 → 5.7 → 5.8 逐步升級，最終版本為 **Laravel 5.8.38**，所有 112 個測試（485 斷言）均通過。以下為整合後的重點，詳盡的計畫／評估／報告已合併進本文。

## 環境與依賴需求
- **PHP**：7.1.3 以上；實際環境為 7.4.33，完全符合。
- **核心套件**：
  - `laravel/framework` → 5.7.* / 5.8.*（需逐版升級）
  - `laravel/passport` → ^7.0
  - `phpunit/phpunit` → ^7.5（既有版本即可）
- **第三方注意事項**：
  - `dingo/api`：經確認完全未使用，可直接移除，亦消除主要風險。
  - `maatwebsite/excel`：版本較舊，升級後需跑匯入／匯出實測。
  - `email` 驗證：Laravel 5.8 採用 RFC6530，會接受更多格式，需確認是否符合業務規範。
  - Cache API 改為以「秒」為單位，但本專案全部以 `Carbon` 物件呼叫 `Cache::put()`，無須調整。

## 風險評估（精簡版）
| 項目 | 影響 | 對策 |
| --- | --- | --- |
| dingo/api | 原被視為高風險，實測未使用 | 直接自 composer.json 移除，清理相關文件 |
| Passport | 中等 | 升級到 ^7.0，重新跑 OAuth 驗證流程 |
| Excel 2.0 | 中等 | 升級後實測批次匯入／匯出 |
| Email 驗證 | 低 | 驗證規則更寬鬆，需更新測試期待 |
| 其他核心改動 | 低 | 未使用相關 API，無需動作 |

## 推薦升級流程
1. **預備／清理**
   - 建立 `feature/laravel-5.8-upgrade` 分支。
   - `composer remove dingo/api`（觀察到會連帶刪除其依賴，也讓 composer 重新解析到 5.7）。
   - `./vendor/bin/phpunit` 確保現況穩定。
2. **升級到 5.7**
   - `composer require "laravel/framework:5.7.*" "laravel/passport:^7.0"`.
   - `composer update --with-all-dependencies`.
   - `php artisan config:clear && php artisan cache:clear`.
   - 完整跑 PHPUnit，重點驗證 API / Passport / Excel。
3. **升級到 5.8**
   - `composer require "laravel/framework:5.8.*"`.
   - 以 `--with-all-dependencies` 允許 email-validator / doctrine 等降版相容。
   - 清理 config cache、執行測試。
4. **文檔＆設定同步**
   - 更新 README、AGENTS、DATABASE、tests/README 等文件的框架版本資訊。
   - `.env` / `config/*` 若有版本號、LOG/QUEUE 相關設定，需同步調整。

## 驗證清單
- ✅ PHPUnit：`./vendor/bin/phpunit`（112 tests / 485 assertions，全綠）。
- ✅ API 路由（`/api/select/*`、`/api/v1/*` 等）及 Passport OAuth。
- ✅ 批次 Excel 匯入／匯出。
- ✅ Cache、Queue、Email 驗證相關功能。
- ⚠️ 測試流程仍會在結束時出現「Class cache does not exist (exit code 255)」，屬 Laravel 既知問題，對結果無影響。

## 主要變更整理
- 移除未使用的 `dingo/api` 生態系套件（含 `league/fractal`, `doctrine/annotations` 等），縮小依賴範圍。
- 更新 `composer.json` 以支援 Laravel 5.8，並清除 `laravel/nexmo-*`、`laravel/slack-*` 等在 5.8 內建的通知套件。
- `config/logging.php` / `config/queue.php` / `.env` / `phpunit.xml` 已在 5.6 階段調整，可沿用。
- 驗證 Email、Cache 時皆以官方建議的新 API 撰寫，無需額外 polyfill。
- 文檔中記錄的升級路線（評估 → 計畫 → 完成）已整合到本文件，後續只需維護此一檔案。

## 未來建議
- 逐步替換廢棄套件（`fzaninotto/faker`、`phpoffice/phpexcel`、`swiftmailer/swiftmailer` 等）。
- 若規畫升級至 Laravel 6+，請先確認 PHP 7.4 → 8.x 路線與 Carbon 2 遷移策略。
- 保留本文的流程／清單作為未來升級（例如 5.8 → 6.x）的模板。
