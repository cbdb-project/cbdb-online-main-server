<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiSearchPinyinTest extends TestCase {
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
            $table->string('lastname_chn')->primary();
            $table->string('lastname_pinyin')->nullable();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('pinyin');
        parent::tearDown();
    }

    #[Test]
    public function search_pinyin_converts_fullwidth_parentheses_and_keeps_space_before_parenthesis(): void {
        $response = $this->get('/api/select/search/pinyin?q=宗氏（李白妻）');

        $response->assertOk();
        $content = trim($response->getContent());

        $this->assertStringContainsString('(Wife of', $content);
        $this->assertStringNotContainsString('（', $content);
        $this->assertStringNotContainsString('）', $content);
        $this->assertStringContainsString(' (', $content);
    }

    #[Test]
    public function search_pinyin_converts_wife_pattern_to_wife_of_english_phrase(): void {
        DB::table('pinyin')->insert([
            'lastname_chn' => '李',
            'lastname_pinyin' => 'Li',
        ]);

        $response = $this->get('/api/select/search/pinyin?q=（李白妻）');

        $response->assertOk();
        $content = trim($response->getContent());
        $this->assertStringStartsWith('(Wife of ', $content);
        $this->assertStringEndsWith(')', $content);
        $this->assertStringNotContainsString('妻', $content);
    }

    #[Test]
    public function search_pinyin_matches_known_surname_using_same_logic_as_auto_pinyin(): void {
        DB::table('pinyin')->insert([
            'lastname_chn' => '王',
            'lastname_pinyin' => 'Wang',
        ]);

        $response = $this->get('/api/select/search/pinyin?q=王安石傳');

        $response->assertOk();
        $content = trim($response->getContent());
        $this->assertStringStartsWith('Wang ', $content);
    }

    #[Test]
    public function search_pinyin_converts_supported_relationship_patterns_to_english_phrases(): void {
        DB::table('pinyin')->insert([
            ['lastname_chn' => '李', 'lastname_pinyin' => 'Li'],
            ['lastname_chn' => '王', 'lastname_pinyin' => 'Wang'],
        ]);

        $cases = [
            '（李白母）' => 'Mother of Li Bai',
            '（王安石女）' => 'Daughter of Wang Anshi',
            '（李白妾）' => 'Concubine of Li Bai',
            '（王安石媳）' => 'Daughter-in-law of Wang Anshi',
            '（李白妹）' => 'Younger Sister of Li Bai',
            '（王安石姐）' => 'Elder Sister of Wang Anshi',
            '（李白姊）' => 'Elder Sister of Li Bai',
        ];

        foreach ($cases as $query => $expectedPhrase) {
            $response = $this->get('/api/select/search/pinyin?q='.urlencode($query));

            $response->assertOk();
            $content = trim($response->getContent());

            $this->assertSame('('.$expectedPhrase.')', $content);
        }
    }

    #[Test]
    public function search_pinyin_converts_expanded_relationship_patterns_to_english_phrases(): void {
        DB::table('pinyin')->insert([
            ['lastname_chn' => '李', 'lastname_pinyin' => 'Li'],
            ['lastname_chn' => '王', 'lastname_pinyin' => 'Wang'],
        ]);

        $cases = [
            '（李白夫）' => 'Husband of Li Bai',
            '（李白父）' => 'Father of Li Bai',
            '（王安石兄）' => 'Elder Brother of Wang Anshi',
            '（李白弟）' => 'Younger Brother of Li Bai',
            '（李白婿）' => 'Son-in-law of Li Bai',
            '（王安石嫂）' => 'Sister-in-law of Wang Anshi',
        ];

        foreach ($cases as $query => $expectedPhrase) {
            $response = $this->get('/api/select/search/pinyin?q='.urlencode($query));
            $response->assertOk();
            $this->assertSame('('.$expectedPhrase.')', trim($response->getContent()));
        }
    }

    #[Test]
    public function search_pinyin_prefers_multi_char_titles_over_single_char_suffix(): void {
        DB::table('pinyin')->insert([
            ['lastname_chn' => '李', 'lastname_pinyin' => 'Li'],
            ['lastname_chn' => '王', 'lastname_pinyin' => 'Wang'],
            ['lastname_chn' => '宗', 'lastname_pinyin' => 'Zong'],
        ]);

        // 多字稱謂不可被其單字後綴（母/父/女）誤切：祖母≠母、祖父≠父、孫女≠女。
        $cases = [
            '（李白祖母）' => '(Grandmother of Li Bai)',
            '（王安石祖父）' => '(Grandfather of Wang Anshi)',
            '（李白孫女）' => '(Granddaughter of Li Bai)',
            // 前綴形（特例 2）同樣須正確消歧
            '宗氏（李白祖母）' => 'Zong Shi (Grandmother of Li Bai)',
        ];

        foreach ($cases as $query => $expected) {
            $response = $this->get('/api/select/search/pinyin?q='.urlencode($query));
            $response->assertOk();
            $this->assertSame($expected, trim($response->getContent()));
        }
    }

    #[Test]
    public function search_pinyin_does_not_treat_non_relationship_parenthetical_as_relationship(): void {
        DB::table('pinyin')->insert([
            ['lastname_chn' => '李', 'lastname_pinyin' => 'Li'],
            ['lastname_chn' => '公', 'lastname_pinyin' => 'Gong'],
        ]);

        // 「子」「孫」非稱謂（刻意未收）：「（李子）」「（公孫）」這類括號別名/詞不應被誤判為關係稱謂。
        foreach (['（李子）', '（公孫）'] as $query) {
            $response = $this->get('/api/select/search/pinyin?q='.urlencode($query));
            $response->assertOk();
            $content = trim($response->getContent());
            $this->assertStringNotContainsString(' of ', $content);
        }
    }

    #[Test]
    public function search_pinyin_with_split_zero_does_not_split_by_surname(): void {
        DB::table('pinyin')->insert([
            'lastname_chn' => '安',
            'lastname_pinyin' => 'An',
        ]);

        // 預設 split=1 會在已知姓氏後插入空格
        $response = $this->get('/api/select/search/pinyin?q='.urlencode('安石'));
        $response->assertOk();
        $this->assertSame('An Shi', trim($response->getContent()));

        // split=0 不拆分姓氏，整體視為一個詞轉換
        $response = $this->get('/api/select/search/pinyin?q='.urlencode('安石').'&split=0');
        $response->assertOk();
        $this->assertSame('Anshi', trim($response->getContent()));
    }

    #[Test]
    public function search_pinyin_capitalizes_first_letter_after_left_parenthesis(): void {
        // 人名含括號（非關係稱謂，如別名/字號）時，左括號後首字母應大寫，
        // 與人名各段首字母大寫慣例一致。split=0 模擬基本資料編輯器「名」欄的生成拼音。
        $response = $this->get('/api/select/search/pinyin?q='.urlencode('李白（青蓮）').'&split=0');

        $response->assertOk();
        $content = trim($response->getContent());

        $this->assertSame('Libai (Qinglian)', $content);
        $this->assertStringNotContainsString('（', $content);
        $this->assertStringNotContainsString('）', $content);
    }

    #[Test]
    public function search_pinyin_keeps_relationship_phrase_capitalization_unchanged(): void {
        DB::table('pinyin')->insert([
            'lastname_chn' => '李',
            'lastname_pinyin' => 'Li',
        ]);

        // 關係片語括號內已為大寫英文（(Wife of ...)），大寫化不得誤傷或重覆處理。
        $response = $this->get('/api/select/search/pinyin?q='.urlencode('（李白妻）'));

        $response->assertOk();
        $this->assertSame('(Wife of Li Bai)', trim($response->getContent()));
    }

    #[Test]
    public function search_pinyin_converts_prefixed_relationship_patterns_to_english_phrases(): void {
        DB::table('pinyin')->insert([
            ['lastname_chn' => '宗', 'lastname_pinyin' => 'Zong'],
            ['lastname_chn' => '李', 'lastname_pinyin' => 'Li'],
        ]);

        $response = $this->get('/api/select/search/pinyin?q='.urlencode('宗氏（李白母）'));

        $response->assertOk();
        $content = trim($response->getContent());

        $this->assertSame('Zong Shi (Mother of Li Bai)', $content);
    }
}
