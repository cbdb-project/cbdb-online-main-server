<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AlterAdminCatTablesCollation extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Step 1: Drop foreign key constraints from ADMIN_CAT_CODE_TYPE_REL
        Schema::table('ADMIN_CAT_CODE_TYPE_REL', function (Blueprint $table) {
            $table->dropForeign('fk_admin_cat_code');
            $table->dropForeign('fk_admin_cat_type_code');
        });

        // Step 2: Alter collation for all three tables
        DB::statement('ALTER TABLE ADMIN_CAT_CODES CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        DB::statement('ALTER TABLE ADMIN_CAT_TYPES CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        DB::statement('ALTER TABLE ADMIN_CAT_CODE_TYPE_REL CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');

        // Step 3: Recreate foreign key constraints
        Schema::table('ADMIN_CAT_CODE_TYPE_REL', function (Blueprint $table) {
            $table->foreign('c_admin_cat_code', 'fk_admin_cat_code')
                ->references('c_admin_cat_code')
                ->on('ADMIN_CAT_CODES')
                ->onUpdate('cascade')
                ->onDelete('cascade');

            $table->foreign('c_admin_cat_type_code', 'fk_admin_cat_type_code')
                ->references('c_admin_cat_type_code')
                ->on('ADMIN_CAT_TYPES')
                ->onUpdate('cascade')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Note: Reverting collation changes is not typically necessary
        // as utf8mb4_general_ci is the standard for this project.
        // If needed, you would need to know the previous collation settings.
    }
}