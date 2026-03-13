<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/helpers.php';

return new class () extends Migration {
    public function up(): void {
        Schema::table('BIOG_MAIN', function (Blueprint $table) {
            $table->dropColumn('c_self_bio');
        });
    }

    public function down(): void {
        Schema::table('BIOG_MAIN', function (Blueprint $table) {
            $table->smallInteger('c_self_bio')->nullable()->default(null);
        });
    }
};
