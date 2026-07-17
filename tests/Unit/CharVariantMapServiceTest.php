<?php

namespace Tests\Unit;

use App\Services\CharVariantMapService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CharVariantMapService 測試
 *
 * 手動建表（比照 AuditLogServiceTest 慣例，避免 RefreshDatabase 在 SQLite 上
 * 因外鍵不符而失敗），種入與 2026_07_15_000000_create_char_variant_map_table.php
 * 相同的 7 筆種子資料。
 */
class CharVariantMapServiceTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        CharVariantMapService::reset();

        Schema::dropIfExists('char_variant_map');
        Schema::create('char_variant_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('c_variant_char', 10);
            $table->string('c_reference_char', 10);
            $table->tinyInteger('c_strict_excluded')->default(1);
            $table->string('c_notes', 255)->nullable();
            $table->timestamps();

            $table->unique('c_variant_char', 'char_variant_map_c_variant_char_unique');
        });

        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '愼', 'c_reference_char' => '慎', 'c_strict_excluded' => 0],
            ['c_variant_char' => '槀', 'c_reference_char' => '稿', 'c_strict_excluded' => 0],
            ['c_variant_char' => '峯', 'c_reference_char' => '峰', 'c_strict_excluded' => 1],
            ['c_variant_char' => '靑', 'c_reference_char' => '青', 'c_strict_excluded' => 0],
            ['c_variant_char' => '頴', 'c_reference_char' => '穎', 'c_strict_excluded' => 0],
            ['c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
            ['c_variant_char' => '厰', 'c_reference_char' => '廠', 'c_strict_excluded' => 0],
        ]);
    }

    #[Test]
    public function lenient_mode_replaces_strict_excluded_char(): void {
        $result = CharVariantMapService::replaceLenient('峯先生');

        $this->assertSame('峰先生', $result['text']);
        $this->assertSame(['峯' => '峰'], $result['replaced']);
    }

    #[Test]
    public function strict_mode_does_not_replace_strict_excluded_char(): void {
        $result = CharVariantMapService::replaceStrict('峯先生');

        $this->assertSame('峯先生', $result['text']);
        $this->assertArrayNotHasKey('峯', $result['replaced']);
    }

    #[Test]
    public function strict_mode_replaces_the_other_six_rows(): void {
        $testCases = [
            ['愼', '慎'],
            ['槀', '稿'],
            ['靑', '青'],
            ['頴', '穎'],
            ['淸', '清'],
            ['厰', '廠'],
        ];

        foreach ($testCases as [$variant, $reference]) {
            $result = CharVariantMapService::replaceStrict("姓{$variant}名");
            $this->assertSame("姓{$reference}名", $result['text']);
            $this->assertSame([$variant => $reference], $result['replaced']);
        }
    }

    #[Test]
    public function replaced_only_lists_characters_actually_present_in_input(): void {
        $result = CharVariantMapService::replaceLenient('峯淸兩字');

        $this->assertSame(['峯' => '峰', '淸' => '清'], $result['replaced']);
        $this->assertCount(2, $result['replaced']);
    }

    #[Test]
    public function cache_is_cleared_by_reset(): void {
        $before = CharVariantMapService::replaceLenient('厰');
        $this->assertSame('廠', $before['text']);

        DB::table('char_variant_map')->where('c_variant_char', '厰')->update(['c_reference_char' => '厂']);

        // Cache not yet reset: still returns the stale mapping.
        $stillCached = CharVariantMapService::replaceLenient('厰');
        $this->assertSame('廠', $stillCached['text']);

        CharVariantMapService::reset();

        $afterReset = CharVariantMapService::replaceLenient('厰');
        $this->assertSame('厂', $afterReset['text']);
    }

    #[Test]
    public function empty_or_unmatched_text_is_returned_unchanged(): void {
        $empty = CharVariantMapService::replaceLenient('');
        $this->assertSame('', $empty['text']);
        $this->assertSame([], $empty['replaced']);

        $unmatched = CharVariantMapService::replaceStrict('普通文字沒有異體字');
        $this->assertSame('普通文字沒有異體字', $unmatched['text']);
        $this->assertSame([], $unmatched['replaced']);
    }

    #[Test]
    public function blank_variant_char_in_table_is_skipped_without_warning(): void {
        DB::table('char_variant_map')->insert([
            'c_variant_char' => '',
            'c_reference_char' => '空字串防呆',
            'c_strict_excluded' => 0,
        ]);
        CharVariantMapService::reset();

        $result = CharVariantMapService::replaceLenient('普通文字');

        $this->assertSame('普通文字', $result['text']);
        $this->assertArrayNotHasKey('', $result['replaced']);
    }
}
