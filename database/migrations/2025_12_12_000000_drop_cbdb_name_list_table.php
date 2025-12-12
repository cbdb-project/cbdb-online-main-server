<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up() {
        Schema::dropIfExists('CBDB_NAME_LIST');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        Schema::create('CBDB_NAME_LIST', function (Blueprint $table) {
            $table->integer('c_personid')->nullable();
            $table->string('name', 255)->nullable();
            $table->string('source', 255)->nullable();

            // Add indexes
            $table->index('c_personid', 'idx_c_personid');
            $table->index('name', 'idx_name');

            // Explicitly set table properties to match original schema
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_general_ci';
            $table->engine = 'InnoDB';
        });
    }
};
