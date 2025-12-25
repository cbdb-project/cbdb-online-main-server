#!/bin/bash

# 此腳本用於補足 Laravel 運行所需的 8 個表結構，
# 這些表在 CBDB 官方發布的 77 表 SQLite 資料庫中並不存在。

# 容器內或專案根目錄下的路徑
DB_FILE="db-data/database.sqlite3"

if [ ! -f "$DB_FILE" ]; then
    echo "❌ 錯誤: 找不到資料庫文件 $DB_FILE"
    exit 1
fi

echo "🔍 檢查資料庫狀態..."

# 需要檢查是否存在的目標表
TARGET_TABLES=("CBDB__NAME_FTS" "CBDB__TRAD_SIMP_MAP" "migrations" "operations" "password_resets" "personal_access_tokens" "pinyin" "users")
EXISTING_TABLES=""

for table in "${TARGET_TABLES[@]}"; do
    if sqlite3 "$DB_FILE" "SELECT name FROM sqlite_master WHERE type='table' AND name='$table';" | grep -q "$table"; then
        EXISTING_TABLES="$EXISTING_TABLES$table "
    fi
done

if [ ! -z "$EXISTING_TABLES" ]; then
    echo "❌ 錯誤: 發現已存在的表: $EXISTING_TABLES"
    echo "🚨 該資料庫文件可能已被 Patch 過，或者不是原始的 CBDB Release 文件。"
    echo "🚫 為了防止數據損壞，腳本已停止執行。"
    exit 1
fi

echo "⏳ 正在為 $DB_FILE 補足 8 個表的 Schema..."

sqlite3 "$DB_FILE" <<'EOF'
CREATE TABLE IF NOT EXISTS "CBDB__NAME_FTS" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "c_personid" INTEGER NOT NULL,
    "name_type_code" smallint DEFAULT NULL,
    "name_type_desc" varchar(32) NOT NULL,
    "name_type_desc_chn" varchar(32) NOT NULL,
    "search_term" varchar(100) NOT NULL,
    "full_name" varchar(100) NOT NULL,
    "source" varchar(32) NOT NULL,
    "source_key" varchar(255) DEFAULT NULL,
    "is_simplified" tinyint(1) NOT NULL DEFAULT '0',
    "created_at" TEXT NULL DEFAULT NULL,
    "updated_at" TEXT NULL DEFAULT NULL
);
CREATE TABLE IF NOT EXISTS "CBDB__TRAD_SIMP_MAP" (
    "trad_char" varbinary(4) NOT NULL,
    "simp_char" varbinary(4) NOT NULL,
    PRIMARY KEY ("trad_char")
);
CREATE TABLE IF NOT EXISTS "migrations" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "migration" varchar(255) NOT NULL,
    "batch" INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS "operations" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "user_id" INTEGER NOT NULL,
    "c_personid" INTEGER NOT NULL,
    "op_type" smallint NOT NULL,
    "resource" varchar(255) NOT NULL,
    "resource_id" varchar(255) NOT NULL DEFAULT '',
    "resource_data" TEXT NOT NULL,
    "resource_original" TEXT,
    "created_at" TEXT NULL DEFAULT NULL,
    "updated_at" TEXT NULL DEFAULT NULL,
    "crowdsourcing_status" smallint NOT NULL DEFAULT '0',
    "rate" smallint NOT NULL DEFAULT '0'
);
CREATE TABLE IF NOT EXISTS "password_resets" (
    "email" varchar(255) NOT NULL,
    "token" varchar(255) NOT NULL,
    "created_at" TEXT NULL DEFAULT NULL
);
CREATE TABLE IF NOT EXISTS "personal_access_tokens" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "tokenable_type" varchar(255) NOT NULL,
    "tokenable_id" INTEGER NOT NULL,
    "name" varchar(255) NOT NULL,
    "token" varchar(64) NOT NULL,
    "abilities" TEXT,
    "last_used_at" TEXT NULL DEFAULT NULL,
    "expires_at" TEXT NULL DEFAULT NULL,
    "created_at" TEXT NULL DEFAULT NULL,
    "updated_at" TEXT NULL DEFAULT NULL
);
CREATE TABLE IF NOT EXISTS "pinyin" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "lastname_chn" varchar(10) DEFAULT NULL,
    "lastname_pinyin" varchar(30) DEFAULT NULL
);
CREATE TABLE IF NOT EXISTS "users" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "name" varchar(255) NOT NULL,
    "email" varchar(255) NOT NULL,
    "password" varchar(255) NOT NULL,
    "institution" varchar(255) DEFAULT NULL,
    "avatar" varchar(255) NOT NULL DEFAULT 'avatar5.png',
    "settings" TEXT,
    "confirmation_token" varchar(255) NOT NULL,
    "is_active" smallint NOT NULL DEFAULT '0',
    "is_admin" smallint NOT NULL DEFAULT '0',
    "remember_token" varchar(100) DEFAULT NULL,
    "created_at" TEXT NULL DEFAULT NULL,
    "updated_at" TEXT NULL DEFAULT NULL
);
EOF

if [ $? -eq 0 ]; then
    echo "✅ Schema 補足成功。"
else
    echo "❌ Schema 補足失敗。"
    exit 1
fi
