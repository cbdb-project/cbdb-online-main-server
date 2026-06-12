<?php

namespace Tests\Feature;

use App\Support\ColumnFilterExpression;
use App\Support\ColumnFilterParseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exercises the parser AND the generated SQL on a real (in-memory) SQLite connection,
 * so De Morgan push-down, group boundaries and NULL-safe NOT are validated against an
 * actual database — not a fake builder. See docs/CODES_BOOLEAN_FILTER_DESIGN.md §9.3.
 */
class ColumnFilterExpressionTest extends TestCase {
    private ColumnFilterExpression $expr;

    protected function setUp(): void {
        parent::setUp();

        $this->expr = new ColumnFilterExpression();

        Schema::dropIfExists('cfe_rows');
        Schema::create('cfe_rows', function ($table): void {
            $table->integer('id')->primary();
            $table->string('name')->nullable();
        });

        DB::table('cfe_rows')->insert([
            ['id' => 1, 'name' => 'alpha beta'],
            ['id' => 2, 'name' => 'alpha'],
            ['id' => 3, 'name' => 'beta'],
            ['id' => 4, 'name' => null],
            ['id' => 5, 'name' => 'gamma'],
            ['id' => 6, 'name' => 'R&D'],
            ['id' => 7, 'name' => '1023-1025'],
            ['id' => 8, 'name' => '黃州'],
            ['id' => 9, 'name' => '隨州'],
            ['id' => 10, 'name' => 'a!b'],
        ]);
    }

    protected function tearDown(): void {
        Schema::dropIfExists('cfe_rows');
        parent::tearDown();
    }

