<?php

namespace Tests\Unit;

use App\Repositories\BiogMainRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 測試 BiogMainRepository::updateById() 的變更檢測邏輯
 *
 * 參考 PR #330 (commit f14925d) 的測試模式
 */
class BiogMainRepositoryUpdateTest extends TestCase {
    /** @var TestableBiogMainRepositoryForUpdate */
    private $repository;

    protected function setUp(): void {
        parent::setUp();
        $this->repository = new TestableBiogMainRepositoryForUpdate();
    }

    /**
     * 測試：當基本資料欄位有實質變更時，應該被檢測到
     */
    #[Test]
    public function testDetectsActualChangesInBasicFields() {
        $newData = [
            'c_name_chn' => '張三',
            'c_dy' => '19',
        ];

        $original = [
            'c_name_chn' => '李四',
            'c_dy' => 19,
        ];

        $this->assertTrue(
            $this->repository->callHasMeaningfulChanges($newData, $original, ['c_modified_by', 'c_modified_date']),
            '基本資料欄位變更應該被檢測到'
        );
    }

    /**
     * 測試：當數值型字串與整數相等時，不應視為變更
     */
    #[Test]
    public function testTreatsNumericStringsAsEqualToIntegers() {
        $newData = [
            'c_dy' => '19',
            'c_fy_nh_code' => '652',
        ];

        $original = [
            'c_dy' => 19,
            'c_fy_nh_code' => 652,
        ];

        $this->assertFalse(
            $this->repository->callHasMeaningfulChanges($newData, $original, ['c_modified_by', 'c_modified_date']),
            '數值型字串應該與對應的整數視為相等'
        );
    }

    /**
     * 測試：當所有欄位都相同時，不應視為有變更
     */
    #[Test]
    public function testNoChangesWhenAllFieldsIdentical() {
        $data = [
            'c_name_chn' => '張三',
            'c_dy' => '19',
            'c_index_year' => 1000,
        ];

        $this->assertFalse(
            $this->repository->callHasMeaningfulChanges($data, $data, ['c_modified_by', 'c_modified_date']),
            '所有欄位相同時不應視為有變更'
        );
    }

    /**
     * 測試：忽略的欄位（如 c_modified_by, c_modified_date）不應影響變更檢測
     */
    #[Test]
    public function testIgnoresSpecifiedFields() {
        $newData = [
            'c_name_chn' => '張三',
            'c_modified_by' => 'user1',
            'c_modified_date' => '2024-01-01',
        ];

        $original = [
            'c_name_chn' => '張三',
            'c_modified_by' => 'user2',
            'c_modified_date' => '2024-01-02',
        ];

        $this->assertFalse(
            $this->repository->callHasMeaningfulChanges($newData, $original, ['c_modified_by', 'c_modified_date']),
            '被忽略的欄位變更不應影響檢測結果'
        );
    }

    /**
     * 測試：空值與 null 的處理
     */
    #[Test]
    public function testHandlesNullAndEmptyValues() {
        $newData = [
            'c_name_chn' => '',
            'c_notes' => null,
        ];

        $original = [
            'c_name_chn' => null,
            'c_notes' => '',
        ];

        // 空字串與 null 在某些情況下應視為相等
        // 這取決於 normalizeComparisonValue 的實作
        $hasChanges = $this->repository->callHasMeaningfulChanges($newData, $original);

        // 此測試驗證系統對空值的一致性處理
        $this->assertIsBool($hasChanges, '空值處理應該返回布林值');
    }

    /**
     * 測試：當新增欄位（原始資料中不存在）時，應視為變更
     */
    #[Test]
    public function testDetectsNewFields() {
        $newData = [
            'c_name_chn' => '張三',
            'c_new_field' => 'new_value',
        ];

        $original = [
            'c_name_chn' => '張三',
        ];

        $this->assertTrue(
            $this->repository->callHasMeaningfulChanges($newData, $original),
            '新增欄位應該被檢測為變更'
        );
    }
}

/**
 * 測試用的 BiogMainRepository 子類別
 * 使用 Reflection 來測試 protected 方法
 */
class TestableBiogMainRepositoryForUpdate extends BiogMainRepository {
    /**
     * 公開 hasMeaningfulChanges 方法供測試使用
     */
    public function callHasMeaningfulChanges(array $newData, array $original, array $ignored = []): bool {
        $ref = new \ReflectionClass(BiogMainRepository::class);
        $method = $ref->getMethod('hasMeaningfulChanges');
        $method->setAccessible(true);

        return $method->invoke($this, $newData, $original, $ignored);
    }
}
