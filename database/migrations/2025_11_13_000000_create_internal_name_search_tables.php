<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateInternalNameSearchTables extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up() {
        Schema::create('CBDB__NAME_FTS', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
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

        // 使用 VARBINARY(4) 以繞過 MySQL 8.0 對 utf8mb4 非BMP字符主鍵索引的 bug
        // utf8mb4 字符類型 (CHAR/VARCHAR) 會將不同的4字節字符誤判為重複
        // VARBINARY 按字節存儲，使用二進制比較，可正確區分所有 UTF-8 字符
        DB::statement("
            CREATE TABLE CBDB__TRAD_SIMP_MAP (
                trad_char VARBINARY(4) NOT NULL COMMENT '繁體字（UTF-8二進制）',
                simp_char VARBINARY(4) NOT NULL COMMENT '簡體字（UTF-8二進制）',
                PRIMARY KEY (trad_char)
            ) ENGINE=InnoDB
        ");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        Schema::dropIfExists('CBDB__TRAD_SIMP_MAP');
        Schema::dropIfExists('CBDB__NAME_FTS');
    }
}
