<?php

namespace Tests\Unit;

use App\Support\PinyinSearchNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PinyinSearchNormalizer：ü／v 折疊（#85）與查詢展開（§D-8）。
 */
class PinyinSearchNormalizerTest extends TestCase {
    #[Test]
    #[DataProvider('umlautToVCases')]
    public function it_folds_umlaut_to_v(string $in, string $expected): void {
        $this->assertSame($expected, PinyinSearchNormalizer::umlautToV($in));
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function umlautToVCases(): array {
        return [
            'lower ü' => ['Lü', 'Lv'],
            'upper Ü' => ['LÜ', 'LV'],
            'joined' => ['Yelü', 'Yelv'],
            'no umlaut' => ['Wang', 'Wang'],
            'chinese' => ['呂', '呂'],
        ];
    }

    #[Test]
    public function it_returns_empty_form_for_empty_input(): void {
        $this->assertSame([''], PinyinSearchNormalizer::expand(''));
        $this->assertSame([''], PinyinSearchNormalizer::expand(null));
    }

    #[Test]
    public function it_expands_v_form_to_both_v_and_umlaut(): void {
        // 使用者打 v 形（CBDB 舊慣例）→ 同查 v 形（殘留）與 ü 形（已遷移）
        $forms = PinyinSearchNormalizer::expand('Lv');
        $this->assertContains('Lv', $forms);
        $this->assertContains('Lü', $forms);
        $this->assertCount(2, $forms);
    }

    #[Test]
    public function it_expands_umlaut_form_to_both_umlaut_and_v(): void {
        // 使用者打 ü 形（正規拼音）→ 同查 ü 形（已遷移）與 v 形（殘留）
        $forms = PinyinSearchNormalizer::expand('Lü');
        $this->assertContains('Lü', $forms);
        $this->assertContains('Lv', $forms);
        $this->assertCount(2, $forms);
    }

    #[Test]
    public function it_expands_joined_and_uppercase_syllables(): void {
        $this->assertEqualsCanonicalizing(['Yelv', 'Yelü'], PinyinSearchNormalizer::expand('Yelv'));
        $this->assertEqualsCanonicalizing(['NV', 'NÜ'], PinyinSearchNormalizer::expand('NV'));
    }

    #[Test]
    #[DataProvider('singleFormCases')]
    public function it_is_a_noop_for_non_convertible_input(string $in): void {
        // 中文／數字／無可轉音節或 ü 的西文名 → 展開後仍為單一形（行為不變）
        $this->assertSame([$in], PinyinSearchNormalizer::expand($in));
    }

    /** @return array<string, array{0:string}> */
    public static function singleFormCases(): array {
        return [
            'plain surname' => ['Wang'],
            'western with lv+vowel' => ['Calvin'],  // lv 後接母音 i，非可轉音節
            'western v-start' => ['Vasco'],
            'chinese' => ['呂胤'],
            'numeric' => ['1762'],
        ];
    }
}
