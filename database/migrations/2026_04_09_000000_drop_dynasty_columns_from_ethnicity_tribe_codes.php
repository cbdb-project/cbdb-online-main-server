<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * 要移除的朝代史書欄位。
     */
    private const COLUMNS = [
        'JiuTangShu',
        'XinTangShu',
        'JiuWudaiShi',
        'XinWudaiShi',
        'SongShi',
        'LiaoShi',
        'JinShi',
        'YuanShi',
        'MingShi',
        'QingShiGao',
    ];

    public function up(): void
    {
        Schema::table('ETHNICITY_TRIBE_CODES', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                if (Schema::hasColumn('ETHNICITY_TRIBE_CODES', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('ETHNICITY_TRIBE_CODES', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->string($column, 255)->nullable()->default(null);
            }
        });
    }
};
