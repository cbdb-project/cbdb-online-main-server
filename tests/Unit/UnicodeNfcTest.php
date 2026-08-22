<?php

namespace Tests\Unit;

use App\Services\CharVariantMapService;
use App\Support\UnicodeNfc;
use App\Support\VariantReplaceScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unicode NFC 正規化（`App\Support\UnicodeNfc`）與其在寫入路徑上的掛鉤。
 *
 * 兩件事必須同時成立、且**互不越界**（見 UnicodeNfc 類註的對照表）：
 *  - NFC 折疊相容表意文字（慎 U+FA87 → 慎 U+614E）——canonical equivalence，同一個字；
 *  - NFC **不碰**異體字（愼 U+613C／峯 U+5CEF）——Unicode 不給統一表意文字 canonical
 *    decomposition，那是 char_variant_map 的職責範圍。
 *
 * 若哪天有人把 NFC 換成 NFKC，第二組斷言不會紅（NFKC 對這些字同樣不變），但
 * `testNfkcOnlyFoldingsAreNotApplied` 會紅——NFKC 會摧毀全形／組合字等**有意義的**區別。
 */
class UnicodeNfcTest extends TestCase {
    /** 相容表意文字（U+F900–U+FAFF）：canonical decomposition ⇒ NFC 必折疊。 */
    private const COMPAT_SHEN = "\u{FA87}";      // 慎
    private const COMPAT_LI = "\u{F9E1}";        // 李（生產庫 c_personid=551931 實際存的碼位）
    private const COMPAT_LANG = "\u{F92C}";      // 郎
    private const COMPAT_JING = "\u{FA1D}";      // 精

    /** 統一表意文字：NFC 不動，異體字關係由 char_variant_map 處理。 */
    private const UNIFIED_SHEN = "\u{614E}";     // 慎
    private const UNIFIED_LI = "\u{674E}";       // 李
    private const VARIANT_SHEN = "\u{613C}";     // 愼（異體字，非相容字）
    private const VARIANT_FENG = "\u{5CEF}";     // 峯（異體字，strict 排除）

