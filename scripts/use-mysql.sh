#!/bin/bash

echo "🔄 切换到 MySQL 数据库..."

# 更新 .env 配置
if [[ "$OSTYPE" == "darwin"* ]]; then
    # macOS
    sed -i '' 's/DB_CONNECTION=.*/DB_CONNECTION=mysql/' .env
    sed -i '' 's|DB_DATABASE=.*|DB_DATABASE=homestead|' .env
else
    # Linux
    sed -i 's/DB_CONNECTION=.*/DB_CONNECTION=mysql/' .env
    sed -i 's|DB_DATABASE=.*|DB_DATABASE=homestead|' .env
fi

echo "✅ 已切换到 MySQL"
echo ""
echo "⚠️  请确保 MySQL 服务正在运行"
echo ""
echo "📋 如需创建数据库："
echo "   mysql -u root -p -e \"CREATE DATABASE homestead CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\""
