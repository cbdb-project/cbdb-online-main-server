<?php

namespace Tests\Unit\Mcp;

use App\Services\Mcp\ReadOnlyTableQueryService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReadOnlyTableQueryServiceTest extends TestCase {
    protected ReadOnlyTableQueryService $service;

    protected function setUp(): void {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('DROP TABLE IF EXISTS DYNASTIES');
        DB::statement('DROP TABLE IF EXISTS BIOG_MAIN');

        DB::statement('CREATE TABLE DYNASTIES (c_dy TEXT PRIMARY KEY, c_dynasty_chn TEXT, status TEXT)');
        DB::statement('CREATE TABLE BIOG_MAIN (c_personid INTEGER PRIMARY KEY, c_name_chn TEXT)');

        DB::table('DYNASTIES')->insert([
            ['c_dy' => 'Tang', 'c_dynasty_chn' => '唐', 'status' => 'active'],
            ['c_dy' => 'Song', 'c_dynasty_chn' => '宋', 'status' => 'active'],
            ['c_dy' => 'Ming', 'c_dynasty_chn' => '明', 'status' => 'archived'],
        ]);

        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 1, 'c_name_chn' => '張三'],
        ]);

        Config::set('mcp.cbdb.max_limit', 100);
        Config::set('mcp.cbdb.allowed_tables', ['DYNASTIES', 'BIOG_MAIN']);

        $this->service = new ReadOnlyTableQueryService();
    }

    #[Test]
    public function it_lists_allowlisted_tables(): void {
        $tables = $this->service->listAllowedTables();

        $this->assertSame(['DYNASTIES', 'BIOG_MAIN'], $tables);
    }

    #[Test]
    public function it_rejects_non_allowlisted_table(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not in allowlist');

        $this->service->getSampleData('USERS');
    }

    #[Test]
    public function it_gets_sample_data_with_pagination(): void {
        $result = $this->service->getSampleData('DYNASTIES', 2, 1);

        $this->assertSame('DYNASTIES', $result['table_name']);
        $this->assertSame(3, $result['total_rows']);
        $this->assertSame(2, $result['returned_rows']);
        $this->assertSame('Song', $result['rows'][0]['c_dy']);
    }

    #[Test]
    public function it_fetches_row_by_id(): void {
        $result = $this->service->getTableRowById('BIOG_MAIN', 'c_personid', 1);

        $this->assertSame('BIOG_MAIN', $result['table_name']);
        $this->assertSame('c_personid', $result['id_column']);
        $this->assertSame('張三', $result['row']['c_name_chn']);
    }

    #[Test]
    public function it_queries_table_with_filters_and_like(): void {
        $result = $this->service->queryTable(
            'DYNASTIES',
            [
                'status' => 'active',
                'c_dynasty_chn' => '%宋%',
            ],
            ['c_dy', 'c_dynasty_chn'],
            10,
            0
        );

        $this->assertSame(1, $result['total_matching_rows']);
        $this->assertSame('Song', $result['rows'][0]['c_dy']);
        $this->assertArrayNotHasKey('status', $result['rows'][0]);
    }

    #[Test]
    public function it_supports_json_string_filters(): void {
        $result = $this->service->queryTable('DYNASTIES', '{"status":"archived"}', null, 10, 0);

        $this->assertSame(1, $result['total_matching_rows']);
        $this->assertSame('Ming', $result['rows'][0]['c_dy']);
    }

    #[Test]
    public function it_rejects_invalid_limit(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be between 1 and 100');

        $this->service->getSampleData('DYNASTIES', 101, 0);
    }

    #[Test]
    public function it_rejects_invalid_identifier(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid column name');

        $this->service->queryTable('DYNASTIES', null, 'c_dy, bad-column', 10, 0);
    }

    #[Test]
    public function it_returns_sqlite_schema_info(): void {
        $result = $this->service->queryTableSchema('DYNASTIES');

        $this->assertSame('DYNASTIES', $result['table_name']);
        $this->assertIsArray($result['columns']);
        $this->assertIsArray($result['indexes']);
        $this->assertSame('sqlite', $result['table_info']['driver']);
        $this->assertNotEmpty($result['columns']);
    }
}
