# cbdb-online-main-server

[中國歷代人物傳在線記录入系统](https://input.cbdb.fas.harvard.edu/)原始碼。

**授權 / License:** [CC BY-NC-SA 4.0 International](https://creativecommons.org/licenses/by-nc-sa/4.0/) (主要授權，詳見 [LICENSE.md](./LICENSE.md))

## 文件導覽（2026 更新）

### 開發指南
* [AGENTS 指南](./AGENTS.md) - AI 代理開發必讀
* [數據庫完整指南](./DATABASE.md) - ⚠️ 必讀：環境、兼容性、Schema 管理
* [CHANGELOG](./CHANGELOG.md)

### 開發手記
* [升級指南](./docs/UPGRADE.md) - Laravel 框架升級記錄
* [AdminLTE 在 CBDB Online 項目中的使用分析](./docs/ADMINLTE.md)
* [Proposal / Approval Flows](./docs/APPROVAL_FLOWS.md)
* [代碼表格前端實作比較](./docs/CODES.md)
* [代碼表界面白名單維護](./docs/CODES_TABLES.md)
* [代碼表界面效能優化](./docs/CODES_PERFORMANCE.md)
* [Posting/Office 流程備忘](./docs/POSTING_OFFICE.md)
* [Resource TTS 注意事項](./docs/RESOURCE_TTS_NOTES.md)
* [使用者權限說明](./docs/USER_PRIVILEGES.md)
* 若需批次導入資料，請參考 [BATCH_UPLOADERS](./docs/BATCH_UPLOADERS.md)
* [CBDB 公共 API v1 說明](./docs/CBDB_PUBLIC_API_V1.md)
* [單向關係修復工具](./docs/UNIDIRECTIONAL_RELATIONSHIP_REPAIR.md)
* [MERGE 工具說明](./docs/MERGE.md)
* [檢視表總覽](./docs/VIEWS.md)
* [Wiki 导入任务管理](./docs/WIKI_TASK_MANAGEMENT.md)
* [姓名搜索索引管理](./docs/NAME_SEARCH_COMMANDS.md)
* [SQLite 每週同步](#sqlite-每週同步) - 自動匯出並同步到 HuggingFace

### 更多文檔
* [AdminLTE 4 升級可行性](./docs/ADMINLTE4_UPGRADE_FEASIBILITY.md)
* [AI 任官自動填充設計](./docs/AI_POSTING_AUTOFILL_DESIGN.md)
* [API 認證方案](./docs/API_AUTHENTICATION.md)
* [稽核日誌提案](./docs/AUDIT_LOG_PROPOSAL.md)
* [人物提案流程計畫](./docs/BIOGMAIN_APPROVAL_FLOWS_PLAN.md)
* [BiogMain Repository 重構計畫](./docs/BIOGMAIN_REPOSITORY_REFACTOR_PLAN.md)
* [複合主鍵 URL 設計](./docs/COMPOSITE_PRIMARY_KEY_URL_DESIGN.md)
* [資料庫 Schema（MySQL/SQLite）](./docs/DATABASE_SCHEMA.md)
* [Laravel Query 審查](./docs/LARAVEL_QUERY_REVIEW.md)
* [MCP HTTP 介面提案](./docs/MCP_HTTP_INTERFACE_PROPOSAL.md)
* [MCP 使用指南](./docs/MCP_USER_GUIDE.md)
* [姓名搜尋效能改進](./docs/NAME_SEARCH_PERFORMANCE_IMPROVEMENT.md)
* [Prometheus 指標說明](./docs/PROMETHEUS_METRICS.md)
* [Schema 文檔生成說明](./docs/SCHEMA_DOCS_GENERATION.md)
* [SQLite 數據發佈流程](./docs/SQLITE_DATA_RELEASE.md)
* [SQLite 遷移計畫](./docs/SQLITE_MIGRATION_PLAN.md)

## 技術環境

### 生產環境
- **PHP**: 8.2+ (最低 8.2.0，建議 8.4)
- **Laravel**: 12.x (已從 11.x 升級，參見 [UPGRADE.md](./docs/UPGRADE.md))
- **數據庫**: MariaDB 10.3.39 (Debian)
- **Web Server**: Caddy
- **Node.js**: 22.x（建議搭配 npm 10）

### 前端構建現況
- 全站已完成 **AdminLTE 3** (Bootstrap 4) 升級，使用 **Vite** 構建系統。
- 主要入口：`resources/js/app.js`（UI 組件）、`resources/js/datatables.js`（DataTables）。
- `resources/js/jquery-global.js` 將 jQuery 暴露到全局；Bootstrap 4、AdminLTE 3、Select2 等在 `app.js` 中實現。
- 所有頁面均使用 `@vite` 載入前端資源，**請勿引入外部 CDN 的 jQuery/Bootstrap**，以免版本衝突。

⚠️ **重要**：本專案現已升級到 Laravel 12.x 並要求 PHP 8.2+。**建議使用 PHP 8.4** 以獲得最佳性能和安全性。Laravel 12 已完全支持 PHP 8.4。

### 數據庫兼容性原則
⚠️ **重要**：為保持未來遷移到其他數據庫實現的靈活性，請遵循以下原則：
- 避免使用特定數據庫專屬功能（如 MySQL 的 ngram parser、MariaDB 專屬插件）
- 優先使用標準 SQL 語法
- 如需使用數據庫特性，應在代碼中提供降級方案或文檔說明
- 索引策略應基於通用的 B-Tree 或其他跨數據庫支持的類型

### 帳號權限分離（重要）

基線 migration 會觸及完整 schema，為了降低事故風險，**務必**將一般應用連線與 migration 連線分離，避免日常帳號擁有過高權限。

**配置原則**：
- `foo`：一般應用使用者，僅需 CRUD 權限（不含 DROP/ALTER/CREATE）。
- `foo_migrate`：僅供 migration 使用的專用帳號，具備 schema 變更所需權限。

**範例設定（.env）**：
```env
DB_USERNAME=foo
DB_PASSWORD=***

DB_MIGRATE_USERNAME=foo_migrate
DB_MIGRATE_PASSWORD=***
```

> 實際對應欄位請以 `config/database.php` 為準。

## 原始说明 (歷史檔案)

### Database migrations

数据库配置文件在.env中

migration 是laravel提供的一个数据库迁移功能，可以方便的在新环境上迁移数据。

```bash
php artisan make:migration creat_tasks_table --create=tasks
```
参考文档：[Laravel 10.x Migrations](https://laravel.com/docs/10.x/migrations)

### 用户验证

执行如下命令，使用laravel自带的用户验证功能

```bash
php artisan make:auth
```

参考文档：[Laravel 10.x Authentication](https://laravel.com/docs/10.x/authentication)

### 信息提示 flash

网站的各种提示使用[flash](https://github.com/laracasts/flash)

### 表单验证

表单验证会是本项目的重点之一，保证用户提交的信息准确无误

参考文档：[Laravel 10.x Validation](https://laravel.com/docs/10.x/validation)

### Eloquent ORM 與 Query Builder

Laravel 的 Eloquent ORM 提供了漂亮、简洁的 ActiveRecord 实现来和数据库进行交互。每个数据库表都有一个对应的「模型」可用来跟数据表进行交互。你可以通过模型查询数据表内的数据，以及将记录添加到数据表中。

**重要原則：**
- **單一主鍵的表**：可以使用 Eloquent 模型進行操作
- **複合主鍵的表**（如 `ALTNAME_DATA`、`POSTED_TO_ADDR_DATA` 等）：必須使用 Query Builder（`DB::table()`）而非 Eloquent 模型，因為 **Eloquent 官方不支持複合主鍵**。雖然有第三方套件提供支援，但會增加維護上的不確定性，因此本專案決定在複合主鍵表上直接使用 Query Builder

参考文档：
- [Laravel 10.x Eloquent ORM](https://laravel.com/docs/10.x/eloquent)
- [Laravel 10.x Query Builder](https://laravel.com/docs/10.x/queries)
- [複合主鍵限制說明](./AGENTS.md#常見坑位)

### 控制器

使用如下命令创建控制器

```bash
php artisan make:controller BiogBasicInformationController --resource
```

使用下面命令查看路由

```
php artisan route:list
```

创建验证Request
```
php artisan make:request BiogBasicInformationRequest
```


参考文档：[Laravel 10.x Controllers](https://laravel.com/docs/10.x/controllers)

### 前端資源構建

本專案已從 Laravel Mix/webpack 遷移至 **Vite**。

**開發環境**：
```bash
npm run dev    # 啟動 Vite 開發伺服器
```

**生產環境**：
```bash
npm run build  # 或 npm run prod，編譯並優化前端資源
```

**入口文件**：
- `resources/js/app.js` - 主要 UI 組件
- `resources/js/datatables.js` - DataTables 功能

**Vue 元件**：
- Vue 3 模板放在 `resources/js/components/`
- 在對應的 JS 入口文件中引入並註冊

### 已处理数据库表格
BIOG_MAIN
DYNASTIES
CHORONYM_CODES

### 对数据库的修改

**migrate 设置主键 寻找解决方法**

1. 把BIOG_MAIN的c_name添加binary属性，用于区分大小写查询

### 注意：
1. 官名包含 `POSTED_TO_OFFICE_DATA` `POSTED_TO_ADDR_DATA`

### 优化：
1. 添加提示，保存错误提示，尤其是操作数据库的提示


### 首页迁移（503 page）

建立 cbdb-online-main-server/resources/views/errors/503.blade.php

503.blade.php 的內容形如

```<html>
<h3>
We will update our server from 10:00am to 12:00pm on 11/16. The inputting service will be closed for 2 hours. We apologize for this inconvenience. CBDB Team 2018.11.14
</h3>
</html>
```

如果相关目录或者文件缺失，请直接新建

編輯 503.blade.php 后，运行 php artisan down 即可开启维护模式

### 将更改应用于服务器（暂停和启动程式）

```
php artisan down

php artisan up
```

记得不要在前面加 sudo。据群超：docker 下没有 sudo 的权限，如果使用 laradock 的环境，sudo 命令将不会成功

### 關閉服務和打開服務(Centos)

STOP:

```
cd /.../cbdb-online-main-server

php artisan down

systemctl stop docker

init 0
```

START:

```
cd /.../laradock

docker-compose up -d nginx mysql

cd /.../cbdb-online-main-server

php artisan up
```

### 当数据库的 datadupm 中有 UTF8MB4 字符集的字符时（比如使用 Navicat 时）

导入的时候，务必打开 .sql 文件，检查文件头是否有 SET NAMES utf8mb4。

如果没有，请手动添加。

### 合并分支与 continuous development

1. 合并分支时，解决 conflict 问题之后，在 Pull requests 界面中使用 Squash and merge 按钮进行分支合并。

2. 合并之后，在服务器环境中的 /.../cbdb-online-main-server 下运行 git pull 即可。建议 pull 之后重启一次。

### 不使用 git pull 更新本地录入系统

先备份本地 .env 文件，以及 vendor、logs 文件夹，将前述三个文件/文件夹复制至从 github 下载的 CBDB 在线录入系统最新源代码解压后的根目录即可。

### path for API codes

https://github.com/cbdb-project/cbdb-online-main-server/tree/develop/app/Http/Controllers/Api

### 如何將 CBDB 部署於 Windows 本地環境

Contributed by [Chengxi YAN](https://github.com/yanchengxi)

https://github.com/cbdb-project/cbdb-online-main-server/issues/59

### How to setup TLS/SSL protocol for CBDB online inputting system

Contributed by [Yawei SUN](https://github.com/yaweisun)

https://github.com/cbdb-project/cbdb-online-main-server/issues/116

### 前端依賴問題排查

如果 `npm run dev` 或 `npm run build` 出現錯誤（如 `error code ELIFECYCLE`），可嘗試以下步驟：

**步驟 1：清理並重裝依賴**
```bash
rm -rf node_modules
npm cache clear --force
npm install
```

**步驟 2：重新執行構建**
```bash
npm run dev    # 開發環境
# 或
npm run build  # 生產環境
```

**說明**：
- `rm -rf node_modules` - 刪除所有已安裝的依賴
- `npm cache clear --force` - 清除 npm 緩存
- `npm install` - 根據 `package-lock.json` 重新安裝依賴

⚠️ **注意**：一般情況下**不需要**刪除 `package-lock.json`，該檔案確保依賴版本一致性。僅在確認鎖定檔案損壞時才考慮刪除。

### 在 Ubuntu 上安裝正確版本的 Node.js 和 npm

本專案需要 **Node.js 22** 和 **npm 10**（根據 `package.json` 中的 engines 要求）。

推薦使用 **nvm (Node Version Manager)** 來安裝和管理 Node.js 版本：

**步驟 1：安裝 nvm**
```bash
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash
```

**步驟 2：載入 nvm（或重新開啟終端）**
```bash
source ~/.bashrc
# 如果使用 zsh，則執行：source ~/.zshrc
```

**步驟 3：安裝 Node.js 22**
```bash
nvm install 22
nvm use 22
```

**步驟 4：驗證版本**
```bash
node --version  # 應該顯示 v22.x.x
npm --version   # 應該顯示 10.x.x
```

安裝完成後，npm 版本會自動符合 Node 22 對應的 npm 10。

**參考文檔**：[nvm-sh/nvm](https://github.com/nvm-sh/nvm)

### If your laravel.log(storage/logs/laravel.log) stops recording log

Please make sure that your web server user is the owner of this file

```
sudo chown caddy laravel.log
```

## SQLite 每週同步

本專案提供自動化腳本，將生產環境的數據庫匯出為 SQLite 格式，並同步到 HuggingFace 公開數據集 [cbdb/cbdb-sqlite](https://huggingface.co/datasets/cbdb/cbdb-sqlite) 供研究者下載使用。

最新下載入口：
- `https://huggingface.co/datasets/cbdb/cbdb-sqlite/resolve/main/latest.zip`
- `https://input.cbdb.fas.harvard.edu/latest.zip`

### 腳本位置

- **匯出腳本**：`scripts/export-daily-sqlite.sh` - 將 MySQL/MariaDB 表格匯出為 SQLite
- **同步腳本**：`scripts/weekly-sqlite-sync.sh` - 匯出、壓縮並上傳到 HuggingFace

### 前置安裝（Ubuntu）

```bash
# 安裝 zip 壓縮工具
sudo apt-get install zip

# 安裝 hf CLI
sudo apt-get install -y pipx
pipx install huggingface-hub
pipx ensurepath
```

### HuggingFace 認證設定

腳本支持兩種認證方式（二擇一）：

```bash
# 方式一：hf auth login（推薦，token 安全存儲於 ~/.cache/huggingface/）
hf auth login

# 方式二：HF_TOKEN 環境變數
export HF_TOKEN=hf_你的token

# 驗證認證狀態
hf auth status
```

**Access Token 設定**：
1. 前往 https://huggingface.co/settings/tokens
2. Create new token → 選擇 Fine-grained
3. 勾選 Repositories → Write 權限
4. 複製 token 並在服務器上執行認證

### Cron 定時任務

```bash
# 編輯 crontab
crontab -e

# 每週日凌晨 3 點執行（GMT+8）
0 3 * * 0 /path/to/cbdb-online-main-server/scripts/weekly-sqlite-sync.sh >> /var/log/cbdb-sqlite-sync.log 2>&1
```

### 手動執行

```bash
# 執行完整同步流程
bash scripts/weekly-sqlite-sync.sh

# 僅匯出 SQLite（不上傳）
bash scripts/export-daily-sqlite.sh
```

### 腳本流程說明

`weekly-sqlite-sync.sh` 執行以下步驟：

1. **前置檢查**：確認 `zip`、`hf` 已安裝且 HuggingFace 認證有效
2. **匯出數據庫**：呼叫 `export-daily-sqlite.sh` 產生 `cbdb_YYYYMMDD.sqlite3` 與 `cbdb_YYYYMMDD.json`
3. **壓縮檔案**：產生 `cbdb_YYYYMMDD.zip`（zip 內使用平面檔名，不包含絕對路徑）
4. **上傳到 HuggingFace**：將 `history/cbdb_YYYYMM/cbdb_YYYYMMDD.zip`、`latest.zip`、`metadata/YYYY-MM/YYYY-MM-DD.json` 及 `latest.json`（metadata 副本）以單一 commit 上傳至數據集倉庫
5. **清理**：刪除所有臨時檔案（包括原始 SQLite 匯出檔與 metadata）
