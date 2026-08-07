/**
 * 分頁資料快取策略（TabContentLoader 用）。
 *
 * 背景：原本 13 個子資源分頁的資料「一載入就永久快取」——切走再切回不再向後端要資料。
 * 結果是其他人（或自己在另一個瀏覽器分頁）的新增／刪除／修改，在不整頁重載的情況下永遠看不到；
 * 分頁徽章計數同樣停在頁面初載的數字。
 *
 * 現行策略：每次「分頁啟用」（personId／activeTab／重載序號任一改變）都重新向後端取資料。
 * 已有舊資料的分頁在重新驗證期間先繼續顯示舊資料（stale-while-revalidate），避免高頻切分頁時
 * 每次都閃一下載入佔位（子資源端點約 200ms）；`basic_info` 例外，見 TABS_REQUIRING_FRESH_DATA。
 *
 * 這裡刻意寫成純函式：切分頁是否重新抓資料、哪些分頁不可先用舊資料渲染、哪些回應該被丟棄，
 * 是這次修復的核心語義，由 tabCachePolicy.test.ts 直接鎖住。
 */

export interface TabState {
    /** 目前沒有可顯示的資料、正在抓取 → 顯示載入佔位。 */
    loading: boolean;
    error: string | null;
    data: unknown;
    /**
     * 產生這筆狀態的啟用識別碼（見 activationKeyOf）。
     * 回應落庫前比對此欄，把「已被新啟用取代」的回應丟掉——快取只以分頁為鍵，而 abort 發生在
     * effect cleanup（commit 之後），若回應剛好落在「新一輪已 commit、cleanup 尚未跑」的空隙，
     * 就會把上一個人物／上一輪的資料寫進當前分頁。比對放在 updater 內、對committed state 進行，
     * 因此不需要在 render 期間寫 ref（那會被丟棄的並行 render 汙染）。
     */
    activation: string;
}

export type TabCache = Record<string, TabState>;

/**
 * 重新驗證期間「不可先用舊資料渲染」的分頁。
 *
 * `basic_info` 的內容是 BasicInfoEditor——它在掛載時把 initialFields 快照進自己的 state，
 * 之後不再跟著 props 同步。若先用舊資料掛載、稍後才把新資料寫進快取，編輯器會永遠停在舊值，
 * 而且按下「直接保存」會把舊值整包寫回、覆蓋他人剛才的修改。故此分頁一律等新資料到齊才渲染。
 */
export const TABS_REQUIRING_FRESH_DATA: ReadonlySet<string> = new Set(['basic_info']);

/**
 * 一次分頁啟用的起始狀態。
 * 可沿用舊資料者不進 loading（先顯示舊資料，等新資料覆蓋）；否則顯示載入佔位。
 * 一律清掉上一輪的錯誤——這次啟用會重新抓一次，成敗由這次的結果決定。
 */
export function beginActivation(prev: TabCache, tabKey: string, activation: string): TabCache {
    const existing = prev[tabKey];
    const showStaleWhileRevalidating = existing?.data != null && !TABS_REQUIRING_FRESH_DATA.has(tabKey);

    return {
        ...prev,
        [tabKey]: {
            loading: !showStaleWhileRevalidating,
            error: null,
            data: showStaleWhileRevalidating ? existing.data : null,
            activation,
        },
    };
}

/** 抓取成功：以新資料取代（含先前顯示的舊資料）。已被新啟用取代則原封不動。 */
export function applySuccess(prev: TabCache, tabKey: string, activation: string, data: unknown): TabCache {
    if (prev[tabKey]?.activation !== activation) {
        return prev;
    }

    return { ...prev, [tabKey]: { loading: false, error: null, data, activation } };
}

/**
 * 抓取失敗：顯示錯誤 + 重新載入按鈕，不留舊資料。已被新啟用取代則原封不動。
 * 寧可讓使用者看到「載入失敗」，也不要讓他在不知情的狀況下對著舊資料編輯。
 */
export function applyError(prev: TabCache, tabKey: string, activation: string, message: string): TabCache {
    if (prev[tabKey]?.activation !== activation) {
        return prev;
    }

    return { ...prev, [tabKey]: { loading: false, error: message, data: null, activation } };
}

/** 明確重載（重試、或列表內新增／刪除成功後）：丟掉該分頁舊資料，下一次啟用會顯示載入佔位。 */
export function dropTab(prev: TabCache, tabKey: string): TabCache {
    if (!(tabKey in prev)) {
        return prev;
    }
    const next = { ...prev };
    delete next[tabKey];

    return next;
}

/**
 * 分頁啟用識別碼：personId／分頁／重載序號／資料端點任一改變即為一次新啟用，必須重新抓資料。
 * 「切分頁要重新抓資料」這條語義就落在這個 key 上。
 *
 * 這四個參數必須與 TabContentLoader 抓取 effect 的依賴**完全一致**。少放一個（例如 tabEndpoint）
 * 會讓 effect 重跑卻沿用同一個 activation 戳記：兩個請求共用戳記，先發後到的舊回應就會蓋掉新回應，
 * 而且不會重新標記啟用（basic_info 因此可能在編輯器仍掛載時被塞進新資料，顯示的仍是舊快照）。
 */
export function activationKeyOf(
    personId: number | null,
    activeTab: string,
    fetchSeq: number,
    tabEndpoint: string,
): string {
    return `${personId ?? ''}|${activeTab}|${fetchSeq}|${tabEndpoint}`;
}
