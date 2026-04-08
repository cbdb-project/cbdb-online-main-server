<?php

namespace Tests\Unit;

use App\Repositories\BiogMainRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BiogMainRepositoryTest extends TestCase {
    /** @var TestableBiogMainRepository */
    private $repository;

    protected function setUp(): void {
        parent::setUp();
        $this->repository = new TestableBiogMainRepository();
    }

    #[Test]
    public function testHasMeaningfulChangesTreatsNumericStringsAsEqual() {
        $newData = ['c_fy_nh_code' => '652', 'c_dy' => '19'];
        $original = ['c_fy_nh_code' => 652, 'c_dy' => 19];

        $this->assertFalse(
            $this->repository->callHasMeaningfulChanges($newData, $original, ['c_modified_by', 'c_modified_date']),
            'Numeric strings should be treated as equal to their integer counterparts.'
        );
    }

    #[Test]
    public function testHasMeaningfulChangesDetectsActualDifferences() {
        $newData = ['c_dy' => '20'];
        $original = ['c_dy' => 19];

        $this->assertTrue(
            $this->repository->callHasMeaningfulChanges($newData, $original),
            'Changed values must be reported as differences.'
        );
    }

    #[Test]
    public function testHasMeaningfulChangesTreatsMissingOriginalAsChange() {
        $newData = ['c_sequence' => '1'];
        $original = [];

        $this->assertTrue($this->repository->callHasMeaningfulChanges($newData, $original));
    }

    #[Test]
    public function testNullifyEmptyForeignKeysConvertsEmptyStringsToNull() {
        $data = [
            'c_death_age_range' => '',
            'c_by_range' => '',
            'c_dy_range' => 'null',
            'c_dy' => '',
            'c_birthyear' => '',
            'c_name_chn' => '',
        ];

        $result = BiogMainRepository::nullifyEmptyForeignKeys($data);

        $this->assertNull($result['c_death_age_range']);
        $this->assertNull($result['c_by_range']);
        $this->assertNull($result['c_dy_range']);
        $this->assertNull($result['c_dy']);
        // 非 FK 欄位不受影響
        $this->assertSame('', $result['c_birthyear']);
        $this->assertSame('', $result['c_name_chn']);
    }

    #[Test]
    public function testNullifyEmptyForeignKeysPreservesValidValues() {
        $data = [
            'c_death_age_range' => '-1',
            'c_by_range' => '3',
            'c_dy' => '15',
        ];

        $result = BiogMainRepository::nullifyEmptyForeignKeys($data);

        $this->assertSame('-1', $result['c_death_age_range']);
        $this->assertSame('3', $result['c_by_range']);
        $this->assertSame('15', $result['c_dy']);
    }

    #[Test]
    public function testNormalizeSelectionListHandlesMinus999AndSorts() {
        $input = ['-999', 123, '456', 123];
        $expected = ['0', '123', '456'];

        $this->assertSame($expected, $this->repository->callNormalizeSelectionList($input, -999));
    }

    #[Test]
    public function testNormalizeSelectionListIgnoresEmptyValues() {
        $input = ['', null, '-999'];
        $expected = ['0'];

        $this->assertSame($expected, $this->repository->callNormalizeSelectionList($input, -999));
    }

    #[Test]
    public function testSelectionListHasChangesDetectsDifferences() {
        $this->assertTrue(
            $this->repository->callSelectionListHasChanges([1, 2], [1], null)
        );

        $this->assertFalse(
            $this->repository->callSelectionListHasChanges(['-999', 5], [0, 5], -999)
        );
    }
}

class TestableBiogMainRepository extends BiogMainRepository {
    public function callHasMeaningfulChanges(array $newData, array $original, array $ignored = []): bool {
        $ref = new \ReflectionClass(BiogMainRepository::class);
        $method = $ref->getMethod('hasMeaningfulChanges');
        $method->setAccessible(true);

        return $method->invoke($this, $newData, $original, $ignored);
    }

    public function callNormalizeSelectionList($values, $nullToken): array {
        $ref = new \ReflectionClass(BiogMainRepository::class);
        $method = $ref->getMethod('normalizeSelectionList');
        $method->setAccessible(true);

        return $method->invoke($this, $values, $nullToken);
    }

    public function callSelectionListHasChanges($incoming, $existing, $nullToken): bool {
        $ref = new \ReflectionClass(BiogMainRepository::class);
        $method = $ref->getMethod('selectionListHasChanges');
        $method->setAccessible(true);

        return $method->invoke($this, $incoming, $existing, $nullToken);
    }
}
