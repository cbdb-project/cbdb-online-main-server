<?php

namespace Tests\Unit;

use App\Services\CharVariantMapService;
use App\Support\VariantLabelMap;
use App\Support\VariantReplaceScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 「標籤 → 代碼」對照表的異體字歸一（plan S4）。
 *
 * 重點在**鍵碰撞**：這些 map 的值同時被當成合法代碼白名單，歸一後若兩列的鍵塌成一個，
 * 被丟掉那個代碼就會從白名單消失、一個完全合法的代碼開始被判 invalid。
 */
class VariantLabelMapTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::create('DYNASTIES', function (Blueprint $table) {
            $table->integer('c_dy')->primary();
            $table->string('c_dynasty_chn')->nullable();
        });

        Schema::create('char_variant_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('c_variant_char', 10);
            $table->string('c_reference_char', 10);
            $table->tinyInteger('c_strict_excluded')->default(1);
            $table->string('c_notes', 255)->nullable();
            $table->timestamps();
            $table->unique('c_variant_char', 'char_variant_map_c_variant_char_unique');
        });
        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
        ]);
        CharVariantMapService::reset();
        VariantReplaceScope::reset();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('char_variant_map');
        Schema::dropIfExists('DYNASTIES');
        parent::tearDown();
    }

    /** 鍵歸一：代碼表寫變體形時，用參考形也查得到。 */
    #[Test]
    public function testKeysAreNormalizedSoReferenceFormLookupHitsVariantFormRow(): void {
        [$map, $codes] = VariantLabelMap::build([['淸', 40]], 'DYNASTIES', 'c_dynasty_chn');

        $this->assertSame(['清' => 40], $map, '鍵應存成參考形');
        $this->assertSame([40], $codes);
        $this->assertSame(40, VariantLabelMap::lookup($map, '清', 'DYNASTIES', 'c_dynasty_chn'));
        $this->assertSame(40, VariantLabelMap::lookup($map, '淸', 'DYNASTIES', 'c_dynasty_chn'), '傳入變體形也要命中');
    }

    /**
     * 鍵碰撞：取**最小**碼（不是既有 last-wins 的最大者），且**兩個代碼都留在白名單裡**。
     * 後者是這個類別存在的主要理由——否則一個完全合法的代碼會開始被判 invalid。
     */
    #[Test]
    public function testCollisionKeepsMinimumCodeInMapAndBothCodesInWhitelist(): void {
        [$map, $codes] = VariantLabelMap::build([['淸', 40], ['清', 41]], 'DYNASTIES', 'c_dynasty_chn');

        $this->assertSame(['清' => 40], $map, '碰撞取最小碼');
        sort($codes);
        $this->assertSame([40, 41], $codes, '兩個代碼都必須留在白名單');
    }

    /** 順序相反也要得到同樣的結果（「取最小」不能依賴輸入順序）。 */
    #[Test]
    public function testCollisionResultIsIndependentOfRowOrder(): void {
        [$mapA] = VariantLabelMap::build([['淸', 40], ['清', 41]], 'DYNASTIES', 'c_dynasty_chn');
        [$mapB] = VariantLabelMap::build([['清', 41], ['淸', 40]], 'DYNASTIES', 'c_dynasty_chn');

        $this->assertSame($mapA, $mapB);
    }

    /**
     * 空標籤的列既不進 map、**也不進白名單**。
     *
     * 白名單的用途只是「不要因為歸一造成的鍵碰撞而漏掉合法代碼」，碰撞雙方都必然有標籤。
     * 把無標籤列（含 c_dy 為 NULL 被折成 0 的佔位列）也算進去，等於把原本「map 的值」
     * 那組白名單悄悄放寬，讓 dynasty_code=0／佔位碼從被擋變成通過。
     */
    #[Test]
    public function testRowsWithEmptyLabelDoNotWidenTheWhitelist(): void {
        [$map, $codes] = VariantLabelMap::build([['', 99], ['清', 40]], 'DYNASTIES', 'c_dynasty_chn');

        $this->assertSame(['清' => 40], $map);
        $this->assertSame([40], $codes, '無標籤列的代碼不得混進白名單');
    }

    /** 字面完全相同的重複標籤不算「歸一造成的碰撞」，不該刷 warning（但仍取最小碼）。 */
    #[Test]
    public function testDuplicateIdenticalLabelsDoNotLogCollisionWarning(): void {
        \Illuminate\Support\Facades\Log::spy();

        [$map] = VariantLabelMap::build([['清', 41], ['清', 40]], 'DYNASTIES', 'c_dynasty_chn');

        $this->assertSame(['清' => 40], $map);
        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning');
    }

    /** 歸一造成的碰撞才記 warning（碰撞是唯一的可觀測性）。 */
    #[Test]
    public function testNormalizationCollisionLogsWarning(): void {
        \Illuminate\Support\Facades\Log::spy();

        VariantLabelMap::build([['淸', 40], ['清', 41]], 'DYNASTIES', 'c_dynasty_chn');

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once();
    }

    /** 對照表不存在時（部署未跑 migration）歸一是恆等映射，不可炸也不可清空 map。 */
    #[Test]
    public function testDegradesToIdentityWhenMappingTableIsMissing(): void {
        Schema::dropIfExists('char_variant_map');
        CharVariantMapService::reset();

        [$map, $codes] = VariantLabelMap::build([['淸', 40]], 'DYNASTIES', 'c_dynasty_chn');

        $this->assertSame(['淸' => 40], $map);
        $this->assertSame([40], $codes);
    }
}