    protected function setUp(): void {
        parent::setUp();

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
        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '愼', 'c_reference_char' => '慎', 'c_strict_excluded' => 0],
            ['c_variant_char' => '峯', 'c_reference_char' => '峰', 'c_strict_excluded' => 1],
        ]);

        Schema::dropIfExists('BIOG_MAIN');
        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->string('c_name_chn', 255)->nullable();
            $table->string('c_surname_chn', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->integer('c_index_year')->nullable();
        });

        CharVariantMapService::reset();
        VariantReplaceScope::reset();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('BIOG_MAIN');
        Schema::dropIfExists('char_variant_map');
        parent::tearDown();
    }

    // ── UnicodeNfc 本身 ──────────────────────────────

    #[Test]
    public function testFoldsCompatibilityIdeographsToUnifiedForm(): void {
        $this->assertSame(self::UNIFIED_SHEN, UnicodeNfc::normalize(self::COMPAT_SHEN));
        $this->assertSame(self::UNIFIED_LI, UnicodeNfc::normalize(self::COMPAT_LI));
        $this->assertSame("\u{90CE}", UnicodeNfc::normalize(self::COMPAT_LANG));
        $this->assertSame("\u{7CBE}", UnicodeNfc::normalize(self::COMPAT_JING));

        // 相容表意文字補充區（U+2F800–U+2FA1D）同樣折疊。
        $this->assertSame(self::UNIFIED_SHEN, UnicodeNfc::normalize("\u{2F8A8}"));

        // 混在句子裡也要折（生產庫 c_personid=427468「郎侃」的實際形狀）。
        $this->assertSame("\u{90CE}\u{4F83}", UnicodeNfc::normalize(self::COMPAT_LANG."\u{4F83}"));
    }

    /**
     * 界線測試：NFC **不得**碰異體字。這兩個字若被折疊，代表有人誤用了 NFKC
     * 或自己加了對照——那會讓 char_variant_map 的 strict／lenient 分野失效
     * （峯 在人名欄是刻意保留的）。
     */
    #[Test]
    public function testDoesNotTouchVariantIdeographs(): void {
        foreach ([self::VARIANT_SHEN, self::VARIANT_FENG, "\u{9751}", "\u{9834}", "\u{6DF8}", "\u{69C0}", "\u{53B0}"] as $char) {
            $this->assertSame($char, UnicodeNfc::normalize($char), sprintf('U+%04X 不應被 NFC 改動', mb_ord($char, 'UTF-8')));
        }
    }

    /**
     * 若有人把 FORM_C 改成 FORM_KC，這條會紅：NFKC 會把全形字母、羅馬數字、
     * 組合符號等**有意義的**區別一併抹掉，那不是本機制要做的事。
     */
    #[Test]
    public function testNfkcOnlyFoldingsAreNotApplied(): void {
        $this->assertSame('Ａ', UnicodeNfc::normalize('Ａ'));      // 全形 A（NFKC 會變半形）
        $this->assertSame('Ⅷ', UnicodeNfc::normalize('Ⅷ'));      // 羅馬數字（NFKC 會拆成 VIII）
        $this->assertSame('㈱', UnicodeNfc::normalize('㈱'));      // 括號株（NFKC 會變 (株)）
    }

    #[Test]
    public function testLeavesAlreadyNormalizedAndAsciiUntouched(): void {
        $this->assertSame('', UnicodeNfc::normalize(''));
        $this->assertSame('Wang Anshi', UnicodeNfc::normalize('Wang Anshi'));
        $this->assertSame('王安石', UnicodeNfc::normalize('王安石'));
        $this->assertSame('bei song', UnicodeNfc::normalize('bei song'));
    }

    /** 格式錯誤的 UTF-8：保留原值，絕不回 false／空字串（這是寫入路徑）。 */
    #[Test]
    public function testMalformedUtf8IsReturnedUnchanged(): void {
        $malformed = "\xC3\x28";
        $this->assertSame($malformed, UnicodeNfc::normalize($malformed));
    }

    // ── 掛鉤：四個公開入口都必須經過 NFC ──────────────────────────────

    #[Test]
    public function testAllReplacementEntryPointsNormalize(): void {
        $this->assertSame(self::UNIFIED_SHEN, CharVariantMapService::replaceLenient(self::COMPAT_SHEN)['text']);
        $this->assertSame(self::UNIFIED_SHEN, CharVariantMapService::replaceStrict(self::COMPAT_SHEN)['text']);
        $this->assertSame(self::UNIFIED_SHEN, CharVariantMapService::replaceFor('BIOG_MAIN', 'c_name_chn', self::COMPAT_SHEN)['text']);

        $row = CharVariantMapService::replaceRow(['c_name_chn' => self::COMPAT_LI, 'c_index_year' => 1200], 'BIOG_MAIN');
        $this->assertSame(self::UNIFIED_LI, $row['data']['c_name_chn']);
        $this->assertSame(1200, $row['data']['c_index_year']);
    }

    /**
     * NFC 是異體字查表的**前置條件**：對照表的鍵是統一表意文字，未正規化的相容碼位
     * 一個都對不上。這裡用「相容形的 慎」驗證兩段依序生效——它 NFC 後就是參考字本身。
     */
    #[Test]
    public function testNfcRunsBeforeVariantLookup(): void {
        // 相容 慎 → NFC → 統一 慎（已是參考字，不需再替換）
        $result = CharVariantMapService::replaceLenient(self::COMPAT_SHEN);
        $this->assertSame(self::UNIFIED_SHEN, $result['text']);
        $this->assertSame([], $result['replaced'], 'NFC 不應被記進 replaced（通知會顯示成兩個相同字形）');

        // 異體 愼 → NFC 不動 → 對照表替換成 慎，且**要**記進 replaced
        $result = CharVariantMapService::replaceLenient(self::VARIANT_SHEN);
        $this->assertSame(self::UNIFIED_SHEN, $result['text']);
        $this->assertSame(['愼' => '慎'], $result['replaced']);
    }

    /** 對照表缺表時 NFC 仍須生效——它與 char_variant_map 無關。 */
    #[Test]
    public function testNfcStillAppliesWhenVariantMapTableIsMissing(): void {
        Schema::dropIfExists('char_variant_map');
        CharVariantMapService::reset();

        $this->assertSame(self::UNIFIED_LI, CharVariantMapService::replaceLenient(self::COMPAT_LI)['text']);
    }

    /**
     * 作用域與異體字替換一致：`VariantReplaceScope` 判定為排除／非文本欄時不做 NFC。
     * 這是刻意的——代碼鍵／join 鍵單邊正規化會打斷關聯，與 D3 排除清單同一個理由。
     */
    #[Test]
    public function testOutOfScopeColumnsAreNotNormalized(): void {
        $this->assertSame(
            self::COMPAT_LI,
            CharVariantMapService::replaceFor('char_variant_map', 'c_variant_char', self::COMPAT_LI)['text'],
            '對照表自身在排除清單內，不應被 NFC 改動'
        );
    }
}
