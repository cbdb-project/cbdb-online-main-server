<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class CreateViewBiogInstDataView extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up() {
        DB::statement('DROP VIEW IF EXISTS View_BiogInstData');

        DB::statement(
            <<<'SQL'
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
SQL
        );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        DB::statement('DROP VIEW IF EXISTS View_BiogInstData');
    }
}
