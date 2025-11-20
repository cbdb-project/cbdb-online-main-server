#!/bin/bash

echo "🔄 切换到 SQLite 数据库..."

# 备份当前 .env
if [ -f .env ]; then
    cp .env .env.backup.$(date +%Y%m%d_%H%M%S)
    echo "✅ 已备份当前配置"
fi

# 创建 SQLite 数据库文件（如果不存在）
DB_PATH="database/database.sqlite"
if [ ! -f "$DB_PATH" ]; then
    touch "$DB_PATH"
    echo "✅ 已创建 SQLite 数据库文件"
fi

# 更新 .env 配置
if [[ "$OSTYPE" == "darwin"* ]]; then
    # macOS
    sed -i '' 's/DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
    sed -i '' "s|DB_DATABASE=.*|DB_DATABASE=$(pwd)/$DB_PATH|" .env
else
    # Linux
    sed -i 's/DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
    sed -i "s|DB_DATABASE=.*|DB_DATABASE=$(pwd)/$DB_PATH|" .env
fi

echo ""
echo "✅ 已切换到 SQLite！"
echo ""
echo "📋 下一步："
echo "   1. 从 MySQL 导出数据: php artisan db:export-to-sqlite --limit-records=5000"
echo "   2. 或者运行全新迁移: php artisan migrate:fresh"
echo "   3. 启动服务: php artisan serve"
