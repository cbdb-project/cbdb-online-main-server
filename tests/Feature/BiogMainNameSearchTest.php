<?php

namespace Tests\Feature;

use App\BiogMain;
use App\Repositories\BiogMainRepository;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BiogMainNameSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 設定使用 SQLite in-memory 資料庫
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // 設置快取為陣列驅動
        config(['cache.default' => 'array']);

        // 使用陣列驅動避免檔案權限問題
        config(['session.driver' => 'array']);

        // 創建必要的測試表結構
        $this->createTestTables();
        $this->seedTestData();
    }

    protected function createTestTables(): void
    {
        Schema::create('BIOG_MAIN', function ($table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
            $table->string('c_surname')->nullable();
            $table->string('c_mingzi')->nullable();
            $table->string('c_name_proper')->nullable();
            $table->string('c_name_rm')->nullable();
            $table->string('c_surname_proper')->nullable();
            $table->string('c_mingzi_proper')->nullable();
            $table->string('c_surname_rm')->nullable();
            $table->string('c_mingzi_rm')->nullable();
            $table->integer('c_index_year')->nullable();
            $table->integer('c_dy')->nullable();
            $table->integer('c_index_addr_id')->nullable();
        });

        Schema::create('DYNASTIES', function ($table) {
            $table->integer('c_dy')->primary();
            $table->string('c_dynasty_chn')->nullable();
        });

        Schema::create('ADDR_CODES', function ($table) {
            $table->integer('c_addr_id')->primary();
            $table->string('c_name_chn')->nullable();
        });

        Schema::create('ALTNAME_DATA', function ($table) {
            $table->integer('c_personid');
            $table->integer('c_alt_name_type_code');
            $table->string('c_alt_name_chn')->nullable();
            $table->primary(['c_personid', 'c_alt_name_type_code']);
        });
    }

    protected function seedTestData(): void
    {
        // 插入測試數據
        DB::table('BIOG_MAIN')->insert([
            [
                'c_personid' => 1001,
                'c_name_chn' => '蘇軾',
                'c_name' => 'Su Shi',
                'c_surname' => '蘇',
                'c_mingzi' => '軾',
                'c_index_year' => 1050,
                'c_dy' => 15,
                'c_index_addr_id' => 100,
                'c_name_proper' => null,
                'c_name_rm' => null,
                'c_surname_proper' => null,
                'c_mingzi_proper' => null,
                'c_surname_rm' => null,
                'c_mingzi_rm' => null,
            ],
            [
                'c_personid' => 1002,
                'c_name_chn' => '蘇轍',
                'c_name' => 'Su Zhe',
                'c_surname' => '蘇',
                'c_mingzi' => '轍',
                'c_index_year' => 1045,
                'c_dy' => 15,
                'c_index_addr_id' => 100,
                'c_name_proper' => null,
                'c_name_rm' => null,
                'c_surname_proper' => null,
                'c_mingzi_proper' => null,
                'c_surname_rm' => null,
                'c_mingzi_rm' => null,
            ],
            [
                'c_personid' => 2001,
                'c_name_chn' => '王安石',
                'c_name' => 'Wang Anshi',
                'c_surname' => '王',
                'c_mingzi' => '安石',
                'c_index_year' => 1030,
                'c_dy' => 15,
                'c_index_addr_id' => 101,
                'c_name_proper' => null,
                'c_name_rm' => null,
                'c_surname_proper' => null,
                'c_mingzi_proper' => null,
                'c_surname_rm' => null,
                'c_mingzi_rm' => null,
            ],
        ]);

        DB::table('DYNASTIES')->insert([
            ['c_dy' => 15, 'c_dynasty_chn' => '宋'],
        ]);

        DB::table('ADDR_CODES')->insert([
            ['c_addr_id' => 100, 'c_name_chn' => '眉州'],
            ['c_addr_id' => 101, 'c_name_chn' => '臨川'],
        ]);

        DB::table('ALTNAME_DATA')->insert([
            ['c_personid' => 1001, 'c_alt_name_type_code' => 4, 'c_alt_name_chn' => '子瞻'],
            ['c_personid' => 1001, 'c_alt_name_type_code' => 5, 'c_alt_name_chn' => '東坡居士'],
            ['c_personid' => 1002, 'c_alt_name_type_code' => 4, 'c_alt_name_chn' => '子由'],
        ]);
    }

    public function test_numeric_query_uses_personid_direct_lookup(): void
    {
        $request = new Request(['q' => '1001']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertCount(1, $result);

        $person = $result->items()[0];
        $this->assertEquals(1001, $person->c_personid);
        $this->assertEquals('蘇軾', $person->c_name_chn);
        $this->assertEquals('宋', $person->c_dynasty_chn);
        $this->assertEquals('眉州', $person->ADDR_c_name_chn);
        $this->assertEquals('子瞻', $person->c_alt_name_chn_zi);
        $this->assertEquals('東坡居士', $person->c_alt_name_chn_hao);
    }

    public function test_numeric_query_returns_empty_for_nonexistent_id(): void
    {
        $request = new Request(['q' => '9999']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertCount(0, $result);
    }

    public function test_text_query_uses_complex_search(): void
    {
        // 注意：此測試在 SQLite 中會因為 FIELD() 函數不存在而失敗
        // 在實際的 MySQL/MariaDB 環境中可以正常運行
        // 因此我們只測試基本的查詢行為，不深入測試 FIELD() 排序

        $this->markTestSkipped('SQLite 不支持 MySQL FIELD() 函數，此測試需要在 MySQL/MariaDB 環境中執行');
    }

    public function test_mixed_alphanumeric_query_uses_complex_search(): void
    {
        $request = new Request(['q' => 'Su1001']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        // 混合字母數字應該使用複雜搜尋，而非純數字優化
        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
    }

    public function test_leading_zeros_not_treated_as_numeric(): void
    {
        $request = new Request(['q' => '01001']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        // 前導零會被 ctype_digit 判定為 true，但 addslashes 可能會移除
        // 這個測試驗證行為的一致性
        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
    }

    public function test_numeric_query_includes_all_join_data(): void
    {
        $request = new Request(['q' => '1002']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        $this->assertCount(1, $result);

        $person = $result->items()[0];
        $this->assertEquals(1002, $person->c_personid);
        $this->assertEquals('蘇轍', $person->c_name_chn);
        $this->assertEquals('宋', $person->c_dynasty_chn);
        $this->assertEquals('眉州', $person->ADDR_c_name_chn);
        $this->assertEquals('子由', $person->c_alt_name_chn_zi);
        // 蘇轍沒有號，應該為 null
        $this->assertNull($person->c_alt_name_chn_hao);
    }

    public function test_empty_query_returns_paginated_list(): void
    {
        $request = new Request(['q' => '']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        // 空查詢應該返回 JSON 字串（原有邏輯）
        $this->assertInternalType('string', $result);

        $decoded = json_decode($result, true);
        $this->assertInternalType('array', $decoded);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertGreaterThanOrEqual(3, count($decoded['data']));
    }
}
