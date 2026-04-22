<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 將 POSTED_TO_OFFICE_DATA.c_appt_code 設為 NOT NULL，並把欄位移到 c_ly_range 之後。
 *
 * 背景：
 * - 2026-04-16 已將 c_appt_code 從 varchar(255) 收斂為 smallint 並建立外鍵，
 *   但歷史資料仍允許 NULL，導致介面與查詢需額外處理空值。
 * - 對齊 c_appt_type_code 原欄位位置（介於 c_ly_range 與 c_assume_office_code 之間），
 *   讓年份與任職相關欄位保持緊鄰，提升 schema 可讀性。
 * - 空值回填 0，沿用 OFFICE_CODES.c_dy 等兄弟欄位的慣例。
 */
class SetPostedToOfficeDataCApptCodeNotNull extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up() {
        disable_foreign_keys();

        // 1. 確保 APPOINTMENT_CODES 存在 0 號「未詳」哨兵，
        //    否則後續 UPDATE 會造成 FK 孤兒、ApiController2 讀取時也會 NPE。
        //    使用 insertOrIgnore 保持冪等，對已存在的 0 號不覆寫。
        if (Schema::hasTable('APPOINTMENT_CODES')) {
            DB::table('APPOINTMENT_CODES')->insertOrIgnore([
                'c_appt_code' => 0,
                'c_appt_desc_chn' => '未詳',
                'c_appt_desc' => 'Unknown',
            ]);
        }

        if (Schema::hasTable('POSTED_TO_OFFICE_DATA')) {
            // 2. 填充空值：c_appt_code 在 2026-04-16 已轉為 smallint，
            //    理論上不會再有空字串，但保留 '' 條件以防 SQLite 型別親和殘留。
            DB::statement("UPDATE POSTED_TO_OFFICE_DATA SET c_appt_code = 0 WHERE c_appt_code IS NULL OR c_appt_code = ''");

            // 3. MySQL：MODIFY COLUMN ... AFTER 同時完成 NOT NULL 與欄位重排。
            //    SQLite：僅改 NOT NULL，欄位順序重排需重建整張表，對應用無實質影響。
            if (is_mysql()) {
                // MODIFY COLUMN ... AFTER 保留 c_appt_code_POSTED_TO_OFFICE_DATA_index 索引
                // 與 POSTED_TO_OFFICE_DATA_ibfk_1 外鍵約束。
                DB::statement('ALTER TABLE POSTED_TO_OFFICE_DATA MODIFY c_appt_code smallint NOT NULL DEFAULT 0 AFTER c_ly_range');
            } else {
                Schema::table('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
                    $table->smallInteger('c_appt_code')->nullable(false)->default(0)->change();
                });
            }
        }

        enable_foreign_keys();
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        disable_foreign_keys();

        if (Schema::hasTable('POSTED_TO_OFFICE_DATA')) {
            if (is_mysql()) {
                // 還原為 nullable；欄位保留在 c_ly_range 之後，不再搬回表尾，
                // 因為舊位置僅是歷史遺留，沒有反向還原的價值。
                DB::statement('ALTER TABLE POSTED_TO_OFFICE_DATA MODIFY c_appt_code smallint DEFAULT NULL');
            } else {
                Schema::table('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
                    $table->smallInteger('c_appt_code')->nullable()->default(null)->change();
                });
            }
        }

        enable_foreign_keys();
    }
}
