<?php

namespace Tests\Unit;

use App\Http\Controllers\MergePreviewController;
use Carbon\Carbon;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class MergePreviewControllerTest extends TestCase
{
    /** @var TestableMergePreviewController */
    private $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new TestableMergePreviewController();
        Carbon::setTestNow(Carbon::create(2025, 1, 2, 3, 4, 5));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testCalculateMergedPersonCombinesAttributesAndNotes()
    {
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
        $this->assertContains('Primary note', $values['c_notes']);
        $this->assertContains('Secondary note', $values['c_notes']);
        $this->assertContains('[merged #123 and #456 on 20250102 with reason] duplicate record', $values['c_notes']);

        $this->assertArrayHasKey('c_notes', $updates);
        $this->assertArrayHasKey('c_modified_by', $updates);
        $this->assertArrayHasKey('c_modified_date', $updates);
        $this->assertArrayNotHasKey('c_personid', $updates);
    }

    public function testCalculateMergedPersonWithoutSecondaryReturnsEmpty()
    {
        $primary = ['exists' => true, 'attributes' => []];
        $secondary = ['exists' => false];

        $result = $this->controller->callCalculateMergedPerson($primary, $secondary, '');

        $this->assertSame([], $result['values']);
        $this->assertSame([], $result['updates']);
    }

    public function testShouldBlockMergeDetectsNameDifference()
    {
        $primary = ['exists' => true, 'name' => 'Zhang San', 'name_chn' => '張三'];
        $secondary = ['exists' => true, 'name' => 'Li Si', 'name_chn' => '李四'];

        $this->assertTrue($this->controller->callShouldBlockMerge($primary, $secondary));

        $secondary['name'] = 'zhang san';
        $secondary['name_chn'] = '張三';
        $this->assertFalse($this->controller->callShouldBlockMerge($primary, $secondary));
    }

    public function testBuildSqlPreviewIncludesAutoArrangeStatements()
    {
        $statements = $this->controller->callBuildSqlPreview(200, 100, ['c_name' => 'Merged'], true, 100);

        $this->assertSame('START TRANSACTION;', $statements[0]);
        $this->assertContains("UPDATE BIOG_MAIN SET c_name = 'Merged' WHERE c_personid = 200;", $statements[1]);
        $this->assertContains('-- 調整至較小 ID 100', $statements, 'Auto arrange block should suggest moving to min ID');
        $this->assertContains("UPDATE BIOG_MAIN SET c_personid = 100 WHERE c_personid = 200;", $statements);
    }

    public function testBuildSqlPreviewSkipsAutoArrangeWhenDisabled()
    {
        $statements = $this->controller->callBuildSqlPreview(300, 200, [], false, null);

        $this->assertNotContains('-- 調整至較小 ID', $statements);
        $this->assertSame('START TRANSACTION;', $statements[0]);
        $this->assertSame('COMMIT;', $statements[count($statements) - 1]);
    }
}

class TestableMergePreviewController extends MergePreviewController
{
    public function callCalculateMergedPerson(array $primary, array $secondary, $reason)
    {
        return $this->calculateMergedPerson($primary, $secondary, $reason);
    }

    public function callShouldBlockMerge(array $primary, array $secondary)
    {
        return $this->shouldBlockMerge($primary, $secondary);
    }

    public function callBuildSqlPreview($primary, $secondary, array $updates, $autoArrange, $minTargetId)
    {
        return $this->buildSqlPreview($primary, $secondary, $updates, $autoArrange, $minTargetId);
    }
}
