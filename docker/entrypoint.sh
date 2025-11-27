#!/bin/bash
# Docker 容器啟動腳本
# 用於開發環境，處理依賴安裝和緩存清理

set -e

echo "🚀 CBDB 容器啟動中..."

# 檢查 vendor 目錄是否存在
if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ]; then
    echo "📦 安裝 Composer 依賴..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
else
    echo "✅ Composer 依賴已存在"
fi

# 清除所有緩存（開發環境）
echo "🧹 清除應用緩存..."
php artisan cache:clear || true
php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true

# 觸發 package discovery
echo "🔍 執行 package discovery..."
php artisan package:discover --ansi || true

# 確保 storage 和 bootstrap/cache 有正確權限
echo "🔒 設置目錄權限..."
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

echo "✨ CBDB 啟動完成！"

# 啟動 PHP-FPM
exec php-fpm
