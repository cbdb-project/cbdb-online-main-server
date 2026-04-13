<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SetOfficeCodesCDyNotNull extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up() {
        disable_foreign_keys();

        if (Schema::hasTable('OFFICE_CODES')) {
            DB::statement("UPDATE OFFICE_CODES SET c_dy = 0 WHERE c_dy IS NULL");

            Schema::table('OFFICE_CODES', function (Blueprint $table) {
                $table->smallInteger('c_dy')->nullable(false)->default(0)->change();
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

        if (Schema::hasTable('OFFICE_CODES')) {
            Schema::table('OFFICE_CODES', function (Blueprint $table) {
                $table->smallInteger('c_dy')->nullable()->change();
            });
        }

        enable_foreign_keys();
    }
}
