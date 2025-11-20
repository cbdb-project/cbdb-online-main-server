# CBDB Online - Docker 开发环境

使用 Docker Compose 快速搭建 CBDB Online 开发环境，无需安装 PHP、Composer、MySQL 等依赖。

## 目录结构

```
/
├── docker/
│   ├── Dockerfile      # PHP 7.4 + SQLite + Composer 镜像
│   ├── nginx.conf      # Nginx 配置
│   └── php.ini         # PHP 配置
├── docker-compose.yml  # Docker Compose 配置文件
├── database/
│   └── database.sqlite3  # SQLite 数据库文件
├── .env                # 环境配置文件
└── .env.docker.example # Docker 环境配置示例
```

默认的 SQLite 文件位于 `database/database.sqlite3`，Docker Compose 会将 PHP 应用运行在名为 `cbdb-php` 的容器中（对应 `php` 服务）。

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

## 快速开始

### 1. 准备 SQLite 数据库

如果还没有 SQLite 数据库文件，可以：

**方式一：创建空数据库并运行迁移**
```bash
touch database/database.sqlite3
```

**方式二：从 MySQL 导出（如果已有 MySQL 数据）**
```bash
php artisan db:export-to-sqlite --output=database/database.sqlite3
```
`db:export-to-sqlite` 默认为 `mysql` 连接，如需从其他连接导出可加上 `--source=connection_name`，也可以依需求附加 `--schema-only`、`--tables` 等参数。

### 2. 配置环境变量

复制 Docker 环境配置示例：
```bash
cp .env.docker.example .env
```

编辑 `.env` 文件，确保数据库配置正确：
```env
DB_CONNECTION=sqlite
DB_DATABASE=/var/www/html/database/database.sqlite3
```

**重要**：路径必须是容器内的路径 `/var/www/html/database/database.sqlite3`，不是本地路径。

### 3. 生成应用密钥（首次运行）

```bash
# 如果 .env 中 APP_KEY 为空，需要生成
docker compose run --rm php php artisan key:generate
```

### 4. 启动服务

```bash
docker compose up --build
```

首次启动会构建镜像，大约需要 3-5 分钟。构建阶段会自动执行 `deploy.sh`（包含 `composer install`、快取清理与 config cache），因此任何对 `deploy.sh` 的修改都需要重新 `docker compose up --build` 才能生效。后续启动只需几秒钟。

### 5. 访问应用

打开浏览器访问：
```
http://localhost:8000
```

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
docker compose exec php php artisan migrate

# 清除缓存
docker compose exec php php artisan cache:clear
docker compose exec php php artisan config:clear
docker compose exec php php artisan route:clear
docker compose exec php php artisan view:clear

# 生成应用密钥
docker compose exec php php artisan key:generate

# 进入 PHP 容器
docker compose exec php bash

# 运行 Composer
docker compose exec php composer install
docker compose exec php composer update
docker compose exec php composer dump-autoload
```

### 查看日志
```bash
docker compose logs        # 查看所有服务日志
docker compose logs php    # 查看 PHP 服务日志
docker compose logs web    # 查看 Nginx 服务日志
docker compose logs -f     # 实时查看日志
```

## 开发工作流

### 1. 修改代码
直接在本地编辑器修改代码，容器会自动映射最新的代码：
```bash
# 代码映射： . -> /var/www/html
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
docker compose exec php composer install

# 如果需要重新构建镜像
docker compose up --build
```

### 4. 运行数据库迁移
```bash
docker compose exec php php artisan migrate
```

## 文件权限问题

如果遇到 `storage/` 或 `bootstrap/cache/` 权限错误：

```bash
docker compose exec php chown -R www-data:www-data storage bootstrap/cache
docker compose exec php chmod -R 775 storage bootstrap/cache
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
docker compose exec php sqlite3 /var/www/html/database/database.sqlite3
```

### 备份数据库
```bash
cp database/database.sqlite3 database/database.sqlite3.backup
```

### 从 MySQL 重新导出
```bash
# 确保 .env 配置为 MySQL
php artisan db:export-to-sqlite --output=database/database.sqlite3

# 然后切换回 SQLite 配置并重启容器
docker compose restart
```

## 升级 PHP 版本

要升级到更高版本的 PHP（如 PHP 8.1），只需修改 `docker/Dockerfile` 第一行：

```dockerfile
# 从
FROM php:7.4-fpm

# 改为
FROM php:8.1-fpm
```

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
docker compose exec php tail -f storage/logs/laravel.log

# 检查权限
docker compose exec php ls -la storage/
docker compose exec php chown -R www-data:www-data storage bootstrap/cache
```

### Composer 依赖安装失败
```bash
# 进入容器手动安装
docker compose exec php bash
composer install --verbose
```

### 数据库连接失败
检查 `.env` 文件：
- `DB_CONNECTION=sqlite`
- `DB_DATABASE=/var/www/html/database/database.sqlite3` （容器内路径，不是本地路径）
- 确保 `database/database.sqlite3` 文件存在且有读写权限

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
