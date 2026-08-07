import { useCallback, useRef, useState } from 'react';

/**
 * TEXT_INSTANCE_DATA 的「Load Data」：依 c_textid 從 TEXT_CODES 帶入 c_instance_title_chn／c_instance_title。
 *
 * 對齊舊版 codes/edit.blade.php 的按鈕，但修掉舊版兩件事：
 *   1. 舊版打 /api/select/search/text（`c_title_chn LIKE %q% OR c_textid = q`）再取 data[0]，
 *      用 ID 查時可能撈到「標題剛好含這串數字」的別本書。改打主鍵精確查詢端點。
 *   2. 舊版無條件覆寫兩個書名欄。改為**只填空欄**（使用者決定），不蓋掉人工修訂過的書名。
 */

/** 目標欄位：值為空字串／空白時視為「空欄」。 */
const TARGET_FIELDS = ['c_instance_title_chn', 'c_instance_title'] as const;

/** 書名來源欄 → 表單目標欄。 */
const SOURCE_OF: Record<string, 'c_title_chn' | 'c_title'> = {
    c_instance_title_chn: 'c_title_chn',
    c_instance_title: 'c_title',
};

interface Options {
    /** 後端提供的端點模板，含 __TEXT_ID__ 佔位。 */
    endpoint: string;
    /** 讀取當前表單值。 */
    getField: (column: string) => string;
    /** 寫入表單值。 */
    setField: (column: string, value: string) => void;
    /** 訊息文案（已翻譯）。 */
    t: (key: string, replace?: Record<string, string>) => string;
}

export interface LoadTextTitleState {
    pending: boolean;
    /** 提示訊息（成功或失敗皆用此欄；failed 決定顏色）。 */
    message: string | null;
    failed: boolean;
    /** 剛被帶入的欄位，供 UI 標黃底。 */
    filled: string[];
}

const EMPTY: LoadTextTitleState = { pending: false, message: null, failed: false, filled: [] };

export function useLoadTextTitle({ endpoint, getField, setField, t }: Options) {
    const [state, setState] = useState<LoadTextTitleState>(EMPTY);
    // 只有「最後一次啟動的請求」可以寫狀態。涵蓋成功／404／例外三條出口——
    // 只在成功路徑檢查是不夠的：舊請求的 404 會蓋掉新畫面（顯示「找不到 A」而欄位已是 B）。
    const runSeqRef = useRef(0);

    /**
     * 使用者一動表單就把上次的結果訊息與黃底清掉（那是對「上一次動作」的描述）。
     * 刻意保留 pending：否則請求進行中改欄位會把按鈕重新啟用，讓兩個請求交錯。
     */
    const reset = useCallback(() => {
        setState((prev) => (prev.message === null && prev.filled.length === 0
            ? prev
            : { ...EMPTY, pending: prev.pending }));
    }, []);

    const run = useCallback(async () => {
        const textId = (getField('c_textid') ?? '').trim();
        if (!/^\d+$/.test(textId)) {
            setState({ ...EMPTY, message: t('load_text_title_need_textid'), failed: true });

            return;
        }

        if (TARGET_FIELDS.every((f) => (getField(f) ?? '').trim() !== '')) {
            setState({ ...EMPTY, message: t('load_text_title_all_present') });

            return;
        }

        const seq = ++runSeqRef.current;
        // 這份回應是否還該影響畫面：必須是最後一次啟動的請求，且 c_textid 未被改掉。
        // 三條出口（成功／404／例外）都要過這道閘——只擋成功路徑的話，舊請求的 404
        // 會蓋掉新畫面（顯示「找不到 A」而欄位已經是 B）。
        const isCurrent = () => runSeqRef.current === seq && (getField('c_textid') ?? '').trim() === textId;

        setState({ ...EMPTY, pending: true });
        try {
            const res = await fetch(endpoint.replace('__TEXT_ID__', textId), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (res.status === 404) {
                if (isCurrent()) {
                    setState({ ...EMPTY, message: t('load_text_title_not_found', { id: textId }), failed: true });
                }

                return;
            }
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();

            if (!isCurrent()) {
                // 已被新的一輪取代或 c_textid 已改：只有仍是自己那一輪時才收掉 pending，
                // 否則會把接手那一輪的 pending 清掉、讓按鈕提早啟用。
                if (runSeqRef.current === seq) {
                    setState(EMPTY);
                }

                return;
            }

            // 逐欄判定，不用「整次請求有沒有書名」的單一旗標——書目常只有中文書名而沒有拼音書名
            // （實測 TEXT_CODES 有 21 筆如此），那時若只看整體旗標，會把「拼音欄還空著、來源也沒有
            // 拼音書名」誤報成「兩欄皆已有值」。
            const filled: string[] = [];
            const noSource: string[] = [];
            for (const field of TARGET_FIELDS) {
                // 空欄判定在 await 之後重做：請求期間使用者可能剛手動填了其中一欄，不可蓋掉。
                if ((getField(field) ?? '').trim() !== '') {
                    continue;
                }
                const incoming = data[SOURCE_OF[field]];
                if (incoming == null || String(incoming) === '') {
                    noSource.push(field);

                    continue;
                }
                setField(field, String(incoming));
                filled.push(field);
            }

            const parts: string[] = [];
            if (filled.length > 0) {
                parts.push(t('load_text_title_filled', { fields: filled.join(', ') }));
            }
            if (noSource.length > 0) {
                // 這些欄還空著，但來源書目也沒有對應書名——如實說明，別讓使用者以為按了沒反應。
                parts.push(t('load_text_title_source_empty', { fields: noSource.join(', ') }));
            }
            if (parts.length === 0) {
                // 兩個目標欄本來就都有值 → 依「只填空欄」的規則不動任何東西。
                parts.push(t('load_text_title_all_present'));
            }

            setState({ ...EMPTY, filled, message: parts.join(' ') });
        } catch (err) {
            if (!isCurrent()) {
                if (runSeqRef.current === seq) {
                    setState(EMPTY);
                }

                return;
            }
            setState({
                ...EMPTY,
                failed: true,
                // 用括號夾技術細節，避免在 en 語境出現全角冒號。
                message: `${t('load_text_title_failed')}${err instanceof Error ? ` (${err.message})` : ''}`,
            });
        }
    }, [endpoint, getField, setField, t]);

    return { ...state, run, reset };
}
