<?php

namespace Tests\Unit;

use App\Services\CharVariantMapService;
use App\Support\VariantReplaceScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * replaceRow()／replaceFor()／assertWritable() 與「載入時傳遞閉包」的測試。
 *
 * 設計見 docs/CHAR_VARIANT_MAP_TEXT_COLUMN_ROLLOUT_PLAN.md D8。手動建表（比照
 * CharVariantMapServiceTest 慣例），種入與 migration 相同的 7 筆種子。
 */
class CharVariantMapServiceRowTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $this->createVariantMapTable();
        $this->seedDefaultMappings();

        Schema::dropIfExists('BIOG_MAIN');
        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->string('c_name_chn', 255)->nullable();
            $table->string('c_surname_chn', 255)->nullable();
            $table->string('c_mingzi_chn', 255)->nullable();
            $table->string('c_surname', 255)->nullable();
            $table->string('c_tribe', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->integer('c_index_year')->nullable();
        });

        CharVariantMapService::reset();
        VariantReplaceScope::reset();
    }

    private function createVariantMapTable(): void {
        Schema::dropIfExists('char_variant_map');
        Schema::create('char_variant_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('c_variant_char', 10);
            $table->string('c_reference_char', 10);
            $table->tinyInteger('c_strict_excluded')->default(1);
            $table->string('c_notes', 255)->nullable();
            $table->timestamps();

            $table->unique('c_variant_char', 'char_variant_map_c_variant_char_unique');
        });
    }

    private function seedDefaultMappings(): void {
        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '愼', 'c_reference_char' => '慎', 'c_strict_excluded' => 0],
            ['c_variant_char' => '槀', 'c_reference_char' => '稿', 'c_strict_excluded' => 0],
            ['c_variant_char' => '峯', 'c_reference_char' => '峰', 'c_strict_excluded' => 1],
            ['c_variant_char' => '靑', 'c_reference_char' => '青', 'c_strict_excluded' => 0],
            ['c_variant_char' => '頴', 'c_reference_char' => '穎', 'c_strict_excluded' => 0],
            ['c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
            ['c_variant_char' => '厰', 'c_reference_char' => '廠', 'c_strict_excluded' => 0],
        ]);
    }

    private function resetCaches(): void {
        CharVariantMapService::reset();
        VariantReplaceScope::reset();
    }

    // ─────────────────────────── replaceRow ───────────────────────────

    /**
     * D4 的核心語義：strict／lenient 是**逐欄位**而非逐表。
     * 同一列裡姓名欄走 strict（「峯」保留）、c_notes 走 lenient（「峯」被替換）。
     */
    #[Test]
    public function testReplaceRowMixesStrictAndLenientWithinOneRow(): void {
        $result = CharVariantMapService::replaceRow([
            'c_surname_chn' => '峯',
            'c_mingzi_chn' => '淸',
            'c_notes' => '峯淸',
            'c_index_year' => 1200,
        ], 'BIOG_MAIN');

        // 姓名欄 strict：峯 保留、淸 仍會替換（它 c_strict_excluded = 0）
        $this->assertSame('峯', $result['data']['c_surname_chn']);
        $this->assertSame('清', $result['data']['c_mingzi_chn']);
        // c_notes lenient：峯 也被替換
        $this->assertSame('峰清', $result['data']['c_notes']);
        // 非文本欄原樣
        $this->assertSame(1200, $result['data']['c_index_year']);

        $this->assertSame(['淸' => '清', '峯' => '峰'], $result['replaced']);
    }

    #[Test]
    public function testReplaceRowSkipsExcludedAndNonStringValues(): void {
        $result = CharVariantMapService::replaceRow([
            'c_surname' => '峯',          // 拉丁人名欄（排除）
            'c_modified_by' => '淸某',      // 稽核欄（排除）
            'c_index_year' => 1200,        // 非文本
            'c_notes' => null,             // null 跳過
            'nested' => ['c_notes' => '淸'], // 陣列跳過（刻意的淺層掃描）
        ], 'BIOG_MAIN');

        $this->assertSame('峯', $result['data']['c_surname']);
        $this->assertSame('淸某', $result['data']['c_modified_by']);
        $this->assertSame(1200, $result['data']['c_index_year']);
        $this->assertNull($result['data']['c_notes']);
        $this->assertSame(['c_notes' => '淸'], $result['data']['nested']);
        $this->assertSame([], $result['replaced']);
    }

    /** D2 fail-closed：未知表整列不動。 */
    #[Test]
    public function testReplaceRowIsNoOpForUnknownTable(): void {
        $result = CharVariantMapService::replaceRow(['anything' => '淸'], 'not_a_cbdb_table');

        $this->assertSame('淸', $result['data']['anything']);
        $this->assertSame([], $result['replaced']);
    }

    #[Test]
    public function testReplaceRowIsIdempotent(): void {
        $once = CharVariantMapService::replaceRow(['c_notes' => '峯淸頴'], 'BIOG_MAIN');
        $twice = CharVariantMapService::replaceRow($once['data'], 'BIOG_MAIN');

        $this->assertSame($once['data'], $twice['data']);
        $this->assertSame([], $twice['replaced'], '第二次套用不該再命中任何對照');
    }

    // ─────────────────────────── replaceFor ───────────────────────────

    #[Test]
    public function testReplaceForHandlesValuesThatAreNotKeyedByColumn(): void {
        // 這是 S4 需要的入口：手上是裸字串、不是以欄位名為鍵的整列
        $strict = CharVariantMapService::replaceFor('BIOG_MAIN', 'c_surname_chn', '峯');
        $this->assertSame('峯', $strict['text']);

        $lenient = CharVariantMapService::replaceFor('BIOG_MAIN', 'c_notes', '峯');
        $this->assertSame('峰', $lenient['text']);

        $excluded = CharVariantMapService::replaceFor('pinyin', 'c_chn', '峯');
        $this->assertSame('峯', $excluded['text']);
        $this->assertSame([], $excluded['replaced']);
    }

    /**
     * 同一個變體在同一列被解析成**不同**的參考字時，兩個結果都要保留。
     *
     * 這會發生在 strict 欄與 lenient 欄的閉包終點不同時（`龴→峯`(excluded=0) +
     * `峯→峰`(excluded=1)：strict 得「峯」、lenient 得「峰」）。若用 `+=` 或
     * `array_merge` 合併，其中一個會被靜默丟掉，`buildNotices()` 就會告訴使用者
     * 錯誤的字——而通知是使用者唯一能看見替換發生的管道。
     */
    #[Test]
    public function testReplaceRowKeepsBothReferencesWhenOneVariantResolvesDifferentlyPerMode(): void {
        DB::table('char_variant_map')->truncate();
        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '龴', 'c_reference_char' => '峯', 'c_strict_excluded' => 0],
            ['c_variant_char' => '峯', 'c_reference_char' => '峰', 'c_strict_excluded' => 1],
        ]);
        $this->resetCaches();

        $result = CharVariantMapService::replaceRow([
            'c_surname_chn' => '龴',  // strict → 峯
            'c_notes' => '龴',        // lenient → 峰
        ], 'BIOG_MAIN');

        $this->assertSame('峯', $result['data']['c_surname_chn']);
        $this->assertSame('峰', $result['data']['c_notes']);

        // 兩個參考字都要在 replaced 裡
        $this->assertArrayHasKey('龴', $result['replaced']);
        $references = is_array($result['replaced']['龴']) ? $result['replaced']['龴'] : [$result['replaced']['龴']];
        $this->assertContains('峯', $references);
        $this->assertContains('峰', $references);

        // buildNotices() 必須能渲染陣列形狀，且兩個字都出現
        $notices = CharVariantMapService::buildNotices($result['replaced']);
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('峯', $notices[0]);
        $this->assertStringContainsString('峰', $notices[0]);
    }

    /** 同一個變體在多欄命中同一個參考字時，不該重複列出。 */
    #[Test]
    public function testReplaceRowDoesNotDuplicateIdenticalReplacements(): void {
        $result = CharVariantMapService::replaceRow([
            'c_notes' => '淸',
            'c_tribe' => '淸',
        ], 'BIOG_MAIN');

        $this->assertSame(['淸' => '清'], $result['replaced']);
        $this->assertCount(1, CharVariantMapService::buildNotices($result['replaced']));
    }

    // ───────────────────── 傳遞閉包與環（D8） ─────────────────────

    /**
     * 幂等性不依賴表的內容：即使有人塞出一條鏈 A→B→C，替換結果仍是不動點。
     *
     * 沒有閉包的話：strtr 第一趟把 A 變 B，第二趟再把 B 變 C ⇒ 套兩次 ≠ 套一次。
     */
    #[Test]
    public function testTransitiveClosureMakesChainedMappingsIdempotent(): void {
        DB::table('char_variant_map')->truncate();
        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '甲', 'c_reference_char' => '乙', 'c_strict_excluded' => 0],
            ['c_variant_char' => '乙', 'c_reference_char' => '丙', 'c_strict_excluded' => 0],
        ]);
        $this->resetCaches();

        $once = CharVariantMapService::replaceLenient('甲乙');
        // 閉包後 甲→丙、乙→丙，一趟就到終端
        $this->assertSame('丙丙', $once['text']);

        $twice = CharVariantMapService::replaceLenient($once['text']);
        $this->assertSame($once['text'], $twice['text']);
        $this->assertSame([], $twice['replaced']);
    }

    /**
     * 環的處置：只丟棄構成環的邊、其餘照常生效，**不拋錯也不回空 map**。
     *
     * 這兩個 map 方法是所有替換的唯一入口，在此 throw 會讓 Codes UI 80 表、
     * 所有 v2 mutate、批次匯入、眾包核准、提案核准一起爆。
     */
    #[Test]
    public function testCycleEdgesAreDroppedWhileOtherMappingsSurvive(): void {
        DB::table('char_variant_map')->truncate();
        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '甲', 'c_reference_char' => '乙', 'c_strict_excluded' => 0],
            ['c_variant_char' => '乙', 'c_reference_char' => '甲', 'c_strict_excluded' => 0],
            ['c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
        ]);
        $this->resetCaches();

        $result = CharVariantMapService::replaceLenient('甲乙淸');

        // 環上兩個字保持原樣
        $this->assertStringContainsString('甲', $result['text']);
        $this->assertStringContainsString('乙', $result['text']);
        // 環外的對照照常生效
        $this->assertStringContainsString('清', $result['text']);
        $this->assertSame(['淸' => '清'], $result['replaced']);
    }

    #[Test]
    public function testSelfReferencingMappingIsTreatedAsCycle(): void {
        DB::table('char_variant_map')->truncate();
        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '甲', 'c_reference_char' => '甲', 'c_strict_excluded' => 0],
            ['c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
        ]);
        $this->resetCaches();

        $result = CharVariantMapService::replaceLenient('甲淸');

        $this->assertSame('甲清', $result['text']);
    }

    /**
     * 「鏈進入環」：A→B、B→C、C→B。只丟環上節點（B、C）的出邊，**A→B 要保留**
     * （B 成為終端）。丟掉 A→B 會誤殺不在環上的合法對照。
     */
    #[Test]
    public function testChainEnteringCycleKeepsTheEdgeIntoTheCycle(): void {
        DB::table('char_variant_map')->truncate();
        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '甲', 'c_reference_char' => '乙', 'c_strict_excluded' => 0],
            ['c_variant_char' => '乙', 'c_reference_char' => '丙', 'c_strict_excluded' => 0],
            ['c_variant_char' => '丙', 'c_reference_char' => '乙', 'c_strict_excluded' => 0],
        ]);
        $this->resetCaches();

        $result = CharVariantMapService::replaceLenient('甲乙丙');

        // 甲→乙 保留；乙、丙 在環上、出邊被丟棄
        $this->assertSame('乙乙丙', $result['text']);
        $this->assertSame(['甲' => '乙'], $result['replaced']);
    }

    /**
     * 閉包必須**先按模式過濾、再各自計算**。
     *
     * `X→峯`(excluded=0) + `峯→峰`(excluded=1)：strict 只看得到第一條邊，
     * 所以 strict 對 X 應得「峯」；若先對全表算閉包再過濾，會得到 `X→峰`，
     * 等於透過傳遞把一條 strict-excluded 的邊套進人名欄，廢掉 c_strict_excluded 的唯一用途。
     */
    #[Test]
    public function testClosureIsComputedPerModeSoStrictExclusionCannotLeakThroughChains(): void {
        DB::table('char_variant_map')->truncate();
        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '龴', 'c_reference_char' => '峯', 'c_strict_excluded' => 0],
            ['c_variant_char' => '峯', 'c_reference_char' => '峰', 'c_strict_excluded' => 1],
        ]);
        $this->resetCaches();

        // lenient 看得到兩條邊 ⇒ 閉包後 龴→峰
        $this->assertSame('峰', CharVariantMapService::replaceLenient('龴')['text']);

        // strict 只看得到 龴→峯 ⇒ 停在「峯」，不可洩漏成「峰」
        $this->assertSame('峯', CharVariantMapService::replaceStrict('龴')['text']);
        // 且 strict 對「峯」本身不動
        $this->assertSame('峯', CharVariantMapService::replaceStrict('峯')['text']);
    }

    /** 多字元 key 會破壞幂等論證，載入時就要被剔除（寫入端另有 guard）。 */
    #[Test]
    public function testMultiCodepointMappingsAreIgnoredWhenLoading(): void {
        DB::table('char_variant_map')->truncate();
        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '甲乙', 'c_reference_char' => '丙丁', 'c_strict_excluded' => 0],
            ['c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
        ]);
        $this->resetCaches();

        $result = CharVariantMapService::replaceLenient('甲乙淸');

        $this->assertSame('甲乙清', $result['text'], '多字元對照不該生效');
    }

    // ─────────────────── mergeReplaced／flattenReplaced 的代數性質 ───────────────────

    /**
     * 這三條把設計倚賴的性質釘住。衝突路徑目前沒有生產呼叫端
     * （`replaceRow()` 要到 S2／S3 才接線），所以只有它們在守著。
     */
    #[Test]
    public function testMergeReplacedIsIdempotent(): void {
        $a = ['淸' => '清', '龴' => ['峯', '峰']];

        $this->assertSame($a, CharVariantMapService::mergeReplaced($a, $a));
    }

    #[Test]
    public function testMergeReplacedIsOrderIndependentAsASet(): void {
        $p = ['龴' => '峯'];
        $q = ['龴' => '峰'];

        $forward = CharVariantMapService::mergeReplaced($p, $q)['龴'];
        $backward = CharVariantMapService::mergeReplaced($q, $p)['龴'];

        sort($forward);
        sort($backward);
        $this->assertSame($forward, $backward, '合併順序只該影響列出順序，不該影響集合內容');
    }

    /** 單元素不得留成陣列，否則同一份資料會有兩種表示。 */
    #[Test]
    public function testMergeReplacedNormalizesSingleElementListsToString(): void {
        $merged = CharVariantMapService::mergeReplaced([], ['淸' => ['清']]);

        $this->assertSame(['淸' => '清'], $merged);
    }

    #[Test]
    public function testFlattenReplacedExpandsConflictsIntoSeparatePairs(): void {
        $pairs = CharVariantMapService::flattenReplaced(['淸' => '清', '龴' => ['峯', '峰']]);

        $this->assertSame([
            ['from' => '淸', 'to' => '清'],
            ['from' => '龴', 'to' => '峯'],
            ['from' => '龴', 'to' => '峰'],
        ], $pairs);
    }

    // ─────────────────── 資料不變式（migration 繞過 guard 的兜底） ───────────────────

    /**
     * 現有 7 筆種子必須無鏈無環，且全為單一 codepoint。
     *
     * 這是 D8 幂等論證的資料側前提；migration 直接 DB::table()->insert() 會繞過所有
     * 應用層 guard，所以這條測試是唯一兜底。
     */
    #[Test]
    public function testSeededMappingsAreSingleCodepointAndChainFree(): void {
        $rows = DB::table('char_variant_map')->get();
        $this->assertCount(7, $rows);

        $variants = [];
        $references = [];
        foreach ($rows as $row) {
            $this->assertSame(1, mb_strlen($row->c_variant_char), "c_variant_char 必須是單一字元：{$row->c_variant_char}");
            $this->assertSame(1, mb_strlen($row->c_reference_char), "c_reference_char 必須是單一字元：{$row->c_reference_char}");
            $variants[] = $row->c_variant_char;
            $references[] = $row->c_reference_char;
        }

        $this->assertSame(
            [],
            array_values(array_intersect($variants, $references)),
            '參考字集與異體字集不得相交（否則對照表成鏈）'
        );
    }

    /**
     * 哨兵字面值不得與異體字集相交。今天成立是**資料巧合**、不是保證——
     * 這些值會被寫進文本欄，若哪天收錄了其中的字元就會被靜默改寫。
     */
    #[Test]
    public function testSentinelLiteralsDoNotIntersectVariantChars(): void {
        $variants = DB::table('char_variant_map')->pluck('c_variant_char')->all();

        foreach (['[n/a]', '-9999', '<待删除>'] as $sentinel) {
            foreach ($variants as $variant) {
                $this->assertStringNotContainsString(
                    (string) $variant,
                    $sentinel,
                    "哨兵值 {$sentinel} 含有異體字 {$variant}，會被落地替換改寫"
                );
            }
        }
    }

    // ─────────────────────────── assertWritable ───────────────────────────

    // 這三條都斷言**訊息**而非只斷言 RuntimeException：光斷言型別的話，日後若把某個
    // 分支的判定順序調換（例如多字元被路由到 incomplete_payload），測試仍會綠。

    #[Test]
    public function testAssertWritableRejectsMultiCodepoint(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(__('variant.single_codepoint_required'));
        CharVariantMapService::assertWritable(['c_variant_char' => '甲乙', 'c_reference_char' => '丙']);
    }

    #[Test]
    public function testAssertWritableRejectsSelfReference(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(__('variant.self_reference_not_allowed'));
        CharVariantMapService::assertWritable(['c_variant_char' => '甲', 'c_reference_char' => '甲']);
    }

    #[Test]
    public function testAssertWritableRejectsNewCycle(): void {
        // 表裡已有 淸→清，新增 清→淸 會成環
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(__('variant.cycle_not_allowed', ['char' => '清']));
        CharVariantMapService::assertWritable(['c_variant_char' => '清', 'c_reference_char' => '淸']);
    }

    /**
     * 只送單邊字元欄、又沒有既有列可以 merge：這是呼叫端沒傳 id 的問題，
     * 訊息必須說「payload 不完整」而不是「必須是單一字元」（使用者根本沒動另一欄）。
     */
    #[Test]
    public function testAssertWritableRejectsIncompletePayloadWithItsOwnMessage(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(__('variant.incomplete_payload'));
        CharVariantMapService::assertWritable(['c_variant_char' => '甲']);
    }

    #[Test]
    public function testAssertWritableAcceptsPlainNewMapping(): void {
        CharVariantMapService::assertWritable(['c_variant_char' => '甲', 'c_reference_char' => '乙']);
        $this->addToAssertionCount(1);
    }

    /**
     * $excludeId：更新既有列時必須排除該列的舊邊，否則會誤報環。
     *
     * 表有 乙→甲、甲→丙；把「乙→甲」那列改成「丙→乙」是合法的 甲→丙→乙，
     * 但若把被取代的舊邊也算進去，就會看到假的環 乙→甲→丙→乙。
     */
    #[Test]
    public function testAssertWritableExcludesTheRowBeingReplacedWhenDetectingCycles(): void {
        DB::table('char_variant_map')->truncate();
        $oldId = DB::table('char_variant_map')->insertGetId(
            ['c_variant_char' => '乙', 'c_reference_char' => '甲', 'c_strict_excluded' => 0]
        );
        DB::table('char_variant_map')->insert(
            ['c_variant_char' => '甲', 'c_reference_char' => '丙', 'c_strict_excluded' => 0]
        );
        $this->resetCaches();

        // 不排除舊邊 ⇒ 誤報環
        try {
            CharVariantMapService::assertWritable(['c_variant_char' => '丙', 'c_reference_char' => '乙']);
            $this->fail('未排除舊邊時應偵測到（假的）環');
        } catch (\RuntimeException $e) {
            $this->addToAssertionCount(1);
        }

        // 排除被取代的那一列 ⇒ 合法
        CharVariantMapService::assertWritable(
            ['c_variant_char' => '丙', 'c_reference_char' => '乙'],
            $oldId
        );
        $this->addToAssertionCount(1);
    }

    /** 部分 payload（restoreUpdate 用歷史快照）需與現有列 merge 後再驗。 */
    #[Test]
    public function testAssertWritableMergesPartialPayloadWithExistingRow(): void {
        DB::table('char_variant_map')->truncate();
        $id = DB::table('char_variant_map')->insertGetId(
            ['c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0]
        );
        $this->resetCaches();

        // 只送 c_reference_char：c_variant_char 應從現有列補齊，不該因缺欄而誤判長度
        CharVariantMapService::assertWritable(['c_reference_char' => '菁'], $id);
        $this->addToAssertionCount(1);
    }

    /** 兩個字元欄都沒被碰到（例如只改 c_notes）⇒ 不需驗證。 */
    #[Test]
    public function testAssertWritableSkipsWhenCharColumnsAreUntouched(): void {
        CharVariantMapService::assertWritable(['c_notes' => '只改備註']);
        $this->addToAssertionCount(1);
    }
}
