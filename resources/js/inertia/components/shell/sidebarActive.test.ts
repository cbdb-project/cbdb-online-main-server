// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import type { NavNode } from '../../types/page';
import { buildActiveContext, isBranchActive, isSelfActive } from './sidebarActive';

/**
 * 側邊欄 active／展開判定測試。
 *
 * ── 2026-09-15（Blade 下架環節 4d-2）─────────────────────────────────────
 * 環節 4d-2 移除後端節點的 `active.pages`／`active.patterns` 時，一併刪掉了
 * `NavigationSchemaTest` 裡三條驗那組欄位的測試。那三條守的確實是已刪除的 Blade 機制，
 * **但 React 這側的判定（`sidebarActive.ts`）當時一條測試都沒有**——review 與 codex 都指出
 * 「換了層級所以還有覆蓋」是錯的陳述。這支測試就是補上那個缺口。
 *
 * 覆蓋三件事：① 葉節點精確比對（不要讓 /app/codes 在 /app/codes/XXX 上誤亮）；
 * ② 顯著 query 簽章（#1109：同路徑僅以 query 區分的兩項各自精確命中）；
 * ③ 父節點展開（自身命中／子孫命中／目前路徑落在其 href 之下）。
 */
function node(partial: Partial<NavNode> & { key: string }): NavNode {
    return {
        label: partial.key,
        icon: 'fas fa-circle',
        href: null,
        suffix: null,
        badge: null,
        children: [],
        ...partial,
    };
}

const NAV: NavNode[] = [
    node({ key: 'dashboard', href: '/app/dashboard' }),
    node({
        key: 'codes',
        href: '/app/codes',
        children: [
            node({ key: 'dynasties', href: '/app/codes/DYNASTIES' }),
            node({ key: 'office-codes', href: '/app/office' }),
        ],
    }),
    node({ key: 'operations', href: '/app/operations' }),
    node({ key: 'proposals', href: '/app/operations?proposals_only=1' }),
];

function ctxFor(url: string) {
    return buildActiveContext(NAV, url);
}

function find(key: string): NavNode {
    const walk = (list: NavNode[]): NavNode | null => {
        for (const n of list) {
            if (n.key === key) {
                return n;
            }
            const hit = walk(n.children);
            if (hit) {
                return hit;
            }
        }
        return null;
    };
    const hit = walk(NAV);
    if (!hit) {
        throw new Error(`測試資料裡沒有節點 ${key}`);
    }
    return hit;
}

describe('isSelfActive（葉節點精確比對）', () => {
    it('路徑相同才算命中', () => {
        expect(isSelfActive(find('dashboard'), ctxFor('/app/dashboard'))).toBe(true);
        expect(isSelfActive(find('dashboard'), ctxFor('/app/operations'))).toBe(false);
    });

    it('子路徑不讓父路徑的節點自身亮起來（/app/codes 在 /app/codes/DYNASTIES 上不自亮）', () => {
        const ctx = ctxFor('/app/codes/DYNASTIES');
        expect(isSelfActive(find('codes'), ctx)).toBe(false);
        expect(isSelfActive(find('dynasties'), ctx)).toBe(true);
    });

    it('尾斜線與 fragment 不影響比對', () => {
        expect(isSelfActive(find('dashboard'), ctxFor('/app/dashboard/'))).toBe(true);
        expect(isSelfActive(find('dashboard'), ctxFor('/app/dashboard#top'))).toBe(true);
    });

    it('沒有連結（href 為 null 或 #）的節點永遠不自亮', () => {
        const ctx = ctxFor('/app/dashboard');
        expect(isSelfActive(node({ key: 'x', href: null }), ctx)).toBe(false);
        expect(isSelfActive(node({ key: 'x', href: '#' }), ctx)).toBe(false);
    });
});

describe('顯著 query 簽章（#1109）', () => {
    it('同路徑、僅以 query 區分的兩項各自精確命中', () => {
        const plain = ctxFor('/app/operations');
        expect(isSelfActive(find('operations'), plain)).toBe(true);
        expect(isSelfActive(find('proposals'), plain)).toBe(false);

        const withQuery = ctxFor('/app/operations?proposals_only=1');
        expect(isSelfActive(find('operations'), withQuery)).toBe(false);
        expect(isSelfActive(find('proposals'), withQuery)).toBe(true);
    });

    it('未列入簽章的參數（分頁等）不影響 active', () => {
        const paged = ctxFor('/app/operations?page=3');
        expect(isSelfActive(find('operations'), paged)).toBe(true);
        expect(isSelfActive(find('proposals'), paged)).toBe(false);
    });

    it('簽章 key 只取「與目前路徑同路徑」的選單項所宣告的', () => {
        // proposals_only 是 /app/operations 那組宣告的；在 /app/dashboard 上帶同名參數
        // 不應該讓 dashboard 失去 active。
        expect(isSelfActive(find('dashboard'), ctxFor('/app/dashboard?proposals_only=1'))).toBe(true);
    });
});

describe('isBranchActive（父節點展開／區段高亮）', () => {
    it('子孫命中時父節點為 active', () => {
        expect(isBranchActive(find('codes'), ctxFor('/app/codes/DYNASTIES'))).toBe(true);
    });

    it('目前路徑落在父節點 href 之下時（即使無對應子節點）父節點仍為 active', () => {
        expect(isBranchActive(find('codes'), ctxFor('/app/codes/SOMETHING_ELSE'))).toBe(true);
    });

    it('子節點指向別的路徑時，該子節點命中一樣會展開父節點', () => {
        const ctx = ctxFor('/app/office');
        expect(isBranchActive(find('office-codes'), ctx)).toBe(true);
        expect(isBranchActive(find('codes'), ctx)).toBe(true);
    });

    it('不相干的路徑不展開', () => {
        expect(isBranchActive(find('codes'), ctxFor('/app/dashboard'))).toBe(false);
    });
});
