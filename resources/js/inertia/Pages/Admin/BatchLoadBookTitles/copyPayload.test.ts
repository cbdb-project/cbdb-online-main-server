import { describe, expect, it } from 'vitest';

import { buildCopyPayload } from './copyPayload';

/**
 * 這個格式契約原本由 legacy Blade 在伺服器端拼好，`AdminBatchLoadBookTitlesTest` 用
 * `assertSee("801\t某某書")` 釘住。移植到 React 之後拼接是前端的事，伺服器端只驗得到
 * 「原料有傳下去」⇒ 這支測試是它現在唯一的守衛（Blade 下架環節 4b-3）。
 */
describe('buildCopyPayload', () => {
    it('單列：textid、TAB、書名', () => {
        expect(buildCopyPayload([{ c_textid: 801, title: '某某書' }])).toBe('801\t某某書');
    });

    it('多列以換行串接，順序照結果列', () => {
        const payload = buildCopyPayload([
            { c_textid: 801, title: '某某書' },
            { c_textid: 802, title: '另一書' },
        ]);

        expect(payload).toBe('801\t某某書\n802\t另一書');
    });

    it('欄序是 textid 在前、書名在後（貼進 Excel 的欄位順序）', () => {
        const payload = buildCopyPayload([{ c_textid: 1, title: 'A' }]);

        expect(payload.indexOf('1')).toBeLessThan(payload.indexOf('A'));
        expect(payload.split('\t')).toHaveLength(2);
    });

    it('分隔符是 TAB 不是逗號或空白——改掉會讓 Excel 併成一欄', () => {
        const payload = buildCopyPayload([{ c_textid: 1, title: 'A' }]);

        expect(payload).toContain('\t');
        expect(payload).not.toContain(', ');
    });

    it('空結果回空字串（不要冒出一個空行）', () => {
        expect(buildCopyPayload([])).toBe('');
    });

    it('書名含空白或標點原樣保留，不做任何清洗', () => {
        expect(buildCopyPayload([{ c_textid: 7, title: '測試稿: 卷一' }])).toBe('7\t測試稿: 卷一');
    });
});