    /**
     * @return list<int> matching ids, sorted
     */
    private function match(string $expression): array {
        $ast = $this->expr->parse($expression);
        $query = DB::table('cfe_rows');
        $this->expr->applyToBuilder($query, 'name', $ast);

        return $query->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function assertParseError(string $expression, string $expectedCode): void {
        try {
            $this->expr->parse($expression);
            $this->fail("Expected parse to throw for: {$expression}");
        } catch (ColumnFilterParseException $e) {
            $this->assertSame($expectedCode, $e->errorCode, "wrong error code for: {$expression}");
        }
    }

    // --- AST shape -------------------------------------------------------

    #[Test]
    public function testWhitespaceStaysLiteralSingleTerm() {
        $this->assertSame(['type' => 'term', 'value' => 'alpha beta'], $this->expr->parse('alpha beta'));
    }

    #[Test]
    public function testPrecedenceAndBindsTighterThanOr() {
        $this->assertSame([
            'type' => 'or',
            'children' => [
                ['type' => 'and', 'children' => [
                    ['type' => 'term', 'value' => 'alpha'],
                    ['type' => 'term', 'value' => 'beta'],
                ]],
                ['type' => 'term', 'value' => 'gamma'],
            ],
        ], $this->expr->parse('alpha AND beta OR gamma'));
    }

    #[Test]
    public function testSymbolsEqualKeywords() {
        $this->assertSame($this->expr->parse('alpha AND beta'), $this->expr->parse('alpha & beta'));
        $this->assertSame($this->expr->parse('alpha OR beta'), $this->expr->parse('alpha | beta'));
    }

    // --- evaluation against real SQLite ---------------------------------

    #[Test]
    public function testAndOrLiteral() {
        $this->assertSame([1], $this->match('alpha AND beta'));
        $this->assertSame([1, 2, 3], $this->match('alpha OR beta'));
        $this->assertSame([1, 2], $this->match('alpha'));
    }

    #[Test]
    public function testNotIsNullSafe() {
        // rows NOT containing 'alpha' — including the NULL row (id 4) which must NOT be excluded.
        $result = $this->match('NOT alpha');
        $this->assertContains(4, $result);
        $this->assertNotContains(1, $result);
        $this->assertNotContains(2, $result);
        $this->assertSame([3, 4, 5, 6, 7, 8, 9, 10], $result);
        // `!` shorthand identical
        $this->assertSame($result, $this->match('!alpha'));
    }

    #[Test]
    public function testGroupNegationDeMorgan() {
        // NOT (alpha OR gamma) = NOT alpha AND NOT gamma, NULL row kept
        $this->assertSame([3, 4, 6, 7, 8, 9, 10], $this->match('NOT (alpha OR gamma)'));
        // NOT (alpha AND beta) = NOT alpha OR NOT beta — excludes only id 1; NULL kept
        $this->assertSame([2, 3, 4, 5, 6, 7, 8, 9, 10], $this->match('NOT (alpha AND beta)'));
    }

    #[Test]
    public function testNestedGroupsKeepBoundaries() {
        // (alpha OR gamma) AND beta — only id 1 has beta together with alpha/gamma
        $this->assertSame([1], $this->match('(alpha OR gamma) AND beta'));
    }

    #[Test]
    public function testTwoOrGroupsAndedKeepBoundaries() {
        // §6 必測 golden：(a|b) AND (c|d) 兩組 OR 群組
        // row1 'alpha beta'：(alpha) AND (beta) → 命中；row5 'gamma'：(gamma) AND (gamma) → 命中
        $this->assertSame([1, 5], $this->match('(alpha OR gamma) AND (beta OR gamma)'));
    }

    #[Test]
    public function testNegatedGroupWithInnerNot() {
        // §6 必測 golden：NOT(NOT a OR b) = a AND NOT b（De Morgan + 群組內雙重否定）
        // 僅 row2 'alpha'（含 alpha、不含 beta）命中
        $this->assertSame([2], $this->match('NOT (NOT alpha OR beta)'));
    }

    #[Test]
    public function testDoubleNegationSimplifies() {
        $this->assertSame($this->match('alpha'), $this->match('NOT NOT alpha'));
        // 符號式雙重否定 !!x 與關鍵字式一致化簡為 x
        $this->assertSame($this->match('alpha'), $this->match('!!alpha'));
    }

    #[Test]
    public function testBangAfterConnectiveIsNotRegardlessOfSpace() {
        // & 與 AND 連接子後緊貼 ! 行為一致（皆視為 NOT）
        $this->assertSame($this->match('alpha & !beta'), $this->match('alpha&!beta'));
        $this->assertSame($this->match('alpha AND !beta'), $this->match('alpha AND!beta'));
        // 且四種寫法等價
        $this->assertSame($this->match('alpha AND !beta'), $this->match('alpha&!beta'));
    }

    #[Test]
    public function testQuotedLiteralIgnoresOperators() {
        $this->assertSame([1], $this->match('"alpha beta"'));
        // & inside quotes is literal
        $this->assertSame([6], $this->match('"R&D"'));
        // unquoted R&D splits into R AND D
        $this->assertSame([6], $this->match('R&D'));

        // 引號保護含括號的真實宋代條目；不加引號時 ( 會被當群組運算子 → 語法錯誤，
        // 證明此處引號「非冗餘」（對應 chip 'NOT "宋代節度(地區未詳)"' 的教學意義）。
        $this->assertSame(
            ['type' => 'term', 'value' => '宋代節度(地區未詳)'],
            $this->expr->parse('"宋代節度(地區未詳)"')
        );
        $this->assertParseError('宋代節度(地區未詳)', 'adjacent_operand');
    }

    #[Test]
    public function testFullWidthOperatorsNormalized() {
        $this->assertSame($this->match('(alpha OR gamma)'), $this->match('（alpha ｜ gamma）'));
    }

    #[Test]
    public function testCjkKeywordNeedsSpaceOtherwiseLiteral() {
        // spaced OR works across CJK terms
        $this->assertSame([8, 9], $this->match('黃州 OR 隨州'));
        // glued keyword stays literal -> matches nothing (no row contains the literal string)
        $this->assertSame([], $this->match('黃州OR隨州'));
    }

    #[Test]
    public function testHyphenAndNumbersAreLiteral() {
        $this->assertSame([7], $this->match('1023-1025'));
    }

    #[Test]
    public function testBangGluedIsLiteral() {
        // a!b is literal (no space before !), matches id 10
        $this->assertSame([10], $this->match('a!b'));
    }

    // --- parse errors ----------------------------------------------------

    #[Test]
    public function testAdjacentOperandIsError() {
        $this->assertParseError('alpha NOT beta', 'adjacent_operand');
        $this->assertParseError('alpha !beta', 'adjacent_operand');
    }

    #[Test]
    public function testDanglingOperatorIsError() {
        $this->assertParseError('alpha AND', 'dangling_operator');
        $this->assertParseError('NOT', 'dangling_operator');
    }

    #[Test]
    public function testUnbalancedAndEmptyParens() {
        $this->assertParseError('(alpha', 'unbalanced_paren');
        $this->assertParseError('alpha)', 'unbalanced_paren');
        $this->assertParseError('()', 'empty_group');
    }

    #[Test]
    public function testQuoteErrors() {
        $this->assertParseError('"abc', 'unterminated_quote');
        $this->assertParseError('""', 'empty_quote');
    }

    #[Test]
    public function testDescribeRendersHumanReadable() {
        $labels = ['contains' => 'has[:term]', 'not' => 'NOT ', 'and' => ' & ', 'or' => ' | '];

        $this->assertSame('has[alpha]', $this->expr->describe($this->expr->parse('alpha'), $labels));
        $this->assertSame('NOT has[alpha] & has[beta]', $this->expr->describe($this->expr->parse('!alpha AND beta'), $labels));
        $this->assertSame('NOT (has[a] | has[b])', $this->expr->describe($this->expr->parse('NOT (a OR b)'), $labels));
        $this->assertSame('(has[alpha] | has[gamma]) & has[beta]', $this->expr->describe($this->expr->parse('(alpha OR gamma) AND beta'), $labels));
        // 雙重否定描述須與查詢一致（NOT NOT x 等於 x），不可顯示「非非含」
        $this->assertSame('has[alpha]', $this->expr->describe($this->expr->parse('NOT NOT alpha'), $labels));
        $this->assertSame('NOT has[alpha]', $this->expr->describe($this->expr->parse('NOT NOT NOT alpha'), $labels));
    }

    #[Test]
    public function testEveryErrorCodeHasLocalizedMessageInBothLocales() {
        foreach (ColumnFilterExpression::ERROR_CODES as $code) {
            $key = 'codes.filter_err_' . $code;
            $this->assertTrue(
                trans()->hasForLocale($key, 'zh-TW'),
                "Missing zh-TW message for error code: {$code} ({$key})"
            );
            $this->assertTrue(
                trans()->hasForLocale($key, 'en'),
                "Missing en message for error code: {$code} ({$key})"
            );
        }
    }

    #[Test]
    public function testChipExamplesAllParseSuccessfully() {
        // 範例 chip 點擊後即送出，必須都是合法布林（中英皆是），否則使用者點了會看到語法錯誤。
        foreach (['zh-TW', 'en'] as $locale) {
            $examples = trans('codes.filter_chip_examples', [], $locale);
            $this->assertIsArray($examples, "chip examples missing for locale {$locale}");
            $this->assertNotEmpty($examples);
            foreach ($examples as $example) {
                try {
                    $this->expr->parse($example);
                } catch (ColumnFilterParseException $e) {
                    $this->fail("Chip example '{$example}' ({$locale}) failed to parse: {$e->errorCode}");
                }
            }
        }
    }

    #[Test]
    public function testLimits() {
        $this->assertParseError(str_repeat('a', ColumnFilterExpression::MAX_LENGTH + 1), 'too_long');
        $this->assertParseError('((((((alpha))))))', 'too_deep');
        // 40 terms + 39 OR = 79 tokens > 64
        $this->assertParseError(implode('|', array_fill(0, 40, 'a')), 'too_many_tokens');
        // whitespace-only → 無 token
        $this->assertParseError('   ', 'empty');
    }
}
