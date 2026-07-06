import { describe, it, expect } from 'vitest';
import { detectUmlautConversions, applyUmlaut } from './pinyinUmlaut';

/**
 * 標準樣本（input → expected）——**必須與後端 tests/Unit/PinyinUmlautTest.php 的
 * canonicalFixtures() 完全一致**。任一端修改都要同步另一端；此組樣本即前後端規則的
 * 位元一致契約（設計 §7）。
 */
const CANONICAL: Array<[string, string]> = [
    // 基本四音節 + 大小寫（ü 大小寫只由被匹配的 v 決定，聲母原樣保留）
    ['Lv', 'Lü'],
    ['lv', 'lü'],
    ['LV', 'LÜ'],
    ['lV', 'lÜ'],
    ['Nv', 'Nü'],
    ['Lve', 'Lüe'],
    ['nve', 'nüe'],
    // 連寫 / 帶邊界
    ['Yelv', 'Yelü'],
    ['Lv Meng', 'Lü Meng'],
    ['Lvzhai', 'Lüzhai'],
    // 西文名：lv/nv 後接母音 a/i/o/u → 不轉
    ['Silva', 'Silva'],
    ['Calvin', 'Calvin'],
    ['Melville', 'Melville'],
    ['Sylvia', 'Sylvia'],
    // v 不在 l/n 之後 → 完全不碰
    ['David', 'David'],
    ['Vasco', 'Vasco'],
    // 西文名踩到 nve/lve（後非 a/i/o/u）會被規則命中——正是需 Tier 2 由人判定之例
    ['Denver', 'Denüer'],
    // 冪等 / 空字串
    ['Lü', 'Lü'],
    ['', ''],
];

describe('applyUmlaut — 與後端規則位元一致', () => {
    it.each(CANONICAL)('applyUmlaut(%j) === %j', (input, expected) => {
        expect(applyUmlaut(input)).toBe(expected);
    });

    it('null / undefined 原樣返回空字串', () => {
        expect(applyUmlaut(null)).toBe('');
        expect(applyUmlaut(undefined)).toBe('');
    });

    it('冪等：對已轉換結果再套用不變', () => {
        for (const [input] of CANONICAL) {
            const once = applyUmlaut(input);
            expect(applyUmlaut(once)).toBe(once);
        }
    });
});

describe('detectUmlautConversions — 只在規則命中時回傳', () => {
    it('命中回傳 from/to/index', () => {
        expect(detectUmlautConversions('Lv Meng')).toEqual([{ from: 'Lv', to: 'Lü', index: 0 }]);
        expect(detectUmlautConversions('Denver')).toEqual([{ from: 'nv', to: 'nü', index: 2 }]);
    });

    it('多處命中全部列出', () => {
        expect(detectUmlautConversions('Lv Nv')).toEqual([
            { from: 'Lv', to: 'Lü', index: 0 },
            { from: 'Nv', to: 'Nü', index: 3 },
        ]);
    });

    it('西文名 / 非 l-n 的 v / 空值 → 無命中', () => {
        for (const s of ['Silva', 'Calvin', 'Melville', 'Sylvia', 'David', 'Vasco', 'Lü', '']) {
            expect(detectUmlautConversions(s)).toEqual([]);
        }
        expect(detectUmlautConversions(null)).toEqual([]);
        expect(detectUmlautConversions(undefined)).toEqual([]);
    });

    it('多次呼叫無 regex lastIndex 殘留', () => {
        expect(detectUmlautConversions('Lv')).toHaveLength(1);
        expect(detectUmlautConversions('Lv')).toHaveLength(1);
    });
});
