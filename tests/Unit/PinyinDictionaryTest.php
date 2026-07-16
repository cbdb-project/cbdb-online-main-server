<?php

namespace Tests\Unit;

use App\Services\PinyinDictionary;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 測試 PinyinDictionary 的查詢與優先序邏輯。
 *
 * 覆蓋場景：
 *   1. 命中一般字典（c_lastname=0）
 *   2. 命中姓氏字典（c_lastname=1），且該字沒有一般讀音
 *   3. 同一字兩邊都有資料時，c_lastname=0（一般讀音）優先
 *   4. pinyin 表查無時退回 opencc-pinyin 靜態字典；表資料優先於靜態字典
 *   5. 兩層皆查無此字回傳原字元（以 Ext G 區 𰻞 U+30EDE 為樣本，zdic 不含）
 *   6. 多字字串逐字串接
 *   7. reset() 後重新查詢
 *   8. getSyllables() 音節邊界與 getNamePinyin() 隔音符規則（長安 → chang'an）
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
    public function it_falls_back_to_static_dictionary_when_table_misses(): void {
        // pinyin 表為空，峯 由 opencc-pinyin 靜態字典（zdic 首讀音、無聲調）補上。
        $this->assertSame('feng', PinyinDictionary::getPinyin('峯'));
    }

    #[Test]
    public function table_reading_wins_over_static_dictionary(): void {
        // 多音字取捨屬於 pinyin 表的人工策展層：表中 重=chong 應蓋過靜態字典的 zhong。
        DB::table('pinyin')->insert(['c_chn' => '重', 'c_pinyin' => 'chong', 'c_lastname' => 0]);

        $this->assertSame('chong', PinyinDictionary::getPinyin('重'));
    }

    #[Test]
    public function it_returns_original_character_when_not_found(): void {
        // 𰻞（U+30EDE，Ext G）在 pinyin 表與靜態字典皆無讀音。
        $this->assertSame('𰻞', PinyinDictionary::getPinyin('𰻞'));
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

        $this->assertSame('an𰻞shi', PinyinDictionary::getPinyin('安𰻞石'));
    }

    #[Test]
    public function get_syllables_preserves_boundaries_and_marks_unmapped_as_null(): void {
        DB::table('pinyin')->insert(['c_chn' => '安', 'c_pinyin' => 'an', 'c_lastname' => 0]);

        $this->assertSame([
            ['char' => '安', 'pinyin' => 'an'],
            ['char' => '𰻞', 'pinyin' => null],
            ['char' => '峯', 'pinyin' => 'feng'],
        ], PinyinDictionary::getSyllables('安𰻞峯'));
    }

    #[Test]
    public function get_name_pinyin_inserts_apostrophe_before_a_o_e_syllables(): void {
        // 依漢語拼音正詞法（GB/T 16159），連寫音節以 a/o/e 開頭時插入隔音符。
        $this->assertSame("chang'an", PinyinDictionary::getNamePinyin('長安'));
        $this->assertSame("xi'an", PinyinDictionary::getNamePinyin('西安'));
        // 首音節不插；非 a/o/e 開頭的後續音節也不插。
        $this->assertSame('an', PinyinDictionary::getNamePinyin('安'));
        $this->assertSame('anshi', PinyinDictionary::getNamePinyin('安石'));
    }

    #[Test]
    public function get_name_pinyin_keeps_unmapped_characters_without_apostrophes(): void {
        // 查無讀音的字元原樣保留（維持「無拼音」信號），其前後不插隔音符。
        $this->assertSame('an𰻞an', PinyinDictionary::getNamePinyin('安𰻞安'));
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
