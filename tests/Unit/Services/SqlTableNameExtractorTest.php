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
}
