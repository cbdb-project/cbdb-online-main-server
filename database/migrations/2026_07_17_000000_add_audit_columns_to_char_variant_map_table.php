<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * char_variant_map 註冊進 config/code_table_writes.php 後，CodeTableCreateHandler
 * 透過 ToolsRepository::timestamp() 無條件寫入 c_created_by/c_created_date（既有
 * CBDB 代碼表慣例欄位），但本表建表時只加了 Laravel 標準 timestamps()、沒有這 4 欄，
 * 導致 /api/v2/create 對 char-variant-map 一定會因 SQL 錯誤失敗。
 * 見 docs/CHAR_VARIANT_MAP_CALL_SITE_WIRING_PLAN.md 步驟 7。
 */
return new class () extends Migration {
    public function up(): void {
        Schema::table('char_variant_map', function (Blueprint $table) {
            column_comment($table->string('c_created_by', 255)->nullable(), '建檔者');
            column_comment($table->dateTime('c_created_date')->nullable(), '建檔時間');
            column_comment($table->string('c_modified_by', 255)->nullable(), '最後修改者');
            column_comment($table->dateTime('c_modified_date')->nullable(), '最後修改時間');
        });
    }

    public function down(): void {
        Schema::table('char_variant_map', function (Blueprint $table) {
            $table->dropColumn(['c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date']);
        });
    }
};
