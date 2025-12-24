# CBDB Online - Docker 开发环境

使用 Docker Compose 快速搭建 CBDB Online 开发环境，无需安装 PHP、Composer、MySQL 等依赖。

## 🆕 Docker 架构

本项目使用 **FrankenPHP** 作为 Docker 架构，提供现代化的 PHP 应用服务器体验。

### ⚡ 架构特点
- 架构：单容器集成 Web 服务器（替代传统 PHP-FPM + Nginx）
- 性能：Classic 模式适合开发，Worker 模式性能可提升 10 倍以上
- 特性：支持 HTTP/2、HTTP/3，配置简单
- 容器名：`cbdb-frankenphp`

## 目录结构

```
/
├── docker/
│   ├── Dockerfile      # FrankenPHP Dockerfile
│   ├── Caddyfile       # Caddy Web 服务器配置
│   ├── entrypoint.sh   # 容器启动脚本
│   └── php.ini         # PHP 配置
├── docker-compose.yml  # Docker Compose 配置
├── db-data/            # [新] SQLite 数据库持久化目录 (Docker Volume)
├── database/           # 数据库模板目录
│   └── database.sqlite3  # 初始数据库模板
├── .env                # 环境配置文件
└── .env.docker.example # Docker 环境配置示例
```

**默认配置**：
- 架构：FrankenPHP（1 个容器）
- 数据库：SQLite (`/app/db-data/database.sqlite3`)
- 端口：8000
- 工作目录：`/app`

## 技术特点

### SQLite 安装方式

本项目 Dockerfile 从 SQLite 官网下载并编译安装最新版本（3.45.0+），而不是使用 `apt install sqlite3`。

**原因**：Ubuntu 24.04 LTS 的 apt 仓库中的 SQLite3 版本存在已知问题，可能导致数据库兼容性或性能问题。从官网编译可以确保：
- 使用最新稳定版本
- 避免发行版特定的补丁问题
- 获得最佳性能和最新特性

如需更新 SQLite 版本，修改 Dockerfile 中的环境变量：
```dockerfile
ENV SQLITE_VERSION=3450000  # 对应 3.45.0
ENV SQLITE_YEAR=2024
```

### FrankenPHP 架构特点

FrankenPHP 是新一代 PHP 应用服务器，将 PHP 与现代 Web 服务器（Caddy）集成：

**优势**：
- ✅ 架构简化：单容器替代 PHP-FPM + Nginx 双容器
- ✅ 性能提升：Worker 模式下性能可提升 10 倍以上
- ✅ 现代协议：原生支持 HTTP/2、HTTP/3
- ✅ 配置简单：使用 Caddyfile 替代复杂的 Nginx 配置
- ✅ 自动 HTTPS：内置自动证书管理

**两种运行模式**：
1. **Classic 模式**（默认）：兼容传统 PHP 应用，性能与 PHP-FPM 相当
2. **Worker 模式**（Octane）：应用常驻内存，性能大幅提升（需要 Laravel Octane）

**适用场景**：
- ✅ 单服务器部署（搭配 SQLite 完美组合）
- ✅ 中小型应用（大部分 Laravel 应用）
- ✅ 开发环境（配置更简单）
- ⚠️ 不适合多服务器横向扩展（建议使用 MySQL/PostgreSQL）

## 快速开始

### 1. 准备 SQLite 数据库 (可选)

容器启动时会自动处理数据库初始化，逻辑如下：
1. **持久化优先**：如果 `db-data/database.sqlite3` 已存在，则直接使用。
2. **模板初始化**：如果持久化文件不存在，但 `database/database.sqlite3` 存在，则复制模板到持久化目录。
3. **自动创建**：如果两者都不存在，则创建一个空的数据库文件。

如果你想手动准备数据，可以：

**方式：从 MySQL 导出（如果已有 MySQL 数据）**
```bash
php artisan db:export-to-sqlite --output=database/database.sqlite3 --limit-records=5000
```
`db:export-to-sqlite` 默认为 `mysql` 连接，如需从其他连接导出可加上 `--source=connection_name`，也可以依需求附加 `--schema-only`、`--tables`、`--limit-records=5000`（限制每表导出笔数）等参数。

