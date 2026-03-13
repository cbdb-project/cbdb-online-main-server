<?php

namespace Tests\Unit\Services;

use App\Services\IndexYearRebuildService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class IndexYearRebuildServiceTest extends TestCase {
    #[Test]
    #[DataProvider('sourceIdRuleProvider')]
    public function test_aggregate_rules_write_index_year_source_id(
        string $method,
        array $arguments = []
    ): void {
        $service = new IndexYearRebuildService();
        $reflection = new ReflectionClass($service);
        $methodReflection = $reflection->getMethod($method);
        $methodReflection->setAccessible(true);
        $sql = $methodReflection->invoke($service, ...$arguments);

        $this->assertStringContainsString('c_index_year_source_id = agg.source_personid', $sql);
    }

    #[Test]
    public function test_aggregate_source_subquery_uses_stable_tie_breaker(): void {
        $service = new IndexYearRebuildService();
        $reflection = new ReflectionClass($service);
        $methodReflection = $reflection->getMethod('sqlAggregateRule13');
        $methodReflection->setAccessible(true);
        $sql = $methodReflection->invoke($service);

        $this->assertStringContainsString('MIN(chosen.source_personid) AS source_personid', $sql);
    }

    public static function sourceIdRuleProvider(): array {
        return [
            'rule 13' => ['sqlAggregateRule13'],
            'rule 15' => ['sqlAggregateRule15'],
            'rule 19' => ['sqlAggregateSiblingRule', [[125, 165], 'MAX', 2, '19']],
            'rule 21' => ['sqlAggregateSiblingRule', [[126, 166], 'MIN', -2, '21']],
            'rule 23' => ['sqlAggregateSonInLawRule', [false, 27, '23']],
            'rule 25' => ['sqlAggregateSonInLawRule', [true, 24, '25']],
            'rule 14' => ['sqlLoopOldestChildIndexToFatherRule'],
            'rule 16' => ['sqlLoopOldestChildIndexToMotherRule'],
            'rule 20' => ['sqlLoopSiblingRule', [[125, 165], 'MAX', 2, '20']],
            'rule 22' => ['sqlLoopSiblingRule', [[126, 166], 'MIN', -2, '22']],
            'rule 24' => ['sqlLoopSonInLawRule', [false, 27, '24']],
            'rule 26' => ['sqlLoopSonInLawRule', [true, 24, '26']],
        ];
    }
}
