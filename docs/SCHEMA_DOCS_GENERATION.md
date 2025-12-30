# 數據庫 Schema 文檔自動生成

## 概述

`cbdb:generate-schema-docs` 命令用於自動生成完整的數據庫 Schema 文檔，涵蓋 **MySQL/MariaDB** 和 **SQLite** 兩種數據庫系統。

## 核心理念

### 雙數據庫策略

1. **MySQL/MariaDB Schema**（連接現有數據庫）
   - 從已運行 migrations 的 MySQL 數據庫讀取 Schema
   - 使用 `INFORMATION_SCHEMA` 查詢表結構
   - 適用於開發環境、Staging 環境、生產環境

2. **SQLite Schema**（程序化生成）
   - 使用 in-memory SQLite 數據庫
   - 自動運行所有 migrations
   - 使用 Laravel Schema facade 讀取結構
   - 完全自動化，無需外部依賴

### 為什麼需要兩套 Schema？

- **MySQL/MariaDB**：生產環境使用的數據庫
- **SQLite**：測試環境使用的數據庫（PHPUnit 測試）
- **目的**：確保兩個數據庫的 Schema 保持一致，便於跨數據庫開發

## 使用方法

### 基本用法

```bash
# 生成 Schema 文檔（默認輸出到 DATABASE_SCHEMA.md）
php artisan cbdb:generate-schema-docs

# 指定輸出文件
php artisan cbdb:generate-schema-docs --output=docs/my_schema.md

# 指定 MySQL 連接（默認使用 'mysql' 連接）
php artisan cbdb:generate-schema-docs --mysql-connection=staging
```

### 命令選項

| 選項 | 默認值 | 說明 |
|------|--------|------|
| `--output` | `DATABASE_SCHEMA.md` | 輸出 Markdown 文件路徑 |
| `--mysql-connection` | `mysql` | MySQL 數據庫連接名稱（來自 `config/database.php`）|

## 環境要求

### 必需的 PHP 擴展

- **pdo_sqlite**：用於 SQLite Schema 生成

  檢查是否已安裝：
  ```bash
  php -m | grep pdo_sqlite
  ```

  如未安裝，請根據系統安裝：
  ```bash
  # Ubuntu/Debian
  sudo apt-get install php-sqlite3

  # CentOS/RHEL
  sudo yum install php-pdo

  # macOS (Homebrew)
  brew install php
  ```

### MySQL 數據庫要求

- MySQL 數據庫必須已運行所有 migrations
- 確保 `config/database.php` 中配置正確的連接信息
- 命令會自動檢測數據庫連接是否可用

## 生成的文檔結構

文檔包含以下主要部分：

### 1. MySQL/MariaDB Schema

- 所有表和視圖的列表
- 每個表的詳細信息：
  - 主鍵
  - 列名、類型、可空性、默認值、註釋
  - 索引列表（包括 UNIQUE 索引）

### 2. SQLite Schema

- 從 migrations 生成的完整 Schema
- 結構與 MySQL 部分相同

### 3. Schema 差異對比

- 僅存在於 MySQL 的表
- 僅存在於 SQLite 的表
- 列結構差異（如果有）

## 示例輸出

```markdown
# 數據庫 Schema 文檔

> 本文檔由 `php artisan cbdb:generate-schema-docs` 自動生成
> 生成時間：2025-12-30 10:00:00

## 目錄

- [MySQL/MariaDB Schema](#mysqlmariadb-schema)
- [SQLite Schema](#sqlite-schema)
- [Schema 差異對比](#schema-差異對比)

## MySQL/MariaDB Schema

### users

**主鍵**: `id`

| 列名 | 類型 | 可空 | 默認值 | 備註 |
|------|------|------|--------|------|
| `id` | bigint unsigned | NO | (NULL) | [AUTO_INCREMENT] |
| `name` | varchar(255) | NO | (NULL) | 用戶名稱 |
| `email` | varchar(255) | NO | (NULL) | Email |
| `password` | varchar(255) | NO | (NULL) | 密碼 |
| `created_at` | timestamp | YES | (NULL) |  |
| `updated_at` | timestamp | YES | (NULL) |  |

**索引**:

- `users_email_unique` (UNIQUE): (email)

---

...
```

