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
#   - HuggingFace 認證（二擇一）：
#     1. hf auth login（推薦，token 存於 ~/.cache/huggingface/）
#     2. HF_TOKEN 環境變數
#
# 使用方式：
#   ./scripts/weekly-sqlite-sync.sh
#
# Cron 設定範例（每週日凌晨 3 點）：
#   0 3 * * 0 /path/to/scripts/weekly-sqlite-sync.sh >> /var/log/cbdb-sqlite-sync.log 2>&1
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

    # 檢查 HuggingFace 認證（支持 hf auth login 或 HF_TOKEN 環境變數）
    # 嘗試驗證認證有效性（支持不同版本的 hf CLI）
    local auth_ok=false

    # 方法 1: hf auth whoami（新版本標準命令）
    if hf auth whoami &> /dev/null; then
        auth_ok=true
    # 方法 2: hf whoami（部分版本使用此命令）
    elif hf whoami &> /dev/null; then
        auth_ok=true
    # 方法 3: 檢查 token 檔案或環境變數（fallback，無法驗證有效性）
    elif [ -n "$HF_TOKEN" ] || [ -f "$HOME/.cache/huggingface/token" ]; then
        echo "警告: 無法驗證 HuggingFace token 有效性（whoami 命令不可用）"
        echo "將在 hf upload 時進行實際驗證"
        echo ""
        auth_ok=true
    fi

    if [ "$auth_ok" = false ]; then
        echo "錯誤: 未找到 HuggingFace 認證"
        echo ""
        echo "請使用以下任一方式設定："
        echo "  1. hf auth login（推薦）"
        echo "  2. export HF_TOKEN=hf_你的token"
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
    if [ -n "$HF_TOKEN" ]; then
        echo "  - HF 認證: HF_TOKEN 環境變數"
    else
        echo "  - HF 認證: hf auth login"
    fi
    echo ""
}

check_requirements

# 設定
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
WORK_DIR="${PROJECT_ROOT}/db-data"
PUBLIC_DIR="${PROJECT_ROOT}/public"
HF_DATASET_REPO="cbdb/cbdb-sqlite"
HF_STAGE_DIR=$(mktemp -d)

# 清理函數
cleanup() {
    echo "清理暫存檔案..."
    if [ -n "$HF_STAGE_DIR" ] && [ -d "$HF_STAGE_DIR" ]; then
        rm -rf "$HF_STAGE_DIR"
    fi
    rm -f "${WORK_DIR}/latest.db"
    rm -f "${WORK_DIR}/latest.zip"
    # 刪除當天產生的原始 sqlite 匯出檔
    if [ -n "$SQLITE_FILE" ] && [ -f "$SQLITE_FILE" ]; then
        echo "刪除原始匯出檔: $SQLITE_FILE"
        rm -f "$SQLITE_FILE"
    fi
    if [ -n "$ZIP_FILE" ] && [ -f "$ZIP_FILE" ]; then
        echo "刪除原始 zip: $ZIP_FILE"
        rm -f "$ZIP_FILE"
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
SQLITE_FILE="${WORK_DIR}/cbdb_${DATE_SUFFIX}.sqlite3"
ZIP_FILE="${WORK_DIR}/cbdb_${DATE_SUFFIX}.zip"
ZIP_NAME="cbdb_${DATE_SUFFIX}.zip"
HF_MONTH="cbdb_${DATE_SUFFIX:0:6}"
HF_DATE="cbdb_${DATE_SUFFIX}"
HF_ZIP_PATH="history/${HF_MONTH}/${ZIP_NAME}"

if [ ! -f "$SQLITE_FILE" ]; then
    echo "錯誤: 找不到匯出檔案 $SQLITE_FILE"
    exit 1
fi

echo "匯出檔案: $SQLITE_FILE"

# Step 2: 複製並重新命名
echo ""
echo "[2/4] 準備打包來源..."

# Step 3: 壓縮為 zip（含 metadata）
echo ""
echo "[3/4] 壓縮為 ${ZIP_NAME}..."
rm -f "$ZIP_FILE"
META_FILE="${WORK_DIR}/cbdb_${DATE_SUFFIX}.json"
if [ -f "$META_FILE" ]; then
    zip -9 "$ZIP_FILE" "$SQLITE_FILE" "$META_FILE" > /dev/null
else
    zip -9 "$ZIP_FILE" "$SQLITE_FILE" > /dev/null
fi

FILE_SIZE=$(ls -lh "$ZIP_FILE" | awk '{print $5}')
echo "壓縮完成，檔案大小: $FILE_SIZE"

# Step 4: 上傳到 HuggingFace
echo ""
echo "[4/4] 上傳到 HuggingFace ${HF_DATASET_REPO}..."

# 上傳 zip 與 metadata
META_DATE="${DATE_SUFFIX:0:4}-${DATE_SUFFIX:4:2}-${DATE_SUFFIX:6:2}"
META_MONTH="${DATE_SUFFIX:0:4}-${DATE_SUFFIX:4:2}"
META_PATH="metadata/${META_MONTH}/${META_DATE}.json"

# 準備暫存目錄，模擬 repo 內的檔案結構
mkdir -p "${HF_STAGE_DIR}/history/${HF_MONTH}"
cp "$ZIP_FILE" "${HF_STAGE_DIR}/${HF_ZIP_PATH}"
cp "$ZIP_FILE" "${HF_STAGE_DIR}/latest.zip"

if [ -f "$META_FILE" ]; then
    mkdir -p "${HF_STAGE_DIR}/metadata/${META_MONTH}"
    cp "$META_FILE" "${HF_STAGE_DIR}/${META_PATH}"
    cp "$META_FILE" "${HF_STAGE_DIR}/latest.json"
    echo "上傳 ${HF_ZIP_PATH}、latest.zip、${META_PATH}、latest.json..."
else
    echo "警告: 找不到 metadata 檔案 $META_FILE，僅上傳 ${HF_ZIP_PATH} 與 latest.zip"
fi

# 上傳整個目錄（所有檔案在同一個 commit）
hf upload "$HF_DATASET_REPO" \
    "$HF_STAGE_DIR" . \
    --repo-type dataset \
    --commit-message "Update CBDB SQLite (${META_DATE})"

SYNC_STATUS="已上傳"

echo ""
echo "將 latest.zip 複製到 public 目錄..."
mkdir -p "$PUBLIC_DIR"
cp "$ZIP_FILE" "${PUBLIC_DIR}/latest.zip"

echo ""
echo "================================================"
echo "同步完成!"
echo "================================================"
echo "目標: https://huggingface.co/datasets/${HF_DATASET_REPO}"
echo "狀態: $SYNC_STATUS"
echo "檔案大小: $FILE_SIZE"
echo "時間: $(date '+%Y-%m-%d %H:%M:%S')"
echo "================================================"
