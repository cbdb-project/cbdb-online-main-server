<?php

namespace Tests\Unit\Services;

use App\Services\SqlTableNameExtractor;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SqlTableNameExtractorTest extends TestCase {
    protected SqlTableNameExtractor $extractor;

    protected function setUp(): void {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement('DROP TABLE IF EXISTS DYNASTIES');
        DB::statement('DROP TABLE IF EXISTS BIOG_MAIN');
        DB::statement('CREATE TABLE DYNASTIES (c_dy TEXT PRIMARY KEY, status TEXT)');
        DB::statement('CREATE TABLE BIOG_MAIN (c_personid INTEGER PRIMARY KEY)');

        $this->extractor = new SqlTableNameExtractor();
    }

    #[Test]
    public function it_extracts_tables_from_simple_select(): void {
        $tables = $this->extractor->extractTableNames('SELECT c_dy FROM DYNASTIES');

        $this->assertSame(['DYNASTIES'], $tables);
    }

    #[Test]
    public function it_extracts_base_tables_from_with_query(): void {
        $tables = $this->extractor->extractTableNames(
            'WITH active_dynasties AS (SELECT c_dy FROM DYNASTIES WHERE status = "active") SELECT c_dy FROM active_dynasties'
        );

        $this->assertSame(['DYNASTIES'], $tables);
    }

    #[Test]
    public function it_extracts_base_tables_from_multiple_ctes(): void {
        $tables = $this->extractor->extractTableNames(
            'WITH cte_dynasties AS (SELECT c_dy FROM DYNASTIES), cte_people AS (SELECT c_personid FROM BIOG_MAIN) SELECT cte_dynasties.c_dy FROM cte_dynasties JOIN cte_people ON 1 = 1'
        );

        $this->assertEqualsCanonicalizing(['DYNASTIES', 'BIOG_MAIN'], $tables);
    }

    #[Test]
    public function it_normalizes_schema_qualified_table_names(): void {
        $tables = $this->extractor->extractTableNames('SELECT * FROM main.DYNASTIES');

        $this->assertSame(['DYNASTIES'], $tables);
    }

    #[Test]
    public function it_extracts_tables_from_union_queries(): void {
        $tables = $this->extractor->extractTableNames('SELECT * FROM DYNASTIES UNION ALL SELECT * FROM BIOG_MAIN');

        $this->assertEqualsCanonicalizing(['DYNASTIES', 'BIOG_MAIN'], $tables);
    }

    #[Test]
    public function it_extracts_tables_from_with_recursive_query(): void {
        $sql = <<<'SQL'
WITH RECURSIVE
  chain AS (
    SELECT ac.c_addr_id, ac.c_name_chn, ac.c_admin_cat_code, ac.c_firstyear, ac.c_lastyear,
           abd.c_belongs_to, 1 AS lvl
    FROM ADDR_CODES ac
      LEFT JOIN ADDR_BELONGS_DATA abd ON abd.c_addr_id = ac.c_addr_id
    WHERE ac.c_name_chn LIKE '%辦事大臣%'
    UNION ALL
    SELECT ac2.c_addr_id, ac2.c_name_chn, ac2.c_admin_cat_code, ac2.c_firstyear, ac2.c_lastyear,
           abd2.c_belongs_to, chain.lvl + 1
    FROM chain
      JOIN ADDR_CODES ac2 ON ac2.c_addr_id = chain.c_belongs_to
      LEFT JOIN ADDR_BELONGS_DATA abd2 ON abd2.c_addr_id = ac2.c_addr_id
    WHERE chain.c_belongs_to IS NOT NULL AND chain.lvl < 8
  )
SELECT * FROM chain ORDER BY c_addr_id, lvl
SQL;

        $tables = $this->extractor->extractTableNames($sql);

        $this->assertEqualsCanonicalizing(['ADDR_CODES', 'ADDR_BELONGS_DATA'], $tables);
    }

    #[Test]
    public function it_extracts_tables_ignoring_table_aliases(): void {
        $tables = $this->extractor->extractTableNames(
            'SELECT d.c_dy, b.c_personid FROM DYNASTIES d JOIN BIOG_MAIN b ON 1 = 1'
        );

        $this->assertEqualsCanonicalizing(['DYNASTIES', 'BIOG_MAIN'], $tables);
    }

    #[Test]
    public function it_extracts_tables_from_subquery_with_aliases(): void {
        $tables = $this->extractor->extractTableNames(
            'SELECT d.* FROM DYNASTIES d JOIN (SELECT c_personid FROM BIOG_MAIN GROUP BY c_personid) t ON 1 = 1'
        );

        $this->assertEqualsCanonicalizing(['DYNASTIES', 'BIOG_MAIN'], $tables);
    }

    #[Test]
    public function it_extracts_tables_from_with_recursive_using_non_keyword_cte_name(): void {
        $tables = $this->extractor->extractTableNames(
            'WITH RECURSIVE cte AS (SELECT c_dy FROM DYNASTIES UNION ALL SELECT c_dy FROM cte) SELECT * FROM cte'
        );

        $this->assertSame(['DYNASTIES'], $tables);
    }

    #[Test]
    public function it_extracts_all_tables_from_comma_separated_from_clause_in_fallback_path(): void {
        $tables = $this->extractor->extractTableNames(
            'WITH x AS (SELECT 1) SELECT * FROM DYNASTIES, BIOG_MAIN'
        );

        $this->assertEqualsCanonicalizing(['DYNASTIES', 'BIOG_MAIN'], $tables);
    }

    #[Test]
    public function it_does_not_extract_table_names_from_string_literals(): void {
        $tables = $this->extractor->extractTableNames(
            "SELECT c_dy FROM DYNASTIES WHERE status = 'FROM BIOG_MAIN WHERE 1=1'"
        );

        $this->assertSame(['DYNASTIES'], $tables);
    }

    #[Test]
    public function it_does_not_extract_table_names_from_block_comments(): void {
        $tables = $this->extractor->extractTableNames(
            'SELECT c_dy /* FROM BIOG_MAIN */ FROM DYNASTIES'
        );

        $this->assertSame(['DYNASTIES'], $tables);
    }

    #[Test]
    public function it_does_not_extract_table_names_from_line_comments(): void {
        $tables = $this->extractor->extractTableNames(
            "SELECT c_dy FROM DYNASTIES -- FROM BIOG_MAIN\nWHERE 1 = 1"
        );

        $this->assertSame(['DYNASTIES'], $tables);
    }

    #[Test]
    public function it_handles_escaped_quotes_in_string_literals(): void {
        $tables = $this->extractor->extractTableNames(
            "SELECT c_dy FROM DYNASTIES WHERE status = 'it''s FROM BIOG_MAIN'"
        );

        $this->assertSame(['DYNASTIES'], $tables);
    }
}
