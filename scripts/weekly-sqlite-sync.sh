#!/bin/bash
#
# 每週 SQLite 匯出並同步到 GitHub
#
# 功能：
#   1. 執行 export-daily-sqlite.sh 匯出資料庫
#   2. 重新命名為 latest.db 並壓縮為 latest.7z
#   3. 推送到 cbdb-project/cbdb_sqlite 倉庫
#
# 前置要求：
#   - p7zip-full (7z 命令)
#   - git-lfs
#   - gh CLI 已登入
#
# 使用方式：
#   ./scripts/weekly-sqlite-sync.sh
#
# Cron 設定範例（每週日凌晨 3 點）：
#   0 3 * * 0 /path/to/scripts/weekly-sqlite-sync.sh >> /var/log/cbdb-sqlite-sync.log 2>&1
#

set -e

# 前置檢查：確認必要工具已安裝
check_requirements() {
    local missing=()

    if ! command -v 7z &> /dev/null; then
        missing+=("7z (請安裝 p7zip-full: sudo apt-get install p7zip-full)")
    fi

    if ! command -v gh &> /dev/null; then
        missing+=("gh (請安裝 GitHub CLI: https://cli.github.com/)")
    fi

    if ! command -v git-lfs &> /dev/null; then
        missing+=("git-lfs (請安裝: sudo apt-get install git-lfs)")
    fi

    if [ ${#missing[@]} -gt 0 ]; then
        echo "錯誤: 缺少必要工具："
        for tool in "${missing[@]}"; do
            echo "  - $tool"
        done
        exit 1
    fi

    # 檢查 gh CLI 是否已登入
    if ! gh auth status &> /dev/null; then
        echo "錯誤: gh CLI 尚未登入，請先執行 'gh auth login'"
        exit 1
    fi
}

check_requirements

# 設定
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
WORK_DIR="${PROJECT_ROOT}/db-data"
GITHUB_REPO="cbdb-project/cbdb_sqlite"
TEMP_DIR=$(mktemp -d)

# 清理函數
cleanup() {
    echo "清理暫存檔案..."
    rm -rf "$TEMP_DIR"
    rm -f "${WORK_DIR}/latest.db"
    rm -f "${WORK_DIR}/latest.7z"
    # 刪除當天產生的原始 sqlite 匯出檔
    if [ -n "$SQLITE_FILE" ] && [ -f "$SQLITE_FILE" ]; then
        echo "刪除原始匯出檔: $SQLITE_FILE"
        rm -f "$SQLITE_FILE"
    fi
}
trap cleanup EXIT

cd "$PROJECT_ROOT"

echo "================================================"
echo "CBDB SQLite 每週同步"
echo "================================================"
echo "時間: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""

# Step 1: 執行匯出腳本
echo "[1/5] 執行 SQLite 匯出腳本..."
bash scripts/export-daily-sqlite.sh

# 找到今天產生的檔案
DATE_SUFFIX=$(date +%Y%m%d)
SQLITE_FILE="${WORK_DIR}/cbdb_daily_${DATE_SUFFIX}.sqlite3"

if [ ! -f "$SQLITE_FILE" ]; then
    echo "錯誤: 找不到匯出檔案 $SQLITE_FILE"
    exit 1
fi

echo "匯出檔案: $SQLITE_FILE"

# Step 2: 複製並重新命名
echo ""
echo "[2/5] 複製並重新命名為 latest.db..."
cp "$SQLITE_FILE" "${WORK_DIR}/latest.db"

# Step 3: 壓縮為 7z
echo ""
echo "[3/5] 壓縮為 latest.7z..."
rm -f "${WORK_DIR}/latest.7z"
7z a -mx=9 "${WORK_DIR}/latest.7z" "${WORK_DIR}/latest.db"

FILE_SIZE=$(ls -lh "${WORK_DIR}/latest.7z" | awk '{print $5}')
echo "壓縮完成，檔案大小: $FILE_SIZE"

# Step 4: 克隆倉庫並更新
echo ""
echo "[4/5] 克隆 $GITHUB_REPO 並更新..."
cd "$TEMP_DIR"
gh repo clone "$GITHUB_REPO" -- --depth=1
cd cbdb_sqlite

# 確保 LFS 已初始化
git lfs install

# 複製新檔案
cp "${WORK_DIR}/latest.7z" ./latest.7z

# Step 5: 提交並推送
echo ""
echo "[5/5] 提交並推送..."
git add latest.7z

# 檢查是否有變更需要提交
if git diff --cached --quiet; then
    echo "檔案內容無變更，跳過推送"
    SYNC_STATUS="無變更"
else
    git commit -m "Update latest.7z ($(date '+%Y-%m-%d'))"
    git push origin master
    SYNC_STATUS="已推送"
fi

echo ""
echo "================================================"
echo "同步完成!"
echo "================================================"
echo "狀態: $SYNC_STATUS"
echo "檔案大小: $FILE_SIZE"
echo "時間: $(date '+%Y-%m-%d %H:%M:%S')"
echo "================================================"
