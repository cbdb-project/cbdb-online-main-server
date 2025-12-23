#!/bin/bash
# FrankenPHP 容器启动脚本
# 用于开发环境，处理依赖安装和缓存清理

# set -e  # 注释掉以便调试，允许脚本在错误后继续运行

echo "🧟 FrankenPHP 容器启动中..."

# 1. 确保 .env 文件存在
if [ ! -f ".env" ]; then
    echo "⚠️  警告: .env 文件不存在"
    if [ -f ".env.docker.example" ]; then
        echo "📋 复制 .env.docker.example 到 .env"
        cp .env.docker.example .env
    fi
fi

# 2. 检查 APP_KEY 是否存在
if ! grep -q "APP_KEY=base64:" .env 2>/dev/null; then
    echo "🔑 生成 APP_KEY..."
    php artisan key:generate --ansi || true
fi

# 3. 执行部署脚本 (集成 deploy.sh)
if [ -f "deploy.sh" ]; then
    echo "🚀 执行 deploy.sh..."
    # 赋予执行权限并运行
    chmod +x deploy.sh
    bash deploy.sh
else
    echo "❌ 错误: 找不到 deploy.sh"
    exit 1
fi

# 4. 确保 storage 和 bootstrap/cache 有正确权限
#echo "🔒 设置目录权限..."
#chown -R www-data:www-data storage bootstrap/cache database 2>/dev/null || true
#chmod -R 775 storage bootstrap/cache database 2>/dev/null || true

# 5. 处理 SQLite 数据库位置
# Single Source of Truth: /app/db-data/database.sqlite3 (Docker Volume，持久化)
# 初始化源: /app/database/database.sqlite3 (仅在首次初始化时使用)
DB_DIR="/app/db-data"
DB_FILE="$DB_DIR/database.sqlite3"
INIT_DB_FILE="/app/database/database.sqlite3"

# 确保目录存在
mkdir -p "$DB_DIR"
chown -R www-data:www-data "$DB_DIR"
chmod -R 775 "$DB_DIR"

# 数据库初始化逻辑（db-data 为 SoT）
if [ -f "$DB_FILE" ]; then
    echo "✅ 使用持久化数据库 (SoT): $DB_FILE"
elif [ -f "$INIT_DB_FILE" ]; then
    echo "🔄 首次初始化: 从 $INIT_DB_FILE 复制到 $DB_FILE"
    cp "$INIT_DB_FILE" "$DB_FILE"
    echo "✅ 初始化完成，后续将使用 $DB_FILE 作为 SoT"
    # 确保文件权限
    chown www-data:www-data "$DB_FILE"
    chmod 664 "$DB_FILE"
else
    echo "🆕 未找到数据库文件，创建空数据库..."
    touch "$DB_FILE"
    chown www-data:www-data "$DB_FILE"
    chmod 664 "$DB_FILE"
fi

# 6. 更新 .env 中的数据库路径
echo "🔧 更新 .env 数据库路径..."
if [ -f ".env" ]; then
    # 使用 sed 替换 DB_DATABASE 路径
    sed -i "s|^DB_DATABASE=.*|DB_DATABASE=$DB_FILE|" .env
    echo "✅ 数据库路径已更新为: $DB_FILE"
fi

# 7. 显示 SQLite 信息
if [ -f "$DB_FILE" ]; then
    echo "📊 数据库状态:"
    ls -lh "$DB_FILE"
else
    echo "⚠️  SQLite 数据库文件不存在，可能需要创建或迁移"
fi

echo "✨ FrankenPHP 准备就绪！"
echo "🌐 服务将在端口 80 启动..."

# 10. 检查并创建默认管理员账户
echo "🔍 检查管理员账户..."
# 使用 grep 检查用户列表中是否存在 admin@example.com
if ! php artisan cbdb:manage-user --list | grep -q "admin@example.com"; then
    echo "👤 未检测到管理员账户，正在创建..."
    # 创建超级管理员 (active=1, role=super-admin)
    if php artisan cbdb:manage-user \
        --email="admin@example.com" \
        --name="System Admin" \
        --password="password" \
        --active=1 \
        --role="super-admin" \
        --no-interaction; then
        echo "✅ 管理员账户已创建: admin@example.com / password"
    else
        echo "❌ 管理员账户创建失败！"
        echo "🐛 容器将保持运行以便调试..."
        echo "💡 使用以下命令进入容器调试:"
        echo "   docker exec -it cbdb-frankenphp /bin/bash"
        echo "💡 或者使用 docker-compose:"
        echo "   docker-compose exec frankenphp /bin/bash"
        echo ""
        echo "⏸️  容器将保持运行状态..."
        # 保持容器运行，等待手动调试
        tail -f /dev/null
    fi
else
    echo "✅ 管理员账户已存在: admin@example.com"
fi

# 11. 启动 FrankenPHP（替代 php-fpm）
# 使用 Classic 模式（兼容开发环境，支持热重载）
# 如需 Worker 模式性能，可以改为: frankenphp octane:start
exec frankenphp run --config /etc/caddy/Caddyfile
