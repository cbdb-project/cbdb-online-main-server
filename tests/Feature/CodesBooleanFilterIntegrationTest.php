<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end regression for boolean column filtering through CodesController::show()
 * on a REAL (in-memory) SQLite connection — NOT the fake-DB harness used by
 * CodesControllerTest. This locks that the controller wires the parsed AST
 * (grouped / NOT / NULL-safe) into actual SQL correctly, complementing the parser-only
 * coverage in ColumnFilterExpressionTest. See docs/CODES_BOOLEAN_FILTER_DESIGN.md §9.3.
 */
class CodesBooleanFilterIntegrationTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        // Real default test connection is sqlite :memory: (phpunit.xml). Do NOT swap in a fake DB.
        config(['codes.tables' => ['cbf_places' => 'Test places']]);
        config(['codes.connection' => null]);
        config(['codes.ui_hidden' => []]);

        $compiledPath = base_path('tests/storage/views');
        if (!is_dir($compiledPath)) {
            mkdir($compiledPath, 0777, true);
        }
        config(['view.compiled' => $compiledPath]);

        Schema::dropIfExists('cbf_places');
        Schema::create('cbf_places', function ($table): void {
            $table->integer('id')->primary();
            $table->string('name')->nullable();
        });

        DB::table('cbf_places')->insert([
            ['id' => 1, 'name' => '黃州'],
            ['id' => 2, 'name' => '隨州'],
            ['id' => 3, 'name' => '黃州隨州'],
            ['id' => 4, 'name' => null],
            ['id' => 5, 'name' => '京兆府'],
        ]);
    }

    protected function tearDown(): void {
        Schema::dropIfExists('cbf_places');
        parent::tearDown();
    }

    /**
     * Run a boolean filter through the real route and return the matched ids (sorted).
     *
     * @return list<int>
     */
    private function ids(string $expression): array {
        $response = $this->get('/codes/cbf_places?filter_bool=1&filters[name]=' . urlencode($expression));
        $response->assertStatus(200);

        return collect($response->viewData('data')->items())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    #[Test]
    public function testOrFilterEndToEnd() {
        $this->assertSame([1, 2, 3], $this->ids('黃州 OR 隨州'));
    }

    #[Test]
    public function testNullSafeNotEndToEnd() {
        // NOT 黃州 — excludes rows containing 黃州 (id 1, 3); the NULL row (id 4) must be kept.
        $result = $this->ids('NOT 黃州');
        $this->assertSame([2, 4, 5], $result);
        $this->assertContains(4, $result, 'NULL row must not be excluded by NOT');
    }

    #[Test]
    public function testGroupedAndEndToEnd() {
        // (黃州 OR 京兆) AND 府 — only 京兆府 (id 5) has both a group match and 府.
        $this->assertSame([5], $this->ids('(黃州 | 京兆) AND 府'));
    }

    #[Test]
    public function testGroupNegationDeMorganEndToEnd() {
        // NOT (黃州 OR 京兆) = NOT 黃州 AND NOT 京兆, NULL-safe — keep 隨州 (2) and NULL (4).
        $this->assertSame([2, 4], $this->ids('NOT (黃州 OR 京兆)'));
    }

    #[Test]
    public function testQuotedLiteralWithParensEndToEnd() {
        DB::table('cbf_places')->insert([['id' => 6, 'name' => '宋代節度(地區未詳)']]);

        // Quoted literal containing parens matches only the literal row; parens are not a group.
        $this->assertSame([6], $this->ids('"宋代節度(地區未詳)"'));
    }
}