### 2. 配置环境变量

复制 Docker 环境配置示例：
```bash
cp .env.docker.example .env
```

编辑 `.env` 文件，确保数据库配置正确：
```env
DB_CONNECTION=sqlite
DB_DATABASE=/app/db-data/database.sqlite3
```

**重要**：路径必须是容器内的持久化路径 `/app/db-data/database.sqlite3`，容器启动脚本会自动检测并更新 `.env` 中的该路径。

### 3. 生成应用密钥（首次运行）

```bash
# 如果 .env 中 APP_KEY 为空，需要生成
docker compose run --rm app php artisan key:generate
```

### 4. 启动服务

```bash
docker compose up --build
```

首次启动会构建镜像，大约需要 3-5 分钟。容器启动时会自动执行 `composer install` 和缓存清理，无需手动操作。后续启动只需几秒钟。

### 5. 访问应用

打开浏览器访问：
```
http://localhost:8000
```

### 6. 初始管理员账户

容器首次启动（或数据库中不存在该用户时）会自动创建一个默认的超级管理员账户：

- **Email**: `admin@example.com`
- **Password**: `password`

你可以使用此账户登录后台管理系统。登录后**强烈建议**立即修改密码。


## 常用命令

### 启动服务
```bash
docker compose up        # 前台运行，查看日志
docker compose up -d     # 后台运行
```

### 停止服务
```bash
docker compose down      # 停止并删除容器
docker compose stop      # 仅停止容器
```

### 重新构建
```bash
docker compose up --build     # 重新构建并启动
docker compose build --no-cache  # 完全重新构建（不使用缓存）
```

### 执行 Laravel 命令

```bash
# 运行迁移
docker compose exec app php artisan migrate

# 清除缓存
docker compose exec app php artisan cache:clear
docker compose exec app php artisan config:clear
docker compose exec app php artisan route:clear
docker compose exec app php artisan view:clear

# 生成应用密钥
docker compose exec app php artisan key:generate

# 进入容器
docker compose exec app bash

# 运行 Composer
docker compose exec app composer install
docker compose exec app composer update
docker compose exec app composer dump-autoload

# 查看 FrankenPHP 状态
docker compose exec app frankenphp version
```

### 查看日志
```bash
docker compose logs        # 查看所有服务日志
docker compose logs app    # 查看应用日志
docker compose logs -f     # 实时查看日志
```

## 开发工作流

### 1. 修改代码
直接在本地编辑器修改代码，容器会自动映射最新的代码：
```bash
# 代码映射： . -> /app
```

刷新浏览器即可看到变化（无需重启容器）。

### 2. 拉取最新代码
```bash
git pull
# 浏览器刷新即可看到变化
```

### 3. 更新依赖
```bash
# 如果 composer.json 有变化
docker compose exec app composer install

# 如果需要重新构建镜像
docker compose up --build
```

### 4. 运行数据库迁移
```bash
docker compose exec app php artisan migrate
```

## 文件权限问题

如果遇到 `storage/` 或 `bootstrap/cache/` 权限错误：

```bash
docker compose exec app chown -R www-data:www-data storage bootstrap/cache
docker compose exec app chmod -R 775 storage bootstrap/cache
```

## 数据库管理

### 查看 SQLite 数据

**方式一：使用 SQLite 客户端**
```bash
# 安装 sqlite3（如果未安装）
# macOS
brew install sqlite

# Linux
sudo apt-get install sqlite3

# 打开数据库
sqlite3 database/database.sqlite3

# SQLite 命令
.tables              # 查看所有表
.schema table_name   # 查看表结构
SELECT * FROM users; # 执行查询
.quit                # 退出
```

**方式二：在容器内查看**
```bash
docker compose exec app sqlite3 /app/db-data/database.sqlite3
```

### 备份数据库
```bash
# 备份持久化目录下的数据库
cp db-data/database.sqlite3 db-data/database.sqlite3.backup
```

