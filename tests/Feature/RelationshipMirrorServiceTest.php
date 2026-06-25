<?php

namespace Tests\Feature;

use App\Services\RelationshipMirrorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RelationshipMirrorService（§8 單一真相來源）契約測試。鎖住 repairConfig / validReverse*Set /
 * reverseRelationExists / buildReverseRelation / formatRecords，供後續里程碑（A/B/C/D）安心擴充。
 */
class RelationshipMirrorServiceTest extends TestCase {
    private RelationshipMirrorService $svc;

    protected function setUp(): void {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::create('KINSHIP_CODES', function (Blueprint $t) {
            $t->integer('c_kincode')->primary();
            $t->integer('c_kin_pair1')->nullable();
            $t->integer('c_kin_pair2')->nullable();
        });
        DB::table('KINSHIP_CODES')->insert([
            ['c_kincode' => 75, 'c_kin_pair1' => 76, 'c_kin_pair2' => 180],
            ['c_kincode' => 76, 'c_kin_pair1' => 75, 'c_kin_pair2' => null],
        ]);

        Schema::create('ASSOC_CODES', function (Blueprint $t) {
            $t->integer('c_assoc_code')->primary();
            $t->integer('c_assoc_pair')->nullable();
            $t->integer('c_assoc_pair2')->nullable();
        });
        DB::table('ASSOC_CODES')->insert([
            ['c_assoc_code' => 100, 'c_assoc_pair' => 101, 'c_assoc_pair2' => 198],
            ['c_assoc_code' => 300, 'c_assoc_pair' => null, 'c_assoc_pair2' => null],
        ]);

        Schema::create('KIN_DATA', function (Blueprint $t) {
            $t->integer('c_personid');
            $t->integer('c_kin_id')->default(0);
            $t->integer('c_kin_code')->default(0);
            $t->integer('c_source')->default(0);
            $t->text('c_notes')->nullable();
            $t->primary(['c_personid', 'c_kin_id', 'c_kin_code']);
        });

        Schema::create('ASSOC_DATA', function (Blueprint $t) {
            $t->integer('c_personid');
            $t->integer('c_assoc_code')->default(0);
            $t->integer('c_assoc_id')->default(0);
            $t->integer('c_kin_code')->default(0);
            $t->integer('c_kin_id')->default(0);
            $t->integer('c_assoc_kin_code')->default(0);
            $t->integer('c_assoc_kin_id')->default(0);
            $t->string('c_text_title', 255)->default('');
            $t->integer('c_assoc_first_year')->default(-9999);
            $t->integer('c_assoc_count')->nullable();
            $t->integer('c_sequence')->nullable();
            $t->integer('c_source')->default(0);
        });

        $this->svc = app(RelationshipMirrorService::class);
    }

    protected function tearDown(): void {
        Schema::dropIfExists('ASSOC_DATA');
        Schema::dropIfExists('KIN_DATA');
        Schema::dropIfExists('ASSOC_CODES');
        Schema::dropIfExists('KINSHIP_CODES');
        parent::tearDown();
    }

    #[Test]
    public function testRepairConfig(): void {
        $this->assertSame('KIN_DATA', $this->svc->repairConfig('kinship')['table']);
        $this->assertSame('c_kin_id', $this->svc->repairConfig('kinship')['related_id_field']);
        $this->assertSame('ASSOC_DATA', $this->svc->repairConfig('association')['table']);
        $this->assertSame('c_assoc_code', $this->svc->repairConfig('association')['relation_code_field']);
    }

    #[Test]
    public function testValidReverseKinSet(): void {
        // $code 自身的 pair1/pair2（非「指向 $code」）。
        $this->assertSame([76, 180], $this->svc->validReverseKinSet(75));
        $this->assertSame([75], $this->svc->validReverseKinSet(76)); // pair2=null 過濾
        $this->assertSame([], $this->svc->validReverseKinSet(0), '0/哨兵 → 空');
        $this->assertSame([], $this->svc->validReverseKinSet(null));
        $this->assertSame([], $this->svc->validReverseKinSet(999), '查無碼 → 空');
    }

    #[Test]
    public function testValidReverseAssocSet(): void {
        $this->assertSame([101, 198], $this->svc->validReverseAssocSet(100));
        $this->assertSame([], $this->svc->validReverseAssocSet(300), '無配對 → 空');
        $this->assertSame([], $this->svc->validReverseAssocSet(0));
        $this->assertSame([], $this->svc->validReverseAssocSet(999));
    }

    #[Test]
    public function testBuildReverseRelationKinship(): void {
        $relation = (object) ['c_source' => 10, 'c_pages' => '1-5', 'c_notes' => 'n', 'c_autogen_notes' => 'a'];
        $rev = $this->svc->buildReverseRelation('kinship', $relation, [
            'person_id' => 1000, 'related_id' => 2000, 'new_relation_code' => 76,
        ]);
        $this->assertSame(2000, $rev['c_personid'], '對方為主體');
        $this->assertSame(1000, $rev['c_kin_id'], '原人為客體');
        $this->assertSame(76, $rev['c_kin_code']);
        $this->assertSame(10, $rev['c_source']);
        $this->assertSame('a', $rev['c_autogen_notes']);
    }

    #[Test]
    public function testBuildReverseRelationAssocAppliesDefaults(): void {
        // c_assoc_first_year 缺 → DEFAULT_ASSOC_FIRST_YEAR；c_assoc_count/c_sequence/inst 缺 → 預設。
        $relation = (object) ['c_kin_code' => 0, 'c_kin_id' => 0, 'c_assoc_kin_code' => 0, 'c_assoc_kin_id' => 0, 'c_text_title' => '史記'];
        $rev = $this->svc->buildReverseRelation('association', $relation, [
            'person_id' => 1000, 'related_id' => 2000, 'new_relation_code' => 101,
        ]);
        $this->assertSame(2000, $rev['c_personid']);
        $this->assertSame(1000, $rev['c_assoc_id']);
        $this->assertSame(101, $rev['c_assoc_code']);
        $this->assertSame(RelationshipMirrorService::DEFAULT_ASSOC_FIRST_YEAR, $rev['c_assoc_first_year']);
        $this->assertSame(1, $rev['c_assoc_count']);
        $this->assertSame(0, $rev['c_sequence']);
        $this->assertSame(0, $rev['c_inst_code']);
    }

    #[Test]
    public function testReverseRelationExistsKinship(): void {
        DB::table('KIN_DATA')->insert(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 76]);
        $params = ['person_id' => 1000, 'related_id' => 2000, 'new_relation_code' => 76];
        $this->assertTrue($this->svc->reverseRelationExists('kinship', (object) [], $params));
        $params['new_relation_code'] = 75;
        $this->assertFalse($this->svc->reverseRelationExists('kinship', (object) [], $params), '反向碼不同 → 不存在');
    }

    #[Test]
    public function testFormatRecords(): void {
        $records = collect([
            (object) ['c_personid' => 1, 'c_kin_id' => 2, 'c_kin_code' => 75, 'c_source' => 9, 'c_created_by' => 'x', 'c_created_date' => null],
        ]);
        $out = $this->svc->formatRecords('kinship', $records);
        $this->assertCount(1, $out);
        $this->assertSame(['c_personid' => 1, 'c_kin_id' => 2, 'c_kin_code' => 75, 'c_source' => 9, 'c_created_by' => 'x', 'c_created_date' => null], $out[0]);
    }
}
