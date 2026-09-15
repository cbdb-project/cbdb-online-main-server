/**
 * 側邊欄 active／展開狀態的判定邏輯（純函式，與 React 元件分離以便測試）。
 *
 * ── 2026-09-15（Blade 下架環節 4d-2）─────────────────────────────────────
 * 這整段原本內嵌在 `SidebarNode.tsx` 裡。環節 4d-2 移除後端節點的
 * `active.pages`／`active.patterns`（那組只服務已刪除的 Blade sidebar）之後，
 * **這裡成為 active 判定的唯一來源**——而它當時一條測試都沒有（codex review 指出）。
 * 抽成獨立模組是為了讓 `sidebarActive.test.ts` 能直接測，而不需要渲染元件。
 *
 * 判定方式：href 的 pathname 精確比對 + 「顯著 query 簽章」；父節點另看祖先前綴。
 * 後端不再參與（見 `app/Support/Navigation.php` 的類別註解）。
 */
import type { NavNode } from '../../types/page';

/** active 比對上下文：目前路徑、目前 URL 的「顯著 query 簽章」、以及此路徑上有意義的 query key。 */
export interface ActiveContext {
    currentPath: string;
    currentSig: string;
    /** 僅「與 currentPath 同路徑的選單項」所使用的 query key；用於區分同路徑但不同 query 的選單項。 */
    sigKeys: string[];
}

/** 取 href 的 pathname（去掉 origin 與 query），用於 active 比對。 */
function pathOf(href: string | null): string | null {
    if (!href || href === '#') {
        return null;
    }
    try {
        // href 可能是相對或絕對；以目前 origin 解析。
        return new URL(href, window.location.origin).pathname.replace(/\/$/, '') || '/';
    } catch {
        return href;
    }
}

/** 取 href 的 query 字串（不含開頭的 '?'）。 */
function searchOf(href: string | null): string {
    if (!href || href === '#') {
        return '';
    }
    try {
        return new URL(href, window.location.origin).search.replace(/^\?/, '');
    } catch {
        const i = href.indexOf('?');
        return i >= 0 ? href.slice(i + 1) : '';
    }
}

function normalize(path: string): string {
    return path.replace(/\/$/, '') || '/';
}

/**
 * 只保留指定的 query key，組成可比對的正規化簽章（key 排序後 key=value 串接）。
 *
 * 忽略分頁／篩選等未列入 keys 的參數（如 page、editor、op_type），避免細項頁改變
 * 分頁時 active 消失；同時讓「僅以 query 區分的同路徑選單項」（如 /app/operations 與
 * /app/operations?proposals_only=1）各自精確命中，不再同時高亮（#1109）。
 */
function querySignature(search: string, keys: string[]): string {
    if (keys.length === 0) {
        return '';
    }
    const params = new URLSearchParams(search);
    const parts: string[] = [];
    for (const key of [...keys].sort()) {
        if (params.has(key)) {
            parts.push(key + '=' + (params.get(key) ?? ''));
        }
    }
    return parts.join('&');
}

/**
 * 蒐集「與 currentPath 同路徑」的選單項所使用的 query key。
 *
 * 只取同路徑的 key（而非整棵樹），避免把某頁的 query key 變成所有路徑的 active
 * 比對條件——否則未來別的選單項引入同名參數，可能讓不相關的頁面在帶上該參數時反而不高亮。
 *
 * 設計前提：active 比對本以「pathname + 顯著 query」進行，因此「共用同一 pathname 的選單項」
 * 天生就是同一個需要以 query 區分的群組（目前僅 operations / proposals 這組）；各項只會用
 * 自己宣告的 key 產生簽章，故同路徑多項可各自精確命中。跨區段若未來共用同一 pathname，
 * 仍屬同一群組、行為一致。
 */
function collectQueryKeysForPath(nodes: NavNode[], currentPath: string): string[] {
    const acc = new Set<string>();
    const walk = (list: NavNode[]) => {
        for (const node of list) {
            if (pathOf(node.href) === currentPath) {
                const s = searchOf(node.href);
                if (s) {
                    new URLSearchParams(s).forEach((_value, key) => acc.add(key));
                }
            }
            if (node.children.length) {
                walk(node.children);
            }
        }
    };
    walk(nodes);

    return [...acc];
}

/** 依目前 page.url 與 nav 樹建立 active 比對上下文。 */
export function buildActiveContext(nav: NavNode[], pageUrl: string): ActiveContext {
    let currentPath: string;
    let currentSearch: string;
    try {
        // page.url 通常為相對路徑（含 query），以目前 origin 解析可穩健處理 fragment / 編碼。
        const u = new URL(pageUrl, window.location.origin);
        currentPath = normalize(u.pathname);
        currentSearch = u.search.replace(/^\?/, '');
    } catch {
        const [pathPart, searchPart = ''] = pageUrl.split('?');
        currentPath = normalize(pathPart || '/');
        currentSearch = searchPart;
    }

    const sigKeys = collectQueryKeysForPath(nav, currentPath);
    const currentSig = querySignature(currentSearch, sigKeys);

    return { currentPath, currentSig, sigKeys };
}

/**
 * 節點自身是否對應目前路徑。葉節點採精確比對（避免 /codes 在 /codes/X 上誤亮），
 * 並比對「顯著 query 簽章」，讓僅以 query 區分的同路徑選單項各自精確命中（#1109）。
 *
 * 註：React 端以 href 路徑 + 顯著 query 判定 active。後端 nav 節點原本另帶一組
 * `active.patterns`（route 名稱 glob，僅供 Blade 以 `request()->routeIs()` 使用；React 無
 * route 名稱對照表，見遷移計畫附錄 D 決策記錄）——**該欄位已於 Blade 下架環節 4d-2 移除**，
 * 這裡的判定是唯一的 active 來源。覆蓋見 `sidebarActive.test.ts`。
 */
export function isSelfActive(node: NavNode, ctx: ActiveContext): boolean {
    const p = pathOf(node.href);
    if (p === null || p !== ctx.currentPath) {
        return false;
    }

    return querySignature(searchOf(node.href), ctx.sigKeys) === ctx.currentSig;
}

/** 父節點是否落在目前路徑的祖先（href 為 currentPath 的前綴段；query 不影響祖先判定）。 */
export function isAncestorOf(node: NavNode, ctx: ActiveContext): boolean {
    const p = pathOf(node.href);
    return p !== null && p !== '/' && (ctx.currentPath === p || ctx.currentPath.startsWith(p + '/'));
}

/**
 * 節點是否應高亮 / 父節點是否應展開：自身精確命中，或任一子孫命中，
 * 或（針對有子節點的區段）目前路徑落在其 href 之下（細項頁高亮其區段）。
 */
export function isBranchActive(node: NavNode, ctx: ActiveContext): boolean {
    if (isSelfActive(node, ctx)) {
        return true;
    }
    if (node.children.length > 0 && isAncestorOf(node, ctx)) {
        return true;
    }
    return node.children.some((child) => isBranchActive(child, ctx));
}
