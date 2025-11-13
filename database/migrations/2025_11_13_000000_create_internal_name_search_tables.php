<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateInternalNameSearchTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('CBDB__NAME_FTS', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('c_personid');
            $table->unsignedSmallInteger('name_type_code')->nullable();
            $table->string('name_type_desc', 32);
            $table->string('name_type_desc_chn', 32);
            $table->string('search_term', 100);
            $table->string('full_name', 100);
            $table->string('source', 32);
            $table->string('source_key', 255)->nullable();
            $table->boolean('is_simplified')->default(false);
            $table->timestamps();

            $table->index(['search_term', 'c_personid'], 'idx_cbdb__name_search_term');
            $table->index('c_personid', 'idx_cbdb__name_person');
            $table->index('name_type_code', 'idx_cbdb__name_type');
        });

        Schema::create('CBDB__TRAD_SIMP_MAP', function (Blueprint $table) {
            $table->char('trad_char', 1)->primary();
            $table->char('simp_char', 1);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('CBDB__TRAD_SIMP_MAP');
        Schema::dropIfExists('CBDB__NAME_FTS');
    }
}
