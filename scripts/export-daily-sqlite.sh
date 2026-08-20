#!/bin/bash
#
# 將指定的數據庫表格逐個導出到 SQLite 文件
# 輸出文件名：db-data/cbdb_daily_YYYYMMDD.sqlite3
# 對應 metadata：db-data/cbdb_daily_YYYYMMDD.json
#
# 使用方式：
#   ./scripts/export-daily-sqlite.sh
#
# 可選環境變數：
#   OUTPUT_DIR  - 輸出目錄（默認：db-data）
#   SOURCE_DB   - 源數據庫連接名稱（默認：mysql）
#

set -e

# 切換到項目根目錄
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$PROJECT_ROOT"

# 配置
OUTPUT_DIR="${OUTPUT_DIR:-db-data}"
SOURCE_DB="${SOURCE_DB:-mysql}"
DATE_SUFFIX=$(date +%Y%m%d)
OUTPUT_FILE="${OUTPUT_DIR}/cbdb_${DATE_SUFFIX}.sqlite3"
OUTPUT_META_FILE="${OUTPUT_DIR}/cbdb_${DATE_SUFFIX}.json"
HF_BASE_URL="https://huggingface.co/datasets/cbdb/cbdb-sqlite/resolve/main"
HF_MONTH="cbdb_${DATE_SUFFIX:0:6}"
HF_DATE="cbdb_${DATE_SUFFIX}"
HF_ZIP_NAME="cbdb_${DATE_SUFFIX}.zip"
HF_PATH="history/${HF_MONTH}/${HF_ZIP_NAME}"

# 要導出的表格列表
TABLES=(
    "ADDR_BELONGS_DATA"
    "ADDR_CODES"
    "ADMIN_CAT_CODES"
    "ADMIN_CAT_CODE_TYPE_REL"
    "ADMIN_CAT_TYPES"
    "ALTNAME_CODES"
    "ALTNAME_DATA"
    "APPOINTMENT_CODES"
    "APPOINTMENT_CODE_TYPE_REL"
    "APPOINTMENT_TYPES"
    "ASSOC_CODES"
    "ASSOC_CODE_TYPE_REL"
    "ASSOC_DATA"
    "ASSOC_TYPES"
    "ASSUME_OFFICE_CODES"
    "BIOG_ADDR_CODES"
    "BIOG_ADDR_DATA"
    "BIOG_INST_CODES"
    "BIOG_INST_DATA"
    "BIOG_MAIN"
    "BIOG_SOURCE_DATA"
    "BIOG_TEXT_DATA"
    "CHORONYM_CODES"
    "COUNTRY_CODES"
    "DYNASTIES"
    "ENTRY_CODES"
    "ENTRY_CODE_TYPE_REL"
    "ENTRY_DATA"
    "ENTRY_TYPES"
    "ETHNICITY_TRIBE_CODES"
    "EVENTS_ADDR"
    "EVENTS_DATA"
    "EVENT_CODES"
    "EXTANT_CODES"
    "GANZHI_CODES"
    "HOUSEHOLD_STATUS_CODES"
    "INDEXYEAR_TYPE_CODES"
    "KINREL_REDUCTION"
    "KINSHIP_CODES"
    "KIN_DATA"
    "KIN_MOURNING"
    "KIN_MOURNING_STEPS"
    "LITERARYGENRE_CODES"
    "MEASURE_CODES"
    "MERGED_PERSON_DATA"
    "NIAN_HAO"
    "OCCASION_CODES"
    "OFFICE_CATEGORIES"
    "OFFICE_CODES"
    "OFFICE_CODE_TYPE_REL"
    "OFFICE_TYPE_TREE"
    "PARENTAL_STATUS_CODES"
    "POSSESSION_ACT_CODES"
    "POSSESSION_ADDR"
    "POSSESSION_DATA"
    "POSTED_TO_ADDR_DATA"
    "POSTED_TO_OFFICE_DATA"
    "POSTING_DATA"
    "SCHOLARLYTOPIC_CODES"
    "SOCIAL_INSTITUTION_ADDR"
    "SOCIAL_INSTITUTION_ADDR_TYPES"
    "SOCIAL_INSTITUTION_ALTNAME_CODES"
    "SOCIAL_INSTITUTION_ALTNAME_DATA"
    "SOCIAL_INSTITUTION_CODES"
    "SOCIAL_INSTITUTION_NAME_CODES"
    "SOCIAL_INSTITUTION_TYPES"
    "STATUS_CODES"
    "STATUS_CODE_TYPE_REL"
    "STATUS_DATA"
    "STATUS_TYPES"
    "TEXT_BIBLCAT_CODES"
    "TEXT_BIBLCAT_CODE_TYPE_REL"
    "TEXT_BIBLCAT_TYPES"
    "TEXT_CODES"
    "TEXT_INSTANCE_DATA"
    "TEXT_ROLE_CODES"
    "TEXT_TYPE"
    "YEAR_RANGE_CODES"
)

# 確保輸出目錄存在
mkdir -p "$OUTPUT_DIR"

# 如果輸出文件已存在，先刪除
if [ -f "$OUTPUT_FILE" ]; then
    echo "移除現有文件: $OUTPUT_FILE"
    rm -f "$OUTPUT_FILE"
