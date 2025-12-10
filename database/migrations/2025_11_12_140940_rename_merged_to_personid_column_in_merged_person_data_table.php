<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RenameMergedToPersonidColumnInMergedPersonDataTable extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up() {
        Schema::table('MERGED_PERSON_DATA', function (Blueprint $table) {
            $table->renameColumn('c_merged_to_personid', 'c_merged_from_personid');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        Schema::table('MERGED_PERSON_DATA', function (Blueprint $table) {
            $table->renameColumn('c_merged_from_personid', 'c_merged_to_personid');
        });
    }
}
