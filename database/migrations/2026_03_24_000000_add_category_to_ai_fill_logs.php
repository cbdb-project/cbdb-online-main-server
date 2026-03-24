<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void {
        Schema::table('ai_fill_logs', function (Blueprint $table) {
            $table->string('category', 20)->default('posting')->after('c_personid');
            $table->index('category');
        });
    }

    public function down(): void {
        Schema::table('ai_fill_logs', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};
