<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 將 STATUS_CODES.c_status_desc 與 c_status_desc_chn 設為 NOT NULL。
 *
 * 背景：
 * - STATUS_CODES 主鍵為 c_status_code，這兩個描述欄位並非 PK 組成，
 *   故不需要先 drop PK、alter、再 add PK。
 * - 對齊 HOUSEHOLD_STATUS_CODES 等兄弟碼表慣例，描述欄位 NOT NULL 預設 ''。
 */
class SetStatusCodesDescNotNull extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up() {
        disable_foreign_keys();

        if (Schema::hasTable('STATUS_CODES')) {
            DB::statement("UPDATE STATUS_CODES SET c_status_desc = '' WHERE c_status_desc IS NULL");
            DB::statement("UPDATE STATUS_CODES SET c_status_desc_chn = '' WHERE c_status_desc_chn IS NULL");

            Schema::table('STATUS_CODES', function (Blueprint $table) {
                $table->string('c_status_desc', 255)->nullable(false)->default('')->change();
                $table->string('c_status_desc_chn', 255)->nullable(false)->default('')->change();
            });
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

        if (Schema::hasTable('STATUS_CODES')) {
            Schema::table('STATUS_CODES', function (Blueprint $table) {
                $table->string('c_status_desc', 255)->nullable()->default(null)->change();
                $table->string('c_status_desc_chn', 255)->nullable()->default(null)->change();
            });
        }

        enable_foreign_keys();
    }
}