fi
if [ -f "$OUTPUT_META_FILE" ]; then
    echo "移除現有 metadata: $OUTPUT_META_FILE"
    rm -f "$OUTPUT_META_FILE"
fi

echo "================================================"
echo "CBDB 每日 SQLite 導出"
echo "================================================"
echo "輸出文件: $OUTPUT_FILE"
echo "源數據庫: $SOURCE_DB"
echo "表格數量: ${#TABLES[@]}"
echo "================================================"
echo ""

# 導出前先重建索引欄位（寫回來源資料庫）
echo "預處理：重建 BIOG_MAIN 索引年份..."
php artisan cbdb:rebuild-index-year --no-interaction
echo ""

echo "預處理：重建 BIOG_MAIN 索引地址..."
php artisan cbdb:rebuild-index-address --no-interaction
echo ""

# 計數器
TOTAL=${#TABLES[@]}
CURRENT=0
FAILED=0
FAILED_TABLES=()

# 逐個導出表格
for TABLE in "${TABLES[@]}"; do
    CURRENT=$((CURRENT + 1))
    echo "[$CURRENT/$TOTAL] 導出表格: $TABLE"

    if [ $CURRENT -eq 1 ]; then
        # 第一個表格：創建新文件
        if php artisan db:export-to-sqlite \
            --output="$OUTPUT_FILE" \
            --tables="$TABLE" \
            --source="$SOURCE_DB" \
            --chunk-size=1000 \
            --skip-row-count \
            --skip-space-check \
            --no-interaction; then
            echo "  ✓ 成功"
        else
            echo "  ✗ 失敗"
            FAILED=$((FAILED + 1))
            FAILED_TABLES+=("$TABLE")
        fi
    else
        # 後續表格：追加模式
        if php artisan db:export-to-sqlite \
            --output="$OUTPUT_FILE" \
            --tables="$TABLE" \
            --source="$SOURCE_DB" \
            --chunk-size=1000 \
            --skip-row-count \
            --skip-space-check \
            --append \
            --no-interaction; then
            echo "  ✓ 成功"
        else
            echo "  ✗ 失敗"
            FAILED=$((FAILED + 1))
            FAILED_TABLES+=("$TABLE")
        fi
    fi

    echo ""
done

echo "================================================"
echo "導出完成"
echo "================================================"
echo "成功: $((TOTAL - FAILED))/$TOTAL"
if [ $FAILED -gt 0 ]; then
    echo "失敗: $FAILED"
    echo "失敗的表格:"
    for T in "${FAILED_TABLES[@]}"; do
        echo "  - $T"
    done
fi
echo ""

if [ -f "$OUTPUT_FILE" ]; then
    FILE_SIZE=$(ls -lh "$OUTPUT_FILE" | awk '{print $5}')
    echo "輸出文件: $OUTPUT_FILE"
    echo "文件大小: $FILE_SIZE"
fi

# 產物自檢（#1251）：直接讀輸出檔的表清單，確認內容就是釋出契約允許的範圍。
#
# 為什麼要有這一步：上面的 allowlist 是「靜態意圖」，而 SqliteReleaseAllowlistTest 檢查的也只是
# 這份腳本的文字——任何一層間接（TABLES+= / 換個變數名餵 for 迴圈 / source 外部檔 / eval）都可能
# 讓實際匯出範圍與清單不一致，靜態檢查追不完。這一步檢查的是**真正要上傳的那個檔案**。
#
# 刻意寫成「裸呼叫 + set -e」而不是 `if ! php artisan ...; then ... exit 1; fi`：後者的結束碼可以
# 被 pipeline（`| tee`）、`&& false`、`|| true` 或漏掉的 exit 1 靜默吞掉，而那些都只能用字串比對來守。
# 裸呼叫沒有可以吞結束碼的位置，非零就直接中止整個腳本。
echo ""
echo "🔒 產物自檢：確認輸出檔只含公開 CBDB 資料表..."
php artisan cbdb:assert-sqlite-release-scope "$OUTPUT_FILE" --min-tables="${#TABLES[@]}" --no-interaction

echo "================================================"

# 如果有失敗，返回非零退出碼
if [ $FAILED -gt 0 ]; then
    exit 1
fi

# 產生 metadata
if [ -f "$OUTPUT_FILE" ]; then
    if command -v sha256sum &> /dev/null; then
        SHA256_SUM=$(sha256sum "$OUTPUT_FILE" | awk '{print $1}')
    elif command -v shasum &> /dev/null; then
        SHA256_SUM=$(shasum -a 256 "$OUTPUT_FILE" | awk '{print $1}')
    else
        echo "錯誤: 找不到 sha256sum 或 shasum，無法產生 metadata"
        exit 1
    fi

    GENERATED_AT_UTC=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
    cat > "$OUTPUT_META_FILE" <<EOF
{
  "sqlite_filename": "cbdb_${DATE_SUFFIX}.sqlite3",
  "sha256": "${SHA256_SUM}",
  "generated_at_utc": "${GENERATED_AT_UTC}",
  "format": "sqlite3",
  "huggingface_path": "${HF_PATH}",
  "huggingface_url": "${HF_BASE_URL}/${HF_PATH}"
}
EOF
    echo "metadata: $OUTPUT_META_FILE"
fi

exit 0
