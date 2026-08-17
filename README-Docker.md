# CBDB Online - Docker 開發環境

使用 Docker Compose 快速搭建 CBDB Online 開發環境，無需安裝 PHP、Composer、MySQL 等依賴。

## 🆕 Docker 架構

本專案使用 **FrankenPHP** 作為 Docker 架構，提供現代化的 PHP 應用伺服器體驗。

### ⚡ 架構特點
- 架構：單容器集成 Web 伺服器（替代傳統 PHP-FPM + Nginx）
- 性能：Classic 模式適合開發，Worker 模式性能可提升 10 倍以上
- 特性：支持 HTTP/2、HTTP/3，配置簡單
- 容器名稱：`cbdb-frankenphp`

## 目錄結構

```
/
├── docker/
│   ├── Dockerfile      # FrankenPHP Dockerfile
│   ├── Caddyfile       # Caddy Web 伺服器配置
│   ├── entrypoint.sh   # 容器啟動腳本
│   └── php.ini         # PHP 配置
├── docker-compose.yml  # Docker Compose 配置
├── db-data/            # [新] SQLite 資料庫持久化目錄 (Docker Volume)
├── database/           # 資料庫模板目錄
│   └── database.sqlite3  # 初始資料庫模板（CBDB SQLite 資料庫檔案）
├── scripts/            # 腳本目錄
│   └── patch_sqlite_db_for_dev.sh  # 補足 Schema 用的腳本
├── README-Docker.md    # Docker 使用說明（此檔案）
├── .env                # 環境配置檔案
└── .env.docker.example # Docker 環境配置示例
```

**默認配置**：
- 架構：FrankenPHP（1 個容器）
- 資料庫：SQLite (`/app/db-data/database.sqlite3`)
- 端口：8000
- 工作目錄：`/app`

## 技術特點

### SQLite 安裝方式

本專案 Dockerfile 從 SQLite 官網下載並編譯安裝最新版本（3.45.0+），而不是使用 `apt install sqlite3`。

**原因**：Ubuntu 24.04 LTS 的 apt 倉庫中的 SQLite3 版本存在已知問題，可能導致資料庫兼容性或性能問題。從官網編譯可以確保：
- 使用最新穩定版本
- 避免發行版特定的補丁問題
- 獲得最佳性能和最新特性

如需更新 SQLite 版本，修改 Dockerfile 中的環境變量：
```dockerfile
ENV SQLITE_VERSION=3450000  # 對應 3.45.0
ENV SQLITE_YEAR=2024
```

### FrankenPHP 架構特點

FrankenPHP 是新一代 PHP 應用伺服器，將 PHP 與現代 Web 伺服器（Caddy）集成：

**優勢**：
- ✅ 架構簡化：單容器替代 PHP-FPM + Nginx 雙容器
- ✅ 性能提升：Worker 模式下性能可提升 10 倍以上
- ✅ 現代協議：原生支持 HTTP/2、HTTP/3
- ✅ 配置簡單：使用 Caddyfile 替代複雜的 Nginx 配置
- ✅ 自動 HTTPS：內置自動證書管理

**兩種運行模式**：
1. **Classic 模式**（默認）：兼容傳統 PHP 應用，性能與 PHP-FPM 相當
2. **Worker 模式**（Octane）：應用常駐內存，性能大幅提升（需要 Laravel Octane）

**適用場景**：
- ✅ 單伺服器部署（搭配 SQLite 完美組合）
- ✅ 中小型應用（大部分 Laravel 應用）
- ✅ 開發環境（配置更簡單）
- ⚠️ 不適合多伺服器橫向擴展（建議使用 MySQL/PostgreSQL）

## 快速開始

### 1. 準備 SQLite 資料庫 (可選)