## 工作流程建議

### 1. 日常開發

在修改 migrations 後：

```bash
# 1. 運行 migrations
php artisan migrate

# 2. 生成最新 Schema 文檔
php artisan cbdb:generate-schema-docs

# 3. 提交到版本控制
git add DATABASE_SCHEMA.md
git commit -m "更新：同步 Schema 文檔"
```

### 2. CI/CD 整合

在 CI 流程中自動驗證 Schema 一致性：

```bash
# .github/workflows/tests.yml
- name: Generate Schema Docs
  run: php artisan cbdb:generate-schema-docs

- name: Check Schema Consistency
  run: |
    if grep -q "僅存在於 MySQL 的表\|僅存在於 SQLite 的表" DATABASE_SCHEMA.md; then
      echo "警告：MySQL 和 SQLite Schema 不一致"
      exit 1
    fi
```

### 3. 定期審查

建議每週或每次 Sprint 結束時：

1. 重新生成 Schema 文檔
2. 審查 Schema 差異部分
3. 確保測試數據庫（SQLite）與生產數據庫（MySQL）保持同步

## 常見問題

### Q: SQLite Schema 生成失敗

**錯誤信息**：`could not find driver`

**解決方案**：
```bash
# 安裝 pdo_sqlite 擴展
sudo apt-get install php-sqlite3

# 重啟 PHP-FPM（如適用）
sudo systemctl restart php-fpm
```

### Q: MySQL 連接失敗

**錯誤信息**：`Connection refused`

**解決方案**：
1. 檢查 `.env` 中的 MySQL 配置
2. 確保 MySQL 服務已啟動
3. 驗證連接憑證

命令會優雅處理連接失敗，只生成可用部分的文檔。

### Q: 文檔過大

如果數據庫表很多，生成的 Markdown 文件可能很大。

**建議**：
- 分批生成（未來功能）
- 使用 `--tables` 選項指定表（未來功能）
- 將文檔拆分為多個部分

## 技術實現

### 核心組件

- **命令類**：`App\Console\Commands\GenerateSchemaDocs`
- **測試**：`Tests\Feature\GenerateSchemaDocsTest`

### 關鍵技術

1. **臨時 SQLite 連接**：
   ```php
   Config::set("database.connections.temp_sqlite", [
       'driver' => 'sqlite',
       'database' => ':memory:',
   ]);
   ```

2. **Migration 自動運行**：
   ```php
   Artisan::call('migrate:fresh', [
       '--database' => 'temp_sqlite',
       '--force' => true,
   ]);
   ```

3. **跨數據庫 Schema 讀取**：
   - MySQL：INFORMATION_SCHEMA 查詢
   - SQLite：PRAGMA 命令

### 擴展性

命令設計考慮了未來擴展：

- 支持更多數據庫類型（PostgreSQL、SQL Server）
- 表過濾和選擇性生成
- JSON/YAML 格式輸出
- Schema 比較和遷移建議

## 相關資源

- [Migration 編寫指南](.claude/skills/migration-guide.md)
- [數據庫 Schema 查詢](.claude/skills/database-schema.md)
- [測試指南](.claude/skills/testing-guide.md)
- [AGENTS.md](../AGENTS.md) - 專案開發規範

## 貢獻

如需改進此命令，請：

1. 修改 `app/Console/Commands/GenerateSchemaDocs.php`
2. 添加/更新測試於 `tests/Feature/GenerateSchemaDocsTest.php`
3. 運行 `./vendor/bin/php-cs-fixer fix` 格式化代碼
4. 運行 `./vendor/bin/phpunit` 確保所有測試通過
5. 更新本文檔
