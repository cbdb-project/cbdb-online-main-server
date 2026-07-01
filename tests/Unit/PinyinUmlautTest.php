<?php

namespace Tests\Unit;

use App\Support\PinyinUmlaut;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 止血 helper：漢語拼音 v → ü 正規化（依《漢語拼音方案》，僅 lv/lve/nv/nve）。
 */
class PinyinUmlautTest extends TestCase {
    /** @return array<string, array{0:string,1:string}> */
    public static function pinyinCases(): array {
        return [
            // 四種音節（小寫）
            'lv'  => ['lv', 'lü'],
            'lve' => ['lve', 'lüe'],
            'nv'  => ['nv', 'nü'],
            'nve' => ['nve', 'nüe'],
            // 大小寫保留
            'Lv title'  => ['Lv', 'Lü'],
            'Nv title'  => ['Nv', 'Nü'],
            'LV upper'  => ['LV', 'LÜ'],
            'Lve title' => ['Lve', 'Lüe'],
            // 邊界：結尾 / 空白 / 子音（連寫音節）
            'Yelv end'       => ['Yelv', 'Yelü'],
            'Lv space'       => ['Lv Yin', 'Lü Yin'],
            'Lvzhai cons'    => ['Lvzhai', 'Lüzhai'],
            'Yelv Xianzhong' => ['Yelv Xianzhong', 'Yelü Xianzhong'],
            'nv zhen'        => ['nv zhen', 'nü zhen'],
            // 多次出現
            'two hits' => ['Lv Lvqiu', 'Lü Lüqiu'],
        ];
    }

    #[Test]
    #[DataProvider('pinyinCases')]
    public function it_converts_pinyin_v_to_umlaut(string $input, string $expected): void {
        $this->assertSame($expected, PinyinUmlaut::normalize($input));
    }

    /** @return array<string, array{0:string}> */
    public static function westernAndUnaffectedCases(): array {
        return [
            // 西文名：l/n 後的 v 接母音 a/i/o/u，不應轉換
            'Silva'   => ['Silva'],
            'Calvin'  => ['Calvin'],
            'Melvin'  => ['Melvin'],
            'Sylvia'  => ['Sylvia'],
            'Galvao'  => ['Galvao'],
            'Vasco'   => ['Vasco'],   // 開頭的 v，非 l/n 之後
            'Verbiest' => ['Verbiest'],
            // 無 l/n 前綴的 v
            'David'   => ['David'],
            // 已是 ü（冪等）
            'lue done' => ['lüe'],
            'Yelu done' => ['Yelü'],
        ];
    }

    #[Test]
    #[DataProvider('westernAndUnaffectedCases')]
    public function it_leaves_western_and_already_correct_forms_unchanged(string $input): void {
        $this->assertSame($input, PinyinUmlaut::normalize($input));
    }

    #[Test]
    public function it_handles_null_and_empty(): void {
        $this->assertSame('', PinyinUmlaut::normalize(null));
        $this->assertSame('', PinyinUmlaut::normalize(''));
    }

    #[Test]
    public function it_is_idempotent(): void {
        $once = PinyinUmlaut::normalize('Lv Yelv nve Lvzhai');
        $this->assertSame($once, PinyinUmlaut::normalize($once));
    }
}
