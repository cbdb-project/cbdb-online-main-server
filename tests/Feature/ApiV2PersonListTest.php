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

    public function test_modified_since_filters_by_watermark_inclusive(): void {
        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 1, 'c_created_date' => '2007-01-01 00:00:00', 'c_modified_date' => null],
            ['c_personid' => 2, 'c_created_date' => '2007-01-01 00:00:00', 'c_modified_date' => null],
            ['c_personid' => 3, 'c_created_date' => '2007-01-01 00:00:00', 'c_modified_date' => null],
            ['c_personid' => 4, 'c_created_date' => '2007-01-01 00:00:00', 'c_modified_date' => null], // 無 sidecar 列
        ]);
        DB::table('person_change_index')->insert([
            ['c_personid' => 1, 'c_last_modified_date' => '2026-01-01 00:00:00', 'c_created_date' => null, 'updated_at' => '2026-01-01 00:00:00'],
            ['c_personid' => 2, 'c_last_modified_date' => '2026-06-15 00:00:00', 'c_created_date' => null, 'updated_at' => '2026-06-15 00:00:00'], // 邊界
            ['c_personid' => 3, 'c_last_modified_date' => '2026-12-31 00:00:00', 'c_created_date' => null, 'updated_at' => '2026-12-31 00:00:00'],
        ]);

        $response = $this->getJson('/api/v2/persons?modified_since=2026-06-15 00:00:00');
        $response->assertOk();

        $ids = array_column($response->json('data'), 'c_personid');
        // 含邊界(2)、之後(3)；排除之前(1) 與無水位線(4)
        $this->assertEqualsCanonicalizing([2, 3], $ids);
        $this->assertSame(2, $response->json('pagination.total'));
    }

    public function test_modified_since_normalizes_timezone_to_app_timezone(): void {
        // app 時區固定為 +08（與 DB datetime 牆鐘一致），確保 UTC 輸入被正確轉換
        config()->set('app.timezone', 'Asia/Shanghai');

        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 1, 'c_created_date' => '2007-01-01 00:00:00', 'c_modified_date' => null],
            ['c_personid' => 2, 'c_created_date' => '2007-01-01 00:00:00', 'c_modified_date' => null],
        ]);
        DB::table('person_change_index')->insert([
            // 04:00 (+08) 早於 UTC 00:00 換算後的 08:00 門檻 → 應排除
            ['c_personid' => 1, 'c_last_modified_date' => '2026-06-15 04:00:00', 'c_created_date' => null, 'updated_at' => '2026-06-15 04:00:00'],
            // 12:00 (+08) 晚於 08:00 門檻 → 應納入
            ['c_personid' => 2, 'c_last_modified_date' => '2026-06-15 12:00:00', 'c_created_date' => null, 'updated_at' => '2026-06-15 12:00:00'],
        ]);

        // UTC 00:00 → 轉成 +08 的 08:00 門檻
        $response = $this->getJson('/api/v2/persons?modified_since=2026-06-15T00:00:00Z');
        $response->assertOk();

        $ids = array_column($response->json('data'), 'c_personid');
        $this->assertEqualsCanonicalizing([2], $ids);
    }

    public function test_modified_since_ignores_relative_keyword_input(): void {
        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 1, 'c_created_date' => '2007-01-01 00:00:00', 'c_modified_date' => null],
            ['c_personid' => 2, 'c_created_date' => '2007-01-01 00:00:00', 'c_modified_date' => null],
        ]);
        DB::table('person_change_index')->insert([
            ['c_personid' => 1, 'c_last_modified_date' => '2000-01-01 00:00:00', 'c_created_date' => null, 'updated_at' => '2000-01-01 00:00:00'],
        ]);

        // 'now' 不以 YYYY-MM-DD 起頭 → 被守衛擋掉 → 不過濾（回傳全部，over-fetch 安全），
        // 而非被 Carbon 解析成當下時間導致漏資料
        $response = $this->getJson('/api/v2/persons?modified_since=now');
        $response->assertOk();
        $this->assertSame(2, $response->json('pagination.total'));
    }

    public function test_invalid_modified_since_is_ignored(): void {
        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 1, 'c_created_date' => '2007-01-01 00:00:00', 'c_modified_date' => null],
            ['c_personid' => 2, 'c_created_date' => '2007-01-01 00:00:00', 'c_modified_date' => null],
        ]);

        $response = $this->getJson('/api/v2/persons?modified_since=not-a-date');
        $response->assertOk();

        // 無法解析 → 不套用過濾，回傳全部
        $this->assertSame(2, $response->json('pagination.total'));
    }

    public function test_modified_since_rejects_date_with_relative_suffix(): void {
        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 1, 'c_created_date' => '2007-01-01 00:00:00', 'c_modified_date' => null],
            ['c_personid' => 2, 'c_created_date' => '2007-01-01 00:00:00', 'c_modified_date' => null],
        ]);
        DB::table('person_change_index')->insert([
            ['c_personid' => 1, 'c_last_modified_date' => '2000-01-01 00:00:00', 'c_created_date' => null, 'updated_at' => '2000-01-01 00:00:00'],
        ]);

        // 「合法日期前綴 + 相對後綴」必須被完整鎖定的守衛擋掉（否則 Carbon 會解成更晚門檻而漏資料）→ 不過濾，回全部
        $response = $this->getJson('/api/v2/persons?' . http_build_query(['modified_since' => '2026-06-15 +1 day']));
        $response->assertOk();
        $this->assertSame(2, $response->json('pagination.total'));
    }

    public function test_modified_since_rejects_calendar_invalid_dates(): void {
        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 1, 'c_created_date' => '2007-01-01 00:00:00', 'c_modified_date' => null],
            ['c_personid' => 2, 'c_created_date' => '2007-01-01 00:00:00', 'c_modified_date' => null],
        ]);
        DB::table('person_change_index')->insert([
            ['c_personid' => 1, 'c_last_modified_date' => '2000-01-01 00:00:00', 'c_created_date' => null, 'updated_at' => '2000-01-01 00:00:00'],
        ]);

        // 形狀合法但曆法非法：不可被 Carbon 進位成更晚門檻（會漏資料），須視為無效→不過濾→回全部
        foreach (['2026-02-31', '2026-06-15 24:00:00', '2026-13-01', '2026-06-15 12:60:00'] as $bad) {
            $response = $this->getJson('/api/v2/persons?' . http_build_query(['modified_since' => $bad]));
            $response->assertOk();
            $this->assertSame(2, $response->json('pagination.total'), "輸入 {$bad} 應被忽略並回全部");
        }
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
