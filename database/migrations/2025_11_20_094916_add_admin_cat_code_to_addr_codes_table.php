<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddAdminCatCodeToAddrCodesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ADDR_CODES', function (Blueprint $table) {
            // Add the c_admin_cat_code column
            $table->integer('c_admin_cat_code')
                ->default(0)
                ->after('c_admin_type'); // Adjust the position as needed
            
            // Add foreign key constraint
            $table->foreign('c_admin_cat_code', 'fk_addr_codes_admin_cat_code')
                ->references('c_admin_cat_code')
                ->on('ADMIN_CAT_CODES')
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
        Schema::table('ADDR_CODES', function (Blueprint $table) {
            // Drop foreign key constraint first
            $table->dropForeign('fk_addr_codes_admin_cat_code');
            
            // Drop the column
            $table->dropColumn('c_admin_cat_code');
        });
    }
}