### 从 MySQL 重新导出
```bash
# 确保 .env 配置为 MySQL
php artisan db:export-to-sqlite --output=database/database.sqlite3

# 然后切换回 SQLite 配置并重启容器
docker compose restart
```

## 启用 Worker 模式（高性能）

如果需要更高性能，可以启用 Laravel Octane Worker 模式：

```bash
# 1. 安装 Laravel Octane
docker compose exec app composer require laravel/octane

# 2. 安装 FrankenPHP 驱动
docker compose exec app php artisan octane:install --server=frankenphp

# 3. 修改 docker/entrypoint.sh 最后一行
# 从: exec frankenphp run --config /etc/caddy/Caddyfile
# 改为: exec php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=80

# 4. 重新构建并启动
docker compose down
docker compose up --build
```

**Worker 模式注意事项**：
- ⚠️ 代码修改后需要重启容器才能生效
- ⚠️ 需要注意内存泄漏和全局状态管理
- ⚠️ 适合生产环境，不适合频繁修改代码的开发环境
- ✅ 性能可提升 10 倍以上

## 升级 PHP 版本

要升级到更高版本的 PHP，只需修改 `docker/Dockerfile` 第一行的镜像标签：

```dockerfile
# 当前版本
FROM dunglas/frankenphp:1-php8.4.15

# 升级示例
FROM dunglas/frankenphp:1-php8.5
```

**镜像标签格式说明**：
- `1`：FrankenPHP 主版本
- `php8.4.15`：具体的 PHP 版本（可选）
- 使用 `1-php8.4` 会自动使用该系列的最新补丁版本

然后重新构建：
```bash
docker compose down
docker compose up --build
```

## 故障排查

### 容器启动失败
```bash
# 查看详细日志
docker compose logs

# 检查端口占用
lsof -i :8000  # macOS/Linux
netstat -ano | findstr :8000  # Windows

# 删除所有容器重新开始
docker compose down
docker compose up --build
```

### 页面显示 500 错误
```bash
# 检查 Laravel 日志
docker compose exec app tail -f storage/logs/laravel.log

# 检查权限
docker compose exec app ls -la storage/
docker compose exec app chown -R www-data:www-data storage bootstrap/cache
```

### Composer 依赖安装失败
```bash
# 进入容器手动安装
docker compose exec app bash
composer install --verbose
```

### 数据库连接失败
检查 `.env` 文件：
- `DB_CONNECTION=sqlite`
- `DB_DATABASE=/app/db-data/database.sqlite3`（容器内持久化路径）
- 确保 `db-data/database.sqlite3` 文件存在且有读写权限

### 容器无响应

**问题：容器启动后访问 localhost:8000 无响应**
```bash
# 检查容器是否真的在运行
docker compose ps

# 检查日志
docker compose logs app

# 检查端口是否正确监听
docker compose exec app netstat -tlnp
```

**问题：Worker 模式下代码修改不生效**
```bash
# Worker 模式需要重启容器
docker compose restart app

# 或者修改 entrypoint.sh 切换回 Classic 模式进行开发
```

**问题：Caddyfile 语法错误**
```bash
# 测试 Caddyfile 配置
docker compose exec app frankenphp validate --config /etc/caddy/Caddyfile
```

## 生产部署注意事项

此 Docker 配置仅用于**开发环境**，生产环境需要：

1. 使用生产优化的 Dockerfile（多阶段构建、最小化镜像）
2. 配置 HTTPS
3. 使用生产级数据库（MySQL、PostgreSQL）
4. 配置日志收集
5. 设置健康检查
6. 使用环境变量管理敏感信息
7. 禁用调试模式（`APP_DEBUG=false`）

## 其他资源

- [Laravel 官方文档](https://laravel.com/docs)
- [Docker Compose 文档](https://docs.docker.com/compose/)
- [PHP Docker 官方镜像](https://hub.docker.com/_/php)

## 技术支持

遇到问题请：
1. 查看项目 Issues
2. 查看 Laravel 日志：`storage/logs/laravel.log`
3. 查看 Docker 日志：`docker compose logs`
