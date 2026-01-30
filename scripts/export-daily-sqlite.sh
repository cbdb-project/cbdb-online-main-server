#!/bin/bash
#
# 將指定的數據庫表格逐個導出到 SQLite 文件
# 輸出文件名：db-data/cbdb_daily_YYYYMMDD.sqlite3
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
OUTPUT_FILE="${OUTPUT_DIR}/cbdb_daily_${DATE_SUFFIX}.sqlite3"

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

echo "================================================"
echo "CBDB 每日 SQLite 導出"
echo "================================================"
echo "輸出文件: $OUTPUT_FILE"
echo "源數據庫: $SOURCE_DB"
echo "表格數量: ${#TABLES[@]}"
echo "================================================"
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

echo "================================================"

# 如果有失敗，返回非零退出碼
if [ $FAILED -gt 0 ]; then
    exit 1
fi

exit 0
