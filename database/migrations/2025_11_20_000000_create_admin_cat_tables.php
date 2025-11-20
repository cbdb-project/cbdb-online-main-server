<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAdminCatTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Create ADMIN_CAT_CODES table
        Schema::create('ADMIN_CAT_CODES', function (Blueprint $table) {
            $table->integer('c_admin_cat_code')->primary();
            $table->string('c_admin_cat_py', 255)->nullable();
            $table->string('c_admin_cat_hz', 255)->nullable();
            $table->string('c_admin_cat_trans', 255)->nullable();
            $table->longText('c_notes')->nullable();
        });

        // Create ADMIN_CAT_TYPES table
        Schema::create('ADMIN_CAT_TYPES', function (Blueprint $table) {
            $table->string('c_admin_cat_type_code', 255)->primary();
            $table->string('c_admin_cat_type_hz', 255)->nullable();
            $table->string('c_admin_cat_type_trans', 255)->nullable();
            $table->longText('c_notes')->nullable();
        });

        // Create ADMIN_CAT_CODE_TYPE_REL table with composite primary key and foreign keys
        Schema::create('ADMIN_CAT_CODE_TYPE_REL', function (Blueprint $table) {
            $table->integer('c_admin_cat_code');
            $table->string('c_admin_cat_type_code', 255);

            // Composite primary key
            $table->primary(['c_admin_cat_code', 'c_admin_cat_type_code'], 'admin_cat_code_type_rel_pk');

            // Foreign key constraints with CASCADE
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
        // Drop tables in reverse order to handle foreign key constraints
        Schema::dropIfExists('ADMIN_CAT_CODE_TYPE_REL');
        Schema::dropIfExists('ADMIN_CAT_TYPES');
        Schema::dropIfExists('ADMIN_CAT_CODES');
    }
}