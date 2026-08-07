import { describe, it, expect } from 'vitest';
import {
    activationKeyOf,
    applyError,
    applySuccess,
    beginActivation,
    dropTab,
    TABS_REQUIRING_FRESH_DATA,
    type TabCache,
} from './tabCachePolicy';

/**
 * 回歸鎖：切分頁必須重新向後端取資料。
 *
 * 原本的錯誤行為是「分頁資料一載入就永久快取」——切走再切回沿用首次載入的快照，
 * 於是他人（或自己在另一個瀏覽器分頁）的新增／刪除／修改在切分頁時完全看不到。
 * 這組測試鎖住修復後的語義，避免日後有人為了「少發幾個請求」把快取短路加回來。
 */
describe('tabCachePolicy', () => {
    const EP = '/app/basicinformation/__PERSON_ID__/tabs/__TAB_KEY__';
    const A = activationKeyOf(11, 'alt_names', 0, EP);
    const A2 = activationKeyOf(11, 'alt_names', 1, EP);
    const cacheWith = (entries: TabCache): TabCache => ({ ...entries });
    const entry = (data: unknown, activation: string, over: Partial<TabCache[string]> = {}) => ({
        loading: false, error: null, data, activation, ...over,
    });

    describe('activationKeyOf', () => {
        it('分頁改變即為新啟用（→ 必須重新抓資料）', () => {
            expect(activationKeyOf(11, 'alt_names', 0, EP)).not.toBe(activationKeyOf(11, 'kinship', 0, EP));
        });

        it('切走再切回同一分頁也是新啟用：key 由當前分頁決定，重新啟用就會重新抓', () => {
            const first = activationKeyOf(11, 'alt_names', 0, EP);
            const away = activationKeyOf(11, 'kinship', 0, EP);
            const back = activationKeyOf(11, 'alt_names', 0, EP);
            expect(away).not.toBe(first);
            // 切回時 key 由 kinship 變回 alt_names，對 effect 而言依賴已改變 → 重跑 → 重新抓。
            expect(back).not.toBe(away);
        });

        it('人物改變即為新啟用', () => {
            expect(activationKeyOf(11, 'alt_names', 0, EP)).not.toBe(activationKeyOf(12, 'alt_names', 0, EP));
        });

        it('重載序號改變即為新啟用（重試／新增刪除後）', () => {
            expect(activationKeyOf(11, 'alt_names', 0, EP)).not.toBe(activationKeyOf(11, 'alt_names', 1, EP));
        });

        it('personId 為 null 時不會與 personId 為 0 的人物撞 key', () => {
            expect(activationKeyOf(null, 'alt_names', 0, EP)).not.toBe(activationKeyOf(0, 'alt_names', 0, EP));
        });

        // 抓取 effect 的依賴是 [personId, activeTab, tabEndpoint, fetchSeq]——key 必須涵蓋全部四項。
        // 少涵蓋任一項，effect 會重跑卻沿用同一戳記：兩個請求共用戳記、舊回應可蓋掉新回應，
        // 且不重新標記啟用（basic_info 會在編輯器仍掛載時被塞新資料、畫面停在舊快照）。
        it('資料端點改變即為新啟用（key 必須涵蓋抓取 effect 的每一項依賴）', () => {
            expect(activationKeyOf(11, 'alt_names', 0, EP)).not.toBe(activationKeyOf(11, 'alt_names', 0, `${EP}?v=2`));
        });
    });

    describe('beginActivation', () => {
        it('首次啟用（無快取）→ 顯示載入佔位', () => {
            const next = beginActivation({}, 'alt_names', A);
            expect(next.alt_names).toEqual({ loading: true, error: null, data: null, activation: A });
        });

        it('已有資料的列表分頁 → 先顯示舊資料、背景重新驗證（不閃載入佔位）', () => {
            const prev = cacheWith({ alt_names: entry({ items: [1] }, A) });
            const next = beginActivation(prev, 'alt_names', A2);
            expect(next.alt_names.loading).toBe(false);
            expect(next.alt_names.data).toEqual({ items: [1] });
        });

        it('重新蓋上本輪的 activation 戳記（舊戳記的回應之後會被丟棄）', () => {
            const prev = cacheWith({ alt_names: entry({ items: [1] }, A) });
            expect(beginActivation(prev, 'alt_names', A2).alt_names.activation).toBe(A2);
        });

        it('basic_info 即使已有資料也一律等新資料才渲染（編輯器在掛載時快照 initialFields）', () => {
            const prev = cacheWith({ basic_info: entry({ form: { fields: {} } }, A) });
            const next = beginActivation(prev, 'basic_info', A2);
            expect(next.basic_info).toEqual({ loading: true, error: null, data: null, activation: A2 });
        });

        it('TABS_REQUIRING_FRESH_DATA 就是 basic_info（改動需連帶檢查編輯器掛載語義）', () => {
            expect([...TABS_REQUIRING_FRESH_DATA]).toEqual(['basic_info']);
        });

        it('上一輪失敗（data 為 null）→ 重新顯示載入佔位', () => {
            const prev = cacheWith({ alt_names: entry(null, A, { error: 'HTTP 500' }) });
            const next = beginActivation(prev, 'alt_names', A2);
            expect(next.alt_names).toEqual({ loading: true, error: null, data: null, activation: A2 });
        });

        it('清掉上一輪的錯誤：這次啟用會重新抓，成敗由這次結果決定', () => {
            const prev = cacheWith({ alt_names: entry({ items: [] }, A, { error: 'HTTP 500' }) });
            expect(beginActivation(prev, 'alt_names', A2).alt_names.error).toBeNull();
        });

        it('不動其他分頁的快取', () => {
            const prev = cacheWith({
                alt_names: entry({ items: [1] }, A),
                kinship: entry({ items: [2] }, A),
            });
            expect(beginActivation(prev, 'alt_names', A2).kinship).toBe(prev.kinship);
        });

        it('不就地修改傳入的快取', () => {
            const prev = cacheWith({ alt_names: entry({ items: [1] }, A) });
            const snapshot = JSON.stringify(prev);
            beginActivation(prev, 'alt_names', A2);
            expect(JSON.stringify(prev)).toBe(snapshot);
        });
    });

    describe('applySuccess', () => {
        it('以新資料取代先前顯示的舊資料', () => {
            const prev = cacheWith({ alt_names: entry({ items: ['舊'] }, A) });
            const next = applySuccess(prev, 'alt_names', A, { items: ['新'] });
            expect(next.alt_names).toEqual({ loading: false, error: null, data: { items: ['新'] }, activation: A });
        });

        it('activation 已被新啟用換掉 → 丟棄這個回應（原封不動回傳）', () => {
            const prev = cacheWith({ alt_names: entry({ items: ['新一輪'] }, A2) });
            expect(applySuccess(prev, 'alt_names', A, { items: ['遲到的舊回應'] })).toBe(prev);
        });

        it('分頁已被移除（換人物／重載）→ 丟棄這個回應', () => {
            expect(applySuccess({}, 'alt_names', A, { items: [1] })).toEqual({});
        });
    });

    describe('applyError', () => {
        it('顯示錯誤且不留舊資料——不讓使用者在不知情下對著舊資料編輯', () => {
            const prev = cacheWith({ alt_names: entry({ items: ['舊'] }, A, { loading: true }) });
            const next = applyError(prev, 'alt_names', A, 'HTTP 500');
            expect(next.alt_names).toEqual({ loading: false, error: 'HTTP 500', data: null, activation: A });
        });

        it('activation 已被新啟用換掉 → 不可把新一輪的畫面打成錯誤態', () => {
            const prev = cacheWith({ alt_names: entry({ items: ['新一輪'] }, A2) });
            expect(applyError(prev, 'alt_names', A, 'HTTP 500')).toBe(prev);
        });
    });

    describe('dropTab', () => {
        it('只移除指定分頁', () => {
            const prev = cacheWith({
                alt_names: entry({ items: [1] }, A),
                kinship: entry({ items: [2] }, A),
            });
            const next = dropTab(prev, 'alt_names');
            expect('alt_names' in next).toBe(false);
            expect(next.kinship).toBe(prev.kinship);
        });

        it('分頁不存在時回傳同一個物件（避免無謂的 re-render）', () => {
            const prev = cacheWith({ kinship: entry(null, A) });
            expect(dropTab(prev, 'alt_names')).toBe(prev);
        });

        it('移除後，該分頁在途中的回應會被丟棄（戳記不再存在）', () => {
            const prev = cacheWith({ alt_names: entry({ items: [1] }, A) });
            const dropped = dropTab(prev, 'alt_names');
            expect(applySuccess(dropped, 'alt_names', A, { items: ['遲到'] })).toBe(dropped);
        });
    });
});
