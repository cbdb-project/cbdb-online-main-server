#!/bin/bash
# FrankenPHP 容器启动脚本
# 用于开发环境，处理依赖安装和缓存清理

set -e

echo "🧟 FrankenPHP 容器启动中..."

# 1. 生成版本号文件
echo "📝 生成版本号..."
if [ -d .git ]; then
  git rev-parse --short=7 HEAD > version.txt 2>/dev/null || echo "unknown" > version.txt
else
  echo "unknown" > version.txt
fi

# 2. 检查并安装 Composer 依赖
# 注意：这一步很重要，因为 vendor 目录是独立的 volume
if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ]; then
    echo "📦 安装 Composer 依赖..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
else
    echo "✅ Composer 依赖已存在"
fi

# 3. 确保 .env 文件存在
if [ ! -f ".env" ]; then
    echo "⚠️  警告: .env 文件不存在"
    if [ -f ".env.docker.example" ]; then
        echo "📋 复制 .env.docker.example 到 .env"
        cp .env.docker.example .env
    fi
fi

# 4. 检查 APP_KEY 是否存在
if ! grep -q "APP_KEY=base64:" .env 2>/dev/null; then
    echo "🔑 生成 APP_KEY..."
    php artisan key:generate --ansi || true
fi

# 5. 清除所有缓存（开发环境）
echo "🧹 清除应用缓存..."
php artisan cache:clear 2>/dev/null || true
php artisan config:clear 2>/dev/null || true
php artisan route:clear 2>/dev/null || true
php artisan view:clear 2>/dev/null || true

# 6. 触发 package discovery
echo "🔍 执行 package discovery..."
php artisan package:discover --ansi || true

# 7. 确保 storage 和 bootstrap/cache 有正确权限
echo "🔒 设置目录权限..."
chown -R www-data:www-data storage bootstrap/cache database 2>/dev/null || true
chmod -R 775 storage bootstrap/cache database 2>/dev/null || true

# 8. 显示 SQLite 信息（如果使用）
if [ -f "database/database.sqlite3" ]; then
    echo "✅ SQLite 数据库文件存在: database/database.sqlite3"
    ls -lh database/database.sqlite3
else
    echo "⚠️  SQLite 数据库文件不存在，可能需要创建或迁移"
fi

echo "✨ FrankenPHP 准备就绪！"
echo "🌐 服务将在端口 80 启动..."

# 9. 启动 FrankenPHP（替代 php-fpm）
# 使用 Classic 模式（兼容开发环境，支持热重载）
# 如需 Worker 模式性能，可以改为: frankenphp octane:start
exec frankenphp run --config /etc/caddy/Caddyfile
