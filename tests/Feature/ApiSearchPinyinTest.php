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
}
