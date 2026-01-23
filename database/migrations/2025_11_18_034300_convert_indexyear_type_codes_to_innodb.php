<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 將 INDEXYEAR_TYPE_CODES 表轉換為 InnoDB 引擎
 *
 * 背景：為了支援交易處理和外鍵約束，需要將此表從 MyISAM 轉換為 InnoDB。
 * InnoDB 提供更好的資料完整性保證和並發控制。
 */
class ConvertIndexyearTypeCodesToInnodb extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up() {
        // 將 INDEXYEAR_TYPE_CODES 表轉換為 InnoDB 引擎
        if (is_mysql()) {
            DB::statement('ALTER TABLE INDEXYEAR_TYPE_CODES ENGINE=InnoDB');
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        // 還原為 MyISAM 引擎
        if (is_mysql()) {
            DB::statement('ALTER TABLE INDEXYEAR_TYPE_CODES ENGINE=MyISAM');
        }
    }
}
