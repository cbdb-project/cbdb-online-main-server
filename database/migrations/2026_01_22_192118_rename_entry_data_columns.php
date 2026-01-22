<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 重新命名 ENTRY_DATA 表格的欄位：
 * - c_nianhao_id → c_entry_nh_id（與其他表格的年號代碼欄位命名一致）
 * - c_parental_status → c_parental_status_code（與 PARENTAL_STATUS_CODES 表格的主鍵名稱一致）
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        $isMysql = DB::getDriverName() === 'mysql';

        // 1. 移除舊的外鍵約束（僅 MySQL）
        if ($isMysql) {
            Schema::table('ENTRY_DATA', function ($table) {
                // 移除 c_nianhao_id 的外鍵 (ENTRY_DATA_ibfk_9)
                $table->dropForeign('ENTRY_DATA_ibfk_9');
                // 移除 c_parental_status 的外鍵 (ENTRY_DATA_ibfk_10)
                $table->dropForeign('ENTRY_DATA_ibfk_10');
            });

            // 2. 移除舊的索引
            Schema::table('ENTRY_DATA', function ($table) {
                $table->dropIndex('c_nianhao_id_ENTRY_DATA_index');
                $table->dropIndex('c_parental_status');
            });
        }

        // 3. 重新命名欄位
        Schema::table('ENTRY_DATA', function ($table) {
            $table->renameColumn('c_nianhao_id', 'c_entry_nh_id');
            $table->renameColumn('c_parental_status', 'c_parental_status_code');
        });

        // 4. 建立新的索引（僅 MySQL）
        if ($isMysql) {
            Schema::table('ENTRY_DATA', function ($table) {
                $table->index('c_entry_nh_id', 'c_entry_nh_id_ENTRY_DATA_index');
                $table->index('c_parental_status_code', 'c_parental_status_code_ENTRY_DATA_index');
            });

            // 5. 重新建立外鍵約束
            Schema::table('ENTRY_DATA', function ($table) {
                $table->foreign('c_entry_nh_id', 'ENTRY_DATA_ibfk_9')
                    ->references('c_nianhao_id')
                    ->on('NIAN_HAO')
                    ->onDelete('cascade')
                    ->onUpdate('cascade');

                $table->foreign('c_parental_status_code', 'ENTRY_DATA_ibfk_10')
                    ->references('c_parental_status_code')
                    ->on('PARENTAL_STATUS_CODES')
                    ->onDelete('cascade')
                    ->onUpdate('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        $isMysql = DB::getDriverName() === 'mysql';

        // 1. 移除新的外鍵約束（僅 MySQL）
        if ($isMysql) {
            Schema::table('ENTRY_DATA', function ($table) {
                $table->dropForeign('ENTRY_DATA_ibfk_9');
                $table->dropForeign('ENTRY_DATA_ibfk_10');
            });

            // 2. 移除新的索引
            Schema::table('ENTRY_DATA', function ($table) {
                $table->dropIndex('c_entry_nh_id_ENTRY_DATA_index');
                $table->dropIndex('c_parental_status_code_ENTRY_DATA_index');
            });
        }

        // 3. 還原欄位名稱
        Schema::table('ENTRY_DATA', function ($table) {
            $table->renameColumn('c_entry_nh_id', 'c_nianhao_id');
            $table->renameColumn('c_parental_status_code', 'c_parental_status');
        });

        // 4. 還原舊的索引（僅 MySQL）
        if ($isMysql) {
            Schema::table('ENTRY_DATA', function ($table) {
                $table->index('c_nianhao_id', 'c_nianhao_id_ENTRY_DATA_index');
                $table->index('c_parental_status', 'c_parental_status');
            });

            // 5. 還原舊的外鍵約束
            Schema::table('ENTRY_DATA', function ($table) {
                $table->foreign('c_nianhao_id', 'ENTRY_DATA_ibfk_9')
                    ->references('c_nianhao_id')
                    ->on('NIAN_HAO')
                    ->onDelete('cascade')
                    ->onUpdate('cascade');

                $table->foreign('c_parental_status', 'ENTRY_DATA_ibfk_10')
                    ->references('c_parental_status_code')
                    ->on('PARENTAL_STATUS_CODES')
                    ->onDelete('cascade')
                    ->onUpdate('cascade');
            });
        }
    }
};
