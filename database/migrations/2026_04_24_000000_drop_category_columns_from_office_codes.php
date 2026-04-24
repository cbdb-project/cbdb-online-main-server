<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * 要移除的類別欄位。
     */
    private const COLUMNS = [
        'c_category_1',
        'c_category_2',
        'c_category_3',
        'c_category_4',
    ];

    public function up(): void {
        Schema::table('OFFICE_CODES', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                if (Schema::hasColumn('OFFICE_CODES', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void {
        Schema::table('OFFICE_CODES', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->string($column, 255)->nullable()->default(null);
            }
        });
    }
};
