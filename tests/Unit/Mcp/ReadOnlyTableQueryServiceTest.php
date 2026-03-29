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
        DB::statement('CREATE TABLE BIOG_MAIN (c_personid INTEGER PRIMARY KEY, c_name_chn TEXT, c_dy TEXT, FOREIGN KEY (c_dy) REFERENCES DYNASTIES(c_dy))');

        DB::table('DYNASTIES')->insert([
            ['c_dy' => 'Tang', 'c_dynasty_chn' => '唐', 'status' => 'active'],
            ['c_dy' => 'Song', 'c_dynasty_chn' => '宋', 'status' => 'active'],
            ['c_dy' => 'Ming', 'c_dynasty_chn' => '明', 'status' => 'archived'],
        ]);

        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 1, 'c_name_chn' => '張三', 'c_dy' => 'Tang'],
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
        $result = $this->service->queryTableSchema('BIOG_MAIN');

        $this->assertSame('BIOG_MAIN', $result['table_name']);
        $this->assertIsArray($result['columns']);
        $this->assertIsArray($result['indexes']);
        $this->assertIsArray($result['foreign_keys']);
        $this->assertSame('sqlite', $result['table_info']['driver']);
        $this->assertNotEmpty($result['columns']);
        $this->assertNotEmpty($result['foreign_keys']);
        $this->assertSame('c_dy', $result['foreign_keys'][0]->from);
        $this->assertSame('DYNASTIES', $result['foreign_keys'][0]->table);
    }

    #[Test]
    public function it_executes_read_only_sql_for_allowlisted_tables(): void {
        $result = $this->service->queryReadOnlySql(
            "SELECT c_dy, c_dynasty_chn FROM DYNASTIES WHERE status = 'active' ORDER BY c_dy",
            10,
            0
        );

        $this->assertSame(['DYNASTIES'], $result['tables']);
        $this->assertSame(2, $result['returned_rows']);
        $this->assertContains('Tang', array_column($result['rows'], 'c_dy'));
    }

    #[Test]
    public function it_rejects_non_allowlisted_table_in_read_only_sql(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not in allowlist');

        $this->service->queryReadOnlySql('SELECT * FROM users');
    }

    #[Test]
    public function it_rejects_non_allowlisted_table_in_union_read_only_sql(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not in allowlist');

        $this->service->queryReadOnlySql('SELECT c_dy FROM DYNASTIES UNION ALL SELECT id FROM users');
    }

    #[Test]
    public function it_rejects_non_allowlisted_table_in_comma_separated_from_clause(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not in allowlist');

        $this->service->queryReadOnlySql('WITH x AS (SELECT 1) SELECT * FROM DYNASTIES, users');
    }

    #[Test]
    public function it_supports_with_query_in_read_only_sql(): void {
        $result = $this->service->queryReadOnlySql(
            'WITH active_dynasties AS (SELECT c_dy FROM DYNASTIES WHERE status = "active") SELECT c_dy FROM active_dynasties ORDER BY c_dy',
            10,
            0
        );

        $this->assertSame(['DYNASTIES'], $result['tables']);
        $this->assertSame(2, $result['returned_rows']);
    }

    #[Test]
    public function it_enforces_service_limit_for_with_query_that_has_existing_limit(): void {
        DB::table('DYNASTIES')->insert([
            ['c_dy' => 'A0', 'c_dynasty_chn' => '甲', 'status' => 'active'],
            ['c_dy' => 'A1', 'c_dynasty_chn' => '乙', 'status' => 'active'],
            ['c_dy' => 'A2', 'c_dynasty_chn' => '丙', 'status' => 'active'],
            ['c_dy' => 'A3', 'c_dynasty_chn' => '丁', 'status' => 'active'],
            ['c_dy' => 'A4', 'c_dynasty_chn' => '戊', 'status' => 'active'],
        ]);

        $result = $this->service->queryReadOnlySql(
            'WITH active_dynasties AS (SELECT c_dy FROM DYNASTIES WHERE status = "active") SELECT c_dy FROM active_dynasties ORDER BY c_dy LIMIT 100000',
            2,
            0
        );

        $this->assertSame(
            'WITH active_dynasties AS (SELECT c_dy FROM DYNASTIES WHERE status = "active") SELECT c_dy FROM active_dynasties ORDER BY c_dy LIMIT 100000',
            $result['sql']
        );
        $this->assertSame(['DYNASTIES'], $result['tables']);
        $this->assertSame(2, $result['returned_rows']);
    }

    #[Test]
    public function it_limits_remaining_rows_when_merging_existing_limit_and_offset(): void {
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = [
                'c_dy' => sprintf('Z%02d', $i),
                'c_dynasty_chn' => '測試',
                'status' => 'active',
            ];
        }
        DB::table('DYNASTIES')->insert($rows);

        $result = $this->service->queryReadOnlySql(
            'WITH z_dynasties AS (SELECT c_dy FROM DYNASTIES WHERE c_dy LIKE "Z%") SELECT c_dy FROM z_dynasties ORDER BY c_dy LIMIT 5 OFFSET 10',
            5,
            3
        );

        $this->assertSame(2, $result['returned_rows']);
        $this->assertSame('Z13', $result['rows'][0]['c_dy']);
        $this->assertSame('Z14', $result['rows'][1]['c_dy']);
    }

    #[Test]
    public function it_supports_multiple_ctes_without_treating_aliases_as_tables(): void {
        $result = $this->service->queryReadOnlySql(
            'WITH cte_dynasties AS (SELECT c_dy FROM DYNASTIES WHERE status = "active"), cte_people AS (SELECT c_personid FROM BIOG_MAIN) SELECT cte_dynasties.c_dy FROM cte_dynasties JOIN cte_people ON 1 = 1 ORDER BY cte_dynasties.c_dy',
            10,
            0
        );

        $this->assertEqualsCanonicalizing(['DYNASTIES', 'BIOG_MAIN'], $result['tables']);
        $this->assertSame(2, $result['returned_rows']);
    }

    #[Test]
    public function it_rejects_into_outfile_clause_in_read_only_sql(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('forbidden read-only side-effect clauses');

        $this->service->queryReadOnlySql('SELECT c_dy INTO OUTFILE "x" FROM DYNASTIES');
    }

    #[Test]
    public function it_rejects_non_select_read_only_sql(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only SELECT / WITH queries are allowed');

        $this->service->queryReadOnlySql('DELETE FROM DYNASTIES');
    }

    #[Test]
    public function it_supports_with_recursive_in_read_only_sql(): void {
        DB::statement('DROP TABLE IF EXISTS ADDR_TREE');
        DB::statement('CREATE TABLE ADDR_TREE (id INTEGER PRIMARY KEY, parent_id INTEGER, name TEXT)');
        DB::table('ADDR_TREE')->insert([
            ['id' => 1, 'parent_id' => null, 'name' => 'Root'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'Child1'],
            ['id' => 3, 'parent_id' => 1, 'name' => 'Child2'],
            ['id' => 4, 'parent_id' => 2, 'name' => 'Grandchild'],
        ]);
        Config::set('mcp.cbdb.allowed_tables', ['DYNASTIES', 'BIOG_MAIN', 'ADDR_TREE']);

        $this->service = new ReadOnlyTableQueryService();

        $result = $this->service->queryReadOnlySql(
            'WITH RECURSIVE tree AS ('
            . '  SELECT id, parent_id, name, 1 AS lvl FROM ADDR_TREE WHERE parent_id IS NULL'
            . '  UNION ALL'
            . '  SELECT t.id, t.parent_id, t.name, tree.lvl + 1 FROM ADDR_TREE t JOIN tree ON t.parent_id = tree.id WHERE tree.lvl < 5'
            . ') SELECT * FROM tree ORDER BY id',
            20,
            0
        );

        $this->assertEqualsCanonicalizing(['ADDR_TREE'], $result['tables']);
        $this->assertSame(4, $result['returned_rows']);
    }
}
