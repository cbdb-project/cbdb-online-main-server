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
}
