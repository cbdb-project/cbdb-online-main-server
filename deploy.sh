#!/bin/bash

# CBDB Online Main Server 部署脚本
# 用于在服务器上部署应用时执行必要的更新操作

set -e

echo "开始部署..."

# 1. 生成版本号文件
echo "生成版本号文件..."
if [ -d .git ]; then
  git rev-parse --short=7 HEAD > version.txt
  echo "版本号: $(cat version.txt)"
else
  echo "unknown" > version.txt
  echo "警告: 未找到 .git 目录，version.txt 标记为 unknown"
fi

# 2. 安装/更新依赖
echo "更新 Composer 依赖..."
# 同时安装 dev 依赖以便运行 PHPUnit 测试
composer install --optimize-autoloader

# 3. 清除缓存
echo "清除应用缓存..."
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# 4. 优化缓存（可选，生产环境建议开启）
echo "优化缓存..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "部署完成！"
