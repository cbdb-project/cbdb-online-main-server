#!/bin/bash

# CBDB Online Main Server 部署脚本
# 用于在服务器上部署应用时执行必要的更新操作

set -euo pipefail

bg_pids=()

cleanup_background_jobs() {
  for pid in "${bg_pids[@]:-}"; do
    kill "$pid" 2>/dev/null || true
  done
}

trap cleanup_background_jobs EXIT

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

# 2. 并行安装后端与前端依赖，并重建前端资源
# 注：目前保留 dev 依赖，以兼容现行服务器流程
echo "并行更新 Composer 依赖与前端资源..."
composer install --optimize-autoloader --no-interaction &
bg_pids+=($!)

(
  npm ci
  npm run build
) &
bg_pids+=($!)

for pid in "${bg_pids[@]}"; do
  wait "$pid"
done
bg_pids=()

# 3. 清除缓存
echo "清除应用缓存..."
php artisan optimize:clear

# 4. 重建缓存
echo "重建缓存..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 5. 確認 CHGIS 底圖（缺檔則自 HuggingFace 下載；失敗不中斷部署）
echo "檢查 CHGIS 底圖..."
php artisan cbdb:fetch-chgis-map || echo "警告: CHGIS 底圖下載失敗，地圖功能將於首次訪問時重試"

echo "部署完成！"
