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
            ['c_kincode' => 77, 'c_kin_pair1' => 75, 'c_kin_pair2' => null], // 與 76 同樣「指向 75」，模擬排行多碼
            ['c_kincode' => 200, 'c_kin_pair1' => 201, 'c_kin_pair2' => null], // 無人指向 200 → legit 退回自身配對 [201]
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
            $t->text('c_autogen_notes')->nullable();
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
    public function testLegitReverseKinCodes(): void {
        // 「指向 $code」的碼集（pair1/pair2 = $code），與 validReverseKinSet（$code 自身配對）方向相反。
        $this->assertEqualsCanonicalizing([76, 77], $this->svc->legitReverseKinCodes(75), '76、77 皆指向 75（排行多碼）');
        $this->assertSame([75], $this->svc->legitReverseKinCodes(76));
        $this->assertSame([201], $this->svc->legitReverseKinCodes(200), '無人指向 200 → 退回自身配對 [201]');
        $this->assertSame([], $this->svc->legitReverseKinCodes(0));
        $this->assertSame([], $this->svc->legitReverseKinCodes(null));
    }

    #[Test]
    public function testKinReverseLocatorCodes(): void {
        // #87：定位碼集＝「指向 $code 的碼」∪「$code 自身 pair1/pair2」。
        // 75：指向集{76,77} ∪ 自身{76,180} = {76,77,180}——關鍵是含 180（75.c_kin_pair2=180 但無碼回指 75）。
        $this->assertEqualsCanonicalizing([76, 77, 180], $this->svc->kinReverseLocatorCodes(75), '聯集須含自身配對 180（非對稱）');
        $this->assertSame([75], $this->svc->kinReverseLocatorCodes(76), '對稱配對：兩集相等');
        $this->assertSame([201], $this->svc->kinReverseLocatorCodes(200), '無人指向 200 → 僅自身配對 [201]');
        $this->assertSame([], $this->svc->kinReverseLocatorCodes(0));
        $this->assertSame([], $this->svc->kinReverseLocatorCodes(null));
    }

    #[Test]
    public function testLocateOppositeEdgesKinAsymmetricPairNotFalseMissing(): void {
        // #87 回歸：對面以「我方自身配對碼」180 編碼（75.c_kin_pair2=180，但無碼回指 75）。
        // 舊定位（僅指向集{76,77}）會漏命中→誤報「對面缺邊」；聯集{76,77,180}應命中→非缺邊。
        $locator = ['person_id' => 1000, 'opposite_id' => 2000, 'autogen_notes' => 'a', 'forward_code' => 75];
        DB::table('KIN_DATA')->insert(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 180, 'c_notes' => 'r', 'c_autogen_notes' => 'a']);
        $this->assertCount(1, $this->svc->locateOppositeEdges('kinship', $locator), '180 編碼的合法反向列應被定位（不誤報缺邊）');
    }

    #[Test]
    public function testLocateOppositeEdgesKinship(): void {
        $locator = ['person_id' => 1000, 'opposite_id' => 2000, 'autogen_notes' => 'a', 'forward_code' => 75];
        // 缺邊（對面 0 列 → 問題 A）。
        $this->assertCount(0, $this->svc->locateOppositeEdges('kinship', $locator));
        // 一條對面（碼 76 ∈ legit{76,77}）。
        DB::table('KIN_DATA')->insert(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 76, 'c_notes' => 'r1', 'c_autogen_notes' => 'a']);
        $this->assertCount(1, $this->svc->locateOppositeEdges('kinship', $locator));
        // 多條（再加碼 77 → 問題 B）。
        DB::table('KIN_DATA')->insert(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 77, 'c_notes' => 'r2', 'c_autogen_notes' => 'a']);
        $this->assertCount(2, $this->svc->locateOppositeEdges('kinship', $locator));
        // autogen_notes 不符 → 不命中（定位器條件）。
        $miss = array_merge($locator, ['autogen_notes' => 'other']);
        $this->assertCount(0, $this->svc->locateOppositeEdges('kinship', $miss));
    }

    #[Test]
    public function testLocateOppositeEdgesKinAutogenNullEmptyEquivalent(): void {
        // 回歸（review SERIOUS）：對面鏡像 c_autogen_notes 為 NULL（常見），前端送 ''（控制器把 NULL 補水成 ''）→
        // 須仍命中（NULL/'' 視為「無自動備註」同義），不可誤報缺邊。反之非空也須能命中既有 ''/NULL 不混淆。
        DB::table('KIN_DATA')->insert(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 76, 'c_autogen_notes' => null]);
        // 送 '' → 命中 NULL 列。
        $this->assertCount(1, $this->svc->locateOppositeEdges('kinship', ['person_id' => 1000, 'opposite_id' => 2000, 'autogen_notes' => '', 'forward_code' => 75]));
        // 送 null → 同樣命中。
        $this->assertCount(1, $this->svc->locateOppositeEdges('kinship', ['person_id' => 1000, 'opposite_id' => 2000, 'autogen_notes' => null, 'forward_code' => 75]));
        // 另一列 autogen='' 亦屬空 → 送 '' 命中兩列。
        DB::table('KIN_DATA')->insert(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 77, 'c_autogen_notes' => '']);
        $this->assertCount(2, $this->svc->locateOppositeEdges('kinship', ['person_id' => 1000, 'opposite_id' => 2000, 'autogen_notes' => '', 'forward_code' => 75]));
        // 送非空 'x' → 不命中空值列。
        $this->assertCount(0, $this->svc->locateOppositeEdges('kinship', ['person_id' => 1000, 'opposite_id' => 2000, 'autogen_notes' => 'x', 'forward_code' => 75]));
    }

    #[Test]
    public function testLocateOppositeEdgesAssociation(): void {
        $locator = ['person_id' => 1000, 'opposite_id' => 2000, 'text_title' => '史記', 'first_year' => 1080, 'forward_code' => 100];
        $this->assertCount(0, $this->svc->locateOppositeEdges('association', $locator));
        // 對面碼 101 ∈ validReverseAssocSet(100)={101,198}。
        DB::table('ASSOC_DATA')->insert(['c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000, 'c_text_title' => '史記', 'c_assoc_first_year' => 1080]);
        $this->assertCount(1, $this->svc->locateOppositeEdges('association', $locator));
        // 對面碼 198（次反向）亦命中 → 多條。
        DB::table('ASSOC_DATA')->insert(['c_personid' => 2000, 'c_assoc_code' => 198, 'c_assoc_id' => 1000, 'c_text_title' => '史記', 'c_assoc_first_year' => 1080]);
        $this->assertCount(2, $this->svc->locateOppositeEdges('association', $locator));
        // 書名不符 → 不命中。
        $this->assertCount(0, $this->svc->locateOppositeEdges('association', array_merge($locator, ['text_title' => '漢書'])));
    }

    #[Test]
    public function testLocateOppositeEdgesAssocPairlessCodeMatchesNone(): void {
        // 刻意差異（codex/review MINOR）：正向碼 300 無合法配對（c_assoc_pair 皆 null）→ 即使對面同 (assoc_id/書名/首年)
        // 有列，本「純偵測」法亦命中 0（whereIn [-99999]），不像 sync 的空 where 群組「全命中」。鎖死此偏離 sync 的刻意行為。
        DB::table('ASSOC_DATA')->insert(['c_personid' => 2000, 'c_assoc_code' => 555, 'c_assoc_id' => 1000, 'c_text_title' => '史記', 'c_assoc_first_year' => 1080]);
        $locator = ['person_id' => 1000, 'opposite_id' => 2000, 'text_title' => '史記', 'first_year' => 1080, 'forward_code' => 300];
        $this->assertCount(0, $this->svc->locateOppositeEdges('association', $locator), '無配對碼 → 命中 0（不退化為全命中）');
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
