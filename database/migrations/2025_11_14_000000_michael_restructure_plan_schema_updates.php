<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class MichaelRestructurePlanSchemaUpdates extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Add NOT NULL constraints to GANZHI_CODES
        if (Schema::hasTable('GANZHI_CODES')) {
            Schema::table('GANZHI_CODES', function (Blueprint $table) {
                $table->string('c_ganzhi_chn', 255)->nullable(false)->change();
                $table->string('c_ganzhi_py', 255)->nullable(false)->change();
            });
        }

        // Add NOT NULL constraints to HOUSEHOLD_STATUS_CODES
        if (Schema::hasTable('HOUSEHOLD_STATUS_CODES')) {
            Schema::table('HOUSEHOLD_STATUS_CODES', function (Blueprint $table) {
                $table->string('c_household_status_desc', 255)->nullable(false)->change();
                $table->string('c_household_status_desc_chn', 255)->nullable(false)->change();
            });
        }

        // Add primary key and NOT NULL constraints to INDEXYEAR_TYPE_CODES
        if (Schema::hasTable('INDEXYEAR_TYPE_CODES')) {
            Schema::table('INDEXYEAR_TYPE_CODES', function (Blueprint $table) {
                // First make the column NOT NULL and add primary key
                $table->string('c_index_year_type_code', 255)->nullable(false)->change();
                $table->string('c_index_year_type_desc', 255)->nullable(false)->change();
                $table->string('c_index_year_type_hz', 255)->nullable(false)->change();
            });
            
            // Add primary key in separate statement to avoid conflicts
            Schema::table('INDEXYEAR_TYPE_CODES', function (Blueprint $table) {
                $table->primary('c_index_year_type_code');
            });
        }

        // Add NOT NULL constraints to LITERARYGENRE_CODES
        if (Schema::hasTable('LITERARYGENRE_CODES')) {
            Schema::table('LITERARYGENRE_CODES', function (Blueprint $table) {
                $table->string('c_lit_genre_desc', 255)->nullable(false)->change();
                $table->string('c_lit_genre_desc_chn', 255)->nullable(false)->change();
                $table->integer('c_sortorder')->nullable(false)->change();
            });
        }

        // Add NOT NULL constraints to KIN_MOURNING_STEPS (c_kinrel is already primary key)
        if (Schema::hasTable('KIN_MOURNING_STEPS')) {
            Schema::table('KIN_MOURNING_STEPS', function (Blueprint $table) {
                $table->smallInteger('c_upstep')->nullable(false)->change();
                $table->smallInteger('c_dwnstep')->nullable(false)->change();
                $table->smallInteger('c_marstep')->nullable(false)->change();
                $table->smallInteger('c_colstep')->nullable(false)->change();
            });
        }

        // Add NOT NULL constraints to KINSHIP_CODES
        if (Schema::hasTable('KINSHIP_CODES')) {
            Schema::table('KINSHIP_CODES', function (Blueprint $table) {
                $table->smallInteger('c_kin_pair1')->nullable(false)->change();
                $table->smallInteger('c_kin_pair2')->nullable(false)->change();
                $table->string('c_kinrel_chn', 255)->nullable(false)->change();
                $table->string('c_kinrel', 255)->nullable(false)->change();
                $table->smallInteger('c_upstep')->nullable(false)->change();
                $table->smallInteger('c_dwnstep')->nullable(false)->change();
                $table->smallInteger('c_marstep')->nullable(false)->change();
                $table->smallInteger('c_colstep')->nullable(false)->change();
                $table->string('c_kinrel_simplified', 255)->nullable(false)->change();
            });
        }

        // Add NOT NULL constraints to DYNASTIES
        if (Schema::hasTable('DYNASTIES')) {
            Schema::table('DYNASTIES', function (Blueprint $table) {
                $table->smallInteger('c_start')->nullable(false)->change();
                $table->smallInteger('c_end')->nullable(false)->change();
            });
        }

        // Add NOT NULL constraints to ENTRY_CODES
        if (Schema::hasTable('ENTRY_CODES')) {
            Schema::table('ENTRY_CODES', function (Blueprint $table) {
                $table->string('c_entry_desc', 255)->nullable(false)->change();
                $table->string('c_entry_desc_chn', 255)->nullable(false)->change();
            });
        }

        // Add NOT NULL constraints to ENTRY_TYPES
        if (Schema::hasTable('ENTRY_TYPES')) {
            Schema::table('ENTRY_TYPES', function (Blueprint $table) {
                $table->string('c_entry_type_desc', 255)->nullable(false)->change();
                $table->string('c_entry_type_desc_chn', 255)->nullable(false)->change();
            });
        }

        // Remove fields from EXTANT_CODES
        if (Schema::hasTable('EXTANT_CODES')) {
            Schema::table('EXTANT_CODES', function (Blueprint $table) {
                $table->dropColumn('c_extant_code_hd');
            });
        }

        // Remove fields from BIOG_INST_DATA
        if (Schema::hasTable('BIOG_INST_DATA')) {
            Schema::table('BIOG_INST_DATA', function (Blueprint $table) {
                $table->dropColumn('tts_sysno');
            });
        }

        // Add timestamp and user tracking fields to POSTING_DATA
        if (Schema::hasTable('POSTING_DATA')) {
            Schema::table('POSTING_DATA', function (Blueprint $table) {
                $table->string('c_created_by', 255)->nullable();
                $table->datetime('c_created_date')->nullable();
                $table->string('c_modified_by', 255)->nullable();
                $table->datetime('c_modified_date')->nullable();
            });
        }

        // Add timestamp and user tracking fields to POSTED_TO_ADDR_DATA
        if (Schema::hasTable('POSTED_TO_ADDR_DATA')) {
            Schema::table('POSTED_TO_ADDR_DATA', function (Blueprint $table) {
                $table->string('c_created_by', 255)->nullable();
                $table->datetime('c_created_date')->nullable();
                $table->string('c_modified_by', 255)->nullable();
                $table->datetime('c_modified_date')->nullable();
            });
        }

        // Add timestamp and user tracking fields to ADDR_BELONGS_DATA
        if (Schema::hasTable('ADDR_BELONGS_DATA')) {
            Schema::table('ADDR_BELONGS_DATA', function (Blueprint $table) {
                $table->string('c_created_by', 255)->nullable();
                $table->datetime('c_created_date')->nullable();
                $table->string('c_modified_by', 255)->nullable();
                $table->datetime('c_modified_date')->nullable();
            });
        }

        // Add timestamp and user tracking fields to BIOG_SOURCE_DATA
        if (Schema::hasTable('BIOG_SOURCE_DATA')) {
            Schema::table('BIOG_SOURCE_DATA', function (Blueprint $table) {
                $table->string('c_created_by', 255)->nullable();
                $table->datetime('c_created_date')->nullable();
                $table->string('c_modified_by', 255)->nullable();
                $table->datetime('c_modified_date')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Reverse NOT NULL constraints for GANZHI_CODES
        if (Schema::hasTable('GANZHI_CODES')) {
            Schema::table('GANZHI_CODES', function (Blueprint $table) {
                $table->string('c_ganzhi_chn', 255)->nullable()->change();
                $table->string('c_ganzhi_py', 255)->nullable()->change();
            });
        }

        // Reverse NOT NULL constraints for HOUSEHOLD_STATUS_CODES
        if (Schema::hasTable('HOUSEHOLD_STATUS_CODES')) {
            Schema::table('HOUSEHOLD_STATUS_CODES', function (Blueprint $table) {
                $table->string('c_household_status_desc', 255)->nullable()->change();
                $table->string('c_household_status_desc_chn', 255)->nullable()->change();
            });
        }

        // Reverse primary key and NOT NULL constraints for INDEXYEAR_TYPE_CODES
        if (Schema::hasTable('INDEXYEAR_TYPE_CODES')) {
            Schema::table('INDEXYEAR_TYPE_CODES', function (Blueprint $table) {
                $table->dropPrimary(['c_index_year_type_code']);
            });
            
            Schema::table('INDEXYEAR_TYPE_CODES', function (Blueprint $table) {
                $table->string('c_index_year_type_code', 255)->nullable()->change();
                $table->string('c_index_year_type_desc', 255)->nullable()->change();
                $table->string('c_index_year_type_hz', 255)->nullable()->change();
            });
        }

        // Reverse NOT NULL constraints for LITERARYGENRE_CODES
        if (Schema::hasTable('LITERARYGENRE_CODES')) {
            Schema::table('LITERARYGENRE_CODES', function (Blueprint $table) {
                $table->string('c_lit_genre_desc', 255)->nullable()->change();
                $table->string('c_lit_genre_desc_chn', 255)->nullable()->change();
                $table->integer('c_sortorder')->nullable()->change();
            });
        }

        // Reverse NOT NULL constraints for KIN_MOURNING_STEPS
        if (Schema::hasTable('KIN_MOURNING_STEPS')) {
            Schema::table('KIN_MOURNING_STEPS', function (Blueprint $table) {
                $table->smallInteger('c_upstep')->nullable()->change();
                $table->smallInteger('c_dwnstep')->nullable()->change();
                $table->smallInteger('c_marstep')->nullable()->change();
                $table->smallInteger('c_colstep')->nullable()->change();
            });
        }

        // Reverse NOT NULL constraints for KINSHIP_CODES
        if (Schema::hasTable('KINSHIP_CODES')) {
            Schema::table('KINSHIP_CODES', function (Blueprint $table) {
                $table->smallInteger('c_kin_pair1')->nullable()->change();
                $table->smallInteger('c_kin_pair2')->nullable()->change();
                $table->string('c_kinrel_chn', 255)->nullable()->change();
                $table->string('c_kinrel', 255)->nullable()->change();
                $table->smallInteger('c_upstep')->nullable()->change();
                $table->smallInteger('c_dwnstep')->nullable()->change();
                $table->smallInteger('c_marstep')->nullable()->change();
                $table->smallInteger('c_colstep')->nullable()->change();
                $table->string('c_kinrel_simplified', 255)->nullable()->change();
            });
        }

        // Reverse NOT NULL constraints for DYNASTIES
        if (Schema::hasTable('DYNASTIES')) {
            Schema::table('DYNASTIES', function (Blueprint $table) {
                $table->smallInteger('c_start')->nullable()->change();
                $table->smallInteger('c_end')->nullable()->change();
            });
        }

        // Reverse NOT NULL constraints for ENTRY_CODES
        if (Schema::hasTable('ENTRY_CODES')) {
            Schema::table('ENTRY_CODES', function (Blueprint $table) {
                $table->string('c_entry_desc', 255)->nullable()->change();
                $table->string('c_entry_desc_chn', 255)->nullable()->change();
            });
        }

        // Reverse NOT NULL constraints for ENTRY_TYPES
        if (Schema::hasTable('ENTRY_TYPES')) {
            Schema::table('ENTRY_TYPES', function (Blueprint $table) {
                $table->string('c_entry_type_desc', 255)->nullable()->change();
                $table->string('c_entry_type_desc_chn', 255)->nullable()->change();
            });
        }

        // Re-add removed fields to EXTANT_CODES
        if (Schema::hasTable('EXTANT_CODES')) {
            Schema::table('EXTANT_CODES', function (Blueprint $table) {
                $table->string('c_extant_code_hd', 255)->nullable();
            });
        }

        // Re-add removed fields to BIOG_INST_DATA
        if (Schema::hasTable('BIOG_INST_DATA')) {
            Schema::table('BIOG_INST_DATA', function (Blueprint $table) {
                $table->integer('tts_sysno')->nullable();
            });
        }

        // Remove timestamp and user tracking fields from POSTING_DATA
        if (Schema::hasTable('POSTING_DATA')) {
            Schema::table('POSTING_DATA', function (Blueprint $table) {
                $table->dropColumn(['c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date']);
            });
        }

        // Remove timestamp and user tracking fields from POSTED_TO_ADDR_DATA
        if (Schema::hasTable('POSTED_TO_ADDR_DATA')) {
            Schema::table('POSTED_TO_ADDR_DATA', function (Blueprint $table) {
                $table->dropColumn(['c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date']);
            });
        }

        // Remove timestamp and user tracking fields from ADDR_BELONGS_DATA
        if (Schema::hasTable('ADDR_BELONGS_DATA')) {
            Schema::table('ADDR_BELONGS_DATA', function (Blueprint $table) {
                $table->dropColumn(['c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date']);
            });
        }

        // Remove timestamp and user tracking fields from BIOG_SOURCE_DATA
        if (Schema::hasTable('BIOG_SOURCE_DATA')) {
            Schema::table('BIOG_SOURCE_DATA', function (Blueprint $table) {
                $table->dropColumn(['c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date']);
            });
        }
    }
}