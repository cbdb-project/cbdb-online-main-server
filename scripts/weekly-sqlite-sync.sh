#!/bin/bash
#
# 每週 SQLite 匯出並同步到 HuggingFace
#
# 功能：
#   1. 執行 export-daily-sqlite.sh 匯出資料庫
#   2. 重新命名為 latest.db 並壓縮為 latest.zip
#   3. 上傳到 HuggingFace datasets/cbdb/cbdb-sqlite
#
# 前置要求：
#   - zip (zip 命令)
#   - hf CLI (pipx install huggingface-hub)
#   - HF_TOKEN 環境變數已設定（HuggingFace Access Token）
#
# 使用方式：
#   HF_TOKEN=hf_xxx ./scripts/weekly-sqlite-sync.sh
#
# Cron 設定範例（每週日凌晨 3 點）：
#   0 3 * * 0 HF_TOKEN=hf_xxx /path/to/scripts/weekly-sqlite-sync.sh >> /var/log/cbdb-sqlite-sync.log 2>&1
#

set -e

# 確保 HOME 環境變量已設置（cron 環境可能缺少）
if [ -z "$HOME" ]; then
    if command -v getent &> /dev/null; then
        export HOME=$(getent passwd "$(id -un)" | cut -d: -f6)
    else
        # macOS 或其他無 getent 的系統
        export HOME=$(eval echo "~$(id -un)")
    fi
fi

