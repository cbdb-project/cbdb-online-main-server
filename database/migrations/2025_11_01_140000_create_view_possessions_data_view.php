<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class CreateViewPossessionsDataView extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up() {
        DB::statement('DROP VIEW IF EXISTS View_PossessionsData');

        DB::statement(
            <<<'SQL'
CREATE VIEW View_PossessionsData AS
SELECT
    pd.c_personid,
    pd.c_possession_record_id,
    pd.c_sequence,
    pd.c_possession_act_code,
    act_codes.c_possession_act_desc,
    act_codes.c_possession_act_desc_chn,
    pd.c_possession_desc,
    pd.c_possession_desc_chn,
    pd.c_quantity,
    pd.c_measure_code,
    measure_codes.c_measure_desc,
    measure_codes.c_measure_desc_chn,
    pd.c_possession_yr,
    pd.c_possession_nh_code,
    nh.c_nianhao_chn,
    nh.c_nianhao_pin,
    pd.c_possession_nh_yr,
    pd.c_possession_yr_range,
    range_codes.c_range,
    range_codes.c_range_chn,
    pd.c_source,
    texts.c_title_chn,
    texts.c_title,
    pd.c_pages,
    pd.c_notes,
    addr.c_addr_id AS c_addr_id
FROM POSSESSION_DATA AS pd
LEFT JOIN POSSESSION_ACT_CODES AS act_codes
    ON act_codes.c_possession_act_code = pd.c_possession_act_code
LEFT JOIN MEASURE_CODES AS measure_codes
    ON measure_codes.c_measure_code = pd.c_measure_code
LEFT JOIN NIAN_HAO AS nh
    ON nh.c_nianhao_id = pd.c_possession_nh_code
LEFT JOIN YEAR_RANGE_CODES AS range_codes
    ON range_codes.c_range_code = pd.c_possession_yr_range
LEFT JOIN TEXT_CODES AS texts
    ON texts.c_textid = pd.c_source
LEFT JOIN POSSESSION_ADDR AS addr
    ON addr.c_possession_record_id = pd.c_possession_record_id
SQL
        );

        DB::statement('CREATE INDEX possession_data_personid_sequence_idx ON POSSESSION_DATA (c_personid, c_sequence)');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        DB::statement('DROP INDEX possession_data_personid_sequence_idx ON POSSESSION_DATA');
        DB::statement('DROP VIEW IF EXISTS View_PossessionsData');
    }
}
