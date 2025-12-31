<?php

namespace Tests\Unit;

use App\Repositories\OperationRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OperationRepositoryTest extends TestCase {
    /** @var OperationRepository */
    private $repository;

    protected function setUp(): void {
        parent::setUp();
        $this->repository = new OperationRepository();
    }

    #[Test]
    public function testGetArrDiffReturnsNullWhenAfterDataIsNotArray() {
        $result = $this->repository->getArrDiff('not-an-array', ['field' => 'value'], []);
        $this->assertNull($result);
    }

    #[Test]
    public function testGetArrDiffTreatsEquivalentNumericStringsAsEqual() {
        $after = ['c_fy_nh_code' => '652', 'c_dy' => '19'];
        $before = ['c_fy_nh_code' => 652, 'c_dy' => 19];

        $result = $this->repository->getArrDiff($after, $before, []);

        $this->assertNull($result, 'Type-only differences should be ignored.');
    }

    #[Test]
    public function testGetArrDiffReportsDifferencesWithCurrentMatchFlags() {
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

    #[Test]
    public function testGetArrDiffMarksWhenCurrentMatchesBefore() {
        $after = ['field' => 'new'];
        $before = ['field' => 'old'];
        $current = ['field' => 'old'];

        $diff = $this->repository->getArrDiff($after, $before, $current);
        $row = $diff['rows'][0];

        $this->assertFalse($row['matches_current']);
        $this->assertTrue($row['matches_before']);
    }

    #[Test]
    public function testGetArrDiffUsesPlaceholderWhenCurrentIsMissing() {
        $after = ['field' => 'value'];
        $before = ['field' => null];
        $current = [];

        $diff = $this->repository->getArrDiff($after, $before, $current);
        $row = $diff['rows'][0];

        $this->assertSame('(null)', $row['before']);
        $this->assertSame('value', $row['after']);
        $this->assertSame('(未取得)', $row['current']);
    }

    #[Test]
    public function testGetArrDiffIgnoresTokenFields() {
        $after = ['name' => 'after', '_token' => 'new-token'];
        $before = ['name' => 'before', '_token' => 'old-token'];

        $diff = $this->repository->getArrDiff($after, $before, []);

        $this->assertCount(1, $diff['rows']);
        $this->assertSame('name', $diff['rows'][0]['field']);
    }

    #[Test]
    public function testGetArrDiffFormatsNestedStructuresAsJson() {
        $after = ['payload' => ['a' => 2]];
        $before = ['payload' => ['a' => 1]];
        $current = ['payload' => ['a' => 3]];

        $diff = $this->repository->getArrDiff($after, $before, $current);
        $row = $diff['rows'][0];

        $this->assertSame('{"a":1}', $row['before']);
        $this->assertSame('{"a":2}', $row['after']);
        $this->assertSame('{"a":3}', $row['current']);
    }

    #[Test]
    public function testBuildPostedToAddrDiffProvidesKeyMetadataAndAddressMatrix() {
        $after = [
            ['c_personid' => 1, 'c_posting_id' => 10, 'c_office_id' => 20, 'c_addr_id' => 100],
            ['c_personid' => 1, 'c_posting_id' => 10, 'c_office_id' => 20, 'c_addr_id' => 200],
        ];
        $before = [
            ['c_personid' => 1, 'c_posting_id' => 10, 'c_office_id' => 15, 'c_addr_id' => 200],
            ['c_personid' => 1, 'c_posting_id' => 10, 'c_office_id' => 15, 'c_addr_id' => 300],
        ];
        $current = [
            ['c_personid' => 1, 'c_posting_id' => 10, 'c_office_id' => 20, 'c_addr_id' => 100],
            ['c_personid' => 1, 'c_posting_id' => 10, 'c_office_id' => 20, 'c_addr_id' => 400],
        ];

        $diff = $this->repository->buildPostedToAddrDiff($after, $before, $current);

        $this->assertNotNull($diff);
        $this->assertSame('POSTED_TO_ADDR_DATA', $diff['type']);

        $this->assertSame(
            ['c_personid' => 1, 'c_office_id' => 20, 'c_posting_id' => 10],
            $diff['keys']['after']
        );
        $this->assertSame(
            ['c_personid' => 1, 'c_office_id' => 15, 'c_posting_id' => 10],
            $diff['keys']['before']
        );
        $this->assertSame(
            ['c_personid' => 1, 'c_office_id' => 20, 'c_posting_id' => 10],
            $diff['keys']['current']
        );

        $this->assertCount(4, $diff['addresses']);

        $first = $diff['addresses'][0];
        $this->assertSame(100, $first['id']);
        $this->assertNull($first['before']);
        $this->assertNotNull($first['after']);
        $this->assertNotNull($first['current']);

        $second = $diff['addresses'][1];
        $this->assertSame(200, $second['id']);
        $this->assertNotNull($second['before']);
        $this->assertNotNull($second['after']);
        $this->assertNull($second['current']);
    }
}
