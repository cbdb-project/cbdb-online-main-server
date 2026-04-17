<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/helpers.php';

/**
 * 1. 移除 SOCIAL_INSTITUTION_ALTNAME_DATA.c_secondary_source_author 欄位。
 * 2. 將 SOCIAL_INSTITUTION_NAME_CODES 的 c_inst_name_hz 與 c_inst_name_py
 *    由 nullable 改為 NOT NULL（既有 NULL 先補為空字串）。
 *
 * 備註：SQLite 執行 column->change() 時會重建整張資料表，會觸發引用
 * SOCIAL_INSTITUTION_NAME_CODES 的 View_BiogInstData 校驗失敗，因此需先 DROP、
 * 再重建該 view。
 */
return new class () extends Migration {
    private const VIEW_BIOG_INST_DATA_SQL = <<<'SQL'
CREATE VIEW View_BiogInstData AS
SELECT
    bi.c_personid,
    person.c_name,
    person.c_name_chn,
    bi.c_inst_name_code,
    bi.c_inst_code,
    inst_names.c_inst_name_hz,
    inst_names.c_inst_name_py,
    bi.c_bi_role_code,
    inst_codes.c_bi_role_desc,
    inst_codes.c_bi_role_chn,
    bi.c_bi_begin_year,
    bi.c_bi_by_nh_code,
    by_nh.c_nianhao_chn AS c_bi_by_nh_chn,
    by_nh.c_nianhao_pin AS c_bi_by_nh_py,
    bi.c_bi_by_nh_year,
    bi.c_bi_by_range,
    by_range.c_range AS c_bi_by_range_desc,
    by_range.c_range_chn AS c_bi_by_range_chn,
    bi.c_bi_end_year,
    bi.c_bi_ey_nh_code,
    ey_nh.c_nianhao_chn AS c_bi_ey_nh_chn,
    ey_nh.c_nianhao_pin AS c_bi_ey_nh_py,
    bi.c_bi_ey_nh_year,
    bi.c_bi_ey_range,
    ey_range.c_range AS c_bi_ey_range_desc,
    ey_range.c_range_chn AS c_bi_ey_range_chn,
    bi.c_source,
    text_codes.c_title_chn AS c_source_chn,
    text_codes.c_title AS c_source_py,
    bi.c_pages,
    bi.c_notes,
    inst_addr.c_inst_addr_id,
    inst_addr.c_inst_addr_type_code,
    inst_addr.inst_xcoord,
    inst_addr.inst_ycoord
FROM BIOG_INST_DATA AS bi
INNER JOIN BIOG_MAIN AS person
    ON person.c_personid = bi.c_personid
INNER JOIN SOCIAL_INSTITUTION_NAME_CODES AS inst_names
    ON inst_names.c_inst_name_code = bi.c_inst_name_code
INNER JOIN BIOG_INST_CODES AS inst_codes
    ON inst_codes.c_bi_role_code = bi.c_bi_role_code
LEFT JOIN NIAN_HAO AS by_nh
    ON by_nh.c_nianhao_id = bi.c_bi_by_nh_code
LEFT JOIN YEAR_RANGE_CODES AS by_range
    ON by_range.c_range_code = bi.c_bi_by_range
LEFT JOIN NIAN_HAO AS ey_nh
    ON ey_nh.c_nianhao_id = bi.c_bi_ey_nh_code
LEFT JOIN YEAR_RANGE_CODES AS ey_range
    ON ey_range.c_range_code = bi.c_bi_ey_range
LEFT JOIN TEXT_CODES AS text_codes
    ON text_codes.c_textid = bi.c_source
LEFT JOIN SOCIAL_INSTITUTION_ADDR AS inst_addr
    ON inst_addr.c_inst_name_code = bi.c_inst_name_code
   AND inst_addr.c_inst_code = bi.c_inst_code
SQL;

    public function up(): void {
        disable_foreign_keys();

        if (Schema::hasTable('SOCIAL_INSTITUTION_ALTNAME_DATA')) {
            Schema::table('SOCIAL_INSTITUTION_ALTNAME_DATA', function (Blueprint $table) {
                $table->dropColumn('c_secondary_source_author');
            });
        }

        if (Schema::hasTable('SOCIAL_INSTITUTION_NAME_CODES')) {
            DB::statement("UPDATE SOCIAL_INSTITUTION_NAME_CODES SET c_inst_name_hz = '' WHERE c_inst_name_hz IS NULL");
            DB::statement("UPDATE SOCIAL_INSTITUTION_NAME_CODES SET c_inst_name_py = '' WHERE c_inst_name_py IS NULL");

            if (is_sqlite()) {
                DB::statement('DROP VIEW IF EXISTS View_BiogInstData');
            }

            Schema::table('SOCIAL_INSTITUTION_NAME_CODES', function (Blueprint $table) {
                $table->string('c_inst_name_hz', 255)->nullable(false)->default('')->change();
                $table->string('c_inst_name_py', 255)->nullable(false)->default('')->change();
            });

            if (is_sqlite()) {
                DB::statement(self::VIEW_BIOG_INST_DATA_SQL);
            }
        }

        enable_foreign_keys();
    }

    public function down(): void {
        disable_foreign_keys();

        if (Schema::hasTable('SOCIAL_INSTITUTION_NAME_CODES')) {
            if (is_sqlite()) {
                DB::statement('DROP VIEW IF EXISTS View_BiogInstData');
            }

            Schema::table('SOCIAL_INSTITUTION_NAME_CODES', function (Blueprint $table) {
                $table->string('c_inst_name_hz', 255)->nullable()->default(null)->change();
                $table->string('c_inst_name_py', 255)->nullable()->default(null)->change();
            });

            if (is_sqlite()) {
                DB::statement(self::VIEW_BIOG_INST_DATA_SQL);
            }
        }

        if (Schema::hasTable('SOCIAL_INSTITUTION_ALTNAME_DATA')) {
            Schema::table('SOCIAL_INSTITUTION_ALTNAME_DATA', function (Blueprint $table) {
                $table->string('c_secondary_source_author', 255)->nullable()->default(null);
            });
        }

        enable_foreign_keys();
    }
};
