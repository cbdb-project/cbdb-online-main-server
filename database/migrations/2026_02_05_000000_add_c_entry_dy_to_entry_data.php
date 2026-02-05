<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 為 ENTRY_DATA 表添加 c_entry_dy 欄位
 *
 * 用於儲存入仕朝代代碼，外鍵參考 DYNASTIES 表
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        // 1. 添加欄位
        Schema::table('ENTRY_DATA', function (Blueprint $table) {
            $table->smallInteger('c_entry_dy')->nullable()->after('c_entry_nh_year');
        });

        // 2. 添加索引（用於 JOIN 效能優化）
        Schema::table('ENTRY_DATA', function (Blueprint $table) {
            $table->index('c_entry_dy', 'c_entry_dy_ENTRY_DATA_index');
        });

        // 3. 添加外鍵約束（僅 MySQL）
        if (is_mysql()) {
            Schema::table('ENTRY_DATA', function (Blueprint $table) {
                $table->foreign('c_entry_dy', 'ENTRY_DATA_ibfk_13')
                    ->references('c_dy')
                    ->on('DYNASTIES')
                    ->onDelete('cascade')
                    ->onUpdate('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        // 1. 移除外鍵約束（僅 MySQL）
        if (is_mysql()) {
            Schema::table('ENTRY_DATA', function (Blueprint $table) {
                $table->dropForeign('ENTRY_DATA_ibfk_13');
            });
        }

        // 2. 移除索引
        Schema::table('ENTRY_DATA', function (Blueprint $table) {
            $table->dropIndex('c_entry_dy_ENTRY_DATA_index');
        });

        // 3. 移除欄位
        Schema::table('ENTRY_DATA', function (Blueprint $table) {
            $table->dropColumn('c_entry_dy');
        });
    }
};
