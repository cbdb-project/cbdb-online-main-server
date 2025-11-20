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
        // Alter ADMIN_CAT_CODES table collation
        DB::statement('ALTER TABLE ADMIN_CAT_CODES CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        
        // Alter ADMIN_CAT_TYPES table collation
        DB::statement('ALTER TABLE ADMIN_CAT_TYPES CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        
        // Alter ADMIN_CAT_CODE_TYPE_REL table collation
        DB::statement('ALTER TABLE ADMIN_CAT_CODE_TYPE_REL CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
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