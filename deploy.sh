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

# 6. person_change_index 初次部署自動全量回填
#    僅當「表已存在且為空」時觸發（典型情境：migration 已建表、尚未做初始回填）。
#    表不存在（尚未 migrate）或已有資料時皆略過，避免每次部署重跑昂貴回填。
#    探測輸出 PCI_STATE=<empty|filled|absent> 哨兵，以 grep 解析以容忍任何額外輸出；
#    探測或回填失敗都不中斷部署（與 CHGIS 步驟一致）。
echo "檢查 person_change_index 是否需要初始回填..."
pci_probe="$(php artisan tinker --execute="echo 'PCI_STATE='.(Schema::hasTable('person_change_index') ? (DB::table('person_change_index')->exists() ? 'filled' : 'empty') : 'absent');" 2>/dev/null || true)"
pci_state="$(printf '%s' "$pci_probe" | grep -oE 'PCI_STATE=(empty|filled|absent)' | head -n1 | cut -d= -f2 || true)"

case "$pci_state" in
  empty)
    echo "person_change_index 為空，開始初始全量回填（一次性，可能耗時）..."
    php artisan cbdb:rebuild-person-change-index \
      || echo "警告: person_change_index 初始回填失敗，可稍後手動執行 php artisan cbdb:rebuild-person-change-index"
    ;;
  filled)
    echo "person_change_index 已有資料，略過初始回填。"
    ;;
  absent)
    echo "person_change_index 表不存在，略過（待執行 migration 後再部署即會自動回填）。"
    ;;
  *)
    echo "警告: 無法判定 person_change_index 狀態（tinker 探測失敗），略過自動回填。"
    ;;
esac

echo "部署完成！"
