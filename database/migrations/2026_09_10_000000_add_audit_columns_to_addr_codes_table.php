<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADDR_CODES 註冊進 config/code_table_writes.php（/api/v2/create 地名表新增）後，
 * CodeTableCreateHandler 透過 ToolsRepository::timestamp() 無條件寫入
 * c_created_by／c_created_date（CBDB 表的稽核欄慣例），但 ADDR_CODES 建表時
 * 從未加過這 4 欄——不補的話新增一定以「Unknown column 'c_created_by'」失敗。
 *
 * 姊妹表 ADDR_BELONGS_DATA 早在 2025_11_14_000000 就補齊了同一組欄位；
 * 先例另見 2026_07_17_000000_add_audit_columns_to_char_variant_map_table。
 */
return new class () extends Migration {
    public function up(): void {
        if (!Schema::hasTable('ADDR_CODES')) {
            return;
        }

        Schema::table('ADDR_CODES', function (Blueprint $table) {
            if (!Schema::hasColumn('ADDR_CODES', 'c_created_by')) {
                column_comment($table->string('c_created_by', 255)->nullable(), '建檔者');
            }
            if (!Schema::hasColumn('ADDR_CODES', 'c_created_date')) {
                column_comment($table->dateTime('c_created_date')->nullable(), '建檔時間');
            }
            if (!Schema::hasColumn('ADDR_CODES', 'c_modified_by')) {
                column_comment($table->string('c_modified_by', 255)->nullable(), '最後修改者');
            }
            if (!Schema::hasColumn('ADDR_CODES', 'c_modified_date')) {
                column_comment($table->dateTime('c_modified_date')->nullable(), '最後修改時間');
            }
        });
    }

    /**
     * 刻意**不刪欄**。
     *
     * 這四欄是稽核資料：一旦 API／`/codes` 寫過幾筆，欄位裡就是「誰在什麼時候建了這筆
     * 地名」的紀錄，rollback 一次就永久消失、無法從別處重建。而且 `up()` 是逐欄
     * `hasColumn` 判斷的——若某個部署在這支 migration 之前就已手動補過其中幾欄，
     * 無條件 drop 會連同它原本就有的歷史一起刪掉。
     *
     * 留著空欄位的成本只是四個 nullable 欄；刪掉的成本是不可回復的紀錄遺失。
     * 真的要移除請人工執行，並先確認欄位全空。
     */
    public function down(): void {
        // no-op（理由見上）
    }
};
