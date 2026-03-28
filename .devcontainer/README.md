# Dev Container 使用說明

本目錄包含讓本專案可在 **VS Code Dev Containers**、**GitHub Codespaces** 或任何 Docker-based 環境中一鍵啟動的配置。

## 環境規格

| 項目 | 版本 |
|------|------|
| PHP | 8.4（Debian Bookworm） |
| Composer | 最新穩定版（隨 base image） |
| Node.js | 22 LTS |
| npm | 10.x |
| SQLite | 內建（供 PHPUnit 使用） |
| MariaDB client | 內建（連線外部 DB 用） |

### PHP Extensions

下列 extensions 均已在容器中啟用：

`pdo` · `pdo_mysql` · `pdo_sqlite` · `mbstring` · `xml` · `curl` · `zip` · `intl` · `bcmath` · `tokenizer` · `ctype` · `json` · `fileinfo` · `openssl`

---

## 如何開啟 Dev Container

### VS Code Dev Containers

1. 安裝 [Dev Containers 擴充功能](https://marketplace.visualstudio.com/items?itemName=ms-vscode-remote.remote-containers)
2. 用 VS Code 開啟本專案資料夾
3. 按 `F1` → **Dev Containers: Reopen in Container**
4. 等待容器 build 與 `postCreateCommand` 完成

### GitHub Codespaces

1. 在 GitHub 儲存庫頁面點選 **Code → Codespaces → Create codespace on …**
2. Codespaces 會自動 build 容器並執行 `postCreateCommand`

---

## 容器啟動後自動完成的事

`postCreateCommand`（`.devcontainer/post-create.sh`）會：

1. `composer install` – 安裝 PHP 依賴
2. `npm install` – 安裝 Node 依賴
3. 若 `.env` 不存在，從 `.env.example` 複製並執行 `php artisan key:generate`

> **注意**：若 `.env` 已存在，`postCreateCommand` 不會覆蓋它。

---

## 容器內常用命令

```bash
# 啟動 Laravel 開發伺服器（轉發至 :8000）
php artisan serve --host=0.0.0.0

# 啟動 Vite 前端 dev server
npm run dev

# 編譯前端資源（production build）
npm run build

# 執行所有 PHPUnit 測試（使用 :memory: SQLite，不需要外部 DB）
./vendor/bin/phpunit

# 執行單一測試
./vendor/bin/phpunit --filter PersonBrowserTest

# 代碼格式化（乾跑，不實際修改）
./vendor/bin/php-cs-fixer fix --dry-run --diff

# 代碼格式化（實際修改）
./vendor/bin/php-cs-fixer fix
```

---

## 資料庫設定

### SQLite（測試用，零配置）

`phpunit.xml` 已將測試環境設為 `DB_CONNECTION=sqlite`、`DB_DATABASE=:memory:`（In-Memory SQLite），因此 **`./vendor/bin/phpunit` 完全不需要外部資料庫**，直接可執行。

> 這是本專案推薦的標準測試模式，可避免依賴完整的 MySQL schema，也使 CI/CD 保持穩定。

### MariaDB（連線外部 / 本地 DB）

Dev Container 本身未內建 MariaDB 服務（第一版維持輕量）。若需要連線 MariaDB：

**選項 A：Docker Compose（本地開發）**

在專案根目錄新增 `docker-compose.override.yml`（不納入版本控制），啟動 MariaDB service：

```yaml
services:
  db:
    image: mariadb:10.11
    environment:
      MARIADB_ROOT_PASSWORD: secret
      MARIADB_DATABASE: cbdb
      MARIADB_USER: cbdb
      MARIADB_PASSWORD: secret
    ports:
      - "3306:3306"
```

然後在 `.env` 中設定：

```
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=cbdb
DB_USERNAME=cbdb
DB_PASSWORD=secret
DB_TIMEZONE=+08:00
```

**選項 B：連線外部 MariaDB**

直接在 `.env` 填入外部 DB 的 host/port/credentials，容器內已安裝 `mariadb-client`，可用 `mysql` 指令測試連線：

```bash
mysql -h <DB_HOST> -u <DB_USERNAME> -p <DB_DATABASE>
```

---

## 關於 DB_TIMEZONE

根據 `AGENTS.md` 的規範：

- `DB_TIMEZONE` **必須**使用數字偏移格式，例如 `+08:00`
- **不可**使用命名時區（如 `Asia/Shanghai`）
- 必須與 `config/app.php` 的 `timezone`（`Asia/Shanghai`，即 GMT+8）一致

預設的 `.env.example` 已正確設定 `DB_TIMEZONE=+08:00`，請勿更改此值。

---

## 自動安裝的 VS Code 擴充功能

| 擴充功能 | 用途 |
|----------|------|
| GitHub Copilot | AI 代碼補全 |
| GitHub Copilot Chat | AI 對話輔助 |
| PHP Intelephense | PHP 語言智慧提示 |
| Laravel Extra Intellisense | Laravel 自動補全 |
| Laravel Blade Snippets | Blade 模板支援 |
| ESLint | JavaScript/TypeScript linting |
| Prettier | 代碼格式化（JS/TS/CSS） |
