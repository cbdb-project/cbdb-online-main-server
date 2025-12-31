<?php

namespace Tests\Unit;

use App\Http\Controllers\MergePreviewController;
use Carbon\Carbon;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MergePreviewControllerTest extends TestCase {
    /** @var TestableMergePreviewController */
    private $controller;

    protected function setUp(): void {
        parent::setUp();

        $this->controller = new TestableMergePreviewController();
        Carbon::setTestNow(Carbon::create(2025, 1, 2, 3, 4, 5));
    }

    protected function tearDown(): void {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function testCalculateMergedPersonCombinesAttributesAndNotes() {
        $user = new GenericUser(['id' => 999, 'name' => 'MergeAdmin']);
        Auth::guard()->setUser($user);

        $primary = [
            'exists' => true,
            'id' => 123,
            'attributes' => [
                'c_personid' => 123,
                'c_name' => 'Primary Name',
                'c_name_chn' => '主名',
                'c_notes' => "Primary note",
                'c_status' => 'Active',
            ],
        ];
        $secondary = [
            'exists' => true,
            'id' => 456,
            'attributes' => [
                'c_personid' => 456,
                'c_name' => 'Secondary Name',
                'c_name_chn' => '次名',
                'c_notes' => "Secondary note",
                'c_status' => 'Inactive',
            ],
        ];

        $result = $this->controller->callCalculateMergedPerson($primary, $secondary, 'duplicate record');

        $values = $result['values'];
        $updates = $result['updates'];

        $this->assertSame(123, $values['c_personid']);
        $this->assertSame('Primary Name', $values['c_name'], 'Primary values should override secondary');
        $this->assertSame('Active', $values['c_status']);
        $this->assertSame('MergeAdmin', $values['c_modified_by']);
        $this->assertSame('20250102', $values['c_modified_date']);
        $this->assertStringContainsString('Primary note', $values['c_notes']);
        $this->assertStringContainsString('Secondary note', $values['c_notes']);
        $this->assertStringContainsString('[merged #123 and #456 on 20250102 with reason] duplicate record', $values['c_notes']);

        $this->assertArrayHasKey('c_notes', $updates);
        $this->assertArrayHasKey('c_modified_by', $updates);
        $this->assertArrayHasKey('c_modified_date', $updates);
        $this->assertArrayNotHasKey('c_personid', $updates);
    }

    #[Test]
    public function testCalculateMergedPersonWithoutSecondaryReturnsEmpty() {
        $primary = ['exists' => true, 'attributes' => []];
        $secondary = ['exists' => false];

        $result = $this->controller->callCalculateMergedPerson($primary, $secondary, '');

        $this->assertSame([], $result['values']);
        $this->assertSame([], $result['updates']);
    }

    #[Test]
    public function testShouldBlockMergeDetectsNameDifference() {
        $primary = ['exists' => true, 'name' => 'Zhang San', 'name_chn' => '張三'];
        $secondary = ['exists' => true, 'name' => 'Li Si', 'name_chn' => '李四'];

        $this->assertTrue($this->controller->callShouldBlockMerge($primary, $secondary));

        $secondary['name'] = 'zhang san';
        $secondary['name_chn'] = '張三';
        $this->assertFalse($this->controller->callShouldBlockMerge($primary, $secondary));
    }

    #[Test]
    public function testBuildSqlPreviewIncludesAutoArrangeStatements() {
        $result = [
            'values' => ['c_name' => 'Merged'],
            'updates' => ['c_name' => 'Merged'],
            'merge_record' => null,
        ];
        $statements = $this->controller->callBuildSqlPreview(200, 100, $result, true, 100);

        $this->assertSame('START TRANSACTION;', $statements[0]);

        $hasMainUpdate = false;
        foreach ($statements as $statement) {
            if (strpos($statement, "UPDATE BIOG_MAIN SET c_name = 'Merged' WHERE c_personid = 200;") !== false) {
                $hasMainUpdate = true;

                break;
            }
        }
        $this->assertTrue($hasMainUpdate, '應該要更新保留人物的 BIOG_MAIN 欄位。');

        $sql = implode("\n", $statements);
        $this->assertStringContainsString('-- 調整至較小 ID 100', $sql, 'Auto arrange block should suggest moving to min ID');

        $hasMinUpdate = false;
        foreach ($statements as $statement) {
            if (strpos($statement, "UPDATE BIOG_MAIN SET c_personid = 100 WHERE c_personid = 200;") !== false) {
                $hasMinUpdate = true;

                break;
            }
        }
        $this->assertTrue($hasMinUpdate, 'Auto arrange 段落應該將人物 ID 調整為較小值。');
    }

    #[Test]
    public function testBuildSqlPreviewSkipsAutoArrangeWhenDisabled() {
        $statements = $this->controller->callBuildSqlPreview(300, 200, ['values' => [], 'updates' => [], 'merge_record' => null], false, null);

        $this->assertNotContains('-- 調整至較小 ID', $statements);
        $this->assertSame('START TRANSACTION;', $statements[0]);
        $this->assertSame('COMMIT;', $statements[count($statements) - 1]);
    }
}

class TestableMergePreviewController extends MergePreviewController {
    public function callCalculateMergedPerson(array $primary, array $secondary, $reason) {
        return $this->calculateMergedPerson($primary, $secondary, $reason);
    }

    public function callShouldBlockMerge(array $primary, array $secondary) {
        return $this->shouldBlockMerge($primary, $secondary);
    }

    public function callBuildSqlPreview($primary, $secondary, array $updates, $autoArrange, $minTargetId) {
        return $this->buildSqlPreview($primary, $secondary, $updates, $autoArrange, $minTargetId);
    }
}
