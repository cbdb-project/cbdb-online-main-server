<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ApiV2PersonListTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
            $table->dateTime('c_created_date')->nullable();
            // BIOG_MAIN 本表也有 c_modified_date（本列語意）；用來驗證 API 不會誤輸出它，
            // 而是輸出 person_change_index 的人物層級水位線。
            $table->dateTime('c_modified_date')->nullable();
        });

        Schema::create('person_change_index', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->dateTime('c_last_modified_date')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('person_change_index');
        Schema::dropIfExists('BIOG_MAIN');
        parent::tearDown();
    }

    public function test_returns_paginated_person_ids(): void {
        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 10, 'c_name_chn' => '甲', 'c_name' => 'A'],
            ['c_personid' => 20, 'c_name_chn' => '乙', 'c_name' => 'B'],
            ['c_personid' => 30, 'c_name_chn' => '丙', 'c_name' => 'C'],
        ]);

        $response = $this->getJson('/api/v2/persons');

        $response->assertOk()->assertJson([
            'ok' => true,
        ]);

        $data = $response->json('data');
        $this->assertCount(3, $data);
        $this->assertEquals(10, $data[0]['c_personid']);
        $this->assertEquals(20, $data[1]['c_personid']);
        $this->assertEquals(30, $data[2]['c_personid']);
    }

    public function test_returns_correct_pagination_structure(): void {
        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 1, 'c_name_chn' => '甲', 'c_name' => 'A'],
            ['c_personid' => 2, 'c_name_chn' => '乙', 'c_name' => 'B'],
        ]);

        $response = $this->getJson('/api/v2/persons?per_page=1&page=1');

        $response->assertOk()->assertJsonStructure([
            'ok',
            'data',
            'pagination' => ['total', 'per_page', 'current_page', 'last_page', 'from', 'to'],
        ]);

        $pagination = $response->json('pagination');
        $this->assertEquals(2, $pagination['total']);
        $this->assertEquals(1, $pagination['per_page']);
        $this->assertEquals(1, $pagination['current_page']);
        $this->assertEquals(2, $pagination['last_page']);
        $this->assertEquals(1, $pagination['from']);
        $this->assertEquals(1, $pagination['to']);
    }

    public function test_per_page_second_page(): void {
        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 1, 'c_name_chn' => '甲', 'c_name' => 'A'],
            ['c_personid' => 2, 'c_name_chn' => '乙', 'c_name' => 'B'],
            ['c_personid' => 3, 'c_name_chn' => '丙', 'c_name' => 'C'],
        ]);

        $response = $this->getJson('/api/v2/persons?per_page=2&page=2');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals(3, $data[0]['c_personid']);
    }

    public function test_per_page_capped_at_1000(): void {
        $response = $this->getJson('/api/v2/persons?per_page=9999');

        $response->assertOk();
        $this->assertEquals(1000, $response->json('pagination.per_page'));
    }

    public function test_invalid_per_page_falls_back_to_default(): void {
        $response = $this->getJson('/api/v2/persons?per_page=-5');

        $response->assertOk();
        $this->assertEquals(100, $response->json('pagination.per_page'));
    }

    public function test_empty_table_returns_zero_pagination(): void {
        $response = $this->getJson('/api/v2/persons');

        $response->assertOk()->assertJson([
            'ok' => true,
            'data' => [],
        ]);

        $pagination = $response->json('pagination');
        $this->assertEquals(0, $pagination['total']);
        $this->assertEquals(0, $pagination['from']);
        $this->assertEquals(0, $pagination['to']);
    }

    public function test_results_ordered_by_personid_ascending(): void {
        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 300, 'c_name_chn' => '丙', 'c_name' => 'C'],
            ['c_personid' => 100, 'c_name_chn' => '甲', 'c_name' => 'A'],
            ['c_personid' => 200, 'c_name_chn' => '乙', 'c_name' => 'B'],
        ]);

        $response = $this->getJson('/api/v2/persons');

        $response->assertOk();
        $ids = array_column($response->json('data'), 'c_personid');
        $this->assertEquals([100, 200, 300], $ids);
    }

    public function test_accessible_without_authentication(): void {
        $response = $this->getJson('/api/v2/persons');

        $response->assertOk()->assertJson(['ok' => true]);
    }

    public function test_outputs_created_date_and_modified_date_from_sidecar(): void {
        DB::table('BIOG_MAIN')->insert([
            [
                'c_personid' => 10,
                'c_name_chn' => '甲',
                'c_name' => 'A',
                'c_created_date' => '2007-01-01 00:00:00',
                'c_modified_date' => '2010-01-01 00:00:00', // BIOG_MAIN 本表語意，不應被輸出
            ],
        ]);
        DB::table('person_change_index')->insert([
            [
                'c_personid' => 10,
                'c_last_modified_date' => '2026-03-12 09:21:00',
                'c_created_date' => '2007-01-01 00:00:00',
                'updated_at' => '2026-03-12 09:21:00',
            ],
        ]);

        $response = $this->getJson('/api/v2/persons');
        $response->assertOk();

        $row = $response->json('data.0');
        $this->assertSame(10, $row['c_personid']);
        // c_created_date 來自 BIOG_MAIN
        $this->assertSame('2007-01-01 00:00:00', $row['c_created_date']);
        // c_modified_date 來自 person_change_index 水位線，而非 BIOG_MAIN.c_modified_date(2010)
        $this->assertSame('2026-03-12 09:21:00', $row['c_modified_date']);
    }

    public function test_modified_date_is_null_when_no_sidecar_row(): void {
        DB::table('BIOG_MAIN')->insert([
            [
                'c_personid' => 5,
                'c_name_chn' => '甲',
                'c_name' => 'A',
                'c_created_date' => '2007-01-01 00:00:00',
                'c_modified_date' => '2010-01-01 00:00:00',
            ],
        ]);

        $response = $this->getJson('/api/v2/persons');
        $response->assertOk();

        $row = $response->json('data.0');
        $this->assertSame('2007-01-01 00:00:00', $row['c_created_date']);
        $this->assertArrayHasKey('c_modified_date', $row);
        $this->assertNull($row['c_modified_date']);
    }
}
