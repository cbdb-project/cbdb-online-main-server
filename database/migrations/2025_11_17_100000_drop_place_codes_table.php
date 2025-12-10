<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DropPlaceCodesTable extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up() {
        Schema::dropIfExists('PLACE_CODES');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        Schema::create('PLACE_CODES', function (Blueprint $table) {
            $table->double('c_place_id')->nullable();
            $table->string('c_place_1990', 255)->nullable();
            $table->string('c_name', 255)->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->nullable();
            $table->string('c_name_chn', 255)->nullable();
            $table->double('x_coord')->nullable();
            $table->double('y_coord')->nullable();

            // Add index
            $table->index('c_place_id', 'c_place_id_PLACE_CODES_index');
        });
    }
}
