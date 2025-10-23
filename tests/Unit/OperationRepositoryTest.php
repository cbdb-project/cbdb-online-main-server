<?php

namespace Tests\Unit;

use App\Repositories\OperationRepository;
use Tests\TestCase;

class OperationRepositoryTest extends TestCase
{
    /** @var OperationRepository */
    private $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new OperationRepository();
    }

    public function testGetArrDiffReturnsNullWhenAfterDataIsNotArray()
    {
        $result = $this->repository->getArrDiff('not-an-array', ['field' => 'value'], []);
        $this->assertNull($result);
    }

    public function testGetArrDiffTreatsEquivalentNumericStringsAsEqual()
    {
        $after = ['c_fy_nh_code' => '652', 'c_dy' => '19'];
        $before = ['c_fy_nh_code' => 652, 'c_dy' => 19];

        $result = $this->repository->getArrDiff($after, $before, []);

        $this->assertNull($result, 'Type-only differences should be ignored.');
    }

    public function testGetArrDiffReportsDifferencesWithCurrentMatchFlags()
    {
        $after = ['field' => 'new'];
        $before = ['field' => 'old'];
        $current = ['field' => 'new'];

        $diff = $this->repository->getArrDiff($after, $before, $current);

        $this->assertNotNull($diff);
        $this->assertCount(1, $diff['rows']);

        $row = $diff['rows'][0];
        $this->assertSame('field', $row['field']);
        $this->assertSame('old', $row['before']);
        $this->assertSame('new', $row['after']);
        $this->assertSame('new', $row['current']);
        $this->assertTrue($row['matches_current']);
        $this->assertFalse($row['matches_before']);
    }

    public function testGetArrDiffMarksWhenCurrentMatchesBefore()
    {
        $after = ['field' => 'new'];
        $before = ['field' => 'old'];
        $current = ['field' => 'old'];

        $diff = $this->repository->getArrDiff($after, $before, $current);
        $row = $diff['rows'][0];

        $this->assertFalse($row['matches_current']);
        $this->assertTrue($row['matches_before']);
    }

    public function testGetArrDiffUsesPlaceholderWhenCurrentIsMissing()
    {
        $after = ['field' => 'value'];
        $before = ['field' => null];
        $current = [];

        $diff = $this->repository->getArrDiff($after, $before, $current);
        $row = $diff['rows'][0];

        $this->assertSame('(null)', $row['before']);
        $this->assertSame('value', $row['after']);
        $this->assertSame('(未取得)', $row['current']);
    }

    public function testGetArrDiffIgnoresTokenFields()
    {
        $after = ['name' => 'after', '_token' => 'new-token'];
        $before = ['name' => 'before', '_token' => 'old-token'];

        $diff = $this->repository->getArrDiff($after, $before, []);

        $this->assertCount(1, $diff['rows']);
        $this->assertSame('name', $diff['rows'][0]['field']);
    }

    public function testGetArrDiffFormatsNestedStructuresAsJson()
    {
        $after = ['payload' => ['a' => 2]];
        $before = ['payload' => ['a' => 1]];
        $current = ['payload' => ['a' => 3]];

        $diff = $this->repository->getArrDiff($after, $before, $current);
        $row = $diff['rows'][0];

        $this->assertSame('{"a":1}', $row['before']);
        $this->assertSame('{"a":2}', $row['after']);
        $this->assertSame('{"a":3}', $row['current']);
    }
}
