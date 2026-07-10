<?php

namespace Tests\Unit;

use App\Services\PinyinDictionary;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 測試 PinyinDictionary::getPinyin() 的查詢與優先序邏輯。
 *
 * 覆蓋場景：
 *   1. 命中一般字典（c_lastname=0）
 *   2. 命中姓氏字典（c_lastname=1），且該字沒有一般讀音
 *   3. 同一字兩邊都有資料時，c_lastname=0（一般讀音）優先
 *   4. 查無此字回傳原字元
 *   5. 多字字串逐字串接
 *   6. reset() 後重新查詢
 */
class PinyinDictionaryTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        Schema::dropIfExists('pinyin');
        Schema::create('pinyin', function (Blueprint $table) {
            $table->increments('id');
            $table->string('c_chn');
            $table->string('c_pinyin');
            $table->tinyInteger('c_lastname')->default(0);
        });

        PinyinDictionary::reset();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('pinyin');
        PinyinDictionary::reset();

        parent::tearDown();
    }

    #[Test]
    public function it_resolves_a_character_only_present_in_general_dictionary(): void {
        DB::table('pinyin')->insert(['c_chn' => '安', 'c_pinyin' => 'an', 'c_lastname' => 0]);

        $this->assertSame('an', PinyinDictionary::getPinyin('安'));
    }

    #[Test]
    public function it_falls_back_to_surname_reading_when_no_general_reading_exists(): void {
        DB::table('pinyin')->insert(['c_chn' => '單', 'c_pinyin' => 'Shan', 'c_lastname' => 1]);

        $this->assertSame('Shan', PinyinDictionary::getPinyin('單'));
    }

    #[Test]
    public function it_prefers_general_reading_over_surname_reading_on_conflict(): void {
        DB::table('pinyin')->insert(['c_chn' => '趙', 'c_pinyin' => 'zhao', 'c_lastname' => 0]);
        DB::table('pinyin')->insert(['c_chn' => '趙', 'c_pinyin' => 'Zhao', 'c_lastname' => 1]);

        $this->assertSame('zhao', PinyinDictionary::getPinyin('趙'));
    }

    #[Test]
    public function it_returns_original_character_when_not_found(): void {
        $this->assertSame('未', PinyinDictionary::getPinyin('未'));
    }

    #[Test]
    public function it_concatenates_pinyin_for_multi_character_strings(): void {
        DB::table('pinyin')->insert(['c_chn' => '安', 'c_pinyin' => 'an', 'c_lastname' => 0]);
        DB::table('pinyin')->insert(['c_chn' => '石', 'c_pinyin' => 'shi', 'c_lastname' => 0]);

        $this->assertSame('anshi', PinyinDictionary::getPinyin('安石'));
    }

    #[Test]
    public function it_returns_empty_string_for_empty_input(): void {
        $this->assertSame('', PinyinDictionary::getPinyin(''));
    }

    #[Test]
    public function it_mixes_matched_and_unmatched_characters_in_the_same_string(): void {
        DB::table('pinyin')->insert(['c_chn' => '安', 'c_pinyin' => 'an', 'c_lastname' => 0]);
        DB::table('pinyin')->insert(['c_chn' => '石', 'c_pinyin' => 'shi', 'c_lastname' => 0]);

        $this->assertSame('an未shi', PinyinDictionary::getPinyin('安未石'));
    }

    #[Test]
    public function reset_forces_cache_to_reload_from_database(): void {
        DB::table('pinyin')->insert(['c_chn' => '安', 'c_pinyin' => 'an', 'c_lastname' => 0]);
        $this->assertSame('an', PinyinDictionary::getPinyin('安'));

        DB::table('pinyin')->where('c_chn', '安')->update(['c_pinyin' => 'AN']);
        // 快取仍是舊值，未 reset 前不會反映資料庫的變更
        $this->assertSame('an', PinyinDictionary::getPinyin('安'));

        PinyinDictionary::reset();
        $this->assertSame('AN', PinyinDictionary::getPinyin('安'));
    }
}