容器啟動時會自動處理資料庫初始化，邏輯如下：
1. **持久化優先**：如果 `db-data/database.sqlite3` 已存在，則直接使用。
2. **模板初始化**：如果持久化檔案不存在，但 `database/database.sqlite3` 存在，則複製模板到持久化目錄。
3. **自動創建**：如果兩者都不存在，則創建一個空的資料庫檔案。

如果你想手動準備數據，可以從 CBDB 伺服器的 MySQL 資料庫導出數據到 SQLite：

```bash
# 在宿主機（.env 指向你的 MySQL）匯出成容器的初始化模板：
php artisan db:export-to-sqlite --output=database/database.sqlite3
```

只有在 `db-data/database.sqlite3`（named volume）還不存在時，這個模板才會被複製進去；詳見下方「從 MySQL 重新導出」。

匯出範圍的兩點說明：帳號／憑證表只會匯出**結構**、不含資料列（見「從 MySQL 重新導出」的第 1 點）；`--tables` 可以只挑部分表，但**對外釋出**用的 77 張表 allowlist 請以 `scripts/export-daily-sqlite.sh` 為準（契約見 [docs/SQLITE_DATA_RELEASE.md](docs/SQLITE_DATA_RELEASE.md)），不要在這裡另抄一份會過期的清單。

### 2. 配置環境變量

複製 Docker 環境配置示例：
```bash
cp .env.docker.example .env
```

編輯 `.env` 檔案，確保資料庫配置正確：
```env
DB_CONNECTION=sqlite
DB_DATABASE=/app/db-data/database.sqlite3
```

**重要**：路徑必須是容器內的持久化路徑 `/app/db-data/database.sqlite3`，容器啟動腳本會自動檢測並更新 `.env` 中的該路徑。

### 3. 生成應用密鑰（首次運行）

```bash
# 如果 .env 中 APP_KEY 為空，需要生成
docker compose run --rm app php artisan key:generate
```

### 4. 啟動服務

```bash
docker compose up --build
```

首次啟動會構建鏡像，大約需要 3-5 分鐘。容器啟動時會自動執行 `composer install` 和緩存清理，無需手動操作。後續啟動只需幾秒鐘。

### 5. 訪問應用

打開瀏覽器訪問：
```
http://localhost:8000
```

### 6. 初始管理員帳戶

容器首次啟動（或資料庫中不存在該用戶時）會自動創建一個默認的超級管理員帳戶：

- **Email**: `admin@example.com`
- **Password**: `password`

你可以使用此帳戶登錄後台管理系統。登錄後**強烈建議**立即修改密碼。

## 深入：初始化完整資料庫內容 (官方數據)

也可使用從官方提供的 CBDB SQLite 資料庫（包含 77 個原始表）開始初始化，請遵循以下步驟：

### 1. 準備官方 SQLite 資料庫
獲取包含 77 個表格的 CBDB 官方最新 SQLite 資料庫（例如 `cbdb_20251223.db`）。將其重命名為 `database/database.sqlite3`。

### 2. 補足 Schema
官方資料庫缺少 Laravel 運行所需的管理表和搜索優化表。運行以下腳本補足這 9 個表的 schema：
`CBDB__NAME_FTS`, `CBDB__TRAD_SIMP_MAP`, `migrations`, `nl_query_logs`, `operations`, `password_resets`, `personal_access_tokens`, `pinyin`, `users`

> ⚠️ **補 schema 要在容器第一次啟動之前完成**（在宿主機用 `sqlite3` 對 `database/database.sqlite3` 跑這個腳本）。原因是 `docker/entrypoint.sh` 在啟動服務前就會查詢／建立 `admin@example.com`——官方檔沒有 `users` 表時那一步會失敗，容器會停在除錯模式（`tail -f`）而不對外服務。
>
> 另外：只要 named volume 裡已經有 `db-data/database.sqlite3`，新放進 `database/` 的檔案就不會被採用；換檔前請先 `docker compose exec app rm /app/db-data/database.sqlite3`（或整個移除該 volume）。

