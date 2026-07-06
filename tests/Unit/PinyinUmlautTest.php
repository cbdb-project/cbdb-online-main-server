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
            'lv' => ['lv', 'lü'],
            'lve' => ['lve', 'lüe'],
            'nv' => ['nv', 'nü'],
            'nve' => ['nve', 'nüe'],
            // 大小寫保留
            'Lv title' => ['Lv', 'Lü'],
            'Nv title' => ['Nv', 'Nü'],
            'LV upper' => ['LV', 'LÜ'],
            'Lve title' => ['Lve', 'Lüe'],
            // 邊界：結尾 / 空白 / 子音（連寫音節）
            'Yelv end' => ['Yelv', 'Yelü'],
            'Lv space' => ['Lv Yin', 'Lü Yin'],
            'Lvzhai cons' => ['Lvzhai', 'Lüzhai'],
            'Yelv Xianzhong' => ['Yelv Xianzhong', 'Yelü Xianzhong'],
            'nv zhen' => ['nv zhen', 'nü zhen'],
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
            'Silva' => ['Silva'],
            'Calvin' => ['Calvin'],
            'Melvin' => ['Melvin'],
            'Sylvia' => ['Sylvia'],
            'Galvao' => ['Galvao'],
            'Vasco' => ['Vasco'],   // 開頭的 v，非 l/n 之後
            'Verbiest' => ['Verbiest'],
            // 無 l/n 前綴的 v
            'David' => ['David'],
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

    /**
     * 標準樣本（input → expected）——**必須與前端 resources/js/inertia/utils/pinyinUmlaut.test.ts 的
     * CANONICAL 完全一致**。此組樣本即前後端規則的位元一致契約（設計 §7）；任一端修改都要同步另一端。
     *
     * @return array<int, array{0:string,1:string}>
     */
    public static function canonicalFixtures(): array {
        return [
            ['Lv', 'Lü'],
            ['lv', 'lü'],
            ['LV', 'LÜ'],
            ['lV', 'lÜ'],
            ['Nv', 'Nü'],
            ['Lve', 'Lüe'],
            ['nve', 'nüe'],
            ['Yelv', 'Yelü'],
            ['Lv Meng', 'Lü Meng'],
            ['Lvzhai', 'Lüzhai'],
            ['Silva', 'Silva'],
            ['Calvin', 'Calvin'],
            ['Melville', 'Melville'],
            ['Sylvia', 'Sylvia'],
            ['David', 'David'],
            ['Vasco', 'Vasco'],
            ['Denver', 'Denüer'],
            ['Lü', 'Lü'],
            ['', ''],
        ];
    }

    #[Test]
    #[DataProvider('canonicalFixtures')]
    public function it_matches_the_shared_frontend_backend_contract(string $input, string $expected): void {
        $this->assertSame($expected, PinyinUmlaut::normalize($input));
    }

    #[Test]
    public function normalize_fields_only_touches_biog_pinyin_columns(): void {
        $data = [
            'c_surname' => 'Lv',
            'c_mingzi' => 'Meng',
            'c_name' => 'Lv Meng',
            'c_surname_rm' => 'Lv',       // Wade-Giles：不可轉
            'c_surname_proper' => 'Silva', // 母語拉丁名：不可轉（且本就 no-op）
            'c_name_proper' => 'Denver',   // 母語拉丁名：即使踩 nve 亦不可轉
            'c_surname_chn' => '呂',        // 中文：不轉
        ];
        $out = PinyinUmlaut::normalizeFields($data, PinyinUmlaut::BIOG_MAIN_PINYIN_V_FIELDS);

        $this->assertSame('Lü', $out['c_surname']);
        $this->assertSame('Meng', $out['c_mingzi']);
        $this->assertSame('Lü Meng', $out['c_name']);
        // 排除欄一律原樣
        $this->assertSame('Lv', $out['c_surname_rm']);
        $this->assertSame('Silva', $out['c_surname_proper']);
        $this->assertSame('Denver', $out['c_name_proper']);
        $this->assertSame('呂', $out['c_surname_chn']);
    }

    #[Test]
    public function normalize_fields_altname_covers_pinyin_columns_but_not_c_alt_name(): void {
        $data = [
            'c_alt_name' => 'Lv Meng',     // 走前端 Tier 2，後端不在此轉
            'c_alt_name_pinyin' => 'lv',
            'c_alt_name_pinyin2' => 'Nve',
            'c_alt_name_pinyin3' => 'Silva', // 西文：no-op
            'c_alt_name_chn' => '呂蒙',       // 中文：不轉
        ];
        $out = PinyinUmlaut::normalizeFields($data, PinyinUmlaut::ALTNAME_PINYIN_V_FIELDS);

        $this->assertSame('Lv Meng', $out['c_alt_name']); // 後端刻意不轉
        $this->assertSame('lü', $out['c_alt_name_pinyin']);
        $this->assertSame('Nüe', $out['c_alt_name_pinyin2']);
        $this->assertSame('Silva', $out['c_alt_name_pinyin3']);
        $this->assertSame('呂蒙', $out['c_alt_name_chn']);
    }

    #[Test]
    public function normalize_fields_skips_missing_null_and_non_string(): void {
        $data = ['c_mingzi' => null, 'c_name' => 123, 'other' => 'Lv'];
        $out = PinyinUmlaut::normalizeFields($data, PinyinUmlaut::BIOG_MAIN_PINYIN_V_FIELDS);

        $this->assertNull($out['c_mingzi']);       // null 略過
        $this->assertSame(123, $out['c_name']);    // 非字串略過
        $this->assertSame('Lv', $out['other']);    // 不在 allowlist、不動
        $this->assertArrayNotHasKey('c_surname', $out); // 缺欄不新增
    }
}
