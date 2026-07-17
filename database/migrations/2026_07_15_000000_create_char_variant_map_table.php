<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void {
        Schema::create('char_variant_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            column_comment($table->string('c_variant_char', 10), '原字（異體字）');
            column_comment($table->string('c_reference_char', 10), '參考字（落地替換時歸一到的目標字）');
            column_comment($table->tinyInteger('c_strict_excluded')->default(1), '是否排除於嚴格模式（BIOG_MAIN／ALTNAME_DATA 人名）；1=僅寬鬆模式可替換，0=兩種模式皆可替換');
            column_comment($table->string('c_notes', 255)->nullable(), '備註，例如排除原因');
            $table->timestamps();

            $table->unique('c_variant_char', 'char_variant_map_c_variant_char_unique');
        });

        // 種子資料（7 筆），c_notes 內容與 docs/CHAR_VARIANT_MAP_CONSOLIDATION_PLAN.md「現有資料遷移」表格一致。
        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '愼', 'c_reference_char' => '慎', 'c_strict_excluded' => 0, 'c_notes' => '原 VariantCharNormalizer::$fallbackMap；愼/慎無歧義風險，可安全落地替換於任何場合，含人名'],
            ['c_variant_char' => '槀', 'c_reference_char' => '稿', 'c_strict_excluded' => 0, 'c_notes' => '原 VariantCharNormalizer::$fallbackMap；槀/稿無歧義風險，可安全落地替換於任何場合，含人名'],
            ['c_variant_char' => '峯', 'c_reference_char' => '峰', 'c_strict_excluded' => 1, 'c_notes' => '原 TITLE_VARIANT_MAP；書名等場合的落地替換可用，但 BIOG_MAIN（人物本名）與 ALTNAME_DATA（人物別名）場合的落地替換須排除，峯本身是合法人名用字，不應被強制改寫'],
            ['c_variant_char' => '靑', 'c_reference_char' => '青', 'c_strict_excluded' => 0, 'c_notes' => '原 TITLE_VARIANT_MAP；靑/青無歧義風險，可安全落地替換於任何場合，含人名'],
            ['c_variant_char' => '頴', 'c_reference_char' => '穎', 'c_strict_excluded' => 0, 'c_notes' => '原 TITLE_VARIANT_MAP；頴/穎無歧義風險，可安全落地替換於任何場合，含人名'],
            ['c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0, 'c_notes' => '新增；淸/清無歧義風險，可安全落地替換於任何場合，含人名'],
            ['c_variant_char' => '厰', 'c_reference_char' => '廠', 'c_strict_excluded' => 0, 'c_notes' => '新增；厰/廠無歧義風險，可安全落地替換於任何場合，含人名'],
        ]);
    }

    public function down(): void {
        Schema::dropIfExists('char_variant_map');
    }
};