```bash
# 在宿主機執行（推薦，於容器首次啟動前）：對 entrypoint 會複製進 volume 的那份模板動手。
# 腳本預設目標是 db-data/database.sqlite3（容器內的 SoT），所以這裡要顯式指定模板路徑。
bash scripts/patch_sqlite_db_for_dev.sh database/database.sqlite3

# 若容器已經在跑（且已能啟動），也可以直接補容器內的 SoT（不帶參數即為預設路徑）
docker compose exec app bash scripts/patch_sqlite_db_for_dev.sh
```

注意：如果 9 個表格中的任何一個已存在，腳本會報錯並停止執行。這時需要手工檢查資料庫檔案，刪除已存在的表格，再重新執行腳本。

### 3. 啟動並登錄管理員帳戶
啟動 Docker (`docker compose up -d`)，訪問 `http://localhost:8000/`
並通過新建的管理員帳戶登錄。

### 4. 灌入特定表內容
登錄後，前往以下頁面以完成最後的數據灌入工作：
`http://localhost:8000/admin/cbdb-table-maintenance`

在該頁面中，請依次執行以下操作：
1. **灌入 CBDB__TRAD_SIMP_MAP**：初始化繁簡轉換映射表。
2. **灌入 CBDB__NAME_FTS**：初始化姓名全文搜索索引表。


## 常用命令

### 啟動服務
```bash
docker compose up        # 前台運行，查看日誌
docker compose up -d     # 後台運行
```

### 停止服務
```bash
docker compose down      # 停止並刪除容器
docker compose stop      # 僅停止容器
```

### 重新構建
```bash
docker compose up --build        # 重新構建並啟動
docker compose build --no-cache  # 完全重新構建（不使用緩存）
```

### Laravel 命令

```bash
# 運行遷移
docker compose exec app php artisan migrate

# 清除緩存
docker compose exec app php artisan cache:clear
docker compose exec app php artisan config:clear
docker compose exec app php artisan route:clear
docker compose exec app php artisan view:clear

# 生成應用密鑰
docker compose exec app php artisan key:generate

# 進入容器
docker compose exec app bash

# 運行 Composer
docker compose exec app composer install
docker compose exec app composer update
docker compose exec app composer dump-autoload

# 查看 FrankenPHP 狀態
docker compose exec app frankenphp version
```

### 查看日誌
```bash
docker compose logs        # 查看所有服務日誌
docker compose logs app    # 查看應用日誌
docker compose logs -f     # 實時查看日誌
```

## 開發工作流

### 1. 修改代碼
直接在本地編輯器修改代碼，容器會自動映射最新的代碼：
```bash
# 代碼映射： . -> /app
```

刷新瀏覽器即可看到變化（無需重啟容器）。

### 2. 拉取最新代碼
```bash
git pull
# 瀏覽器刷新即可看到變化
```

### 3. 更新依賴
```bash
# 如果 composer.json 有變化
docker compose exec app composer install

# 如果需要重新構建鏡像
docker compose up --build
```

### 4. 運行資料庫遷移
```bash
docker compose exec app php artisan migrate
```

## 檔案權限問題

如果遇到 `storage/` 或 `bootstrap/cache/` 權限錯誤：

```bash
docker compose exec app chown -R www-data:www-data storage bootstrap/cache
docker compose exec app chmod -R 775 storage bootstrap/cache
```

## 資料庫管理

### 查看 SQLite 數據

**方式一：使用 SQLite 客戶端**
```bash
# 安裝 sqlite3（如果未安裝）
# macOS
brew install sqlite

# Linux
sudo apt-get install sqlite3

# 打開資料庫
sqlite3 db-data/database.sqlite3

# SQLite 命令
.tables              # 查看所有表
.schema table_name   # 查看表結構
SELECT * FROM users; # 執行查詢
.quit                # 退出
```

**方式二：在容器內查看**
```bash
docker compose exec app sqlite3 /app/db-data/database.sqlite3
```

