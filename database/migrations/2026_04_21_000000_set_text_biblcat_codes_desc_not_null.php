<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 將 TEXT_BIBLCAT_CODES 的 c_text_cat_desc、c_text_cat_desc_chn、c_text_cat_pinyin 設為 NOT NULL。
 *
 * 背景：
 * - TEXT_BIBLCAT_CODES 主鍵為 c_text_cat_code，這三個描述欄位並非 PK 組成，
 *   故不需要先 drop PK、alter、再 add PK。
 * - 對齊 STATUS_CODES 等兄弟碼表慣例，描述欄位 NOT NULL 預設 ''。
 */
class SetTextBiblcatCodesDescNotNull extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up() {
        disable_foreign_keys();

        if (Schema::hasTable('TEXT_BIBLCAT_CODES')) {
            DB::statement("UPDATE TEXT_BIBLCAT_CODES SET c_text_cat_desc = '' WHERE c_text_cat_desc IS NULL");
            DB::statement("UPDATE TEXT_BIBLCAT_CODES SET c_text_cat_desc_chn = '' WHERE c_text_cat_desc_chn IS NULL");
            DB::statement("UPDATE TEXT_BIBLCAT_CODES SET c_text_cat_pinyin = '' WHERE c_text_cat_pinyin IS NULL");

            Schema::table('TEXT_BIBLCAT_CODES', function (Blueprint $table) {
                $table->string('c_text_cat_desc', 255)->nullable(false)->default('')->change();
                $table->string('c_text_cat_desc_chn', 255)->nullable(false)->default('')->change();
                $table->string('c_text_cat_pinyin', 255)->nullable(false)->default('')->change();
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

        if (Schema::hasTable('TEXT_BIBLCAT_CODES')) {
            Schema::table('TEXT_BIBLCAT_CODES', function (Blueprint $table) {
                $table->string('c_text_cat_desc', 255)->nullable()->default(null)->change();
                $table->string('c_text_cat_desc_chn', 255)->nullable()->default(null)->change();
                $table->string('c_text_cat_pinyin', 255)->nullable()->default(null)->change();
            });
        }

        enable_foreign_keys();
    }
}
