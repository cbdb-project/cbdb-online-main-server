<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIndexesToAltnameDataTable extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up() {
        if (Schema::hasTable('ALTNAME_DATA')) {
            Schema::table('ALTNAME_DATA', function (Blueprint $table) {
                // 加速與 ALTNAME_CODES、TEXT_CODES 的多表關聯。
                $table->index(
                    ['c_alt_name_type_code', 'c_source'],
                    'idx_altname_code_source'
                );

                // 支援依 c_personid、c_sequence 排序的分頁查詢。
                $table->index(
                    ['c_personid', 'c_sequence'],
                    'idx_altname_person_seq'
                );
            });
        }

        if (Schema::hasTable('ASSOC_DATA')) {
            Schema::table('ASSOC_DATA', function (Blueprint $table) {
                // 支援 ASSOC_DATA 依人物排序的分頁查詢。
                $table->index(
                    ['c_personid', 'c_sequence'],
                    'idx_assoc_person_seq'
                );
            });
        }

        if (Schema::hasTable('BIOG_ADDR_DATA')) {
            Schema::table('BIOG_ADDR_DATA', function (Blueprint $table) {
                // 支援 BIOG_ADDR_DATA 依人物排序的分頁查詢。
                $table->index(
                    ['c_personid', 'c_sequence'],
                    'idx_biog_addr_person_seq'
                );
            });
        }

        if (Schema::hasTable('BIOG_INST_DATA')) {
            Schema::table('BIOG_INST_DATA', function (Blueprint $table) {
                // 支援 BIOG_INST_DATA 依人物與機構查詢。
                $table->index(
                    ['c_personid', 'c_inst_name_code', 'c_inst_code'],
                    'idx_biog_inst_person_instcode'
                );
            });
        }

        if (Schema::hasTable('BIOG_SOURCE_DATA')) {
            Schema::table('BIOG_SOURCE_DATA', function (Blueprint $table) {
                // 支援 BIOG_SOURCE_DATA 依人物與文獻的查詢。
                $table->index(
                    ['c_personid', 'c_textid'],
                    'idx_biog_source_person_text'
                );
            });
        }

        if (Schema::hasTable('BIOG_TEXT_DATA')) {
            Schema::table('BIOG_TEXT_DATA', function (Blueprint $table) {
                // 支援 BIOG_TEXT_DATA 依人物與文本的查詢。
                $table->index(
                    ['c_personid', 'c_textid'],
                    'idx_biog_text_person_text'
                );
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        if (Schema::hasTable('ALTNAME_DATA')) {
            Schema::table('ALTNAME_DATA', function (Blueprint $table) {
                $table->dropIndex('idx_altname_code_source');
                $table->dropIndex('idx_altname_person_seq');
            });
        }

        if (Schema::hasTable('ASSOC_DATA')) {
            Schema::table('ASSOC_DATA', function (Blueprint $table) {
                $table->dropIndex('idx_assoc_person_seq');
            });
        }

        if (Schema::hasTable('BIOG_ADDR_DATA')) {
            Schema::table('BIOG_ADDR_DATA', function (Blueprint $table) {
                $table->dropIndex('idx_biog_addr_person_seq');
            });
        }

        if (Schema::hasTable('BIOG_INST_DATA')) {
            Schema::table('BIOG_INST_DATA', function (Blueprint $table) {
                $table->dropIndex('idx_biog_inst_person_instcode');
            });
        }

        if (Schema::hasTable('BIOG_SOURCE_DATA')) {
            Schema::table('BIOG_SOURCE_DATA', function (Blueprint $table) {
                $table->dropIndex('idx_biog_source_person_text');
            });
        }

        if (Schema::hasTable('BIOG_TEXT_DATA')) {
            Schema::table('BIOG_TEXT_DATA', function (Blueprint $table) {
                $table->dropIndex('idx_biog_text_person_text');
            });
        }
    }
}
