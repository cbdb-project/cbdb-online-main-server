<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiSearchOfficeTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        Schema::dropIfExists('OFFICE_CODES');
        Schema::dropIfExists('DYNASTIES');

        Schema::create('DYNASTIES', function (Blueprint $table) {
            $table->smallInteger('c_dy')->primary();
            $table->string('c_dynasty_chn')->nullable();
            $table->integer('c_dynasty_firstyear')->nullable();
            $table->integer('c_dynasty_lastyear')->nullable();
        });

        Schema::create('OFFICE_CODES', function (Blueprint $table) {
            $table->integer('c_office_id')->primary();
            $table->smallInteger('c_dy')->nullable();
            $table->string('c_office_pinyin')->nullable();
            $table->string('c_office_chn')->nullable();
            $table->string('c_office_trans')->nullable();
        });

        DB::table('DYNASTIES')->insert([
            ['c_dy' => 15, 'c_dynasty_chn' => '宋'],
            ['c_dy' => 17, 'c_dynasty_chn' => '金'],
        ]);

        DB::table('OFFICE_CODES')->insert([
            ['c_office_id' => 1001, 'c_dy' => 15, 'c_office_pinyin' => 'li bu shi lang', 'c_office_chn' => '吏部侍郎'],
            ['c_office_id' => 1002, 'c_dy' => 17, 'c_office_pinyin' => 'li bu shi lang', 'c_office_chn' => '吏部侍郎'],
            ['c_office_id' => 1003, 'c_dy' => 15, 'c_office_pinyin' => 'li bu shang shu', 'c_office_chn' => '吏部尚書'],
        ]);
    }

    protected function tearDown(): void {
        Schema::dropIfExists('OFFICE_CODES');
        Schema::dropIfExists('DYNASTIES');
        parent::tearDown();
    }

    #[Test]
    public function search_office_with_positive_dynasty_returns_only_that_dynasty(): void {
        $response = $this->get('/api/select/search/office?q=吏部侍郎&c_dy=17');

        $response->assertOk();
        $data = $response->json('data');

        $ids = array_column($data, 'c_office_id');
        $this->assertContains(1002, $ids);
        $this->assertNotContains(1001, $ids);
    }

    #[Test]
    public function search_office_with_zero_dynasty_returns_all_matching_offices(): void {
        $response = $this->get('/api/select/search/office?q=吏部侍郎&c_dy=0');

        $response->assertOk();
        $data = $response->json('data');

        $ids = array_column($data, 'c_office_id');
        $this->assertContains(1001, $ids);
        $this->assertContains(1002, $ids);
    }

    #[Test]
    public function search_office_with_negative_dynasty_returns_all_matching_offices(): void {
        $response = $this->get('/api/select/search/office?q=吏部侍郎&c_dy=-1');

        $response->assertOk();
        $data = $response->json('data');

        $ids = array_column($data, 'c_office_id');
        $this->assertContains(1001, $ids);
        $this->assertContains(1002, $ids);
    }

    #[Test]
    public function search_office_without_dynasty_returns_all_matching_offices(): void {
        $response = $this->get('/api/select/search/office?q=吏部侍郎');

        $response->assertOk();
        $data = $response->json('data');

        $ids = array_column($data, 'c_office_id');
        $this->assertContains(1001, $ids);
        $this->assertContains(1002, $ids);
    }

    #[Test]
    public function search_office_falls_back_to_all_dynasties_when_current_dynasty_has_no_match(): void {
        // 朝代 99 在 OFFICE_CODES 中没有任何官职，应 fallback 到全朝代结果
        DB::table('DYNASTIES')->insert(['c_dy' => 99, 'c_dynasty_chn' => '測試朝']);

        $response = $this->get('/api/select/search/office?q=吏部侍郎&c_dy=99');

        $response->assertOk();
        $data = $response->json('data');

        $ids = array_column($data, 'c_office_id');
        $this->assertContains(1001, $ids);
        $this->assertContains(1002, $ids);
    }

    #[Test]
    public function search_office_with_numeric_zero_query_preserves_q_in_pagination_links(): void {
        // q=0 是合法的 office_id 搜索，array_filter 不应丢掉它
        DB::table('OFFICE_CODES')->insert([
            'c_office_id' => 0, 'c_dy' => 15, 'c_office_pinyin' => 'unknown', 'c_office_chn' => '未知',
        ]);

        $response = $this->get('/api/select/search/office?q=0');

        $response->assertOk();
        // 分页链接中应保留 q=0
        $this->assertStringContainsString('q=0', $response->getContent());
    }

    #[Test]
    public function search_office_page_two_request_with_zero_query_still_preserves_q_in_links(): void {
        // 验证翻页请求（page=2）中 q=0 同样不被丢弃
        DB::table('OFFICE_CODES')->insert([
            'c_office_id' => 0, 'c_dy' => 15, 'c_office_pinyin' => 'unknown', 'c_office_chn' => '未知',
        ]);

        $response = $this->get('/api/select/search/office?q=0&page=2');

        $response->assertOk();
        // first_page_url 和 last_page_url 都必须带 q=0，确保翻页链接不丢参数
        $firstPageUrl = $response->json('first_page_url');
        $this->assertNotNull($firstPageUrl);
        $this->assertStringContainsString('q=0', $firstPageUrl);
    }
}
