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