### 備份資料庫
```bash
# 備份持久化目錄下的資料庫
cp db-data/database.sqlite3 db-data/database.sqlite3.backup
```

### 從 MySQL 重新導出

這條路徑產出的是**你自己 MySQL 的快照**（與上面「官方發布檔 ＋ 補 schema」是兩條互斥的流程，詳見下方第 3 點）。

**這條指令要在宿主機（能連到 MySQL 的環境）執行，不要在容器內跑。** 容器的 `DB_CONNECTION`／`DB_DATABASE` 是由 `docker-compose.yml` 以**真實環境變數**寫死成 SQLite 的，改 `.env` 不會生效（環境變數優先於 dotenv），而且 compose 裡也沒有 MySQL service。

```bash
# 在宿主機：.env 指向你的 MySQL，然後導出
php artisan db:export-to-sqlite --output=database/database.sqlite3
```

導出後要讓容器真的用到新檔，**必須先刪掉容器裡的舊檔**：

```bash
# db-data 是 named volume（docker-compose.yml 的 db_data），
# 宿主機的 ./db-data 與容器內的 /app/db-data 無關——在宿主機刪檔是無效的。
docker compose exec app rm /app/db-data/database.sqlite3
docker compose restart
```

原因見 `docker/entrypoint.sh`：`/app/db-data/database.sqlite3` 是 SoT，**只有在它不存在時**才會從 `/app/database/` 複製過去；否則重啟只會沿用舊檔。（`docker compose restart` 會重跑依賴安裝與前端建置，是分鐘級而非秒級，不是卡住。）

三件要注意的事：

1. **已列名的憑證表預設只匯出結構、不匯出資料列。** `db:export-to-sqlite` 對 `users`／`personal_access_tokens`／`password_resets`／`sessions`／`oauth_*` 等 14 張表（完整清單見 `App\Console\Commands\ExportMysqlToSqlite::CREDENTIAL_TABLES`）只複製結構，密碼雜湊、API token、`confirmation_token` 不會被帶到本機（見 issue #1251）。
   兩個範圍限制要知道：比對是**精確表名**，所以備份還原留下的 `users_bak`／`users_20260101` 這類副本**仍會連資料一起匯出**；而且這只擋憑證，**不代表**檔案裡沒有個人資料——`audit_log` 含 email 與登入 IP／User-Agent，`operations`／`nl_query_logs`／`ai_fill_logs` 含使用者輸入與 user_id。
   帳號方面不需要手動處理——容器啟動時若找不到 `admin@example.com` 會自動建一個超級管理員（`admin@example.com` / `password`，見上文第 6 節）。**那是弱密碼超管，請立刻改密碼**，或改用 `docker compose exec app php artisan cbdb:manage-user` 建自己的帳號。
   真的需要連帳號資料一起導出時顯式加 `--with-credentials`（命令會印警告）——那等於把生產環境的密碼雜湊與長期憑證複製到本機磁碟。

2. **導出檔不含 `CBDB__` 開頭的內部表**（`CBDB__NAME_FTS`、`CBDB__TRAD_SIMP_MAP`）。**部分中文姓名查詢會直接 500**（`no such table: CBDB__NAME_FTS`，例如人物瀏覽工作台的搜尋）——寫入／重建索引路徑與少數讀取端有 `Schema::hasTable()` 護欄，但主要的姓名搜尋讀取路徑沒有。要嘛導出時加 `--with-internal`，要嘛事後重建索引。

3. 這條流程與上文「官方發布檔 ＋ 補 schema」是**兩條互斥的流程，不要混用**：這裡產出的檔案已自帶 `users`／`password_resets`／`operations`／`pinyin`／`migrations` 等表，再去跑 `patch_sqlite_db_for_dev.sh` 會如設計般 abort（該腳本在目標表已存在時會停止）。

## 啟用 Worker 模式（高性能）

如果需要更高性能，可以啟用 Laravel Octane Worker 模式：

