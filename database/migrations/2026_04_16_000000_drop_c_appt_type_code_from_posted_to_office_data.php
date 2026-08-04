<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 移除 POSTED_TO_OFFICE_DATA.c_appt_type_code 欄位，
 * 將 c_appt_code 型別從 varchar 改為 smallint 並加上外鍵。
 *
 * 背景：c_appt_type_code 原指向已不存在的 APPOINTMENT_TYPE_CODES 表，
 * 2025-03-21 已重構改用 c_appt_code 指向 APPOINTMENT_CODES，
 * 但 c_appt_type_code 欄位與舊外鍵一直未清除。
 */
return new class () extends Migration {
    public function up(): void {
        disable_foreign_keys();

        if (is_mysql()) {
            // 1. 移除舊外鍵、索引與欄位
            Schema::table('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
                $table->dropForeign('POSTED_TO_OFFICE_DATA_ibfk_1');
                $table->dropIndex('c_appt_type_code_POSTED_TO_OFFICE_DATA_index');
                $table->dropColumn('c_appt_type_code');
            });

            // 2. 將 c_appt_code 從 varchar(255) 轉為 smallint（與 APPOINTMENT_CODES.c_appt_code 一致）
            DB::statement("UPDATE POSTED_TO_OFFICE_DATA SET c_appt_code = NULL WHERE c_appt_code = ''");
            DB::statement('ALTER TABLE POSTED_TO_OFFICE_DATA MODIFY c_appt_code smallint DEFAULT NULL');

            // 3. 為 c_appt_code 建立索引與外鍵
            Schema::table('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
                $table->index('c_appt_code', 'c_appt_code_POSTED_TO_OFFICE_DATA_index');
                $table->foreign('c_appt_code', 'POSTED_TO_OFFICE_DATA_ibfk_1')
                    ->references('c_appt_code')
                    ->on('APPOINTMENT_CODES')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
            });
        }

        if (is_sqlite()) {
            // SQLite：直接用 Schema Builder 刪除欄位
            // 基礎 migration 的 sanitizer 會移除 inline KEY 定義，
            // 但 CONSTRAINT FK 定義會保留。SQLite 3.35+ 支援 ALTER TABLE DROP COLUMN，
            // 但如果有 FK 引用該欄位會報錯，故需先關閉 FK 檢查。
            // 使用原生 SQL 重建表以避開 FK 限制。
            $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name='POSTED_TO_OFFICE_DATA'");
            $originalSql = $row->sql;

            // 移除 c_appt_type_code 欄位定義及引用它的 FK 約束
            $lines = explode("\n", $originalSql);
            $filtered = [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                // 跳過 c_appt_type_code 欄位定義
                if (preg_match('/^[`"]?c_appt_type_code[`"]?\s/i', $trimmed)) {
                    continue;
                }
                // 跳過引用 c_appt_type_code 的 FK 約束（POSTED_TO_OFFICE_DATA_ibfk_1）
                if (preg_match('/CONSTRAINT.*c_appt_type_code/i', $trimmed)) {
                    continue;
                }
                $filtered[] = $line;
            }
            $newSql = implode("\n", $filtered);
            // 修正尾隨逗號
            $newSql = preg_replace('/,(\s*\))/m', '$1', $newSql);

            // 替換表名為臨時名稱
            $newSql = preg_replace(
                '/CREATE\s+TABLE\s+[`"]?POSTED_TO_OFFICE_DATA[`"]?/i',
                'CREATE TABLE "POSTED_TO_OFFICE_DATA_rebuild"',
                $newSql
            );

            // 取得保留的欄位清單
            $columns = Schema::getColumnListing('POSTED_TO_OFFICE_DATA');
            $keepColumns = array_filter($columns, fn ($col) => $col !== 'c_appt_type_code');
            $colList = implode(', ', array_map(fn ($c) => "\"$c\"", $keepColumns));

            DB::statement($newSql);
            DB::statement("INSERT INTO \"POSTED_TO_OFFICE_DATA_rebuild\" SELECT $colList FROM \"POSTED_TO_OFFICE_DATA\"");
            DB::statement('DROP TABLE "POSTED_TO_OFFICE_DATA"');
            DB::statement('ALTER TABLE "POSTED_TO_OFFICE_DATA_rebuild" RENAME TO "POSTED_TO_OFFICE_DATA"');
        }

        enable_foreign_keys();
    }

    public function down(): void {
        disable_foreign_keys();

        if (is_mysql()) {
            Schema::table('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
                $table->dropForeign('POSTED_TO_OFFICE_DATA_ibfk_1');
                $table->dropIndex('c_appt_code_POSTED_TO_OFFICE_DATA_index');
            });

            DB::statement("ALTER TABLE POSTED_TO_OFFICE_DATA MODIFY c_appt_code varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL");

            Schema::table('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
                $table->smallInteger('c_appt_type_code')->nullable()->after('c_ly_range');
                $table->index('c_appt_type_code', 'c_appt_type_code_POSTED_TO_OFFICE_DATA_index');
                $table->foreign('c_appt_type_code', 'POSTED_TO_OFFICE_DATA_ibfk_1')
                    ->references('c_appt_code')
                    ->on('APPOINTMENT_CODES')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
            });
        }

        if (is_sqlite()) {
            Schema::table('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
                $table->smallInteger('c_appt_type_code')->nullable();
            });
        }

        enable_foreign_keys();
    }
};
