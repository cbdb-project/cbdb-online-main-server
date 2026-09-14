import { describe, expect, it } from 'vitest';

import { buildRowId, buildShowParams } from './showNavigation';

/**
 * 這兩組不變量原本由 legacy Blade 在伺服器端完成，由 `CodesControllerTest` 用
 * `assertSee` 驗；移植到 React 之後它們是前端邏輯，伺服器端只驗得到 prop
 * ⇒ 這支測試是它們現在唯一的守衛（Blade 下架環節 4b-2c-2）。
 */
describe('buildRowId', () => {
    it('單欄主鍵不可被組成複合鍵形式', () => {
        // 原 assertSee('/codes/TEXT_CODES/T001/edit') + assertDontSee('.../T001_._')
        // 驗的就是這件事：TEXT_CODES 的主鍵覆寫只有 c_textid 一欄。
        const row = { c_textid: 'T001', c_title: 'Sample', c_title_chn: '樣本' };

        expect(buildRowId(row, ['c_textid'])).toBe('T001');
    });

    it('複合主鍵依 key_columns 的順序以 _._ 串接', () => {
        const row = { code_id: 'A1', code_sub: 'X1', description: 'Alpha' };

        expect(buildRowId(row, ['code_id', 'code_sub'])).toBe('A1_._X1');
    });

    it('主鍵欄為空值時跳過該欄，不產生空段', () => {
        const row = { code_id: 'A1', code_sub: '', description: 'Alpha' };

        expect(buildRowId(row, ['code_id', 'code_sub'])).toBe('A1');
    });

    it('沒有主鍵資訊時退回前兩個非空欄（Blade 既有的退路）', () => {
        const row = { a: '', b: 'first', c: 'second', d: 'third' };

        expect(buildRowId(row, [])).toBe('first_._second');
    });

    it('0 是合法的鍵值，不可被當成空值丟掉', () => {
        // c_textid=0 是真實可編輯的「未知」書目列（見 CodesController::appEdit 的註解）。
        expect(buildRowId({ c_textid: 0 }, ['c_textid'])).toBe('0');
    });
});

describe('buildShowParams', () => {
    const base = {
        filters: {},
        search: '',
        sortBy: '',
        sortDir: 'asc',
        booleanEnabled: false,
    };

    it('空值一律不送（與 Blade 的 array_filter 同義）', () => {
        expect(buildShowParams(base)).toEqual({});
    });

    it('filters 裡的空字串欄位被丟掉，非空的留下', () => {
        const params = buildShowParams({ ...base, filters: { description: 'Beta', code_sub: '' } });

        expect(params.filters).toEqual({ description: 'Beta' });
    });

    it('sort_dir 只在有 sort_by 時才送', () => {
        expect(buildShowParams({ ...base, sortDir: 'desc' })).toEqual({});
        expect(buildShowParams({ ...base, sortBy: 'code_id', sortDir: 'desc' }))
            .toEqual({ sort_by: 'code_id', sort_dir: 'desc' });
    });

    it('布林模式開著時帶 filter_bool，關著時不帶（互動不會洗掉布林模式）', () => {
        expect(buildShowParams({ ...base, booleanEnabled: true })).toEqual({ filter_bool: 1 });
        expect(buildShowParams({ ...base, booleanEnabled: false })).toEqual({});
    });

    it('extra 會覆蓋同名參數，且可以用 undefined 刻意清掉 page', () => {
        const params = buildShowParams({ ...base, sortBy: 'code_id', extra: { page: undefined } });

        expect(params).toHaveProperty('page', undefined);
        expect(params.sort_by).toBe('code_id');
    });

    it('🔴 換頁帶 applied 時，被後端略過的壞欄位不會回灌進 URL', () => {
        // 這是環節 4b-2c-2 要修的那個 bug 的前端半邊：`code_sub: 'X1 AND'` 非空，
        // 舊寫法（拿輸入框的 filters）會把它帶進 URL ⇒ 壞掉的條件黏在網址上、
        // 每次換頁再報一次錯。呼叫端改帶 applied_filters 之後就不會。
        const rawInput = { description: 'Beta', code_sub: 'X1 AND' };
        const applied = { description: 'Beta' };

        expect(buildShowParams({ ...base, filters: rawInput, booleanEnabled: true, extra: { page: 2 } }).filters)
            .toEqual(rawInput);
        expect(buildShowParams({ ...base, filters: applied, booleanEnabled: true, extra: { page: 2 } }).filters)
            .toEqual({ description: 'Beta' });
    });

    it('🔴 排序／換頁帶已送出的 search，不帶打到一半的字', () => {
        // 拿未送出的搜尋字去排序／換頁，會把它一併套用，而且還帶著另一個結果集的 page=N。
        const submitted = 'Beta';
        const halfTyped = 'Bet';

        expect(buildShowParams({ ...base, search: submitted, extra: { page: 3 } }).search).toBe('Beta');
        expect(buildShowParams({ ...base, search: halfTyped, extra: { page: 3 } }).search).toBe('Bet');
    });
});