# 前置檢查：確認必要工具已安裝
check_requirements() {
    local missing=()

    if ! command -v zip &> /dev/null; then
        missing+=("zip (請安裝: sudo apt-get install zip)")
    fi

    if ! command -v hf &> /dev/null; then
        missing+=("hf (請安裝: pipx install huggingface-hub)")
    fi

    if [ ${#missing[@]} -gt 0 ]; then
        echo "錯誤: 缺少必要工具："
        for tool in "${missing[@]}"; do
            echo "  - $tool"
        done
        exit 1
    fi

    # 檢查 HF_TOKEN 是否已設定
    if [ -z "$HF_TOKEN" ]; then
        echo "錯誤: HF_TOKEN 環境變數未設定"
        echo "請先設定 HuggingFace Access Token："
        echo "  export HF_TOKEN=hf_你的token"
        echo ""
        echo "Token 可在此建立: https://huggingface.co/settings/tokens"
        echo "需要的權限: Repositories → Write"
        exit 1
    fi
}

# 顯示環境資訊（便於除錯）
show_environment() {
    echo "環境資訊:"
    echo "  - USER: $(whoami)"
    echo "  - HOME: $HOME"
    echo "  - HF_TOKEN: (已設定)"
    echo ""
}

check_requirements

# 設定
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
WORK_DIR="${PROJECT_ROOT}/db-data"
HF_DATASET_REPO="cbdb/cbdb-sqlite"

# 清理函數
cleanup() {
    echo "清理暫存檔案..."
    rm -f "${WORK_DIR}/latest.db"
    rm -f "${WORK_DIR}/latest.zip"
    rm -f "${WORK_DIR}/latest.json"
    # 刪除當天產生的原始 sqlite 匯出檔
    if [ -n "$SQLITE_FILE" ] && [ -f "$SQLITE_FILE" ]; then
        echo "刪除原始匯出檔: $SQLITE_FILE"
        rm -f "$SQLITE_FILE"
    fi
    if [ -n "$META_FILE" ] && [ -f "$META_FILE" ]; then
        echo "刪除原始 metadata: $META_FILE"
        rm -f "$META_FILE"
    fi
}
trap cleanup EXIT

cd "$PROJECT_ROOT"

echo "================================================"
echo "CBDB SQLite 每週同步（HuggingFace）"
echo "================================================"
echo "時間: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""

show_environment

# Step 1: 執行匯出腳本
echo "[1/4] 執行 SQLite 匯出腳本..."
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
echo "[2/4] 複製並重新命名為 latest.db..."
cp "$SQLITE_FILE" "${WORK_DIR}/latest.db"

# Step 3: 壓縮為 zip
echo ""
echo "[3/4] 壓縮為 latest.zip..."
rm -f "${WORK_DIR}/latest.zip"
zip -9 "${WORK_DIR}/latest.zip" "${WORK_DIR}/latest.db" > /dev/null

FILE_SIZE=$(ls -lh "${WORK_DIR}/latest.zip" | awk '{print $5}')
echo "壓縮完成，檔案大小: $FILE_SIZE"

# Step 4: 上傳到 HuggingFace
echo ""
echo "[4/4] 上傳到 HuggingFace ${HF_DATASET_REPO}..."

# 上傳 latest.zip
META_FILE="${WORK_DIR}/cbdb_daily_${DATE_SUFFIX}.json"
META_DATE="${DATE_SUFFIX:0:4}-${DATE_SUFFIX:4:2}-${DATE_SUFFIX:6:2}"
META_MONTH="${DATE_SUFFIX:0:4}-${DATE_SUFFIX:4:2}"
META_PATH="metadata/${META_MONTH}/${META_DATE}.json"

# hf CLI 會自動讀取 HF_TOKEN 環境變數，不需要 --token 參數
# 避免 token 出現在 process list 中

# 準備上傳檔案清單（latest.zip 必須，metadata 可選）
UPLOAD_ARGS=("${WORK_DIR}/latest.zip" "latest.zip")

if [ -f "$META_FILE" ]; then
    # 複製一份作為 latest.json（HuggingFace 不支援 symlink）
    cp "$META_FILE" "${WORK_DIR}/latest.json"
    UPLOAD_ARGS+=("$META_FILE" "$META_PATH")
    UPLOAD_ARGS+=("${WORK_DIR}/latest.json" "latest.json")
    echo "上傳 latest.zip、${META_PATH}、latest.json..."
else
    echo "警告: 找不到 metadata 檔案 $META_FILE，僅上傳 latest.zip"
fi

# 單次上傳所有檔案（同一個 commit）
hf upload "$HF_DATASET_REPO" \
    "${UPLOAD_ARGS[@]}" \
    --repo-type dataset \
    --commit-message "Update CBDB SQLite (${META_DATE})"

SYNC_STATUS="已上傳"

echo ""
echo "================================================"
echo "同步完成!"
echo "================================================"
echo "目標: https://huggingface.co/datasets/${HF_DATASET_REPO}"
echo "狀態: $SYNC_STATUS"
echo "檔案大小: $FILE_SIZE"
echo "時間: $(date '+%Y-%m-%d %H:%M:%S')"
echo "================================================"

# ==============================================================================
# [暫時停用] GitHub 同步
# 未來可能用於同步其他內容，保留以下代碼供參考
# ==============================================================================
#
# # --- GitHub 前置要求 ---
# # - git-lfs
# # - gh CLI 已登入
#
# # 僅在無法讀取系統級 git 配置時才跳過（避免影響 LFS 等系統配置）
# # if [ -f /etc/gitconfig ] && [ ! -r /etc/gitconfig ]; then
# #     export GIT_CONFIG_NOSYSTEM=1
# #     echo "注意: /etc/gitconfig 無法讀取，已設置 GIT_CONFIG_NOSYSTEM=1"
# # fi
#
# # --- GitHub 工具檢查 ---
# # if ! command -v gh &> /dev/null; then
# #     missing+=("gh (請安裝 GitHub CLI: https://cli.github.com/)")
# # fi
# # if ! command -v git-lfs &> /dev/null; then
# #     missing+=("git-lfs (請安裝: sudo apt-get install git-lfs)")
# # fi
# # if ! gh auth status &> /dev/null; then
# #     echo "錯誤: gh CLI 尚未登入，請先執行 'gh auth login'"
# #     echo "提示: 若在 cron 環境執行，請確保 GH_TOKEN 環境變量已設置"
# #     exit 1
# # fi
#
# # --- GitHub 同步邏輯 ---
# # GITHUB_REPO="cbdb-project/cbdb_sqlite"
# # TEMP_DIR=$(mktemp -d)
# #
# # cd "$TEMP_DIR"
# # git clone --depth=1 "https://github.com/${GITHUB_REPO}.git"
# # cd cbdb_sqlite
# #
# # GH_TOKEN=$(gh auth token 2>/dev/null)
# # if [ -n "$GH_TOKEN" ]; then
# #     git remote set-url origin "https://x-access-token:${GH_TOKEN}@github.com/${GITHUB_REPO}.git"
# # else
# #     echo "警告: 無法取得 GitHub token，推送可能會失敗"
# # fi
# #
# # git lfs install
# #
# # if [ -z "$(git config --get user.name)" ]; then
# #     git config user.name "sudoghut"
# # fi
# # if [ -z "$(git config --get user.email)" ]; then
# #     git config user.email "sudospace@gmail.com"
# # fi
# #
# # cp "${WORK_DIR}/latest.zip" ./latest.zip
# # mkdir -p "metadata/${META_MONTH}"
# # cp "$META_FILE" "${META_PATH}"
# # ln -sfn "${META_PATH}" latest.json
# #
# # git add latest.zip latest.json "${META_PATH}"
# #
# # if git diff --cached --quiet; then
# #     echo "檔案內容無變更，跳過推送"
# # else
# #     git commit -m "Update latest.zip ($(date '+%Y-%m-%d'))"
# #     git push origin master
# # fi
# #
# # rm -rf "$TEMP_DIR"