```bash
# 1. 安裝 Laravel Octane
docker compose exec app composer require laravel/octane

# 2. 安裝 FrankenPHP 驅動
docker compose exec app php artisan octane:install --server=frankenphp

# 3. 修改 docker/entrypoint.sh 最後一行
# 從: exec frankenphp run --config /etc/caddy/Caddyfile
# 改為: exec php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=80

# 4. 重新構建並啟動
docker compose down
docker compose up --build
```

**Worker 模式注意事項**：
- ⚠️ 代碼修改後需要重啟容器才能生效
- ⚠️ 需要注意內存洩漏和全局狀態管理
- ⚠️ 適合生產環境，不適合頻繁修改代碼的開發環境
- ✅ 性能可提升 10 倍以上

## 升級 PHP 版本

要升級到更高版本的 PHP，只需修改 `docker/Dockerfile` 第一行的鏡像標籤：

```dockerfile
# 當前版本
FROM dunglas/frankenphp:1-php8.4.15

# 升級示例
FROM dunglas/frankenphp:1-php8.5
```

**鏡像標籤格式說明**：
- `1`：FrankenPHP 主版本
- `php8.4.15`：具體的 PHP 版本（可選）
- 使用 `1-php8.4` 會自動使用該系列的最新補丁版本

然後重新構建：
```bash
docker compose down
docker compose up --build
```

## 故障排查

### 容器啟動失敗
```bash
# 查看詳細日誌
docker compose logs

# 檢查端口佔用
lsof -i :8000  # macOS/Linux
netstat -ano | findstr :8000  # Windows

# 刪除所有容器重新開始
docker compose down
docker compose up --build
```

### 頁面顯示 500 錯誤
```bash
# 檢查 Laravel 日誌
docker compose exec app tail -f storage/logs/laravel.log

# 檢查權限
docker compose exec app ls -la storage/
docker compose exec app chown -R www-data:www-data storage bootstrap/cache
```

### Composer 依賴安裝失敗
```bash
# 進入容器手動安裝
docker compose exec app bash
composer install --verbose
```

### 資料庫連接失敗
檢查 `.env` 檔案：
- `DB_CONNECTION=sqlite`
- `DB_DATABASE=/app/db-data/database.sqlite3`（容器內持久化路徑）
- 確保 `db-data/database.sqlite3` 檔案存在且有讀寫權限

### 容器無響應

**問題：容器啟動後訪問 localhost:8000 無響應**
```bash
# 檢查容器是否真的在運行
docker compose ps

# 檢查日誌
docker compose logs app

# 檢查端口是否正確監聽
docker compose exec app netstat -tlnp
```

**問題：Worker 模式下代碼修改不生效**
```bash
# Worker 模式需要重啟容器
docker compose restart app

# 或者修改 entrypoint.sh 切換回 Classic 模式進行開發
```

**問題：Caddyfile 語法錯誤**
```bash
# 測試 Caddyfile 配置
docker compose exec app frankenphp validate --config /etc/caddy/Caddyfile
```

## 生產部署注意事項

此 Docker 配置僅用於**開發環境**，生產環境需要：

1. 使用生產優化的 Dockerfile（多階段構建、最小化鏡像）
2. 配置 HTTPS
3. 使用生產級資料庫（MySQL、PostgreSQL）
4. 配置日誌收集
5. 設置健康檢查
6. 使用環境變量管理敏感信息
7. 禁用調試模式（`APP_DEBUG=false`）

## 其他資源

- [Laravel 官方文檔](https://laravel.com/docs)
- [Docker Compose 文檔](https://docs.docker.com/compose/)
- [PHP Docker 官方鏡像](https://hub.docker.com/_/php)

## 技術支持

遇到問題請：
1. 查看專案 Issues
2. 查看 Laravel 日誌：`storage/logs/laravel.log`
3. 查看 Docker 日誌：`docker compose logs`
